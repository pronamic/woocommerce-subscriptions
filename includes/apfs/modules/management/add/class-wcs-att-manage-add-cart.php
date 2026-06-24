<?php
/**
 * WCS_ATT_Manage_Add_Cart class
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
 * @class    WCS_ATT_Manage_Add_Cart
 * @version  6.0.0
 */
class WCS_ATT_Manage_Add_Cart extends WCS_ATT_Abstract_Module {

	/**
	 * Register display hooks.
	 *
	 * @return void
	 */
	protected function register_display_hooks() {

		// Template hooks.
		self::register_template_hooks();

		// Ajax handler.
		self::register_ajax_hooks();
	}

	/**
	 * Register form hooks.
	 */
	protected function register_form_hooks() {

		// Adds carts to subscriptions.
		add_action( 'wp_loaded', array( __CLASS__, 'form_handler' ), 100 );
	}

	/**
	 * Register template hooks.
	 */
	private static function register_template_hooks() {

		// Render the "Add-to-Subscription" options under the "Proceed to Checkout" button.
		add_action( 'woocommerce_after_cart_totals', array( __CLASS__, 'options_template' ), 100 );

		// Render the "Add-to-Subscription" options under the "Place Order / Sign Up" button in the Checkout page.
		add_action( 'woocommerce_checkout_order_review', array( __CLASS__, 'options_template' ), 999 );

		// Render subscriptions list.
		add_action( 'wcsatt_add_cart_to_subscription_html', array( __CLASS__, 'matching_subscriptions_template' ), 10, 2 );

		// Render subscriptions matching cart (server-side).
		add_action( 'wcsatt_display_subscriptions_matching_cart', array( __CLASS__, 'display_matching_subscriptions' ) );
	}

	/**
	 * Register ajax hooks.
	 */
	private static function register_ajax_hooks() {

		// Fetch subscriptions matching cart scheme via ajax.
		add_action( 'wc_ajax_wcsatt_load_subscriptions_matching_cart', array( __CLASS__, 'load_matching_subscriptions' ) );

		// Adds a cart to a subscription via the checkout page.
		add_action( 'wc_ajax_wcsatt_add_cart_to_subscription_from_checkout', array( __CLASS__, 'add_cart_to_subscription_from_checkout' ) );
	}

	/**
	 * Is adding carts to existing subscriptions supported?
	 *
	 * @since  APFS 3.1.19
	 * @return boolean
	 */
	public static function is_feature_supported( $context = 'cart' ) {

		if ( 'off' === get_option( 'wcsatt_add_cart_to_subscription', 'off' ) ) {
			return false;
		}

		$matching_schemes     = WCS_ATT_Manage_Add::get_schemes_matching_cart();
		$is_feature_supported = is_null( $matching_schemes ) || ( is_array( $matching_schemes ) && ! empty( $matching_schemes ) );

		// Hide the "Add to existing subscription" option when any cart item has an active
		// subscription plan with a sign-up fee. Sign-up fees create ambiguity around whether
		// the fee should be charged again when adding to an existing subscription, so we
		// disable the feature entirely in this case.
		if ( $is_feature_supported && ! is_null( WC()->cart ) && ! empty( WC()->cart->cart_contents ) ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				$active_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'], 'object' );

				if ( is_a( $active_scheme, 'WCS_ATT_Scheme' ) && $active_scheme->has_signup_fee() ) {
					$is_feature_supported = false;
					break;
				}
			}
		}

		/**
		 * 'wcsatt_add_cart_to_subscription_supported' filter.
		 *
		 * @since  APFS 3.1.29
		 *
		 * @param  boolean  $is_feature_supported
		 * @param  string   $context
		 */
		return apply_filters( 'wcsatt_add_cart_to_subscription_supported', $is_feature_supported, $context );
	}

	/*
	|--------------------------------------------------------------------------
	| Templates
	|--------------------------------------------------------------------------
	*/

	/**
	 * 'Add cart to subscription' view -- template wrapper element.
	 */
	public static function options_template() {

		if ( 'off' === get_option( 'wcsatt_add_cart_to_subscription', 'off' ) ) {
			return;
		}

		// User must be logged in.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$posted_data = WCS_ATT_Manage_Add::get_posted_data( 'update-cart' );

		if ( is_checkout() ) {
			wc_get_template(
				'checkout/cart-add-to-subscription.php',
				array(
					'is_visible'       => self::is_feature_supported( 'checkout-display' ),
					'is_checked'       => $posted_data['add_to_subscription_checked'],
					'force_responsive' => apply_filters( 'wcsatt_add_cart_to_subscription_table_force_responsive', true ),
				),
				false,
				plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
			);
		} else {
			wc_get_template(
				'cart/cart-add-to-subscription.php',
				array(
					'is_visible'       => self::is_feature_supported( 'cart-display' ),
					'is_checked'       => $posted_data['add_to_subscription_checked'],
					'force_responsive' => apply_filters( 'wcsatt_add_cart_to_subscription_table_force_responsive', true ),
				),
				false,
				plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
			);
		}
	}

	/**
	 * Displays list of subscriptions matching a cart.
	 */
	public static function display_matching_subscriptions() {

		$context = is_checkout() ? 'checkout-display' : 'cart-display';
		if ( self::is_feature_supported( $context ) ) {

			$supported_schemes = WCS_ATT_Manage_Add::get_schemes_matching_cart();

			/**
			 * 'wcsatt_subscriptions_matching_cart' filter.
			 *
			 * Last chance to filter matched subscriptions.
			 *
			 * @param  array       $matching_subscriptions
			 * @param  array|null  $scheme Pass null to allow adding carts into all the supported account subscriptions.
			 */
			$matching_subscriptions = apply_filters( 'wcsatt_subscriptions_matching_cart', WCS_ATT_Manage_Add::get_matching_subscriptions( $supported_schemes ), $supported_schemes );

			/**
			 * 'wcsatt_add_cart_to_subscription_html' action.
			 *
			 * @param  array                $matching_subscriptions
			 * @param  WCS_ATT_Scheme|null  $scheme
			 */
			do_action( 'wcsatt_add_cart_to_subscription_html', $matching_subscriptions, $supported_schemes );
		}
	}

	/**
	 * 'Add to subscription' view -- matching list of subscriptions.
	 *
	 * @param  array      $subscriptions
	 * @param  array|null $schemes
	 * @return void
	 */
	public static function matching_subscriptions_template( $subscriptions, $schemes ) {

		wp_nonce_field( 'wcsatt_add_cart_to_subscription', 'wcsatt_nonce' );

		wc_get_template(
			'cart/cart-add-to-subscription-list.php',
			array(
				'subscriptions' => $subscriptions,
				'user_id'       => get_current_user_id(),
				'context'       => 'cart',
			),
			false,
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Ajax Handlers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Load all user subscriptions matching a cart + scheme key (known billing period and interval).
	 *
	 * @return void
	 */
	public static function load_matching_subscriptions() {

		$failure = array(
			'result' => 'failure',
			'html'   => '',
		);

		// User must be logged in.
		if ( ! is_user_logged_in() ) {
			wp_send_json( $failure );
		}

		ob_start();

		self::display_matching_subscriptions();

		$html = ob_get_clean();

		if ( ! $html ) {
			$result = $failure;
		} else {
			$result = array(
				'result' => 'success',
				'html'   => $html,
			);
		}

		wp_send_json( $result );
	}

	/**
	 * Adds a cart to a subscription via the checkout page.
	 *
	 * @return void
	 */
	public static function add_cart_to_subscription_from_checkout() {

		$failure = array(
			'result' => 'failure',
		);

		// If the subscription ID is not posted by the AJAX request, exit early.
		if ( ! isset( $_POST['add-cart-to-subscription'] ) ) {
			wp_send_json( $failure );
		}

		// Store the subscription ID to use it to generate the subscription URL.
		$subscription_id = absint( $_POST['add-cart-to-subscription'] );

		// A successful addition of the cart to an existing subscription means that the
		// 'wcsatt_add_cart_to_subscription' action has ran and the cart has been emptied.
		//
		// See: WCS_ATT_Manage_Add_Cart::form_handler() and WCS_ATT_Manage_Add::add_cart_to_subscription().
		$added = did_action( 'wcsatt_add_cart_to_subscription' ) && 0 === WC()->cart->get_cart_contents_count();

		if ( ! $added ) {

			$result = $failure;

		} else {

			$subscription     = wcs_get_subscription( $subscription_id );
			$subscription_url = $subscription->get_view_order_url();
			$redirect_url     = apply_filters( 'wcsatt_add_cart_to_subscription_redirect_url', $subscription_url, $subscription );

			$result = array(
				'result' => 'success',
				'url'    => $redirect_url,
			);
		}

		wp_send_json( $result );
	}

	/*
	|--------------------------------------------------------------------------
	| Form Handlers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Adds carts to subscriptions.
	 */
	public static function form_handler() {

		$posted_data = WCS_ATT_Manage_Add::get_posted_data( 'cart' );

		if ( empty( $posted_data['subscription_id'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $posted_data['nonce'], 'wcsatt_add_cart_to_subscription' ) ) {
			return;
		}

		if ( ! self::is_feature_supported( 'cart' ) ) {
			return;
		}

		$subscription_id = $posted_data['subscription_id'];
		$subscription    = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			wc_add_notice( sprintf( __( 'Subscription #%d cannot be edited. Please get in touch with us for assistance.', 'woocommerce-subscriptions' ), $subscription_id ), 'error' );
			return;
		}

		// Extract the scheme details from the subscription and create a dummy scheme.
		$subscription_scheme_obj = new WCS_ATT_Scheme(
			array(
				'context' => 'product',
				'data'    => array(
					'subscription_period'          => $subscription->get_billing_period(),
					'subscription_period_interval' => $subscription->get_billing_interval(),
				),
			)
		);

		$subscription_scheme_key = $subscription_scheme_obj->get_key();
		$available_schemes       = WCS_ATT_Manage_Add::get_schemes_matching_cart();

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {

			// If we are adding a product with subscription plans to an existing subscription, use existing scheme to benefit from the discount!
			if ( ! is_null( $available_schemes ) && isset( $available_schemes[ $subscription_scheme_key ] ) ) {
				$subscription_scheme = array( $subscription_scheme_key => $available_schemes[ $subscription_scheme_key ] );

				// Otherwise, if we are adding a one-time product to a subscription, apply dummy subscription scheme.
			} else {
				$subscription_scheme = array( $subscription_scheme_key => $subscription_scheme_obj );
			}

			WCS_ATT_Product_Schemes::set_subscription_schemes( WC()->cart->cart_contents[ $cart_item_key ]['data'], $subscription_scheme );
			WCS_ATT_Product_Schemes::set_subscription_scheme( WC()->cart->cart_contents[ $cart_item_key ]['data'], $subscription_scheme_key );
		}

		if ( ! is_null( $available_schemes ) && ( ! isset( $available_schemes[ $subscription_scheme_key ] ) || ! WC_Subscriptions_Cart::cart_contains_subscription() || ! $subscription_scheme_obj->matches_subscription( $subscription ) ) ) {
			wc_add_notice( sprintf( __( 'Your cart cannot be added to subscription #%d. Please get in touch with us for assistance.', 'woocommerce-subscriptions' ), $subscription_id ), 'error' );
			return;
		}

		try {

			/**
			 * 'wcsatt_add_cart_to_subscription' action.
			 *
			 * @param  WC_Subscription  $subscription
			 *
			 * @hooked WCS_ATT_Manage_Add::add_cart_to_subscription - 10
			 */
			do_action( 'wcsatt_add_cart_to_subscription', $subscription );

		} catch ( Exception $e ) {

			if ( $notice = $e->getMessage() ) {

				wc_add_notice( $notice, 'error' );
				return false;
			}
		}
	}
}
