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
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'woocommerce_checkout_cart_item_quantity', __CLASS__ . '::add_gifting_option_checkout', 1, 3 );

		add_action( 'woocommerce_checkout_subscription_created', __CLASS__ . '::subscription_created', 1, 3 );

		add_filter( 'woocommerce_subscriptions_recurring_cart_key', __CLASS__ . '::add_recipient_email_recurring_cart_key', 1, 2 );

		add_action( 'woocommerce_checkout_process', __CLASS__ . '::update_cart_before_checkout' );

		add_filter( 'woocommerce_ship_to_different_address_checked', __CLASS__ . '::maybe_ship_to_recipient', 100, 1 );

		add_filter( 'woocommerce_checkout_get_value', __CLASS__ . '::maybe_get_recipient_shipping', 10, 2 );

		add_action( 'woocommerce_checkout_update_order_review', __CLASS__ . '::store_recipients_in_session', 10, 1 );

		add_action( 'woocommerce_before_checkout_shipping_form', __CLASS__ . '::maybe_display_recipient_shipping_notice', 10 );

		add_filter( 'woocommerce_get_item_data', __CLASS__ . '::woocommerce_get_item_data', 10, 2 );
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
		$cart_item = reset( $recurring_cart->cart_contents );
		if ( ! empty( $cart_item['wcsg_gift_recipients_email'] ) ) {
			$subscription->update_meta_data( '_recipient_user_email_address', $cart_item['wcsg_gift_recipients_email'] );
			$subscription->save();
		}
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
						WCS_Gifting::update_cart_item_recipient( $item, $key, $_POST['recipient_email'][ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
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
	 * @param bool $ship_to_different_address Whether the order will ship to a different address.
	 * @return bool
	 */
	public static function maybe_ship_to_recipient( $ship_to_different_address ) {

		if ( ! $ship_to_different_address && ( WCSG_Cart::contains_gifted_renewal() || WCSG_Cart::contains_gift_recipient_email() ) ) {
			$ship_to_different_address = true;
		}
		return $ship_to_different_address;
	}

	/**
	 * Returns recipient's shipping address if the checkout is requesting
	 * the shipping fields for a gifted subscription renewal.
	 *
	 * @param string $value Default checkout field value.
	 * @param string $key The checkout form field name/key.
	 */
	public static function maybe_get_recipient_shipping( $value, $key ) {
		$shipping_fields = WC()->countries->get_address_fields( '', 'shipping_' );

		if ( array_key_exists( $key, $shipping_fields ) && WCSG_Cart::contains_gifted_renewal() ) {
			$item              = wcs_cart_contains_renewal();
			$subscription      = wcs_get_subscription( $item['subscription_renewal']['subscription_id'] );
			$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );
			$value             = get_user_meta( $recipient_user_id, $key, true );
		}

		return $value;
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
					WCS_Gifting::update_cart_item_recipient( $item, $key, $checkout_data['recipient_email'][ $key ] );
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
		wc_print_notice( esc_html__( 'Please enter the gift recipient’s shipping address here, or we’ll collect it directly from them when they log in.', 'woocommerce-subscriptions' ), 'notice' );
	}

	/**
	 * Adds meta data so it can be displayed in the Cart.
	 */
	public static function woocommerce_get_item_data( $other_data, $cart_item ) {
		$product = $cart_item['data'];

		if ( ! WC_Subscriptions_Product::is_subscription( $product ) || ! WCSG_Product::is_giftable( $product ) ) {
			return $other_data;
		}

		$other_data[] = array(
			'name'                                     => 'item_key',
			'value'                                    => $cart_item['key'],
			'hidden'                                   => true,
			'__experimental_woocommerce_blocks_hidden' => false,
		);

		$gift_recipient = $cart_item['wcsg_gift_recipients_email'] ?? '';

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
