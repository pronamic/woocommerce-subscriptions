<?php
/**
 * Subscriptions Admin Report - Dashboard Stats
 *
 * Creates the subscription admin reports area.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Admin_Reports
 * @category	Class
 * @author		Prospress
 * @since		2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Report_Dashboard {

	/**
	 * Hook in additional reporting to WooCommerce dashboard widget
	 */
	public function __construct() {

			// Add the dashboard widget text
			add_action( 'woocommerce_after_dashboard_status_widget', __CLASS__ . '::add_stats_to_dashboard' );

			// Add any necessary scripts / styles
			add_action( 'admin_enqueue_scripts', __CLASS__ . '::dashboard_scripts' );
	}

	/**
	 * Add the subscription specific details to the bottom of the dashboard widget
	 *
	 * @since 2.1
	 */
	public static function add_stats_to_dashboard() {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT wcsubs.ID) AS count
				FROM {$wpdb->posts} AS wcsubs
				INNER JOIN {$wpdb->posts} AS wcorder
					ON wcsubs.post_parent = wcorder.ID
				WHERE wcorder.post_type IN ( 'shop_order' )
					AND wcsubs.post_type IN ( 'shop_subscription' )
					AND wcorder.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
					AND wcorder.post_date >= '%s'
					AND wcorder.post_date < '%s'",
			date( 'Y-m-01', current_time( 'timestamp' ) ),
			date( 'Y-m-d H:i:s', current_time( 'timestamp' ) )
		);

		$signup_count = $wpdb->get_var( apply_filters( 'woocommerce_subscription_dashboard_status_widget_signup_query', $query ) );

		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT wcorder.ID) AS count
				FROM {$wpdb->posts} AS wcorder
				INNER JOIN {$wpdb->postmeta} AS meta__subscription_renewal
					ON (
						wcorder.id = meta__subscription_renewal.post_id
						AND
						meta__subscription_renewal.meta_key = '_subscription_renewal'
					)
				WHERE wcorder.post_type IN ( 'shop_order' )
					AND wcorder.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
					AND wcorder.post_date >= '%s'
					AND wcorder.post_date < '%s'",
			date( 'Y-m-01', current_time( 'timestamp' ) ),
			date( 'Y-m-d H:i:s', current_time( 'timestamp' ) )
		);

		$renewal_count = $wpdb->get_var( apply_filters( 'woocommerce_subscription_dashboard_status_widget_renewal_query', $query ) );

		?>
		<li class="signup-count">
			<a href="<?php echo esc_html( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date' ) ); ?>">
				<?php printf( wp_kses_post( _n( '<strong>%s signup</strong> subscription signups this month', '<strong>%s signups</strong> subscription signups this month', $signup_count, 'woocommerce-subscriptions' ) ), esc_html( $signup_count ) ); ?>
			</a>
		</li>
		<li class="renewal-count">
			<a href="<?php echo esc_html( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date' ) ); ?>">
				<?php printf( wp_kses_post( _n( '<strong>%s renewal</strong> subscription renewals this month', '<strong>%s renewals</strong> subscription renewals this month', $renewal_count, 'woocommerce-subscriptions' ) ), esc_html( $renewal_count ) ); ?>
			</a>
		</li>
		<?php

	}

	/**
	 * Add the subscription specific details to the bottom of the dashboard widget
	 *
	 * @since 2.1
	 */
	public static function dashboard_scripts() {
		wp_enqueue_style( 'wcs-dashboard-report', plugin_dir_url( WC_Subscriptions::$plugin_file ) . 'assets/css/dashboard.css', array(), WC_Subscriptions::$version );
	}
}

return new WCS_Report_Dashboard();
