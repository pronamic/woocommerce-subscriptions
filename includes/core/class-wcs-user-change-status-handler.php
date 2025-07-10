<?php
/**
 * WooCommerce Subscriptions User Change Status Handler Class
 *
 * @author      Prospress
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_User_Change_Status_Handler {

	public static function init() {
		// Check if a user is requesting to cancel their subscription
		add_action( 'wp_loaded', __CLASS__ . '::maybe_change_users_subscription', 100 );
	}

	/**
	 * Checks if the current request is by a user to change the status of their subscription, and if it is,
	 * validate the request and proceed to change to the subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_change_users_subscription() {

		if ( isset( $_GET['change_subscription_to'], $_GET['subscription_id'], $_GET['_wpnonce'] ) && ! empty( $_GET['_wpnonce'] ) ) {
			$user_id      = get_current_user_id();
			$subscription = wcs_get_subscription( absint( $_GET['subscription_id'] ) );
			$new_status   = wc_clean( wp_unslash( $_GET['change_subscription_to'] ) );

			if ( self::validate_request( $user_id, $subscription, $new_status, wc_clean( wp_unslash( $_GET['_wpnonce'] ) ) ) ) {
				self::change_users_subscription( $subscription, $new_status );

				wp_safe_redirect( $subscription->get_view_order_url() );
				exit;
			}
		}
	}

	/**
	 * Change the status of a subscription and show a notice to the user if there was an issue.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function change_users_subscription( $subscription, $new_status ) {
		/** @var WC_Subscription $subscription */
		$subscription = ( ! is_object( $subscription ) ) ? wcs_get_subscription( $subscription ) : $subscription;
		$changed      = false;

		do_action( 'woocommerce_before_customer_changed_subscription_to_' . $new_status, $subscription );

		switch ( $new_status ) {
			case 'active':
				if ( ! $subscription->needs_payment() ) {
					$subscription->update_status( $new_status );
					$subscription->add_order_note( _x( 'Subscription reactivated by the subscriber from their account page.', 'order note left on subscription after user action', 'woocommerce-subscriptions' ) );
					wc_add_notice( _x( 'Your subscription has been reactivated.', 'Notice displayed to user confirming their action.', 'woocommerce-subscriptions' ), 'success' );
					$changed = true;
				} else {
					wc_add_notice( __( 'You can not reactivate that subscription until paying to renew it. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );
				}
				break;
			case 'on-hold':
				if ( wcs_can_user_put_subscription_on_hold( $subscription ) ) {
					$subscription->update_status( $new_status );
					$subscription->add_order_note( _x( 'Subscription put on hold by the subscriber from their account page.', 'order note left on subscription after user action', 'woocommerce-subscriptions' ) );
					wc_add_notice( _x( 'Your subscription has been put on hold.', 'Notice displayed to user confirming their action.', 'woocommerce-subscriptions' ), 'success' );
					$changed = true;
				} else {
					wc_add_notice( __( 'You can not suspend that subscription - the suspension limit has been reached. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );
				}
				break;
			case 'cancelled':
				$subscription->cancel_order();
				$subscription->add_order_note( _x( 'Subscription cancelled by the subscriber from their account page.', 'order note left on subscription after user action', 'woocommerce-subscriptions' ) );
				wc_add_notice( _x( 'Your subscription has been cancelled.', 'Notice displayed to user confirming their action.', 'woocommerce-subscriptions' ), 'success' );
				$changed = true;
				break;
		}

		if ( $changed ) {
			do_action( 'woocommerce_customer_changed_subscription_to_' . $new_status, $subscription );
		}
	}

	/**
	 * Validates a user change status change request.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.0
	 *
	 * @param int             $user_id The ID of the user performing the request.
	 * @param WC_Subscription $subscription The Subscription to update.
	 * @param string          $new_status The new subscription status to validate.
	 * @param string|null     $wpnonce Optional. The nonce to validate the request or null if there's no nonce to validate.
	 *
	 * @return bool Whether the status change request is valid.
	 */
	public static function validate_request( $user_id, $subscription, $new_status, $wpnonce = null ) {
		$subscription = ( ! is_object( $subscription ) ) ? wcs_get_subscription( $subscription ) : $subscription;

		if ( ! wcs_is_subscription( $subscription ) ) {
			wc_add_notice( __( 'That subscription does not exist. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );
			return false;

		} elseif ( isset( $wpnonce ) && wp_verify_nonce( $wpnonce, $subscription->get_id() . $subscription->get_status() ) === false ) {
			wc_add_notice( __( 'Security error. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );
			return false;

		} elseif ( ! user_can( $user_id, 'edit_shop_subscription_status', $subscription->get_id() ) ) {
			wc_add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'woocommerce-subscriptions' ), 'error' );
			return false;

		} elseif ( ! $subscription->can_be_updated_to( $new_status ) ) {
			// translators: placeholder is subscription's new status, translated
			wc_add_notice( sprintf( __( 'That subscription can not be changed to %s. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), wcs_get_subscription_status_name( $new_status ) ), 'error' );
			return false;
		}

		return true;
	}
}
