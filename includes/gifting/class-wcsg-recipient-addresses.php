<?php
/**
 * Address update handling.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Allow for updating subscription addresses taking into consideration purchaser/recipient subscriptions.
 */
class WCSG_Recipient_Addresses {

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {

		add_filter( 'wcs_get_users_subscriptions', __CLASS__ . '::get_users_subscriptions', 100, 2 );

		add_filter( 'woocommerce_form_field_checkbox', __CLASS__ . '::display_update_all_addresses_notice', 1, 2 );
	}

	/**
	 * Returns the subset of user subscriptions which should be included when updating all subscription addresses.
	 * When setting shipping addresses only include those which the user has purchased for themselves or have been gifted to them.
	 * When setting billing addresses only include subscriptions that belong to the user and those they have gifted to another user.
	 *
	 * @param array $subscriptions Array of subscriptions.
	 * @param int   $user_id       User ID.
	 * @return array
	 */
	public static function get_users_subscriptions( $subscriptions, $user_id ) {

		if ( ( 'shipping' === get_query_var( 'edit-address' ) || 'billing' === get_query_var( 'edit-address' ) ) && ! isset( $_GET['subscription'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// We dont want to update the shipping address of subscriptions the user isn't the recipient of.
			if ( 'shipping' === get_query_var( 'edit-address' ) ) {

				foreach ( $subscriptions as $subscription_id => $subscription ) {
					$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );

					if ( ! empty( $recipient_user_id ) && $recipient_user_id != $user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
						unset( $subscriptions[ $subscription_id ] );
					}
				}
			} elseif ( 'billing' === get_query_var( 'edit-address' ) ) {

				// We dont want to update the billing address of gifted subscriptions for this user.
				foreach ( $subscriptions as $subscription_id => $subscription ) {
					$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );

					if ( ! empty( $recipient_user_id ) && $recipient_user_id == $user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
						unset( $subscriptions[ $subscription_id ] );
					}
				}
			}
		}

		return $subscriptions;
	}

	/**
	 * Appends a notice to the 'update all subscriptions addresses' checkbox notifing the customer that updating all
	 * subscription addresses will not update gifted subscriptions, depending on which address is being updated.
	 *
	 * @param string $field    The generated html element field string.
	 * @param string $field_id The id attribute of the html element being generated.
	 */
	public static function display_update_all_addresses_notice( $field, $field_id ) {

		if ( 'update_all_subscriptions_addresses' === $field_id && ( 'shipping' === get_query_var( 'edit-address' ) || 'billing' === get_query_var( 'edit-address' ) ) ) {

			switch ( get_query_var( 'edit-address' ) ) {
				case 'shipping':
					// Translators: 1) <strong> opening tag, 2) </strong> closing tag.
					$field = substr_replace( $field, '<small>' . sprintf( esc_html__( '%1$sNote:%2$s This will not update the shipping address of subscriptions you have purchased for others.', 'woocommerce-subscriptions' ), '<strong>', '</strong>' ) . '</small>', strpos( $field, '</p>' ), 0 );
					break;
				case 'billing':
					// Translators: 1) <strong> opening tag, 2) </strong> closing tag.
					$field = substr_replace( $field, '<small>' . sprintf( esc_html__( '%1$sNote:%2$s This will not update the billing address of subscriptions purchased for you by someone else.', 'woocommerce-subscriptions' ), '<strong>', '</strong>' ) . '</small>', strpos( $field, '</p>' ), 0 );
					break;
			}
		}

		return $field;
	}
}
