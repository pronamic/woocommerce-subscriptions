<?php
/**
 * Address update handling.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Allow for updating subscription addresses taking into consideration purchaser/recipient subscriptions.
 */
class WCSG_Recipient_Addresses {

	/**
	 * The renewal in the cart whose shipping address is withheld from the current customer, decided once per request:
	 * null until decided, then the subscription or false.
	 *
	 * @var WC_Subscription|false|null
	 */
	private static $withheld_cart_renewal = null;

	/**
	 * The address that renewal ships to, resolved alongside it.
	 *
	 * @var array|null
	 */
	private static $withheld_cart_renewal_address = null;

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {

		add_filter( 'wcs_get_users_subscriptions', __CLASS__ . '::get_users_subscriptions', 100, 2 );

		add_filter( 'woocommerce_form_field_checkbox', __CLASS__ . '::display_update_all_addresses_notice', 1, 2 );

		add_filter( 'woocommerce_subscriptions_can_user_edit_subscription_address', array( __CLASS__, 'maybe_prevent_purchaser_editing_shipping_address' ), 10, 4 );

		// Resolve protection before renewal checkout writes the submitted address onto the subscription at priority 10.
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', __CLASS__ . '::resolve_store_api_checkout_protection', 5, 0 );

		// Before WC_Subscriptions_Addresses::maybe_restrict_edit_address_endpoint() (priority 10) so the purchaser is told why.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_purchaser_from_edit_address_endpoint' ), 9 );

		// The block cart and checkout pages preload these responses through WooCommerce's hydration service, which calls the route handler directly and applies its own filter instead.
		foreach ( array( 'rest_request_after_callbacks', 'woocommerce_hydration_request_after_callbacks' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'maybe_withhold_shipping_address_from_store_api_order' ), 10, 3 );
			add_filter( $hook, array( __CLASS__, 'maybe_withhold_shipping_address_from_store_api_cart' ), 10, 3 );
		}

		add_filter( 'render_block_woocommerce/order-confirmation-shipping-address', array( __CLASS__, 'maybe_withhold_shipping_address_from_order_confirmation' ) );

		foreach ( array( 'woocommerce_cart_loaded_from_session', 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_cart_emptied', 'set_current_user' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget_withheld_cart_renewal' ) );
		}
	}

	/**
	 * Resolves address protection before checkout writes the submitted address onto the subscription.
	 *
	 * @since 9.4.0
	 * @internal
	 */
	public static function resolve_store_api_checkout_protection() {
		self::get_withheld_cart_renewal_subscription();
	}

	/**
	 * The subscription being renewed through the cart, when its shipping address is withheld from the customer at the checkout.
	 *
	 * Decided for the subscription as of now rather than as of the renewal order's creation: whoever pays today must
	 * neither see nor replace the address the recipient owns today. An address with no country is nothing to withhold
	 * at a checkout: there is nothing to disclose and nothing to calculate shipping from, so the checkout behaves as
	 * it does for any gift whose recipient has not supplied an address. The result is kept for the request and
	 * forgotten when the cart or the current user changes.
	 *
	 * @since 9.4.0
	 *
	 * @return WC_Subscription|null
	 */
	public static function get_withheld_cart_renewal_subscription() {
		if ( null !== self::$withheld_cart_renewal ) {
			return self::$withheld_cart_renewal ? self::$withheld_cart_renewal : null;
		}

		$subscription = null;
		$address      = null;

		if ( ! wp_doing_cron() && ( ! is_admin() || wp_doing_ajax() ) && function_exists( 'WC' ) && WC()->cart instanceof WC_Cart ) {
			$item = wcs_cart_contains_renewal();

			if ( $item && ! empty( $item['subscription_renewal']['subscription_id'] ) ) {
				$candidate = wcs_get_subscription( $item['subscription_renewal']['subscription_id'] );

				if ( $candidate && self::is_shipping_address_withheld( $candidate, get_current_user_id() ) ) {
					$address = self::resolve_cart_renewal_shipping_address( $candidate, $item );

					if ( ! empty( $address['country'] ) ) {
						$subscription = $candidate;
					}
				}
			}
		}

		self::$withheld_cart_renewal         = $subscription ? $subscription : false;
		self::$withheld_cart_renewal_address = $subscription ? $address : null;

		return $subscription;
	}

	/**
	 * Forgets the decision made by get_withheld_cart_renewal_subscription(): the cart or the current user changed.
	 */
	public static function forget_withheld_cart_renewal() {
		self::$withheld_cart_renewal         = null;
		self::$withheld_cart_renewal_address = null;
	}

	/**
	 * The address the renewal in the cart ships to, when it is withheld from the customer at the checkout.
	 *
	 * @since 9.4.0
	 *
	 * @return array|null Address fields keyed without the 'shipping_' prefix, or null when nothing is withheld.
	 */
	public static function get_withheld_cart_renewal_shipping_address() {
		return self::get_withheld_cart_renewal_subscription() ? self::$withheld_cart_renewal_address : null;
	}

	/**
	 * The address the renewal in the cart ships to.
	 *
	 * A renewal order being paid keeps the address it was created to ship to, even when that is no address at all,
	 * for as long as the purchaser can read the order: filling it from the subscription would hand them the
	 * recipient's current address through the order. Only an order the purchaser cannot read, one created after the
	 * recipient activated their account, falls back to the subscription's address when it has none. A renewal
	 * created at this checkout, such as a cart-based early renewal, ships to the subscription's address.
	 *
	 * @param WC_Subscription $subscription The subscription being renewed.
	 * @param array           $item         The renewal cart item.
	 * @return array Address fields keyed without the 'shipping_' prefix.
	 */
	private static function resolve_cart_renewal_shipping_address( $subscription, $item ) {
		if ( ! empty( $item['subscription_renewal']['renewal_order_id'] ) ) {
			$order = wc_get_order( $item['subscription_renewal']['renewal_order_id'] );

			if ( $order instanceof WC_Order && ! wcs_is_subscription( $order ) ) {
				return self::resolve_renewal_order_shipping_address( $order, self::get_stored_shipping_address( $order ), $subscription, get_current_user_id() );
			}
		}

		return self::get_stored_shipping_address( $subscription );
	}

	/**
	 * The shipping address as stored on an order or subscription, bypassing the display filters on the address getters
	 * so that what is calculated from and written back is the stored value.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Abstract_Order $order The order or subscription.
	 * @return array Address fields keyed without the 'shipping_' prefix.
	 */
	public static function get_stored_shipping_address( $order ) {
		$address = array();

		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $field ) {
			$address[ $field ] = is_callable( array( $order, "get_shipping_{$field}" ) ) ? (string) $order->{"get_shipping_{$field}"}( 'edit' ) : '';
		}

		return $address;
	}

	/**
	 * The address a renewal order being paid must ship to: its own, or the subscription's when the order has none and
	 * the user cannot read the order anyway. See resolve_cart_renewal_shipping_address().
	 *
	 * @param WC_Order        $order        The renewal order.
	 * @param array           $address      The order's shipping address before the current request changed it.
	 * @param WC_Subscription $subscription The subscription it renews.
	 * @param int             $user_id      The user paying.
	 * @return array Address fields keyed without the 'shipping_' prefix; without a country when nothing is withheld.
	 */
	private static function resolve_renewal_order_shipping_address( $order, $address, $subscription, $user_id ) {
		if ( ! empty( $address['country'] ) || ! self::is_shipping_address_withheld( $order, $user_id ) ) {
			return $address;
		}

		return self::get_stored_shipping_address( $subscription );
	}

	/**
	 * The address a renewal order must ship to when the Store API lets its customer set the order's addresses directly,
	 * or null when the order's shipping address is not withheld from them.
	 *
	 * The order-pay route writes the request's shipping address, or the billing address, onto the order before payment.
	 * For the purchaser of a gift that write is ignored: the order ships where it did before the request, or to the
	 * subscription's address under the same rule as the cart checkout.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Order $order   The order being paid.
	 * @param array    $address The order's shipping address before the request changed it.
	 * @param int      $user_id The user paying.
	 * @return array|null
	 */
	public static function get_withheld_renewal_order_shipping_address( $order, $address, $user_id ) {
		if ( ! $order instanceof WC_Order || wcs_is_subscription( $order ) || ! wcs_order_contains_renewal( $order ) ) {
			return null;
		}

		foreach ( wcs_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
			if ( ! self::is_shipping_address_withheld( $subscription, $user_id ) ) {
				continue;
			}

			$resolved = self::resolve_renewal_order_shipping_address( $order, (array) $address, $subscription, $user_id );

			return empty( $resolved['country'] ) ? null : $resolved;
		}

		return null;
	}

	/**
	 * Whether a gifted subscription's shipping address is withheld from the given user.
	 *
	 * The address on a gifted subscription belongs to the recipient once they have activated their account, or
	 * from the outset when the recipient already had one. Until a newly created recipient activates, the address
	 * is the one the purchaser typed at checkout and stays visible to them.
	 *
	 * For an order, the decision is made as of the order's creation: a renewal order created before the recipient
	 * activated still shows the purchaser the address they supplied. Orders which aren't renewals or resubscribes, the initial
	 * order included, always show their address.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Subscription|WC_Order $order   The gifted subscription, or an order related to it.
	 * @param int                      $user_id The user viewing the address.
	 * @return bool
	 */
	public static function is_shipping_address_withheld( $order, $user_id ) {
		if ( ! $order instanceof WC_Abstract_Order ) {
			return false;
		}

		if ( wcs_is_subscription( $order ) ) {
			$subscriptions = array( $order );
			$as_of         = null;
		} elseif ( wcs_order_contains_renewal( $order ) || wcs_order_contains_resubscribe( $order ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => wcs_order_contains_renewal( $order ) ? 'renewal' : 'parent' ) );
			$subscriptions = $subscriptions ? $subscriptions : wcs_get_subscriptions_for_resubscribe_order( $order );
			$date_created  = $order->get_date_created();
			$as_of         = $date_created ? $date_created->getTimestamp() : time();
		} else {
			return false;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( self::recipient_owns_shipping_address( $subscription, $user_id, $as_of ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the shipping address on a gifted subscription is the recipient's rather than the given user's to see.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @param int             $user_id      The user viewing the address.
	 * @param int|null        $as_of        Unix timestamp to decide as of, or null for now.
	 * @return bool
	 */
	public static function recipient_owns_shipping_address( $subscription, $user_id, $as_of ) {
		if ( ! WCS_Gifting::is_gifted_subscription( $subscription ) ) {
			return false;
		}

		$recipient_user_id = absint( WCS_Gifting::get_recipient_user( $subscription ) );
		$email             = $subscription->get_meta( '_recipient_user_email_address' );
		if ( ! $recipient_user_id && is_string( $email ) && WCS_Related_Order_Store::instance()->get_related_subscription_ids( $subscription, 'resubscribe' ) ) {
			$recipient_user_id = absint( email_exists( $email ) );
		}

		if ( ! $recipient_user_id || absint( $user_id ) === $recipient_user_id ) {
			return false;
		}

		// A recipient whose account was created for this gift has not activated it until they complete the new-recipient form.
		if ( 'true' === get_user_meta( $recipient_user_id, 'wcsg_update_account', true ) && self::recipient_account_was_created_for( $subscription, $recipient_user_id ) ) {
			return false;
		}

		if ( null === $as_of ) {
			return true;
		}

		$activated_at = absint( get_user_meta( $recipient_user_id, 'wcsg_recipient_activated', true ) );

		return ! $activated_at || $as_of >= $activated_at;
	}

	/**
	 * Whether the recipient's account was created for the given subscription, rather than for an earlier gift.
	 *
	 * Gifting creates the account when the order reaches processing, after the subscription exists. A recipient
	 * who was created for an earlier gift and never activated may still have saved an address since (checking out
	 * on the store saves one), and a later gift copies that address onto its subscription. That later purchaser
	 * did not supply the address, so the recipient's unactivated account does not entitle them to see it.
	 *
	 * @param WC_Subscription $subscription      The subscription.
	 * @param int             $recipient_user_id The recipient's user ID.
	 * @return bool
	 */
	private static function recipient_account_was_created_for( $subscription, $recipient_user_id ) {
		$recipient    = get_userdata( $recipient_user_id );
		$date_created = $subscription->get_date_created();

		if ( ! $recipient || empty( $recipient->user_registered ) || ! $date_created ) {
			return false;
		}

		return strtotime( $recipient->user_registered ) >= $date_created->getTimestamp();
	}

	/**
	 * The notice shown in place of a withheld shipping address.
	 *
	 * @since 9.4.0
	 *
	 * @return string
	 */
	public static function get_withheld_shipping_address_message() {
		return __( 'Only the gift recipient can view the shipping address.', 'woocommerce-subscriptions' );
	}

	/**
	 * Stops the purchaser editing the shipping address of a gifted subscription once it is withheld from them.
	 *
	 * @param bool            $can_edit     Whether the user may edit the address.
	 * @param WC_Subscription $subscription The subscription whose address is being edited.
	 * @param int             $user_id      The user editing the address.
	 * @param string          $address_type The address type being edited: 'billing' or 'shipping'.
	 * @return bool
	 */
	public static function maybe_prevent_purchaser_editing_shipping_address( $can_edit, $subscription, $user_id, $address_type ) {
		if ( $can_edit && 'shipping' === $address_type && $subscription instanceof WC_Subscription && self::is_shipping_address_withheld( $subscription, $user_id ) ) {
			return false;
		}

		return $can_edit;
	}

	/**
	 * Sends the purchaser back to the subscription, with an explanation, when they open the change shipping address
	 * form for a gifted subscription whose address is withheld from them.
	 */
	public static function maybe_redirect_purchaser_from_edit_address_endpoint() {
		global $wp;

		if ( ! is_wc_endpoint_url() || 'edit-address' !== WC()->query->get_current_endpoint() || empty( $_GET['subscription'] ) || empty( $wp->query_vars['edit-address'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( 'shipping' !== wc_edit_address_i18n( sanitize_title( $wp->query_vars['edit-address'] ), true ) ) {
			return;
		}

		$subscription = wcs_get_subscription( absint( $_GET['subscription'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id      = get_current_user_id();

		// Only the purchaser is told why. Anyone else is left to the ownership check which follows, so this reveals nothing about the subscription to them.
		if ( ! $subscription || ! $user_id || (int) $subscription->get_user_id() !== $user_id || ! self::is_shipping_address_withheld( $subscription, $user_id ) ) {
			return;
		}

		wc_add_notice( self::get_withheld_shipping_address_message(), 'error' );
		wp_safe_redirect( $subscription->get_view_order_url() );
		exit;
	}

	/**
	 * Blanks the shipping address in the Store API's order response when it is withheld from the user reading it.
	 *
	 * The Store API lets an order's customer read it at /wc/store/v1/order/{id}, and a subscription resolves as an
	 * order there, so the purchaser could otherwise read the recipient's address from the response. The keys stay
	 * in place, empty, so the response keeps its shape; totals and needs_shipping are untouched.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response The response so far.
	 * @param array|callable                                   $handler  The route handler.
	 * @param WP_REST_Request                                  $request  The request.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
	 */
	public static function maybe_withhold_shipping_address_from_store_api_order( $response, $handler, $request ) {
		// WordPress matches routes regardless of case, so the route is matched the same way.
		if ( ! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request || ! preg_match( '#^/wc/store(?:/v\\d+)?/order/\\d+$#i', $request->get_route() ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || empty( $data['id'] ) || empty( $data['shipping_address'] ) ) {
			return $response;
		}

		$order = wc_get_order( absint( $data['id'] ) );

		if ( ! $order || ! self::is_shipping_address_withheld( $order, get_current_user_id() ) ) {
			return $response;
		}

		$data['shipping_address'] = self::blank_address( $data['shipping_address'] );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Blanks the shipping address in the Store API's cart and checkout responses while the cart holds a renewal
	 * whose shipping address is withheld from the customer.
	 *
	 * Shipping and taxes for such a renewal are calculated from the recipient's address (see WCSG_Checkout), so the
	 * cart's shipping address and each shipping package's destination carry it. The checkout response carries the
	 * order's address, which is withheld on the order's own terms as well because the cart is emptied once the order
	 * is placed. While the session has no draft order, as for a cart-based early renewal, the checkout response also
	 * embeds a copy of the cart under __experimentalCart, which the checkout block reads for its address and rates;
	 * it is blanked the same way. So is the refreshed copy of the cart that any of these routes puts into the error
	 * it serialises for a conflict (HTTP 409). The keys stay in place, empty; rates, totals and needs_shipping are
	 * untouched.
	 *
	 * @since 9.4.0
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response The response so far.
	 * @param array|callable                                   $handler  The route handler.
	 * @param WP_REST_Request                                  $request  The request.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
	 */
	public static function maybe_withhold_shipping_address_from_store_api_cart( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route = $request->get_route();

		if ( preg_match( '#^/wc/store(?:/v\\d+)?/cart(?:/|$)#i', $route ) ) {
			$is_checkout_route = false;
		} elseif ( preg_match( '#^/wc/store(?:/v\\d+)?/checkout(?:/|$)#i', $route ) ) {
			$is_checkout_route = true;
		} else {
			return $response;
		}

		if ( ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || ! self::is_store_api_cart_withheld( $data, $is_checkout_route ) ) {
			return $response;
		}

		$data = self::blank_cart_shipping_data( $data );
		$data = self::blank_cart_under( $data, '__experimentalCart' );

		// A conflict error carries a refreshed copy of the cart in its data, and so may each further error it lists.
		if ( isset( $data['data'] ) ) {
			$data['data'] = self::blank_cart_under( $data['data'], 'cart' );
		}

		if ( ! empty( $data['additional_errors'] ) && is_array( $data['additional_errors'] ) ) {
			foreach ( $data['additional_errors'] as $index => $error ) {
				if ( is_array( $error ) && isset( $error['data'] ) ) {
					$data['additional_errors'][ $index ]['data'] = self::blank_cart_under( $error['data'], 'cart' );
				}
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Whether the cart data in a Store API cart or checkout response is withheld from the customer: the cart holds a
	 * renewal whose shipping address is withheld from them, or the checkout response names an order whose shipping
	 * address is.
	 *
	 * @param array $data              The response or error data.
	 * @param bool  $is_checkout_route Whether the request went to the checkout route rather than a cart route.
	 * @return bool
	 */
	private static function is_store_api_cart_withheld( $data, $is_checkout_route ) {
		if ( self::get_withheld_cart_renewal_subscription() ) {
			return true;
		}

		if ( ! $is_checkout_route || empty( $data['order_id'] ) ) {
			return false;
		}

		$order = wc_get_order( absint( $data['order_id'] ) );

		return $order && self::is_shipping_address_withheld( $order, get_current_user_id() );
	}

	/**
	 * Blanks the cart data stored under the given key of a response's data, when there is any.
	 *
	 * @param mixed  $data The data, as an array if it holds anything.
	 * @param string $key  The key the cart is stored under.
	 * @return mixed The data, with the cart under that key blanked.
	 */
	private static function blank_cart_under( $data, $key ) {
		if ( is_array( $data ) && isset( $data[ $key ] ) && ( is_array( $data[ $key ] ) || is_object( $data[ $key ] ) ) ) {
			$data[ $key ] = self::blank_cart_shipping_data( $data[ $key ] );
		}

		return $data;
	}

	/**
	 * Blanks the shipping address and each shipping package's destination in cart data as the Store API serialises
	 * it: the cart response itself, the cart the checkout response embeds under __experimentalCart, or the cart a
	 * serialised conflict error carries.
	 *
	 * @param array|object $cart The cart data.
	 * @return array|object The cart data, in the type it was given.
	 */
	private static function blank_cart_shipping_data( $cart ) {
		$is_object = is_object( $cart );
		$cart      = (array) $cart;

		if ( ! empty( $cart['shipping_address'] ) ) {
			$cart['shipping_address'] = self::blank_address( $cart['shipping_address'] );
		}

		if ( ! empty( $cart['shipping_rates'] ) && is_array( $cart['shipping_rates'] ) ) {
			foreach ( $cart['shipping_rates'] as $index => $package ) {
				if ( is_array( $package ) && ! empty( $package['destination'] ) ) {
					$cart['shipping_rates'][ $index ]['destination'] = self::blank_address( $package['destination'] );
				}
			}
		}

		return $is_object ? (object) $cart : $cart;
	}

	/**
	 * Replaces every field of an address with an empty string, keeping the keys and the object shape.
	 *
	 * @param object|array $address The address as the Store API serialises it.
	 * @return object
	 */
	private static function blank_address( $address ) {
		return (object) array_fill_keys( array_keys( (array) $address ), '' );
	}

	/**
	 * Replaces the shipping address in WooCommerce's Order Confirmation block with the withheld-address message.
	 *
	 * On a block theme the order received page renders the paid order's shipping address through this block rather
	 * than the order details template, so the swap in WCSG_Template_Loader does not reach it.
	 *
	 * @since 9.4.0
	 *
	 * @param string $block_content The rendered block.
	 * @return string
	 */
	public static function maybe_withhold_shipping_address_from_order_confirmation( $block_content ) {
		$order_id = absint( get_query_var( 'order-received' ) );

		if ( ! $order_id || '' === trim( (string) $block_content ) ) {
			return $block_content;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! self::is_shipping_address_withheld( $order, get_current_user_id() ) ) {
			return $block_content;
		}

		$message  = '<p>' . esc_html( self::get_withheld_shipping_address_message() ) . '</p>';
		$replaced = preg_replace( '#^(\s*<div\b[^>]*>).*(</div>\s*)$#s', '$1' . $message . '$2', $block_content, 1, $count );

		return $count ? $replaced : $message;
	}

	/**
	 * Returns the subset of user subscriptions which should be included when updating all subscription addresses.
	 * When setting shipping addresses only include those which the user has purchased for themselves or have been gifted to them.
	 * When setting billing addresses only include subscriptions that belong to the user and those they have gifted to another user.
	 *
	 * @param array $subscriptions Array of subscriptions.
	 * @param int   $user_id       User ID.
	 * @return array
	 */
	public static function get_users_subscriptions( $subscriptions, $user_id ) {
		$address_type = self::get_edit_address_type();

		// The form for a single subscription is shown and saved only when this list is not empty, so it is left whole there.
		if ( '' !== $address_type && is_array( $subscriptions ) && ! isset( $_GET['subscription'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// We dont want to update the shipping address of subscriptions the user isn't the recipient of.
			if ( 'shipping' === $address_type ) {

				foreach ( $subscriptions as $subscription_id => $subscription ) {
					$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );

					if ( ! empty( $recipient_user_id ) && $recipient_user_id != $user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
						unset( $subscriptions[ $subscription_id ] );
					}
				}
			} elseif ( 'billing' === $address_type ) {

				// We dont want to update the billing address of gifted subscriptions for this user.
				foreach ( $subscriptions as $subscription_id => $subscription ) {
					$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );

					if ( ! empty( $recipient_user_id ) && $recipient_user_id == $user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
						unset( $subscriptions[ $subscription_id ] );
					}
				}
			}
		}

		return $subscriptions;
	}

	/**
	 * Appends a notice to the 'update all subscriptions addresses' checkbox notifing the customer that updating all
	 * subscription addresses will not update gifted subscriptions, depending on which address is being updated.
	 *
	 * @param string $field    The generated html element field string.
	 * @param string $field_id The id attribute of the html element being generated.
	 */
	public static function display_update_all_addresses_notice( $field, $field_id ) {

		if ( 'update_all_subscriptions_addresses' === $field_id ) {

			switch ( self::get_edit_address_type() ) {
				case 'shipping':
					// Translators: 1) <strong> opening tag, 2) </strong> closing tag.
					$field = substr_replace( $field, '<small>' . sprintf( esc_html__( '%1$sNote:%2$s This will not update the shipping address of subscriptions you have purchased for others.', 'woocommerce-subscriptions' ), '<strong>', '</strong>' ) . '</small>', strpos( $field, '</p>' ), 0 );
					break;
				case 'billing':
					// Translators: 1) <strong> opening tag, 2) </strong> closing tag.
					$field = substr_replace( $field, '<small>' . sprintf( esc_html__( '%1$sNote:%2$s This will not update the billing address of subscriptions purchased for you by someone else.', 'woocommerce-subscriptions' ), '<strong>', '</strong>' ) . '</small>', strpos( $field, '</p>' ), 0 );
					break;
			}
		}

		return $field;
	}

	/**
	 * The address type of the My Account edit address page being requested.
	 *
	 * The endpoint's value can be a translated slug, so it is mapped back to its canonical form.
	 *
	 * @return string 'billing', 'shipping', or an empty string on any other page.
	 */
	private static function get_edit_address_type() {
		$slug = get_query_var( 'edit-address' );

		if ( ! is_string( $slug ) || '' === $slug ) {
			return '';
		}

		$address_type = wc_edit_address_i18n( sanitize_title( $slug ), true );

		return in_array( $address_type, array( 'billing', 'shipping' ), true ) ? $address_type : '';
	}
}
