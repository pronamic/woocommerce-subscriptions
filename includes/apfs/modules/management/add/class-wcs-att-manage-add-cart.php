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

		// Hide the "Add to existing subscription" option when any cart item is on a plan that requires an upfront
		// charge - a sign-up fee or a prorated first payment. Adding to an existing subscription collects nothing,
		// so the charge would go uncollected; the single-product view applies the same rule per plan.
		if ( $is_feature_supported && ! is_null( WC()->cart ) && ! empty( WC()->cart->cart_contents ) ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				$active_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'], 'object' );

				if ( is_a( $active_scheme, 'WCS_ATT_Scheme' ) && $active_scheme->requires_upfront_charge( $cart_item['data'], $active_scheme->get_key() ) ) {
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

		WCS_ATT_Display::maybe_enqueue_cart_script();

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
			 * Last chance to filter the subscriptions shown. This only shapes the list offered: the form handler
			 * re-checks the posted subscription with 'WCS_ATT_Manage_Add::is_matching_subscription()', so use the
			 * 'wcsatt_is_matching_subscription' filter to change which subscriptions are eligible.
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

		// A subscription that does not exist and one that belongs to somebody else share a single notice. The nonce
		// action here is fixed rather than per-subscription, so any logged-in customer can mint a valid one: telling
		// the two cases apart would let them walk the ID space to learn which IDs are subscriptions.
		if ( ! WCS_ATT_Manage_Add::current_user_can_add_to_subscription( $subscription ) ) {
			// translators: %d: subscription ID.
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

		// The subscriptions offered in the cart and checkout views are only those the cart can genuinely be added to.
		// That list is worked out when the page is built, so re-derive the same answer here for the subscription that
		// was actually posted. A cart of one-time products contributes no schemes to match, but the subscription must
		// still be active and paid with a gateway that can change the amount charged - otherwise the store has no way
		// of collecting the increase.
		if ( ! WCS_ATT_Manage_Add::is_matching_subscription( $subscription, $available_schemes ) ) {
			// translators: %d: subscription ID.
			wc_add_notice( sprintf( __( 'Your cart cannot be added to subscription #%d. Please choose an active subscription paid with a payment method that supports changes to the amount charged.', 'woocommerce-subscriptions' ), $subscription_id ), 'error' );
			return;
		}

		// Plans created since 9.0.0 are keyed by an id rather than by their billing schedule, so the cart's plan
		// cannot be looked up under the dummy scheme's key. Find it by billing schedule instead, which is the test the
		// list of subscriptions offered to the customer was built with. A cart item without a selected plan offers all
		// of its product's plans, and two of those may share a schedule; choosing between them would apply pricing
		// the customer never picked, so that case is refused below, the same as no match at all.
		$matching_scheme_keys = array();

		if ( is_array( $available_schemes ) ) {
			foreach ( $available_schemes as $scheme_key => $scheme ) {
				if ( $scheme instanceof WCS_ATT_Scheme && $scheme->matches_subscription( $subscription ) ) {
					$matching_scheme_keys[] = $scheme_key;
				}
			}
		}

		// If we are adding a product with subscription plans to an existing subscription, use existing scheme to benefit from the discount!
		if ( 1 === count( $matching_scheme_keys ) ) {
			$applied_scheme_key = current( $matching_scheme_keys );
			$applied_schemes    = array( $applied_scheme_key => $available_schemes[ $applied_scheme_key ] );

			// Otherwise, if we are adding a one-time product to a subscription, apply dummy subscription scheme.
		} else {
			$applied_scheme_key = $subscription_scheme_key;
			$applied_schemes    = array( $subscription_scheme_key => $subscription_scheme_obj );
		}

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
			WCS_ATT_Product_Schemes::set_subscription_schemes( WC()->cart->cart_contents[ $cart_item_key ]['data'], $applied_schemes );
			WCS_ATT_Product_Schemes::set_subscription_scheme( WC()->cart->cart_contents[ $cart_item_key ]['data'], $applied_scheme_key );
		}

		if ( ! is_null( $available_schemes ) && ( 1 !== count( $matching_scheme_keys ) || ! WC_Subscriptions_Cart::cart_contains_subscription() || ! $subscription_scheme_obj->matches_subscription( $subscription ) ) ) {
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
