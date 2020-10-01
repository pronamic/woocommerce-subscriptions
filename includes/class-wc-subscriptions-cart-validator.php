<?php
/**
 * Subscriptions Cart Validator Class
 *
 * Validates the Cart contents
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Cart_Validator
 * @category   Class
 * @since      2.6.0
 */
class WC_Subscriptions_Cart_Validator {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 */
	public static function init() {

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'maybe_empty_cart' ), 10, 5 );
		add_filter( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'validate_cart_contents_for_mixed_checkout' ), 10 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'can_add_subscription_product_to_cart' ), 10, 6 );

	}

	/**
	 * When a subscription is added to the cart, remove other products/subscriptions to
	 * work with PayPal Standard, which only accept one subscription per checkout.
	 *
	 * If multiple purchase flag is set, allow them to be added at the same time.
	 *
	 * @since 2.6.0
	 */
	public static function maybe_empty_cart( $valid, $product_id, $quantity, $variation_id = '', $variations = array() ) {

		$is_subscription                 = WC_Subscriptions_Product::is_subscription( $product_id );
		$cart_contains_subscription      = WC_Subscriptions_Cart::cart_contains_subscription();
		$multiple_subscriptions_possible = WC_Subscriptions_Payment_Gateways::one_gateway_supports( 'multiple_subscriptions' );
		$manual_renewals_enabled         = ( 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no' ) );
		$canonical_product_id            = ! empty( $variation_id ) ? $variation_id : $product_id;

		if ( $is_subscription && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {

			// Generate a cart item key from variation and cart item data - which may be added by other plugins
			$cart_item_data = (array) apply_filters( 'woocommerce_add_cart_item_data', array(), $product_id, $variation_id, $quantity );
			$cart_item_id   = WC()->cart->generate_cart_id( $product_id, $variation_id, $variations, $cart_item_data );
			$product        = wc_get_product( $product_id );

			// If the product is sold individually or if the cart doesn't already contain this product, empty the cart.
			if ( ( $product && $product->is_sold_individually() ) || ! WC()->cart->find_product_in_cart( $cart_item_id ) ) {
				WC()->cart->empty_cart();
			}
		} elseif ( $is_subscription && wcs_cart_contains_renewal() && ! $multiple_subscriptions_possible && ! $manual_renewals_enabled ) {

			WC_Subscriptions_Cart::remove_subscriptions_from_cart();

			wc_add_notice( __( 'A subscription renewal has been removed from your cart. Multiple subscriptions can not be purchased at the same time.', 'woocommerce-subscriptions' ), 'notice' );

		} elseif ( $is_subscription && $cart_contains_subscription && ! $multiple_subscriptions_possible && ! $manual_renewals_enabled && ! WC_Subscriptions_Cart::cart_contains_product( $canonical_product_id ) ) {

			WC_Subscriptions_Cart::remove_subscriptions_from_cart();

			wc_add_notice( __( 'A subscription has been removed from your cart. Due to payment gateway restrictions, different subscription products can not be purchased at the same time.', 'woocommerce-subscriptions' ), 'notice' );

		} elseif ( $cart_contains_subscription && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {

			WC_Subscriptions_Cart::remove_subscriptions_from_cart();

			wc_add_notice( __( 'A subscription has been removed from your cart. Products and subscriptions can not be purchased at the same time.', 'woocommerce-subscriptions' ), 'notice' );

			// Redirect to cart page to remove subscription & notify shopper
			if ( WC_Subscriptions::is_woocommerce_pre( '3.0.8' ) ) {
				add_filter( 'add_to_cart_fragments', 'WC_Subscriptions::redirect_ajax_add_to_cart' );
			} else {
				add_filter( 'woocommerce_add_to_cart_fragments', 'WC_Subscriptions::redirect_ajax_add_to_cart' );
			}
		}

		return $valid;
	}

	/**
	 * This checks cart items for mixed checkout.
	 *
	 * @param $cart WC_Cart the one we got from session
	 * @return WC_Cart $cart
	 *
	 * @since 2.6.0
	 */
	public static function validate_cart_contents_for_mixed_checkout( $cart ) {

		// When mixed checkout is enabled
		if ( $cart->cart_contents && 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {
			return $cart;
		}

		if ( ! WC_Subscriptions_Cart::cart_contains_subscription() && ! wcs_cart_contains_renewal() ) {
			return $cart;
		}

		foreach ( $cart->cart_contents as $key => $item ) {

			// If two different subscription products are in the cart
			// or a non-subscription product is found in the cart containing subscriptions
			// ( maybe because of carts merge while logging in )
			if ( ! WC_Subscriptions_Product::is_subscription( $item['data'] ) ||
				WC_Subscriptions_Cart::cart_contains_other_subscription_products( wcs_get_canonical_product_id( $item['data'] ) ) ) {
				// remove the subscriptions from the cart
				WC_Subscriptions_Cart::remove_subscriptions_from_cart();

				// and add an appropriate notice
				wc_add_notice( __( 'Your cart has been emptied of subscription products. Only one subscription product can be purchased at a time.', 'woocommerce-subscriptions' ), 'notice' );

				// Redirect to cart page to remove subscription & notify shopper
				if ( WC_Subscriptions::is_woocommerce_pre( '3.0.8' ) ) {
					add_filter( 'add_to_cart_fragments', array( 'WC_Subscriptions', 'redirect_ajax_add_to_cart' ) );
				} else {
					add_filter( 'woocommerce_add_to_cart_fragments', array( 'WC_Subscriptions', 'redirect_ajax_add_to_cart' ) );
				}

				break;
			}
		}

		return $cart;
	}

	/**
	 * Don't allow new subscription products to be added to the cart if it contains a subscription renewal already.
	 *
	 * @since 2.6.0
	 */
	public static function can_add_subscription_product_to_cart( $can_add, $product_id, $quantity, $variation_id = '', $variations = array(), $item_data = array() ) {

		if ( $can_add && ! isset( $item_data['subscription_renewal'] ) && wcs_cart_contains_renewal() && WC_Subscriptions_Product::is_subscription( $product_id ) ) {

			wc_add_notice( __( 'That subscription product can not be added to your cart as it already contains a subscription renewal.', 'woocommerce-subscriptions' ), 'error' );
			$can_add = false;
		}

		return $can_add;
	}

}
