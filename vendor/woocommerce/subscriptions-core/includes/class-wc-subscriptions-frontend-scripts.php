<?php
/**
 * Enqueues WC Subscriptions frontend scripts.
 *
 * @package WooCommerce Subscriptions
 * @since 3.1.3
 */

defined( 'ABSPATH' ) || exit;

class WC_Subscriptions_Frontend_Scripts {

	/**
	 * Attach hooks and callbacks to enqueue frontend scripts and styles.
	 */
	public static function init() {
		add_filter( 'woocommerce_enqueue_styles', array( __CLASS__, 'enqueue_styles' ), 100, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ), 3 );
	}

	/**
	 * Gets the plugin URL for an assets file.
	 *
	 * @since 3.1.3
	 * @return string The file URL.
	 */
	public static function get_file_url( $file_relative_url = '' ) {
		return WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( $file_relative_url );
	}

	/**
	 * Enqueues scripts for frontend.
	 *
	 * @since 3.1.3
	 */
	public static function enqueue_scripts() {
		$dependencies = array( 'jquery' );

		if ( is_cart() || is_checkout() ) {
			wp_enqueue_script( 'wcs-cart', self::get_file_url( 'assets/js/frontend/wcs-cart.js' ), $dependencies, WC_Subscriptions_Core_Plugin::instance()->get_plugin_version(), true );
		} elseif ( is_product() ) {
			wp_enqueue_script( 'wcs-single-product', self::get_file_url( 'assets/js/frontend/single-product.js' ), $dependencies, WC_Subscriptions_Core_Plugin::instance()->get_plugin_version(), true );
		} elseif ( wcs_is_view_subscription_page() ) {
			$subscription = wcs_get_subscription( absint( get_query_var( 'view-subscription' ) ) );

			if ( $subscription && current_user_can( 'view_order', $subscription->get_id() ) ) {
				$dependencies[] = 'jquery-blockui';
				$script_params  = array(
					'ajax_url'               => esc_url( WC()->ajax_url() ),
					'subscription_id'        => $subscription->get_id(),
					'add_payment_method_msg' => __( 'To enable automatic renewals for this subscription, you will first need to add a payment method.', 'woocommerce-subscriptions' ) . "\n\n" . __( 'Would you like to add a payment method now?', 'woocommerce-subscriptions' ),
					'auto_renew_nonce'       => WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $subscription ) ? wp_create_nonce( "toggle-auto-renew-{$subscription->get_id()}" ) : false,
					'add_payment_method_url' => esc_url( $subscription->get_change_payment_method_url() ),
					'has_payment_gateway'    => $subscription->has_payment_gateway() && wc_get_payment_gateway_by_order( $subscription )->supports( 'subscriptions' ),
				);

				wp_enqueue_script( 'wcs-view-subscription', self::get_file_url( 'assets/js/frontend/view-subscription.js' ), $dependencies, WC_Subscriptions_Core_Plugin::instance()->get_plugin_version(), true );
				wp_localize_script( 'wcs-view-subscription', 'WCSViewSubscription', apply_filters( 'woocommerce_subscriptions_frontend_view_subscription_script_parameters', $script_params ) );
			}
		}
	}

	/**
	 * Enqueues stylesheets.
	 *
	 * @since 3.1.3
	 */
	public static function enqueue_styles( $styles ) {

		if ( is_checkout() || is_cart() ) {
			$styles['wcs-checkout'] = array(
				'src'     => str_replace( array( 'http:', 'https:' ), '', self::get_file_url( 'assets/css/checkout.css' ) ),
				'deps'    => 'wc-checkout',
				'version' => WC_VERSION,
				'media'   => 'all',
			);
		} elseif ( is_account_page() ) {
			$styles['wcs-view-subscription'] = array(
				'src'     => str_replace( array( 'http:', 'https:' ), '', self::get_file_url( 'assets/css/view-subscription.css' ) ),
				'deps'    => 'woocommerce-smallscreen',
				'version' => WC_Subscriptions_Core_Plugin::instance()->get_plugin_version(),
				'media'   => 'all',
			);
		}

		return $styles;
	}
}
