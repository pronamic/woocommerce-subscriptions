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
		as_schedule_single_action( time() + ( MINUTE_IN_SECONDS * 3 ), self::$cron_hook );
		WCS_Upgrade_Logger::add( 'Scheduled next APFS product migration batch.' );
	}

	/**
	 * Process a batch of products for APFS migration.
	 *
	 * Reads the category restriction list once, then queries for products that have no
	 * APFS configuration and assigns the appropriate subscription scheme mode based on
	 * category membership.
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

		// Query products that have no _wcsatt_schemes_status meta and no legacy APFS meta.
		$products = self::get_products_to_migrate( $last_product_id );

		$processed_count = 0;

		foreach ( $products as $product_id ) {
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
			} catch ( Exception $e ) {
				WCS_Upgrade_Logger::add( sprintf( 'Error migrating product %d: %s. Continuing with next product.', $product_id, $e->getMessage() ) );
			}

			update_option( self::$tracking_option, $product_id, 'no' );
			++$processed_count;
		}

		// Schedule next batch if we processed a full batch, otherwise we are done.
		if ( count( $products ) === self::$batch_size ) {
			WCS_Upgrade_Logger::add( sprintf( 'Batch complete. Processed %d products. Scheduling next batch.', $processed_count ) );
			self::schedule_apfs_migration();
		} else {
			WCS_Upgrade_Logger::add( sprintf( 'APFS product migration complete. Processed %d products in final batch.', $processed_count ) );
			delete_option( self::$tracking_option );
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
				WHERE p.post_type IN ('product', 'product_variation')
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
