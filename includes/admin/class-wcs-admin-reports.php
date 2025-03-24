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
		// The subscription reports are incompatible with stores running HPOS with sycning disabled.
		if ( wcs_is_custom_order_tables_usage_enabled() && ! wcs_is_custom_order_tables_data_sync_enabled() ) {
			add_action( 'admin_notices', [ __CLASS__, 'display_hpos_incompatibility_notice' ] );
			return;
		}

		// Add the reports layout to the WooCommerce -> Reports admin section
		add_filter( 'woocommerce_admin_reports', __CLASS__ . '::initialize_reports', 12, 1 );

		// Add any necessary scripts
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::reports_scripts' );

		// Add any actions we need based on the screen
		add_action( 'current_screen', __CLASS__ . '::conditional_reporting_includes' );
	}

	/**
	 * Displays an admin notice indicating subscription reports are disabled on HPOS environments with no syncing.
	 */
	public static function display_hpos_incompatibility_notice() {
		$screen = get_current_screen();

		// Only display the admin notice on report admin screens.
		if ( ! $screen || 'woocommerce_page_wc-reports' !== $screen->id ) {
			return;
		}

		$admin_notice = new WCS_Admin_Notice( 'error' );

		$admin_notice->set_html_content(
			sprintf(
				'<p><strong>%s</strong></p><p>%s</p>',
				_x( 'WooCommerce Subscriptions - Reports Not Available', 'heading used in an admin notice', 'woocommerce-subscriptions' ),
				sprintf(
					// translators: placeholders $1 and $2 are opening <a> tags linking to the WooCommerce documentation on HPOS, and to the Advanced Feature settings screen. Placeholder $3 is a closing link (<a>) tag.
					__( 'Subscription reports are incompatible with the %1$sWooCommerce data storage features%3$s enabled on your store. Please %2$senable compatibility mode%3$s if you wish to use subscription reports.', 'woocommerce-subscriptions' ),
					'<a href="https://woocommerce.com/document/high-performance-order-storage/">',
					'<a href="' . esc_url( get_admin_url( null, 'admin.php?page=wc-settings&tab=advanced&section=features' ) ) . '">',
					'</a>'
				)
			)
		);
		$admin_notice->display();
	}

	/**
	 * Add the 'Subscriptions' report type to the WooCommerce reports screen.
	 *
	 * @param array Array of Report types & their labels, excluding the Subscription product type.
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

		switch ( $screen->id ) {
			case 'dashboard':
				new WCS_Report_Dashboard();
				break;
		}

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
