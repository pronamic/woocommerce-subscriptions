<?php
/**
 * Checkout integration.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class for checkout integration.
 */
class WCSG_Checkout {

	/**
	 * Whether a Store API cart or checkout request is being served.
	 *
	 * @var bool
	 */
	private static $is_store_api_request = false;

	/**
	 * Whether this request submits the cart checkout or an order for payment.
	 *
	 * @var bool
	 */
	private static $is_store_api_checkout_submission = false;

	/**
	 * Whether a withheld shipping address has been put on the session customer during this request.
	 *
	 * @var bool
	 */
	private static $applied_withheld_address = false;

	/**
	 * The session customer the withheld address was applied to, and the shipping address it carried before that.
	 *
	 * @var WC_Customer|null
	 */
	private static $applied_customer = null;

	/**
	 * @var array|null
	 */
	private static $shipping_before_apply = null;

	/**
	 * Whether the checkout has created its order during this request.
	 *
	 * @var bool
	 */
	private static $order_created = false;

	/**
	 * The shipping address each order being paid through the Store API carried when the request began, by order ID.
	 *
	 * @var array
	 */
	private static $order_pay_shipping_before = array();

	/**
	 * The items of the cart being checked out, and the ID of its order.
	 *
	 * WC_Subscriptions_Checkout::process_checkout() puts each recurring cart in WC()->cart while it creates the
	 * subscriptions, so subscription_created() finds bundle and composite containers here.
	 *
	 * @var array|null
	 */
	private static $checkout_cart = null;

	/**
	 * Items in the recurring group currently being calculated, or null to use the current cart.
	 *
	 * @var array|null
	 */
	private static $tax_calculation_cart_contents = null;

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'woocommerce_customer_taxable_address', __CLASS__ . '::maybe_use_resubscribe_tax_location', 10, 2 );
		add_filter( 'woocommerce_order_get_tax_location', __CLASS__ . '::maybe_use_resubscribe_tax_location', 10, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', __CLASS__ . '::maybe_use_withheld_package_destinations', 100 );
		add_action( 'woocommerce_after_calculate_totals', __CLASS__ . '::maybe_restore_checkout_tax_context_after_totals', PHP_INT_MAX );
		add_action( 'woocommerce_checkout_subscription_created', __CLASS__ . '::maybe_ship_subscription_to_withheld_address', 11, 3 );
		add_action( 'woocommerce_checkout_order_processed', __CLASS__ . '::maybe_ship_classic_checkout_order_to_withheld_address', 101, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', __CLASS__ . '::maybe_ship_order_to_withheld_address', 101 );
		add_filter( 'woocommerce_checkout_cart_item_quantity', __CLASS__ . '::add_gifting_option_checkout', 1, 3 );

		add_action( 'woocommerce_checkout_subscription_created', __CLASS__ . '::subscription_created', 1, 3 );

		// Before WC_Subscriptions_Checkout::process_checkout() (priority 100) replaces WC()->cart.
		add_action( 'woocommerce_checkout_order_processed', __CLASS__ . '::remember_checkout_cart', 99, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', __CLASS__ . '::remember_checkout_cart', 99, 1 );

		add_filter( 'woocommerce_subscriptions_recurring_cart_key', __CLASS__ . '::add_recipient_email_recurring_cart_key', 1, 2 );

		add_action( 'woocommerce_checkout_process', __CLASS__ . '::update_cart_before_checkout' );

		add_filter( 'woocommerce_ship_to_different_address_checked', __CLASS__ . '::maybe_ship_to_recipient', 100, 1 );

		add_filter( 'woocommerce_checkout_get_value', __CLASS__ . '::maybe_get_recipient_shipping', 10, 2 );

		add_action( 'woocommerce_checkout_update_order_review', __CLASS__ . '::store_recipients_in_session', 10, 1 );

		add_action( 'woocommerce_before_checkout_shipping_form', __CLASS__ . '::maybe_display_recipient_shipping_notice', 10 );

		add_filter( 'woocommerce_get_item_data', __CLASS__ . '::woocommerce_get_item_data', 10, 2 );

		// A renewal whose shipping address is withheld from the paying customer: no shipping fields, totals from the recipient's address, nothing of it kept.
		add_filter( 'rest_request_before_callbacks', __CLASS__ . '::note_store_api_request', 10, 3 );
		add_filter( 'rest_dispatch_request', __CLASS__ . '::note_store_api_order_pay_request', 10, 2 );
		add_filter( 'rest_request_after_callbacks', __CLASS__ . '::maybe_restore_store_api_orders', 15 );
		add_filter( 'rest_request_after_callbacks', __CLASS__ . '::forget_store_api_request', 20 );
		// Checkout preloading calls the controller without the REST request hooks.
		add_filter( 'woocommerce_hydration_dispatch_request', __CLASS__ . '::note_store_api_hydration_request', 10, 2 );
		add_filter( 'woocommerce_hydration_request_after_callbacks', __CLASS__ . '::forget_store_api_request', 20 );
		add_action( 'woocommerce_checkout_order_created', __CLASS__ . '::note_order_created' );
		add_action( 'woocommerce_store_api_checkout_order_processed', __CLASS__ . '::note_order_created' );
		add_action( 'woocommerce_store_api_checkout_order_processed', __CLASS__ . '::maybe_ship_store_api_order_to_withheld_address', 5 );
		add_filter( 'pre_option_woocommerce_ship_to_destination', __CLASS__ . '::maybe_hide_shipping_fields_for_withheld_address' );
		add_action( 'woocommerce_checkout_update_order_review', __CLASS__ . '::maybe_remember_session_shipping_address' );
		add_action( 'woocommerce_checkout_process', __CLASS__ . '::maybe_remember_session_shipping_address' );
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::maybe_calculate_totals_from_withheld_address' );
		add_action( 'woocommerce_before_customer_object_save', __CLASS__ . '::maybe_discard_shipping_address_changes' );
		add_action( 'woocommerce_after_customer_object_save', __CLASS__ . '::maybe_reapply_withheld_address', PHP_INT_MAX );
		add_action( 'woocommerce_before_order_object_save', __CLASS__ . '::maybe_keep_blank_renewal_shipping_address' );
		add_action( 'woocommerce_before_order_object_save', __CLASS__ . '::maybe_keep_store_api_resubscribe_destination' );
		add_filter( 'woocommerce_checkout_posted_data', __CLASS__ . '::maybe_remove_posted_shipping_fields' );
		add_action( 'woocommerce_checkout_create_order', __CLASS__ . '::maybe_ship_order_to_withheld_address', 100 );
		add_filter( 'woocommerce_shipping_packages', __CLASS__ . '::maybe_withhold_package_destinations' );
		add_filter( 'woocommerce_shipping_show_shipping_calculator', __CLASS__ . '::maybe_hide_shipping_calculator' );
	}

	/**
	 * Uses the withheld resubscribe destination for customer and current Store API draft tax calculations.
	 *
	 * @since 9.4.0
	 *
	 * @param array                     $location The tax location.
	 * @param WC_Customer|WC_Order|null $subject  The customer or order being calculated, if supplied.
	 * @return array
	 */
	public static function maybe_use_resubscribe_tax_location( $location, $subject = null ) {
		if ( null === $subject || ! is_array( $location ) || ! wcs_cart_contains_resubscribe() ) {
			return $location;
		}

		if ( ! WC()->customer instanceof WC_Customer || ! WC()->session ) {
			return $location;
		}

		// Cloned customers use normal tax rules without another override.
		if ( WC()->customer !== $subject ) {
			if ( ! $subject instanceof WC_Order || 'store-api' !== $subject->get_created_via() ) {
				return $location;
			}

			if ( $subject->get_customer_id() !== get_current_user_id() ) {
				return $location;
			}

			$draft_order_id = absint( WC()->session->get( 'store_api_draft_order' ) );
			if ( $subject->get_id() && $subject->get_id() !== $draft_order_id ) {
				return $location;
			}
		}

		$contents = $subject instanceof WC_Order ? null : self::$tax_calculation_cart_contents;
		$source   = self::get_withheld_subscription_at_checkout( $contents );
		if ( ! $source ) {
			return $location;
		}

		$customer = clone WC()->customer;
		foreach ( array( 'country', 'state', 'postcode', 'city' ) as $field ) {
			$customer->{"set_shipping_{$field}"}( $source->{"get_shipping_{$field}"}( 'edit' ) );
		}

		$address = $customer->get_taxable_address();
		if ( ! is_array( $address ) || 4 !== count( $address ) ) {
			$address = array_values( $location );
		}

		if ( $subject instanceof WC_Order ) {
			return array_combine( array( 'country', 'state', 'postcode', 'city' ), $address );
		}

		return $address;
	}

	/**
	 * Adds gifting ui elements to the checkout page. Also updates recipient information
	 * stored on the cart item from session data if it exists.
	 *
	 * @param int    $quantity      Quantity.
	 * @param object $cart_item     The Cart_Item for which we are adding ui elements.
	 * @param string $cart_item_key Cart item key.
	 * @return int The quantity of the cart item with ui elements appended on.
	 */
	public static function add_gifting_option_checkout( $quantity, $cart_item, $cart_item_key ) {
		return $quantity . WCSG_Cart::maybe_display_gifting_information( $cart_item, $cart_item_key );
	}

	/**
	 * Attaches the recipient email address to a subscription when it is purchased via checkout.
	 *
	 * @param WC_Subscription $subscription   The subscription that has just been created.
	 * @param WC_Order        $order          Order object.
	 * @param WC_Cart         $recurring_cart An array of subscription products that make up the subscription.
	 */
	public static function subscription_created( $subscription, $order, $recurring_cart ) {
		$recipient     = '';
		$cart_contents = self::get_checkout_cart_contents( $order );

		// Every item in a recurring cart carries the same recipient, as it forms part of the recurring cart key. The
		// subscription is a gift only if each of those items may carry it.
		foreach ( $recurring_cart->cart_contents as $cart_item ) {
			if ( empty( $cart_item['wcsg_gift_recipients_email'] ) ) {
				continue;
			}

			if ( ! WCSG_Cart::has_allowed_recipient( $cart_item, $cart_contents ) ) {
				wc_get_logger()->warning(
					sprintf(
						'Subscription #%1$d was not set up as a gift because it contains an item that cannot be gifted. The gift recipient entered in the cart was discarded.',
						$subscription->get_id()
					),
					array( 'source' => 'woocommerce-subscriptions-gifting' )
				);
				$subscription->add_order_note( __( 'This subscription was not set up as a gift because it contains an item that cannot be gifted. The gift recipient entered in the cart was discarded.', 'woocommerce-subscriptions' ) );
				return;
			}

			$recipient = $cart_item['wcsg_gift_recipients_email'];
		}

		if ( '' !== $recipient ) {
			$subscription->update_meta_data( '_recipient_user_email_address', $recipient );
			$subscription->save();
		}
	}

	/**
	 * Keeps the items of the cart being checked out, before subscriptions are created from its recurring carts.
	 *
	 * A bundle or composite child can be in a recurring cart without its container, so subscription_created() needs
	 * the complete cart to decide whether the child may carry the container's recipient.
	 *
	 * @since 9.4.0
	 *
	 * @param int|WC_Order $order The order, or its ID.
	 */
	public static function remember_checkout_cart( $order ) {
		self::$tax_calculation_cart_contents = null;

		$order_id = $order instanceof WC_Order ? $order->get_id() : ( is_numeric( $order ) ? absint( $order ) : 0 );

		self::$checkout_cart = $order_id && isset( WC()->cart ) ? array(
			'order_id' => $order_id,
			'contents' => WC()->cart->cart_contents,
		) : null;
	}

	/**
	 * Returns the items of the cart the order was checked out from, if remember_checkout_cart() kept them.
	 *
	 * @param WC_Order $order The order.
	 * @return array|false The cart items, or false to use the items of WC()->cart.
	 */
	private static function get_checkout_cart_contents( $order ) {
		if ( ! isset( self::$checkout_cart['order_id'] ) || ! $order instanceof WC_Order || self::$checkout_cart['order_id'] !== $order->get_id() ) {
			return false;
		}

		return self::$checkout_cart['contents'];
	}

	/**
	 * Attaches the recipient email to a recurring cart key to differentiate subscription products
	 * gifted to different recipients.
	 *
	 * @param string $cart_key  Cart key.
	 * @param object $cart_item Cart item.
	 * @return string The cart_key with a recipient's email appended
	 */
	public static function add_recipient_email_recurring_cart_key( $cart_key, $cart_item ) {
		if ( ! empty( $cart_item['wcsg_gift_recipients_email'] ) ) {
			$cart_key .= '_' . $cart_item['wcsg_gift_recipients_email'];
		}
		return $cart_key;

	}

	/**
	 * Updates the cart items for changes made to recipient infomation on the checkout page.
	 * This needs to occur right before WooCommerce processes the cart.
	 * If an error occurs schedule a checkout reload so the user can see the emails causing the errors.
	 */
	public static function update_cart_before_checkout() {
		if ( ! empty( $_POST['recipient_email'] ) ) {
			if ( ! empty( $_POST['_wcsgnonce'] ) && wp_verify_nonce( $_POST['_wcsgnonce'], 'wcsg_add_recipient' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				$recipients = $_POST['recipient_email']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				if ( ! WCS_Gifting::validate_recipient_emails( $recipients ) ) {
					WC()->session->set( 'reload_checkout', true );
				}
				foreach ( WC()->cart->cart_contents as $key => $item ) {
					if ( isset( $_POST['recipient_email'][ $key ] ) ) {
						$recipient_email = $_POST['recipient_email'][ $key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

						// Clearing a recipient is always allowed; assigning one has to satisfy the store's giftability rules.
						if ( '' !== $recipient_email && ! WCSG_Cart::is_giftable_item( $item ) ) {
							wc_add_notice( __( 'This item cannot be gifted.', 'woocommerce-subscriptions' ), 'error' );
							continue;
						}

						WCS_Gifting::update_cart_item_recipient( $item, $key, $recipient_email );

						// Propagate recipient to bundle/composite child items.
						WCSG_Cart::propagate_recipient_to_children( $key, $recipient_email );
					}
				}
			} else {
				wc_add_notice( __( 'There was an error with your request. Please try again.', 'woocommerce-subscriptions' ), 'error' );
			}
		}
	}

	/**
	 * If the cart contains a gifted subscription renewal or a gift recipient, tell the checkout to ship to a different address.
	 *
	 * The section is hidden altogether while the renewal's address is withheld from the customer at this checkout. When
	 * it is not, a gifted renewal still opens the section, so a recipient's missing address is entered deliberately
	 * rather than copied from the billing address.
	 *
	 * @param bool $ship_to_different_address Whether the order will ship to a different address.
	 * @return bool
	 */
	public static function maybe_ship_to_recipient( $ship_to_different_address ) {

		if ( WCSG_Admin::is_gifting_enabled() && ! $ship_to_different_address && ( WCSG_Cart::contains_gifted_renewal() || WCSG_Cart::contains_gift_recipient_email() ) && ! WCSG_Recipient_Addresses::get_withheld_cart_renewal_subscription() ) {
			$ship_to_different_address = true;
		}
		return $ship_to_different_address;
	}

	/**
	 * Whether the shipping address of the gifted subscription being renewed through the cart is withheld from the
	 * current customer, whatever address the subscription itself carries.
	 *
	 * @return bool
	 */
	private static function renewal_shipping_address_is_withheld() {
		$item         = wcs_cart_contains_renewal();
		$subscription = $item && ! empty( $item['subscription_renewal']['subscription_id'] ) ? wcs_get_subscription( $item['subscription_renewal']['subscription_id'] ) : false;

		return $subscription && WCSG_Recipient_Addresses::is_shipping_address_withheld( $subscription, get_current_user_id() );
	}

	/**
	 * Returns recipient's shipping address if the checkout is requesting
	 * the shipping fields for a gifted subscription renewal.
	 *
	 * The purchaser gets nothing once the address is withheld from them, whatever the subscription itself carries.
	 *
	 * @param string $value Default checkout field value.
	 * @param string $key The checkout form field name/key.
	 */
	public static function maybe_get_recipient_shipping( $value, $key ) {
		$shipping_fields = WC()->countries->get_address_fields( '', 'shipping_' );

		if ( array_key_exists( $key, $shipping_fields ) && WCSG_Cart::contains_gifted_renewal() && ! self::renewal_shipping_address_is_withheld() ) {
			$item              = wcs_cart_contains_renewal();
			$subscription      = wcs_get_subscription( $item['subscription_renewal']['subscription_id'] );
			$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );
			$value             = get_user_meta( $recipient_user_id, $key, true );
		}

		return $value;
	}

	/**
	 * Notes that a Store API cart or checkout request is being served, so the checkout behaviour below applies to it.
	 *
	 * @since 9.4.0
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response The response so far.
	 * @param array|callable                                   $handler  The route handler.
	 * @param WP_REST_Request                                  $request  The request.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
	 */
	public static function note_store_api_request( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		// WordPress matches routes regardless of case, so the route is matched the same way.
		if ( preg_match( '#^/wc/store(?:/v\\d+)?/(?:cart|checkout|batch)(?:/|$)#i', $request->get_route() ) ) {
			self::$is_store_api_request             = true;
			self::$is_store_api_checkout_submission = 'POST' === $request->get_method() && preg_match( '#^/wc/store(?:/v\\d+)?/checkout(?:/\\d+)?/?$#i', $request->get_route() );
		}

		// The order-pay route accepts a subscription as the "order" to pay and writes the request's addresses onto it.
		// A gifted subscription whose shipping address is withheld from its purchaser is refused to them before the route runs.
		// Anyone else gets the route's own answer, so the refusal reveals nothing about which subscriptions are gifts.
		if ( preg_match( '#^/wc/store(?:/v\\d+)?/checkout/(\\d+)$#i', $request->get_route(), $matches ) ) {
			$subscription = wcs_get_subscription( absint( $request['id'] ? $request['id'] : $matches[1] ) );
			$user_id      = get_current_user_id();

			if ( $subscription && $user_id && (int) $subscription->get_customer_id() === $user_id && WCSG_Recipient_Addresses::is_shipping_address_withheld( $subscription, $user_id ) ) {
				return new WP_Error(
					'woocommerce_subscriptions_rest_cannot_pay_subscription',
					__( 'This subscription cannot be paid here. Use the payment link for its renewal order instead.', 'woocommerce-subscriptions' ),
					array( 'status' => 403 )
				);
			}
		}

		return $response;
	}

	/**
	 * Applies Store API request handling to checkout preloading, which uses a different hook argument order.
	 *
	 * @since 9.4.0
	 * @internal
	 *
	 * @param mixed           $response The response so far.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public static function note_store_api_hydration_request( $response, $request ) {
		return self::note_store_api_request( $response, array(), $request );
	}

	/**
	 * Remembers where an order about to be paid through the Store API's order-pay route ships, before the route
	 * rewrites its addresses from the request.
	 *
	 * This runs once the request has passed the route's permission and parameter checks, so only a request the
	 * route will act on is remembered. The route pays the order named by the request's 'id' parameter, which a
	 * query or body value takes over from the path, so that is the order remembered.
	 *
	 * @since 9.4.0
	 *
	 * @param mixed           $dispatch_result The dispatch result so far; null to let the route handle the request.
	 * @param WP_REST_Request $request         The request.
	 * @return mixed
	 */
	public static function note_store_api_order_pay_request( $dispatch_result, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! preg_match( '#^/wc/store(?:/v\\d+)?/checkout/(\\d+)$#i', $request->get_route(), $matches ) ) {
			return $dispatch_result;
		}

		$order = wc_get_order( absint( $request['id'] ? $request['id'] : $matches[1] ) );

		if ( $order instanceof WC_Order && ! wcs_is_subscription( $order ) ) {
			self::$order_pay_shipping_before[ $order->get_id() ] = WCSG_Recipient_Addresses::get_stored_shipping_address( $order );
		}

		return $dispatch_result;
	}

	/**
	 * Ships an order paid through the Store API to the withheld address, whatever address the request carried.
	 *
	 * The order-pay route sets the order's shipping address from the request, or from its billing address, before
	 * payment; the cart checkout copies it from the session customer. Both fire this hook before payment, so the
	 * order and its totals are put right here for the purchaser of a gift. For an order paid through the order-pay
	 * route the address it carried when the request began is the reference; for the cart checkout it is the address
	 * the renewal in the cart ships to, which for an order already carrying one is that order's own.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Order $order The order about to be paid.
	 */
	public static function maybe_ship_store_api_order_to_withheld_address( $order ) {
		if ( ! $order instanceof WC_Order || wcs_is_subscription( $order ) ) {
			return;
		}

		$current = WCSG_Recipient_Addresses::get_stored_shipping_address( $order );

		if ( isset( self::$order_pay_shipping_before[ $order->get_id() ] ) ) {
			$address = wcs_order_contains_resubscribe( $order ) && WCSG_Recipient_Addresses::is_shipping_address_withheld( $order, get_current_user_id() )
				? self::$order_pay_shipping_before[ $order->get_id() ]
				: WCSG_Recipient_Addresses::get_withheld_renewal_order_shipping_address( $order, self::$order_pay_shipping_before[ $order->get_id() ], get_current_user_id() );
		} else {
			$address = WCSG_Recipient_Addresses::get_withheld_cart_renewal_shipping_address();

			// Not reached through the cart either: hold the order to its own address, or the subscription's when it has none.
			if ( ! $address ) {
				$address = WCSG_Recipient_Addresses::get_withheld_renewal_order_shipping_address( $order, $current, get_current_user_id() );
			}
		}

		if ( ! $address ) {
			return;
		}

		$current = array_map( 'strval', $current );

		if ( array_diff_assoc( array_map( 'strval', $address ), $current ) ) {
			$order->set_address( $address, 'shipping' );
			$order->calculate_totals();
		}
	}

	/**
	 * Puts every order the order-pay route touched during this request back where it ships, whatever the outcome.
	 *
	 * The route saves the request's addresses on the order before it validates the rest of the request, so a request
	 * refused later, for a missing payment method say, still leaves its shipping address on the order. The order is
	 * put right once the dispatch ends; the successful path has already done so before payment.
	 *
	 * @since 9.4.0
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response The response so far.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
	 */
	public static function maybe_restore_store_api_orders( $response ) {
		foreach ( self::$order_pay_shipping_before as $order_id => $before ) {
			$order = wc_get_order( $order_id );

			// Only an order the route could pay (the same check it makes), whose stored address it changed, needs putting back.
			if ( $order instanceof WC_Order && $order->needs_payment() && WCSG_Recipient_Addresses::get_stored_shipping_address( $order ) !== $before ) {
				self::maybe_ship_store_api_order_to_withheld_address( $order );
			}
		}

		return $response;
	}

	/**
	 * The Store API request has been answered. What the request applied to the session customer is still reverted when
	 * WooCommerce saves it on shutdown; nothing else of the checkout behaviour is needed after this point.
	 *
	 * @since 9.4.0
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response The response so far.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
	 */
	public static function forget_store_api_request( $response ) {
		self::$is_store_api_request             = false;
		self::$is_store_api_checkout_submission = false;
		self::$checkout_cart                    = null;
		self::$tax_calculation_cart_contents    = null;
		self::$order_pay_shipping_before        = array();

		return $response;
	}

	/**
	 * Notes that the checkout has created its order, so the store's own shipping destination setting applies again
	 * to whatever the rest of the request renders, such as the order emails.
	 *
	 * @since 9.4.0
	 */
	public static function note_order_created() {
		self::$order_created = true;
	}

	/**
	 * Whether this request renders or processes the customer's cart or checkout: the cart and checkout pages, their
	 * AJAX calls, and Store API cart and checkout requests.
	 *
	 * The behaviour below changes how the store treats the customer while the renewal is in the cart. The cart stays
	 * in the session until it is paid or emptied, so the behaviour is limited to these requests and the rest of the
	 * customer's session, such as My Account and the emails rendered elsewhere, is untouched.
	 *
	 * @return bool
	 */
	private static function is_cart_or_checkout_request() {
		return self::$is_store_api_request || is_checkout() || is_cart();
	}

	/**
	 * The resubscribe source or renewal in the cart whose shipping address is withheld during cart or checkout requests.
	 *
	 * @param array|null $contents Cart items to inspect, or null for the current cart.
	 * @return WC_Subscription|null
	 */
	private static function get_withheld_subscription_at_checkout( $contents = null ) {
		if ( ! self::is_cart_or_checkout_request() || ! WC()->cart instanceof WC_Cart ) {
			return null;
		}

		$source = self::get_withheld_resubscribe_source( $contents );
		return $source ? $source : WCSG_Recipient_Addresses::get_withheld_cart_renewal_subscription();
	}

	/**
	 * Finds the private source for an unchanged gift recipient in the supplied cart items.
	 *
	 * @param array|null $contents Cart items to inspect, or null for the current cart.
	 * @return WC_Subscription|null
	 */
	private static function get_withheld_resubscribe_source( $contents = null ) {
		$contents      = null === $contents ? WC()->cart->cart_contents : (array) $contents;
		$full_contents = self::$checkout_cart['contents'] ?? WC()->cart->cart_contents;

		foreach ( $contents as $item ) {
			$source_id = is_array( $item ) ? ( $item['subscription_resubscribe']['subscription_id'] ?? null ) : null;
			$source    = is_numeric( $source_id ) ? wcs_get_subscription( $source_id ) : false;

			if ( ! $source || $source->get_customer_id() !== get_current_user_id() ) {
				continue;
			}

			$recipient_email = $item['wcsg_gift_recipients_email'] ?? null;
			if ( ! is_string( $recipient_email ) || (int) email_exists( $recipient_email ) !== absint( WCS_Gifting::get_recipient_user( $source ) ) ) {
				continue;
			}

			if ( ! WCSG_Cart::has_allowed_recipient( $item, $full_contents ) || ! $source->get_shipping_country( 'edit' ) ) {
				continue;
			}

			if ( WCSG_Recipient_Addresses::is_shipping_address_withheld( $source, get_current_user_id() ) ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * Hides the shipping fields from a customer the renewal's shipping address is withheld from.
	 *
	 * Treating the store as shipping to the billing address only is the one switch both checkouts honour: the classic
	 * checkout drops the shipping section and posts no shipping fields, and the checkout block hides its shipping form
	 * while the cart still needs shipping. It takes effect on the cart and checkout requests while such a renewal is
	 * in the cart, and ends once the checkout has created its order so the order emails keep their shipping address.
	 *
	 * @since 9.4.0
	 *
	 * @param mixed $pre The value to short-circuit get_option() with, false to read the option.
	 * @return mixed
	 */
	public static function maybe_hide_shipping_fields_for_withheld_address( $pre ) {
		return ( ! self::$order_created && self::get_withheld_subscription_at_checkout() ) ? 'billing_only' : $pre;
	}

	/**
	 * Prepares the recurring tax context and applies a withheld renewal address before totals.
	 *
	 * Renewals use the cart customer's address in memory, with saves restored by
	 * maybe_discard_shipping_address_changes(). Resubscribes use the recurring context in the tax and package filters.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Cart $cart The cart being calculated.
	 */
	public static function maybe_calculate_totals_from_withheld_address( $cart ) {
		self::$tax_calculation_cart_contents = $cart instanceof WC_Cart ? $cart->cart_contents : null;
		self::maybe_forget_pre_activation_checkout_orders();
		self::maybe_apply_withheld_address( $cart instanceof WC_Cart ? $cart->get_customer() : null );
	}

	/**
	 * Ends the recurring tax context and restores checkout-wide customer handling after totals.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Cart $cart The cart whose totals were calculated.
	 */
	public static function maybe_restore_checkout_tax_context_after_totals( $cart ) {
		self::$tax_calculation_cart_contents = null;
		self::maybe_forget_pre_activation_checkout_orders();
		self::maybe_apply_withheld_address( $cart instanceof WC_Cart ? $cart->get_customer() : null );
	}

	/**
	 * Stops checkout reusing an order created before its recipient address became private.
	 */
	private static function maybe_forget_pre_activation_checkout_orders() {
		if ( ! WC()->session || ! WC()->cart instanceof WC_Cart || ! wcs_cart_contains_resubscribe() ) {
			return;
		}

		$source = self::get_withheld_subscription_at_checkout();
		if ( ! $source ) {
			return;
		}

		foreach ( array( 'store_api_draft_order', 'order_awaiting_payment' ) as $key ) {
			$order = wc_get_order( WC()->session->get( $key ) );
			if ( ! $order || ! $order->get_date_created() ) {
				continue;
			}

			$created_at = $order->get_date_created()->getTimestamp();
			if ( ! WCSG_Recipient_Addresses::recipient_owns_shipping_address( $source, get_current_user_id(), $created_at ) ) {
				WC()->session->set( $key, null );
			}
		}
	}

	/**
	 * Puts the withheld address back on the session customer once it has been saved, so the shipping calculation the
	 * classic checkout runs right after saving the posted address prices the recipient's address too.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Customer $customer The customer just saved.
	 */
	public static function maybe_reapply_withheld_address( $customer ) {
		if ( $customer instanceof WC_Customer && WC()->customer === $customer ) {
			self::maybe_apply_withheld_address( $customer );
		}
	}

	/**
	 * Sets the withheld address on the given customer, in memory, when this request is the cart or checkout for it.
	 *
	 * @param WC_Customer|null $customer The session customer.
	 */
	private static function maybe_apply_withheld_address( $customer ) {
		$address = self::get_withheld_subscription_at_checkout() ? WCSG_Recipient_Addresses::get_withheld_cart_renewal_shipping_address() : null;

		if ( ! $address || ! $customer instanceof WC_Customer ) {
			return;
		}

		self::remember_shipping_address( $customer );

		foreach ( $address as $field => $value ) {
			if ( is_callable( array( $customer, "set_shipping_{$field}" ) ) ) {
				$customer->{"set_shipping_{$field}"}( $value );
			}
		}

		self::$applied_withheld_address = true;
	}

	/**
	 * Remembers the session customer's shipping address before the classic checkout copies the posted address onto it.
	 *
	 * The order-review and place-order requests set the posted billing address as the shipping address, since the
	 * store ships to the billing address only for this cart, and save it before any totals calculation. Remembering
	 * the address here means the save puts back what the customer had before the request, not that copy.
	 *
	 * @since 9.4.0
	 */
	public static function maybe_remember_session_shipping_address() {
		if ( WC()->customer instanceof WC_Customer && self::get_withheld_subscription_at_checkout() ) {
			self::remember_shipping_address( WC()->customer );
		}
	}

	/**
	 * Keeps a draft refresh from assigning the purchaser's profile to a missing recipient destination.
	 *
	 * Use the cached protection decision: another save can fill a previously blank destination in this request.
	 * Keep the stored destination visible to this request before saving.
	 * A checkout submission keeps its entered fallback address, including when payment needs another attempt.
	 *
	 * @since 9.4.0
	 * @internal
	 *
	 * @param WC_Order $order The order being saved.
	 */
	public static function maybe_keep_blank_renewal_shipping_address( $order ) {
		if ( ! self::$is_store_api_request || self::$is_store_api_checkout_submission || ! $order instanceof WC_Order || wcs_is_subscription( $order ) ) {
			return;
		}

		$item     = wcs_cart_contains_renewal();
		$order_id = ! empty( $item['subscription_renewal']['renewal_order_id'] ) ? absint( $item['subscription_renewal']['renewal_order_id'] ) : 0;

		if ( ! $order_id || $order->get_id() !== $order_id || ! self::renewal_shipping_address_is_withheld() || WCSG_Recipient_Addresses::get_withheld_cart_renewal_subscription() ) {
			return;
		}

		$stored = wc_get_order( $order->get_id() );

		if ( $stored instanceof WC_Order ) {
			$order->set_shipping_address( WCSG_Recipient_Addresses::get_stored_shipping_address( $stored ) );
		}
	}

	/**
	 * Keeps checkout hydration at the private resubscribe destination used by its calculations.
	 *
	 * @since 9.4.0
	 * @internal
	 * @param WC_Order $order The checkout order about to be saved.
	 */
	public static function maybe_keep_store_api_resubscribe_destination( $order ) {
		if ( ! self::$is_store_api_request || self::$is_store_api_checkout_submission || ! $order instanceof WC_Order || wcs_is_subscription( $order ) || ! WC()->session || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		if ( $order->get_id() !== absint( WC()->session->get( 'store_api_draft_order' ) ) || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return;
		}

		$source = self::get_withheld_resubscribe_source();
		$stored = $source ? wc_get_order( $order->get_id() ) : false;
		if ( ! $stored || $stored->get_customer_id() !== get_current_user_id() || ! WCSG_Recipient_Addresses::is_shipping_address_withheld( $stored, get_current_user_id() ) ) {
			return;
		}

		$source_ids = WCS_Related_Order_Store::instance()->get_related_subscription_ids( $stored, 'resubscribe' );
		if ( in_array( $source->get_id(), (array) $source_ids, true ) ) {
			$order->set_address( WCSG_Recipient_Addresses::get_stored_shipping_address( $source ), 'shipping' );
		}
	}

	/**
	 * Remembers what the customer carries, defaults included, so a save can put it back exactly.
	 *
	 * @param WC_Customer $customer The session customer.
	 */
	private static function remember_shipping_address( $customer ) {
		if ( self::$applied_customer !== $customer ) {
			self::$applied_customer      = $customer;
			self::$shipping_before_apply = $customer->get_shipping( 'edit' );
		}
	}

	/**
	 * Keeps a withheld shipping address out of the customer's session and profile.
	 *
	 * While such a renewal is in the cart, the session customer carries the recipient's address for calculations and
	 * the checkouts would otherwise save their own idea of the shipping address: the classic checkout from the posted
	 * fields, the Store API from the request and then from the placed order. The session customer goes back to the
	 * address it carried before the recipient's was applied to it, and the current user's stored profile keeps every
	 * shipping value it was loaded with. This holds for the rest of the request once the address has been applied,
	 * since the payment empties the cart before WooCommerce saves the session customer on shutdown.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Customer $customer The customer about to be saved.
	 */
	public static function maybe_discard_shipping_address_changes( $customer ) {
		if ( ! $customer instanceof WC_Customer || ( ! self::$applied_withheld_address && ! self::get_withheld_subscription_at_checkout() ) ) {
			return;
		}

		$changes = $customer->get_changes();

		if ( empty( $changes['shipping'] ) || ! is_array( $changes['shipping'] ) ) {
			return;
		}

		if ( self::$applied_customer !== $customer && ( WC()->customer === $customer || (int) $customer->get_id() !== get_current_user_id() ) ) {
			return;
		}

		if ( self::$applied_customer === $customer && is_array( self::$shipping_before_apply ) ) {
			$original = self::$shipping_before_apply;
		} else {
			$loaded   = $customer->get_data();
			$original = isset( $loaded['shipping'] ) && is_array( $loaded['shipping'] ) ? $loaded['shipping'] : array();
		}

		foreach ( array_keys( $changes['shipping'] ) as $field ) {
			if ( array_key_exists( $field, $original ) && is_callable( array( $customer, "set_shipping_{$field}" ) ) ) {
				$customer->{"set_shipping_{$field}"}( $original[ $field ] );
			}
		}
	}

	/**
	 * Drops the shipping fields from the classic checkout's posted data when the address is withheld from the customer.
	 *
	 * With the fields hidden, WooCommerce posts the billing address in their place. Without them, the renewal order
	 * keeps the address it was created with, nothing is saved to the customer's profile and nothing is written back
	 * onto the subscription.
	 *
	 * @since 9.4.0
	 *
	 * @param array $data The posted checkout data.
	 * @return array
	 */
	public static function maybe_remove_posted_shipping_fields( $data ) {
		if ( ! is_array( $data ) || ! self::get_withheld_subscription_at_checkout() ) {
			return $data;
		}

		$shipping_fields = WC()->checkout()->get_checkout_fields( 'shipping' );

		foreach ( array_keys( is_array( $shipping_fields ) ? $shipping_fields : array() ) as $field ) {
			unset( $data[ $field ] );
		}

		$data['ship_to_different_address'] = false;

		return $data;
	}

	/**
	 * Ships a checkout order or subscription to its withheld address.
	 *
	 * A renewal order being paid already carries it, and the Store API copies it from the cart's customer. An order
	 * created here, such as a cart-based early renewal, would otherwise ship nowhere because its shipping fields were
	 * not posted.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Order     $order The order or subscription being created.
	 * @param WC_Cart|null $cart  The subscription's recurring cart, or null for the current cart.
	 */
	public static function maybe_ship_order_to_withheld_address( $order, $cart = null ) {
		$contents = $cart instanceof WC_Cart ? $cart->cart_contents : null;

		if ( ! $order instanceof WC_Order || isset( self::$order_pay_shipping_before[ $order->get_id() ] ) ) {
			return;
		}

		$address     = WCSG_Recipient_Addresses::get_withheld_cart_renewal_shipping_address();
		$source      = self::get_withheld_subscription_at_checkout( $contents );
		$stored      = $order->get_id() ? wc_get_order( $order->get_id() ) : false;
		$resubscribe = false;

		if ( $source && $stored ) {
			$source_ids  = WCS_Related_Order_Store::instance()->get_related_subscription_ids( $stored, 'resubscribe' );
			$resubscribe = in_array( $source->get_id(), (array) $source_ids, true );
		}

		if ( $resubscribe ) {
			$address = WCSG_Recipient_Addresses::get_stored_shipping_address( $source );
		} elseif ( ! $address && $source ) {
			$address = WC()->customer->get_shipping( 'edit' );
		}

		if ( ! $address ) {
			return;
		}

		$order->set_address( $address, 'shipping' );
		if ( $resubscribe ) {
			$order->save();
		}
	}

	/**
	 * Copies the withheld destination for the new subscription's recurring group.
	 *
	 * @since 9.4.0
	 *
	 * @param WC_Subscription $subscription The new subscription.
	 * @param WC_Order        $order        Its parent order.
	 * @param WC_Cart         $cart         Its recurring cart.
	 */
	public static function maybe_ship_subscription_to_withheld_address( $subscription, $order, $cart ) {
		self::maybe_ship_order_to_withheld_address( $subscription, $cart );
	}

	/**
	 * Copies the withheld destination after Classic checkout creates the normal source relations.
	 *
	 * @since 9.4.0
	 *
	 * @param int           $order_id The new order ID.
	 * @param array         $data     Checkout data, if supplied.
	 * @param WC_Order|null $order    The new order, if supplied.
	 */
	public static function maybe_ship_classic_checkout_order_to_withheld_address( $order_id, $data = array(), $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = is_numeric( $order_id ) && $order_id > 0 ? wc_get_order( $order_id ) : false;
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		self::maybe_ship_order_to_withheld_address( $order );
	}

	/**
	 * Uses the source destination while calculating shipping for each eligible resubscribe group.
	 *
	 * @since 9.4.0
	 *
	 * @param array $packages Shipping packages to calculate.
	 * @return array
	 */
	public static function maybe_use_withheld_package_destinations( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		$has_resubscribe = wcs_cart_contains_resubscribe();
		foreach ( $packages as $index => $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}

			self::$tax_calculation_cart_contents = isset( $package['recurring_cart_key'] ) ? ( $package['contents'] ?? array() ) : null;
			if ( ! $has_resubscribe || ! isset( $package['destination'] ) || ! is_array( $package['destination'] ) ) {
				continue;
			}

			$source = self::get_withheld_subscription_at_checkout( self::$tax_calculation_cart_contents );
			if ( ! $source ) {
				continue;
			}

			$address                           = WCSG_Recipient_Addresses::get_stored_shipping_address( $source );
			$address['address']                = $address['address_1'];
			$packages[ $index ]['destination'] = array_intersect_key( $address, $package['destination'] );
		}

		return $packages;
	}

	/**
	 * Blanks the destination of each calculated shipping package while the address is withheld from the customer.
	 *
	 * Rates are already calculated when this runs. The destination is otherwise printed on the cart page and served
	 * by the Store API with every shipping rate.
	 *
	 * @since 9.4.0
	 *
	 * @param array $packages The calculated shipping packages.
	 * @return array
	 */
	public static function maybe_withhold_package_destinations( $packages ) {
		if ( ! is_array( $packages ) || ! self::get_withheld_subscription_at_checkout() ) {
			return $packages;
		}

		foreach ( $packages as $index => $package ) {
			if ( is_array( $package ) && isset( $package['destination'] ) && is_array( $package['destination'] ) ) {
				$packages[ $index ]['destination'] = array_fill_keys( array_keys( $package['destination'] ), '' );
			}
		}

		return $packages;
	}

	/**
	 * Hides the cart page's shipping calculator while the address is withheld from the customer, since its fields
	 * would show the address the totals are calculated from and changing it would change nothing.
	 *
	 * @since 9.4.0
	 *
	 * @param bool $show_calculator Whether to show the calculator.
	 * @return bool
	 */
	public static function maybe_hide_shipping_calculator( $show_calculator ) {
		return $show_calculator && ! self::get_withheld_subscription_at_checkout();
	}

	/**
	 * Stores recipient email data in the session to prevent losing changes made to recipient emails
	 * during the checkout updating the order review fields.
	 *
	 * @param string $checkout_data Checkout _POST data in a query string format.
	 */
	public static function store_recipients_in_session( $checkout_data ) {

		parse_str( $checkout_data, $checkout_data );

		if ( isset( $checkout_data['recipient_email'] ) ) {
			// Store recipient emails on the cart items so they can be repopulated after checkout update.
			foreach ( WC()->cart->cart_contents as $key => $item ) {
				if ( isset( $checkout_data['recipient_email'][ $key ] ) ) {
					$recipient_email = $checkout_data['recipient_email'][ $key ];

					// Clearing a recipient is always allowed; assigning one has to satisfy the store's giftability rules.
					if ( '' !== $recipient_email && ! WCSG_Cart::is_giftable_item( $item ) ) {
						continue;
					}

					WCS_Gifting::update_cart_item_recipient( $item, $key, $recipient_email );

					// Propagate recipient to bundle/composite child items.
					WCSG_Cart::propagate_recipient_to_children( $key, $recipient_email );
				}
			}
		}
	}

	/**
	 * Output a notice to guide the shopper on how to fill the shipping address. The visibility of this notice is controlled by CSS depending on
	 * the status of the gifting checkbox.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.
	 */
	public static function maybe_display_recipient_shipping_notice() {
		if ( ! WCSG_Admin::is_gifting_enabled() || ! WCSG_Cart::contains_gift_recipient_email() ) {
			return;
		}

		wc_print_notice( esc_html__( 'Please enter the gift recipient’s shipping address here, or we’ll collect it directly from them when they log in.', 'woocommerce-subscriptions' ), 'notice' );
	}

	/**
	 * Adds meta data so it can be displayed in the Cart.
	 *
	 * The block cart shows the recipient field for each item given an item_key, so that matches the items the Store
	 * API update callback accepts a recipient for. A renewal carries the recipient of the gifted subscription it
	 * renews; that recipient is returned without the field, so the checkout block can show it and ask for the
	 * recipient's shipping address. The cart block's stylesheet hides the row there.
	 */
	public static function woocommerce_get_item_data( $other_data, $cart_item ) {
		$product = $cart_item['data'];

		if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			return $other_data;
		}

		$gift_recipient    = $cart_item['wcsg_gift_recipients_email'] ?? '';
		$is_giftable       = WCSG_Cart::is_giftable_item( $cart_item );
		$is_gifted_renewal = '' !== $gift_recipient && isset( $cart_item['subscription_renewal'] ) && WCSG_Admin::is_gifting_enabled();

		if ( ! $is_giftable && ! $is_gifted_renewal ) {
			return $other_data;
		}

		if ( $is_giftable ) {
			$other_data[] = array(
				'name'                                     => 'item_key',
				'value'                                    => $cart_item['key'],
				'hidden'                                   => true,
				'__experimental_woocommerce_blocks_hidden' => false,
			);
		}

		if ( $gift_recipient ) {
			$other_data[] = array(
				'name'                                     => __( 'Gifting to', 'woocommerce-subscriptions' ),
				'value'                                    => $gift_recipient,
				'hidden'                                   => true,
				'__experimental_woocommerce_blocks_hidden' => false,
			);

			$other_data[] = array(
				'name'                                     => 'gifting_to_hidden',
				'value'                                    => $gift_recipient,
				'hidden'                                   => true,
				'__experimental_woocommerce_blocks_hidden' => false,
			);
		}

		return $other_data;
	}
}
