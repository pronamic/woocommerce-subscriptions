<?php
/**
 * Upgrade script for version 9.0.0
 *
 * @version 9.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_9_0_0 {

	/**
	 * The cron hook used to schedule batch migrations of APFS products.
	 *
	 * @var string
	 */
	private static $cron_hook = 'woocommerce_subscriptions_migrate_apfs_products';

	/**
	 * The number of products to process per batch.
	 *
	 * @var int
	 */
	private static $batch_size = 50;

	/**
	 * The option name used to track the last migrated product ID.
	 *
	 * @var string
	 */
	private static $tracking_option = 'woocommerce_subscriptions_9_0_0_last_migrated_product_id';

	/**
	 * The option name used to store the total number of products to migrate.
	 *
	 * Captured once when the migration starts and used as the denominator for the
	 * admin progress notice.
	 *
	 * @var string
	 */
	private static $total_option = 'woocommerce_subscriptions_9_0_0_migration_total';

	/**
	 * The option name used to track how many products have been processed so far.
	 *
	 * Used as the numerator for the admin progress notice.
	 *
	 * @var string
	 */
	private static $migrated_count_option = 'woocommerce_subscriptions_9_0_0_migrated_count';

	/**
	 * The option name used to tally the products a run could not migrate.
	 *
	 * Written as each failure happens and cleared when a run starts. Unlike the other progress
	 * options it deliberately survives completion: the completion notice reads it to report how many
	 * products the run could not migrate, and dismissing that notice is what clears it.
	 *
	 * @var string
	 */
	private static $failure_count_option = 'woocommerce_subscriptions_9_0_0_migration_failures';

	/**
	 * The option name used to flag that the migration has finished.
	 *
	 * Set when the final batch completes and drives the dismissible completion notice.
	 * Deleted when the merchant dismisses that notice.
	 *
	 * @var string
	 */
	private static $complete_option = 'woocommerce_subscriptions_9_0_0_migration_complete';

	/**
	 * The standalone APFS plugin basename.
	 *
	 * @var string
	 */
	private static $apfs_plugin_basename = 'woocommerce-all-products-for-subscriptions/woocommerce-all-products-for-subscriptions.php';

	/**
	 * Initialize hooks for the upgrade class.
	 *
	 * Registers the cron callback and the standalone APFS plugin deactivation hook.
	 *
	 * @since 9.0.0
	 */
	public static function init() {
		add_action( self::$cron_hook, array( __CLASS__, 'migrate_apfs_products_batch' ) );

		// Hook into standalone APFS plugin deactivation to trigger migration.
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_apfs_plugin_deactivated' ) );

		// Prevent the standalone APFS plugin from being activated — its functionality is now part of Subscriptions.
		add_action( 'admin_init', array( __CLASS__, 'block_apfs_plugin_activation' ) );

		// Display the product migration progress notice while the migration is running.
		add_action( 'admin_notices', array( __CLASS__, 'display_migration_progress_notice' ) );

		// While the migration is running, resolve not-yet-migrated products using the standalone
		// plugin's own rules so they display exactly as they did before it was deactivated,
		// until each product's batch writes its permanent mode.
		add_filter( 'woocommerce_subscriptions_pre_product_subscription_scheme_mode', array( __CLASS__, 'maybe_emulate_standalone_scheme_mode' ), 10, 2 );
	}

	/**
	 * While the APFS migration is in progress, resolve a product's scheme mode with the standalone
	 * plugin's rules instead of the core meta-based resolution.
	 *
	 * Core reads the mode from the `_wcsatt_schemes_status` / `_wcsatt_storewide_selection_mode`
	 * meta keys, which the standalone plugin never wrote and does not honor — so a product carrying
	 * stale/core-only meta could display differently than it did under the standalone. To keep the
	 * catalog stable during the migration window, we emulate the standalone read
	 * (`WCS_ATT_Product_Schemes::get_subscription_schemes()`) until each product's batch writes its
	 * permanent mode. Once the migration completes (the total option is deleted) this filter becomes
	 * inert and normal core resolution resumes.
	 *
	 * @since 9.0.1
	 *
	 * @param string|null     $mode    The pre-resolved mode (null unless another callback set it).
	 * @param WC_Product|null $product The product being resolved.
	 * @return string|null A WCS_ATT_Scheme::MODE_* constant to force, or the incoming value otherwise.
	 */
	public static function maybe_emulate_standalone_scheme_mode( $mode, $product = null ) {
		if ( ! ( $product instanceof WC_Product ) || ! self::is_migration_in_progress() ) {
			return $mode;
		}

		return self::standalone_scheme_mode( $product );
	}

	/**
	 * Resolve a product's scheme mode using the standalone APFS plugin's rules.
	 *
	 * Mirrors the standalone `WCS_ATT_Product_Schemes::get_subscription_schemes()` resolution:
	 * a product is one-time when explicitly disabled; uses its own custom plans when it has any;
	 * otherwise inherits storewide plans when they exist and the product is category-eligible,
	 * falling back to one-time. Deliberately ignores the core-only `_wcsatt_schemes_status` and
	 * `_wcsatt_storewide_selection_mode` keys so the result matches the standalone exactly.
	 *
	 * @since 9.0.1
	 *
	 * @param WC_Product $product The product to resolve.
	 * @return string A WCS_ATT_Scheme::MODE_* constant.
	 */
	private static function standalone_scheme_mode( $product ) {
		// Variations inherit their configuration from the parent, matching the standalone.
		$source = $product;

		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent instanceof WC_Product ) {
				$source = $parent;
			}
		}

		// Explicitly disabled → one-time only.
		if ( 'yes' === $source->get_meta( '_wcsatt_disabled', true ) ) {
			return WCS_ATT_Scheme::MODE_DISABLE;
		}

		// Has its own custom plans → override.
		$schemes = $source->get_meta( '_wcsatt_schemes', true );

		if ( ! empty( $schemes ) && is_array( $schemes ) ) {
			return WCS_ATT_Scheme::MODE_OVERRIDE;
		}

		// No custom plans → inherit storewide plans if they exist and the product qualifies.
		$global_schemes = get_option( 'wcsatt_subscribe_to_cart_schemes', array() );

		if ( empty( $global_schemes ) || ! is_array( $global_schemes ) ) {
			return WCS_ATT_Scheme::MODE_DISABLE;
		}

		$categories = get_option( 'wcsatt_subscribe_to_cart_categories', array() );

		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		return self::should_inherit_storewide_plans( $product, $categories ) ? WCS_ATT_Scheme::MODE_INHERIT : WCS_ATT_Scheme::MODE_DISABLE;
	}

	/**
	 * Whether the APFS product migration is currently in progress.
	 *
	 * True from the moment the migration is scheduled (the total option is set) until the
	 * final batch completes and deletes it.
	 *
	 * @since 9.0.1
	 *
	 * @return bool
	 */
	private static function is_migration_in_progress() {
		return false !== get_option( self::$total_option, false );
	}

	/**
	 * Migrate the legacy proration option to the new first billing behavior option.
	 *
	 * Runs once on upgrade. Guarded by checking whether the new option is already set.
	 *
	 * @since 9.0.0
	 */
	public static function maybe_migrate_proration_option() {
		// If the new option is already set, migration has already run — do nothing.
		if ( false !== get_option( 'woocommerce_subscriptions_first_billing_behavior', false ) ) {
			return;
		}

		WCS_Upgrade_Logger::add( 'Migrating proration option to first billing behavior.' );

		// If sync was disabled on the old store, the proration setting was inert — always migrate to 'full'.
		if ( 'yes' !== get_option( 'woocommerce_subscriptions_sync_payments' ) ) {
			update_option( 'woocommerce_subscriptions_first_billing_behavior', 'full' );
			WCS_Upgrade_Logger::add( 'Proration option migration complete. Sync was disabled; migrated to full.' );
			return;
		}

		$old_value = get_option( 'woocommerce_subscriptions_prorate_synced_payments', 'no' );

		switch ( $old_value ) {
			case 'no':
				update_option( 'woocommerce_subscriptions_first_billing_behavior', 'next_billing_date' );
				break;
			case 'virtual':
				update_option( 'woocommerce_subscriptions_first_billing_behavior', 'prorate' );
				update_option( 'woocommerce_subscriptions_prorate_virtual', 'yes' );
				update_option( 'woocommerce_subscriptions_prorate_physical', 'no' );
				break;
			case 'yes':
				update_option( 'woocommerce_subscriptions_first_billing_behavior', 'prorate' );
				update_option( 'woocommerce_subscriptions_prorate_virtual', 'yes' );
				update_option( 'woocommerce_subscriptions_prorate_physical', 'yes' );
				break;
			case 'recurring':
			default:
				update_option( 'woocommerce_subscriptions_first_billing_behavior', 'full' );
				break;
		}

		WCS_Upgrade_Logger::add( sprintf( 'Proration option migration complete. Old value: %s.', $old_value ) );
	}

	/**
	 * Entry point for the APFS product migration.
	 *
	 * Checks whether the standalone APFS plugin was previously active and is now disabled.
	 * If so, triggers the batch migration immediately. If the plugin is still active,
	 * migration is deferred to the `on_apfs_plugin_deactivated()` hook — this ensures the
	 * category restrictions are read at the moment the merchant disables APFS, not at
	 * upgrade time (when they may still modify categories).
	 *
	 * @since 9.0.0
	 */
	public static function log_apfs_products_migration_status() {
		// Check if standalone APFS had storewide subscription plans configured.
		// If this option doesn't exist, the merchant was not using storewide plans — no migration needed.
		$global_schemes = get_option( 'wcsatt_subscribe_to_cart_schemes' );

		if ( false === $global_schemes ) {
			WCS_Upgrade_Logger::add( 'No storewide subscription plans found (wcsatt_subscribe_to_cart_schemes option not found). Skipping product migration.' );
			return;
		}

		// Only migrate if the standalone APFS plugin was actually active.
		// If it was not active, the merchant was not using storewide subscription plans.
		if ( ! self::is_apfs_plugin_active() ) {
			WCS_Upgrade_Logger::add( 'Standalone APFS plugin is not active. Skipping product migration.' );
			return;
		}

		// Plugin is active — defer migration to plugin deactivation.
		// The merchant may still modify category restrictions while APFS is active.
		WCS_Upgrade_Logger::add( 'Standalone APFS plugin is active. Product migration will run when the plugin is deactivated.' );
	}

	/**
	 * Schedule the next batch of APFS product migrations via Action Scheduler.
	 *
	 * @since 9.0.0
	 *
	 */
	private static function schedule_apfs_migration() {
		as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::$cron_hook );
		WCS_Upgrade_Logger::add( 'Scheduled next APFS product migration batch.' );
	}

	/**
	 * Process a batch of products for APFS migration.
	 *
	 * Reads the category restriction list once, then queries for products that have no
	 * APFS configuration and assigns the appropriate subscription scheme mode based on
	 * category membership.
	 *
	 * A product that raises an error is logged, counted as a failure and skipped — one bad product
	 * must not stall the whole run. The run still completes when it reaches the end of the catalog:
	 * a product that failed before its mode was written is left at the default (sell one-time only),
	 * and the merchant is told how many failed through the completion notice, with a link to the log
	 * that names them.
	 *
	 * Although this class belongs to a shipped release, this method is a persisted Action Scheduler
	 * callback that keeps running on any store whose migration has not finished yet, so it is
	 * maintained in place rather than frozen like a one-shot upgrade routine.
	 *
	 * @since 9.0.0
	 */
	public static function migrate_apfs_products_batch() {

		WCS_Upgrade_Logger::add( 'Starting batch migration of APFS products.' );

		$categories      = get_option( 'wcsatt_subscribe_to_cart_categories', array() );
		$last_product_id = (int) get_option( self::$tracking_option, 0 );

		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		// A cursor of zero means this is the first batch of a run, so the failure tally belongs to
		// the run about to start rather than to whatever ran before it.
		if ( 0 === $last_product_id ) {
			delete_option( self::$failure_count_option );
		}

		// Capture the fixed denominator for the progress notice on the first batch. Deferred to here
		// (rather than plugin deactivation) so the full-catalog count query stays off the synchronous
		// deactivation request. On the first run the total option is still the `0` "pending" sentinel.
		if ( 0 === $last_product_id && 0 === (int) get_option( self::$total_option, 0 ) ) {
			update_option( self::$total_option, self::count_products_to_migrate(), 'no' );
		}

		// Query products that have no _wcsatt_schemes_status meta and no legacy APFS meta.
		$products = self::get_products_to_migrate( $last_product_id );

		$processed_count = 0;
		$migrated_count  = (int) get_option( self::$migrated_count_option, 0 );
		$failure_count   = (int) get_option( self::$failure_count_option, 0 );

		foreach ( $products as $product_id ) {
			$failed = false;

			try {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					WCS_Upgrade_Logger::add( sprintf( 'Product %d could not be loaded. Skipping.', $product_id ) );
				} elseif ( WCS_ATT_Product::has_subscription_config( $product, false ) ) {
					// Safety net: skip products with legacy APFS meta (already filtered by the SQL query).
					WCS_Upgrade_Logger::add( sprintf( 'Product %d already has subscription configuration. Skipping.', $product_id ) );
				} elseif ( ! self::should_inherit_storewide_plans( $product, $categories ) ) {
					// Product doesn't qualify — `disable` is already the default mode, no write needed.
					WCS_Upgrade_Logger::add( sprintf( 'Product %d does not match category restrictions. Skipping (default is sell one-time only).', $product_id ) );
				} else {
					WCS_ATT_Product::set_subscription_scheme_mode( $product, WCS_ATT_Scheme::MODE_INHERIT );
					$product->save();
					WCS_Upgrade_Logger::add( sprintf( 'Product %d migrated to mode: %s.', $product_id, WCS_ATT_Scheme::MODE_INHERIT ) );
				}
			} catch ( Throwable $e ) {
				// Catch Throwable, not Exception: a PHP Error raised by one product (an incompatible
				// class, a bad callback on save) is not an Exception, so it used to escape the loop
				// before the cursor was written — Action Scheduler marked the action failed, no
				// further batch was scheduled, and the migration stopped dead on that product.
				$failed = true;

				WCS_Upgrade_Logger::add(
					sprintf(
						'Error migrating product %1$d: %2$s: %3$s in %4$s on line %5$d. Skipping this product and continuing with the next.',
						$product_id,
						get_class( $e ),
						$e->getMessage(),
						$e->getFile(),
						$e->getLine()
					)
				);
			}

			// The cursor always advances, including past a product that failed, so the batch cannot
			// stall on it. Failures are counted separately so they are never mistaken for progress.
			update_option( self::$tracking_option, $product_id, 'no' );

			if ( $failed ) {
				update_option( self::$failure_count_option, ++$failure_count, 'no' );
			} else {
				update_option( self::$migrated_count_option, ++$migrated_count, 'no' );
			}

			++$processed_count;
		}

		// Schedule next batch if we processed a full batch, otherwise we are done.
		if ( count( $products ) === self::$batch_size ) {
			WCS_Upgrade_Logger::add( sprintf( 'Batch complete. Processed %d products. Scheduling next batch.', $processed_count ) );
			self::schedule_apfs_migration();
			return;
		}

		WCS_Upgrade_Logger::add( sprintf( 'APFS product migration complete. Processed %d products in final batch.', $processed_count ) );

		if ( $failure_count > 0 ) {
			// The run still completes. Holding it open would keep the standalone emulation answering for
			// every product on the store, overriding the mode the product edit screen writes catalog-wide
			// for as long as one product stays broken; the merchant is told the count instead so the
			// affected products can be set up by hand.
			WCS_Upgrade_Logger::add(
				sprintf(
					'APFS product migration finished with %1$d failure(s) (%2$d migrated). Each is logged above with its product ID. Check each one: a product that failed before its mode was written sells one-time only until it is set up by hand.',
					$failure_count,
					$migrated_count
				)
			);
		}

		// Migration finished — clear the tracking and progress options so the notice stops showing.
		// Deleting the total is also what retires the pre-resolution filter, so every product resolves
		// from its own meta from here on.
		delete_option( self::$tracking_option );
		delete_option( self::$total_option );
		delete_option( self::$migrated_count_option );

		// The failure tally deliberately survives: the completion notice reports it, and dismissing
		// that notice is what clears it.

		// Flag completion so the dismissible "finished" notice is shown, but only if the run actually
		// processed products (avoids a "finished" notice when there was nothing to do). A run in which
		// everything failed has migrated nothing, and the merchant needs to hear about that too.
		if ( $migrated_count > 0 || $failure_count > 0 ) {
			update_option( self::$complete_option, 'yes', 'no' );
		}
	}

	/**
	 * Enable subscription product type creation settings if matching products exist.
	 *
	 * Checks if the store has any simple subscription or variable subscription products
	 * and enables the corresponding creation settings. Only runs for stores that had
	 * standalone APFS installed.
	 *
	 * @since 9.0.0
	 */
	public static function maybe_enable_subscription_product_types() {
		WCS_Upgrade_Logger::add( 'Checking for existing subscription product types to enable creation settings.' );

		// Check for simple subscription products.
		$simple_subscriptions = wc_get_products(
			array(
				'type'   => 'subscription',
				'limit'  => 1,
				'return' => 'ids',
				'status' => 'any',
			)
		);

		if ( ! empty( $simple_subscriptions ) ) {
			update_option( 'woocommerce_subscriptions_enable_simple_subscription', 'yes' );
			WCS_Upgrade_Logger::add( 'Simple subscription products found. Enabled simple subscription product creation.' );
		}

		// Check for variable subscription products.
		$variable_subscriptions = wc_get_products(
			array(
				'type'   => 'variable-subscription',
				'limit'  => 1,
				'return' => 'ids',
				'status' => 'any',
			)
		);

		if ( ! empty( $variable_subscriptions ) ) {
			update_option( 'woocommerce_subscriptions_enable_variable_subscription', 'yes' );
			WCS_Upgrade_Logger::add( 'Variable subscription products found. Enabled variable subscription product creation.' );
		}

		WCS_Upgrade_Logger::add( 'Subscription product type settings migration complete.' );
	}

	/**
	 * Handle standalone APFS plugin deactivation.
	 *
	 * Hooked to `deactivated_plugin`. Checks if the deactivated plugin is the
	 * standalone APFS plugin, then triggers the batch migration. Category restrictions
	 * are read at this point to reflect the merchant's final configuration.
	 *
	 * @since 9.0.0
	 *
	 * @param string $plugin The plugin basename that was deactivated.
	 */
	public static function on_apfs_plugin_deactivated( $plugin = '' ) {
		if ( self::$apfs_plugin_basename !== $plugin ) {
			return;
		}

		// Guard: only run if the merchant had storewide subscription plans configured.
		if ( false === get_option( 'wcsatt_subscribe_to_cart_schemes' ) ) {
			return;
		}

		WCS_Upgrade_Logger::add( 'Standalone APFS plugin deactivated. Triggering product migration with current category restrictions.' );

		// Mark the migration as started. The total is left at the `0` "pending" sentinel and computed
		// on the first batch instead — this keeps the full-catalog count query off the synchronous
		// deactivation request, which could otherwise time out on very large catalogs. Guard against
		// re-triggering mid-migration (0 is a valid "already started" value, so check for `false`).
		if ( false === get_option( self::$total_option, false ) ) {
			update_option( self::$total_option, 0, 'no' );
			update_option( self::$migrated_count_option, 0, 'no' );
		}

		// Schedule the first batch with a short delay to avoid conflicts with old APFS classes still loaded.
		as_schedule_single_action( time() + 5, self::$cron_hook );

		WCS_Upgrade_Logger::add( 'Scheduled first APFS product migration batch.' );
	}

	/**
	 * Block the standalone APFS plugin from being activated.
	 *
	 * Hooked to `admin_init` to intercept the activation request before WordPress
	 * sandbox-scrapes the plugin file. This prevents fatal errors.
	 *
	 * @since 9.0.0
	 */
	public static function block_apfs_plugin_activation() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['action'], $_GET['plugin'] ) || 'activate' !== $_GET['action'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plugin = sanitize_text_field( wp_unslash( $_GET['plugin'] ) );

		if ( self::$apfs_plugin_basename !== $plugin ) {
			return;
		}

		wp_die(
			esc_html__( "The WooCommerce All Products for Subscriptions plugin can't be activated because it isn't compatible with WooCommerce Subscriptions 9.0. All Products features are now included with WooCommerce Subscriptions. You can uninstall this plugin.", 'woocommerce-subscriptions' ),
			esc_html__( 'Plugin Activation Error', 'woocommerce-subscriptions' ),
			array(
				'link_text' => esc_html__( 'Back to Plugins', 'woocommerce-subscriptions' ),
				'link_url'  => esc_url( admin_url( 'plugins.php' ) ),
				'response'  => 200,
			)
		);
	}

	/**
	 * Get the next batch of product IDs to migrate.
	 *
	 * Queries for products with ID greater than the last migrated product ID that do
	 * not have `_wcsatt_schemes_status` meta set. Additionally excludes products with
	 * any legacy APFS meta keys.
	 *
	 * Only top-level products (`post_type = 'product'`) are queried — variations inherit
	 * their subscription scheme mode from the parent at runtime, so they must not be
	 * migrated independently.
	 *
	 * @since 9.0.0
	 *
	 * @param int $last_product_id The last product ID that was migrated.
	 * @return array Array of product IDs.
	 */
	private static function get_products_to_migrate( $last_product_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'product'
				AND p.post_status != 'auto-draft'
				AND p.ID > %d
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID AND pm.meta_key = '_wcsatt_schemes_status'
				)
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm2
					WHERE pm2.post_id = p.ID AND pm2.meta_key = '_wcsatt_disabled'
				)
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm3
					WHERE pm3.post_id = p.ID AND pm3.meta_key = '_wcsatt_schemes'
				)
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm4
					WHERE pm4.post_id = p.ID AND pm4.meta_key = '_wcsatt_storewide_selection_mode'
				)
				ORDER BY p.ID ASC
				LIMIT %d",
				$last_product_id,
				self::$batch_size
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'absint', $product_ids );
	}

	/**
	 * Count the total number of products that will be migrated.
	 *
	 * Mirrors the criteria used by `get_products_to_migrate()` (minus the ID cursor and
	 * batch limit) so the progress notice's denominator matches exactly what the batches
	 * iterate over. Captured once at the start of the run because the eligible set shrinks
	 * as products gain the `_wcsatt_schemes_status` meta.
	 *
	 * @since 9.0.1
	 *
	 * @return int The number of products to migrate.
	 */
	private static function count_products_to_migrate() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'product'
			AND p.post_status != 'auto-draft'
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID AND pm.meta_key = '_wcsatt_schemes_status'
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm2
				WHERE pm2.post_id = p.ID AND pm2.meta_key = '_wcsatt_disabled'
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm3
				WHERE pm3.post_id = p.ID AND pm3.meta_key = '_wcsatt_schemes'
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm4
				WHERE pm4.post_id = p.ID AND pm4.meta_key = '_wcsatt_storewide_selection_mode'
			)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Display an admin notice reporting the product migration status.
	 *
	 * While the migration is running (the total option is set) it shows a progress notice.
	 * Once the final batch completes it shows a dismissible "finished" notice, which reports the
	 * failure count with a link to the log when the run recorded any. Dismissing clears the
	 * completion flag and the tally.
	 *
	 * @since 9.0.1
	 */
	public static function display_migration_progress_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Handle dismissal of the completion notice.
		if ( isset( $_GET['_wcsnonce'], $_GET['woocommerce_subscriptions_dismiss_apfs_migration_notice'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wcsnonce'] ) ), 'woocommerce_subscriptions_dismiss_apfs_migration_notice' ) ) {
			delete_option( self::$complete_option );
			delete_option( self::$failure_count_option );
			return;
		}

		$total = (int) get_option( self::$total_option, 0 );

		// Migration in progress — show the progress notice.
		if ( $total > 0 ) {
			$migrated = min( (int) get_option( self::$migrated_count_option, 0 ), $total );
			$percent  = (int) floor( ( $migrated / $total ) * 100 );

			$notice = new WCS_Admin_Notice( 'notice notice-info' );
			$notice->set_simple_content(
				sprintf(
					/* translators: 1: number of products migrated, 2: total number of products, 3: percentage complete. */
					__( 'WooCommerce Subscriptions is migrating your existing product data. %1$s of %2$s products migrated (%3$d%%). This runs in the background — please don\'t downgrade Subscriptions until it finishes.', 'woocommerce-subscriptions' ),
					number_format_i18n( $migrated ),
					number_format_i18n( $total ),
					$percent
				)
			);
			$notice->display();
			return;
		}

		// Migration finished — show a dismissible completion notice.
		if ( 'yes' !== get_option( self::$complete_option ) ) {
			return;
		}

		$dismiss_url   = wp_nonce_url( add_query_arg( 'woocommerce_subscriptions_dismiss_apfs_migration_notice', '1' ), 'woocommerce_subscriptions_dismiss_apfs_migration_notice', '_wcsnonce' );
		$failure_count = (int) get_option( self::$failure_count_option, 0 );

		if ( $failure_count > 0 ) {
			// A product that failed before its mode was written now sells one-time only; one that failed
			// after (a callback throwing on save) is migrated but tallied all the same. Naming the count
			// and linking the log is what lets the merchant check them, since the per-product detail only
			// exists there.
			$log_url = admin_url( 'admin.php?page=wc-status&tab=logs&source=' . WCS_Upgrade_Logger::$handle );

			$notice = new WCS_Admin_Notice( 'notice notice-warning', array(), $dismiss_url );
			$notice->set_simple_content(
				sprintf(
					/* translators: 1: number of products that could not be migrated, 2: opening link tag to the migration logs, 3: closing link tag. */
					_n(
						'WooCommerce Subscriptions has finished migrating your existing product data. %1$s product could not be migrated. Please update this product manually. %2$sReview logs%3$s',
						'WooCommerce Subscriptions has finished migrating your existing product data. %1$s products could not be migrated. Please update these products manually. %2$sReview logs%3$s',
						$failure_count,
						'woocommerce-subscriptions'
					),
					number_format_i18n( $failure_count ),
					'<a href="' . esc_url( $log_url ) . '">',
					'</a>'
				)
			);
			$notice->display();
			return;
		}

		$notice = new WCS_Admin_Notice( 'notice notice-success', array(), $dismiss_url );
		$notice->set_simple_content( __( 'WooCommerce Subscriptions has finished migrating your existing product data.', 'woocommerce-subscriptions' ) );
		$notice->display();
	}

	/**
	 * Determine whether a product should inherit storewide subscription plans.
	 *
	 * If the category restriction list is empty, all products qualify. If the list has
	 * entries, only products belonging to at least one listed category qualify.
	 * Products that don't qualify are left at the default `disable` mode (no write needed).
	 *
	 * @since 9.0.0
	 *
	 * @param WC_Product $product    The product to check.
	 * @param array      $categories Array of category IDs from the APFS category restriction setting.
	 * @return bool True if the product should be set to `inherit` mode.
	 */
	private static function should_inherit_storewide_plans( $product, $categories ) {
		// No category restrictions — all products inherit storewide plans.
		if ( empty( $categories ) ) {
			return true;
		}

		// Get the product's category IDs.
		$product_category_ids = $product->get_category_ids();

		// For variations, also check the parent product categories.
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$product_category_ids = array_merge( $product_category_ids, $parent->get_category_ids() );
				$product_category_ids = array_unique( $product_category_ids );
			}
		}

		// Check if product belongs to any of the restricted categories.
		$matching_categories = array_intersect( $product_category_ids, array_map( 'absint', $categories ) );

		return ! empty( $matching_categories );
	}

	/**
	 * Check if the standalone APFS plugin is currently active.
	 *
	 * @since 9.0.0
	 *
	 * @return bool True if the standalone APFS plugin is active.
	 */
	private static function is_apfs_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::$apfs_plugin_basename );
	}
}
