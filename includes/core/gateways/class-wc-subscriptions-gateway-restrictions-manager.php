<?php
/**
 * A class to manage Subscriptions gateway restrictions.
 *
 * @since 4.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Subscriptions_Gateway_Restrictions_Manager class
 */
class WC_Subscriptions_Gateway_Restrictions_Manager {

	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Registers and enqueues payment gateway specific scripts.
	 */
	public static function enqueue_scripts() {
		$screen    = get_current_screen();
		$screen_id = isset( $screen->id ) ? $screen->id : '';

		if ( 'product' === $screen_id ) {
			wp_enqueue_script(
				'woocommerce_subscriptions_payment_restrictions',
				WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( 'assets/js/admin/payment-method-restrictions.js' ),
				array( 'jquery', 'woocommerce_admin' ),
				filemtime( WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'assets/js/admin/payment-method-restrictions.js' ) ),
				true // Load in footer.
			);

			$decimals          = wc_get_price_decimals();
			$decimal_separator = wc_get_price_decimal_separator();
			$zero_price        = sprintf( get_woocommerce_price_format(), get_woocommerce_currency_symbol(), number_format( 0, $decimals, $decimal_separator, '' ) );

			$script_data = array(
				// Translators: placeholder is a 0 price formatted with the the store's currency and decimal settings.
				'i18n_zero_subscription_error' => sprintf( __( 'Please enter a price greater than %s.', 'woocommerce-subscriptions' ), $zero_price ),
				'number_of_decimal_places'     => $decimals,
				'decimal_point_separator'      => $decimal_separator,
			);

			/**
			 * Allow WC Payments to return the minimum amount that can be processed.
			 *
			 * @since 1.5.0
			 *
			 * @param false|float $minimum_processable_amount The minimum amount that can be processed in the given currency.
			 * @param string      $currency The currency.
			 */
			$minimum_processable_amount = apply_filters( 'woocommerce_subscriptions_minimum_processable_recurring_amount', false, get_woocommerce_currency() );

			if ( is_numeric( $minimum_processable_amount ) ) {
				$i18n_minimum_price                         = sprintf( get_woocommerce_price_format(), get_woocommerce_currency_symbol(), number_format( $minimum_processable_amount, $decimals, $decimal_separator, '' ) );
				$script_data['minimum_subscription_amount'] = $minimum_processable_amount;

				// Translators: Placeholder is a localized price string (eg. $1.00).
				$script_data['i18n_below_minimum_subscription_error'] = sprintf( __( 'Warning! Your store cannot create subscriptions less than %s', 'woocommerce-subscriptions' ), $i18n_minimum_price );
			}

			wp_localize_script( 'woocommerce_subscriptions_payment_restrictions', 'wcs_gateway_restrictions', $script_data );
		}
	}
}
