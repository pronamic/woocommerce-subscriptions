<?php
/**
 * Subscriptions Admin Report - Upcoming Recurring Revenue
 *
 * Display the renewal order count and revenue that will be processed for all currently active subscriptions
 * for a given period of time in the future.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 * @deprecated In favor of WCS_Report_Upcoming_Recurring_Revenue
 */
class WC_Report_Upcoming_Recurring_Revenue extends WCS_Report_Upcoming_Recurring_Revenue {
	public function __construct() {
		wcs_deprecated_function( __CLASS__, '2.4.0', get_parent_class( __CLASS__ ) );
	}
}
