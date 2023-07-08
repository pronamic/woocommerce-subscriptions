<?php
/**
 * Upgrade script for version 3.1.0
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_3_1_0 {

	/**
	 * Update Subscription webhooks with API Version set to 3, to now deliver API Version 1 payloads.
	 * This is to maintain backwards compatibility with the delivery payloads now that we have added a
	 * wc/v3/subscriptions endpoint with 3.1
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public static function migrate_subscription_webhooks_using_api_version_3() {
		global $wpdb;

		$results = $wpdb->get_results( "SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE `topic` LIKE 'subscription.%' AND `api_version` = 3" );

		if ( ! empty( $results ) && is_array( $results ) ) {
			WCS_Upgrade_Logger::add( sprintf( '3.1.0 - Updating %d subscription webhooks to use API Version 1 when building the payload to preserve backwards compatibility.', count( $results ) ) );

			foreach ( $results as $result ) {
				$webhook = ! empty( $result->webhook_id ) ? wc_get_webhook( $result->webhook_id ) : null;

				if ( $webhook ) {
					$webhook->set_api_version( 1 );
					$webhook->save();

					WCS_Upgrade_Logger::add( sprintf( 'Updated webhook: %s (#%d).', $webhook->get_name(), $webhook->get_id() ) );
				} else {
					WCS_Upgrade_Logger::add( sprintf( 'Warning! Couldn\'t find and update webhook: %s', print_r( $result, true ) ) );
				}
			}
		} else {
			WCS_Upgrade_Logger::add( '3.1.0 - No subscription webhooks found using API version 3. No updates needed.' );
		}
	}
}
