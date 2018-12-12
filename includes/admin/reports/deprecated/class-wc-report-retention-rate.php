<?php

/**
 * Subscriptions Admin Report - Retention Rate
 *
 * Find the number of periods between when each subscription is created and ends or ended
 * then plot all subscriptions using this data to provide a curve of retention rates.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 * @deprecated in favor of WCS_Report_Retention_Rate
 */
class WC_Report_Retention_Rate extends WCS_Report_Retention_Rate {
	public function __construct() {
		wcs_deprecated_function( __CLASS__, '2.4.0', get_parent_class( __CLASS__ ) );
	}
}
