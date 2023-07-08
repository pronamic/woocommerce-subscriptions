<?php
/**
 * Personal data exporters.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions\Privacy
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Privacy_Exporters {

	/**
	 * Finds and exports subscription data which could be used to identify a person from an email address.
	 *
	 * Subscriptions are exported in blocks of 10 to avoid timeouts.
	 * Based on @see WC_Privacy_Exporters::order_data_exporter().
	 *
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public static function subscription_data_exporter( $email_address, $page ) {
		$done              = false;
		$page              = (int) $page;
		$user              = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.
		$data_to_export    = array();
		$subscription_args = array(
			'limit'    => 10,
			'page'     => $page,
			'customer' => array( $email_address ),
			'status'   => 'any',
		);

		if ( $user instanceof WP_User ) {
			$subscription_args['customer'][] = (int) $user->ID;
		}

		// Use the data store get_orders() function as it supports getting subscriptions from billing email or customer ID - wcs_get_subscriptions() doesn't.
		$subscriptions = WC_Data_Store::load( 'subscription' )->get_orders( $subscription_args );

		if ( 0 < count( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_subscriptions',
					'group_label' => __( 'Subscriptions', 'woocommerce-subscriptions' ),
					'item_id'     => 'subscription-' . $subscription->get_id(),
					'data'        => self::get_subscription_personal_data( $subscription ),
				);
			}
			$done = 10 > count( $subscriptions );
		} else {
			$done = true;
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Get personal data (key/value pairs) for an subscription object.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array
	 */
	protected static function get_subscription_personal_data( $subscription ) {
		$personal_data   = array();
		$props_to_export = apply_filters( 'woocommerce_privacy_export_subscription_personal_data_props', array(
			'order_number'               => __( 'Subscription Number', 'woocommerce-subscriptions' ),
			'date_created'               => __( 'Created Date', 'woocommerce-subscriptions' ),
			'total'                      => __( 'Recurring Total', 'woocommerce-subscriptions' ),
			'items'                      => __( 'Subscription Items', 'woocommerce-subscriptions' ),
			'customer_ip_address'        => __( 'IP Address', 'woocommerce-subscriptions' ),
			'customer_user_agent'        => __( 'Browser User Agent', 'woocommerce-subscriptions' ),
			'formatted_billing_address'  => __( 'Billing Address', 'woocommerce-subscriptions' ),
			'formatted_shipping_address' => __( 'Shipping Address', 'woocommerce-subscriptions' ),
			'billing_phone'              => __( 'Phone Number', 'woocommerce-subscriptions' ),
			'billing_email'              => __( 'Email Address', 'woocommerce-subscriptions' ),
		), $subscription );

		foreach ( $props_to_export as $prop => $name ) {
			$value = '';

			switch ( $prop ) {
				case 'items':
					$item_names = array();
					foreach ( $subscription->get_items() as $item ) {
						$item_names[] = $item->get_name() . ' x ' . $item->get_quantity();
					}
					$value = implode( ', ', $item_names );
					break;
				case 'date_created':
					$value = wc_format_datetime( $subscription->get_date_created(), get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
					break;
				case 'formatted_billing_address':
				case 'formatted_shipping_address':
					$value = preg_replace( '#<br\s*/?>#i', ', ', $subscription->{"get_$prop"}() );
					break;
				default:
					if ( is_callable( array( $subscription, 'get_' . $prop ) ) ) {
						$value = $subscription->{"get_$prop"}();
					}
					break;
			}

			$value = apply_filters( 'woocommerce_privacy_export_subscription_personal_data_prop', $value, $prop, $subscription );

			if ( $value ) {
				$personal_data[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}

		/**
		 * Allow extensions to register their own personal data for this subscription for the export.
		 *
		 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
		 * @param array    $personal_data Array of name value pairs to expose in the export.
		 * @param WC_Subscription $subscription A subscription object.
		 */
		$personal_data = apply_filters( 'woocommerce_privacy_export_subscription_personal_data', $personal_data, $subscription );

		return $personal_data;
	}
}
