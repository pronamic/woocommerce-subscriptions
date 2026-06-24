<?php
/**
 * WCS_ATT_Order class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order hooks for saving/restoring the subscription state of a product to/from order item data.
 *
 * @class    WCS_ATT_Order
 * @version  6.0.3
 */
class WCS_ATT_Order {

	/**
	 * Initialization.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Hook-in.
	 */
	private static function add_hooks() {

		// Restore subscription data when creating a cart item using an order item as reference.
		add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'restore_cart_item_from_order_item' ), 10, 3 );

		// Restore the subscription state of a product instantiated using an order item as reference.
		add_filter( 'woocommerce_order_item_product', array( __CLASS__, 'restore_product_from_order_item' ), 10, 2 );

		// Save subscription scheme in subscription item meta when checking out so it can be re-applied later.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_subscription_scheme_meta' ), 10, 3 );

		// Ensure _has_trial is set on APFS subscription line items that have a trial period.
		// WC_Subscriptions_Checkout::maybe_add_free_trial_item_meta() (priority 10) calls
		// WC_Subscriptions_Product::get_trial_length( $item->get_product() ). get_product() creates a fresh
		// product object each call with no APFS runtime meta, so get_trial_length() returns 0 and _has_trial
		// is never set. Without _has_trial, WC_Subscription::get_sign_up_fee() uses
		// (order_line_total - subscription_line_total) instead of the full initial amount, producing the
		// wrong sign-up fee value. Running at priority 11 allows us to set _has_trial directly from cart
		// item data when WCS missed it.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'maybe_add_apfs_free_trial_item_meta' ), 11, 4 );

		// Hide subscription scheme metadata in order line items.
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_order_item_meta' ) );

		// Alter scheme meta key for switching.
		add_filter( 'woocommerce_attribute_label', array( __CLASS__, 'modify_scheme_attribute_label' ) );

		// Update the order item price when a matching scheme is found.
		add_action( 'woocommerce_new_order_item', array( __CLASS__, 'apply_matching_scheme_discount_to_order_item' ), 10, 3 );
	}

	/*
	|--------------------------------------------------------------------------
	| API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Returns the key of the subscription scheme applied on the product when it was purchased.
	 *
	 * @param  array $order_item
	 * @param  array $args
	 * @return string|false|null
	 */
	public static function get_subscription_scheme( $order_item, $args = array() ) {

		$scheme_key = null;

		if ( $order_item->meta_exists( '_wcsatt_scheme' ) ) {

			$scheme_key = $order_item->get_meta( '_wcsatt_scheme', true );
			$scheme_key = WCS_ATT_Product_Schemes::parse_subscription_scheme_key( $scheme_key );

			// Backwards compatibility with v1.
		} elseif ( $order_item->meta_exists( '_wcsatt_scheme_id' ) ) {

			$scheme_key = $order_item->get_meta( '_wcsatt_scheme_id', true );
			$scheme_key = WCS_ATT_Product_Schemes::parse_subscription_scheme_key( $scheme_key );

		} else {

			$default_args = array(
				'order'              => false,
				'product'            => false,
				'cart_item'          => false,
				'match_subscription' => false,
				'match_args'         => array(
					'next_payment'      => false,
					'upcoming_renewals' => false,
					'payment_date'      => false,
				),
			);

			$args = wp_parse_args( $args, $default_args );

			if ( isset( $args['product'] ) && is_object( $args['product'] ) ) {
				$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $args['product'] );
				if ( empty( $subscription_schemes ) ) {
					return $scheme_key;
				}
			}

			/*
			 * Restore scheme when working with subscriptions.
			 */

			// Paying for an initial subscription order created via a manually created subscription?
			if ( is_object( $args['order'] ) && isset( $args['cart_item']['subscription_initial_payment'] ) ) {
				$subscriptions              = wcs_get_subscriptions_for_order( $args['order']->get_id(), array( 'order_type' => 'parent' ) );
				$args['match_subscription'] = current( $subscriptions );
				// Setting up a subscription renewal in the cart?
			} elseif ( is_object( $args['order'] ) && 'shop_subscription' === $args['order']->get_type() ) {
				$args['match_subscription'] = $args['order'];
				// Creating a product instance from an order item in a subscription?
			} elseif ( ( $subscription_id = $order_item->get_order_id() ) && wcs_is_subscription( $subscription_id ) ) {
				$args['match_subscription'] = wcs_get_subscription( $subscription_id );
			}

			/**
			 * 'wcsatt_restore_subscription_scheme_from_subscription' filter.
			 *
			 * Controls whether SATT will attempt to restore a missing scheme key by matching a scheme against the subscription object.
			 *
			 * @since  APFS 2.1.2
			 *
			 * @param  array          $args
			 * @param  WC_Order_Item  $order_item
			 */
			$args = apply_filters( 'wcsatt_restore_subscription_scheme_from_subscription_args', $args, $order_item );

			if ( ! $args['match_subscription'] || ! wcs_is_subscription( $args['match_subscription'] ) ) {
				return $scheme_key;
			}

			$product = isset( $args['product'] ) && is_object( $args['product'] ) ? $args['product'] : $order_item->get_product();

			if ( ! is_object( $product ) ) {
				return $scheme_key;
			}

			if ( ! isset( $subscription_schemes ) ) {
				$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
				if ( empty( $subscription_schemes ) ) {
					return $scheme_key;
				}
			}

			foreach ( $subscription_schemes as $subscription_scheme ) {
				if ( $subscription_scheme->matches_subscription( $args['match_subscription'], $args['match_args'] ) ) {
					$scheme_key = $subscription_scheme->get_key();
					break;
				}
			}
		}

		return $scheme_key;
	}

	/**
	 * Returns a summary of the products included in an order/subscription.
	 *
	 * @since APFS 3.4.0
	 *
	 * @param  WC_Order|int $order
	 * @param  array        $args
	 * @return string|array
	 */
	public static function get_contents_summary( $order, $args = array() ) {

		$default_args = array(
			'return' => 'html',
		);

		$args         = wp_parse_args( $args, $default_args );
		$output       = 'html' === $args['return'] ? '' : array();
		$return_array = is_array( $output );
		$order        = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return $output;
		}

		$items = $order->get_items();
		if ( empty( $items ) ) {
			return $output;
		}

		if ( ! $return_array ) {
			$output = '<ul>';
		}

		foreach ( $items as $order_item ) {

			if ( ! apply_filters( 'woocommerce_order_item_visible', true, $order_item ) ) {
				continue;
			}

			$product    = $order_item->get_product();
			$is_visible = $product && $product->is_visible();
			$item_name  = apply_filters( 'woocommerce_order_item_name', $order_item->get_name(), $order_item, $is_visible );

			/* translators: %1$s product name, %2$s quantity */
			$row = sprintf( _x( '%1$s&nbsp;&times;&nbsp;%2$s', 'Order overview product row', 'woocommerce-subscriptions' ), $item_name, $order_item->get_quantity() );

			if ( $return_array ) {
				$output[] = $row;
			} else {
				$output .= '<li class="' . esc_attr( apply_filters( 'woocommerce_order_item_class', 'wcsatt_order_summary_list_item', $order_item, $order ) ) . '">' . $row . '</li>';
			}
		}

		if ( ! $return_array ) {
			$output .= '</ul>';
		}

		return $output;
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Attempts to restore subscription data when creating a cart item using an order item as reference.
	 *
	 * @param  array    $cart_item
	 * @param  array    $order_item
	 * @param  WC_Order $order
	 * @return array
	 */
	public static function restore_cart_item_from_order_item( $cart_item, $order_item, $order ) {

		$product              = $order_item->get_product();
		$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );

		if ( empty( $subscription_schemes ) && ! isset( $cart_item['subscription_resubscribe'] ) && ! isset( $cart_item['subscription_initial_payment'] ) && ! isset( $_GET['order_again'] ) ) {
			return $cart_item;
		}

		$scheme_key = self::get_subscription_scheme(
			$order_item,
			array(
				'order'     => $order,
				'product'   => $product,
				'cart_item' => $cart_item,
			)
		);

		if ( is_null( $scheme_key ) && ! empty( $subscription_schemes ) ) {
			$scheme_key = false;
		}

		$cart_item['wcsatt_data'] = array(
			'active_subscription_scheme' => $scheme_key,
		);

		return $cart_item;
	}

	/**
	 * Attempts to restore the subscription state of a product instantiated using an order item as reference.
	 *
	 * @param  WC_Product $product
	 * @param  array      $order_item
	 * @return WC_Product
	 */
	public static function restore_product_from_order_item( $product, $order_item ) {

		$scheme_key = null;
		$scheme_set = null;

		if ( $product && null !== ( $scheme_key = self::get_subscription_scheme( $order_item, array( 'product' => $product ) ) ) ) {
			$scheme_set = WCS_ATT_Product_Schemes::set_subscription_scheme( $product, $scheme_key );
		}

		return $product;
	}

	/**
	 * Stores the scheme key on the order item when checking out.
	 * Used for reconstructing the scheme when reordering, resubscribing, etc - @see 'WCS_ATT_Cart::add_cart_item_data'.
	 *
	 * @param  WC_Order_Item $order_item
	 * @param  string        $cart_item_key
	 * @param  array         $cart_item
	 * @return void
	 */
	public static function save_subscription_scheme_meta( $order_item, $cart_item_key, $cart_item ) {

		$scheme_key = WCS_ATT_Cart::get_subscription_scheme( $cart_item );

		if ( null !== $scheme_key ) {
			$order_item->add_meta_data( '_wcsatt_scheme', WCS_ATT_Product_Schemes::stringify_subscription_scheme_key( $scheme_key ), true );
		}

		// Log mismatch - @see 'WCS_ATT_Cart::check_applied_subscription_schemes' and 'WCS_ATT_Cart::apply_subscription_scheme'.
		$applied_scheme_key = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'] );

		if ( $scheme_key !== $applied_scheme_key ) {
			$log_message = sprintf( 'Incorrect subscription scheme applied to cart item %s (%s). Scheme to apply: "%s". Applied scheme: "%s".', $cart_item_key, $cart_item['data']->get_name(), var_export( $scheme_key, true ), var_export( $applied_scheme_key, true ) );
			WCS_ATT()->log( $log_message, 'notice' );
		}
	}

	/**
	 * Sets _has_trial on APFS subscription line items that have a trial period.
	 *
	 * WC_Subscriptions_Checkout::maybe_add_free_trial_item_meta() (priority 10) misses APFS trials
	 * because $item->get_product() creates a fresh product object without APFS runtime meta.
	 * We run at priority 11 and read trial_length from $cart_item['data'] which carries the
	 * runtime meta set by WCS_ATT_Product_Schemes::set_subscription_scheme().
	 *
	 * _has_trial is required by WC_Subscription::get_items_sign_up_fee() to correctly return the
	 * full initial amount paid rather than (order_line_total - subscription_line_total).
	 *
	 * @since  9.0.0
	 *
	 * @param WC_Order_Item_Product    $item          The line item being created.
	 * @param string                   $_cart_item_key The cart item key.
	 * @param array                    $cart_item     The cart item data.
	 * @param WC_Order|WC_Subscription $order         The order or subscription being created.
	 */
	public static function maybe_add_apfs_free_trial_item_meta( $item, $_cart_item_key, $cart_item, $order ) {

		if ( ! wcs_is_subscription( $order ) ) {
			return;
		}

		// Only act on APFS items — legacy subscription items are handled by WC_Subscriptions_Checkout::maybe_add_free_trial_item_meta() at priority 10.
		if ( empty( $cart_item['wcsatt_data']['active_subscription_scheme'] ) ) {
			return;
		}

		if ( 'true' === $item->get_meta( '_has_trial' ) ) {
			return;
		}

		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// Read trial_length from the cart item's product which has APFS runtime meta.
		if ( WC_Subscriptions_Product::get_trial_length( $product ) > 0 ) {
			$item->update_meta_data( '_has_trial', 'true' );
		}
	}

	/**
	 * Hides subscription scheme metadata.
	 *
	 * @since  APFS 2.1.0
	 *
	 * @param  array $hidden
	 * @return array
	 */
	public static function hidden_order_item_meta( $hidden ) {

		// Hide only if not in switch context.
		if ( did_action( 'woocommerce_subscription_item_switched' ) ) {
			return $hidden;
		}

		$current_meta = array( '_wcsatt_scheme' );
		$legacy_meta  = array( '_wcsatt_scheme_id' );

		return array_merge( $hidden, $current_meta, $legacy_meta );
	}

	/**
	 * Modify scheme meta key for switching context.
	 *
	 * @since  APFS 2.5.0
	 *
	 * @param  string $label
	 * @return string
	 */
	public static function modify_scheme_attribute_label( $label ) {

		// Modify only if in switch context.
		if ( did_action( 'woocommerce_subscription_item_switched' ) && '_wcsatt_scheme' === $label ) {
			$label = __( 'plan', 'woocommerce-subscriptions' );
		}

		return $label;
	}

	/**
	 * When adding a new product to a subscription, apply scheme discounts
	 * if a scheme with the same billing schedule as the subscription is found.
	 *
	 * @param int           $item_id
	 * @param WC_Order_Item $item
	 * @param int           $order_id
	 */
	public static function apply_matching_scheme_discount_to_order_item( $item_id, $item, $order_id ) {

		if ( ! is_admin() || ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return;
		}

		if ( ! wcs_is_subscription( $order_id ) ) {
			return;
		}

		if ( ! is_a( $item, 'WC_Order_Item' ) || ! is_callable( array( $item, 'get_product' ) ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $order_id );
		$product      = $item->get_product();

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );

		// Bundles and bundled items are handled via WCS_ATT_Integration_PB_CP::set_bundled_items_scheme().
		if ( $product->is_type( 'bundle' ) ) {
			return;
		}

		// Skipping bundled items when editing Bundles in subscriptions, as plan matching and discounts are handled by 'WCS_ATT_Integration_PB_CP'.
		// An alternative solution would be to use 'wc_pb_is_bundled_order_item' to identify composited items.
		if ( doing_action( 'wp_ajax_woocommerce_configure_bundle_order_item' ) || doing_action( 'wp_ajax_woocommerce_edit_bundle_in_order' ) ) {
			return;
		}

		// Composites and components are handled via WCS_ATT_Integration_PB_CP::set_component_option_scheme().
		if ( $product->is_type( 'composite' ) ) {
			return;
		}

		// Skipping composited items when editing Composites in subscriptions, as plan matching and discounts are handled by 'WCS_ATT_Integration_PB_CP'.
		// An alternative solution would be to use 'wc_cp_is_composited_order_item' to identify composited items.
		if ( doing_action( 'wp_ajax_woocommerce_configure_composite_order_item' ) || doing_action( 'wp_ajax_woocommerce_edit_composite_in_order' ) ) {
			return;
		}

		if ( is_array( $schemes ) && ! empty( $schemes ) ) {

			foreach ( $schemes as $scheme_key => $scheme ) {

				if ( $scheme->matches_subscription( $subscription ) ) {

					// If product prices are entered with tax, then calculate the scheme's price without tax.
					// Order item taxes will be calculated automatically based on the provided subtotal.
					// If product prices are entered with tax, then 'wc_get_price_excluding_tax' doesn't deduct any taxes.
					$price = (float) wc_get_price_excluding_tax(
						$product,
						array(
							'price' => WCS_ATT_Product_Prices::get_price( $product, $scheme_key ),
						)
					);

					$item->set_subtotal( $price );
					$item->set_total( $price * $item->get_quantity() );
					$item->save();
					break;
				}
			}
		}
	}
}
