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
				'title' => __( 'Purchase text', 'woocommerce-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Customize the text that appears on your product and checkout pages.', 'woocommerce-subscriptions' ),
				'id'    => WC_Subscriptions_Admin::$option_prefix . '_button_text',
			),
			array(
				'name'        => __( 'Add to cart button', 'woocommerce-subscriptions' ),
				'desc'        => __( 'Customize the add to cart button text that appears on an individual product page when a subscription product is selected.', 'woocommerce-subscriptions' ),
				'tip'         => '',
				'id'          => WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text',
				'css'         => 'min-width:150px;',
				'default'     => __( 'Add to cart', 'woocommerce-subscriptions' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'placeholder' => __( 'Add to cart', 'woocommerce-subscriptions' ),
			),
			array(
				'name'        => __( 'Place order button', 'woocommerce-subscriptions' ),
				'desc'        => __( 'Customize the place order button text that appears on checkout page when the order contains a subscription.', 'woocommerce-subscriptions' ),
				'tip'         => '',
				'id'          => WC_Subscriptions_Admin::$option_prefix . '_order_button_text',
				'css'         => 'min-width:150px;',
				'default'     => __( 'Place order', 'woocommerce-subscriptions' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'placeholder' => __( 'Place order', 'woocommerce-subscriptions' ),
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
