<?php
/**
 * Upgrade script for version 8.3.0
 *
 * @version 8.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_8_3_0 {

	/**
	 * Update Subscription email templates Subject and Heading replacing blogname with site_title.
	 *
	 * @since 8.3.0
	 */
	public static function migrate_subscription_email_templates() {

		$settings_names = array(
			'woocommerce_cancelled_subscription_settings',
			'woocommerce_customer_completed_renewal_order_settings',
			'woocommerce_customer_completed_switch_order_settings',
			'woocommerce-subscriptions_customer_notification_auto_renewal_settings',
			'woocommerce-subscriptions_customer_notification_auto_trial_expiry_settings',
			'woocommerce-subscriptions_customer_notification_manual_renewal_settings',
			'woocommerce-subscriptions_customer_notification_manual_trial_expiry_settings',
			'woocommerce-subscriptions_customer_notification_auto_trial_expiry_settings',
			'woocommerce_customer_on_hold_renewal_order_settings',
			'woocommerce_customer_renewal_invoice_settings',
			'woocommerce_expired_subscription_settings',
			'woocommerce_new_renewal_order_settings',
			'woocommerce_new_switch_order_settings',
			'woocommerce_customer_processing_renewal_order_settings',
			'woocommerce_suspended_subscription_settings',
		);

		WCS_Upgrade_Logger::add( '8.3.0 - Updating subscription email settings.' );

		foreach ( $settings_names as $settings_name ) {
			$option = get_option( $settings_name );

			if ( ! $option || ( empty( $option['subject'] && empty( $option['heading'] ) ) ) ) {
				WCS_Upgrade_Logger::add( sprintf( 'Subscription email settings not found: %s.', $settings_name ) );
				continue;
			}

			$option['subject'] = str_replace( '{blogname}', '{site_title}', $option['subject'] );
			$option['heading'] = str_replace( '{blogname}', '{site_title}', $option['heading'] );

			WCS_Upgrade_Logger::add(
				update_option( $settings_name, $option )
					? sprintf( 'Updated subscription email settings: %s.', $settings_name )
					: sprintf( 'Subscription email settings for %s were not changed.', $settings_name )
			);
		}
	}
}
