<?php
/**
 * REST API Subscriptions controller
 *
 * Handles requests to the /subscription endpoint.
 *
 * @author   Prospress
 * @since    2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Subscriptions controller class.
 *
 * @package WooCommerce_Subscriptions/API
 */
class WC_REST_Subscriptions_Controller extends WC_REST_Orders_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'subscriptions';

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type = 'shop_subscription';

	/**
	 * Initialize subscription actions and filters
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_prepare_shop_subscription', array( $this, 'filter_get_subscription_response' ), 10, 3 );

		add_filter( 'woocommerce_rest_shop_subscription_query', array( $this, 'query_args' ), 10, 2 );

		add_filter( 'woocommerce_rest_pre_insert_shop_subscription', array( $this, 'prepare_subscription_args' ), 10, 2 );
	}

	/**
	 * Register the routes for subscriptions.
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/orders', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_orders' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			),
			'schema' => array( $this, 'get_public_item_schema' ),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/statuses', array( // nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true  -- /subscriptions/statuses is a public endpoint and doesn't need any permission checks.
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_statuses' ),
				'permission_callback' => '__return_true',
			),
			'schema' => array( $this, 'get_public_item_schema' ),
		) );
	}

	/**
	 * Filter WC_REST_Orders_Controller::get_item response for subscription post types
	 *
	 * @since 2.1
	 * @param WP_REST_Response $response
	 * @param WP_Post $post
	 * @param WP_REST_Request $request
	 */
	public function filter_get_subscription_response( $response, $post, $request ) {

		if ( ! empty( $post->post_type ) && ! empty( $post->ID ) && 'shop_subscription' == $post->post_type ) {
			$subscription = wcs_get_subscription( $post->ID );

			$response->data['billing_period']    = $subscription->get_billing_period();
			$response->data['billing_interval']  = $subscription->get_billing_interval();
			$response->data['start_date']        = wc_rest_prepare_date_response( $subscription->get_date( 'start_date' ) );
			$response->data['trial_end_date']    = wc_rest_prepare_date_response( $subscription->get_date( 'trial_end' ) );
			$response->data['next_payment_date'] = wc_rest_prepare_date_response( $subscription->get_date( 'next_payment' ) );
			$response->data['end_date']          = wc_rest_prepare_date_response( $subscription->get_date( 'end_date' ) );
		}

		return $response;
	}

	/**
	 * Sets the order_total value on the subscription after WC_REST_Orders_Controller::create_order
	 * calls calculate_totals(). This allows store admins to create a recurring payment via the api
	 * without needing to attach a product to the subscription.
	 *
	 * @since 2.1
	 * @param WP_REST_Request $request
	 */
	protected function create_order( $request ) {
		$post_id = parent::create_order( $request );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( isset( $request['order_total'] ) ) {
			update_post_meta( $post_id, '_order_total', wc_format_decimal( $request['order_total'], get_option( 'woocommerce_price_num_decimals' ) ) );
		}

		return $post_id;
	}

	/**
	 * Overrides WC_REST_Orders_Controller::update_order to update subscription specific meta
	 * calls parent::update_order to update the rest.
	 *
	 * @since 2.1
	 * @param WP_REST_Request $request
	 * @param WP_Post $post
	 */
	protected function update_order( $request, $post ) {
		try {
			$post_id = parent::update_order( $request, $post );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$subscription = wcs_get_subscription( $post_id );
			$this->update_schedule( $subscription, $request );

			$payment_data = ( ! empty( $request['payment_details'] ) ) ? $request['payment_details'] : array();
			$existing_payment_method_id = $subscription->get_payment_method();

			if ( empty( $payment_data['method_id'] ) && isset( $request['payment_method'] ) ) {
				$payment_data['method_id'] = $request['payment_method'];

			} elseif ( ! empty( $existing_payment_method_id ) ) {
				$payment_data['method_id'] = $existing_payment_method_id;
			}

			if ( isset( $payment_data['method_id'] ) ) {
				$this->update_payment_method( $subscription, $payment_data, true );
			}

			return $post_id;
		} catch ( WC_REST_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'woocommerce_rest_cannot_update_subscription', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get subscription orders
	 *
	 * @since 2.1
	 * @param WP_REST_Request $request
	 * @return WP_Error|WP_REST_Response $response
	 */
	public function get_subscription_orders( $request ) {
		$id = absint( $request['id'] );

		if ( empty( $id ) || ! wcs_is_subscription( $id ) ) {
			return new WP_Error( 'woocommerce_rest_invalid_shop_subscription_id', __( 'Invalid subscription id.', 'woocommerce-subscriptions' ), array( 'status' => 404 ) );
		}

		$this->post_type     = 'shop_order';
		$subscription        = wcs_get_subscription( $id );
		$subscription_orders = $subscription->get_related_orders();

		$orders = array();

		foreach ( $subscription_orders as $order_id ) {
			$post = get_post( $order_id );
			if ( ! wc_rest_check_post_permissions( $this->post_type, 'read', $post->ID ) ) {
				continue;
			}

			$response = $this->prepare_item_for_response( $post, $request );

			foreach ( array( 'parent', 'renewal', 'switch' ) as $order_type ) {
				if ( wcs_order_contains_subscription( $order_id, $order_type ) ) {
					$response->data['order_type'] = $order_type . '_order';
					break;
				}
			}

			$orders[] = $this->prepare_response_for_collection( $response );
		}

		$response = rest_ensure_response( $orders );
		$response->header( 'X-WP-Total', count( $orders ) );
		$response->header( 'X-WP-TotalPages', 1 );

		return apply_filters( 'wcs_rest_subscription_orders_response', $response, $request );
	}

	/**
	 * Get subscription statuses
	 *
	 * @since 2.1
	 */
	public function get_statuses() {
		return rest_ensure_response( wcs_get_subscription_statuses() );
	}

	/**
	 * Overrides WC_REST_Orders_Controller::get_order_statuses() so that subscription statuses are
	 * validated correctly in WC_REST_Orders_Controller::get_collection_params()
	 *
	 * @since 2.1
	 */
	protected function get_order_statuses() {
		$subscription_statuses = array();

		foreach ( array_keys( wcs_get_subscription_statuses() ) as $status ) {
			$subscription_statuses[] = str_replace( 'wc-', '', $status );
		}
		return $subscription_statuses;
	}

	/**
	 * Create WC_Subscription object.
	 *
	 * @since 2.1
	 * @param array $args subscription args.
	 * @return WC_Subscription
	 */
	protected function create_base_order( $args ) {
		$subscription = wcs_create_subscription( $args );

		if ( is_wp_error( $subscription ) ) {
			// translators: placeholder is an error message.
			throw new WC_REST_Exception( 'woocommerce_rest_cannot_create_subscription', sprintf( __( 'Cannot create subscription: %s.', 'woocommerce-subscriptions' ), implode( ', ', $subscription->get_error_messages() ) ), 400 );
		}

		$this->update_schedule( $subscription, $args );

		if ( empty( $args['payment_details']['method_id'] ) && ! empty( $args['payment_method'] ) ) {
			$args['payment_details']['method_id'] = $args['payment_method'];
		}

		$this->update_payment_method( $subscription, $args['payment_details'] );

		return $subscription;
	}

	/**
	 * Update or set the subscription schedule with the request data
	 *
	 * @since 2.1
	 * @param WC_Subscription $subscription
	 * @param array $data
	 */
	public function update_schedule( $subscription, $data ) {
		if ( isset( $data['billing_interval'] ) ) {
			update_post_meta( $subscription->get_id(), '_billing_interval', absint( $data['billing_interval'] ) );
		}

		if ( ! empty( $data['billing_period'] ) ) {
			update_post_meta( $subscription->get_id(), '_billing_period', $data['billing_period'] );
		}

		try {
			$dates_to_update = array();

			foreach ( array( 'start', 'trial_end', 'end', 'next_payment' ) as $date_type ) {
				if ( isset( $data[ $date_type . '_date' ] ) ) {
					$date_type_key = ( 'start' === $date_type ) ? 'date_created' : $date_type;
					$dates_to_update[ $date_type_key ] = $data[ $date_type . '_date' ];
				}
			}

			if ( ! empty( $dates_to_update ) ) {
				$subscription->update_dates( $dates_to_update );
			}
		} catch ( Exception $e ) {
			// translators: placeholder is an error message.
			throw new WC_REST_Exception( 'woocommerce_rest_cannot_update_subscription_dates', sprintf( __( 'Updating subscription dates errored with message: %s', 'woocommerce-subscriptions' ), $e->getMessage() ), 400 );
		}
	}

	/**
	 * Validate and update payment method on a subscription
	 *
	 * @since 2.1
	 * @param WC_Subscription $subscription
	 * @param array $data
	 * @param bool $updating
	 */
	public function update_payment_method( $subscription, $data, $updating = false ) {
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$payment_method   = ( ! empty( $data['method_id'] ) ) ? $data['method_id'] : 'manual';
		$payment_gateway  = ( ! empty( $payment_gateways[ $payment_method ] ) ) ? $payment_gateways[ $payment_method ] : '';

		try {
			if ( $updating && ! array_key_exists( $payment_method, WCS_Change_Payment_Method_Admin::get_valid_payment_methods( $subscription ) ) ) {
				throw new Exception( __( 'Gateway does not support admin changing the payment method on a Subscription.', 'woocommerce-subscriptions' ) );
			}

			$payment_method_meta = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );

			if ( ! empty( $payment_gateway ) && isset( $payment_method_meta[ $payment_gateway->id ] ) ) {
				$payment_method_meta = $payment_method_meta[ $payment_gateway->id ];

				if ( ! empty( $payment_method_meta ) ) {

					foreach ( $payment_method_meta as $meta_table => &$meta ) {
						if ( ! is_array( $meta ) ) {
							continue;
						}

						foreach ( $meta as $meta_key => &$meta_data ) {

							if ( isset( $data[ $meta_table ][ $meta_key ] ) ) {
								$meta_data['value'] = $data[ $meta_table ][ $meta_key ];
							}
						}
					}
				}
			}

			$subscription->set_payment_method( $payment_gateway, $payment_method_meta );

		} catch ( Exception $e ) {
			$subscription->set_payment_method();
			// translators: 1$: gateway id, 2$: error message
			throw new WC_REST_Exception( 'woocommerce_rest_invalid_payment_data', sprintf( __( 'Subscription payment method could not be set to %1$s with error message: %2$s', 'woocommerce-subscriptions' ), $payment_method, $e->getMessage() ), 400 );
		}
	}

	/**
	 * Adds additional item schema information for subscription requests
	 *
	 * @since 2.1
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		$subscriptions_schema = array(
			'billing_interval'  => array(
				'description' => __( 'The number of billing periods between subscription renewals.', 'woocommerce-subscriptions' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
			),
			'billing_period'    => array(
				'description' => __( 'Billing period for the subscription.', 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'enum'        => array_keys( wcs_get_subscription_period_strings() ),
				'context'     => array( 'view', 'edit' ),
			),
			'payment_details'   => array(
				'description' => __( 'Subscription payment details.', 'woocommerce-subscriptions' ),
				'type'        => 'object',
				'context'     => array( 'edit' ),
				'properties'  => array(
					'method_id' => array(
						'description' => __( 'Payment gateway ID.', 'woocommerce-subscriptions' ),
						'type'        => 'string',
						'context'     => array( 'edit' ),
					),
				),
			),
			'start_date'        => array(
				'description' => __( "The subscription's start date.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
			'trial_date'        => array(
				'description' => __( "The subscription's trial date", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
			'next_payment_date' => array(
				'description' => __( "The subscription's next payment date.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
			'end_date'          => array(
				'description' => __( "The subscription's end date.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
		);

		$schema['properties'] += $subscriptions_schema;
		return $schema;
	}

	/**
	 * Prepare subscription data for create.
	 *
	 * @since 2.1
	 * @param stdClass $data
	 * @param WP_REST_Request $request Request object.
	 * @return stdClass
	 */
	public function prepare_subscription_args( $data, $request ) {
		$data->billing_interval = $request['billing_interval'];
		$data->billing_period   = $request['billing_period'];

		foreach ( array( 'start', 'trial_end', 'end', 'next_payment' ) as $date_type ) {
			if ( ! empty( $request[ $date_type . '_date' ] ) ) {
				$date_type_key = ( 'start' === $date_type ) ? 'date_created' : $date_type . '_date';
				$data->{$date_type_key} = $request[ $date_type . '_date' ];
			}
		}

		$data->payment_details = ! empty( $request['payment_details'] ) ? $request['payment_details'] : '';
		$data->payment_method  = ! empty( $request['payment_method'] ) ? $request['payment_method'] : '';

		return $data;
	}
}
