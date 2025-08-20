<?php
/**
 * Gifting's integration with `WCS_Query`.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WCSG_Query.
 */
class WCSG_Query extends WCS_Query {

	/**
	 * Setup hooks & filters, when the class is constructed.
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'add_endpoints' ) );

		add_filter( 'the_title', array( $this, 'change_endpoint_title' ), 11, 1 );

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_filter( 'woocommerce_endpoint_new-recipient-account_title', array( $this, 'change_my_account_endpoint_title' ), 10, 2 );
		}

		$this->init_query_vars();
	}

	/**
	 * Init query vars by loading options.
	 */
	public function init_query_vars() {
		$this->query_vars = array(
			'new-recipient-account' => get_option( 'woocommerce_myaccount_view_subscriptions_endpoint', 'new-recipient-account' ),
		);
	}

	/**
	 * Enqueue frontend scripts
	 */
	public function enqueue_scripts() {
		if ( $this->is_query( 'new-recipient-account' ) ) {
			// Enqueue WooCommerce country select scripts.
			wp_enqueue_script( 'wc-country-select' );
			wp_enqueue_script( 'wc-address-i18n' );
		}
	}

	/**
	 * Changes the recipient account details endpoint title.
	 *
	 * Hooked onto the dynamic hook 'woocommerce_endpoint_new-recipient-account_title'.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.2.
	 *
	 * @param string $title Endpoint title.
	 * @param string $endpoint Endpoint.
	 *
	 * @return string The gift recipient account details page title.
	 */
	public function change_my_account_endpoint_title( $title, $endpoint ) {
		return __( 'Account details', 'woocommerce-subscriptions' );
	}

	/* Function Overrides */

	/**
	 * This function is attached to the 'woocommerce_account_menu_items' filter by the @see parent::__construct().
	 * In this context there is no menu items to add so this function is simply overriding the parent instance to avoid it from being called twice.
	 *
	 * @param array $menu_items The My Account menu items.
	 * @deprecated 2.0.0 Because parent::__construct() is no longer called, this function is no longer attached to any filters, no longer called and so no longer needs to be overridden.
	 */
	public function add_menu_items( $menu_items ) {
		_deprecated_function( __METHOD__, '2.0' );
		return $menu_items;
	}

	/**
	 * This function is attached to the 'woocommerce_account_subscriptions_endpoint' action hook by the @see parent::__construct().
	 * In this context there is no subscriptions endpoint content so this function is simply overriding the parent instance to avoid it from being called twice.
	 *
	 * @param int $current_page Current page.
	 * @deprecated 2.0.0 Because parent::__construct() is no longer called, this function is no longer attached to any hooks, no longer called and so no longer needs to be overridden.
	 */
	public function endpoint_content( $current_page = 1 ) {
		_deprecated_function( __METHOD__, '2.0' );
	}
}
new WCSG_Query();
