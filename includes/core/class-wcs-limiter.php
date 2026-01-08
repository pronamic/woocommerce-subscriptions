<?php
/**
 * A class to make it possible to limit a subscription product.
 *
 * @package WooCommerce Subscriptions
 * @category Class
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
 */
class WCS_Limiter {

	/* cache whether a given product is purchasable or not to save running lots of queries for the same product in the same request */
	protected static $is_purchasable_cache = array();

	/* cache the check on whether the session has an order awaiting payment for a given product */
	protected static $order_awaiting_payment_for_product = array();

	public static function init() {

		// Add limiting subscriptions options on edit product page
		add_action( 'woocommerce_product_options_advanced', __CLASS__ . '::admin_edit_product_fields' );

		// Only attach limited subscription purchasability logic on the front end or during checkout block requests.
		if ( wcs_is_frontend_request() || wcs_is_checkout_blocks_api_request() ) {
			add_filter( 'woocommerce_subscription_is_purchasable', __CLASS__ . '::is_purchasable_switch', 12, 2 );
			add_filter( 'woocommerce_subscription_variation_is_purchasable', __CLASS__ . '::is_purchasable_switch', 12, 2 );
			add_filter( 'woocommerce_valid_order_statuses_for_order_again', array( __CLASS__, 'filter_order_again_statuses_for_limited_subscriptions' ) );
		}
	}

	/**
	 * Adds limit options to 'Edit Product' screen.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1, Moved from WC_Subscriptions_Admin
	 */
	public static function admin_edit_product_fields() {
		global $post;

		echo '<div class="options_group limit_subscription show_if_subscription show_if_variable-subscription hidden">';

		// Only one Subscription per customer
		woocommerce_wp_select(
			array(
				'id'          => '_subscription_limit',
				'class'       => 'wc-enhanced-select',
				'label'       => __( 'Limit subscription', 'woocommerce-subscriptions' ),
				// translators: placeholders are opening and closing link tags
				'description' => sprintf( __( 'Only allow a customer to have one subscription to this product. %1$sLearn more%2$s.', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/document/subscriptions/creating-subscription-products/#limit-subscriptions">', '</a>' ),
				'options'     => array(
					'no'     => __( 'Do not limit', 'woocommerce-subscriptions' ),
					'active' => __( 'Limit to one active subscription', 'woocommerce-subscriptions' ),
					'any'    => __( 'Limit to one of any status', 'woocommerce-subscriptions' ),
				),
			)
		);
		echo '</div>';

		do_action( 'woocommerce_subscriptions_product_options_advanced' );
	}

	/**
	 * Checks if the session contains a renewal for a given product.
	 * Used for the pay for order flow.
	 *
	 * @param WC_Product $product The product to check.
	 * @return bool
	 */
	private static function session_contains_renewal( $product ) {
		if ( ! empty( WC()->session->cart ) ) {
			foreach ( WC()->session->cart as $cart_item_key => $cart_item ) {
				if ( (int) $product->get_id() === (int) $cart_item['product_id'] && isset( $cart_item['subscription_renewal'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Checks if the session contains a resubscribe for a given product.
	 * Used for the pay for order flow with limited subscriptions products.
	 *
	 * @param WC_Product $product The product to check.
	 * @return bool
	 */
	private static function session_contains_resubscribe( $product ) {
		if ( ! empty( WC()->session->cart ) ) {
			foreach ( WC()->session->cart as $cart_item_key => $cart_item ) {
				if ( (int) $product->get_id() === (int) $cart_item['product_id'] && isset( $cart_item['subscription_resubscribe'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Canonical is_purchasable method to be called by product classes.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	 * @param bool $purchasable Whether the product is purchasable as determined by parent class
	 * @param mixed $product The product in question to be checked if it is purchasable.
	 *
	 * @return bool
	 */
	public static function is_purchasable( $purchasable, $product ) {

		// Check if product is private (for variations, also check parent product)
		$is_private_product = 'private' === $product->get_status();
		if ( $product->get_parent_id() > 0 ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			if ( $parent_product ) {
				$is_private_product = 'private' === $parent_product->get_status();
			}
		}

		// Checks limits for variable subscription products.
		if ( $product->get_type() === 'subscription_variation' && isset( $parent_product ) ) {

			if ( $is_private_product ) {
				$purchasable = false;

				// Forces product to be available when processing a renewal order. Allowing people to renew private products.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce validation is not required for this context.
				if ( self::is_paying_for_failed_renewal_order( $parent_product ) || isset( $_GET['subscription_renewal'] ) || wcs_cart_contains_renewal() || self::session_contains_renewal( $parent_product ) ) {
					$purchasable = true;
				}
			}

			if ( 'no' !== wcs_get_product_limitation( $parent_product ) && ( ! empty( WC()->cart->cart_contents ) || self::session_contains_resubscribe( $parent_product ) ) && ! wcs_is_order_received_page() && ! wcs_is_paypal_api_page() ) {
				// When mixed checkout is disabled, the variation is replaceable.
				if ( 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {
					foreach ( WC()->cart->cart_contents as $cart_item ) {
						// If the variable product is limited, it can't be purchased if it is the same variation
						if ( $product->get_parent_id() === $cart_item['data']->get_parent_id() && $product->get_id() !== $cart_item['data']->get_id() ) {
							$purchasable = false;
							break;
						}
					}
				}
			}
		} else { // Checks limits for simple subscription products.
			if ( $is_private_product ) {
				$purchasable = false;

				// Forces product to be available when processing a renewal order. Allowing people to renew private products.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce validation is not required for this context.
				if ( self::is_paying_for_failed_renewal_order( $product ) || isset( $_GET['subscription_renewal'] ) || wcs_cart_contains_renewal() || self::session_contains_renewal( $product ) ) {
					$purchasable = true;
				}
			}

			// This actually means the product is limited when returning false.
			if ( false === self::is_product_limited( $purchasable, $product ) ) {
				$resubscribe_cart_item = wcs_cart_contains_resubscribe();
				// Allows the product to be resubscribed but not purchased again.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce validation is not required for this context.
				if ( empty( $_GET['resubscribe'] ) && false === $resubscribe_cart_item && false === self::session_contains_resubscribe( $product ) ) {
					$purchasable = false;
				}
			}
		}

		return $purchasable;
	}

	/**
	 * If a product is limited and the customer already has a subscription, mark it as not purchasable.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1, Moved from WC_Subscriptions_Product
	 * @return bool
	 */
	public static function is_purchasable_product( $is_purchasable, $product ) {
		_deprecated_function( __METHOD__, '8.0.0', 'WCS_Limiter::is_product_limited' );
		return self::is_product_limited( $is_purchasable, $product );
	}

	/**
	 * If a product is limited and the customer already has a subscription, mark it as not purchasable.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1, Moved from WC_Subscriptions_Product
	 * @return bool
	 */
	public static function is_product_limited( $is_purchasable, $product ) {

		// Set up cache
		if ( ! isset( self::$is_purchasable_cache[ $product->get_id() ] ) ) {
			self::$is_purchasable_cache[ $product->get_id() ] = array();
		}

		// Populate the cache if it hasn't been set yet.
		if ( ! isset( self::$is_purchasable_cache[ $product->get_id() ]['standard'] ) ) {
			self::$is_purchasable_cache[ $product->get_id() ]['standard'] = $is_purchasable;

			if ( WC_Subscriptions_Product::is_subscription( $product->get_id() ) && 'no' != wcs_get_product_limitation( $product ) && ! wcs_is_order_received_page() && ! wcs_is_paypal_api_page() ) {

				if ( wcs_is_product_limited_for_user( $product ) && ! self::order_awaiting_payment_for_product( $product->get_id() ) ) {
					self::$is_purchasable_cache[ $product->get_id() ]['standard'] = false;
				}
			}
		}

		return self::$is_purchasable_cache[ $product->get_id() ]['standard'];
	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to switch the subscription, then mark it as purchasable.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1, Moved from WC_Subscriptions_Switcher::is_purchasable
	 * @return bool
	 */
	public static function is_purchasable_switch( $is_purchasable, $product ) {
		$product_key = wcs_get_canonical_product_id( $product );

		// Set an empty cache if one isn't set yet.
		if ( ! isset( self::$is_purchasable_cache[ $product_key ] ) ) {
			self::$is_purchasable_cache[ $product_key ] = array();
		}

		// Exit early if we've already determined this product's purchasability via switching.
		if ( isset( self::$is_purchasable_cache[ $product_key ]['switch'] ) ) {
			return self::$is_purchasable_cache[ $product_key ]['switch'];
		}

		// If the product is already purchasble, we don't need to determine it's purchasibility via switching/auto-switching.
		if ( true === $is_purchasable || ! is_user_logged_in() || ! wcs_is_product_switchable_type( $product ) || ! WC_Subscriptions_Product::is_subscription( $product->get_id() ) ) {
			self::$is_purchasable_cache[ $product_key ]['switch'] = $is_purchasable;
			return self::$is_purchasable_cache[ $product_key ]['switch'];
		}

		$user_id            = get_current_user_id();
		$product_limitation = wcs_get_product_limitation( $product );

		if ( 'no' == $product_limitation || ! wcs_user_has_subscription( $user_id, $product->get_id(), wcs_get_product_limitation( $product ) ) ) {
			self::$is_purchasable_cache[ $product_key ]['switch'] = $is_purchasable;
			return self::$is_purchasable_cache[ $product_key ]['switch'];
		}

		// Adding to cart
		if ( isset( $_GET['switch-subscription'] ) && array_key_exists( wc_clean( wp_unslash( $_GET['switch-subscription'] ) ), self::get_user_subscriptions_to_product( $product, $user_id, $product_limitation ) ) ) {
			$is_purchasable = true;
		} else {
			// If we have a variation product get the variable product's ID. We can't use the variation ID for comparison because this function sometimes receives a variable product.
			$product_id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$cart_contents = array();

			// Use the version of the cart we have access to. We may need to look for switches in the cart being loaded from the session.
			if ( wcs_cart_contains_switches() ) {
				$cart_contents = WC()->cart->cart_contents;
			} elseif ( isset( WC()->session->cart ) ) {
				$cart_contents = WC()->session->cart;
			}

			// Check if the cart contains a switch for this specific product.
			foreach ( $cart_contents as $cart_item ) {
				if ( $product_id === $cart_item['product_id'] && isset( $cart_item['subscription_switch']['subscription_id'] ) && array_key_exists( $cart_item['subscription_switch']['subscription_id'], self::get_user_subscriptions_to_product( $product, $user_id, $product_limitation ) ) ) {
					$is_purchasable = true;
					break;
				}
			}
		}

		self::$is_purchasable_cache[ $product_key ]['switch'] = $is_purchasable;
		return self::$is_purchasable_cache[ $product_key ]['switch'];
	}

	/**
	 * Determines whether a product is purchasable based on whether the cart is to resubscribe or renew.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1, Combines WCS_Cart_Renewal::is_purchasable and WCS_Cart_Resubscribe::is_purchasable
	 * @return bool
	 */
	public static function is_purchasable_renewal( $is_purchasable, $product ) {
		_deprecated_function( __METHOD__, '8.0.0', 'WCS_Limiter::is_product_limited' );

		return ! self::is_product_limited( $is_purchasable, $product );
	}

	/**
	 * Check if the current session has an order awaiting payment for a subscription to a specific product line item.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1.0
	 * @param int $product_id The product to look for a subscription awaiting payment.
	 * @return bool
	 **/
	protected static function order_awaiting_payment_for_product( $product_id ) {
		global $wp;

		if ( isset( self::$order_awaiting_payment_for_product[ $product_id ] ) ) {
			return self::$order_awaiting_payment_for_product[ $product_id ];
		}

		// Set up the cache with a default value.
		self::$order_awaiting_payment_for_product[ $product_id ] = false;

		// If there's no order waiting payment, exit early.
		if ( empty( WC()->session->order_awaiting_payment ) && ! isset( $_GET['pay_for_order'] ) ) {
			return self::$order_awaiting_payment_for_product[ $product_id ];
		}

		$order_id = ! empty( WC()->session->order_awaiting_payment ) ? WC()->session->order_awaiting_payment : $wp->query_vars['order-pay'];
		$order    = wc_get_order( absint( $order_id ) );

		if ( is_object( $order ) && $order->has_status( array( 'pending', 'failed' ) ) ) {
			foreach ( $order->get_items() as $item ) {

				// If this order contains the product we're interested in, continue finding a related subscription.
				if ( $item['product_id'] == $product_id || $item['variation_id'] == $product_id ) {
					$subscriptions = wcs_get_subscriptions(
						array(
							'order_id'            => $order->get_id(),
							'subscription_status' => array( 'active', 'pending', 'on-hold' ),
						)
					);

					foreach ( $subscriptions as $subscription ) {
						// Check that the subscription has the product we're interested in.
						if ( $subscription->has_product( $product_id ) && $subscription->needs_payment() ) {
							self::$order_awaiting_payment_for_product[ $product_id ] = true;
							break 2; // break out of the $subscriptions and order line item loops - we've found at least 1 subscription pending payment for the product.
						}
					}
				}
			}
		}

		return self::$order_awaiting_payment_for_product[ $product_id ];
	}

	/**
	 * Check if we're currently paying for a failed renewal order containing the product.
	 *
	 * @since 8.3.0 - Migrated from WooCommerce Subscriptions v2.1.0
	 * @param WC_Product $product The product to check.
	 * @return bool
	 */
	protected static function is_paying_for_failed_renewal_order( $product ) {
		global $wp;

		// Check if we're on the pay for order page
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['pay_for_order'] ) || ! isset( $_GET['key'] ) || ! isset( $wp->query_vars['order-pay'] ) ) {
			// Also check if cart contains a failed renewal order payment
			$failed_renewal_cart_item = wcs_cart_contains_failed_renewal_order_payment();
			if ( false !== $failed_renewal_cart_item ) {
				$cart_item_product_id = isset( $failed_renewal_cart_item['variation_id'] ) && $failed_renewal_cart_item['variation_id'] > 0
					? $failed_renewal_cart_item['variation_id']
					: $failed_renewal_cart_item['product_id'];
				// Check both the product ID and parent product ID (for variations)
				if ( (int) $product->get_id() === (int) $cart_item_product_id || (int) $product->get_id() === (int) $failed_renewal_cart_item['product_id'] ) {
					return true;
				}
				// Also check if product is a variation and matches the parent
				if ( $product->get_parent_id() > 0 && (int) $product->get_parent_id() === (int) $failed_renewal_cart_item['product_id'] ) {
					return true;
				}
			}
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		$order_id  = isset( $wp->query_vars['order-pay'] ) ? $wp->query_vars['order-pay'] : 0;
		$order     = wc_get_order( absint( $order_id ) );

		if ( ! $order || $order->get_order_key() !== $order_key ) {
			return false;
		}

		// Check if order is a failed renewal order
		if ( ! $order->has_status( 'failed' ) && ! wcs_order_contains_renewal( $order ) ) {
			return false;
		}

		// Check if the order contains the product
		foreach ( $order->get_items() as $item ) {
			$item_product_id = isset( $item['variation_id'] ) && $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'];
			// Check both the product ID and parent product ID (for variations)
			if ( (int) $product->get_id() === (int) $item_product_id || (int) $product->get_id() === (int) $item['product_id'] ) {
				return true;
			}
			// Also check if product is a variation and matches the parent
			if ( $product->get_parent_id() > 0 && (int) $product->get_parent_id() === (int) $item['product_id'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filters the order statuses that enable the order again button and functionality.
	 *
	 * This function will return no statuses if the order contains non purchasable or limited products.
	 *
	 * @since 8.3.0 - Migrated from WooCommerce Subscriptions v3.0.2
	 *
	 * @param array $statuses The order statuses that enable the order again button.
	 * @return array $statuses An empty array if the order contains limited products, otherwise the default statuses are returned.
	 */
	public static function filter_order_again_statuses_for_limited_subscriptions( $statuses ) {
		global $wp;

		if ( is_view_order_page() ) {
			$order = wc_get_order( absint( $wp->query_vars['view-order'] ) );
		} elseif ( is_order_received_page() ) {
			$order = wc_get_order( absint( $wp->query_vars['order-received'] ) );
		}

		if ( empty( $order ) ) {
			return $statuses;
		}

		$is_purchasable = true;

		foreach ( $order->get_items() as $line_item ) {
			$product = $line_item->get_product();

			if ( WC_Subscriptions_Product::is_subscription( $product ) && wcs_is_product_limited_for_user( $product ) ) {
				$is_purchasable = false;
				break;
			}
		}

		// If all products are purchasable, return the default statuses, otherwise return no statuses.
		if ( $is_purchasable ) {
			return $statuses;
		} else {
			return array();
		}
	}

	/**
	 * Gets a list of the customer subscriptions to a product with a particular limited status.
	 *
	 * @param WC_Product|int $product      The product object or product ID.
	 * @param int            $user_id      The user's ID.
	 * @param string         $limit_status The limit status.
	 *
	 * @return WC_Subscription[] An array of a customer's subscriptions with a specific status and product.
	 */
	protected static function get_user_subscriptions_to_product( $product, $user_id, $limit_status ) {
		static $user_subscriptions_to_product = array();
		$product_id = is_object( $product ) ? $product->get_id() : $product;
		$cache_key  = "{$product_id}_{$user_id}_{$limit_status}";

		if ( ! isset( $user_subscriptions_to_product[ $cache_key ] ) ) {
			// Getting all the customers subscriptions and removing ones without the product is more performant than querying for subscriptions with the product.
			$subscriptions = wcs_get_subscriptions(
				array(
					'customer_id' => $user_id,
					'status'      => $limit_status,
				)
			);

			foreach ( $subscriptions as $subscription_id => $subscription ) {
				if ( ! $subscription->has_product( $product_id ) ) {
					unset( $subscriptions[ $subscription_id ] );
				}
			}

			$user_subscriptions_to_product[ $cache_key ] = $subscriptions;
		}

		return $user_subscriptions_to_product[ $cache_key ];
	}
}
