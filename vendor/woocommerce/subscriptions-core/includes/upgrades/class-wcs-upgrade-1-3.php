<?php
/**
 * Update Subscriptions to 1.3.0
 *
 * Upgrade cron lock values to be options rather than transients to work around potential early deletion by W3TC
 * and other caching plugins. Also add the Variable Subscription product type (if it doesn't exist).
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_1_3 {

	public static function init() {
		global $wpdb;

		// Change transient timeout entries to be a vanilla option
		$wpdb->query( " UPDATE $wpdb->options
						SET option_name = TRIM(LEADING '_transient_timeout_' FROM option_name)
						WHERE option_name LIKE '_transient_timeout_wcs_blocker_%'" );

		// Change transient keys from the < 1.1.5 format to new format
		$wpdb->query( " UPDATE $wpdb->options
						SET option_name = CONCAT('wcs_blocker_', TRIM(LEADING '_transient_timeout_block_scheduled_subscription_payments_' FROM option_name))
						WHERE option_name LIKE '_transient_timeout_block_scheduled_subscription_payments_%'" );

		// Delete old transient values
		$wpdb->query( " DELETE FROM $wpdb->options
						WHERE option_name LIKE '_transient_wcs_blocker_%'
						OR option_name LIKE '_transient_block_scheduled_subscription_payments_%'" );
	}
}
