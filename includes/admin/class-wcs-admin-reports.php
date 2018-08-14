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

		// Add the reports layout to the WooCommerce -> Reports admin section
		add_filter( 'woocommerce_admin_reports',  __CLASS__ . '::initialize_reports', 12, 1 );

		// Add the reports layout to the WooCommerce -> Reports admin section
		add_filter( 'wc_admin_reports_path',  __CLASS__ . '::initialize_reports_path', 12, 3 );

		// Add any necessary scripts
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::reports_scripts' );

		// Add any actions we need based on the screen
		add_action( 'current_screen', __CLASS__ . '::conditional_reporting_includes' );

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
			'title'  => __( 'Subscriptions', 'woocommerce-subscriptions' ),
			'reports' => array(
				'subscription_events_by_date' => array(
					'title'       => __( 'Subscription Events by Date', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WC_Admin_Reports', 'get_report' ),
				),
				'upcoming_recurring_revenue' => array(
					'title'       => __( 'Upcoming Recurring Revenue', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WC_Admin_Reports', 'get_report' ),
				),
				'retention_rate' => array(
					'title'       => __( 'Retention Rate', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WC_Admin_Reports', 'get_report' ),
				),
				'subscription_by_product' => array(
					'title'       => __( 'Subscriptions by Product', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WC_Admin_Reports', 'get_report' ),
				),
				'subscription_by_customer' => array(
					'title'       => __( 'Subscriptions by Customer', 'woocommerce-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( 'WC_Admin_Reports', 'get_report' ),
				),
			),
		);

		if ( WCS_Retry_Manager::is_retry_enabled() ) {
			$reports['subscriptions']['reports']['subscription_payment_retry'] = array(
				'title'       => __( 'Failed Payment Retries', 'woocommerce-subscriptions' ),
				'description' => '',
				'hide_title'  => true,
				'callback'    => array( 'WC_Admin_Reports', 'get_report' ),
			);
		}

		return $reports;
	}

	/**
	 * If we hit one of our reports in the WC get_report function, change the path to our dir.
	 *
	 * @param report_path the parth to the report.
	 * @param name the name of the report.
	 * @param class the class of the report.
	 * @return string  path to the report template.
	 * @since 2.1
	 */
	public static function initialize_reports_path( $report_path, $name, $class ) {

		if ( in_array( strtolower( $class ), array( 'wc_report_subscription_events_by_date', 'wc_report_upcoming_recurring_revenue', 'wc_report_retention_rate', 'wc_report_subscription_by_product', 'wc_report_subscription_by_customer', 'wc_report_subscription_payment_retry' ) ) ) {
			$report_path = dirname( __FILE__ ) . '/reports/class-wcs-report-' . $name . '.php';
		}

		return $report_path;

	}

	/**
	 * Add any subscriptions report javascript to the admin pages.
	 *
	 * @since 1.5
	 */
	public static function reports_scripts() {
		global $wp_query, $post;

		$suffix       = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$screen       = get_current_screen();
		$wc_screen_id = sanitize_title( __( 'WooCommerce', 'woocommerce-subscriptions' ) );

		// Reports Subscriptions Pages
		if ( in_array( $screen->id, apply_filters( 'woocommerce_reports_screen_ids', array( $wc_screen_id . '_page_wc-reports', 'toplevel_page_wc-reports', 'dashboard' ) ) ) && isset( $_GET['tab'] ) && 'subscriptions' == $_GET['tab'] ) {

			wp_enqueue_script( 'wcs-reports', plugin_dir_url( WC_Subscriptions::$plugin_file ) . 'assets/js/admin/reports.js', array( 'jquery', 'jquery-ui-datepicker', 'wc-reports', 'accounting' ), WC_Subscriptions::$version );

			// Add currency localisation params for axis label
			wp_localize_script( 'wcs-reports', 'wcs_reports', array(
				'currency_format_num_decimals'  => wc_get_price_decimals(),
				'currency_format_symbol'        => get_woocommerce_currency_symbol(),
				'currency_format_decimal_sep'   => esc_js( wc_get_price_decimal_separator() ),
				'currency_format_thousand_sep'  => esc_js( wc_get_price_thousand_separator() ),
				'currency_format'               => esc_js( str_replace( array( '%1$s', '%2$s' ), array( '%s', '%v' ), get_woocommerce_price_format() ) ), // For accounting JS
			) );

			wp_enqueue_script( 'flot-order', plugin_dir_url( WC_Subscriptions::$plugin_file ) . 'assets/js/admin/jquery.flot.orderBars' . $suffix . '.js', array( 'jquery', 'flot' ), WC_Subscriptions::$version );
			wp_enqueue_script( 'flot-axis-labels', plugin_dir_url( WC_Subscriptions::$plugin_file ) . 'assets/js/admin/jquery.flot.axislabels' . $suffix . '.js', array( 'jquery', 'flot' ), WC_Subscriptions::$version );
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
			case 'dashboard' :
				include_once( 'reports/class-wcs-report-dashboard.php' );
			break;
		}

	}
}

new WCS_Admin_Reports();
