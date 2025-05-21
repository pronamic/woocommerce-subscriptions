<?php
/**
 * WooCommerce Subscriptions Query Handler
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 * @author  Prospress
 */
class WCS_Query extends WC_Query {

	public function __construct() {

		add_action( 'init', array( $this, 'add_endpoints' ) );

		add_filter( 'the_title', array( $this, 'change_endpoint_title' ), 11, 1 );

		if ( ! is_admin() ) {
			add_filter( 'query_vars', array( $this, 'add_query_vars' ), 0 );
			add_action( 'parse_request', array( $this, 'parse_request' ), 0 );
			add_action( 'pre_get_posts', array( $this, 'maybe_redirect_payment_methods' ) );
			add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ), 11 );
			add_filter( 'woocommerce_get_query_vars', array( $this, 'add_wcs_query_vars' ) );

			// Inserting your new tab/page into the My Account page.
			add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_items' ) );

			// Since WC 3.3.0, add_wcs_query_vars() is enough for custom endpoints to work.
			if ( wcs_is_woocommerce_pre( '3.3.0' ) ) {
				add_filter( 'woocommerce_get_endpoint_url', array( $this, 'get_endpoint_url' ), 10, 4 );
			}

			add_filter( 'woocommerce_get_endpoint_url', array( $this, 'maybe_redirect_to_only_subscription' ), 10, 2 );
			add_action( 'woocommerce_account_subscriptions_endpoint', array( $this, 'endpoint_content' ) );
			add_filter( 'woocommerce_account_menu_item_classes', array( $this, 'maybe_add_active_class' ), 10, 2 );

			add_filter( 'woocommerce_endpoint_subscriptions_title', array( $this, 'change_my_account_endpoint_title' ), 10, 2 );
			add_filter( 'woocommerce_endpoint_view-subscription_title', array( $this, 'change_my_account_endpoint_title' ), 10, 2 );
		}

		$this->init_query_vars();

		if ( wcs_is_woocommerce_pre( '3.4' ) ) {
			add_filter( 'woocommerce_account_settings', array( $this, 'add_endpoint_account_settings' ) );
		} else {
			add_filter( 'woocommerce_get_settings_advanced', array( $this, 'add_endpoint_account_settings' ) );
		}
	}

	/**
	 * Init query vars by loading options.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function init_query_vars() {
		$this->query_vars = array(
			'view-subscription' => $this->get_view_subscription_endpoint(),
		);
		if ( ! wcs_is_woocommerce_pre( '2.6' ) ) {
			$this->query_vars['subscriptions']               = get_option( 'woocommerce_myaccount_subscriptions_endpoint', 'subscriptions' );
			$this->query_vars['subscription-payment-method'] = get_option( 'woocommerce_myaccount_subscription_payment_method_endpoint', 'subscription-payment-method' );
		}
	}

	/**
	 * Changes page title on view subscription page
	 *
	 * @param  string $title original title
	 * @return string        changed title
	 */
	public function change_endpoint_title( $title ) {

		if ( in_the_loop() && is_account_page() ) {
			foreach ( $this->query_vars as $key => $query_var ) {
				if ( $this->is_query( $query_var ) ) {
					$title = $this->get_endpoint_title( $key );

					// unhook after we've returned our title to prevent it from overriding others
					remove_filter( 'the_title', array( $this, __FUNCTION__ ), 11 );
				}
			}
		}
		return $title;
	}

	/**
	 * Hooks onto `woocommerce_endpoint_{$endpoint}_title` to return the correct page title for subscription endpoints
	 * in My Account.
	 *
	 * @param string $title
	 * @param string $endpoint
	 * @return string
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.10
	 */
	public function change_my_account_endpoint_title( $title, $endpoint ) {
		global $wp;

		switch ( $endpoint ) {
			case 'view-subscription':
				$subscription = wcs_get_subscription( $wp->query_vars['view-subscription'] );
				// translators: placeholder is a subscription ID.
				$title = ( $subscription ) ? sprintf( _x( 'Subscription #%s', 'hash before order number', 'woocommerce-subscriptions' ), $subscription->get_order_number() ) : '';
				break;
			case 'subscriptions':
				if ( ! empty( $wp->query_vars['subscriptions'] ) ) {
					// translators: placeholder is a page number.
					$title = sprintf( __( 'Subscriptions (page %d)', 'woocommerce-subscriptions' ), intval( $wp->query_vars['subscriptions'] ) );
				} else {
					$title = __( 'Subscriptions', 'woocommerce-subscriptions' );
				}

				break;
		}

		return $title;
	}

	/**
	 * Insert the new endpoint into the My Account menu.
	 *
	 * @param array $items
	 * @return array
	 */
	public function add_menu_items( $menu_items ) {

		// If the Subscriptions endpoint setting is empty, don't display it in line with core WC behaviour.
		if ( empty( $this->query_vars['subscriptions'] ) ) {
			return $menu_items;
		}

		if ( 1 === count( wcs_get_users_subscriptions() ) && apply_filters( 'wcs_my_account_redirect_to_single_subscription', true ) ) {
			$label = __( 'My Subscription', 'woocommerce-subscriptions' );
		} else {
			$label = __( 'Subscriptions', 'woocommerce-subscriptions' );
		}

		// Add our menu item after the Orders tab if it exists, otherwise just add it to the end
		if ( array_key_exists( 'orders', $menu_items ) ) {
			$menu_items = wcs_array_insert_after( 'orders', $menu_items, 'subscriptions', $label );
		} else {
			$menu_items['subscriptions'] = $label;
		}

		return $menu_items;
	}

	/**
	 * Changes the URL for the subscriptions endpoint when there's only one user subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.17
	 * @param string $url
	 * @param string $endpoint
	 * @return string
	 */
	public function maybe_redirect_to_only_subscription( $url, $endpoint ) {
		if ( $this->query_vars['subscriptions'] === $endpoint && ( is_account_page() || is_order_received_page() ) ) {
			$subscriptions = wcs_get_users_subscriptions();

			if ( is_array( $subscriptions ) && 1 === count( $subscriptions ) && apply_filters( 'wcs_my_account_redirect_to_single_subscription', true ) ) {
				$subscription = reset( $subscriptions );
				$url          = $subscription->get_view_order_url();
			}
		}

		return $url;
	}

	/**
	 * Endpoint HTML content.
	 *
	 * @param int $current_page
	 */
	public function endpoint_content( $current_page = 1 ) {

		$current_page = empty( $current_page ) ? 1 : absint( $current_page );

		wc_get_template( 'myaccount/subscriptions.php', array( 'current_page' => $current_page ), '', WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' ) );
	}

	/**
	 * Check if the current query is for a type we want to override.
	 *
	 * @param  string $query_var the string for a query to check for
	 * @return bool
	 */
	protected function is_query( $query_var ) {
		global $wp;

		if ( is_main_query() && is_page() && isset( $wp->query_vars[ $query_var ] ) ) {
			$is_view_subscription_query = true;
		} else {
			$is_view_subscription_query = false;
		}

		return apply_filters( 'wcs_query_is_query', $is_view_subscription_query, $query_var );
	}

	/**
	 * Fix for endpoints on the homepage
	 *
	 * Based on WC_Query->pre_get_posts(), but only applies the fix for endpoints on the homepage from it
	 * instead of duplicating all the code to handle the main product query.
	 *
	 * @param mixed $q query object
	 */
	public function pre_get_posts( $q ) {
		// We only want to affect the main query
		if ( ! $q->is_main_query() ) {
			return;
		}

		if ( $q->is_home() && 'page' === get_option( 'show_on_front' ) && absint( get_option( 'page_on_front' ) ) !== absint( $q->get( 'page_id' ) ) ) {
			$_query = wp_parse_args( $q->query );
			if ( ! empty( $_query ) && array_intersect( array_keys( $_query ), array_keys( $this->query_vars ) ) ) {
				$q->is_page     = true;
				$q->is_home     = false;
				$q->is_singular = true;
				$q->set( 'page_id', (int) get_option( 'page_on_front' ) );
				add_filter( 'redirect_canonical', '__return_false' );
			}
		}
	}

	/**
	 * Redirect to order-pay flow for Subscription Payment Method endpoint.
	 *
	 * @param WP_Query $query WordPress query object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public function maybe_redirect_payment_methods( $query ) {

		if ( ! $query->is_main_query() || ! absint( $query->get( 'subscription-payment-method' ) ) ) {
			return;
		}

		$subscription_id = absint( $query->get( 'subscription-payment-method' ) );
		$subscription    = wcs_get_subscription( $subscription_id );

		if ( ! $subscription || ! current_user_can( 'edit_shop_subscription_payment_method', $subscription_id ) ) {
			return;
		}

		if ( ! $subscription->can_be_updated_to( 'new-payment-method' ) ) {

			$url = $subscription->get_view_order_url();
			wc_add_notice( __( 'The payment method can not be changed for that subscription.', 'woocommerce-subscriptions' ), 'error' );

		} else {

			$args = array(
				'change_payment_method' => $subscription->get_id(),
				'_wpnonce'              => wp_create_nonce(),
			);
			$url  = add_query_arg( $args, $subscription->get_checkout_payment_url() );
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit();
	}

	/**
	 * Reset the woocommerce_myaccount_view_subscriptions_endpoint option name to woocommerce_myaccount_view_subscription_endpoint
	 *
	 * @return mixed Value set for the option
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.18
	 */
	private function get_view_subscription_endpoint() {
		$value = get_option( 'woocommerce_myaccount_view_subscriptions_endpoint', null );

		if ( isset( $value ) ) {
			wcs_doing_it_wrong( 'woocommerce_myaccount_view_subscriptions_endpoint', sprintf( '%1$s option is deprecated. Use %2$s option instead.', 'woocommerce_myaccount_view_subscriptions_endpoint', 'woocommerce_myaccount_view_subscription_endpoint' ), '2.2.17' );

			// Update the current option name with the value that was set in the deprecated option name
			update_option( 'woocommerce_myaccount_view_subscription_endpoint', $value );
			// Now that things are upto date, do away with the deprecated option name
			delete_option( 'woocommerce_myaccount_view_subscriptions_endpoint' );
		}

		return get_option( 'woocommerce_myaccount_view_subscription_endpoint', 'view-subscription' );
	}

	/**
	 * Add UI option for changing Subscription endpoints in WC settings
	 *
	 * @param mixed $account_settings
	 * @return mixed $account_settings
	 */
	public function add_endpoint_account_settings( $settings ) {
		$subscriptions_endpoint_setting = array(
			'title'    => __( 'Subscriptions', 'woocommerce-subscriptions' ),
			'desc'     => __( 'Endpoint for the My Account &rarr; Subscriptions page', 'woocommerce-subscriptions' ),
			'id'       => 'woocommerce_myaccount_subscriptions_endpoint',
			'type'     => 'text',
			'default'  => 'subscriptions',
			'desc_tip' => true,
		);

		$view_subscription_endpoint_setting = array(
			'title'    => __( 'View subscription', 'woocommerce-subscriptions' ),
			'desc'     => __( 'Endpoint for the My Account &rarr; View Subscription page', 'woocommerce-subscriptions' ),
			'id'       => 'woocommerce_myaccount_view_subscription_endpoint',
			'type'     => 'text',
			'default'  => 'view-subscription',
			'desc_tip' => true,
		);

		$subscription_payment_method_endpoint_setting = array(
			'title'    => __( 'Subscription payment method', 'woocommerce-subscriptions' ),
			'desc'     => __( 'Endpoint for the My Account &rarr; Change Subscription Payment Method page', 'woocommerce-subscriptions' ),
			'id'       => 'woocommerce_myaccount_subscription_payment_method_endpoint',
			'type'     => 'text',
			'default'  => 'subscription-payment-method',
			'desc_tip' => true,
		);

		WC_Subscriptions_Admin::insert_setting_after( $settings, 'woocommerce_myaccount_view_order_endpoint', array( $subscriptions_endpoint_setting, $view_subscription_endpoint_setting, $subscription_payment_method_endpoint_setting ), 'multiple_settings' );
		return $settings;
	}

	/**
	 * Get endpoint URL.
	 *
	 * Gets the URL for an endpoint, which varies depending on permalink settings.
	 *
	 * @param  string $endpoint
	 * @param  string $value
	 * @param  string $permalink
	 *
	 * @return string $url
	 */

	public function get_endpoint_url( $url, $endpoint, $value = '', $permalink = '' ) {

		if ( ! empty( $this->query_vars[ $endpoint ] ) ) {
			remove_filter( 'woocommerce_get_endpoint_url', array( $this, 'get_endpoint_url' ) );
			$url = wc_get_endpoint_url( $this->query_vars[ $endpoint ], $value, $permalink );
			add_filter( 'woocommerce_get_endpoint_url', array( $this, 'get_endpoint_url' ), 10, 4 );
		}
		return $url;
	}

	/**
	 * Hooks into `woocommerce_get_query_vars` to make sure query vars defined in
	 * this class are also considered `WC_Query` query vars.
	 *
	 * @param  array $query_vars
	 * @return array
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public function add_wcs_query_vars( $query_vars ) {
		return array_merge( $query_vars, $this->query_vars );
	}

	/**
	 * Adds `is-active` class to Subscriptions label when we're viewing a single Subscription.
	 *
	 * @param array  $classes  The classes present in the current endpoint.
	 * @param string $endpoint The endpoint/label we're filtering.
	 *
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.6
	 */
	public function maybe_add_active_class( $classes, $endpoint ) {
		if ( ! isset( $classes['is-active'] ) && 'subscriptions' === $endpoint && wcs_is_view_subscription_page() ) {
			$classes[] = 'is-active';
		}

		return $classes;
	}

	/**
	 * Adds endpoint breadcrumb when viewing subscription.
	 *
	 * Deprecated as we now use the `woocommerce_endpoint_{$endpoint}_title` hook which automatically integrates with
	 * breadcrumb generation.
	 *
	 * @param  array $crumbs already assembled breadcrumb data
	 * @return array $crumbs if we're on a view-subscription page, then augmented breadcrumb data
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.10
	 */
	public function add_breadcrumb( $crumbs ) {
		_deprecated_function( __METHOD__, '3.0.10' );

		foreach ( $this->query_vars as $key => $query_var ) {
			if ( $this->is_query( $query_var ) ) {
				$crumbs[] = array( $this->get_endpoint_title( $key ) );
			}
		}
		return $crumbs;
	}
}
