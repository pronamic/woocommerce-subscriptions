<?php

/**
 * Subscriptions Admin Report - Subscription Events by Date
 *
 * Display important historical data for subscription revenue and events, like switches and cancellations.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 * @deprecated In favor of WCS_Report_Subscription_Events_By_Date
 */
class WC_Report_Subscription_Events_By_Date extends WCS_Report_Subscription_Events_By_Date {
	public function __construct() {
		wcs_deprecated_function( __CLASS__, '2.4.0', get_parent_class( __CLASS__ ) );
	}
}
