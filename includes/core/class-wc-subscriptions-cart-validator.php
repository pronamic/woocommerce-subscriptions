<?php
/**
 * Subscriptions Cart Validator Class
 *
 * Validates the Cart contents
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Cart_Validator
 * @category   Class
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */
class WC_Subscriptions_Cart_Validator {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 */
	public static function init() {

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'maybe_empty_cart' ), 10, 6 );
		add_filter( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'validate_cart_contents_for_mixed_checkout' ), 10 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'can_add_product_to_cart' ), 10, 6 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_subscription_limits' ) );

	}

	/**
	 * When a subscription is added to the cart, remove other products/subscriptions to
	 * work with PayPal Standard, which only accept one subscription per checkout.
	 *
	 * If multiple purchase flag is set, allow them to be added at the same time.
	 *
	 * @param bool   $valid       Whether the product can be added to the cart.
	 * @param int    $product_id  The product ID.
	 * @param int    $quantity    The quantity of the product being added.
	 * @param int    $variation_id The variation ID.
	 * @param array  $variations  The variations of the product being added.
	 * @param array  $item_data   The additional item data set by all plugins.
	 *
	 * @return bool Whether the product can be added to the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function maybe_empty_cart( $valid, $product_id, $quantity, $variation_id = 0, $variations = array(), $item_data = array() ) {
		$is_subscription                 = WC_Subscriptions_Product::is_subscription( $product_id );
		$cart_contains_subscription      = WC_Subscriptions_Cart::cart_contains_subscription();
		$payment_gateways_handler        = WC_Subscriptions_Core_Plugin::instance()->get_gateways_handler_class();
		$multiple_subscriptions_possible = $payment_gateways_handler::one_gateway_supports( 'multiple_subscriptions' );
		$manual_renewals_enabled         = wcs_is_manual_renewal_enabled();
		$canonical_product_id            = ! empty( $variation_id ) ? $variation_id : $product_id;

		/**
		 * These flags are used by Product Bundles and Composite Products to indicate that the product is being added as part of an order again.
		 * We don't need to empty cart in this case but neither we need to add the product again.
		 */
		if ( isset( $item_data['is_order_again_composited'] ) || isset( $item_data['is_order_again_bundled'] ) ) {
			return false;
		}

		if ( $is_subscription && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {

			// Generate a cart item key from variation and cart item data - which may be added by other plugins
			$cart_item_data = (array) apply_filters( 'woocommerce_add_cart_item_data', array(), $product_id, $variation_id, $quantity );
			$cart_item_id   = WC()->cart->generate_cart_id( $product_id, $variation_id, $variations, $cart_item_data );
			$product        = wc_get_product( $product_id );

			// If the product is sold individually or if the cart doesn't already contain this product, empty the cart.
			if ( ! WC()->cart->is_empty() && ( ( $product && $product->is_sold_individually() ) || ! WC()->cart->find_product_in_cart( $cart_item_id ) ) ) {
				$message = $cart_contains_subscription ? __( 'A subscription has been removed from your cart. Only one subscription product can be purchased at a time.', 'woocommerce-subscriptions' ) : __( 'Products have been removed from your cart. Products and subscriptions can not be purchased at the same time.', 'woocommerce-subscriptions' );
				WC()->cart->empty_cart();
				wc_add_notice( $message, 'notice' );
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
			add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'add_to_cart_ajax_redirect' ) );
		}

		return $valid;
	}

	/**
	 * This checks cart items for mixed checkout.
	 *
	 * @param $cart WC_Cart the one we got from session
	 * @return WC_Cart $cart
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function validate_cart_contents_for_mixed_checkout( $cart ) {

		// When mixed checkout is enabled, the broader "different sub products in cart" reconciliation
		// below should not run, but per-product duplicates (resubscribe/renewal + plain for the same
		// limited product) must still be reconciled. Mixed checkout opts in to multiple subscriptions,
		// not to violating per-product limits.
		if ( $cart->cart_contents && 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {
			self::reconcile_same_product_duplicates( $cart );
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
				add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'add_to_cart_ajax_redirect' ) );

				break;
			}
		}

		return $cart;
	}

	/**
	 * Validates an incoming add-to-cart attempt against four cart-item-shape rules:
	 *
	 * 1. Renewal-block: if the cart already contains a subscription renewal, reject any
	 *    non-renewal add (plain or resubscribe-tagged). Renewal carts must remain isolated to
	 *    the single renewing subscription.
	 * 2. Active-subscription-flow-block: if the cart already contains a resubscribe or switch
	 *    item for the same product, reject a plain add for that product. Each control-item
	 *    type fires its own branch with a flow-specific notice ("subscription resubscribe",
	 *    "subscription switch") so the customer can identify which existing flow to complete
	 *    or cancel. `subscription_initial_payment` items are deliberately excluded - those
	 *    only appear via the pay-for-order URL, where the cart is captive to the pending
	 *    order at the system level (update_cart_hash) and Layer 1 protection is unnecessary.
	 * 3. Plain-vs-control-block: if the incoming item is renewal- or resubscribe-tagged and the
	 *    cart already contains a plain item for the same product, reject the add (symmetric
	 *    counterpart of rule 2).
	 * 4. Limited-product duplicate-block: if the incoming item is a plain add for a limited
	 *    subscription product and the cart already contains another plain item for the same
	 *    product, reject the add. WC normally merges matching cart-ids, but variations with
	 *    differing attributes or extension-injected item data can bypass that merge.
	 *
	 * @since 7.7.0
	 * @since 8.8.0 Extended to handle three additional enforcement paths (rules 2, 3, and 4).
	 *              Rule 2 expanded to cover subscription_switch and subscription_initial_payment
	 *              cart items in addition to subscription_resubscribe.
	 *
	 * @param bool   $can_add      Whether the product can be added to the cart.
	 * @param int    $product_id   The product ID.
	 * @param int    $quantity     The quantity of the product being added.
	 * @param int    $variation_id The variation ID.
	 * @param array  $variations   The variations of the product being added.
	 * @param array  $item_data    The item data.
	 *
	 * @return bool Whether the product can be added to the cart.
	 */
	public static function can_add_product_to_cart( $can_add, $product_id, $quantity, $variation_id = 0, $variations = array(), $item_data = array() ) {
		if ( ! $can_add ) {
			return $can_add;
		}

		$is_renewal_item     = isset( $item_data['subscription_renewal'] );
		$is_resubscribe_item = isset( $item_data['subscription_resubscribe'] );
		$is_plain_item       = ! $is_renewal_item && ! $is_resubscribe_item;

		// Reject any non-renewal add (plain or resubscribe-tagged) when the cart already contains
		// a subscription renewal. Renewal carts must remain isolated to that single subscription.
		if ( ! $is_renewal_item && wcs_cart_contains_renewal() ) {
			wc_add_notice( __( 'That product can not be added to your cart as it already contains a subscription renewal.', 'woocommerce-subscriptions' ), 'error' );

			return false;
		}

		// Compute the cart-item map once; the rules below all index into the same map.
		$existing_items = self::get_cart_items_for_product( $product_id, $variation_id );

		// Block adding a plain item when the cart already contains a resubscribe for the same product.
		if ( $is_plain_item && ! empty( $existing_items['resubscribe'] ) ) {
			wc_add_notice( __( 'That product can not be added to your cart as it already contains a subscription resubscribe.', 'woocommerce-subscriptions' ), 'error' );

			return false;
		}

		// Block adding a plain item when the cart already contains a subscription switch for the same product.
		if ( $is_plain_item && ! empty( $existing_items['switch'] ) ) {
			wc_add_notice( __( 'That product can not be added to your cart as it already contains a subscription switch for the same product.', 'woocommerce-subscriptions' ), 'error' );

			return false;
		}

		// `subscription_initial_payment` cart items are intentionally NOT enforced here. Those
		// items are populated by `WCS_Cart_Initial_Payment::maybe_setup_cart()` only on the
		// pay-for-order URL (/checkout/order-pay/X/?pay_for_order=...); the cart is captive to
		// the pending order at the system level via `update_cart_hash`, so Layer 1 does not
		// need to defend against fresh plain adds layered on top. The 5-bucket scheme in
		// `get_cart_items_for_product()` still exposes them so Layer 3 can classify correctly.

		// Block adding a renewal/resubscribe-tagged item when the cart already contains a plain item for the same product.
		if ( ( $is_renewal_item || $is_resubscribe_item ) && ! empty( $existing_items['plain'] ) ) {
			wc_add_notice( __( 'That product can not be added to your cart because it already contains a non-subscription item for the same product. Please remove the existing item before continuing.', 'woocommerce-subscriptions' ), 'error' );

			return false;
		}

		// Block adding a duplicate plain item for a limited subscription product. WC normally merges
		// quantities when the cart_id matches, but variations with different attributes or item data
		// injected by extensions can bypass that merge - this is the safety net for those edge cases.
		if ( $is_plain_item && ! empty( $existing_items['plain'] ) && self::is_limited_subscription_product( $product_id ) ) {
			wc_add_notice( __( 'That product can not be added to your cart because it can not be purchased more than once. Please remove the existing item before continuing.', 'woocommerce-subscriptions' ), 'error' );

			return false;
		}

		return $can_add;
	}

	/**
	 * Adds the required cart AJAX args and filter callbacks to cause an error and redirect the customer.
	 *
	 * Attached by @see WC_Subscriptions_Cart_Validator::validate_cart_contents_for_mixed_checkout() and
	 * @see WC_Subscriptions_Cart_Validator::maybe_empty_cart() when the store has multiple subscription
	 * purchases disabled, the cart already contains products and the customer adds a new item or logs in
	 * causing a cart merge.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param array  $fragments The add to cart AJAX args.
	 * @return array $fragments
	 */
	public static function add_to_cart_ajax_redirect( $fragments ) {
		$fragments['error']       = true;
		$fragments['product_url'] = wc_get_cart_url();

		# Force error on add_to_cart() to redirect
		add_filter( 'woocommerce_add_to_cart_validation', '__return_false', 10 );
		add_filter( 'woocommerce_cart_redirect_after_error', 'wc_get_cart_url', 10 );
		do_action( 'wc_ajax_add_to_cart' );

		return $fragments;
	}

	/**
	 * Validates the cart against per-product subscription limits.
	 *
	 * Hooked to `woocommerce_check_cart_items` (cart page load, checkout page load, and Place Order
	 * submission). When a limited subscription product appears more than once across cart items,
	 * or appears as a plain item while the customer already has an active/on-hold subscription
	 * to it, an error notice is queued. WooCommerce blocks cart and checkout progression while
	 * any error notice is registered against this hook.
	 *
	 * Acts as the last-mile defence for code paths that bypass `can_add_product_to_cart` (Store API,
	 * programmatic `WC()->cart->add_to_cart()` from extensions, restored session carts).
	 *
	 * Skips the order-received page and the PayPal API handler so post-payment confirmation
	 * and PayPal IPN callback hydration do not surface error notices. This mirrors two of the
	 * three context bypasses in `WCS_Limiter::is_product_limited`; the third bypass there
	 * (`order_awaiting_payment_for_product()`) is not duplicated because Layer 3 already exempts
	 * "control item + zero plain" carts, which is the shape produced by pay-for-order flows.
	 *
	 * @since 8.8.0
	 */
	public static function validate_subscription_limits() {
		// Mirror `WCS_Limiter::is_product_limited`'s context guards. The order-received page and
		// PayPal IPN handlers can hydrate the cart with remnants of just-paid orders; treating
		// those carts as conflicts would block payment confirmations.
		if ( wcs_is_order_received_page() || wcs_is_paypal_api_page() ) {
			return;
		}

		$cart = WC()->cart;

		if ( ! $cart || empty( $cart->cart_contents ) ) {
			return;
		}

		$groups  = self::group_cart_items_by_product( $cart );
		$user_id = get_current_user_id();

		foreach ( $groups as $group_id => $group ) {
			$product = $group['product'];

			if ( ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}

			if ( ! self::is_limited_subscription_product( $group_id ) ) {
				continue;
			}

			$plain_count   = count( $group['plain'] );
			$control_count = (int) $group['control_count'];
			$has_conflict  = false;

			// Group-shape semantics:
			// - When at least one control item is present (resubscribe / renewal / switch / initial
			//   payment) and there are NO plain items in the group, never conflict. Switch and
			//   initial-payment flows are actively in progress for the customer's existing
			//   subscription and `WCS_Limiter::is_purchasable_switch` / the order-pay flow
			//   specifically allow them. A solo resubscribe is fine for the same reason: it
			//   inherently replaces the cancelled subscription it was created from.
			// - When a control item AND one or more plain items co-exist for the same limited
			//   product, the plain item is the bypass we are guarding against.
			// - More than one plain item for a limited product is always a conflict.
			// - A single plain item is a conflict only when the customer already has a
			//   subscription that the limit considers "occupied" (delegated to
			//   `wcs_is_product_limited_for_user()`, which honours the active/on-hold/pending-cancel
			//   matrix for `active` limits and the `any`-status matrix for `any` limits, plus the
			//   `woocommerce_subscriptions_product_limited_for_user` filter).
			if ( $control_count >= 1 && $plain_count >= 1 ) {
				$has_conflict = true;
			} elseif ( $plain_count > 1 ) {
				$has_conflict = true;
			} elseif ( 1 === $plain_count && $user_id && wcs_is_product_limited_for_user( $group_id, $user_id ) ) {
				$has_conflict = true;
			}

			if ( ! $has_conflict ) {
				continue;
			}

			$product_for_name = self::get_display_product( $product );

			wc_add_notice(
				sprintf(
					/* translators: %s: product name */
					__( 'Only one of "%s" can be in the cart at a time. Please remove one to continue.', 'woocommerce-subscriptions' ),
					esc_html( $product_for_name->get_name() )
				),
				'error'
			);
		}
	}

	/**
	 * Reconciles the cart when it contains both a control item (resubscribe or renewal) and a
	 * plain item for the same limited subscription product. The plain duplicate is removed and
	 * the control item is preserved. Mirrors the notice/redirect pattern used by
	 * `validate_cart_contents_for_mixed_checkout()` for its mixed-checkout-off branch.
	 *
	 * Switch and initial-payment items are intentionally NOT treated as Layer 2 reconciliation
	 * triggers - those flows are actively in progress and the customer has chosen them
	 * deliberately, so silently mutating their cart would surprise them. Layer 3
	 * (`validate_subscription_limits()`) gives them the same exemption when no plain duplicate
	 * is present.
	 *
	 * @since 8.8.0
	 *
	 * @param WC_Cart $cart The cart loaded from session.
	 */
	private static function reconcile_same_product_duplicates( $cart ) {
		if ( empty( $cart->cart_contents ) ) {
			return;
		}

		$groups      = self::group_cart_items_by_product( $cart );
		$removed_any = false;

		foreach ( $groups as $group_id => $group ) {
			// "Control" for Layer 2 reconciliation purposes is resubscribe + renewal only. Switch
			// and initial-payment flows are deliberately excluded - see method-level docblock.
			$control_keys = array_merge( $group['resubscribe'], $group['renewal'] );

			if ( empty( $control_keys ) || empty( $group['plain'] ) ) {
				continue;
			}

			// Mixed checkout legitimately allows multiple line items for unlimited subscription
			// products. Only reconcile (i.e. remove the plain duplicate) when the product is
			// limited. This mirrors the per-product nature of the limit setting.
			if ( ! self::is_limited_subscription_product( $group_id ) ) {
				continue;
			}

			foreach ( $group['plain'] as $plain_item_key ) {
				$cart->remove_cart_item( $plain_item_key );
				$removed_any = true;
			}

			$product_for_name = self::get_display_product( $group['product'] );
			$product_name     = is_a( $product_for_name, 'WC_Product' ) ? $product_for_name->get_name() : '';

			wc_add_notice(
				sprintf(
					/* translators: %s: product name */
					__( 'A duplicate of your subscription was removed from the cart. Only one of "%s" can be in the cart at a time.', 'woocommerce-subscriptions' ),
					esc_html( $product_name )
				),
				'notice'
			);
		}

		if ( $removed_any ) {
			add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'add_to_cart_ajax_redirect' ) );
		}
	}

	/**
	 * Groups the cart items by canonical product (variation IDs collapse to their parent).
	 *
	 * Returns one entry per logical product in the cart. Each entry carries the cart-item keys
	 * for every classification of cart item we care about:
	 *
	 * - `plain`           - regular add-to-cart items.
	 * - `resubscribe`     - items tagged with `subscription_resubscribe` (created via
	 *                       `WCS_Cart_Resubscribe`).
	 * - `renewal`         - items tagged with `subscription_renewal` (created via
	 *                       `WCS_Cart_Renewal`).
	 * - `switch`          - items tagged with `subscription_switch` (created via
	 *                       `WCS_Cart_Switch`).
	 * - `initial_payment` - items tagged with `subscription_initial_payment` (created via
	 *                       `WCS_Cart_Initial_Payment`).
	 *
	 * The four control-flow buckets (resubscribe, renewal, switch, initial_payment) are kept
	 * separate because Layer 2 (silent reconciliation) and Layer 3 (limit validation) apply
	 * different rules to each:
	 *
	 * - Layer 3 treats all four as control items - a solo control item never conflicts, but a
	 *   control + plain combination always does.
	 * - Layer 2 treats only `resubscribe` and `renewal` as triggers. Switch and initial-payment
	 *   flows are user-initiated, in-progress checkouts; silently removing line items mid-flow
	 *   would surprise the customer.
	 *
	 * `control_count` is the sum of the four control buckets, exposed as a precomputed field to
	 * avoid every caller summing it manually.
	 *
	 * @since 8.8.0
	 *
	 * @param WC_Cart $cart The cart whose `cart_contents` should be grouped.
	 *
	 * @return array<int,array{
	 *     product: WC_Product,
	 *     plain: array<int,string>,
	 *     resubscribe: array<int,string>,
	 *     renewal: array<int,string>,
	 *     switch: array<int,string>,
	 *     initial_payment: array<int,string>,
	 *     control_count: int
	 * }>
	 */
	private static function group_cart_items_by_product( $cart ) {
		$groups = array();

		if ( ! $cart || empty( $cart->cart_contents ) ) {
			return $groups;
		}

		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['data'] ) ) {
				continue;
			}

			$product      = $cart_item['data'];
			$canonical_id = wcs_get_canonical_product_id( $product );

			if ( ! $canonical_id ) {
				continue;
			}

			// Group variations under their parent so a plain variation and a resubscribe/renewal
			// for the parent are treated as the same logical product (and vice versa).
			$group_id = $canonical_id;

			if ( is_a( $product, 'WC_Product' ) && $product->is_type( 'variation' ) && $product->get_parent_id() ) {
				$group_id = $product->get_parent_id();
			}

			if ( ! isset( $groups[ $group_id ] ) ) {
				$groups[ $group_id ] = array(
					'product'         => $product,
					'plain'           => array(),
					'resubscribe'     => array(),
					'renewal'         => array(),
					'switch'          => array(),
					'initial_payment' => array(),
					'control_count'   => 0,
				);
			}

			if ( isset( $cart_item['subscription_resubscribe'] ) ) {
				$groups[ $group_id ]['resubscribe'][] = $cart_item_key;
				++$groups[ $group_id ]['control_count'];
			} elseif ( isset( $cart_item['subscription_renewal'] ) ) {
				$groups[ $group_id ]['renewal'][] = $cart_item_key;
				++$groups[ $group_id ]['control_count'];
			} elseif ( isset( $cart_item['subscription_switch'] ) ) {
				$groups[ $group_id ]['switch'][] = $cart_item_key;
				++$groups[ $group_id ]['control_count'];
			} elseif ( isset( $cart_item['subscription_initial_payment'] ) ) {
				$groups[ $group_id ]['initial_payment'][] = $cart_item_key;
				++$groups[ $group_id ]['control_count'];
			} else {
				$groups[ $group_id ]['plain'][] = $cart_item_key;
			}
		}

		return $groups;
	}

	/**
	 * Resolves the product to use for user-facing display. Variations are not subscriptions in
	 * their own right (`WC_Subscriptions_Product::is_subscription` returns false for them); the
	 * parent variable subscription product carries the limit setting and the customer-facing
	 * name. Fall back to the parent so notices and assertions reference the merchant-defined
	 * product.
	 *
	 * @since 8.8.0
	 *
	 * @param WC_Product|null $product Product taken from a cart item.
	 *
	 * @return WC_Product|null Original product when it is not a variation; the parent product
	 *                         when it is and the parent can be loaded; the original product
	 *                         (variation or null) otherwise.
	 */
	private static function get_display_product( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $product;
		}

		if ( ! $product->is_type( 'variation' ) || ! $product->get_parent_id() ) {
			return $product;
		}

		$parent = wc_get_product( $product->get_parent_id() );

		return $parent ? $parent : $product;
	}

	/**
	 * Returns the cart item keys matching a given product, grouped by item type.
	 *
	 * Variations are matched against their parent product so a plain variation cart item
	 * conflicts with a resubscribe/renewal/switch/initial-payment cart item that uses the
	 * parent ID and vice versa.
	 *
	 * Buckets mirror `group_cart_items_by_product()` so the two helpers classify cart items
	 * consistently. Layer 1's rules (`can_add_product_to_cart()`) read individual control
	 * buckets to surface accurate notice messages; Layer 3's matrix iterates the same
	 * buckets via `control_count`. Keeping the shapes aligned avoids a class of bug where a
	 * switch or initial-payment cart item (placed via Store API, session restoration, or an
	 * extension that bypasses `setup_cart()`) is misclassified as plain and triggers a Layer
	 * 1 rule with a misleading notice ("non-subscription item" when the cart actually holds
	 * a subscription switch).
	 *
	 * @since 8.8.0
	 *
	 * @param int $product_id   The product ID being added.
	 * @param int $variation_id The variation ID being added (0 if not a variation).
	 *
	 * @return array{plain:array<int,string>,resubscribe:array<int,string>,renewal:array<int,string>,switch:array<int,string>,initial_payment:array<int,string>}
	 */
	private static function get_cart_items_for_product( $product_id, $variation_id = 0 ) {
		$grouped = array(
			'plain'           => array(),
			'resubscribe'     => array(),
			'renewal'         => array(),
			'switch'          => array(),
			'initial_payment' => array(),
		);

		if ( empty( WC()->cart ) || empty( WC()->cart->cart_contents ) ) {
			return $grouped;
		}

		$incoming_canonical_id = ! empty( $variation_id ) ? (int) $variation_id : (int) $product_id;
		$incoming_parent_id    = (int) $product_id;

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['data'] ) ) {
				continue;
			}

			$cart_product      = $cart_item['data'];
			$cart_canonical_id = (int) wcs_get_canonical_product_id( $cart_product );
			$cart_parent_id    = is_a( $cart_product, 'WC_Product' ) ? (int) $cart_product->get_parent_id() : 0;

			$matches = false;

			if ( $cart_canonical_id && $cart_canonical_id === $incoming_canonical_id ) {
				$matches = true;
			} elseif ( $incoming_parent_id && $cart_canonical_id === $incoming_parent_id ) {
				// Cart contains the parent product; incoming item is a variation of it.
				$matches = true;
			} elseif ( $cart_parent_id && $cart_parent_id === $incoming_canonical_id ) {
				// Cart contains a variation; incoming item is the parent product.
				$matches = true;
			} elseif ( $cart_parent_id && $incoming_parent_id && $cart_parent_id === $incoming_parent_id ) {
				// Both are variations of the same parent.
				$matches = true;
			}

			if ( ! $matches ) {
				continue;
			}

			if ( isset( $cart_item['subscription_resubscribe'] ) ) {
				$grouped['resubscribe'][] = $cart_item_key;
			} elseif ( isset( $cart_item['subscription_renewal'] ) ) {
				$grouped['renewal'][] = $cart_item_key;
			} elseif ( isset( $cart_item['subscription_switch'] ) ) {
				$grouped['switch'][] = $cart_item_key;
			} elseif ( isset( $cart_item['subscription_initial_payment'] ) ) {
				$grouped['initial_payment'][] = $cart_item_key;
			} else {
				$grouped['plain'][] = $cart_item_key;
			}
		}

		return $grouped;
	}

	/**
	 * Determines whether a product is a limited subscription product.
	 *
	 * For variations, falls back to the parent product when needed - `wcs_get_product_limitation()`
	 * already resolves the limitation off the parent.
	 *
	 * @since 8.8.0
	 *
	 * @param int|WC_Product $product Product ID or product object.
	 *
	 * @return bool True when the product is a subscription with `_subscription_limit !== 'no'`.
	 */
	private static function is_limited_subscription_product( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product ) {
			return false;
		}

		if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			// Variations are not subscriptions themselves; the limit setting lives on the parent
			// variable subscription product. Fall back to the parent so the limit applies to
			// both the parent and any of its variations consistently.
			$parent_id = $product->get_parent_id();
			if ( ! $parent_id || ! WC_Subscriptions_Product::is_subscription( $parent_id ) ) {
				return false;
			}
		}

		return 'no' !== wcs_get_product_limitation( $product );
	}

	/**
	 * Don't allow new subscription products to be added to the cart if it contains a subscription renewal already.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 * @deprecated 3.0.0
	 */
	public static function can_add_subscription_product_to_cart( $can_add, $product_id, $quantity, $variation_id = '', $variations = array(), $item_data = array() ) {
		wcs_deprecated_function( __METHOD__, '6.9.0', 'WC_Subscriptions_Cart_Validator::can_add_product_to_cart' );
		if ( $can_add && ! isset( $item_data['subscription_renewal'] ) && wcs_cart_contains_renewal() && WC_Subscriptions_Product::is_subscription( $product_id ) ) {
			wc_add_notice( __( 'That subscription product can not be added to your cart as it already contains a subscription renewal.', 'woocommerce-subscriptions' ), 'error' );

			$can_add = false;
		}

		return $can_add;
	}
}
