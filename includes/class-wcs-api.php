<?php
/**
 * WooCommerce Subscriptions API
 *
 * Handles WC-API endpoint requests related to Subscriptions
 *
 * @author   Prospress
 * @since    2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_API {

	public static function init() {
		add_filter( 'woocommerce_api_classes', array( __CLASS__, 'includes' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 15 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route_overrides' ), 15 );
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
			// V3 (latest)
			'WC_REST_Subscriptions_Controller',
			'WC_REST_Subscription_notes_Controller',
		);

		foreach ( $endpoint_classes as $class ) {
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
}
