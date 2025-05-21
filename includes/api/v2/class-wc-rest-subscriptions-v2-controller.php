<?php
/**
 * REST API Subscriptions V2 Controller
 *
 * Handles requests to the wc/v2/subscriptions and wc/v2/orders/ID/subscriptions endpoint.
 *
 * @since 6.4.0
 * @package WooCommerce Subscriptions\Rest Api
 */
defined( 'ABSPATH' ) || exit;

class WC_REST_Subscriptions_V2_Controller extends WC_REST_Orders_V2_Controller {

	/**
	 * @var string Route base.
	 */
	protected $rest_base = 'subscriptions';

	/**
	 * @var string The post type.
	 */
	protected $post_type = 'shop_subscription';

	/**
	 * Register the routes for the subscriptions endpoint.
	 *
	 * GET|POST       /subscriptions
	 * GET|PUT|DELETE /subscriptions/<subscription_id>
	 * GET            /subscriptions/status
	 * GET            /subscriptions/<subscription_id>/orders
	 * POST           /orders/<order_id>/subscriptions
	 *
	 * @since 6.4.0
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route( $this->namespace, "/{$this->rest_base}/statuses", [ // nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true  -- /subscriptions/statuses is a public endpoint and doesn't need any permission checks.
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_statuses' ],
				'permission_callback' => '__return_true',
			],
			'schema' => [ $this, 'get_statuses_schema' ],
		] );

		register_rest_route( $this->namespace, "/{$this->rest_base}/(?P<id>[\d]+)/orders", [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_subscription_orders' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
				'args'                => $this->get_collection_params(),
			],
			'schema' => [ $this, 'get_subscription_orders_schema' ],
		] );

		register_rest_route( $this->namespace, "/orders/(?P<id>[\d]+)/{$this->rest_base}", [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_subscriptions_from_order' ],
				'permission_callback' => [ $this, 'create_item_permissions_check' ],
				'args'                => $this->get_collection_params(),
			],
			'schema' => [ $this, 'create_subscriptions_from_order_schema' ],
		] );
	}

	/**
	 * Gets the request object. Return false if the ID is not a subscription.
	 *
	 * @since 6.4.0
	 *
	 * @param int $id Object ID.
	 *
	 * @return WC_Subscription|bool
	 */
	protected function get_object( $id ) {
		$subscription = wcs_get_subscription( $id );

		if ( ! $subscription || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return false;
		}

		return $subscription;
	}

	/**
	 * Prepare a single subscription output for response.
	 *
	 * @since 6.4.0
	 *
	 * @param WC_Subscription $object  Subscription object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_object_for_response( $object, $request ) {
		$response = parent::prepare_object_for_response( $object, $request );

		// When generating the `/subscriptions/[id]/orders` response this function is called to generate related-order data so exit early if this isn't a subscription.
		if ( ! wcs_is_subscription( $object ) ) {
			return $response;
		}

		// Add subscription specific data to the base order response data.
		$response->data['billing_period']   = $object->get_billing_period();
		$response->data['billing_interval'] = $object->get_billing_interval();

		foreach ( wcs_get_subscription_date_types() as $date_type => $date_name ) {
			$date = $object->get_date( wcs_normalise_date_type_key( $date_type ) );
			$response->data[ $date_type . '_date' ]     = ( ! empty( $date ) ) ? wc_rest_prepare_date_response( $date, false ) : '';
			$response->data[ $date_type . '_date_gmt' ] = ( ! empty( $date ) ) ? wc_rest_prepare_date_response( $date ) : '';
		}

		// Some base WC_Order dates need to be pulled from the subscription object to be correct.
		$response->data['date_paid']          = wc_rest_prepare_date_response( $object->get_date_paid(), false );
		$response->data['date_paid_gmt']      = wc_rest_prepare_date_response( $object->get_date_paid() );
		$response->data['date_completed']     = wc_rest_prepare_date_response( $object->get_date_completed(), false );
		$response->data['date_completed_gmt'] = wc_rest_prepare_date_response( $object->get_date_completed() );

		// Include resubscribe data.
		$resubscribed_subscriptions                  = array_filter( $object->get_related_orders( 'ids', 'resubscribe' ), 'wcs_is_subscription' );
		$response->data['resubscribed_from']         = strval( $object->get_meta( '_subscription_resubscribe' ) );
		$response->data['resubscribed_subscription'] = strval( reset( $resubscribed_subscriptions ) ); // Subscriptions can only be resubscribed to once so return the first and only element.

		// Include the removed line items.
		$response->data['removed_line_items'] = [];

		foreach ( $object->get_items( 'line_item_removed' ) as $item ) {
			$response->data['removed_line_items'][] = $this->get_order_item_data( $item );
		}

		// Remove non-subscription properties
		unset( $response->data['cart_hash'] );
		unset( $response->data['transaction_id'] );

		return $response;
	}

	/**
	 * Gets the /subscriptions/statuses response.
	 *
	 * @since 6.4.0
	 *
	 * @return WP_REST_Response The response object.
	 */
	public function get_statuses() {
		return rest_ensure_response( wcs_get_subscription_statuses() );
	}

	/**
	 * Gets the /subscriptions/[id]/orders response.
	 *
	 * @since 6.4.0
	 *
	 * @param WP_REST_Request            $request  The request object.
	 *
	 * @return WP_Error|WP_REST_Response $response The response or an error if one occurs.
	 */
	public function get_subscription_orders( $request ) {
		$id = absint( $request['id'] );

		if ( empty( $id ) || ! wcs_is_subscription( $id ) ) {
			return new WP_Error( 'woocommerce_rest_invalid_shop_subscription_id', __( 'Invalid subscription ID.', 'woocommerce-subscriptions' ), [ 'status' => 404 ] );
		}

		$subscription = wcs_get_subscription( $id );

		if ( ! $subscription ) {
			return new WP_Error( 'woocommerce_rest_invalid_shop_subscription_id', sprintf( __( 'Failed to load subscription object with the ID %d.', 'woocommerce-subscriptions' ), $id ), [ 'status' => 404 ] );
		}

		$orders = [];

		foreach ( [ 'parent', 'renewal', 'switch' ] as $order_type ) {
			foreach ( $subscription->get_related_orders( 'ids', $order_type ) as $order_id ) {

				if ( ! wc_rest_check_post_permissions( 'shop_order', 'read', $order_id ) ) {
					continue;
				}

				// Validate that the order can be loaded before trying to generate a response object for it.
				$order = wc_get_order( $order_id );

				if ( ! $order ) {
					continue;
				}

				$response = parent::prepare_object_for_response( $order, $request );

				// Add the order's relationship to the response.
				$response->data['order_type'] = $order_type . '_order';

				$orders[] = $this->prepare_response_for_collection( $response );
			}
		}

		$response = rest_ensure_response( $orders );
		$response->header( 'X-WP-Total', count( $orders ) );
		$response->header( 'X-WP-TotalPages', 1 );

		return apply_filters( 'wcs_rest_subscription_orders_response', $response, $request );
	}

	/**
	 * Overrides WC_REST_Orders_V2_Controller::get_order_statuses() so that subscription statuses are
	 * validated correctly.
	 *
	 * @since 6.4.0
	 *
	 * @return array An array of valid subscription statuses.
	 */
	protected function get_order_statuses() {
		$subscription_statuses = [];

		foreach ( wcs_get_subscription_statuses() as $status => $status_name ) {
			$subscription_statuses[] = str_replace( 'wc-', '', $status );
		}

		return $subscription_statuses;
	}

	/**
	 * Prepares a single subscription for creation or update.
	 *
	 * @since 6.4.0
	 *
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating If the request is for creating a new object.
	 *
	 * @return WP_Error|WC_Subscription
	 */
	public function prepare_object_for_database( $request, $creating = false ) {
		$id           = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$subscription = new WC_Subscription( $id );
		$schema       = $this->get_item_schema();
		$data_keys    = array_keys( array_filter( $schema['properties'], [ $this, 'filter_writable_props' ] ) );

		// Prepare variables for properties which need to be saved late (like status) or in a group (dates and payment data).
		$status         = '';
		$payment_method = '';
		$payment_meta   = [];
		$dates          = [];

		// Both setting (set_status()) and updating (update_status()) are valid ways for requests to set a subscription's status.
		$status_transition = 'set';

		foreach ( $data_keys as $i => $key ) {
			$value = $request[ $key ];

			if ( is_null( $value ) ) {
				continue;
			}

			switch ( $key ) {
				case 'parent_id':
					$subscription->set_parent_id( $value );
					break;
				case 'transition_status':
					$status_transition = 'update';
				case 'status':
					// This needs to be done later so status changes take into account other data like dates.
					$status = $value;
					break;
				case 'billing':
				case 'shipping':
					$this->update_address( $subscription, $value, $key );
					break;
				case 'start_date':
				case 'trial_end_date':
				case 'next_payment_date':
				case 'cancelled_date':
				case 'end_date':
					// Group all the subscription date properties so they can be validated together.
					$dates[ $key ] = $value;
					break;
				case 'payment_method':
					$payment_method = $value;
					break;
				case 'payment_details':
					// Format the value in a way payment gateways expect so it can be validated.
					$payment_meta = $value;
					break;
				case 'line_items':
				case 'shipping_lines':
				case 'fee_lines':
					if ( is_array( $value ) ) {
						foreach ( $value as $item ) {
							if ( is_array( $item ) ) {
								if ( $this->item_is_null( $item ) || ( isset( $item['quantity'] ) && 0 === $item['quantity'] ) ) {
									if ( isset( $item['id'] ) ) {
										$subscription->remove_item( $item['id'] );
									}
								} else {
									$this->set_item( $subscription, $key, $item );
								}
							}
						}
					}
					break;
				case 'meta_data':
					if ( is_array( $value ) ) {
						foreach ( $value as $meta ) {
							$subscription->update_meta_data( $meta['key'], $meta['value'], isset( $meta['id'] ) ? $meta['id'] : '' );
						}
					}
					break;
				default:
					if ( is_callable( [ $subscription, "set_{$key}" ] ) ) {
						$subscription->{"set_{$key}"}( $value );
					}
					break;
			}
		}

		if ( ! empty( $payment_method ) ) {
			$this->update_payment_method( $subscription, $payment_method, $payment_meta );
		}

		if ( ! empty( $dates ) ) {
			// If the start date is not set in the request when a subscription is being created, set its default to now.
			if ( empty( $id ) && ! isset( $dates['start_date'] ) ) {
				$dates['start_date'] = gmdate( 'Y-m-d H:i:s' );
			}

			try {
				$subscription->update_dates( $dates );
			} catch ( Exception $e ) {
				throw new WC_REST_Exception( 'woocommerce_rest_invalid_payment_data', sprintf( __( 'Subscription dates could not be set. Error message: %s', 'woocommerce-subscriptions' ), $e->getMessage() ), 400 );
			}
		}

		if ( ! empty( $status ) ) {
			if ( 'set' === $status_transition ) {
				$subscription->set_status( $status );
			} else {
				$subscription->update_status( $status );
				$request['status'] = $status; // Set the request status so parent::save_object() doesn't set it to the default 'pending' status.
			}
		}

		/**
		 * Filters an object before it is inserted via the REST API.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`,
		 * refers to the object type slug.
		 *
		 * @param WC_Subscription $subscription The subscription object.
		 * @param WP_REST_Request $request      Request object.
		 * @param bool            $creating     If is creating a new object.
		 */
		return apply_filters( "woocommerce_rest_pre_insert_{$this->post_type}_object", $subscription, $request, $creating );
	}

	/**
	 * Adds additional item schema information for subscription requests.
	 *
	 * @since 6.4.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		// If this is a request for a subscription's orders, return the subscription orders schema.
		if ( $this->request instanceof WP_REST_Request && preg_match( "#/{$this->rest_base}/(?P<id>[\d]+)/orders#", $this->request->get_route() ) ) {
			return $this->get_subscription_orders_schema();
		}

		$schema = parent::get_item_schema();

		// Base order schema overrides.
		$schema['properties']['status']['description'] = __( 'Subscription status.', 'woocommerce-subscriptions' );
		$schema['properties']['status']['enum']        = $this->get_order_statuses();

		$schema['properties']['created_via']['description']       = __( 'Where the subscription was created.', 'woocommerce-subscriptions' );
		$schema['properties']['currency']['description']          = __( 'Currency the subscription was created with, in ISO format.', 'woocommerce-subscriptions' );
		$schema['properties']['date_created']['description']      = __( "The date the subscription was created, in the site's timezone.", 'woocommerce-subscriptions' );
		$schema['properties']['date_created_gmt']['description']  = __( 'The date the subscription was created, as GMT.', 'woocommerce-subscriptions' );
		$schema['properties']['date_modified']['description']     = __( "The date the subscription was last modified, in the site's timezone.", 'woocommerce-subscriptions' );
		$schema['properties']['date_modified_gmt']['description'] = __( 'The date the subscription was last modified, as GMT.', 'woocommerce-subscriptions' );
		$schema['properties']['customer_id']['description']       = __( 'User ID who owns the subscription.', 'woocommerce-subscriptions' );

		unset( $schema['properties']['transaction_id'] );
		unset( $schema['properties']['refunds'] );
		unset( $schema['properties']['set_paid'] );
		unset( $schema['properties']['cart_hash'] );

		// Add subscription schema.
		$schema['properties'] += [
			'transition_status' => [
				'description' => __( 'The status to transition a subscription to.', 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'context'     => [ 'edit' ],
				'enum'        => $this->get_order_statuses(),
			],
			'billing_interval' => [
				'description' => __( 'The number of billing periods between subscription renewals.', 'woocommerce-subscriptions' ),
				'type'        => 'integer',
				'context'     => [ 'view', 'edit' ],
			],
			'billing_period' => [
				'description' => __( 'Billing period for the subscription.', 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'enum'        => array_keys( wcs_get_subscription_period_strings() ),
				'context'     => [ 'view', 'edit' ],
			],
			'payment_details' => [
				'description' => __( 'Subscription payment details.', 'woocommerce-subscriptions' ),
				'type'        => 'object',
				'context'     => [ 'edit' ],
				'properties' => [
					'post_meta' => [
						'description' => __( 'Payment method meta and token in a post_meta_key: token format.', 'woocommerce-subscriptions' ),
						'type'        => 'object',
						'context'     => [ 'edit' ],
					],
					'user_meta' => [
						'description' => __( 'Payment method meta and token in a user_meta_key : token format.', 'woocommerce-subscriptions' ),
						'type'        => 'object',
						'context'     => [ 'view' ],
					],
				],
			],
			'start_date' => [
				'description' => __( "The subscription's start date, as GMT.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => [ 'view', 'edit' ],
			],
			'trial_end_date' => [
				'description' => __( "The subscription's trial end date, as GMT.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => [ 'view', 'edit' ],
			],
			'next_payment_date' => [
				'description' => __( "The subscription's next payment date, as GMT.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => [ 'view', 'edit' ],
			],
			'cancelled_date' => [
				'description' => __( "The subscription's cancelled date, as GMT.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => [ 'view', 'edit' ],
			],
			'end_date' => [
				'description' => __( "The subscription's end date, as GMT.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => [ 'view', 'edit' ],
			],
		];

		return $schema;
	}

	/**
	 * Get the query params for collections.
	 *
	 * @since 6.4.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		// Override the base order status description to be subscription specific.
		$params['status']['description'] = __( 'Limit result set to subscriptions which have specific statuses.', 'woocommerce-subscriptions' );
		return $params;
	}

	/**
	 * Gets an object's links to include in the response.
	 *
	 * Because this class also handles retrieving order data, we need
	 * to edit the links generated so the correct REST API href is included
	 * when its generated for an order.
	 *
	 * @since 6.4.0
	 *
	 * @param WC_Data         $object  Object data.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array                   Links for the given object.
	 */
	protected function prepare_links( $object, $request ) {
		$links = parent::prepare_links( $object, $request );

		if ( isset( $links['self'] ) && wcs_is_order( $object ) ) {
			$links['self'] = [
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, 'orders', $object->get_id() ) ),
			];
		}

		return $links;
	}

	/**
	 * Updates a subscription's payment method and meta from data provided in a REST API request.
	 *
	 * @since 6.4.0
	 *
	 * @param WC_Subscription $subscription   The subscription to update.
	 * @param string          $payment_method The ID of the payment method to set.
	 * @param array           $payment_meta   The payment method meta.
	 *
	 * @return void
	 */
	public function update_payment_method( $subscription, $payment_method, $payment_meta ) {
		$updating_subscription = (bool) $subscription->get_id();

		try {
			if ( $updating_subscription && ! array_key_exists( $payment_method, WCS_Change_Payment_Method_Admin::get_valid_payment_methods( $subscription ) ) ) {
				// translators: placeholder is the payment method ID.
				throw new Exception( sprintf( __( 'The %s payment gateway does not support admin changing the payment method.', 'woocommerce-subscriptions' ), $payment_method ) );
			}

			// Format the payment meta in the way payment gateways expect so it can be validated.
			$payment_method_meta = [];

			foreach ( $payment_meta as $table => $meta ) {
				foreach ( $meta as $meta_key => $value ) {
					$payment_method_meta[ $table ][ $meta_key ] = [ 'value' => $value ];
				}
			}

			$subscription->set_payment_method( $payment_method, $payment_method_meta );
		} catch ( Exception $e ) {
			$subscription->set_payment_method();
			$subscription->save();
			// translators: 1$: gateway id, 2$: error message
			throw new WC_REST_Exception( 'woocommerce_rest_invalid_payment_data', sprintf( __( 'Subscription payment method could not be set to %1$s with error message: %2$s', 'woocommerce-subscriptions' ), $payment_method, $e->getMessage() ), 400 );
		}
	}

	/**
	 * Creates subscriptions from an order.
	 *
	 * @since 6.4.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array Subscriptions created from the order.
	 */
	public function create_subscriptions_from_order( $request ) {
		$order_id = absint( $request->get_param( 'id' ) );

		if ( empty( $order_id ) ) {
			return new WP_Error( 'woocommerce_rest_order_invalid_id', __( 'Invalid order ID.', 'woocommerce-subscriptions' ), [ 'status' => 404 ] );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! wcs_is_order( $order ) ) {
			return new WP_Error( 'woocommerce_rest_order_invalid_id', sprintf( __( 'Failed to load order object with the ID %d.', 'woocommerce-subscriptions' ), $order_id ), [ 'status' => 404 ] );
		}

		if ( ! $order->get_customer_id() ) {
			return new WP_Error( 'woocommerce_rest_invalid_order', __( 'Order does not have a customer associated with it. Subscriptions require a customer.', 'woocommerce-subscriptions' ), [ 'status' => 404 ] );
		}

		if ( wcs_order_contains_subscription( $order, 'any' ) ) {
			return new WP_Error( 'woocommerce_rest_invalid_order', __( 'Order already has subscriptions associated with it.', 'woocommerce-subscriptions' ), [ 'status' => 404 ] );
		}

		$subscription_groups = [];
		$subscriptions       = [];

		// Group the order items into subscription groups.
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
				continue;
			}

			$subscription_groups[ wcs_get_subscription_item_grouping_key( $item ) ][] = $item;
		}

		// Return a 204 if there are no subscriptions to be created.
		if ( empty( $subscription_groups ) ) {
			$response = rest_ensure_response( $subscriptions );
			$response->set_status( 204 );
			return $response;
		}

		/**
		 * Start creating any subscriptions start transaction if available.
		 *
		 * To ensure data integrity, if any subscription fails to be created, the transaction will be rolled back. This will enable
		 * the client to resubmit the request without having to worry about duplicate subscriptions being created.
		 */
		$transaction = new WCS_SQL_Transaction();
		$transaction->start();

		try {
			// Create subscriptions.
			foreach ( $subscription_groups as $items ) {
				// Get the first item in the group to use as the base for the subscription.
				$product      = $items[0]->get_product();
				$start_date   = wcs_get_datetime_utc_string( $order->get_date_created( 'edit' ) );
				$subscription = wcs_create_subscription( [
					'order_id'           => $order_id,
					'created_via'        => 'rest-api',
					'start_date'         => $start_date,
					'status'             => $order->is_paid() ? 'active' : 'pending',
					'billing_period'     => WC_Subscriptions_Product::get_period( $product ),
					'billing_interval'   => WC_Subscriptions_Product::get_interval( $product ),
					'customer_note'      => $order->get_customer_note(),
				] );

				if ( is_wp_error( $subscription ) ) {
					throw new Exception( $subscription->get_error_message() );
				}

				wcs_copy_order_address( $order, $subscription );

				$subscription->update_dates(
					[
						'trial_end'    => WC_Subscriptions_Product::get_trial_expiration_date( $product, $start_date ),
						'next_payment' => WC_Subscriptions_Product::get_first_renewal_payment_date( $product, $start_date ),
						'end'          => WC_Subscriptions_Product::get_expiration_date( $product, $start_date ),
					]
				);

				$subscription->set_payment_method( $order->get_payment_method() );

				wcs_copy_order_meta( $order, $subscription, 'subscription' );

				// Add items.
				$subscription_needs_shipping = false;
				foreach ( $items as $item ) {
					// Create order line item.
					$item_id = wc_add_order_item(
						$subscription->get_id(),
						[
							'order_item_name' => $item->get_name(),
							'order_item_type' => $item->get_type(),
						]
					);

					$subscription_item = $subscription->get_item( $item_id );

					wcs_copy_order_item( $item, $subscription_item );

					// Don't include sign-up fees or $0 trial periods when setting the subscriptions item totals.
					wcs_set_recurring_item_total( $subscription_item );

					$subscription_item->save();

					// Check if this subscription will need shipping.
					if ( ! $subscription_needs_shipping ) {
						$product = $item->get_product();

						if ( $product ) {
							$subscription_needs_shipping = $product->needs_shipping() && ! WC_Subscriptions_Product::needs_one_time_shipping( $product );
						}
					}
				}

				// Add coupons.
				foreach ( $order->get_coupons() as $coupon_item ) {
					$coupon = new WC_Coupon( $coupon_item->get_code() );

					try {
						// validate_subscription_coupon_for_order will throw an exception if the coupon cannot be applied to the subscription.
						WC_Subscriptions_Coupon::validate_subscription_coupon_for_order( true, $coupon, $subscription );

						$subscription->apply_coupon( $coupon->get_code() );
					} catch ( Exception $e ) {
						// Do nothing. The coupon will not be applied to the subscription.
					}
				}

				// Add shipping.
				if ( $subscription_needs_shipping ) {
					foreach ( $order->get_shipping_methods() as $shipping_item ) {
						$rate = new WC_Shipping_Rate( $shipping_item->get_method_id(), $shipping_item->get_method_title(), $shipping_item->get_total(), $shipping_item->get_taxes(), $shipping_item->get_instance_id() );

						$item = new WC_Order_Item_Shipping();
						$item->set_order_id( $subscription->get_id() );
						$item->set_shipping_rate( $rate );

						$subscription->add_item( $item );
					}
				}

				// Add fees.
				foreach ( $order->get_fees() as $fee_item ) {
					if ( ! apply_filters( 'wcs_should_copy_fee_item_to_subscription', true, $fee_item, $subscription, $order ) ) {
						continue;
					}

					$item = new WC_Order_Item_Fee();
					$item->set_props(
						[
							'name'      => $fee_item->get_name(),
							'tax_class' => $fee_item->get_tax_class(),
							'amount'    => $fee_item->get_amount(),
							'total'     => $fee_item->get_total(),
							'total_tax' => $fee_item->get_total_tax(),
							'taxes'     => $fee_item->get_taxes(),
						]
					);

					$subscription->add_item( $item );
				}


				/*
				 * Fetch a fresh instance of the subscription because the current instance has an empty line item cache generated before we had copied the line items.
				 * Fetching a new instance will ensure the line items are used when calculating totals.
				 */
				$subscription = wcs_get_subscription( $subscription->get_id() );
				$subscription->calculate_totals();

				/**
				 * Fires after a single subscription is created or updated via the REST API.
				 *
				 * @param WC_Subscription $object   Inserted subscription.
				 * @param WP_REST_Request $request  Request object.
				 * @param boolean         $creating True when creating object, false when updating.
				 */
				do_action( "woocommerce_rest_insert_{$this->post_type}_object", $subscription, $request, true );

				$response = $this->prepare_object_for_response( wcs_get_subscription( $subscription->get_id() ), $request );
				$subscriptions[] = $this->prepare_response_for_collection( $response );
			}
		} catch ( Exception $e ) {
			$transaction->rollback();
			return new WP_Error( 'woocommerce_rest_invalid_subscription_data', $e->getMessage(), [ 'status' => 404 ] );
		}

		// If we got here, the subscription was created without problems
		$transaction->commit();

		return rest_ensure_response( $subscriptions );
	}

	/**
	 * Subscriptions statuses schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_statuses_schema() {
		$schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'shop_subscription statuses', // Use a unique title for the schema so that CLI commands aren't overridden.
			'type'       => 'object',
			'properties' => [],
		];

		// Add the subscription statuses to the schema.
		foreach ( wcs_get_subscription_statuses() as $status => $status_name ) {
			$schema['properties'][ $status ] = [
				'type'        => 'string',
				'description' => sprintf( __( 'Subscription status: %s', 'woocommerce-subscription' ), $status_name ),
			];
		}

		return $schema;
	}

	/**
	 * Subscriptions orders schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_subscription_orders_schema() {
		$schema = parent::get_item_schema(); // Fetch the order schema.
		$schema['title']                    = 'shop_subscription orders'; // Use a unique title for the schema so that CLI commands aren't overridden.
		$schema['properties']['order_type'] = [
			'type'        => 'string',
			'description' => __( 'The type of order related to the subscription.', 'woocommerce-subscriptions' ),
		];

		return $schema;
	}

	/**
	 * Subscriptions schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function create_subscriptions_from_order_schema() {
		$schema = $this->get_public_item_schema();
		$schema['title'] = 'shop_order subscriptions'; // Use a unique title for the schema so that CLI commands aren't overridden and we can target this endpoint specifically.

		return $schema;
	}
}
