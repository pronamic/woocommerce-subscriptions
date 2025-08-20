<?php
/**
 * Upgrade script for version 7.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Plugin_Upgrade_7_8_0 {

	/**
	 * Check if the Gifting plugin is enabled and update the settings.
	 *
	 * @since 7.8.0
	 */
	public static function check_gifting_plugin_is_enabled() {

		WCS_Upgrade_Logger::add( 'Checking if the Gifting plugin is enabled...' );

		if ( ! is_plugin_active( 'woocommerce-subscriptions-gifting/woocommerce-subscriptions-gifting.php' ) ) {
			WCS_Upgrade_Logger::add( 'Gifting plugin is not enabled, skipping...' );
			return;
		}

		WCS_Upgrade_Logger::add( 'Gifting plugin is enabled, updating Gifting settings...' );

		update_option( 'woocommerce_subscriptions_gifting_enable_gifting', 'yes' );
		update_option( 'woocommerce_subscriptions_gifting_default_option', 'enabled' );
	}
}
