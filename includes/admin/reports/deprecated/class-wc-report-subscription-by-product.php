<?php

/**
 * Subscriptions Admin Report - Subscriptions by product
 *
 * Creates the subscription admin reports area.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 * @deprecated In favor of WCS_Report_Subscription_By_Product
 */
class WC_Report_Subscription_By_Product extends WCS_Report_Subscription_By_Product {
	public function __construct() {
		wcs_deprecated_function( __CLASS__, '2.4.0', get_parent_class( __CLASS__ ) );
		parent::__construct();
	}
}
