<?php
/**
 * A class for managing the place order and add to cart button text for subscription products.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Call_To_Action_Button_Text_Manager {

	/**
	 * Initialise the class's callbacks.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ), 5 );
		add_filter( 'wc_subscription_product_add_to_cart_text', array( __CLASS__, 'filter_add_to_cart_text' ) );
		add_filter( 'wcs_place_subscription_order_text', array( __CLASS__, 'filter_place_subscription_order_text' ) );
	}

	/**
	 * Adds the subscription add to cart and place order button text settings.
	 *
	 * @since 4.0.0
	 *
	 * @param  array $settings The WC Subscriptions settings.
	 * @return array $settings
	 */
	public static function add_settings( $settings ) {
		$button_text_settings = array(
			array(
				'name' => __( 'Button Text', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'desc' => '',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_button_text',
			),
			array(
				'name'        => __( 'Add to Cart Button Text', 'woocommerce-subscriptions' ),
				'desc'        => __( 'A product displays a button with the text "Add to cart". By default, a subscription changes this to "Sign up now". You can customise the button text for subscriptions here.', 'woocommerce-subscriptions' ),
				'tip'         => '',
				'id'          => WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text',
				'css'         => 'min-width:150px;',
				'default'     => __( 'Sign up now', 'woocommerce-subscriptions' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'placeholder' => __( 'Sign up now', 'woocommerce-subscriptions' ),
			),
			array(
				'name'        => __( 'Place Order Button Text', 'woocommerce-subscriptions' ),
				'desc'        => __( 'Use this field to customise the text displayed on the checkout button when an order contains a subscription. Normally the checkout submission button displays "Place order". When the cart contains a subscription, this is changed to "Sign up now".', 'woocommerce-subscriptions' ),
				'tip'         => '',
				'id'          => WC_Subscriptions_Admin::$option_prefix . '_order_button_text',
				'css'         => 'min-width:150px;',
				'default'     => __( 'Sign up now', 'woocommerce-subscriptions' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'placeholder' => __( 'Sign up now', 'woocommerce-subscriptions' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_button_text',
			),
		);

		return array_merge( $button_text_settings, $settings );
	}

	/**
	 * Filters subscription products add to cart text to honour the setting.
	 *
	 * @since 4.0.0
	 *
	 * @param string $add_to_cart_text The product's add to cart text.
	 *
	 * @return string
	 */
	public static function filter_add_to_cart_text( $add_to_cart_text ) {
		return get_option( WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text', $add_to_cart_text );
	}

	/**
	 * Filters the place order text while there's a subscription in the cart.
	 *
	 * @since 4.0.0
	 *
	 * @param string $button_text The default place order button text.
	 * @return string The button text.
	 */
	public static function filter_place_subscription_order_text( $button_text ) {
		return get_option( WC_Subscriptions_Admin::$option_prefix . '_order_button_text', $button_text );
	}
}
