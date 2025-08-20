<?php
/**
 * Reports Admin
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * WCS_Admin_Reports Class
 *
 * Handles the reports screen.
 */
class WCS_Admin_Reports {

	/**
	 * Constructor
	 */
	public function __construct() {
		// The subscription reports are compatible with HPOS since 7.8.0.
		// We can inform users running data sync mode, that it's no longer needed.
		if (
			wcs_is_custom_order_tables_usage_enabled() &&
			wcs_is_custom_order_tables_data_sync_enabled()
		) {
			add_action( 'admin_notices', [ __CLASS__, 'display_hpos_compatibility_notice' ] );
		}

		// Add the reports layout to the WooCommerce -> Reports admin section
		add_filter( 'woocommerce_admin_reports', __CLASS__ . '::initialize_reports', 12, 1 );

		// Add any necessary scripts
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::reports_scripts' );

		// Add any actions we need based on the screen
		add_action( 'current_screen', __CLASS__ . '::conditional_reporting_includes' );

		// Starting from WooCommerce 10.0 the dashboard widget is loaded asynchronously.
		// We also need to hook into AJAX request before WooCommerce so we can attach our hook to widget rendering flow.
		add_action( 'wp_ajax_woocommerce_load_status_widget', __CLASS__ . '::init_dashboard_report', 9 );
	}

	/**
	 * Displays an admin notice indicating subscription reports are compatible with HPOS.
	 *
	 * @since 7.8.0
	 */
	public static function display_hpos_compatibility_notice() {
		$screen = get_current_screen();

		// Only display the admin notice on report admin screens.
		if ( ! $screen || 'woocommerce_page_wc-reports' !== $screen->id ) {
			return;
		}

		$nonce_name  = 'wcs_reports_hpos_compatibility_notice';
		$option_name = 'woocommerce_subscriptions_reports_hpos_compatibility_notice_dismissed';

		$is_dismissed = get_option( $option_name );

		if ( 'yes' === $is_dismissed ) {
			return;
		}

		if ( isset( $_GET['_wcsnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wcsnonce'] ) ), $nonce_name ) && ! empty( $_GET[ $nonce_name ] ) ) {
			update_option( $option_name, 'yes' );
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( $nonce_name, '1' ), $nonce_name, '_wcsnonce' );

		$admin_notice = new WCS_Admin_Notice( 'notice notice-info is-dismissible', array(), $dismiss_url );

		$content = sprintf(
			// translators: placeholders $1 and $2 are opening <a> tags linking to the WooCommerce documentation on HPOS, and to the Advanced Features settings screen. Placeholder $3 is a closing link (</a>) tag.
			__( 'WooCommerce Subscriptions now supports %1$sHigh-Performance Order Storage (HPOS)%3$s - compatibility mode is no longer required to view subscriptions reports. You can disable compatibility mode in your %2$sstore settings%3$s.', 'woocommerce-subscriptions' ),
			'<a href="https://woocommerce.com/document/high-performance-order-storage/">',
			'<a href="' . esc_url( get_admin_url( null, 'admin.php?page=wc-settings&tab=advanced&section=features' ) ) . '">',
			'</a>'
		);

		$admin_notice->set_html_content( "<p>{$content}</p>" );

		$admin_notice->display();
	}

	/**
	 * Add the 'Subscriptions' report type to the WooCommerce reports screen.
	 *
	 * @param array $reports Array of Report types & their labels, excluding the Subscription product type.
	 * @return array Array of Report types & their labels, including the Subscription product type.
	 * @since 2.1
	 */
	public static function initialize_reports( $reports ) {

		$reports['subscriptions'] = array(
			'title'   => __( 'Subscriptions', 'woocommerce-subscriptions' ),
			'reports' => array(
				'subscription_events_by_date' => array(
					'title'       => __( 'Subscription Events by Date', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WCS_Admin_Reports', 'get_report' ),
				),
				'upcoming_recurring_revenue'  => array(
					'title'       => __( 'Upcoming Recurring Revenue', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WCS_Admin_Reports', 'get_report' ),
				),
				'retention_rate'              => array(
					'title'       => __( 'Retention Rate', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WCS_Admin_Reports', 'get_report' ),
				),
				'subscription_by_product'     => array(
					'title'       => __( 'Subscriptions by Product', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WCS_Admin_Reports', 'get_report' ),
				),
				'subscription_by_customer'    => array(
					'title'       => __( 'Subscriptions by Customer', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WCS_Admin_Reports', 'get_report' ),
				),
			),
		);

		if ( WCS_Retry_Manager::is_retry_enabled() ) {
			$reports['subscriptions']['reports']['subscription_payment_retry'] = array(
				'title'       => __( 'Failed Payment Retries', 'woocommerce-subscriptions' ),
				'description' => '',
				'hide_title'  => true,
				'callback'    => array( 'WCS_Admin_Reports', 'get_report' ),
			);
		}

		return $reports;
	}

	/**
	 * Add any subscriptions report javascript to the admin pages.
	 *
	 * @since 1.5
	 */
	public static function reports_scripts() {
		$suffix         = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$screen         = get_current_screen();
		$wc_screen_id   = sanitize_title( __( 'WooCommerce', 'woocommerce-subscriptions' ) );
		$version        = WC_Subscriptions_Core_Plugin::instance()->get_library_version();

		// Reports Subscriptions Pages
		if ( in_array( $screen->id, apply_filters( 'woocommerce_reports_screen_ids', array( $wc_screen_id . '_page_wc-reports', 'toplevel_page_wc-reports', 'dashboard' ) ) ) && isset( $_GET['tab'] ) && 'subscriptions' == $_GET['tab'] ) {

			wp_enqueue_script( 'wcs-reports', WC_Subscriptions_Plugin::instance()->get_plugin_directory_url( 'assets/js/admin/reports.js' ), array( 'jquery', 'jquery-ui-datepicker', 'wc-reports', 'accounting' ), $version );

			// Add currency localisation params for axis label
			wp_localize_script( 'wcs-reports', 'wcs_reports', array(
				'currency_format_num_decimals' => wc_get_price_decimals(),
				'currency_format_symbol'       => get_woocommerce_currency_symbol(),
				'currency_format_decimal_sep'  => esc_js( wc_get_price_decimal_separator() ),
				'currency_format_thousand_sep' => esc_js( wc_get_price_thousand_separator() ),
				'currency_format'              => esc_js( str_replace( array( '%1$s', '%2$s' ), array( '%s', '%v' ), get_woocommerce_price_format() ) ), // For accounting JS
			) );

			wp_enqueue_script( 'flot-order', WC_Subscriptions_Plugin::instance()->get_plugin_directory_url( 'assets/js/admin/jquery.flot.orderBars' ) . $suffix . '.js', array( 'jquery', 'flot' ), $version );
			wp_enqueue_script( 'flot-axis-labels', WC_Subscriptions_Plugin::instance()->get_plugin_directory_url( 'assets/js/admin/jquery.flot.axislabels' ) . $suffix . '.js', array( 'jquery', 'flot' ), $version );

			// Add tracks script if tracking is enabled.
			if ( 'yes' === get_option( 'woocommerce_allow_tracking', 'no' ) ) {
				wp_enqueue_script( 'wcs-tracks', WC_Subscriptions_Plugin::instance()->get_plugin_directory_url( 'assets/js/admin/tracks.js' ), array( 'jquery' ), $version, true );
			}
		}
	}

	/**
	 * Add any reporting files we may need conditionally
	 *
	 * @since 2.1
	 */
	public static function conditional_reporting_includes() {

		$screen = get_current_screen();

		// Before WooCommerce 10.0 the dashboard widget was loaded synchronously on the dashboard screen. Keep this for backward compatibility.
		if ( isset( $screen->id ) && 'dashboard' === $screen->id ) {
			self::init_dashboard_report();
		}
	}

	/**
	 * Initialize the dashboard report.
	 *
	 * Used for loading the dashboard widget sync and async.
	 */
	public static function init_dashboard_report() {
		new WCS_Report_Dashboard();
	}

	/**
	 * Get a report from one of our classes.
	 *
	 * @param string $name report name to be fetched.
	 */
	public static function get_report( $name ) {
		$name  = sanitize_title( str_replace( '_', '-', $name ) );
		$class = 'WCS_Report_' . str_replace( '-', '_', $name );

		if ( ! class_exists( $class ) ) {
			return;
		}

		$report = new $class();
		$report->output_report();

		if ( class_exists( 'WC_Tracks' ) ) {

			$reports = array(
				'subscription-events-by-date' => 'subscriptions_report_events_by_date_view',
				'upcoming-recurring-revenue'  => 'subscriptions_report_upcoming_recurring_revenue_view',
				'retention-rate'              => 'subscriptions_report_retention_rate_view',
				'subscription-by-product'     => 'subscriptions_report_by_product_view',
				'subscription-by-customer'    => 'subscriptions_report_by_customer_view',
				'subscription-payment-retry'  => 'subscriptions_report_payment_retry_view',
			);

			$properties = array(
				'orders_count'          => array_sum( (array) wp_count_posts( 'shop_order' ) ),
				'subscriptions_count'   => array_sum( (array) wp_count_posts( 'shop_subscription' ) ),
				'subscriptions_version' => WC_Subscriptions_Plugin::instance()->get_plugin_version(), // get_plugin_version() is used here to report the correct WCS version.
			);

			if ( in_array( $name, array( 'subscription-events-by-date', 'upcoming-recurring-revenue', 'subscription-payment-retry' ), true ) ) {
				$properties['range'] = ! empty( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7day'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.NonceVerification.Recommended
				if ( 'custom' === $properties['range'] ) {
					// We have to get start date from _GET variables since $report sets this far into the past when empty.
					$properties['start_date'] = ! empty( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.NonceVerification.Recommended
					$properties['end_date']   = gmdate( 'Y-m-d', $report->end_date );
					$properties['span']       = $properties['start_date'] ? floor( ( $report->end_date - $report->start_date ) / DAY_IN_SECONDS ) + 1 . 'day' : null;
				}
			}

			WC_Tracks::record_event( $reports[ $name ], $properties );
		}
	}
}
