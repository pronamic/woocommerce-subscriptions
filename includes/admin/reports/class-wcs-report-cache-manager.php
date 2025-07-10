<?php
/**
 * Subscriptions Report Cache Manager
 *
 * Update report data caches on appropriate events, like renewal order payment.
 *
 * @class    WCS_Cache_Manager
 * @since    2.1
 * @package  WooCommerce Subscriptions/Classes
 * @category Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WCS_Report_Cache_Manager {

	/**
	 * Array of event => report classes to determine which reports need to be updated on certain events.
	 *
	 * The index for each report's class is specified as its used later to determine when to schedule the report and we want
	 * it to be consistently at the same time, regardless of the hook which triggered the cache update. The indexes are based
	 * on the order of the reports in the menu on the WooCommerce > Reports > Subscriptions screen, which is why the indexes
	 * are not sequential (because not all reports need caching).
	 *
	 */
	private $update_events_and_classes = array(
		'woocommerce_subscriptions_reports_schedule_cache_updates' => array( // a custom hook that can be called to schedule a full cache update, used by WC_Subscriptions_Upgrader
			0 => 'WCS_Report_Dashboard',
			1 => 'WCS_Report_Subscription_Events_By_Date',
			2 => 'WCS_Report_Upcoming_Recurring_Revenue',
			4 => 'WCS_Report_Subscription_By_Product',
			5 => 'WCS_Report_Subscription_By_Customer',
		),
		'woocommerce_subscription_payment_complete'  => array( // this hook takes care of renewal, switch and initial payments
			0 => 'WCS_Report_Dashboard',
			1 => 'WCS_Report_Subscription_Events_By_Date',
			5 => 'WCS_Report_Subscription_By_Customer',
		),
		'woocommerce_subscriptions_switch_completed' => array(
			1 => 'WCS_Report_Subscription_Events_By_Date',
		),
		'woocommerce_subscription_status_changed'    => array(
			0 => 'WCS_Report_Dashboard',
			1 => 'WCS_Report_Subscription_Events_By_Date', // we really only need cancelled, expired and active status here, but we'll use a more generic hook for convenience
			5 => 'WCS_Report_Subscription_By_Customer',
		),
		'woocommerce_subscription_status_active'     => array(
			2 => 'WCS_Report_Upcoming_Recurring_Revenue',
		),
		'woocommerce_new_order_item'                 => array(
			4 => 'WCS_Report_Subscription_By_Product',
		),
		'woocommerce_update_order_item'              => array(
			4 => 'WCS_Report_Subscription_By_Product',
		),
	);

	/**
	 * Record of all the report classes to need to have the cache updated during this request. Prevents duplicate updates in the same request for different events.
	 */
	private $reports_to_update = array();

	/**
	 * The hook name to use for our WP-Cron entry for updating report cache.
	 */
	private $cron_hook = 'wcs_report_update_cache';

	/**
	 * The hook name to use for our WP-Cron entry for updating report cache.
	 */
	protected $use_large_site_cache;

	/**
	 * Attach callbacks to manage cache updates
	 *
	 * @since 2.1
	 */
	public function __construct() {
		// Our reports integration does not work if A) HPOS is enabled and B) compatibility mode is disabled.
		// In these cases, there is no reason to cache report data/to update data that was already cached.
		if ( wcs_is_custom_order_tables_usage_enabled() && ! wcs_is_custom_order_tables_data_sync_enabled() ) {
			return;
		}

		// Use the old hooks
		if ( wcs_is_woocommerce_pre( '3.0' ) ) {

			$hooks = array(
				'woocommerce_order_add_product'  => 'woocommerce_new_order_item',
				'woocommerce_order_edit_product' => 'woocommerce_update_order_item',
			);

			foreach ( $hooks as $old_hook => $new_hook ) {
				$this->update_events_and_classes[ $old_hook ] = $this->update_events_and_classes[ $new_hook ];
				unset( $this->update_events_and_classes[ $new_hook ] ); // New hooks aren't called, so no need to attach to them
			}
		}

		add_action( $this->cron_hook, array( $this, 'update_cache' ), 10, 1 );

		foreach ( $this->update_events_and_classes as $event_hook => $report_classes ) {
			add_action( $event_hook, array( $this, 'set_reports_to_update' ), 10 );
		}

		add_action( 'shutdown', array( $this, 'schedule_cache_updates' ), 10 );

		// Notify store owners that report data can be out-of-date
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 0 );

		// Add system status information.
		add_filter( 'wcs_system_status', array( $this, 'add_system_status_info' ) );

		add_action( 'woocommerce_subscriptions_upgraded', array( $this, 'transfer_large_site_cache_option' ), 10, 2 );
	}

	/**
	 * Check if the given hook has reports associated with it, and if so, add them to our $this->reports_to_update
	 * property so we know to schedule an event to update their cache at the end of the request.
	 *
	 * This function is attached as a callback on the events in the $update_events_and_classes property.
	 *
	 * @since 2.1
	 * @return void
	 */
	public function set_reports_to_update() {
		if ( isset( $this->update_events_and_classes[ current_filter() ] ) ) {
			$this->reports_to_update = array_unique( array_merge( $this->reports_to_update, $this->update_events_and_classes[ current_filter() ] ) );
		}
	}

	/**
	 * At the end of the request, schedule cache updates for any events that occured during this request.
	 *
	 * For large sites, cache updates are run only once per day to avoid overloading the DB where the queries are very resource intensive
	 * (as reported during beta testing in https://github.com/Prospress/woocommerce-subscriptions/issues/1732). We do this at 4am in the
	 * site's timezone, which helps avoid running the queries during busy periods and also runs them after all the renewals for synchronised
	 * subscriptions should have finished for the day (which begins at 3am and rarely takes more than 1 hours of processing to get through
	 * an entire queue).
	 *
	 * This function is attached as a callback on 'shutdown' and will schedule cache updates for any reports found to need updates by
	 * @see $this->set_reports_to_update().
	 *
	 * @since 2.1
	 */
	public function schedule_cache_updates() {

		if ( ! empty( $this->reports_to_update ) ) {

			// On large sites, we want to run the cache update once at 4am in the site's timezone
			if ( $this->use_large_site_cache() ) {

				$cache_update_timestamp = $this->get_large_site_cache_update_timestamp();

				// Schedule one update event for each class to avoid updating cache more than once for the same class for different events
				foreach ( $this->reports_to_update as $index => $report_class ) {

					$cron_args = array( 'report_class' => $report_class );

					if ( false === as_next_scheduled_action( $this->cron_hook, $cron_args ) ) {
						// Use the index to space out caching of each report to make them 15 minutes apart so that on large sites, where we assume they'll get a request at least once every few minutes, we don't try to update the caches of all reports in the same request
						as_schedule_single_action( $cache_update_timestamp + 15 * MINUTE_IN_SECONDS * ( $index + 1 ), $this->cron_hook, $cron_args );
					}
				}
			} else { // Otherwise, run it 10 minutes after the last cache invalidating event

				// Schedule one update event for each class to avoid updating cache more than once for the same class for different events
				foreach ( $this->reports_to_update as $index => $report_class ) {

					$cron_args = array( 'report_class' => $report_class );

					if ( false !== as_next_scheduled_action( $this->cron_hook, $cron_args ) ) {
						as_unschedule_action( $this->cron_hook, $cron_args );
					}

					// Use the index to space out caching of each report to make them 5 minutes apart so that on large sites, where we assume they'll get a request at least once every few minutes, we don't try to update the caches of all reports in the same request
					as_schedule_single_action( (int) gmdate( 'U' ) + MINUTE_IN_SECONDS * ( $index + 1 ) * 5, $this->cron_hook, $cron_args );
				}
			}
		}
	}

	/**
	 * Update the cache data for a given report, as specified with $report_class, by call it's get_data() method.
	 *
	 * @since 2.1
	 */
	public function update_cache( $report_class ) {
		/**
		 * Filter whether Report Cache Updates are enabled.
		 *
		 * @param bool   $enabled      Whether report updates are enabled.
		 * @param string $report_class The report class to use.
		 */
		if ( ! apply_filters( 'wcs_report_cache_updates_enabled', 'yes' === get_option( 'woocommerce_subscriptions_cache_updates_enabled', 'yes' ), $report_class ) ) {
			return;
		}

		// Validate the report class
		$valid_report_class = false;

		foreach ( $this->update_events_and_classes as $event_hook => $report_classes ) {
			if ( in_array( $report_class, $report_classes ) ) {
				$valid_report_class = true;
				break;
			}
		}

		if ( false === $valid_report_class ) {
			return;
		}

		// Hook our error catcher.
		add_action( 'shutdown', array( $this, 'catch_unexpected_shutdown' ) );

		// Load report class dependencies
		require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

		$within_ci_environment = getenv( 'CI' );
		$wc_core_dir_from_env  = getenv( 'WC_CORE_DIR' );

		if ( $within_ci_environment && ! empty( $wc_core_dir_from_env ) ) {
			$wc_core_dir = $wc_core_dir_from_env;
		} elseif ( $within_ci_environment ) {
			$wc_core_dir = '/tmp/woocommerce';
		} else {
			$wc_core_dir = WC()->plugin_path();
		}

		require_once( $wc_core_dir . '/includes/admin/reports/class-wc-admin-report.php' );

		$reflector = new ReflectionMethod( $report_class, 'get_data' );

		// Some report classes extend WP_List_Table which has a constructor using methods not available on WP-Cron (and unable to be loaded with a __doing_it_wrong() notice), so they have a static get_data() method and do not need to be instantiated
		if ( $reflector->isStatic() ) {
			call_user_func( array( $report_class, 'clear_cache' ) );
			call_user_func( array( $report_class, 'get_data' ), array( 'no_cache' => true ) );
		} else {
			$report = new $report_class();
			$report->clear_cache();

			// Classes with a non-static get_data() method can be displayed for different time series, so we need to update the cache for each of those ranges
			foreach ( array( 'year', 'last_month', 'month', '7day' ) as $range ) {
				$report->calculate_current_range( $range );
				$report->get_data( array( 'no_cache' => true ) );
			}
		}

		// Remove our error catcher.
		remove_action( 'shutdown', array( $this, 'catch_unexpected_shutdown' ) );
	}

	/**
	 * Boolean flag to check whether to use a the large site cache method or not, which is determined based on the number of
	 * subscriptions and orders on the site (using arbitrary counts).
	 *
	 * @since 2.1
	 * @return bool
	 */
	protected function use_large_site_cache() {

		if ( null === $this->use_large_site_cache ) {
			$this->use_large_site_cache = wcs_is_large_site();
		}

		return apply_filters( 'wcs_report_use_large_site_cache', $this->use_large_site_cache );
	}

	/**
	 * Make it clear to store owners that data for some reports can be out-of-date.
	 *
	 * @since 2.1
	 */
	public function admin_notices() {

		$screen       = get_current_screen();
		$wc_screen_id = sanitize_title( __( 'WooCommerce', 'woocommerce-subscriptions' ) );

		if ( in_array( $screen->id, apply_filters( 'woocommerce_reports_screen_ids', array( $wc_screen_id . '_page_wc-reports', 'dashboard' ) ) ) && isset( $_GET['tab'] ) && 'subscriptions' == $_GET['tab'] && ( ! isset( $_GET['report'] ) || in_array( $_GET['report'], array( 'subscription_events_by_date', 'upcoming_recurring_revenue', 'subscription_by_product', 'subscription_by_customer' ) ) ) && $this->use_large_site_cache() ) {
			wcs_add_admin_notice( __( 'Please note: data for this report is cached. The data displayed may be out of date by up to 24 hours. The cache is updated each morning at 4am in your site\'s timezone.', 'woocommerce-subscriptions' ) );
		}
	}

	/**
	 * Handle error instances that lead to an unexpected shutdown.
	 *
	 * This attempts to detect if there was an error, and proactively prevent errors
	 * from piling up.
	 *
	 * @author Jeremy Pry
	 */
	public function catch_unexpected_shutdown() {
		$error = error_get_last();
		if ( null === $error || ! isset( $error['type'] ) ) {
			return;
		}

		// Check for the error types that matter to us.
		if ( $error['type'] & ( E_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR ) ) {
			$failures = get_option( 'woocommerce_subscriptions_cache_updates_failures', 0 );
			$failures++;
			update_option( 'woocommerce_subscriptions_cache_updates_failures', $failures, false );

			/**
			 * Filter the allowed number of detected failures before we turn off cache updates.
			 *
			 * @param int $threshold The failure count threshold.
			 */
			if ( $failures > apply_filters( 'woocommerce_subscriptions_cache_updates_failures_threshold', 2 ) ) {
				update_option( 'woocommerce_subscriptions_cache_updates_enabled', 'no', false );
			}
		}
	}

	/**
	 * Add system status information to include failure count and cache update status.
	 *
	 * @author Jeremy Pry
	 *
	 * @param array $data Existing status data.
	 *
	 * @return array Filtered status data.
	 */
	public function add_system_status_info( $data ) {
		$cache_enabled = ( 'yes' === get_option( 'woocommerce_subscriptions_cache_updates_enabled', 'yes' ) );
		$failures      = get_option( 'woocommerce_subscriptions_cache_updates_failures', 0 );
		$new_data      = array(
			'wcs_report_cache_enabled'  => array(
				'name'    => _x( 'Report Cache Enabled', 'Whether the Report Cache has been enabled', 'woocommerce-subscriptions' ),
				'label'   => 'Report Cache Enabled',
				'note'    => $cache_enabled ? __( 'Yes', 'woocommerce-subscriptions' ) : __( 'No', 'woocommerce-subscriptions' ),
				'success' => $cache_enabled,
			),
			'wcs_cache_update_failures' => array(
				'name'    => __( 'Cache Update Failures', 'woocommerce-subscriptions' ),
				'label'   => 'Cache Update Failures',
				/* translators: %d refers to the number of times we have detected cache update failures */
				'note'    => sprintf( _n( '%d failures', '%d failure', $failures, 'woocommerce-subscriptions' ), $failures ),
				'success' => 0 === (int)$failures,
			),
		);

		$data = array_merge( $data, $new_data );

		return $data;
	}

	/**
	 * Get the scheduled update cache time for large sites.
	 *
	 * @return int The timestamp of the next occurring 4 am in the site's timezone converted to UTC.
	 */
	protected function get_large_site_cache_update_timestamp() {
		// Get the timestamp for 4 am in the site's timezone converted to the UTC equivalent.
		$cache_update_timestamp = wc_string_to_timestamp( '4 am', current_time( 'timestamp' ) ) - wc_timezone_offset();

		// PHP doesn't support a "next 4am" time format equivalent, so we need to manually handle getting 4am from earlier today (which will always happen when this is run after 4am and before midnight in the site's timezone)
		if ( $cache_update_timestamp <= gmdate( 'U' ) ) {
			$cache_update_timestamp += DAY_IN_SECONDS;
		}

		return $cache_update_timestamp;
	}

	/**
	 * Transfers the 'wcs_report_use_large_site_cache' option to the new 'wcs_is_large_site' option.
	 *
	 * In 3.0.7 we introduced a more general use option, 'wcs_is_large_site', replacing the need for one specifically
	 * for report caching. This function migrates the existing option value if it was previously set.
	 *
	 * @since 3.0.7
	 *
	 * @param string $new_version      The new Subscriptions plugin version.
	 * @param string $previous_version The version of Subscriptions prior to upgrade.
	 */
	public function transfer_large_site_cache_option( $new_version, $previous_version ) {

		// Check if the plugin upgrade is from a version prior to the option being deprecated (before 3.0.7).
		if ( version_compare( $previous_version, '3.0.7', '<' ) && false !== get_option( 'wcs_report_use_large_site_cache' ) ) {
			update_option( 'wcs_is_large_site', 'yes', false );
			delete_option( 'wcs_report_use_large_site_cache' );
		}
	}
}
