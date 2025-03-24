<?php
/**
 * REST API System Status Endpoint Manager.
 *
 * Adds additional subscription-related data to the /wc/<version>/system_status endpoint.
 *
 * @package WooCommerce Subscriptions\Rest Api
 * @since   3.1.0
 */

defined( 'ABSPATH' ) || exit;

class WC_REST_Subscription_System_Status_Manager {

	/**
	 * Attach callbacks.
	 */
	public static function init() {
		add_filter( 'woocommerce_rest_prepare_system_status', array( __CLASS__, 'add_subscription_fields_to_response' ) );
		add_filter( 'woocommerce_rest_system_status_schema', array( __CLASS__, 'add_additional_fields_to_schema' ) );
	}

	/**
	 * Adds subscription fields to System Status response.
	 *
	 * @since 3.1.0
	 * @deprecated 4.8.0
	 *
	 * @param WP_REST_Response $response The base system status response.
	 * @return WP_REST_Response
	 */
	public static function add_subscription_fields_to_reponse( $response ) {
		wcs_deprecated_function( __METHOD__, '4.8.0', __CLASS__ . '::add_subscription_fields_to_response' );
		$response->data['subscriptions'] = array(
			'wcs_debug'                        => defined( 'WCS_DEBUG' ) ? WCS_DEBUG : false,
			'mode'                             => ( WCS_Staging::is_duplicate_site() ) ? __( 'staging', 'woocommerce-subscriptions' ) : __( 'live', 'woocommerce-subscriptions' ),
			'live_url'                         => esc_url( WCS_Staging::get_site_url_from_source( 'subscriptions_install' ) ),
			'statuses'                         => array_filter( (array) wp_count_posts( 'shop_subscription' ) ),
			'report_cache_enabled'             => ( 'yes' === get_option( 'woocommerce_subscriptions_cache_updates_enabled', 'yes' ) ),
			'cache_update_failures'            => absint( get_option( 'woocommerce_subscriptions_cache_updates_failures', 0 ) ),
			'subscriptions_by_payment_gateway' => WCS_Admin_System_Status::get_subscriptions_by_gateway(),
			'payment_gateway_feature_support'  => self::get_payment_gateway_feature_support(),
		);

		return $response;
	}

	/**
	 * Adds subscription fields to System Status response.
	 *
	 * @since 4.8.0
	 *
	 * @param WP_REST_Response $response The base system status response.
	 * @return WP_REST_Response
	 */
	public static function add_subscription_fields_to_response( $response ) {
		$count_by_status = WCS_Admin_System_Status::get_subscription_status_counts();

		$response->data['subscriptions'] = array(
			'wcs_debug'                        => defined( 'WCS_DEBUG' ) ? WCS_DEBUG : false,
			'mode'                             => ( WCS_Staging::is_duplicate_site() ) ? __( 'staging', 'woocommerce-subscriptions' ) : __( 'live', 'woocommerce-subscriptions' ),
			'live_url'                         => esc_url( WCS_Staging::get_site_url_from_source( 'subscriptions_install' ) ),
			'statuses'                         => array_map( 'strval', $count_by_status ), // Enforce values as strings.
			'report_cache_enabled'             => ( 'yes' === get_option( 'woocommerce_subscriptions_cache_updates_enabled', 'yes' ) ),
			'cache_update_failures'            => absint( get_option( 'woocommerce_subscriptions_cache_updates_failures', 0 ) ),
			'subscriptions_by_payment_gateway' => WCS_Admin_System_Status::get_subscriptions_by_gateway(),
			'payment_gateway_feature_support'  => self::get_payment_gateway_feature_support(),
		);

		return $response;
	}

	/**
	 * Gets the store's payment gateways and the features they support.
	 *
	 * @since 3.1.0
	 * @return array Payment gateway and their features.
	 */
	private static function get_payment_gateway_feature_support() {
		$gateway_features = array();

		foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway_id => $gateway ) {
			// Some gateways include array keys. For consistency, only send the values.
			$gateway_features[ $gateway_id ] = array_values( (array) apply_filters( 'woocommerce_subscriptions_payment_gateway_features_list', $gateway->supports, $gateway ) );

			if ( 'paypal' === $gateway_id && WCS_PayPal::are_reference_transactions_enabled() ) {
				$gateway_features[ $gateway_id ][] = 'paypal_reference_transactions';
			}
		}

		return $gateway_features;
	}

	/**
	 * Adds subscription system status fields the system status schema.
	 *
	 * @since 3.1.0
	 * @param array $schema
	 *
	 * @return array the system status schema.
	 */
	public static function add_additional_fields_to_schema( $schema ) {

		$schema['properties']['subscriptions'] = array(
			array(
				'description' => __( 'Subscriptions.', 'woocommerce-subscriptions' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'readonly'    => true,
				'properties'  => array(
					'wcs_debug_enabled'                => array(
						'description' => __( 'WCS debug constant.', 'woocommerce-subscriptions' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'mode'                             => array(
						'description' => __( 'Subscriptions Mode', 'woocommerce-subscriptions' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'live_url'                         => array(
						'description' => __( 'Subscriptions Live Site URL', 'woocommerce-subscriptions' ),
						'type'        => 'string',
						'format'      => 'uri',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'statuses'                         => array(
						'description' => __( 'Subscriptions broken down by status.', 'woocommerce-subscriptions' ),
						'type'        => 'array',
						'context'     => array( 'view' ),
						'readonly'    => true,
						'items'       => array(
							'type' => 'string',
						),
					),
					'report_cache_enabled'             => array(
						'description' => __( 'Whether the Report Cache is enabled.', 'woocommerce-subscriptions' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'cache_update_failures'            => array(
						'description' => __( 'Number of report cache failures.', 'woocommerce-subscriptions' ),
						'type'        => 'integer',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'subscriptions_by_payment_gateway' => array(
						'description' => __( 'Subscriptions by Payment Gateway.', 'woocommerce-subscriptions' ),
						'type'        => 'array',
						'context'     => array( 'view' ),
						'readonly'    => true,
						'items'       => array(
							'type' => 'string',
						),
					),
					'payment_gateway_feature_support'  => array(
						'description' => __( 'Payment Gateway Feature Support.', 'woocommerce-subscriptions' ),
						'type'        => 'array',
						'context'     => array( 'view' ),
						'readonly'    => true,
						'items'       => array(
							'type' => 'string',
						),
					),
				),
			),
		);

		return $schema;
	}
}
