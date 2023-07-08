<?php
/**
 * Personal data erasers.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions\Privacy
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Privacy_Erasers {

	/**
	 * Finds and erases data which could be used to identify a person from subscription data associated with an email address.
	 *
	 * Subscriptions are erased in blocks of 10 to avoid timeouts.
	 * Based on @see WC_Privacy_Erasers::order_data_eraser().
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of response data to return to the WP eraser.
	 */
	public static function subscription_data_eraser( $email_address, $page ) {
		$page              = (int) $page;
		$user              = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.
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

		return self::erase_subscription_data_and_generate_response( $subscriptions );
	}

	/**
	 * Erase personal data from an array of subscriptions and generate an eraser response.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param  array $subscriptions An array of WC_Subscription objects.
	 * @param  int   $limit The number of subscriptions erased in each batch. Optional. Default is 10.
	 * @return array An array of response data to return to the WP eraser.
	 */
	public static function erase_subscription_data_and_generate_response( $subscriptions, $limit = 10 ) {
		$erasure_enabled = wc_string_to_bool( get_option( 'woocommerce_erasure_request_removes_subscription_data', 'no' ) );
		$response        = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( 0 < count( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				if ( apply_filters( 'woocommerce_privacy_erase_subscription_personal_data', $erasure_enabled, $subscription ) ) {
					self::remove_subscription_personal_data( $subscription );

					/* Translators: %s subscription number. */
					$response['messages'][]    = sprintf( __( 'Removed personal data from subscription %s.', 'woocommerce-subscriptions' ), $subscription->get_order_number() );
					$response['items_removed'] = true;
				} else {
					/* Translators: %s subscription number. */
					$response['messages'][]     = sprintf( __( 'Personal data within subscription %s has been retained.', 'woocommerce-subscriptions' ), $subscription->get_order_number() );
					$response['items_retained'] = true;
				}
			}

			$response['done'] = $limit > count( $subscriptions );
		}

		return $response;
	}

	/**
	 * Remove personal data from a subscription object.
	 *
	 * Note; this will hinder the subscription's ability function correctly for obvious reasons!
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param WC_Subscription $subscription $subscription object.
	 */
	public static function remove_subscription_personal_data( $subscription ) {
		$anonymized_data = array();

		/**
		 * Allow extensions to remove their own personal data for this subscription first, so subscription data is still available.
		 *
		 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
		 * @param WC_Subscription $subscription A Subscription object.
		 */
		do_action( 'woocommerce_privacy_before_remove_subscription_personal_data', $subscription );

		// Cancel the subscription before removing personal data so payment gateways still have access to that data.
		if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
			$subscription->update_status( 'cancelled' );
		}

		/**
		 * Expose props and data types we'll be anonymizing.
		 *
		 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
		 * @param array    $props Keys are the prop names, values are the data type we'll be passing to wp_privacy_anonymize_data().
		 * @param WC_subscription $subscription A subscription object.
		 */
		$props_to_remove = apply_filters( 'woocommerce_privacy_remove_subscription_personal_data_props', array(
			'customer_ip_address' => 'ip',
			'customer_user_agent' => 'text',
			'billing_first_name'  => 'text',
			'billing_last_name'   => 'text',
			'billing_company'     => 'text',
			'billing_address_1'   => 'text',
			'billing_address_2'   => 'text',
			'billing_city'        => 'text',
			'billing_postcode'    => 'text',
			'billing_state'       => 'address_state',
			'billing_country'     => 'address_country',
			'billing_phone'       => 'phone',
			'billing_email'       => 'email',
			'shipping_first_name' => 'text',
			'shipping_last_name'  => 'text',
			'shipping_company'    => 'text',
			'shipping_address_1'  => 'text',
			'shipping_address_2'  => 'text',
			'shipping_city'       => 'text',
			'shipping_postcode'   => 'text',
			'shipping_state'      => 'address_state',
			'shipping_country'    => 'address_country',
			'customer_id'         => 'numeric_id',
			'transaction_id'      => 'numeric_id',
		), $subscription );

		if ( ! empty( $props_to_remove ) && is_array( $props_to_remove ) ) {
			foreach ( $props_to_remove as $prop => $data_type ) {
				// Get the current value in edit context.
				$value = $subscription->{"get_$prop"}( 'edit' );

				// If the value is empty, it does not need to be anonymized.
				if ( empty( $value ) || empty( $data_type ) ) {
					continue;
				}

				if ( function_exists( 'wp_privacy_anonymize_data' ) ) {
					$anon_value = wp_privacy_anonymize_data( $data_type, $value );
				} else {
					$anon_value = '';
				}

				/**
				 * Expose a way to control the anonymized value of a prop via 3rd party code.
				 *
				 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
				 * @param bool     $anonymized_data Value of this prop after anonymization.
				 * @param string   $prop Name of the prop being removed.
				 * @param string   $value Current value of the data.
				 * @param string   $data_type Type of data.
				 * @param WC_Subscription $subscription An subscription object.
				 */
				$anonymized_data[ $prop ] = apply_filters( 'woocommerce_privacy_remove_subscription_personal_data_prop_value', $anon_value, $prop, $value, $data_type, $subscription );
			}
		}

		// Set all new props and persist the new data to the database.
		$subscription->set_props( $anonymized_data );
		$subscription->update_meta_data( '_anonymized', 'yes' );
		$subscription->save();

		// Delete subscription notes which can contain PII.
		$notes = wc_get_order_notes( array(
			'order_id' => $subscription->get_id(),
		) );

		foreach ( $notes as $note ) {
			wc_delete_order_note( $note->id );
		}

		// Add note that this event occurred.
		$subscription->add_order_note( __( 'Personal data removed.', 'woocommerce-subscriptions' ) );

		/**
		 * Allow extensions to remove their own personal data for this subscription.
		 *
		 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
		 * @param WC_subscription $subscription A subscription object.
		 */
		do_action( 'woocommerce_privacy_remove_subscription_personal_data', $subscription );
	}
}
