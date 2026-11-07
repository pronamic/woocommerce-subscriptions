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
	 * Personal data was removed by an administrator using the subscriptions list table bulk action.
	 *
	 * @since 9.4.0
	 */
	const SOURCE_BULK_ACTION = 'bulk_action';

	/**
	 * Personal data was removed while fulfilling a personal data erasure request.
	 *
	 * @since 9.4.0
	 */
	const SOURCE_ERASURE_REQUEST = 'erasure_request';

	/**
	 * Personal data was removed by the scheduled personal data retention cleanup.
	 *
	 * @since 9.4.0
	 */
	const SOURCE_RETENTION_CLEANUP = 'retention_cleanup';

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

		return self::erase_subscription_data_and_generate_response( $subscriptions, 10, self::SOURCE_ERASURE_REQUEST );
	}

	/**
	 * Erase personal data from an array of subscriptions and generate an eraser response.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param  array  $subscriptions An array of WC_Subscription objects.
	 * @param  int    $limit The number of subscriptions erased in each batch. Optional. Default is 10.
	 * @param  string $source What triggered the removal, recorded on the subscription. Optional. Default is '',
	 *                        which records the removal without naming a source.
	 * @return array An array of response data to return to the WP eraser.
	 */
	public static function erase_subscription_data_and_generate_response( $subscriptions, $limit = 10, $source = '' ) {
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
					self::remove_subscription_personal_data( $subscription, $source );

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
	 * @param string          $source What triggered the removal, recorded on the subscription. One of the
	 *                                self::SOURCE_* values, or an identifier of the calling code's own,
	 *                                which is named in the note and logged. Optional. Default is '',
	 *                                which records the removal without naming a source.
	 */
	public static function remove_subscription_personal_data( $subscription, $source = '' ) {
		// The source can arrive through the 'woocommerce_remove_subscription_personal_data' action, so don't trust its type.
		$source          = is_scalar( $source ) ? (string) $source : '';
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
			// The subscription is being anonymized, so the customer gets no cancellation email.
			WC_Subscriptions_Email::run_without_customer_cancelled_email(
				$subscription->get_id(),
				static function () use ( $subscription ) {
					$subscription->update_status( 'cancelled' );
				}
			);
		}

		/**
		 * Expose props and data types we'll be anonymizing.
		 *
		 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
		 * @param array    $props Keys are the prop names, values are the data type we'll be passing to wp_privacy_anonymize_data().
		 * @param WC_Subscription $subscription A subscription object.
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
				 * @param string     $anonymized_data Value of this prop after anonymization.
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

		// A source this class does not define reaches the note as given. Log it too, with a backtrace, so the
		// code behind it can be identified: the note names the source but not what called this method.
		if ( '' !== $source && ! self::is_known_source( $source ) ) {
			wc_get_logger()->info(
				sprintf( 'Personal data removed from subscription #%1$s with the source "%2$s", which WCS_Privacy_Erasers does not define. The subscription note records the source as given.', $subscription->get_id(), $source ),
				array( 'backtrace' => true )
			);
		}

		// Add note that this event occurred, recording what triggered it so the removal can be explained later.
		$subscription->add_order_note( self::get_personal_data_removal_note( $source, $subscription ), 0, self::source_is_attributable_to_user( $source ) );

		/**
		 * Allow extensions to remove their own personal data for this subscription.
		 *
		 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
		 * @param WC_Subscription $subscription A subscription object.
		 */
		do_action( 'woocommerce_privacy_remove_subscription_personal_data', $subscription );
	}

	/**
	 * Get the note recording that personal data was removed from a subscription.
	 *
	 * Every removal path erases the subscription's existing notes, so this note is often the only
	 * record left of why the data went missing. Naming the source lets a store explain the removal
	 * when a customer asks about it later.
	 *
	 * A source this class does not recognise is named in the note as given, rather than falling back
	 * to the unattributed note, so a removal triggered by code outside this class still leaves a
	 * trail. Use the 'woocommerce_subscriptions_privacy_remove_subscription_personal_data_note' filter
	 * to give such a source a note of its own.
	 *
	 * @since 9.4.0
	 * @param string               $source What triggered the removal. One of the self::SOURCE_* values, an
	 *                                     identifier of the calling code's own, or '' when no source was given.
	 * @param WC_Subscription|null $subscription The subscription the data was removed from. Optional.
	 * @return string The note to add to the subscription.
	 */
	protected static function get_personal_data_removal_note( $source, $subscription = null ) {
		switch ( $source ) {
			case self::SOURCE_BULK_ACTION:
				$note = __( 'Personal data removed via the subscriptions bulk action.', 'woocommerce-subscriptions' );
				break;
			case self::SOURCE_ERASURE_REQUEST:
				$note = __( 'Personal data removed following a personal data erasure request.', 'woocommerce-subscriptions' );
				break;
			case self::SOURCE_RETENTION_CLEANUP:
				$note = __( 'Personal data removed by the personal data retention cleanup.', 'woocommerce-subscriptions' );
				break;
			case '':
				// No source given. Keeps the note this method has produced since 2.2.20 for callers which pass none.
				$note = __( 'Personal data removed.', 'woocommerce-subscriptions' );
				break;
			default:
				/* Translators: %s source identifier passed by the code which removed the data. */
				$note = sprintf( __( 'Personal data removed (source: %s).', 'woocommerce-subscriptions' ), $source );
				break;
		}

		/**
		 * Filter the note recording that personal data was removed from a subscription.
		 *
		 * Lets code which removes personal data with its own source record a note describing it.
		 *
		 * @since 9.4.0
		 * @param string               $note The note to add to the subscription.
		 * @param string               $source What triggered the removal.
		 * @param WC_Subscription|null $subscription The subscription the data was removed from.
		 */
		$filtered_note = apply_filters( 'woocommerce_subscriptions_privacy_remove_subscription_personal_data_note', $note, $source, $subscription );

		// A callback can return anything, and an empty note would leave no record of the removal.
		return ( is_string( $filtered_note ) && '' !== trim( $filtered_note ) ) ? $filtered_note : $note;
	}

	/**
	 * Determine whether a removal source should credit the note to the current user.
	 *
	 * Only sources an administrator triggers by hand are attributed. The retention cleanup runs on a
	 * scheduled action which can be dispatched during any request, so attributing it would credit
	 * whoever happened to trigger the queue. An unrecognised source is not attributed either: this
	 * class cannot tell whether the code behind it runs in an administrator's request.
	 *
	 * @since 9.4.0
	 * @param string $source What triggered the removal. One of the self::SOURCE_* values, an identifier
	 *                       of the calling code's own, or '' when no source was given.
	 * @return bool Whether to record the note against the current user.
	 */
	protected static function source_is_attributable_to_user( $source ) {
		return in_array( $source, array( self::SOURCE_BULK_ACTION, self::SOURCE_ERASURE_REQUEST ), true );
	}

	/**
	 * Determine whether a removal source is one this class defines.
	 *
	 * @since 9.4.0
	 * @param string $source What triggered the removal.
	 * @return bool Whether the source is one of the self::SOURCE_* values.
	 */
	protected static function is_known_source( $source ) {
		return in_array( $source, array( self::SOURCE_BULK_ACTION, self::SOURCE_ERASURE_REQUEST, self::SOURCE_RETENTION_CLEANUP ), true );
	}
}
