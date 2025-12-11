<?php
/**
 * Subscriptions Address Class
 *
 * Hooks into WooCommerce to handle editing addresses for subscriptions (by editing the original order for the subscription)
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Addresses
 * @category   Class
 * @author     Brent Shepherd
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v1.3
 */
class WC_Subscriptions_Addresses {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function init() {

		add_filter( 'wcs_view_subscription_actions', __CLASS__ . '::add_edit_address_subscription_action', 10, 2 );

		add_action( 'template_redirect', array( __CLASS__, 'maybe_restrict_edit_address_endpoint' ) );

		add_action( 'woocommerce_edit_account_form_fields', __CLASS__ . '::maybe_add_edit_addresses_checkbox' );

		add_action( 'woocommerce_after_edit_address_form_billing', __CLASS__ . '::maybe_add_edit_address_checkbox' );
		add_action( 'woocommerce_after_edit_address_form_shipping', __CLASS__ . '::maybe_add_edit_address_checkbox' );

		add_action( 'woocommerce_customer_save_address', __CLASS__ . '::maybe_update_subscription_addresses', 10, 2 );
		add_action( 'woocommerce_save_account_details', __CLASS__ . '::maybe_update_subscription_addresses_contact' );

		add_filter( 'woocommerce_address_to_edit', __CLASS__ . '::maybe_populate_subscription_addresses', 10 );

		add_filter( 'woocommerce_get_breadcrumb', __CLASS__ . '::change_addresses_breadcrumb', 10, 1 );
	}

	/**
	 * Checks if a user can edit a subscription's address.
	 *
	 * @param int|WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object.
	 * @param int                 $user_id      The ID of a user.
	 * @return bool Whether the user can edit the subscription's address.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.15
	 */
	private static function can_user_edit_subscription_address( $subscription, $user_id = 0 ) {
		$subscription = wcs_get_subscription( $subscription );
		$user_id      = empty( $user_id ) ? get_current_user_id() : absint( $user_id );

		return $subscription ? user_can( $user_id, 'view_order', $subscription->get_id() ) : false;
	}

	/**
	 * Add a "Change Shipping Address" button to the "My Subscriptions" table for those subscriptions
	 * which require shipping.
	 *
	 * @param array $actions The $subscription_id => $actions array with all actions that will be displayed for a subscription on the "My Subscriptions" table
	 * @param \WC_Subscription $subscription the Subscription object that is being viewed.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function add_edit_address_subscription_action( $actions, $subscription ) {
		if ( $subscription->needs_shipping_address() && $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
			$actions['change_address'] = array(
				'url'  => esc_url( add_query_arg( array( 'subscription' => $subscription->get_id() ), wc_get_endpoint_url( 'edit-address', 'shipping' ) ) ),
				'name' => __( 'Change address', 'woocommerce-subscriptions' ),
				'role' => 'link',
			);
		}

		return $actions;
	}

	/**
	 * Redirects to "My Account" when attempting to edit the address on a subscription that doesn't belong to the user.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.15
	 */
	public static function maybe_restrict_edit_address_endpoint() {
		if ( ! is_wc_endpoint_url() || 'edit-address' !== WC()->query->get_current_endpoint() || ! isset( $_GET['subscription'] ) ) {
			return;
		}

		if ( ! self::can_user_edit_subscription_address( absint( $_GET['subscription'] ) ) ) {
			wc_add_notice( 'Invalid subscription.', 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'dashboard' ) );
			exit();
		}
	}

	/**
	 * Outputs the necessary markup on the "My Account" > "Edit Address" page for editing a single subscription's
	 * address or to check if the customer wants to update the addresses for all of their subscriptions.
	 *
	 * If editing their default shipping address, this function adds a checkbox to the to allow subscribers to
	 * also update the address on their active subscriptions. If editing a single subscription's address, the
	 * subscription key is added as a hidden field.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function maybe_add_edit_address_checkbox() {
		global $wp;

		if ( wcs_user_has_subscription() ) {
			$subscription_id = isset( $_GET['subscription'] ) ? absint( $_GET['subscription'] ) : 0;

			if ( $subscription_id && self::can_user_edit_subscription_address( $subscription_id ) ) {

				echo '<p>' . esc_html__( 'Both the shipping address used for the subscription and your default shipping address for future purchases will be updated.', 'woocommerce-subscriptions' ) . '</p>';

				echo '<input type="hidden" name="update_subscription_address" value="' . esc_attr( $subscription_id ) . '" id="update_subscription_address" />';

			} elseif ( ( ( isset( $wp->query_vars['edit-address'] ) && ! empty( $wp->query_vars['edit-address'] ) ) || isset( $_GET['address'] ) ) ) {

				if ( isset( $wp->query_vars['edit-address'] ) ) {
					$address_type = esc_attr( $wp->query_vars['edit-address'] );
				} else {
					// No need to check nonce or sanitize address below as it'll be passed via wcs_get_address_type_to_display
					$address_type = ( isset( $_GET['address'] ) ) ? esc_attr( wp_unslash( $_GET['address'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}

				// translators: $1: address type (Shipping Address / Billing Address), $2: opening <strong> tag, $3: closing </strong> tag
				$label = sprintf( esc_html__( 'Update the %1$s used for %2$sall%3$s future renewals of my active subscriptions', 'woocommerce-subscriptions' ), wcs_get_address_type_to_display( $address_type ), '<strong>', '</strong>' );

				woocommerce_form_field(
					'update_all_subscriptions_addresses',
					array(
						'type'    => 'checkbox',
						'class'   => array( 'form-row-wide' ),
						'label'   => $label,
						/**
						 * Filters whether the update all subscriptions addresses checkbox should be checked by default.
						 *
						 * @param bool $checked Whether the checkbox should be checked by default.
						 * @since 2.3.7 Introduced.
						 * @since 7.5.0 Default changed to true.
						 */
						'default' => apply_filters( 'wcs_update_all_subscriptions_addresses_checked', true ),
					)
				);
			}

			wp_nonce_field( 'wcs_edit_address', '_wcsnonce' );

		}
	}

	/**
	 * Outputs the necessary markup on the "My Account" > "Edit Account" page for editing contact info (Name, Email)
	 * to check if the customer wants to update the contact info in Billing addresses for all of their active subscriptions.
	 *
	 * @since 7.5.0
	 */
	public static function maybe_add_edit_addresses_checkbox() {
		global $wp;

		// Escape early because we're not on the edit account page.
		if ( ! isset( $wp->query_vars['edit-account'] ) ) {
			return;
		}

		// No need to render UI if user doesn't have subscriptions
		if ( ! wcs_user_has_subscription() ) {
			return;
		}

		// translators: $1: address type (Billing Address), $2: opening <strong> tag, $3: closing </strong> tag
		$label = sprintf( esc_html__( 'Update the %1$s contact used for %2$sall%3$s future renewals of my active subscriptions', 'woocommerce-subscriptions' ), wcs_get_address_type_to_display( 'billing' ), '<strong>', '</strong>' );
		woocommerce_form_field(
			'update_all_subscriptions_billing_contact',
			array(
				'type'    => 'checkbox',
				'class'   => array( 'form-row-wide' ),
				'label'   => $label,
				'default' => true, // Default to checked, intentionally not passed through the filter.
			)
		);

		// Note, there is no need to add one more nonce here, we'll rely on existing save-account-details-nonce.
	}

	/**
	 * When user's contact info is successfully updated, check if the subscriber
	 * has also requested to update the contact info in addresses on existing subscriptions and if so, go ahead and update
	 * the addresses on the initial order for each subscription.
	 *
	 * @param int $user_id The ID of a user who own's the subscription (and address)
	 * @since 7.5.0
	 */
	public static function maybe_update_subscription_addresses_contact( $user_id ) {
		// Verify nonce will take care of validation, and wc_get_var will check if the value is set.
		$nonce = isset( $_POST['save-account-details-nonce'] ) ? wp_unslash( $_POST['save-account-details-nonce'] ) : ( isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = wc_get_var( $nonce, '' );
		if ( ! wp_verify_nonce( $nonce, 'save_account_details' ) ) {
			return;
		}

		// Verify that the current user is updating their own account
		$current_user_id = get_current_user_id();
		if ( $current_user_id <= 0 || $current_user_id !== $user_id ) {
			return;
		}

		// Check if user has subscriptions and if they've checked the update checkbox
		if ( ! wcs_user_has_subscription( $current_user_id ) || ! isset( $_POST['update_all_subscriptions_billing_contact'] ) || wc_notice_count( 'error' ) > 0 ) {
			return;
		}

		// Get user data directly from the user object instead of POST data
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		// Get the contact information from the user object
		$contact_info = array(
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'email'      => $user->user_email,
		);

		// Only proceed if we have contact information to update
		if ( empty( $contact_info['first_name'] ) && empty( $contact_info['last_name'] ) && empty( $contact_info['email'] ) ) {
			return;
		}

		// Get all active subscriptions for the user
		$users_subscriptions = wcs_get_users_subscriptions( $user_id );

		// Update the billing contact info for each active subscription
		foreach ( $users_subscriptions as $subscription ) {
			if ( $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
				// Update the billing address with the new contact information
				wcs_set_order_address( $subscription, $contact_info, 'billing' );
				$subscription->save();
			}
		}
	}

	/**
	 * When a subscriber's billing or shipping address is successfully updated, check if the subscriber
	 * has also requested to update the addresses on existing subscriptions and if so, go ahead and update
	 * the addresses on the initial order for each subscription.
	 *
	 * @param int $user_id The ID of a user who own's the subscription (and address)
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function maybe_update_subscription_addresses( $user_id, $address_type ) {

		if ( ! wcs_user_has_subscription( $user_id ) || ! isset( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcsnonce'] ) ), 'wcs_edit_address' ) || wc_notice_count( 'error' ) > 0 ) {
			return;
		}

		$address_type   = ( 'billing' === $address_type || 'shipping' === $address_type ) ? $address_type : '';
		$address_fields = WC()->countries->get_address_fields( esc_attr( wc_clean( wp_unslash( $_POST[ $address_type . '_country' ] ) ) ), $address_type . '_' );
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
					wcs_set_order_address( $subscription, $address, $address_type );
					$subscription->save();
				}
			}
		} elseif ( isset( $_POST['update_subscription_address'] ) ) {
			$subscription = wcs_get_subscription( absint( $_POST['update_subscription_address'] ) );

			// Update the address only if the user actually owns the subscription
			if ( $subscription && self::can_user_edit_subscription_address( $subscription->get_id() ) ) {
				wcs_set_order_address( $subscription, $address, $address_type );
				$subscription->save();

				wp_safe_redirect( $subscription->get_view_order_url() );
				exit();
			}
		}
	}

	/**
	 * Prepopulate the address fields on a subscription item
	 *
	 * @param array $address A WooCommerce address array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5
	 */
	public static function maybe_populate_subscription_addresses( $address ) {
		$subscription_id = isset( $_GET['subscription'] ) ? absint( $_GET['subscription'] ) : 0;

		if ( $subscription_id && self::can_user_edit_subscription_address( $subscription_id ) ) {
			$subscription = wcs_get_subscription( $subscription_id );

			foreach ( array_keys( $address ) as $key ) {

				$function_name = 'get_' . $key;

				if ( is_callable( array( $subscription, $function_name ) ) ) {
					$address[ $key ]['value'] = $subscription->$function_name();
				}
			}
		}

		return $address;
	}

	/**
	 * Update the address fields on an order
	 *
	 * @param array $subscription A WooCommerce Subscription array
	 * @param array $address_fields Locale aware address fields of the form returned by WC_Countries->get_address_fields() for a given country
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function maybe_update_order_address( $subscription, $address_fields ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Order::set_address() or WC_Subscription::set_address()' );
	}

	/**
	 * Replace the change address breadcrumbs structure to include a link back to the subscription.
	 *
	 * @param  array $crumbs
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.2
	 */
	public static function change_addresses_breadcrumb( $crumbs ) {
		if ( isset( $_GET['subscription'] ) && is_wc_endpoint_url() && 'edit-address' === WC()->query->get_current_endpoint() ) {
			global $wp_query;
			$subscription = wcs_get_subscription( absint( $_GET['subscription'] ) );

			if ( ! $subscription ) {
				return $crumbs;
			}

			$crumbs[1] = array(
				get_the_title( wc_get_page_id( 'myaccount' ) ),
				get_permalink( wc_get_page_id( 'myaccount' ) ),
			);

			$crumbs[2] = array(
				// translators: %s: subscription ID.
				sprintf( _x( 'Subscription #%s', 'hash before order number', 'woocommerce-subscriptions' ), $subscription->get_order_number() ),
				esc_url( $subscription->get_view_order_url() ),
			);

			$crumbs[3] = array(
				// translators: %s: address type (eg. 'billing' or 'shipping').
				sprintf( _x( 'Change %s address', 'change billing or shipping address', 'woocommerce-subscriptions' ), $wp_query->query_vars['edit-address'] ),
				'',
			);
		}

		return $crumbs;
	}
}
