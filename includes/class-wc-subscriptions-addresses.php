<?php
/**
 * Subscriptions Address Class
 *
 * Hooks into WooCommerce to handle editing addresses for subscriptions (by editing the original order for the subscription)
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Addresses
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.3
 */
class WC_Subscriptions_Addresses {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.3
	 */
	public static function init() {

		add_filter( 'wcs_view_subscription_actions', __CLASS__ . '::add_edit_address_subscription_action', 10, 2 );

		add_action( 'woocommerce_after_edit_address_form_billing', __CLASS__ . '::maybe_add_edit_address_checkbox', 10 );
		add_action( 'woocommerce_after_edit_address_form_shipping', __CLASS__ . '::maybe_add_edit_address_checkbox', 10 );

		add_action( 'woocommerce_customer_save_address', __CLASS__ . '::maybe_update_subscription_addresses', 10, 2 );

		add_filter( 'woocommerce_address_to_edit', __CLASS__ . '::maybe_populate_subscription_addresses', 10 );

	}

	/**
	 * Add a "Change Shipping Address" button to the "My Subscriptions" table for those subscriptions
	 * which require shipping.
	 *
	 * @param array $all_actions The $subscription_id => $actions array with all actions that will be displayed for a subscription on the "My Subscriptions" table
	 * @param array $subscriptions All of a given users subscriptions that will be displayed on the "My Subscriptions" table
	 * @since 1.3
	 */
	public static function add_edit_address_subscription_action( $actions, $subscription ) {

		if ( $subscription->needs_shipping_address() && $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
			$actions['change_address'] = array(
				'url'  => add_query_arg( array( 'subscription' => $subscription->id ), wc_get_endpoint_url( 'edit-address', 'shipping' ) ),
				'name' => __( 'Change Address', 'woocommerce-subscriptions' ),
			);
		}

		return $actions;
	}

	/**
	 * Outputs the necessary markup on the "My Account" > "Edit Address" page for editing a single subscription's
	 * address or to check if the customer wants to update the addresses for all of their subscriptions.
	 *
	 * If editing their default shipping address, this function adds a checkbox to the to allow subscribers to
	 * also update the address on their active subscriptions. If editing a single subscription's address, the
	 * subscription key is added as a hidden field.
	 *
	 * @since 1.3
	 */
	public static function maybe_add_edit_address_checkbox() {
		global $wp;

		if ( wcs_user_has_subscription() ) {

			if ( isset( $_GET['subscription'] ) ) {

				echo '<p>' . esc_html__( 'Both the shipping address used for the subscription and your default shipping address for future purchases will be updated.', 'woocommerce-subscriptions' ) . '</p>';

				echo '<input type="hidden" name="update_subscription_address" value="' . absint( $_GET['subscription'] ) . '" id="update_subscription_address" />';

			} elseif ( ( ( isset( $wp->query_vars['edit-address'] ) && ! empty( $wp->query_vars['edit-address'] ) ) || isset( $_GET['address'] ) ) ) {

				if ( isset( $wp->query_vars['edit-address'] ) ) {
					$address_type = esc_attr( $wp->query_vars['edit-address'] ) . ' ';
				} else {
					$address_type = ( ! isset( $_GET['address'] ) ) ? esc_attr( $_GET['address'] ) . ' ' : '';
				}

				// translators: $1: address type (Shipping Address / Billing Address), $2: opening <strong> tag, $3: closing </strong> tag
				$label = sprintf( __( 'Update the %1$s used for %2$sall%3$s of my active subscriptions', 'woocommerce-subscriptions' ), wcs_get_address_type_to_display( $address_type ), '<strong>', '</strong>' );

				woocommerce_form_field( 'update_all_subscriptions_addresses', array(
					'type'  => 'checkbox',
					'class' => array( 'form-row-wide' ),
					'label' => $label,
					)
				);
			}

			wp_nonce_field( 'wcs_edit_address', '_wcsnonce' );

		}
	}

	/**
	 * When a subscriber's billing or shipping address is successfully updated, check if the subscriber
	 * has also requested to update the addresses on existing subscriptions and if so, go ahead and update
	 * the addresses on the initial order for each subscription.
	 *
	 * @param int $user_id The ID of a user who own's the subscription (and address)
	 * @since 1.3
	 */
	public static function maybe_update_subscription_addresses( $user_id, $address_type ) {

		if ( ! wcs_user_has_subscription( $user_id ) || wc_notice_count( 'error' ) > 0 || empty( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( $_POST['_wcsnonce'], 'wcs_edit_address' ) ) {
			return;
		}

		$address_type   = ( 'billing' == $address_type || 'shipping' == $address_type ) ? $address_type : '';
		$address_fields = WC()->countries->get_address_fields( esc_attr( $_POST[ $address_type . '_country' ] ), $address_type . '_' );
		$address        = array();

		foreach ( $address_fields as $key => $field ) {
			if ( isset( $_POST[ $key ] ) ) {
				$address[ str_replace( $address_type . '_', '', $key ) ] = wc_clean( $_POST[ $key ] );
			}
		}

		if ( isset( $_POST['update_all_subscriptions_addresses'] ) ) {

			$users_subscriptions = wcs_get_users_subscriptions( $user_id );

			foreach ( $users_subscriptions as $subscription ) {
				if ( $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
					$subscription->set_address( $address, $address_type );
				}
			}
		} elseif ( isset( $_POST['update_subscription_address'] ) ) {

			$subscription = wcs_get_subscription( intval( $_POST['update_subscription_address'] ) );

			// Update the address only if the user actually owns the subscription
			if ( ! empty( $subscription ) ) {
				$subscription->set_address( $address, $address_type );
			}

			wp_safe_redirect( $subscription->get_view_order_url() );
			exit();
		}
	}

	/**
	 * Prepopulate the address fields on a subscription item
	 *
	 * @param array $address A WooCommerce address array
	 * @since 1.5
	 */
	public static function maybe_populate_subscription_addresses( $address ) {

		if ( isset( $_GET['subscription'] ) ) {
			$subscription = wcs_get_subscription( absint( $_GET['subscription'] ) );

			foreach ( array_keys( $address ) as $key ) {
				$address[ $key ]['value'] = $subscription->$key;
			}
		}

		return $address;
	}

	/**
	 * Update the address fields on an order
	 *
	 * @param array $subscription A WooCommerce Subscription array
	 * @param array $address_fields Locale aware address fields of the form returned by WC_Countries->get_address_fields() for a given country
	 * @since 1.3
	 */
	public static function maybe_update_order_address( $subscription, $address_fields ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Order::set_address() or WC_Subscription::set_address()' );
	}
}

WC_Subscriptions_Addresses::init();
