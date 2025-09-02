<?php
/**
 * Cart integration.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class for cart integration.
 */
class WCSG_Cart {

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'woocommerce_after_cart_item_name', __CLASS__ . '::print_gifting_option_cart', 10, 2 );

		add_filter( 'woocommerce_widget_cart_item_quantity', __CLASS__ . '::add_gifting_option_minicart', 1, 3 );

		add_filter( 'woocommerce_update_cart_action_cart_updated', __CLASS__ . '::cart_update', 1, 1 );

		add_filter( 'woocommerce_add_to_cart_validation', __CLASS__ . '::prevent_products_in_gifted_renewal_orders', 10 );

		add_filter( 'woocommerce_order_again_cart_item_data', __CLASS__ . '::add_recipient_to_resubscribe_initial_payment_item', 10, 3 );

		add_filter( 'woocommerce_order_again_cart_item_data', __CLASS__ . '::remove_recipient_from_order_again_cart_item_meta', 10, 1 );

		add_filter( 'woocommerce_checkout_create_order_line_item', __CLASS__ . '::add_recipient_to_order_line_item', 10, 4 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_blocks_update_callback();
		} else {
			add_action( 'woocommerce_blocks_loaded', __CLASS__ . '::register_blocks_update_callback' );
		}
	}

	/**
	 * Adds the wcsg_cart_key meta to the order line item
	 * So it's possible to track which subscription came from which parent order line item.
	 *
	 * @param WC_Order_Item_Product $item The order line item.
	 * @param string $cart_item_key The cart item key.
	 * @param array $values The cart item values.
	 * @param WC_Order $order The order.
	 */
	public static function add_recipient_to_order_line_item( $item, $cart_item_key, $values, $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! isset( $values['wcsg_gift_recipients_email'] ) ) {
			return;
		}

		$item->add_meta_data( '_wcsg_cart_key', $cart_item_key );
	}

	/**
	 * Registers the blocks cart update callback.
	 */
	public static function register_blocks_update_callback() {
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'wcsg-cart',
				'callback'  => __CLASS__ . '::handle_blocks_update_cart_item_recipient',
			)
		);
	}

	/**
	 * Handles the blocks cart update callback.
	 *
	 * @param array $data The data from the blocks update callback.
	 */
	public static function handle_blocks_update_cart_item_recipient( $data ) {
		if ( ! isset( $data['recipient'] ) || ! isset( $data['itemKey'] ) ) {
			return;
		}

		$recipient = $data['recipient'];
		$key       = $data['itemKey'];

		if ( ! WCS_Gifting::validate_recipient_emails( array( $recipient ) ) ) {
			return;
		}

		if ( ! isset( WC()->cart->cart_contents[ $key ] ) ) {
			return;
		}

		WC()->cart->cart_contents[ $key ]['wcsg_gift_recipients_email'] = $recipient;
	}

	/**
	 * Adds gifting ui elements to subscription cart items.
	 *
	 * @param string $title         The product title displayed in the cart table.
	 * @param array  $cart_item     Details of an item in WC_Cart.
	 * @param string $cart_item_key The key of the cart item being displayed in the cart table.
	 */
	public static function add_gifting_option_cart( $title, $cart_item, $cart_item_key ) {

		$is_mini_cart = did_action( 'woocommerce_before_mini_cart' ) && ! did_action( 'woocommerce_after_mini_cart' );

		if ( is_cart() && ! $is_mini_cart ) {
			$title .= self::maybe_display_gifting_information( $cart_item, $cart_item_key );
		}

		return $title;
	}

	/**
	 * Adds gifting ui elements to subscription items in the mini cart.
	 *
	 * @param int    $quantity      The quantity of the cart item.
	 * @param array  $cart_item     Details of an item in WC_Cart.
	 * @param string $cart_item_key Key of the cart item being displayed in the mini cart.
	 */
	public static function add_gifting_option_minicart( $quantity, $cart_item, $cart_item_key ) {
		$recipient_email = '';
		$html_string     = '';

		if ( self::contains_gifted_renewal() ) {
			$recipient_user_id = self::get_recipient_from_cart_item( wcs_cart_contains_renewal() );
			$recipient_user    = get_userdata( $recipient_user_id );

			if ( $recipient_user ) {
				$recipient_email = $recipient_user->user_email;
			}
		} elseif ( ! empty( $cart_item['wcsg_gift_recipients_email'] ) ) {
			$recipient_email = $cart_item['wcsg_gift_recipients_email'];
		}

		if ( '' !== $recipient_email ) {
			ob_start();
			wc_get_template( 'html-flat-gifting-recipient-details.php', array( 'email' => $recipient_email ), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
			$html_string = ob_get_clean();
		}

		return $quantity . $html_string;
	}

	/**
	 * Updates the cart items for changes made to recipient infomation on the cart page.
	 *
	 * @param bool $cart_updated whether the cart has been updated.
	 */
	public static function cart_update( $cart_updated ) {
		if ( ! empty( $_POST['recipient_email'] ) ) {
			if ( ! empty( $_POST['_wcsgnonce'] ) && wp_verify_nonce( $_POST['_wcsgnonce'], 'wcsg_add_recipient' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				$recipients = $_POST['recipient_email']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				WCS_Gifting::validate_recipient_emails( $recipients );
				foreach ( WC()->cart->cart_contents as $key => $item ) {
					if ( isset( $_POST['recipient_email'][ $key ] ) ) {
						WCS_Gifting::update_cart_item_recipient( $item, $key, $_POST['recipient_email'][ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
					}
				}
			} else {
				wc_add_notice( __( 'There was an error with your request. Please try again.', 'woocommerce-subscriptions' ), 'error' );
			}
		}

		return $cart_updated;
	}

	/**
	 * Prevent products being added to the cart if the cart contains a gifted subscription renewal.
	 *
	 * @param bool $passed Whether adding to cart is valid.
	 */
	public static function prevent_products_in_gifted_renewal_orders( $passed ) {
		if ( $passed ) {
			foreach ( WC()->cart->cart_contents as $key => $item ) {
				if ( isset( $item['subscription_renewal'] ) ) {
					$subscription = wcs_get_subscription( $item['subscription_renewal']['subscription_id'] );
					if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {
						$passed = false;
						wc_add_notice( __( 'You cannot add additional products to the cart. Please pay for the subscription renewal first.', 'woocommerce-subscriptions' ), 'error' );
						break;
					}
				}
			}
		}
		return $passed;
	}

	/**
	 * Determines if a cart item is able to be gifted.
	 * Only subscriptions that are not a renewal or switch subscription are giftable.
	 *
	 * @param array $cart_item Cart item.
	 * @return bool Whether the cart item is giftable.
	 */
	public static function is_giftable_item( $cart_item ) {
		return WCSG_Product::is_giftable( $cart_item['data'] ) && ! isset( $cart_item['subscription_renewal'] ) && ! isset( $cart_item['subscription_switch'] );
	}

	/**
	 * Returns the relevant html (static/flat, interactive or none at all) depending on
	 * whether the cart item is a giftable cart item or is a gifted renewal item.
	 *
	 * @param array  $cart_item       The cart item.
	 * @param string $cart_item_key   The cart item key.
	 * @param string $print_or_return Wether to print or return the HTML content. Optional. Default behaviour is to return the string. Pass 'print' to print the HTML content directly.
	 * @return string Returns the HTML string if $print_or_return is set to 'return', otherwise prints the HTML and nothing is returned.
	 */
	public static function maybe_display_gifting_information( $cart_item, $cart_item_key, $print_or_return = 'return' ) {
		$output = '';

		if ( self::is_giftable_item( $cart_item ) ) {
			$email  = ( empty( $cart_item['wcsg_gift_recipients_email'] ) ) ? '' : $cart_item['wcsg_gift_recipients_email'];
			$output = WCS_Gifting::render_add_recipient_fields( $email, $cart_item_key, 'return' );
		} elseif ( self::contains_gifted_renewal() ) {
			$recipient_user_id = self::get_recipient_from_cart_item( wcs_cart_contains_renewal() );
			$recipient_user    = get_userdata( $recipient_user_id );

			if ( $recipient_user ) {
				$output = wc_get_template_html(
					'html-flat-gifting-recipient-details.php',
					array( 'email' => $recipient_user->user_email ),
					'',
					plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/'
				);
			}
		}

		if ( 'return' === $print_or_return ) {
			return $output;
		} else {
			echo wp_kses(
				$output,
				array(
					'fieldset' => array(),
					'input'    => array(
						'type'           => array(),
						'id'             => array(),
						'class'          => array(),
						'style'          => array(),
						'value'          => array(),
						'checked'        => array(),
						'disabled'       => array(),
						'data-recipient' => array(),
						'name'           => array(),
						'placeholder'    => array(),
					),
					'label'    => array(
						'for' => array(),
					),
					'div'      => array(
						'class' => array(),
						'style' => array(),
					),
					'p'        => array(
						'class' => array(),
						'style' => array(),
						'id'    => array(),
					),
					'svg'      => array(
						'xmlns'       => array(),
						'viewbox'     => array(),
						'width'       => array(),
						'height'      => array(),
						'aria-hidden' => array(),
						'focusable'   => array(),
					),
					'path'     => array(
						'd' => array(),
					),
					'span'     => array(),
				)
			);
		}

		return '';
	}

	/**
	 * When setting up the cart for resubscribes or initial subscription payment carts, ensure the existing subscription recipient email is added to the cart item.
	 *
	 * @param array  $cart_item_data Cart item data.
	 * @param array  $line_item      Line item.
	 * @param object $subscription   Subscription object.
	 * @return array Updated cart item data.
	 */
	public static function add_recipient_to_resubscribe_initial_payment_item( $cart_item_data, $line_item, $subscription ) {
		$recipient_user_id = 0;

		if ( $subscription instanceof WC_Order && isset( $line_item['wcsg_recipient'] ) ) {
			$recipient_user_id = substr( $line_item['wcsg_recipient'], strlen( 'wcsg_recipient_id_' ) );

		} elseif ( ! array_key_exists( 'subscription_renewal', $cart_item_data ) && WCS_Gifting::is_gifted_subscription( $subscription ) ) {
			$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );
		}

		if ( ! empty( $recipient_user_id ) ) {
			$recipient_user = get_userdata( $recipient_user_id );

			if ( $recipient_user ) {
				$cart_item_data['wcsg_gift_recipients_email'] = $recipient_user->user_email;
			}
		}

		return $cart_item_data;
	}

	/**
	 * Checks the cart to see if it contains a gifted subscription renewal.
	 *
	 * @return bool
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.
	 */
	public static function contains_gifted_renewal() {
		$cart_contains_gifted_renewal = false;

		$item = wcs_cart_contains_renewal();

		if ( $item ) {
			$cart_contains_gifted_renewal = WCS_Gifting::is_gifted_subscription( $item['subscription_renewal']['subscription_id'] );
		}

		return $cart_contains_gifted_renewal;
	}

	/**
	 * Checks the cart to see if a gift recipient email is set.
	 *
	 * @return bool
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.
	 */
	public static function contains_gift_recipient_email() {
		$has_recipient_email = false;

		if ( ! empty( WC()->cart->cart_contents ) ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( ! empty( $cart_item['wcsg_gift_recipients_email'] ) ) {
					$has_recipient_email = true;
					break;
				}
			}
		}

		return $has_recipient_email;
	}

	/**
	 * Retrieve a recipient user's ID from a cart item.
	 *
	 * @param array $cart_item Cart item.
	 * @return string the recipient id. If the cart item doesn't belong to a recipient an empty string is returned
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.1.
	 */
	public static function get_recipient_from_cart_item( $cart_item ) {
		$recipient_email   = '';
		$recipient_user_id = '';

		if ( isset( $cart_item['subscription_renewal'] ) && WCS_Gifting::is_gifted_subscription( $cart_item['subscription_renewal']['subscription_id'] ) ) {
			$recipient_id    = WCS_Gifting::get_recipient_user( wcs_get_subscription( $cart_item['subscription_renewal']['subscription_id'] ) );
			$recipient       = get_user_by( 'id', $recipient_id );
			$recipient_email = $recipient->user_email;
		} elseif ( isset( $cart_item['wcsg_gift_recipients_email'] ) ) {
			$recipient_email = $cart_item['wcsg_gift_recipients_email'];
		}

		if ( ! empty( $recipient_email ) ) {
			$recipient_user_id = email_exists( $recipient_email );
		}

		return $recipient_user_id;
	}

	/**
	 * Remove recipient line item meta from order again cart item meta. This meta is re-added to the line item after
	 * checkout and so doesn't need to copied through the cart in this way.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @return array Updated cart item data.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.1.
	 */
	public static function remove_recipient_from_order_again_cart_item_meta( $cart_item_data ) {

		foreach ( array( 'subscription_renewal', 'subscription_resubscribe', 'subscription_initial_payment' ) as $subscription_order_again_key ) {
			if ( isset( $cart_item_data[ $subscription_order_again_key ]['custom_line_item_meta']['wcsg_recipient'] ) ) {
				unset( $cart_item_data[ $subscription_order_again_key ]['custom_line_item_meta']['wcsg_recipient'] );
			}
		}

		return $cart_item_data;
	}

	/**
	 * Maybe print gifting HTML elements.
	 *
	 * @param array  $cart_item     The cart item array data.
	 * @param string $cart_item_key The cart item key.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 */
	public static function print_gifting_option_cart( $cart_item, $cart_item_key ) {
		self::maybe_display_gifting_information( $cart_item, $cart_item_key, 'print' );
	}

	/** Deprecated **/

	/**
	 * Returns gifting ui html elements displaying the email of the recipient.
	 *
	 * @param string $cart_item_key The key of the cart item being displayed in the mini cart.
	 * @param string $email The email of the gift recipient.
	 * @deprecated 2.0.1
	 */
	public static function generate_static_gifting_html( $cart_item_key, $email ) {
		wcs_deprecated_function( __METHOD__, '2.0.1', "the 'html-flat-gifting-recipient-details.php' template. For example usage, see " . __METHOD__ );

		ob_start();
		wc_get_template( 'html-flat-gifting-recipient-details.php', array( 'email' => $email ), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
		return ob_get_clean();
	}
}
