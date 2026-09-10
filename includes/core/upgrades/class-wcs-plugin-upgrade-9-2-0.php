<?php
/**
 * Upgrade script for version 9.2.0
 *
 * @version 9.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WCS_Plugin_Upgrade_9_2_0 class.
 */
class WCS_Plugin_Upgrade_9_2_0 {

	/**
	 * The cron hook used to schedule batches of the gifting product migration.
	 *
	 * @var string
	 */
	private const GIFTING_CRON_HOOK = 'woocommerce_subscriptions_migrate_gifting_products';

	/**
	 * The option name used to track the last product ID processed by the gifting migration.
	 *
	 * @var string
	 */
	private const GIFTING_TRACKING_OPTION = 'woocommerce_subscriptions_gifting_migration_last_product_id';

	/**
	 * The option name used to store the total number of products the gifting migration will process.
	 *
	 * Doubles as the in-progress flag: it is set the moment the migration is scheduled and deleted when the
	 * final batch completes. See {@see self::is_gifting_migration_in_progress()}.
	 *
	 * @var string
	 */
	private const GIFTING_TOTAL_OPTION = 'woocommerce_subscriptions_gifting_migration_total';

	/**
	 * The option name used to track how many products the gifting migration has processed so far.
	 *
	 * Used as the numerator for the admin progress notice.
	 *
	 * @var string
	 */
	private const GIFTING_MIGRATED_COUNT_OPTION = 'woocommerce_subscriptions_gifting_migration_migrated_count';

	/**
	 * The option name used to count products the gifting migration could not write.
	 *
	 * Belongs to the run in flight: reset when a run starts, incremented as each failure happens. Mirrors
	 * {@see WCS_Upgrade_9_0_0}'s tally for the APFS migration, with one deliberate difference - a failure
	 * here does not hold the migration open. The run completes and the count is reported to the merchant
	 * instead, because the products that failed are exactly the ones needing a manual look or a bulk edit,
	 * and leaving the migration permanently unfinished would also leave every new product inheriting the
	 * storewide default.
	 *
	 * @var string
	 */
	private const GIFTING_FAILURE_COUNT_OPTION = 'woocommerce_subscriptions_gifting_migration_failures';

	/**
	 * The option name holding the highest post ID the gifting migration will consider.
	 *
	 * Captured when the run is scheduled. Without a ceiling the candidate set grows as the run proceeds, so
	 * a product created after the upgrade but before the final batch would be swept up and have the legacy
	 * storewide default written onto it - the opposite of the rule that products created from 9.2.0 start
	 * with gifting off. It also keeps the progress denominator from measuring a moving target.
	 *
	 * @var string
	 */
	private const GIFTING_MAX_ID_OPTION = 'woocommerce_subscriptions_gifting_migration_max_id';

	/**
	 * The option name used to flag that the gifting migration has finished.
	 *
	 * Set when the final batch completes and drives the dismissible completion notice. Deleted when the
	 * merchant dismisses that notice.
	 *
	 * @var string
	 */
	private const GIFTING_COMPLETE_OPTION = 'woocommerce_subscriptions_gifting_migration_complete';

	/**
	 * The option name used to record that the storewide gifting default has been resolved for this store.
	 *
	 * This is what retires the read-time storewide fallback in
	 * {@see WC_Subscriptions_Product::is_gifting_enabled_for_product()}. "Has this store been settled?" is
	 * the question the resolver actually needs: unlike "is a migration running?", which reads false both
	 * before a run starts and after it ends, this is false only while the store's gifting values are still
	 * unresolved. So every way the migration can fail to run - the window before the 9.2.0 upgrade fires, a
	 * scheduling error, Action Scheduler being unavailable, a run that stalls partway - leaves the fallback
	 * in place rather than silently switching gifting off storewide.
	 *
	 * Lifecycle: set when the migration finishes, or immediately when there is nothing to migrate. It is
	 * **not** write-once. Scheduling a run clears it again (see {@see self::maybe_schedule_gifting_migration()}),
	 * so a store recovering from a skip re-applies the fallback for the duration of that run and is settled
	 * again when it completes. Ordinary upgrades never hit that path - the option is absent to begin with -
	 * but code reasoning about this flag must not assume it can only ever go false to true.
	 *
	 * Autoloaded, unlike the progress options: it is read on storefront paths through
	 * {@see WCSG_Product::is_giftable()}, whereas the progress options are written every batch and read only
	 * by the admin notice.
	 *
	 * @var string
	 */
	private const GIFTING_SETTLED_OPTION = 'woocommerce_subscriptions_gifting_migration_settled';

	/**
	 * The number of products the gifting migration processes per batch.
	 *
	 * @var int
	 */
	private static $gifting_batch_size = 50;

	/**
	 * Initialize hooks for the upgrade class.
	 *
	 * @since 9.2.0
	 */
	public static function init() {
		add_action( self::GIFTING_CRON_HOOK, array( __CLASS__, 'migrate_gifting_products_batch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_gifting_migration_notice' ) );
	}

	/**
	 * Ensure the subscription downloads mapping table exists.
	 *
	 * The table was previously only created on plugin activation, so stores that
	 * updated from a pre-8.1.0 version without deactivating and reactivating never
	 * got it, and enabling the downloadable file sharing setting failed silently.
	 * The table is created unconditionally (whether or not the feature is enabled)
	 * to keep the repair simple; an unused empty table is an accepted trade-off.
	 *
	 * @since 9.2.0
	 */
	public static function maybe_create_subscription_downloads_table() {

		WCS_Upgrade_Logger::add( 'Ensuring the subscription downloads table exists...' );

		// The standalone WooCommerce Subscription Downloads plugin bundles its own
		// WC_Subscription_Downloads_Install class without ensure_table_exists(). That class is
		// only included during the standalone plugin's activation request, but guard against it
		// being the loaded one to avoid a fatal; on every other request this check autoloads
		// the class bundled with Subscriptions and passes.
		if ( ! method_exists( 'WC_Subscription_Downloads_Install', 'ensure_table_exists' ) ) {
			WCS_Upgrade_Logger::add( 'The standalone Subscription Downloads plugin install class is loaded, skipping...' );
			return;
		}

		$table_exists = WC_Subscription_Downloads_Install::ensure_table_exists();

		if ( $table_exists ) {
			WCS_Upgrade_Logger::add( 'Subscription downloads table verified present.' );
		} else {
			WCS_Upgrade_Logger::add( 'WARNING: subscription downloads table is still missing after dbDelta. Check the DB user can CREATE tables.' );
		}
	}

	/**
	 * Normalize the manual/automatic renewal option pair on stores holding the contradictory state.
	 *
	 * Before 9.2.0 the pairing between "Accept manual renewals" and its child "Turn off automatic
	 * payments" was only kept consistent by the settings form; write paths that save one option at a
	 * time (the plugin's REST settings group, WP-CLI) could store the child as 'yes' with the parent
	 * off. That state behaved as forced-manual renewals, because the runtime read the child option
	 * alone. 9.2.0 makes the child take effect only while the parent is enabled
	 * ({@see WCS_Manual_Renewal_Manager::is_manual_renewal_required()}), which would silently flip
	 * such a store to automatic renewals - charging saved payment methods the merchant explicitly
	 * turned off. Preserve the store's effective behavior instead by enabling the parent, and leave a
	 * traceable log line. Note the disclosed side effect: accepting manual renewals also makes payment
	 * gateways that do not support recurring payments available at subscription checkout (that is part
	 * of what the parent option means), which the contradictory pre-upgrade state suppressed. This runs
	 * from the standard capability-gated upgrader, so a store updated without an admin request keeps
	 * the pre-normalization behavior gap until an admin next visits wp-admin - an accepted trade-off
	 * (plain option reads/writes, no fatal risk either way).
	 *
	 * @since 9.2.0
	 */
	public static function maybe_normalize_manual_renewal_options() {
		$option_prefix = WC_Subscriptions_Admin::$option_prefix;

		if ( 'yes' !== get_option( $option_prefix . '_turn_off_automatic_payments', 'no' ) || 'yes' === get_option( $option_prefix . '_accept_manual_renewals', 'no' ) ) {
			return;
		}

		/*
		 * Plain, idempotent option writes: enabling the parent makes the already-stored child value take
		 * effect again, and re-asserting the child is a defensive no-op that also repairs a store where
		 * only the parent row was missing. Safe regardless of which rows exist.
		 */
		update_option( $option_prefix . '_accept_manual_renewals', 'yes' );
		update_option( $option_prefix . '_turn_off_automatic_payments', 'yes' );

		WCS_Upgrade_Logger::add( 'Enabled "Accept Manual Renewals": the store had "Turn off automatic payments" enabled without it. 9.2.0 enforces the pairing between the two settings, and enabling the parent preserves the store\'s existing forced-manual-renewal behavior. Note: accepting manual renewals also makes payment gateways without recurring-payment support available at subscription checkout. Disable both under WooCommerce > Settings > Subscriptions to return to automatic payments.' );
	}

	/**
	 * Keep the switch button label a store was showing before 9.2.0 changed the unset default.
	 *
	 * The storefront switch link and the settings field fell back to "Upgrade or Downgrade" when the
	 * option had never been saved; 9.2.0 changed that fallback to "Switch". Storing the old label for a
	 * store with no saved value keeps its link as it was and leaves "Switch" to new installs. Any saved
	 * value, a cleared one included, is the store's own and stays.
	 *
	 * The label is stored in the site language, the language the storefront rendered the fallback in.
	 * This runs on an admin-capable request, usually in wp-admin, where a plain `__()` follows the acting
	 * user's profile language. A stored label is a single language from here on, as it already was for
	 * every store that saved the tab; a store translating the fallback per request through a
	 * multilingual plugin loses that.
	 *
	 * Only meaningful for a store updating from an earlier version; the caller skips first installs.
	 *
	 * @since 9.2.0
	 */
	public static function maybe_preserve_switch_button_text() {
		$option_name = WC_Subscriptions_Admin::$option_prefix . '_switch_button_text';

		if ( false !== get_option( $option_name ) ) {
			return;
		}

		$site_locale = get_locale();

		// WordPress refuses to switch to a site language it has no language pack for. Such a store showed the
		// untranslated default, so that is stored rather than the acting user's translation of it.
		wc_switch_to_site_locale();
		$switched = determine_locale() === $site_locale;
		$label    = $switched ? __( 'Upgrade or Downgrade', 'woocommerce-subscriptions' ) : 'Upgrade or Downgrade';
		wc_restore_locale();

		update_option( $option_name, $label );

		WCS_Upgrade_Logger::add( 'Stored "Upgrade or Downgrade" as the switch button text: the store had no saved value and was showing that pre-9.2.0 default, which 9.2.0 changed to "Switch" for unsaved values. Change the label under WooCommerce > Settings > Subscriptions.' );
	}

	/**
	 * Schedule the gifting product migration, if the store has a storewide default to materialize.
	 *
	 * The 9.2.0 redesign removes the "enabled/disabled for all products" storewide default and the per-product
	 * "use global setting" indirection along with it. Products that were following that indirection have to end
	 * up carrying the value they effectively had, or an upgrade would silently change their giftability.
	 *
	 * Only an "enabled for all products" store has anything to materialize: everywhere else a product without an
	 * explicit value already resolves to gifting off, which is what it resolves to after the migration too. When
	 * there is nothing to migrate the in-progress flag is never set, so the read-time fallback in
	 * {@see WC_Subscriptions_Product::is_gifting_enabled_for_product()} resolves to false straight away.
	 *
	 * @since 9.2.0
	 */
	public static function maybe_schedule_gifting_migration() {
		// Both skips below are deliberate, so they settle the store: there is nothing to materialize, and the
		// storewide fallback should retire just as it does after a completed run. Without this a skipped store
		// would keep inheriting the storewide default forever, which is the pre-redesign behaviour rather than
		// the one migration.md §3b decided on.
		if ( ! self::is_gifting_enabled() ) {
			WCS_Upgrade_Logger::add( 'Gifting is not enabled. Skipping the gifting product migration.' );
			self::settle_gifting_migration();
			return;
		}

		if ( ! self::is_gifting_enabled_for_all_products() ) {
			WCS_Upgrade_Logger::add( 'Gifting is not enabled for all products. Skipping the gifting product migration.' );
			self::settle_gifting_migration();
			return;
		}

		// Guard against re-scheduling a migration that is already running. `0` is a valid "started, total not
		// yet counted" value, so check for the option's absence rather than for a falsy value.
		$total = get_option( self::GIFTING_TOTAL_OPTION, false );

		if ( false !== $total ) {
			WCS_Upgrade_Logger::add( 'The gifting product migration is already in progress. Skipping.' );
			return;
		}

		// The ceiling survives a completed run, so its presence is also the record that a run has happened on
		// this store at all - the progress options are all cleared at completion, leaving nothing else to tell
		// a finished store from one that was skipped at upgrade time. Both look settled with no total.
		$is_first_run = ( (int) get_option( self::GIFTING_MAX_ID_OPTION, 0 ) ) < 1;

		// Unique: the total-option guard above is check-then-act, so two requests racing through this routine
		// before the stored plugin version is bumped (two admin tabs, or admin vs. cron/REST/CLI) could both
		// pass it. The unique flag is the real claim - Action Scheduler enforces it as one atomic conditional
		// INSERT, so exactly one racer gets a non-zero action id, and every state write below runs only for
		// that winner. A stalled loser therefore cannot rewind a live run's cursor or counters. The batch
		// handler's own reschedule must NOT pass unique: it blocks on a running action too, and the current
		// action is still running when the next one is scheduled.
		$action_id = as_schedule_single_action( time() + 5, self::GIFTING_CRON_HOOK, array(), '', true );

		if ( ! $action_id ) {
			// Action Scheduler returns 0 when it is not initialised, when `pre_as_schedule_single_action` lets
			// a plugin short-circuit it, or when a concurrent request already scheduled the action (the unique
			// flag above). No migration state was written by this request, so nothing claims to be in progress
			// and product gifting keeps resolving exactly as before this call - this log is where support
			// looks first, so it must not claim this request scheduled or started anything.
			WCS_Upgrade_Logger::add( 'WARNING: did not schedule the first gifting product migration batch - Action Scheduler unavailable, short-circuited, or a concurrent request already scheduled it. This request recorded no migration state; unless a batch was scheduled elsewhere, re-run the scheduler once Action Scheduler is available.' );
			return;
		}

		// State writes below belong to the winner of the unique INSERT above; the batch is five seconds out,
		// leaving ample headroom before it reads any of them.
		if ( $is_first_run ) {
			// First run here. Freeze the candidate set - a cheap PRIMARY lookup, and any product created from
			// now on has a higher ID, so it is excluded by construction rather than by inspecting its meta.
			global $wpdb;
			update_option( self::GIFTING_MAX_ID_OPTION, (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ), 'no' );

			// A run is starting on a store whose gifting values are not resolved yet, so it is no longer
			// settled: the storewide fallback has to cover the run. This is the recovery path for a store that
			// was skipped at upgrade time and settled then.
			delete_option( self::GIFTING_SETTLED_OPTION );
		}

		// Re-running after a completed migration deliberately keeps both the original ceiling and the settled
		// flag. Re-capturing the ceiling would pull in every product created since - products that correctly
		// carry no gifting value under the new rule - and write the legacy storewide default onto them
		// permanently. Un-settling would hand those same products the fallback for the duration of the run.
		// Neither is what a re-run is for: it exists to finish products the first run left behind, and those
		// are all below the original ceiling.

		// Mark the migration as started. The total is left at the `0` "pending" sentinel and counted on the
		// first batch instead, keeping the full-catalog count query off the synchronous upgrade request.
		update_option( self::GIFTING_TOTAL_OPTION, 0, 'no' );
		update_option( self::GIFTING_MIGRATED_COUNT_OPTION, 0, 'no' );
		delete_option( self::GIFTING_TRACKING_OPTION );
		delete_option( self::GIFTING_FAILURE_COUNT_OPTION );

		WCS_Upgrade_Logger::add( 'Scheduled the first gifting product migration batch.' );
	}

	/**
	 * Whether the gifting product migration is currently in progress.
	 *
	 * True from the moment the migration is scheduled (the total option is set) until the final batch
	 * completes and deletes it. False on a store where the migration was never needed.
	 *
	 * @since 9.2.0
	 *
	 * @return bool
	 */
	public static function is_gifting_migration_in_progress() {
		return false !== get_option( self::GIFTING_TOTAL_OPTION, false );
	}

	/**
	 * Whether the storewide gifting default has been resolved for this store.
	 *
	 * Drives the read-time fallback: until this is true, a product with no explicit gifting value still
	 * inherits the storewide default, exactly as it did before 9.2.0.
	 *
	 * @since 9.2.0
	 *
	 * @return bool
	 */
	public static function has_gifting_migration_settled() {
		return 'yes' === get_option( self::GIFTING_SETTLED_OPTION, 'no' );
	}

	/**
	 * Record that the storewide gifting default has been resolved for this store.
	 *
	 * Autoloaded because the resolver reads it on storefront requests. Written with `update_option()` rather
	 * than `add_option()` so re-settling an already-settled store is a harmless no-op.
	 *
	 * @since 9.2.0
	 */
	private static function settle_gifting_migration() {
		update_option( self::GIFTING_SETTLED_OPTION, 'yes', 'yes' );
	}

	/**
	 * Process a batch of products for the gifting migration.
	 *
	 * Writes the storewide default being migrated ("enabled") onto every giftable product that does not
	 * already carry an explicit value. The value is materialized into `_subscription_gifting` itself rather
	 * than a parallel field, so a store that downgrades reads it back unchanged.
	 *
	 * @since 9.2.0
	 */
	public static function migrate_gifting_products_batch() {
		// A stray action firing after the run finished must not restart it: that would re-enable the read-time
		// storewide fallback on a store that has already resolved every product.
		if ( ! self::is_gifting_migration_in_progress() ) {
			WCS_Upgrade_Logger::add( 'No gifting product migration is running. Skipping the batch.' );
			return;
		}

		$last_product_id = (int) get_option( self::GIFTING_TRACKING_OPTION, 0 );
		$total           = (int) get_option( self::GIFTING_TOTAL_OPTION, 0 );

		// Capture the fixed denominator for the progress notice on the first batch. On the first run the total
		// option is still the `0` "pending" sentinel.
		if ( 0 === $last_product_id && 0 === $total ) {
			$count = self::count_gifting_products_to_migrate();

			if ( null === $count ) {
				self::pause_gifting_migration_batch( 'count the products to migrate', $last_product_id );
				return;
			}

			update_option( self::GIFTING_TOTAL_OPTION, $count, 'no' );
		}

		$product_ids = self::get_gifting_products_to_migrate( $last_product_id );

		if ( null === $product_ids ) {
			self::pause_gifting_migration_batch( 'fetch the next batch of products', $last_product_id );
			return;
		}

		$migrated_count = (int) get_option( self::GIFTING_MIGRATED_COUNT_OPTION, 0 );
		$failure_count  = (int) get_option( self::GIFTING_FAILURE_COUNT_OPTION, 0 );
		$written_count  = 0;
		$skipped_count  = 0;
		$batch_failures = 0;

		foreach ( $product_ids as $product_id ) {
			$failed = false;

			try {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					// Counts as a failure, not a skip: the row matched the candidate query, so something is
					// there, but it could not be instantiated - corrupt data, or a product type whose plugin is
					// inactive, which is how a bundle or composite behaves when its extension is switched off.
					// Its giftability was never evaluated, so the merchant needs it in the reported count.
					$failed = true;

					WCS_Upgrade_Logger::add( sprintf( 'Product %d could not be loaded. Skipping gifting migration for it.', $product_id ) );
				} elseif ( self::is_product_giftable_eligible( $product ) ) {
					// A direct meta write, not `$product->save()`: a full CRUD save fires
					// `woocommerce_update_product` per product, which WooCommerce maps to the `product.updated`
					// webhook topic and Jetpack Sync / lookup-table / indexer listeners consume - one delivery
					// per giftable product, catalogue-wide, on top of the batch chain itself. The variation
					// save and the bulk edit's variation leg write this meta the same direct way; the
					// product-level bulk edit stays on the full CRUD save because its selection is
					// merchant-bounded, unlike this catalogue-wide sweep.
					update_post_meta( $product_id, '_subscription_gifting', 'enabled' );
					++$written_count;
				} else {
					// A product that cannot be gifted never inherited the storewide default, so leaving it
					// without an explicit value keeps it resolving to gifting off, exactly as it does today.
					// Counted rather than logged per product: the candidate query matches every product and
					// variation without an explicit value, so on a catalogue of any size this is the common
					// case and a line each would bury the failures that matter.
					++$skipped_count;
				}
			} catch ( Throwable $e ) {
				// Throwable, not Exception: the meta write fires WordPress's meta hooks (added_post_meta /
				// updated_post_meta), and `wc_get_product()` resolves the class through the
				// `woocommerce_product_class` filter - a strictly-typed third-party callback on either can
				// raise a TypeError. That is an
				// Error, it would escape an Exception-only catch, and because the cursor below would never
				// advance the whole run would stop on that one product with no way to restart it.
				$failed = true;

				WCS_Upgrade_Logger::add( sprintf( 'Error migrating gifting for product %d: %s. Continuing with next product.', $product_id, $e->getMessage() ) );
			}

			// The cursor always advances, past failures included, so a bad product cannot stall the run.
			update_option( self::GIFTING_TRACKING_OPTION, $product_id, 'no' );
			update_option( self::GIFTING_MIGRATED_COUNT_OPTION, ++$migrated_count, 'no' );

			if ( $failed ) {
				update_option( self::GIFTING_FAILURE_COUNT_OPTION, ++$failure_count, 'no' );
				++$batch_failures;
			}
		}

		if ( count( $product_ids ) === self::$gifting_batch_size ) {
			WCS_Upgrade_Logger::add( sprintf( 'Gifting migration batch complete. %d of %d products enabled, %d not giftable, %d failed. Scheduling next batch.', $written_count, count( $product_ids ), $skipped_count, $batch_failures ) );

			// Due immediately rather than a minute out: the chain only ever has one action pending, so it takes
			// a single slot per queue pass and cannot crowd out time-critical renewal actions no matter how
			// large the catalogue. A fixed delay just adds idle time - at 50 products a minute a six-figure
			// catalogue would sit under the progress notice for more than a day.
			$next_batch_id = as_schedule_single_action( time(), self::GIFTING_CRON_HOOK );

			if ( ! $next_batch_id ) {
				WCS_Upgrade_Logger::add( 'WARNING: could not schedule the next gifting product migration batch. The run is paused at the cursor above, with the storewide default still applying.' );
			}

			return;
		}

		WCS_Upgrade_Logger::add( sprintf( 'Gifting product migration complete. Final batch: %d of %d products enabled, %d not giftable, %d failed.', $written_count, count( $product_ids ), $skipped_count, $batch_failures ) );

		if ( $failure_count > 0 ) {
			// The run still completes. Holding it open would keep the storewide fallback alive for every new
			// product too, which is a broader change than the failures warrant; the merchant is told the count
			// instead so the affected products can be checked and fixed with the gifting bulk edit.
			WCS_Upgrade_Logger::add( sprintf( 'Gifting product migration finished with %d failure(s). Each is logged above with its product ID; those products keep no explicit gifting value.', $failure_count ) );
		}

		// Migration finished. Settling the store is what retires the read-time storewide fallback, so products
		// without an explicit value resolve to gifting off from here on.
		self::settle_gifting_migration();

		// Clearing the total is still load-bearing, for the notice rather than for the read rule: the progress
		// notice renders while it holds a count, so leaving it would pin the store at "12 of 40 migrated" and
		// the completion notice would never appear. It also closes the run to `is_gifting_migration_in_progress()`,
		// which is what makes a stray action arriving later return early instead of recounting.
		delete_option( self::GIFTING_TOTAL_OPTION );

		// Housekeeping. Nothing reads these once the run is over; they are removed so a finished migration does
		// not leave rows behind on every store.
		delete_option( self::GIFTING_TRACKING_OPTION );
		delete_option( self::GIFTING_MIGRATED_COUNT_OPTION );

		// The ceiling is deliberately kept. It is what a later re-run reuses instead of capturing a new one,
		// and its presence is how {@see self::maybe_schedule_gifting_migration()} tells a completed store from
		// one that was skipped at upgrade time.

		// The failure tally deliberately survives: the completion notice reports it, and dismissing that
		// notice is what clears it.

		// Report completion when the run actually walked something, so a store with an empty catalogue stays
		// silent. Note this counts products *processed*, not written: a catalogue with no giftable products in
		// it still reports completion. That is deliberate for now - the notice says the migration finished, not
		// that anything changed. Gating on a written count would need one, since $written_count is per batch,
		// and that only becomes worth adding if the notice is ever asked to report how many products it
		// updated.
		if ( $migrated_count > 0 ) {
			update_option( self::GIFTING_COMPLETE_OPTION, 'yes', 'no' );
		}
	}

	/**
	 * Log a database failure and leave the gifting migration run open at its current cursor.
	 *
	 * A failed query returns the same empty result as a finished catalogue, so without this path a transient
	 * database failure mid-run would read as end-of-data and settle the store - permanently switching gifting
	 * off for every product the run never reached. Pausing deliberately writes nothing: the failing connection
	 * makes any database write just as unreliable, and the run being open is by itself what keeps the
	 * storewide fallback applying and the progress notice visible. The log - file-based, so it survives the
	 * database failure - carries what support needs: the error, the cursor the run stopped at, and how to
	 * resume it.
	 *
	 * @since 9.2.0
	 *
	 * @param string $failed_step     Which query failed, for the log line.
	 * @param int    $last_product_id The cursor the run is paused at.
	 */
	private static function pause_gifting_migration_batch( $failed_step, $last_product_id ) {
		global $wpdb;

		WCS_Upgrade_Logger::add(
			sprintf(
				'ERROR: the gifting product migration could not %1$s: %2$s. Pausing the run at product ID cursor %3$d. The store stays unsettled, so the storewide gifting default keeps applying and the progress notice remains. To resume from the cursor once the database is healthy, schedule a new "%4$s" action.',
				$failed_step,
				'' !== $wpdb->last_error ? $wpdb->last_error : 'unknown database error',
				$last_product_id,
				self::GIFTING_CRON_HOOK
			)
		);
	}

	/**
	 * Display an admin notice reporting the gifting migration status.
	 *
	 * Four states. While a run is open it shows a progress notice, in one of two forms: "preparing" before
	 * the first batch has counted the catalogue, and a counted "X of Y" once it has. Once the final batch
	 * completes it shows a dismissible "finished" notice, which reports the failure count when the run
	 * recorded any. Dismissing clears the completion flag and the tally.
	 *
	 * @since 9.2.0
	 */
	public static function display_gifting_migration_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Handle dismissal of the completion notice.
		if ( isset( $_GET['_wcsnonce'], $_GET['woocommerce_subscriptions_dismiss_gifting_migration_notice'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wcsnonce'] ) ), 'woocommerce_subscriptions_dismiss_gifting_migration_notice' ) ) {
			delete_option( self::GIFTING_COMPLETE_OPTION );
			delete_option( self::GIFTING_FAILURE_COUNT_OPTION );
			return;
		}

		// Gate on the run being open rather than on the total being positive. The total is written as a `0`
		// sentinel when the run is scheduled and only replaced with the real count by the first batch, so a
		// positive-total check shows nothing at all in between - and if Action Scheduler never gets to that
		// first batch, nothing ever. The store is unsettled for that whole time, so the merchant should see it.
		if ( self::is_gifting_migration_in_progress() ) {
			$total  = (int) get_option( self::GIFTING_TOTAL_OPTION, 0 );
			$notice = new WCS_Admin_Notice( 'notice notice-info' );

			if ( $total < 1 ) {
				// Scheduled, not yet counted. No denominator to report, and dividing by it would fatal.
				$notice->set_simple_content(
					__( 'WooCommerce Subscriptions is preparing to migrate your existing product data. This runs in the background — please don\'t downgrade Subscriptions until it finishes.', 'woocommerce-subscriptions' )
				);
				$notice->display();
				return;
			}

			$migrated = min( (int) get_option( self::GIFTING_MIGRATED_COUNT_OPTION, 0 ), $total );
			$percent  = (int) floor( ( $migrated / $total ) * 100 );

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

		// Migration finished - show a dismissible completion notice.
		if ( 'yes' !== get_option( self::GIFTING_COMPLETE_OPTION ) ) {
			return;
		}

		$dismiss_url   = wp_nonce_url( add_query_arg( 'woocommerce_subscriptions_dismiss_gifting_migration_notice', '1' ), 'woocommerce_subscriptions_dismiss_gifting_migration_notice', '_wcsnonce' );
		$failure_count = (int) get_option( self::GIFTING_FAILURE_COUNT_OPTION, 0 );

		if ( $failure_count > 0 ) {
			// Those products kept whatever gifting value they had when the write failed, which may not be the one
			// the migration intended. Naming the count and linking the log is what lets the merchant check them,
			// since the per-product detail only exists there.
			$log_url = admin_url( 'admin.php?page=wc-status&tab=logs&source=' . WCS_Upgrade_Logger::$handle );

			$notice = new WCS_Admin_Notice( 'notice notice-warning is-dismissible', array(), $dismiss_url );
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

		$notice = new WCS_Admin_Notice( 'notice notice-success is-dismissible', array(), $dismiss_url );
		$notice->set_simple_content( __( 'WooCommerce Subscriptions has finished migrating your existing product data.', 'woocommerce-subscriptions' ) );
		$notice->display();
	}

	/**
	 * Get the next batch of product IDs to consider for the gifting migration.
	 *
	 * Products carrying an explicit `enabled`/`disabled` value are excluded: they already express a choice, and
	 * anything else stored there is not one, so it is migrated like an unset value. Variations are still
	 * selected by the query, but only variable subscription variations are migrated - every other variation
	 * resolves gifting through its parent and is skipped per product by
	 * {@see self::is_product_giftable_eligible()}.
	 *
	 * Whether each candidate can actually be gifted depends on its subscription plans, which cannot be resolved
	 * in SQL, so that check happens per product in {@see self::is_product_giftable_eligible()}.
	 *
	 * @since 9.2.0
	 *
	 * @param int $last_product_id The last product ID that was processed.
	 * @return int[]|null Array of product IDs, or null when the query failed and no answer exists.
	 */
	private static function get_gifting_products_to_migrate( $last_product_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				WHERE p.post_type IN ( 'product', 'product_variation' )
				AND p.post_status != 'auto-draft'
				AND p.ID > %d
				AND p.ID <= %d
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
					AND pm.meta_key = '_subscription_gifting'
					AND pm.meta_value IN ( 'enabled', 'disabled' )
				)
				ORDER BY p.ID ASC
				LIMIT %d",
				$last_product_id,
				self::get_gifting_migration_max_id(),
				self::$gifting_batch_size
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// A failed query returns the same empty array as a finished catalogue. The caller must be able to
		// tell them apart - treating a failure as end-of-data would settle the store as a false success.
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return array_map( 'absint', $product_ids );
	}

	/**
	 * Count the products the gifting migration will process.
	 *
	 * Mirrors the criteria used by {@see self::get_gifting_products_to_migrate()} (minus the ID cursor and batch
	 * limit) so the progress notice's denominator matches what the batches iterate over. Captured once at the
	 * start of the run because the candidate set shrinks as products gain an explicit value.
	 *
	 * @since 9.2.0
	 *
	 * @return int|null The count, or null when the query failed and no answer exists.
	 */
	private static function count_gifting_products_to_migrate() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID)
				FROM {$wpdb->posts} p
				WHERE p.post_type IN ( 'product', 'product_variation' )
				AND p.post_status != 'auto-draft'
				AND p.ID <= %d
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
					AND pm.meta_key = '_subscription_gifting'
					AND pm.meta_value IN ( 'enabled', 'disabled' )
				)",
				self::get_gifting_migration_max_id()
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// A failed query and an empty catalogue both come back as a falsy value; only the former must pause
		// the run instead of being stored as the progress denominator.
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return (int) $count;
	}

	/**
	 * The highest post ID this run will consider.
	 *
	 * Falls back to PHP_INT_MAX rather than 0 if the option is somehow missing: an absent ceiling migrates
	 * more than intended, while a zero ceiling would silently match nothing and report the run complete.
	 *
	 * @since 9.2.0
	 *
	 * @return int
	 */
	private static function get_gifting_migration_max_id() {
		$max_id = (int) get_option( self::GIFTING_MAX_ID_OPTION, 0 );

		return $max_id > 0 ? $max_id : PHP_INT_MAX;
	}

	/**
	 * Whether a product is one the gifting feature can apply to.
	 *
	 * Mirrors the eligibility half of {@see WCSG_Product::is_giftable()}: subscription product types, plus
	 * products purchasable through a subscription plan. Only these ever consulted the storewide default, so
	 * only these have a value to materialize.
	 *
	 * @since 9.2.0
	 *
	 * @param WC_Product $product The product to check.
	 * @return bool
	 */
	private static function is_product_giftable_eligible( $product ) {
		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
			return true;
		}

		// Only variable subscription variations carry a per-variation gifting value, and those are subscription
		// types ('subscription_variation') caught above. A plain variation resolves gifting through its
		// parent, so the migration must write the parent row and leave the variation alone - a stamped
		// variation row would be dead data at best, and would shadow the parent's setting on stores running
		// code that read variation meta directly. The exact-type comparison is deliberate: get_parent_id()
		// alone is not a variation test (core stores post_parent for every product type, and a plan product
		// with a stray post_parent must stay eligible on its own), and is_type( 'variation' ) is aliased by
		// WC_Product_Subscription_Variation to answer true as well. Mirrors
		// WC_Subscriptions_Product::is_gifting_enabled_for_product(); change both together.
		if ( 'variation' === $product->get_type() ) {
			return false;
		}

		// The standalone Gifting extension ships its own WCSG_Product without the subscription plans check.
		if ( ! method_exists( 'WCSG_Product', 'product_has_subscription_plans' ) ) {
			return false;
		}

		return WCSG_Product::product_has_subscription_plans( $product );
	}

	/**
	 * Whether the gifting feature is enabled storewide.
	 *
	 * The standalone Gifting extension can provide an older WCSG_Admin without the integrated feature API.
	 *
	 * @since 9.2.0
	 *
	 * @return bool
	 */
	private static function is_gifting_enabled() {
		return method_exists( 'WCSG_Admin', 'is_gifting_enabled' ) && WCSG_Admin::is_gifting_enabled();
	}

	/**
	 * Whether the legacy storewide default enables gifting for all products.
	 *
	 * @since 9.2.0
	 *
	 * @return bool
	 */
	private static function is_gifting_enabled_for_all_products() {
		return method_exists( 'WCSG_Admin', 'is_gifting_enabled_for_all_products' ) && WCSG_Admin::is_gifting_enabled_for_all_products();
	}
}
