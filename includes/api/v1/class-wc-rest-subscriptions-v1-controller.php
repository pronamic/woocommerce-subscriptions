<?php
/**
 * REST API Subscriptions controller
 *
 * Handles requests to the /subscriptions endpoint.
 *
 * @package WooCommerce Subscriptions\Rest Api
 * @author  WooCommerce
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Subscriptions controller class.
 *
 * @package WooCommerce_Subscriptions/API
 */
class WC_REST_Subscriptions_V1_Controller extends WC_REST_Orders_V1_Controller {

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
		$decimal_places = is_null( $request['dp'] ) ? wc_get_price_decimals() : absint( $request['dp'] );

		if ( ! empty( $post->post_type ) && ! empty( $post->ID ) && 'shop_subscription' == $post->post_type ) {
			$subscription = wcs_get_subscription( $post->ID );

			if ( ! $subscription ) {
				return $response;
			}

			$response->data['billing_period']   = $subscription->get_billing_period();
			$response->data['billing_interval'] = $subscription->get_billing_interval();

			// Send resubscribe data
			$resubscribed_subscriptions                  = array_filter( $subscription->get_related_orders( 'ids', 'resubscribe' ), 'wcs_is_subscription' );
			$response->data['resubscribed_from']         = strval( wcs_get_objects_property( $subscription, 'subscription_resubscribe' ) );
			$response->data['resubscribed_subscription'] = strval( reset( $resubscribed_subscriptions ) ); // Subscriptions can only be resubscribed to once so return the first and only element.

			foreach ( array( 'start', 'trial_end', 'next_payment', 'end' ) as $date_type ) {
				$date = $subscription->get_date( $date_type );
				$response->data[ $date_type . '_date' ] = ( ! empty( $date ) ) ? wc_rest_prepare_date_response( $date ) : '';
			}

			// v1 API includes some date types in site time, include those dates in UTC as well.
			$response->data['date_completed_gmt'] = wc_rest_prepare_date_response( $subscription->get_date_completed() );
			$response->data['date_paid_gmt']      = wc_rest_prepare_date_response( $subscription->get_date_paid() );
			$response->data['removed_line_items'] = array();

			// Include removed line items of a subscription
			foreach ( $subscription->get_items( 'line_item_removed' ) as $item_id => $item ) {
				$product      = $item->get_product();
				$product_id   = 0;
				$variation_id = 0;
				$product_sku  = null;

				// Check if the product exists.
				if ( is_object( $product ) ) {
					$product_id   = $item->get_product_id();
					$variation_id = $item->get_variation_id();
					$product_sku  = $product->get_sku();
				}

				$item_meta = array();

				$hideprefix = 'true' === $request['all_item_meta'] ? null : '_';

				foreach ( $item->get_formatted_meta_data( $hideprefix, true ) as $meta_key => $formatted_meta ) {
					$item_meta[] = array(
						'key'   => $formatted_meta->key,
						'label' => $formatted_meta->display_key,
						'value' => wc_clean( $formatted_meta->display_value ),
					);
				}

				$line_item = array(
					'id'           => $item_id,
					'name'         => $item['name'],
					'sku'          => $product_sku,
					'product_id'   => (int) $product_id,
					'variation_id' => (int) $variation_id,
					'quantity'     => wc_stock_amount( $item['qty'] ),
					'tax_class'    => ! empty( $item['tax_class'] ) ? $item['tax_class'] : '',
					'price'        => wc_format_decimal( $subscription->get_item_total( $item, false, false ), $decimal_places ),
					'subtotal'     => wc_format_decimal( $subscription->get_line_subtotal( $item, false, false ), $decimal_places ),
					'subtotal_tax' => wc_format_decimal( $item['line_subtotal_tax'], $decimal_places ),
					'total'        => wc_format_decimal( $subscription->get_line_total( $item, false, false ), $decimal_places ),
					'total_tax'    => wc_format_decimal( $item['line_tax'], $decimal_places ),
					'taxes'        => array(),
					'meta'         => $item_meta,
				);

				$item_line_taxes = maybe_unserialize( $item['line_tax_data'] );
				if ( isset( $item_line_taxes['total'] ) ) {
					$line_tax = array();

					foreach ( $item_line_taxes['total'] as $tax_rate_id => $tax ) {
						$line_tax[ $tax_rate_id ] = array(
							'id'       => $tax_rate_id,
							'total'    => $tax,
							'subtotal' => '',
						);
					}

					foreach ( $item_line_taxes['subtotal'] as $tax_rate_id => $tax ) {
						$line_tax[ $tax_rate_id ]['subtotal'] = $tax;
					}

					$line_item['taxes'] = array_values( $line_tax );
				}

				$response->data['removed_line_items'][] = $line_item;
			}
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
		try {
			if ( ! is_null( $request['customer_id'] ) && 0 !== $request['customer_id'] && false === get_user_by( 'id', $request['customer_id'] ) ) {
				throw new WC_REST_Exception( 'woocommerce_rest_invalid_customer_id', __( 'Customer ID is invalid.', 'woocommerce-subscriptions' ), 400 );
			}

			// If the start date is not set in the request, set its default to now
			if ( ! isset( $request['start_date'] ) ) {
				$request['start_date'] = gmdate( 'Y-m-d H:i:s' );
			}

			// prepare all subscription data from the request
			$subscription = $this->prepare_item_for_database( $request );
			$subscription->set_created_via( 'rest-api' );
			$subscription->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
			$subscription->calculate_totals();

			// allow the order total to be overriden (i.e. if you want to have a subscription with no order items but a flat $10.00 recurring payment )
			if ( isset( $request['order_total'] ) ) {
				$subscription->set_total( wc_format_decimal( $request['order_total'], get_option( 'woocommerce_price_num_decimals' ) ) );
			}

			// Store the post meta on the subscription after it's saved, this is to avoid compat. issue with the filters in WC_Subscription::set_payment_method_meta() expecting the $subscription to have an ID (therefore it needs to be called after the WC_Subscription has been saved)
			$payment_data = ( ! empty( $request['payment_details'] ) ) ? $request['payment_details'] : array();
			if ( empty( $payment_data['payment_details']['method_id'] ) && ! empty( $request['payment_method'] ) ) {
				$payment_data['method_id'] = $request['payment_method'];
			}

			$this->update_payment_method( $subscription, $payment_data );

			$subscription->save();

			// Handle set paid.
			if ( true === $request['set_paid'] ) {
				$subscription->payment_complete( $request['transaction_id'] );
			}

			do_action( 'wcs_api_subscription_created', $subscription->get_id() );

			return $subscription->get_id();
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
		} catch ( WC_REST_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Overrides WC_REST_Orders_Controller::update_order to update subscription specific meta
	 * calls parent::update_order to update the rest.
	 *
	 * @since 2.1
	 * @param WP_REST_Request $request
	 */
	protected function update_order( $request ) {
		try {
			$subscription = $this->prepare_item_for_database( $request );

			// If any line items have changed, recalculate subscription totals.
			if ( isset( $request['line_items'] ) || isset( $request['shipping_lines'] ) || isset( $request['fee_lines'] ) || isset( $request['coupon_lines'] ) ) {
				$subscription->calculate_totals();
			}

			// allow the order total to be overriden (i.e. if you want to have a subscription with no order items but a flat $10.00 recurring payment )
			if ( isset( $request['order_total'] ) ) {
				$subscription->set_total( wc_format_decimal( $request['order_total'], get_option( 'woocommerce_price_num_decimals' ) ) );
			}

			$subscription->save();

			// Update the post meta on the subscription after it's saved, this is to avoid compat. issue with the filters in WC_Subscription::set_payment_method_meta() expecting the $subscription to have an ID (therefore it needs to be called after the WC_Subscription has been saved)
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

			// Handle set paid.
			if ( $subscription->needs_payment() && true === $request['set_paid'] ) {
				$subscription->payment_complete();
			}

			do_action( 'wcs_api_subscription_updated', $subscription->get_id() );

			return $subscription->get_id();
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
		} catch ( WC_REST_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
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
		$id = (int) $request['id'];

		if ( empty( $id ) || ! wcs_is_subscription( $id ) ) {
			return new WP_Error( 'woocommerce_rest_invalid_shop_subscription_id', __( 'Invalid subscription id.', 'woocommerce-subscriptions' ), array( 'status' => 404 ) );
		}

		$this->post_type = 'shop_order';
		$subscription    = wcs_get_subscription( $id );

		if ( ! $subscription ) {
			return new WP_Error( 'woocommerce_rest_invalid_shop_subscription_id', __( 'Invalid subscription id.', 'woocommerce-subscriptions' ), array( 'status' => 404 ) );
		}

		$subscription_orders = $subscription->get_related_orders();

		$orders = array();

		foreach ( $subscription_orders as $order_id ) {
			// Validate that the order can be loaded before trying to generate a response object for it.
			$order = wc_get_order( $order_id );

			if ( ! $order || ! wc_rest_check_post_permissions( $this->post_type, 'read', $order_id ) ) {
				continue;
			}

			$response = $this->prepare_item_for_response( $order, $request );

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
	 * Validate and update payment method on a subscription
	 *
	 * @since 2.1
	 * @param WC_Subscription $subscription
	 * @param array $data
	 * @param bool $updating
	 */
	public function update_payment_method( $subscription, $data, $updating = false ) {
		$payment_method = ( ! empty( $data['method_id'] ) ) ? $data['method_id'] : '';

		try {
			if ( $updating && ! array_key_exists( $payment_method, WCS_Change_Payment_Method_Admin::get_valid_payment_methods( $subscription ) ) ) {
				throw new Exception( __( 'Gateway does not support admin changing the payment method on a Subscription.', 'woocommerce-subscriptions' ) );
			}

			$payment_method_meta = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );

			// Reload the subscription to update the meta values.
			// In particular, the update_post_meta() called while _stripe_card_id is updated to _stripe_source_id
			$subscription = wcs_get_subscription( $subscription->get_id() );

			if ( ! $subscription ) {
				throw new WC_REST_Exception( 'woocommerce_rest_payment_update_failed', __( 'Subscription payment method could not be set updated due to technical issues.', 'woocommerce-subscriptions' ), 500 );
			}

			if ( isset( $payment_method_meta[ $payment_method ] ) ) {
				$payment_method_meta = $payment_method_meta[ $payment_method ];

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

			$subscription->set_payment_method( $payment_method, $payment_method_meta );

			// Save the subscription to reflect the new values
			$subscription->save();

		} catch ( Exception $e ) {
			$subscription->set_payment_method();
			$subscription->save();
			// translators: 1$: gateway id, 2$: error message
			throw new WC_REST_Exception( 'woocommerce_rest_invalid_payment_data', sprintf( __( 'Subscription payment method could not be set to %1$s with error message: %2$s', 'woocommerce-subscriptions' ), $payment_method, $e->getMessage() ), 400 );
		}
	}

	/**
	 * Prepare a single subscription for create.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_Error|WC_Subscription $data Object.
	 */
	protected function prepare_item_for_database( $request ) {
		$id           = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$subscription = new WC_Subscription( $id );
		$schema       = $this->get_item_schema();
		$data_keys    = array_keys( array_filter( $schema['properties'], array( $this, 'filter_writable_props' ) ) );

		$dates_to_update = array();

		// Handle all writable props
		foreach ( $data_keys as $key ) {
			$value = $request[ $key ];

			if ( ! is_null( $value ) ) {
				switch ( $key ) {
					case 'billing':
					case 'shipping':
						$this->update_address( $subscription, $value, $key );
						break;
					case 'line_items':
					case 'shipping_lines':
					case 'fee_lines':
					case 'coupon_lines':
						if ( is_array( $value ) ) {
							foreach ( $value as $item ) {
								if ( is_array( $item ) ) {
									if ( $this->item_is_null( $item ) || ( isset( $item['quantity'] ) && 0 === $item['quantity'] ) ) {
										$subscription->remove_item( $item['id'] );
									} else {
										$this->set_item( $subscription, $key, $item );
									}
								}
							}
						}
						break;
					case 'transition_status':
						$subscription->update_status( $value );
						break;
					case 'start_date':
					case 'trial_end_date':
					case 'next_payment_date':
					case 'end_date':
						$dates_to_update[ $key ] = $value;
						break;
					default:
						if ( is_callable( array( $subscription, "set_{$key}" ) ) ) {
							$subscription->{"set_{$key}"}( $value );
						}
						break;
				}
			}
		}

		$subscription->save();

		try {
			if ( ! empty( $dates_to_update ) ) {
				$subscription->update_dates( $dates_to_update );
			}
		} catch ( Exception $e ) {
			// translators: placeholder is an error message.
			throw new WC_REST_Exception( 'woocommerce_rest_cannot_update_subscription_dates', sprintf( __( 'Updating subscription dates errored with message: %s', 'woocommerce-subscriptions' ), $e->getMessage() ), 400 );
		}

		/**
		 * Filter the data for the insert.
		 *
		 * The dynamic portion of the hook name, $this->post_type, refers to post_type of the post being
		 * prepared for the response.
		 *
		 * @param WC_Subscription    $subscription   The subscription object.
		 * @param WP_REST_Request    $request        Request object.
		 */
		return apply_filters( "woocommerce_rest_pre_insert_{$this->post_type}", $subscription, $request );
	}

	/**
	 * Adds additional item schema information for subscription requests
	 *
	 * @since 2.1
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		$subscriptions_schema = array(
			'transition_status'         => array(
				'description' => __( 'The status to transition the subscription to. Unlike the "status" param, this will calculate and update the subscription dates.', 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'enum'        => $this->get_order_statuses(),
				'context'     => array( 'edit' ),
			),
			'billing_interval'          => array(
				'description' => __( 'The number of billing periods between subscription renewals.', 'woocommerce-subscriptions' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
			),
			'billing_period'            => array(
				'description' => __( 'Billing period for the subscription.', 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'enum'        => array_keys( wcs_get_subscription_period_strings() ),
				'context'     => array( 'view', 'edit' ),
			),
			'payment_details'           => array(
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
			'start_date'                => array(
				'description' => __( "The subscription's start date.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
			'trial_end_date'            => array(
				'description' => __( "The subscription's trial date", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
			'next_payment_date'         => array(
				'description' => __( "The subscription's next payment date.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
			'end_date'                  => array(
				'description' => __( "The subscription's end date.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view', 'edit' ),
			),
			'resubscribed_from'         => array(
				'description' => __( "The subscription's original subscription ID if this is a resubscribed subscription.", 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'resubscribed_subscription' => array(
				'description' => __( "The subscription's resubscribed subscription ID.", 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'date_completed_gmt'        => array(
				'description' => __( "The date the subscription's latest order was completed, in GMT.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'date_paid_gmt'             => array(
				'description' => __( "The date the subscription's latest order was paid, in GMT.", 'woocommerce-subscriptions' ),
				'type'        => 'date-time',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'removed_line_items'        => array(
				'description' => __( 'Removed line items data.', 'woocommerce-subscriptions' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array(
							'description' => __( 'Item ID.', 'woocommerce-subscriptions' ),
							'type'        => 'integer',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'name'         => array(
							'description' => __( 'Product name.', 'woocommerce-subscriptions' ),
							'type'        => 'mixed',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'sku'          => array(
							'description' => __( 'Product SKU.', 'woocommerce-subscriptions' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'product_id'   => array(
							'description' => __( 'Product ID.', 'woocommerce-subscriptions' ),
							'type'        => 'mixed',
							'context'     => array( 'view', 'edit' ),
						),
						'variation_id' => array(
							'description' => __( 'Variation ID, if applicable.', 'woocommerce-subscriptions' ),
							'type'        => 'integer',
							'context'     => array( 'view', 'edit' ),
						),
						'quantity'     => array(
							'description' => __( 'Quantity ordered.', 'woocommerce-subscriptions' ),
							'type'        => 'integer',
							'context'     => array( 'view', 'edit' ),
						),
						'tax_class'    => array(
							'description' => __( 'Tax class of product.', 'woocommerce-subscriptions' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'price'        => array(
							'description' => __( 'Product price.', 'woocommerce-subscriptions' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'subtotal'     => array(
							'description' => __( 'Line subtotal (before discounts).', 'woocommerce-subscriptions' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'subtotal_tax' => array(
							'description' => __( 'Line subtotal tax (before discounts).', 'woocommerce-subscriptions' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'total'        => array(
							'description' => __( 'Line total (after discounts).', 'woocommerce-subscriptions' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'total_tax'    => array(
							'description' => __( 'Line total tax (after discounts).', 'woocommerce-subscriptions' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'taxes'        => array(
							'description' => __( 'Line taxes.', 'woocommerce-subscriptions' ),
							'type'        => 'array',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array(
										'description' => __( 'Tax rate ID.', 'woocommerce-subscriptions' ),
										'type'        => 'integer',
										'context'     => array( 'view', 'edit' ),
										'readonly'    => true,
									),
									'total'    => array(
										'description' => __( 'Tax total.', 'woocommerce-subscriptions' ),
										'type'        => 'string',
										'context'     => array( 'view', 'edit' ),
										'readonly'    => true,
									),
									'subtotal' => array(
										'description' => __( 'Tax subtotal.', 'woocommerce-subscriptions' ),
										'type'        => 'string',
										'context'     => array( 'view', 'edit' ),
										'readonly'    => true,
									),
								),
							),
						),
						'meta'         => array(
							'description' => __( 'Removed line item meta data.', 'woocommerce-subscriptions' ),
							'type'        => 'array',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'key'   => array(
										'description' => __( 'Meta key.', 'woocommerce-subscriptions' ),
										'type'        => 'string',
										'context'     => array( 'view', 'edit' ),
										'readonly'    => true,
									),
									'label' => array(
										'description' => __( 'Meta label.', 'woocommerce-subscriptions' ),
										'type'        => 'string',
										'context'     => array( 'view', 'edit' ),
										'readonly'    => true,
									),
									'value' => array(
										'description' => __( 'Meta value.', 'woocommerce-subscriptions' ),
										'type'        => 'mixed',
										'context'     => array( 'view', 'edit' ),
										'readonly'    => true,
									),
								),
							),
						),
					),
				),
			),
		);

		$schema['properties'] += $subscriptions_schema;
		return $schema;
	}
}
