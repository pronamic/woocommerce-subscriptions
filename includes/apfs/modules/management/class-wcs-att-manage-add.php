<?php
/**
 * WCS_ATT_Manage_Add class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add stuff to existing subscriptions.
 *
 * @class    WCS_ATT_Manage_Add
 * @version  4.0.5
 */
class WCS_ATT_Manage_Add extends WCS_ATT_Abstract_Module {

	/**
	 * Include sub-modules.
	 */
	protected function register_modules() {

		// Initialize modules.
		$this->modules = array(
			'manage_add_product' => 'WCS_ATT_Manage_Add_Product',
			'manage_add_cart'    => 'WCS_ATT_Manage_Add_Cart',
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register_core_hooks() {

		// Adds a product to a subscription.
		add_action( 'wcsatt_add_product_to_subscription', array( __CLASS__, 'add_product_to_subscription' ), 10, 3 );

		// Adds all items in a recurring cart to a subscription.
		add_action( 'wcsatt_add_cart_to_subscription', array( __CLASS__, 'add_cart_to_subscription' ), 10 );
	}

	/**
	 * Get posted data.
	 *
	 * @param  string $context
	 * @return array
	 */
	public static function get_posted_data( $context ) {

		$posted_data = array();

		if ( 'product' === $context ) {

			$posted_data = array(
				'product_id'          => false,
				'subscription_id'     => false,
				'subscription_scheme' => false,
			);

			if ( ! empty( $_REQUEST['add-to-subscription'] ) && is_numeric( $_REQUEST['add-to-subscription'] ) ) {

				if ( ! empty( $_REQUEST['add-product-to-subscription'] ) && is_numeric( $_REQUEST['add-product-to-subscription'] ) ) {

					$posted_data['product_id']          = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_REQUEST['add-product-to-subscription'] ) );
					$posted_data['nonce']               = ! empty( $_REQUEST[ 'wcsatt_nonce_' . $posted_data['product_id'] ] ) ? wc_clean( $_REQUEST[ 'wcsatt_nonce_' . $posted_data['product_id'] ] ) : '';
					$posted_data['subscription_id']     = absint( $_REQUEST['add-to-subscription'] );
					$posted_data['subscription_scheme'] = WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $posted_data['product_id'] );
				}
			}
		} elseif ( 'cart' === $context ) {

			$posted_data = array(
				'subscription_id' => false,
			);

			if ( ! empty( $_REQUEST['add-to-subscription-checked'] ) ) {

				if ( ! empty( $_REQUEST['add-cart-to-subscription'] ) && is_numeric( $_REQUEST['add-cart-to-subscription'] ) ) {

					$posted_data['nonce']           = ! empty( $_REQUEST['wcsatt_nonce'] ) ? wc_clean( $_REQUEST['wcsatt_nonce'] ) : '';
					$posted_data['subscription_id'] = absint( $_REQUEST['add-cart-to-subscription'] );
				}
			}
		} elseif ( 'update-cart' === $context ) {

			$posted_data = array(
				'add_to_subscription_checked' => false,
			);

			$key = doing_action( 'wc_ajax_wcsatt_update_cart_option' ) ? 'add_to_subscription_checked' : 'add-to-subscription-checked';

			if ( ! empty( $_REQUEST[ $key ] ) && 'yes' === $_REQUEST[ $key ] ) {
				$posted_data['add_to_subscription_checked'] = true;
			}
		}

		return $posted_data;
	}

	/**
	 * Get matching cart scheme, if all cart items share the same scheme. Returns false otherwise.
	 *
	 * @since  APFS 3.4.0
	 *
	 * @return array|null An array of WCS_ATT_Scheme objects that are common on the cart contents, null for all subscriptions.
	 */
	public static function get_schemes_matching_cart() {

		// Check for empty cart.
		if ( empty( WC()->cart->cart_contents ) ) {
			return array();
		}

		$add_cart_to_subscription_setting = get_option( 'wcsatt_add_cart_to_subscription', 'off' );
		$intersection                     = null;

		foreach ( WC()->cart->cart_contents as $cart_item ) {

			if ( ! WCS_ATT_Cart::is_supported( $cart_item ) ) {
				// Block the intersection.
				$intersection = array();
				break;
			}

			if ( ! WCS_ATT_Product::supports_feature( $cart_item['data'], 'subscription_management_add_to_subscription_product_cart' ) ) {
				// Block the intersection.
				$intersection = array();
				break;
			}

			if ( isset( $cart_item['subscription_renewal'] ) || isset( $cart_item['subscription_initial_payment'] ) || isset( $cart_item['subscription_resubscribe'] ) || isset( $cart_item['subscription_switch'] ) ) {
				// Block the intersection.
				$intersection = array();
				break;
			}

			$schemes_for_match = array();
			$current_scheme    = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'], 'object' );

			// Product has a selected plan.
			if ( is_a( $current_scheme, 'WCS_ATT_Scheme' ) ) {

				$schemes_for_match = array( $current_scheme->get_key() => $current_scheme );

			} elseif ( empty( $current_scheme ) ) {

				// The current plan might be null but product plans might still exist (this may happen if the currently selected plan gets deleted by the admin).
				$available_schemes = WCS_ATT_Cart::get_subscription_schemes( $cart_item );

				// If the product has plans, contribute those.
				if ( $available_schemes ) {
					$schemes_for_match = $available_schemes;
				} else {

					$schemes_for_match = null;

					/**
					 *
					 * Hook `wcsatt_add_cart_to_subscription_schemes_matching_schemeless_cart_item`.
					 *
					 * @since  APFS 3.4.0
					 *
					 * Use this filter to limit the plans contributed by this otherwise plan-less cart items.
					 * For example you may prefer to always return null, or an empty array.
					 *
					 * @param  null|array  $schemes
					 * @param  array       $cart_item
					 */
					$schemes_for_match = apply_filters( 'wcsatt_add_cart_to_subscription_schemes_matching_schemeless_cart_item', $schemes_for_match, $cart_item );

					// Can be added to any subscription - not contributing in the intersection?
					if ( is_null( $schemes_for_match ) ) {
						continue;
					}
				}
			}

			// Handle first-time iteration and intersect keys on all others.
			if ( is_null( $intersection ) ) {
				$intersection = $schemes_for_match;
			} else {
				$intersection = array_intersect_key( $schemes_for_match, $intersection );
			}

			if ( is_array( $intersection ) && empty( $intersection ) ) {
				break;
			}
		}

		/**
		 * Hook `wcsatt_add_cart_to_subscription_schemes_matching_cart`.
		 *
		 * @since APFS 3.4.0
		 *
		 * Filter the available options for adding a cart to an existing subscription.
		 *
		 * @param  array|null  $intersection
		 * @return array|null
		 */
		return apply_filters( 'wcsatt_add_cart_to_subscription_schemes_matching_cart', $intersection );
	}

	/**
	 * Gets all active subscriptions of the current user matching a set of schemes.
	 *
	 * @param  array|null $schemes
	 * @return array
	 */
	public static function get_matching_subscriptions( $schemes ) {

		// Transform input param into array for backwards compatibility.
		if ( is_a( $schemes, 'WCS_ATT_Scheme' ) ) {
			$schemes = array( $schemes->get_key() => $schemes );
		}

		// Filter them by period + interval.
		$matching_subscriptions = array();

		// Get all subscriptions of the current user.
		$subscriptions = wcs_get_subscriptions(
			array(
				'subscription_status'    => array( 'active' ),
				'subscriptions_per_page' => -1,
				'customer_id'            => get_current_user_id(),
			)
		);

		if ( empty( $subscriptions ) ) {
			return $matching_subscriptions;
		}

		foreach ( $subscriptions as $subscription_id => $subscription ) {

			if ( ! $subscription->payment_method_supports( 'subscription_amount_changes' ) ) {
				continue;
			}

			// If null, include all supported account subscriptions.
			if ( is_array( $schemes ) && ! empty( $schemes ) ) {

				foreach ( $schemes as $scheme_key => $scheme ) {

					if ( $scheme->matches_subscription( $subscription ) ) {
						$matching_subscriptions[ $subscription_id ] = $subscription;
						break;
					}
				}
			} else {
				$matching_subscriptions[ $subscription_id ] = $subscription;
			}
		}

		return $matching_subscriptions;
	}

	/**
	 * Adds a product to a subscription.
	 *
	 * @param  WC_Subscription $subscription
	 * @param  WC_Product      $product
	 * @param  array           $args
	 * @return boolean
	 */
	public static function add_product_to_subscription( $subscription, $product, $args ) {

		$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
		$default_args        = array(
			'product_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
			'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
			'quantity'     => 1,
			'variation'    => array(),
		);

		$parsed_args = wp_parse_args( $args, $default_args );

		/*
		 * Add the product to cart first to ensure all hooks get fired.
		 */

		// Back up the existing cart contents.
		$add_to_subscription_args = array(
			'adding_product'        => $product,
			'restore_cart_contents' => WC()->cart->get_cart(),
		);

		$add_to_subscription_args['restore_cart_contents'] = empty( $add_to_subscription_args['restore_cart_contents'] ) ? false : $add_to_subscription_args['restore_cart_contents'];

		// Empty the cart.
		WC()->cart->empty_cart( false );

		$extra_cart_data = array( 'add_product_to_subscription_schemes' => WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) );
		$cart_item_key   = WC()->cart->add_to_cart( $parsed_args['product_id'], $parsed_args['quantity'], $parsed_args['variation_id'], $parsed_args['variation'], $extra_cart_data );

		// Add the product to cart.
		if ( ! $cart_item_key ) {

			wc_clear_notices();

			$subscription_url  = $subscription->get_view_order_url();
			$subscription_link = sprintf( _x( '<a href="%1$s">#%2$s</a>', 'link to subscription', 'woocommerce-subscriptions' ), esc_url( $subscription_url ), $subscription->get_id() );

			wc_add_notice( sprintf( __( 'There was a problem adding "%1$s" to subscription %2$s. Please get in touch with us for assistance.', 'woocommerce-subscriptions' ), $product->get_name(), $subscription_link ), 'error' );

			if ( $add_to_subscription_args['restore_cart_contents'] ) {
				WC()->cart->cart_contents = $parsed_args['restore_cart_contents'];
				WC()->cart->calculate_totals();
			}

			return false;
		}

		// Set scheme on product in cart to ensure it gets seen as a subscription by WCS.
		WCS_ATT_Product_Schemes::set_subscription_scheme( WC()->cart->cart_contents[ $cart_item_key ]['data'], $subscription_scheme );

		// Calculate totals.
		WC()->cart->calculate_totals();

		/*
		 * Now -- add the cart contents to our subscription.
		 */

		self::add_cart_to_subscription( $subscription, $add_to_subscription_args );
	}

	/**
	 * Adds the contents of a (recurring) cart to a subscription.
	 *
	 * @param  WC_Subscription $subscription
	 * @param  boolean         $args
	 */
	public static function add_cart_to_subscription( $subscription, $args = array() ) {

		// Make sure recurring carts are there.
		if ( ! did_action( 'woocommerce_after_calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}

		if ( empty( WC()->cart->recurring_carts ) || 1 !== count( WC()->cart->recurring_carts ) ) {
			wc_add_notice( __( 'Action failed. Please get in touch with us for assistance.', 'woocommerce-subscriptions' ), 'error' );
			return;
		}

		$default_args = array(
			'adding_product'        => false,
			'restore_cart_contents' => false,
		);

		$parsed_args = wp_parse_args( $args, $default_args );

		$cart               = current( WC()->cart->recurring_carts );
		$item_added         = false;
		$found_items        = array();
		$subscription_items = $subscription->get_items();
		$subscription_url   = $subscription->get_view_order_url();
		$subscription_link  = sprintf( _x( '<a href="%1$s">#%2$s</a>', 'link to subscription', 'woocommerce-subscriptions' ), esc_url( $subscription_url ), $subscription->get_id() );

		// First, map out identical items.
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

			$product        = $cart_item['data'];
			$variation_data = $cart_item['variation'];
			$product_id     = $cart_item['product_id'];
			$variation_id   = $cart_item['variation_id'];
			$quantity       = $cart_item['quantity'];
			$found_item     = false;

			/*
			 * Does an identical line item already exist (hm, what does identical really mean in this context :S)?
			 */
			foreach ( $subscription->get_items() as $item_id => $item ) {

				// Same ID?
				if ( $product_id === $item->get_product_id() && $variation_id === $item->get_variation_id() ) {

					/*
					 * Totals match?
					 */

					$quantity_changed = false;

					// Are we comparing apples to apples?
					if ( $quantity !== $item->get_quantity() ) {

						$cart->set_quantity( $cart_item_key, $item->get_quantity() );
						$cart->calculate_totals();

						$quantity_changed = true;
					}

					// Compare totals.
					if ( $cart->cart_contents[ $cart_item_key ]['line_total'] == $item->get_total() && $cart->cart_contents[ $cart_item_key ]['line_subtotal'] == $item->get_subtotal() ) {
						$found_item = $item;
					}

					// Reset cart item quantity.
					if ( $quantity_changed ) {
						$cart->set_quantity( $cart_item_key, $quantity );
					}

					/*
					 * Variation? Check if attribute values match.
					 */

					if ( $found_item ) {
						if ( $product->is_type( 'variation' ) ) {
							foreach ( $variation_data as $key => $value ) {
								if ( $value !== $item->get_meta( str_replace( 'attribute_', '', $key ), true ) ) {
									$found_item = false;
									break;
								}
							}
						}
					}
				}

				// There's still a chance something else might be different, so let's add a filter here.

				/**
				 * 'wcsatt_add_cart_to_subscription_found_item' filter.
				 *
				 * @param  WC_Order_Item_Product|false  $found_item
				 * @param  array                        $cart_item
				 * @param  WC_Cart                      $cart
				 * @param  WC_Subscription              $subscription
				 * @param  WC_Order_Item                $item
				 */
				$found_item = apply_filters( 'wcsatt_add_cart_to_subscription_found_item', $found_item, $cart_item, $cart, $subscription, $item );

				if ( $found_item ) {
					// Save.
					$found_items[ $cart_item_key ] = $item_id;
					// Break.
					break;
				}
			}
		}

		// If any identical items were found, increment their quantities and recalculate cart totals :)
		if ( ! empty( $found_items ) ) {

			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

				if ( isset( $found_items[ $cart_item_key ] ) ) {

					$quantity   = $cart_item['quantity'];
					$found_item = $subscription_items[ $found_items[ $cart_item_key ] ];

					$cart->set_quantity( $cart_item_key, $quantity + $found_item->get_quantity() );
				}
			}

			$cart->calculate_totals();
		}

		// Now, get to work.
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

			$product        = $cart_item['data'];
			$variation_data = $cart_item['variation'];
			$quantity       = $cart_item['quantity'];
			$total          = $cart_item['line_total'];
			$subtotal       = $cart_item['line_subtotal'];

			$product_id   = $cart_item['product_id'];
			$variation_id = $cart_item['variation_id'];

			// If an identical line item was found, increase its quantity in the subscription.
			if ( isset( $found_items[ $cart_item_key ] ) ) {

				$found_item    = $subscription_items[ $found_items[ $cart_item_key ] ];
				$existing_item = clone $found_item;

				$item_qty          = $found_item->get_quantity();
				$item_qty_new      = $quantity;
				$item_total_new    = $total;
				$item_subtotal_new = $subtotal;

				$found_item->set_quantity( $item_qty_new );
				$found_item->set_total( $item_total_new );
				$found_item->set_subtotal( $item_subtotal_new );

				$subscription->add_order_note( sprintf( _x( 'Customer increased the quantity of "%1$s" (Product ID: #%2$d) from %3$s to %4$s.', 'used in order note', 'woocommerce-subscriptions' ), $found_item->get_name(), $product_id, $item_qty, $item_qty_new ) );

				/**
				 * 'wcsatt_add_cart_item_to_subscription_item_updated' action.
				 *
				 * Fired when an identical item is found in the subscription.
				 *
				 * @param  WC_Order_Item_Product  $found_item
				 * @param  WC_Order_Item_Product  $existing_item
				 * @param  array                  $cart_item
				 * @param  WC_Cart                $cart
				 * @param  WC_Subscription        $subscription
				 */
				do_action( 'wcsatt_add_cart_item_to_subscription_item_updated', $found_item, $existing_item, $cart_item, $cart, $subscription );

				$found_item->save();

				$item_added = true;

				// Otherwise, add a new line item.
			} else {

				/**
				 * Custom callback for adding cart items to subscriptions.
				 *
				 * @param  array|false  $callback
				 * @param  array        $cart_item
				 * @param  WC_Cart      $cart
				 */
				$add_cart_item_to_subscription_callback = apply_filters( 'wscatt_add_cart_item_to_subscription_callback', false, $cart_item, $cart );

				// Do not add cart item.
				if ( is_null( $add_cart_item_to_subscription_callback ) ) {

					continue;

					// Use custom callback to add cart item.
				} if ( is_callable( $add_cart_item_to_subscription_callback ) ) {

					$added_item_id = call_user_func_array( $add_cart_item_to_subscription_callback, array( $subscription, $cart_item, $cart ) );

					// Use standard method.
				} else {

					$item = apply_filters( 'woocommerce_checkout_create_order_line_item_object', new WC_Order_Item_Product(), $cart_item_key, $cart_item, $subscription );

					$item->set_props(
						array(
							'name'         => $product->get_name(),
							'tax_class'    => $product->get_tax_class(),
							'product_id'   => $product_id,
							'variation_id' => $variation_id,
							'variation'    => $variation_data,
							'quantity'     => $quantity,
							'subtotal'     => $subtotal,
							'total'        => $total,
						)
					);

					do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $cart_item, $subscription );

					$item->save();
					$subscription->add_item( $item );

					$added_item_id = $item->get_id();
				}

				if ( ! $added_item_id || is_wp_error( $added_item_id ) ) {

					wc_add_notice( sprintf( __( 'There was a problem adding "%1$s" to subscription %2$s.', 'woocommerce-subscriptions' ), $product->get_name(), $subscription_link ), 'error' );

				} else {

					$item_added = true;
					$added_item = wcs_get_order_item( $added_item_id, $subscription );

					// Save the scheme key!
					$added_item->add_meta_data( '_wcsatt_scheme', WCS_ATT_Product_Schemes::get_subscription_scheme( $product ), true );

					$subscription->add_order_note( sprintf( _x( 'Customer added "%1$s" (Product ID: #%2$d).', 'used in order note', 'woocommerce-subscriptions' ), $added_item->get_name(), $product_id ) );

					/**
					 * 'wcsatt_add_cart_item_to_subscription_item_added' action.
					 *
					 * Fired when a new item is added to the subscription.
					 *
					 * @param  WC_Order_Item_Product  $found_item
					 * @param  array                  $cart_item
					 * @param  WC_Cart                $cart
					 * @param  WC_Subscription        $subscription
					 */
					do_action( 'wcsatt_add_cart_item_to_subscription_item_added', $added_item, $cart_item, $cart, $subscription );

					$added_item->save();
				}
			}
		}

		// Success, something was added. Note that we don't handle partial failures here, maybe we should?
		if ( $item_added ) {

			$subscription->calculate_totals();
			$subscription->save();

			// Adding a product to a subscription from the single-product page?
			if ( is_a( $parsed_args['adding_product'], 'WC_Product' ) ) {
				$success_message = sprintf( __( 'You have successfully added "%1$s" to subscription %2$s.', 'woocommerce-subscriptions' ), $parsed_args['adding_product']->get_name(), $subscription_link );
			} else {
				$success_message = sprintf( __( 'You have successfully added the contents of your cart to subscription %s.', 'woocommerce-subscriptions' ), $subscription_link );
			}

			wc_add_notice( $success_message );

			/**
			 * Filter redirect url.
			 *
			 * @param  string           $url
			 * @param  WC_Subscription  $subscription
			 */
			$redirect_url = apply_filters( 'wcsatt_add_cart_to_subscription_redirect_url', $subscription_url, $subscription );

			// Adding a product to a subscription from the single-product page?
			if ( is_a( $parsed_args['adding_product'], 'WC_Product' ) ) {
				// Reset cart contents to an earlier state if needed - @see 'add_product_to_subscription'.
				if ( is_array( $parsed_args['restore_cart_contents'] ) ) {
					WC()->cart->cart_contents = $parsed_args['restore_cart_contents'];
					WC()->cart->calculate_totals();
					// Otherwise nothing must have been in the cart in the first place.
				} else {
					WC()->cart->empty_cart();
				}
				// Just empty the cart, assuming success at this point... or?
			} else {
				WC()->cart->empty_cart();
			}

			// Prevent redirections and early exits in AJAX requests -- these are handled by the AJAX handler.
			if ( ! wp_doing_ajax() ) {
				wp_safe_redirect( $redirect_url );
				exit;
			}
		}
	}
}
