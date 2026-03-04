<?php
/**
 * Upgrade script for version 8.5.0
 *
 * @version 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WCS_Plugin_Upgrade_8_5_0 class.
 */
class WCS_Plugin_Upgrade_8_5_0 {

	/**
	 * Enable the "show shared downloadable products" setting for stores
	 * that already have downloadable file sharing enabled.
	 *
	 * This preserves backward compatibility: existing stores continue to see
	 * downloadable products as line items on subscriptions. New activations
	 * of the downloads feature will default to the better-performing behavior
	 * (no line items).
	 *
	 * @since 8.5.0
	 */
	public static function maybe_enable_downloads_line_items() {
		WCS_Upgrade_Logger::add( 'Checking if downloads line items setting needs to be enabled for backward compatibility...' );

		if ( ! WC_Subscription_Downloads_Settings::is_enabled() ) {
			WCS_Upgrade_Logger::add( 'Downloadable file sharing is not enabled, skipping.' );
			return;
		}

		if ( WC_Subscription_Downloads_Settings::add_line_items_enabled() ) {
			WCS_Upgrade_Logger::add( 'Downloads line items setting is already enabled, skipping.' );
			return;
		}

		WCS_Upgrade_Logger::add( 'Downloadable file sharing is enabled, enabling line items setting for backward compatibility.' );
		update_option( WC_Subscriptions_Admin::$option_prefix . '_downloads_add_line_items', 'yes' );
	}
}
