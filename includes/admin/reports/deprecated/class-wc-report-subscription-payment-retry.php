<?php
/**
 * Subscriptions Admin Report - Subscription Events by Date
 *
 * Creates the subscription admin reports area.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 * @deprecated In favor of WCS_Report_Subscription_Payment_Retry
 */
class WC_Report_Subscription_Payment_Retry extends WCS_Report_Subscription_Payment_Retry {
	public function __construct() {
		wcs_deprecated_function( __CLASS__, '2.4.0', get_parent_class( __CLASS__ ) );
	}
}
