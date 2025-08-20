<?php
/**
 * Recipient details endpoint.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles the new recipient account endpoint.
 */
class WCSG_Recipient_Details {

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'template_redirect', __CLASS__ . '::update_recipient_details', 1 );
		add_action( 'template_redirect', __CLASS__ . '::my_account_template_redirect' );

		if ( wcsg_is_woocommerce_pre( '2.6' ) ) {
			add_filter( 'wc_get_template', array( __CLASS__, 'add_new_customer_template' ), 10, 5 );
		} else {
			add_filter( 'wc_get_template', array( __CLASS__, 'get_new_recipient_account_container' ), 10, 4 );
			add_action( 'woocommerce_account_new-recipient-account_endpoint', array( __CLASS__, 'get_new_customer_template' ) );
		}
	}

	/**
	 * Determines if the current page is the recipient details page.
	 *
	 * @return boolean Whether the current page is the recipient details page or not.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	private static function is_recipient_details_page() {
		global $wp;

		return isset( $wp->query_vars['new-recipient-account'] );
	}

	/**
	 * Override the core My Account base template and display a full-width template if we're displaying the Recipient Details page.
	 *
	 * @param string $located       Path to template.
	 * @param string $template_name The template's name.
	 * @param array  $args          An array of arguments used in the template.
	 * @param string $template_path Path for including template.
	 * @return string Path to template.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function get_new_recipient_account_container( $located, $template_name, $args, $template_path ) {
		if ( 'myaccount/my-account.php' === $template_name && self::is_recipient_details_page() ) {
			$located = wc_locate_template( 'recipient-details-my-account.php', $template_path, plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
		}

		return $located;
	}

	/**
	 * Get the new-recipient-account template
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function get_new_customer_template() {
		wc_get_template( 'new-recipient-account.php', array(), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
	}

	/**
	 * Locates the new recipient details page template if the user is flagged for requiring further details.
	 *
	 * @param string $located       Path to template.
	 * @param string $template_name The template's name.
	 * @param array  $args          An array of arguments used in the template.
	 * @param string $template_path Path for including template.
	 * @param string $default_path  Default path.
	 * @return string Path to template.
	 */
	public static function add_new_customer_template( $located, $template_name, $args, $template_path, $default_path ) {
		if ( 'myaccount/my-account.php' === $template_name && self::is_recipient_details_page() && 'true' === get_user_meta( get_current_user_id(), 'wcsg_update_account', true ) ) {
			$located = wc_locate_template( 'new-recipient-account.php', $template_path, plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
		}
		return $located;
	}

	/**
	 * Redirects the user to the relevant page if they are trying to access my account or recipient account details page.
	 */
	public static function my_account_template_redirect() {
		global $wp;
		$current_user_id = get_current_user_id();
		if ( is_account_page() && ! isset( $wp->query_vars['customer-logout'] ) ) {
			if ( 'true' === get_user_meta( $current_user_id, 'wcsg_update_account', true ) && ! isset( $wp->query_vars['new-recipient-account'] ) ) {
				wp_safe_redirect( wc_get_endpoint_url( 'new-recipient-account', '', wc_get_page_permalink( 'myaccount' ) ) );
				exit();
			} elseif ( 'true' !== get_user_meta( $current_user_id, 'wcsg_update_account', true ) && isset( $wp->query_vars['new-recipient-account'] ) ) {
				wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
				exit();
			}
		}
	}

	/**
	 * Validates the new recipient account details page updating user data and removing the 'required account update' user flag
	 * if there are no errors in validation.
	 */
	public static function update_recipient_details() {
		if ( isset( $_POST['wcsg_new_recipient_customer'] ) && ! empty( $_POST['_wcsgnonce'] ) && wp_verify_nonce( $_POST['_wcsgnonce'], 'wcsg_new_recipient_data' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			$country          = ( ! empty( $_POST['shipping_country'] ) ) ? wc_clean( $_POST['shipping_country'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$form_fields      = self::get_new_recipient_account_form_fields( $country );
			$password_fields  = array();
			$password_missing = false;

			foreach ( $form_fields as $key => $field ) {

				if ( isset( $field['type'] ) && 'password' === $field['type'] ) {
					$password_fields[ $key ] = $field;
				}

				// If the field is a required field and missing from posted data.
				if ( isset( $field['required'] ) && true == $field['required'] && empty( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison

					if ( isset( $password_fields[ $key ] ) ) {
						if ( ! $password_missing ) {
							wc_add_notice( __( 'Please enter both password fields.', 'woocommerce-subscriptions' ), 'error' );
							$password_missing = true;
						}
					} else {
						wc_add_notice( $field['label'] . ' ' . __( 'is a required field.', 'woocommerce-subscriptions' ), 'error' );
					}
				}
			}

			// Now match the passwords but only if we haven't displayed the password missing error.
			if ( ! $password_missing && ! empty( $password_fields ) ) {
				$passwords = array_intersect_key( $_POST, $password_fields );

				if ( count( array_unique( $passwords ) ) !== 1 ) {
					wc_add_notice( __( 'The passwords you have entered do not match.', 'woocommerce-subscriptions' ), 'error' );
				}
			}

			// Validate the postcode field.
			if ( ! empty( $_POST['shipping_postcode'] ) && ! WC_Validation::is_postcode( $_POST['shipping_postcode'], $_POST['shipping_country'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				wc_add_notice( __( 'Please enter a valid postcode/ZIP.', 'woocommerce-subscriptions' ), 'error' );
			}

			if ( 0 === wc_notice_count( 'error' ) ) {
				$user               = wp_get_current_user();
				$address            = array();
				$non_user_meta_keys = array( 'set_billing' );

				foreach ( $form_fields as $key => $field ) {

					if ( ! in_array( $key, $non_user_meta_keys, true ) ) {

						$value = isset( $_POST[ $key ] ) ? wc_clean( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

						if ( false !== strpos( $key, 'shipping_' ) ) {

							$address_field = str_replace( 'shipping_', '', $key ); // Get the key minus the leading 'shipping_'.

							// If the field is a shipping first or last name and there isn't a posted value, fallback to our custom name field (if it exists).
							if ( in_array( $key, array( 'shipping_first_name', 'shipping_last_name' ), true ) && empty( $_POST[ $key ] ) && ! empty( $_POST[ $address_field ] ) ) {
								$value = wc_clean( $_POST[ $address_field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
							}

							if ( isset( $_POST['set_billing'] ) ) {
								update_user_meta( $user->ID, str_replace( 'shipping', 'billing', $key ), $value );
							}

							$address[ $address_field ] = $value;
						}

						update_user_meta( $user->ID, $key, $value );
					}
				}

				// Find out the user's full name from our custom first/last name fields and the shipping fields (if available).
				foreach ( array( 'first_name', 'last_name' ) as $name_property ) {
					if ( empty( $_POST[ $name_property ] ) && ! empty( $_POST[ 'shipping_' . $name_property ] ) ) {
						$user->{$name_property} = wc_clean( wp_unslash( $_POST[ 'shipping_' . $name_property ] ) );
					}
				}

				if ( $user->first_name ) {
					$user->nickname     = $user->first_name;
					$user->display_name = $user->first_name;
				}

				wp_update_user( $user );

				if ( ! empty( $address ) ) {
					$recipient_subscriptions = WCSG_Recipient_Management::get_recipient_subscriptions( $user->ID );
					foreach ( $recipient_subscriptions as $subscription_id ) {
						$subscription = wcs_get_subscription( $subscription_id );

						foreach( $address as $key => $value ) {
							if ( is_callable( array( $subscription, 'set_shipping_' . $key ) ) ) {
								$subscription->{'set_shipping_' . $key}( $value );
							}
						}

						$subscription->save();
					}
				}

				delete_user_meta( $user->ID, 'wcsg_update_account', 'true' );
				delete_user_meta( $user->ID, 'wcsg_recipient_just_reset_password', 'true' );

				do_action( 'wcsg_recipient_details_updated', $user->ID );
				wc_add_notice( __( 'Your account has been updated.', 'woocommerce-subscriptions' ), 'notice' );

				wp_safe_redirect( apply_filters( 'wcsg_recipient_details_update_redirect_url', wc_get_page_permalink( 'myaccount' ), $user->ID ) );
				exit;
			}
		} elseif ( isset( $_POST['wcsg_new_recipient_customer'] ) ) {
			wc_add_notice( __( 'There was an error with your request to update your account. Please try again.', 'woocommerce-subscriptions' ), 'error' );
		}
	}

	/**
	 * Creates an array of form fields for the new recipient user details form
	 *
	 * @param string $country For which country we need to fetch fields.
	 * @param int    $user_id For which user we need to fetch the fields. Default get_current_user_id().
	 *
	 * @return array Form elements for recipient details page
	 */
	public static function get_new_recipient_account_form_fields( $country, $user_id = null ) {
		$user_id = ( is_numeric( $user_id ) ) ? absint( $user_id ) : get_current_user_id();

		$shipping_fields = array();
		if ( self::need_shipping_address_details_for_recipient( $user_id ) ) {
			$shipping_fields = WC()->countries->get_address_fields( $country, 'shipping_' );

			// We have our own name fields, so hide and make the shipping name fields not required.
			foreach ( array( 'shipping_first_name', 'shipping_last_name' ) as $field_key ) {
				$shipping_fields[ $field_key ]['type']     = 'hidden';
				$shipping_fields[ $field_key ]['required'] = false;
				$shipping_fields[ $field_key ]['label']    = '';
			}

			// Add the option for users to also set their billing address.
			$shipping_fields['set_billing'] = array(
				'type'     => 'checkbox',
				'label'    => esc_html__( 'Set my billing address to the same as above.', 'woocommerce-subscriptions' ),
				'class'    => array( 'form-row' ),
				'required' => false,
				'default'  => 1,
			);
		}

		$personal_fields        = array();
		$user_requires_password = WCSG_Recipient_Management::user_requires_new_password( $user_id );

		$personal_fields['first_name'] = array(
			'label'        => esc_html__( 'First Name', 'woocommerce-subscriptions' ),
			'required'     => true,
			'class'        => array( 'form-row-first' ),
			'autocomplete' => 'given-name',
		);

		$personal_fields['last_name'] = array(
			'label'        => esc_html__( 'Last Name', 'woocommerce-subscriptions' ),
			'required'     => true,
			'class'        => array( 'form-row-last' ),
			'clear'        => true,
			'autocomplete' => 'family-name',
		);

		return apply_filters( 'wcsg_new_recipient_account_details_fields', array_merge( $personal_fields, $shipping_fields ) );
	}


	/**
	 * Determines whether shipping address information is required for the given recipient.
	 *
	 * @param int $user_id User ID.
	 * @return bool TRUE if shipping address information is required for the given user.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.1.
	 */
	private static function need_shipping_address_details_for_recipient( $user_id = null ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! wc_shipping_enabled() ) {
			return false;
		}

		$needs_shipping = WCS_Gifting::require_shipping_address_for_virtual_products();

		if ( ! $needs_shipping ) {
			foreach ( WCSG_Recipient_Management::get_recipient_subscriptions( $user_id ) as $subscription_id ) {
				$subscription = wcs_get_subscription( $subscription_id );

				if ( $subscription && $subscription->needs_shipping_address() ) {
					$needs_shipping = true;
					break;
				}
			}
		}

		return apply_filters( 'wcsg_need_shipping_address_details_for_recipient', $needs_shipping, $user_id );
	}

}
