<?php
/**
 * WCS_ATT_Manage_Add_Product class
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
 * @class    WCS_ATT_Manage_Add_Product
 * @version  6.0.0
 */
class WCS_ATT_Manage_Add_Product extends WCS_ATT_Abstract_Module {

	/**
	 * Using this to pass data from 'WC_Form_Handler::add_to_cart_action' into our own logic.
	 *
	 * @var array
	 */
	private static $add_to_subscription_args = array();

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

		// Adds products to subscriptions after validating.
		add_action( 'wp_loaded', array( __CLASS__, 'form_handler' ), 15 );
	}

	/**
	 * Register template hooks.
	 */
	private static function register_template_hooks() {

		// Render the add-to-subscription wrapper element in single-product pages.
		add_action( 'woocommerce_after_add_to_cart_button', array( __CLASS__, 'options_template' ), 1000 );

		// Render subscriptions list.
		add_action( 'wcsatt_add_product_to_subscription_html', array( __CLASS__, 'matching_subscriptions_template' ), 10, 3 );
	}

	/**
	 * Register ajax hooks.
	 */
	private static function register_ajax_hooks() {

		// Fetch subscriptions matching product scheme via ajax.
		add_action( 'wc_ajax_wcsatt_load_subscriptions_matching_product', array( __CLASS__, 'load_matching_subscriptions' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Templates
	|--------------------------------------------------------------------------
	*/

	/**
	 * 'Add to subscription' view -- wrapper element.
	 */
	public static function options_template() {

		global $product;

		if ( ! WCS_ATT_Product::supports_feature( $product, 'subscription_management_add_to_subscription_product_single' ) ) {
			return;
		}

		// Bypass when switching.
		if ( WCS_ATT()->is_module_registered( 'manage' ) && WCS_ATT_Manage_Switch::is_switch_request_for_product( $product ) ) {
			return;
		}

		// User must be logged in.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$subscription_options_visible = true;

		/*
		 * Subscription options for variable products are embedded inside the variation data 'price_html' field and updated by the core variations script.
		 * The add-to-subscription template is displayed when a variation is found.
		 */
		if ( $product->is_type( 'variable' ) ) {

			$subscription_options_visible = false;

		} elseif ( WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_options_product_single' ) ) {

			$subscription_options_visible = false;

			$subscription_schemes                 = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
			$force_subscription                   = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product );
			$is_single_scheme_forced_subscription = $force_subscription && count( $subscription_schemes ) === 1;
			$default_subscription_scheme_key      = apply_filters( 'wcsatt_get_default_subscription_scheme_id', WCS_ATT_Product_Schemes::get_default_subscription_scheme( $product, 'key' ), $subscription_schemes, false === $force_subscription, $product ); // Why 'false === $force_subscription'? The answer is back-compat.

			if ( isset( $_REQUEST['add-to-cart'] ) ) {
				if ( $product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_REQUEST['add-to-cart'] ) ) ) {
					if ( $posted_subscription_scheme_key = WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $product_id ) ) {
						$default_subscription_scheme_key = $posted_subscription_scheme_key;
					}
				}
			}

			$default_subscription_scheme  = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $default_subscription_scheme_key ); // Again, the reason we are not using the key directly below is back-compat (the filter). Accounting for an invalid filtered value means we're probably a bit too conservative here.
			$subscription_options_visible = $is_single_scheme_forced_subscription || ( is_object( $default_subscription_scheme ) && ! $default_subscription_scheme->requires_upfront_charge( $product, $default_subscription_scheme_key ) );
		}

		wc_get_template(
			'single-product/product-add-to-subscription.php',
			array(
				'product_id'       => $product->get_id(),
				'is_visible'       => $subscription_options_visible,
				'force_responsive' => apply_filters( 'wcsatt_add_to_subscription_table_force_responsive', true ),
			),
			false,
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
		);
	}

	/**
	 * 'Add to subscription' view -- matching list of subscriptions.
	 *
	 * @param  array               $subscriptions
	 * @param  WC_Product          $product
	 * @param  WCS_ATT_Scheme|null $scheme
	 * @return void
	 */
	public static function matching_subscriptions_template( $subscriptions, $product, $scheme ) {

		wp_nonce_field( 'wcsatt_add_product_to_subscription', 'wcsatt_nonce_' . $product->get_id() );

		wc_get_template(
			'single-product/product-add-to-subscription-list.php',
			array(
				'subscriptions' => $subscriptions,
				'product'       => $product,
				'scheme'        => $scheme,
				'user_id'       => get_current_user_id(),
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
	 * Load all user subscriptions matching a product + scheme key (known billing period and interval).
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

		$product_id = ! empty( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : false;
		$scheme_key = ! empty( $_POST['subscription_scheme'] ) ? wc_clean( $_POST['subscription_scheme'] ) : false;

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json( $failure );
		}

		// Keep the sneaky folks out.
		if ( ! WCS_ATT_Product::supports_feature( $product, 'subscription_management_add_to_subscription_product_single' ) ) {
			wp_send_json( $failure );
		}

		$scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $scheme_key );

		// We expect a scheme key to be posted when it's only possible to add the product to matching subscriptions.
		if ( ! $scheme && WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_options_product_single' ) ) {
			wp_send_json( $failure );
		}

		/**
		 * 'wcsatt_subscriptions_matching_product' filter.
		 *
		 * Last chance to filter matched subscriptions.
		 *
		 * @param  array                $matching_subscriptions
		 * @param  WC_Product           $product
		 * @param  WCS_ATT_Scheme|null  $scheme
		 */
		$matching_subscriptions = apply_filters( 'wcsatt_subscriptions_matching_product', WCS_ATT_Manage_Add::get_matching_subscriptions( $scheme ), $product, $scheme );

		ob_start();

		/**
		 * 'wcsatt_add_product_to_subscription_html' action.
		 *
		 * @param  array                $matching_subscriptions
		 * @param  WC_Product           $product
		 * @param  WCS_ATT_Scheme|null  $scheme
		 *
		 * @hooked WCS_ATT_Manage_Add_Product::matching_subscriptions_template - 10
		 */
		do_action( 'wcsatt_add_product_to_subscription_html', $matching_subscriptions, $product, $scheme );

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

	/*
	|--------------------------------------------------------------------------
	| Form Handlers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Adds products to subscriptions after validating.
	 */
	public static function form_handler() {

		$posted_data = WCS_ATT_Manage_Add::get_posted_data( 'product' );

		if ( empty( $posted_data['product_id'] ) ) {
			return;
		}

		if ( empty( $posted_data['subscription_id'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $posted_data['nonce'], 'wcsatt_add_product_to_subscription' ) ) {
			return;
		}

		$product_id      = $posted_data['product_id'];
		$subscription_id = $posted_data['subscription_id'];
		$subscription    = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			wc_add_notice( sprintf( __( 'Subscription #%d cannot be edited. Please get in touch with us for assistance.', 'woocommerce-subscriptions' ), $subscription_id ), 'error' );
			return;
		}

		/*
		 * Relay form validation to 'WC_Form_Handler::add_to_cart_action'.
		 * Use 'woocommerce_add_to_cart_validation' filter to:
		 *
		 * - Let WC validate the form.
		 * - If invalid, stop.
		 * - If valid, add the validated product to the selected subscription.
		 */

		self::$add_to_subscription_args = false;

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_subscription_validation' ), 9999, 5 );

		/**
		 * 'wcsatt_pre_add_product_to_subscription_validation' action.
		 *
		 * @param  int  $product_id
		 * @param  int  $subscription_id
		 */
		do_action( 'wcsatt_pre_add_product_to_subscription_validation', $product_id, $subscription_id );

		$_REQUEST['add-to-cart'] = $product_id;

		// No worries, nothing gets added to the cart at this point.
		WC_Form_Handler::add_to_cart_action();

		// Disarm 'WC_Form_Handler::add_to_cart_action'.
		$_REQUEST['add-to-cart'] = false;

		/**
		 * 'wcsatt_post_add_product_to_subscription_validation' action.
		 *
		 * @param  int  $product_id
		 * @param  int  $subscription_id
		 */
		do_action( 'wcsatt_post_add_product_to_subscription_validation', $product_id, $subscription_id );

		// Remove filter.
		remove_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_subscription_validation' ), 9999 );

		// Validation passed?
		if ( ! self::$add_to_subscription_args ) {
			return;
		}

		// At this point we've got the green light to proceed.
		$subscription_scheme = $posted_data['subscription_scheme'];
		$product             = self::$add_to_subscription_args['product'];
		$args                = array_diff_key( self::$add_to_subscription_args, array( 'product' => 1 ) );

		// Keep the sneaky folks out.
		if ( ! WCS_ATT_Product::supports_feature( $product, 'subscription_management_add_to_subscription_product_single' ) ) {
			return;
		}

		// A subscription scheme key should be posted already if we are supposed to do any matching.
		if ( WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_options_product_single' ) ) {

			if ( empty( $subscription_scheme ) ) {
				return;
			}

			$subscription_scheme_object = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $subscription_scheme );

			if ( empty( $subscription_scheme_object ) ) {
				return;
			}

			// Disable syncing. If we don't do it at this point 'WCS_ATT_Manage_Add::add_cart_subscription' starts to behave in weird ways.
			if ( $subscription_scheme_object->is_synced() ) {
				$subscription_scheme_object->set_sync_date( 0 );
			}

			// Extract the scheme details from the subscription and create a dummy scheme.
		} else {

			$subscription_scheme_object = new WCS_ATT_Scheme(
				array(
					'context' => 'product',
					'data'    => array(
						'subscription_period'          => $subscription->get_billing_period(),
						'subscription_period_interval' => $subscription->get_billing_interval(),
					),
				)
			);

			$subscription_scheme = $subscription_scheme_object->get_key();

			WCS_ATT_Product_Schemes::set_subscription_schemes( $product, array( $subscription_scheme => $subscription_scheme_object ) );
		}

		// Set scheme on product object for later reference.
		WCS_ATT_Product_Schemes::set_subscription_scheme( $product, $subscription_scheme );

		try {

			/**
			 * 'wcsatt_add_product_to_subscription' action.
			 *
			 * @param  WC_Subscription  $subscription
			 * @param  WC_Product       $product
			 * @param  array            $args
			 *
			 * @hooked WCS_ATT_Manage_Add::add_product_to_subscription - 10
			 */
			do_action( 'wcsatt_add_product_to_subscription', $subscription, $product, $args );

		} catch ( Exception $e ) {

			if ( $notice = $e->getMessage() ) {

				wc_add_notice( $notice, 'error' );
				return false;
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Form Handling Hooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Signals 'form_handler' that validation failed.
	 * Data is exchanged via the 'add_product_to_subscription' static prop.
	 * Always returns false to ensure nothing gets added to the cart.
	 *
	 * @param  boolean $result
	 * @param  int     $product_id
	 * @param  mixed   $quantity
	 * @param  int     $variation_id
	 * @param  array   $variation_data
	 * @return bool
	 */
	public static function add_to_subscription_validation( $result, $product_id, $quantity, $variation_id = 0, $variation_data = array() ) {

		if ( $result ) {

			$product = wc_get_product( $variation_id ? $variation_id : $product_id );

			/*
			 * Validate stock.
			 */

			if ( ! $product->is_in_stock() ) {
				wc_add_notice( sprintf( __( '&quot;%s&quot; is out of stock.', 'woocommerce-subscriptions' ), $product->get_name() ), 'error' );
				return false;
			}

			if ( ! $product->has_enough_stock( $quantity ) ) {
				/* translators: 1: product name 2: quantity in stock */
				wc_add_notice( sprintf( __( '&quot;%1$s&quot; does not have enough stock (%2$s remaining).', 'woocommerce-subscriptions' ), $product->get_name(), wc_format_stock_quantity_for_display( $product->get_stock_quantity(), $product ) ), 'error' );
				return false;
			}

			/*
			 * Flash the green light.
			 */

			self::$add_to_subscription_args = array(
				'product'      => $product,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
				'variation'    => $variation_data,
			);
		}

		return false;
	}
}
