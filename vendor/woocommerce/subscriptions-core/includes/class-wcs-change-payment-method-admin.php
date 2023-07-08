<?php
/**
 * Class to handle everything to do with changing a payment method for a subscription on the
 * edit subscription admin page.
 *
 * @class    WCS_Change_Payment_Method_Admin
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 * @package  WooCommerce Subscriptions/Includes
 * @category Class
 * @author   Prospress
 */

class WCS_Change_Payment_Method_Admin {

	/**
	 * Display the edit payment gateway option under
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function display_fields( $subscription ) {

		$payment_method        = $subscription->get_payment_method();
		$valid_payment_methods = self::get_valid_payment_methods( $subscription );

		if ( ! $subscription->is_manual() && ! isset( $valid_payment_methods[ $payment_method ] ) ) {
			$payment_gateways_handler     = WC_Subscriptions_Core_Plugin::instance()->get_gateways_handler_class();
			$subscription_payment_gateway = $payment_gateways_handler::get_payment_gateway( $payment_method );

			if ( false != $subscription_payment_gateway ) {
				$valid_payment_methods[ $payment_method ] = $subscription_payment_gateway->title;
			}
		}

		echo '<p class="form-field form-field-wide">';

		if ( count( $valid_payment_methods ) > 1 ) {

			$found_method = false;
			echo '<label>' . esc_html__( 'Payment Method', 'woocommerce-subscriptions' ) . ':</label>';
			echo '<select class="wcs_payment_method_selector" name="_payment_method" id="_payment_method" class="first">';

			foreach ( $valid_payment_methods as $gateway_id => $gateway_title ) {

				echo '<option value="' . esc_attr( $gateway_id ) . '" ' . selected( $payment_method, $gateway_id, false ) . '>' . esc_html( $gateway_title ) . '</option>';
				if ( $payment_method == $gateway_id ) {
					$found_method = true;
				}
			}
			echo '</select>';

		} elseif ( count( $valid_payment_methods ) == 1 ) {
			echo '<strong>' . esc_html__( 'Payment Method', 'woocommerce-subscriptions' ) . ':</strong><br/>' . esc_html( current( $valid_payment_methods ) );
			// translators: %s: gateway ID.
			echo wcs_help_tip( sprintf( _x( 'Gateway ID: [%s]', 'The gateway ID displayed on the Edit Subscriptions screen when editing payment method.', 'woocommerce-subscriptions' ), key( $valid_payment_methods ) ) );
			echo '<input type="hidden" value="' . esc_attr( key( $valid_payment_methods ) ) . '" id="_payment_method" name="_payment_method">';
		}

		echo '</p>';

		$payment_method_table = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );

		if ( is_array( $payment_method_table ) ) {

			foreach ( $payment_method_table as $payment_method_id => $payment_method_meta ) {

				echo '<div class="wcs_payment_method_meta_fields" id="wcs_' . esc_attr( $payment_method_id ) . '_fields" ' . ( ( $payment_method_id != $payment_method || $subscription->is_manual() ) ? 'style="display:none;"' : '' ) . ' >';

				foreach ( $payment_method_meta as $meta_table => $meta ) {

					foreach ( $meta as $meta_key => $meta_data ) {

						$field_id       = sprintf( '_payment_method_meta[%s][%s][%s]', $payment_method_id, $meta_table, $meta_key );
						$field_label    = ( ! empty( $meta_data['label'] ) ) ? $meta_data['label'] : $meta_key;
						$field_value    = ( ! empty( $meta_data['value'] ) ) ? $meta_data['value'] : null;
						$field_disabled = ( isset( $meta_data['disabled'] ) && true == $meta_data['disabled'] ) ? ' readonly' : '';

						echo '<p class="form-field form-field-wide">';
						echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html( $field_label ) . '</label>';

						$payment_meta_input_action_name = sprintf( 'woocommerce_subscription_payment_meta_input_%s_%s_%s', $payment_method_id, $meta_table, $meta_key );

						// Allow third parties to display their own custom meta input fields.
						if ( has_action( $payment_meta_input_action_name ) ) {
							do_action( $payment_meta_input_action_name, $subscription, $field_id, $field_value, $meta_data );
						} else {
							echo '<input type="text" class="short" name="' . esc_attr( $field_id ) . '" id="' . esc_attr( $field_id ) . '" value="' . esc_attr( $field_value ) . '" placeholder="" ' . esc_attr( $field_disabled ) . '>';
						}

						echo '</p>';
					}
				}

				echo '</div>';

			}
		}

		wp_nonce_field( 'wcs_change_payment_method_admin', '_wcsnonce' );

	}

	/**
	 * Get the new payment data from POST and check the new payment method supports
	 * the new admin change hook.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @param $subscription WC_Subscription
	 */
	public static function save_meta( $subscription ) {

		if ( empty( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( $_POST['_wcsnonce'], 'wcs_change_payment_method_admin' ) ) {
			return;
		}

		$payment_gateways    = WC()->payment_gateways->payment_gateways();
		$payment_method      = isset( $_POST['_payment_method'] ) ? wc_clean( $_POST['_payment_method'] ) : '';
		$payment_method_meta = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );
		$payment_method_meta = ( ! empty( $payment_method_meta[ $payment_method ] ) ) ? $payment_method_meta[ $payment_method ] : array();

		$valid_payment_methods = self::get_valid_payment_methods( $subscription );

		if ( ! isset( $valid_payment_methods[ $payment_method ] ) && ! ( isset( $payment_gateways[ $payment_method ] ) && $subscription->get_payment_method() == $payment_gateways[ $payment_method ]->id ) ) {
			throw new Exception( __( 'Please choose a valid payment gateway to change to.', 'woocommerce-subscriptions' ) );
		}

		if ( ! empty( $payment_method_meta ) ) {

			foreach ( $payment_method_meta as $meta_table => $meta ) {

				if ( ! is_array( $meta ) ) {
					continue;
				}

				foreach ( $meta as $meta_key => $meta_data ) {
					$payment_method_meta[ $meta_table ][ $meta_key ]['value'] = isset( $_POST['_payment_method_meta'][ $payment_method ][ $meta_table ][ $meta_key ] ) ? $_POST['_payment_method_meta'][ $payment_method ][ $meta_table ][ $meta_key ] : '';
				}
			}
		}

		$payment_gateway = ( 'manual' != $payment_method ) ? $payment_gateways[ $payment_method ] : '';

		if ( ! $subscription->is_manual() && ( '' == $payment_gateway || $subscription->get_payment_method() != $payment_gateway->id ) ) {
			// Before updating to a new payment gateway make sure the subscription status is updated with the current gateway
			$gateway_status           = apply_filters( 'wcs_gateway_status_payment_changed', 'cancelled', $subscription, $payment_gateway );
			$payment_gateways_handler = WC_Subscriptions_Core_Plugin::instance()->get_gateways_handler_class();

			$payment_gateways_handler::trigger_gateway_status_updated_hook( $subscription, $gateway_status );
		}

		// Update the payment method for manual only if it has changed.
		if ( ! $subscription->is_manual() || 'manual' !== $payment_method ) {
			$subscription->set_payment_method( $payment_gateway, $payment_method_meta );
			$subscription->save();
		}
	}

	/**
	 * Get a list of possible gateways that a subscription could be changed to by admins.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @param $subscription int | WC_Subscription
	 * @return
	 */
	public static function get_valid_payment_methods( $subscription ) {

		if ( ! $subscription instanceof WC_Subscription ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		$valid_gateways = array( 'manual' => __( 'Manual Renewal', 'woocommerce-subscriptions' ) );

		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

		foreach ( $available_gateways as $gateway_id => $gateway ) {

			if ( $gateway->supports( 'subscription_payment_method_change_admin' ) && ! wcs_is_manual_renewal_required() || ( ! $subscription->is_manual() && $gateway_id == $subscription->get_payment_method() ) ) {
				$valid_gateways[ $gateway_id ] = $gateway->get_title();
			}
		}

		return $valid_gateways;

	}

}
