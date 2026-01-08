<?php
/**
 * Upgrade script for version 8.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Plugin_Upgrade_8_3_0 {

	/**
	 * Check if the Gifting plugin is enabled and update the settings.
	 *
	 * @since 8.1.0
	 */
	public static function check_downloads_plugin_is_enabled() {

		WCS_Upgrade_Logger::add( 'Checking if the Downloads plugin is enabled...' );

		$active_plugins             = get_option( 'active_plugins', array() );
		$is_downloads_plugin_active = false;

		foreach ( $active_plugins as $plugin ) {
			if ( strpos( $plugin, 'woocommerce-subscription-downloads.php' ) !== false ) {
				$is_downloads_plugin_active = true;
				break;
			}
		}

		if ( ! $is_downloads_plugin_active ) {
			WCS_Upgrade_Logger::add( 'Downloads plugin is not enabled via active_plugins, skipping...' );
			return;
		}

		WCS_Upgrade_Logger::add( 'Downloads plugin is enabled, updating Downloads settings...' );

		update_option( WC_Subscriptions_Admin::$option_prefix . '_enable_downloadable_file_linking', 'yes' );
	}
}
