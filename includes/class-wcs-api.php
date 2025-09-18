<?php
/**
 * WooCommerce Subscriptions API
 *
 * Handles WC-API endpoint requests related to Subscriptions
 *
 * @author   Prospress
 * @since    2.0
 */

use Automattic\Jetpack\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_API {

	public static function init() {
		add_filter( 'woocommerce_api_classes', array( __CLASS__, 'includes' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 15 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route_overrides' ), 15 );
		add_action( 'woocommerce_rest_set_order_item', array( __CLASS__, 'add_sign_up_fee_to_order_item' ), 15, 2 );
	}

	/**
	 * Include the required files for the REST API and add register the subscription
	 * API class in the WC_API_Server.
	 *
	 * @since 2.0
	 * @param Array $wc_api_classes WC_API::registered_resources list of api_classes
	 * @return array
	 */
	public static function includes( $wc_api_classes ) {

		if ( ! defined( 'WC_API_REQUEST_VERSION' ) || 3 == WC_API_REQUEST_VERSION ) {
			array_push( $wc_api_classes, 'WC_API_Subscriptions' );
			array_push( $wc_api_classes, 'WC_API_Subscriptions_Customers' );
		}

		return $wc_api_classes;
	}

	/**
	 * Load the new REST API subscription endpoints
	 *
	 * @since 2.1
	 */
	public static function register_routes() {

		if ( ! self::is_wp_compatible() ) {
			return;
		}

		$endpoint_classes = array(
			// V1
			'WC_REST_Subscriptions_V1_Controller',
			'WC_REST_Subscription_Notes_V1_Controller',
			// V2
			'WC_REST_Subscriptions_V2_Controller',
			'WC_REST_Subscription_Notes_V2_Controller',
			// V3 (latest)
			'WC_REST_Subscriptions_Controller',
			'WC_REST_Subscription_notes_Controller',
			'WC_REST_Subscriptions_Settings_Option_Controller',
		);

		foreach ( $endpoint_classes as $class ) {
			// @phpstan-ignore class.nameCase
			$controller = new $class();
			$controller->register_routes();
		}
	}

	/**
	 * Register classes which override base endpoints.
	 *
	 * @since 3.1.0
	 */
	public static function register_route_overrides() {
		if ( ! self::is_wp_compatible() ) {
			return;
		}

		WC_REST_Subscription_System_Status_Manager::init();
		new WC_REST_Subscriptions_Settings();
	}

	/**
	 * Adds sign-up fees to order items added/edited via the REST API.
	 *
	 * @since 6.3.0
	 *
	 * @param WC_Order_Item_Product $item              Order item object.
	 * @param array                 $item_request_data Data posted to the API about the order item.
	 */
	public static function add_sign_up_fee_to_order_item( $item, $item_request_data = array() ) {
		if ( 'line_item' !== $item->get_type() || ! self::is_orders_api_request() ) {
			return;
		}

		// If the request includes an item subtotal or total, we don't want to override the provided total.
		if ( isset( $item_request_data['subtotal'] ) || isset( $item_request_data['total'] ) ) {
			return;
		}

		$product = $item->get_product();

		if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			return;
		}

		$sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( $product );
		$sign_up_fee = is_numeric( $sign_up_fee ) ? (float) $sign_up_fee : 0;

		if ( 0 < $sign_up_fee ) {
			// Recalculate the totals as in `prepare_line_items`, but including the sign up fee in the price.
			$trial_length = WC_Subscriptions_Product::get_trial_length( $product );

			if ( $trial_length > 0 ) {
				$price = $sign_up_fee;
			} else {
				$price = (float) $product->get_price() + $sign_up_fee;
			}

			$total = wc_get_price_excluding_tax(
				$product,
				array(
					'qty'   => $item->get_quantity(),
					'price' => $price,
				)
			);

			$item->set_total( $total );
			$item->set_subtotal( $total );
		}
	}

	/**
	 * Determines if a WP version compatible with REST API requests.
	 *
	 * @since 3.1.0
	 * @return boolean
	 */
	protected static function is_wp_compatible() {
		global $wp_version;
		return version_compare( $wp_version, '4.4', '>=' );
	}

	/**
	 * Determines if the current request is a REST API request for orders.
	 *
	 * @since 6.3.0
	 *
	 * @return boolean
	 */
	protected static function is_orders_api_request() {
		if ( ! Constants::is_true( 'REST_REQUEST' ) || empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return false;
		}

		return (bool) preg_match( '/\/wc\/v[1-3]\/orders\b/', $GLOBALS['wp']->query_vars['rest_route'] );
	}

	/**
	 * Fetches WooCommerce API endpoint data in a WooCommerce version compatible way.
	 *
	 * This method is a wrapper for the WooCommerce API get_endpoint_data method. In WooCommerce 9.0.0 and later, the
	 * WC()->api was deprecated in favor of the new Automattic\WooCommerce\Utilities\RestApiUtil class.
	 *
	 * @since 6.4.1
	 *
	 * @param string $endpoint The endpoint to get data for.
	 * @return array|\WP_Error The endpoint data or WP_Error if the request fails.
	 */
	public static function get_wc_api_endpoint_data( $endpoint ) {
		if ( wcs_is_woocommerce_pre( '9.0.0' ) ) {
			// @phpstan-ignore-next-line Call to deprecated method.
			return WC()->api->get_endpoint_data( $endpoint );
		}

		return wc_get_container()->get( Automattic\WooCommerce\Utilities\RestApiUtil::class )->get_endpoint_data( $endpoint );
	}
}
