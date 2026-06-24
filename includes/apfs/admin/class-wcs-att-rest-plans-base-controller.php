<?php
/**
 * REST API Plans Base Controller
 *
 * Base class for subscription plan REST API controllers.
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    9.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base REST API controller for subscription plans.
 *
 * Contains the shared HTTP orchestration: CRUD endpoint handlers, the consistent
 * success/error response envelope, and the try/catch structure. Business logic
 * (validation, ID generation, storage, filter hooks) is delegated to WCS_ATT_Plans_Manager.
 *
 * Child classes are responsible for:
 *  - Registering routes and permission checks.
 *  - Providing the plan type string used to construct the manager and select filter hooks.
 *  - Providing the storage context (e.g. product ID) required by the manager, if any.
 *  - Extracting the plan data fields relevant to their plan type from the request.
 *  - Formatting the plan data for the REST response.
 *  - Defining the item schema (using self::get_base_schema_properties() as a starting
 *    point and extending with plan-type-specific fields).
 */
abstract class WCS_ATT_REST_Plans_Base_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v3';

	/**
	 * Get the base schema properties shared by all plan types.
	 *
	 * @since 9.0.0
	 *
	 * @return array Base schema properties.
	 */
	protected function get_base_schema_properties() {
		return array(
			'id'                             => array(
				'description' => __( 'Unique identifier for the plan.', 'woocommerce-subscriptions' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'subscription_period'            => array(
				'description'       => __( 'Subscription billing period.', 'woocommerce-subscriptions' ),
				'type'              => 'string',
				'enum'              => array( 'day', 'week', 'month', 'year' ),
				'context'           => array( 'view', 'edit' ),
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'subscription_period_interval'   => array(
				'description' => __( 'Subscription billing interval.', 'woocommerce-subscriptions' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'subscription_length'            => array(
				'description' => __( 'Subscription length (number of intervals). 0 for never expire.', 'woocommerce-subscriptions' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'default'     => 0,
			),
			'subscription_trial_period'      => array(
				'description'       => __( 'Free trial period.', 'woocommerce-subscriptions' ),
				'type'              => 'string',
				'enum'              => array( 'day', 'week', 'month', 'year' ),
				'context'           => array( 'view', 'edit' ),
				'default'           => 'day',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'subscription_trial_length'      => array(
				'description' => __( 'Free trial length (number of periods). 0 for no trial.', 'woocommerce-subscriptions' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'default'     => 0,
			),
			'subscription_signup_fee'        => array(
				'description'       => __( 'Sign-up fee charged at subscription creation.', 'woocommerce-subscriptions' ),
				'type'              => array( 'number', 'string' ),
				'context'           => array( 'view', 'edit' ),
				'default'           => '',
				'sanitize_callback' => 'wc_format_decimal',
			),
			'subscription_payment_sync_date' => array(
				'description'       => __( 'Synchronization date for aligning renewal payments. Pass {"day":0} for no sync, {"day":N} for week/month sync (day of week 1–7 or day of month 1–31), or {"day":N,"month":"MM"} for yearly sync.', 'woocommerce-subscriptions' ),
				'type'              => 'object',
				'context'           => array( 'view', 'edit' ),
				'required'          => false,
				'default'           => array( 'day' => 0 ),
				'properties'        => array(
					'day'   => array(
						'description' => __( 'Day of the week (1–7), month (1–31), or 0 for no sync.', 'woocommerce-subscriptions' ),
						'type'        => 'integer',
					),
					'month' => array(
						'description' => __( 'Month number (1–12), required for yearly sync.', 'woocommerce-subscriptions' ),
						'type'        => 'integer',
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_sync_date_for_request' ),
			),
		);
	}

	/**
	 * Sanitize a subscription_payment_sync_date object value from the REST request.
	 *
	 * Translates the public always-object API shape back to the internal mixed format
	 * expected by WCS_ATT_Scheme: 0 for no sync, integer for week/month sync, or
	 * array {day, month} for yearly sync.
	 *
	 * @since 9.0.0
	 *
	 * @param  array|object $value Raw value from the request.
	 * @return int|array 0, integer day, or array {day, month}.
	 */
	public static function sanitize_sync_date_for_request( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( ! is_array( $value ) ) {
			return array( 'day' => 0 );
		}

		$sanitized = array(
			'day' => isset( $value['day'] ) ? absint( $value['day'] ) : 0,
		);

		$month = isset( $value['month'] ) ? absint( $value['month'] ) : 0;
		if ( $month >= 1 && $month <= 12 ) {
			$sanitized['month'] = $month;
		}

		return $sanitized;
	}

	/**
	 * Normalize a sync_date value from internal mixed format to the always-object API shape.
	 *
	 * @since 9.0.0
	 *
	 * @param  int|array $sync_date Internal sync date: 0, integer, or array {day, month}.
	 * @return array Always-object: {day} or {day, month}.
	 */
	protected function normalize_sync_date_for_response( $sync_date ) {
		if ( is_array( $sync_date ) ) {
			return $sync_date;
		}
		return array( 'day' => absint( $sync_date ) );
	}

	/**
	 * Convert a sanitized subscription_payment_sync_date object to the internal mixed format.
	 *
	 * Translates the always-object API shape to the format expected by WCS_ATT_Scheme:
	 * 0 for no sync, integer for week/month sync, or array {day, month} for yearly sync.
	 *
	 * @since 9.0.0
	 *
	 * @param  array $value Sanitized object value from the request.
	 * @return int|array 0, integer day, or array {day, month}.
	 */
	protected function convert_sync_date_for_storage( $value ) {
		if ( ! is_array( $value ) ) {
			return 0;
		}

		$day       = isset( $value['day'] ) ? absint( $value['day'] ) : 0;
		$month_raw = isset( $value['month'] ) ? absint( $value['month'] ) : 0;
		$month     = ( $month_raw >= 1 && $month_raw <= 12 )
			? str_pad( (string) $month_raw, 2, '0', STR_PAD_LEFT )
			: null;

		if ( null !== $month && $day > 0 ) {
			return array(
				'day'   => $day,
				'month' => $month,
			);
		}

		return $day;
	}

	/**
	 * Get the base response data shared by all plan types.
	 *
	 * @since 9.0.0
	 *
	 * @param  WCS_ATT_Scheme $scheme    Scheme object.
	 * @param  array          $plan_data Raw plan data.
	 * @return array Base response data.
	 */
	protected function get_base_response_data( $scheme, $plan_data ) {
		return array(
			'id'                             => isset( $plan_data['id'] ) ? $plan_data['id'] : '',
			'subscription_period'            => $scheme->get_period(),
			'subscription_period_interval'   => $scheme->get_interval(),
			'subscription_length'            => $scheme->get_length(),
			'subscription_trial_period'      => $scheme->get_trial_period(),
			'subscription_trial_length'      => $scheme->get_trial_length(),
			'subscription_signup_fee'        => $scheme->get_signup_fee(),
			'subscription_payment_sync_date' => $this->normalize_sync_date_for_response( $scheme->get_sync_date() ),
		);
	}

	/**
	 * Create a new subscription plan.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_plan( $request ) {
		try {
			$plan_data  = $this->get_plan_data_from_request( $request );
			$product_id = $this->get_plan_context( $request );
			$manager    = $this->make_manager();
			$result     = $manager->create( $plan_data, $product_id );

			$scheme        = new WCS_ATT_Scheme( array( 'data' => $result ) );
			$response_data = $this->prepare_plan_for_response( $scheme, $result );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $response_data,
					'message' => __( 'Plan saved successfully.', 'woocommerce-subscriptions' ),
				)
			);

		} catch ( WCS_ATT_Plan_Exception $e ) {
			return $this->plan_exception_to_wp_error( $e, 'woocommerce_rest_create_plan_error' );
		} catch ( Exception $e ) {
			$this->log_request_exception( $e, $request );
			return new WP_Error(
				'woocommerce_rest_create_plan_error',
				__( 'An unexpected error occurred while saving the plan.', 'woocommerce-subscriptions' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Update an existing subscription plan.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_plan( $request ) {
		try {
			$plan_id    = $request->get_param( $this->get_plan_id_param() );
			$plan_data  = $this->get_plan_data_from_request( $request );
			$product_id = $this->get_plan_context( $request );
			$manager    = $this->make_manager();
			$result     = $manager->update( $plan_id, $plan_data, $product_id );

			$scheme        = new WCS_ATT_Scheme( array( 'data' => $result ) );
			$response_data = $this->prepare_plan_for_response( $scheme, $result );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $response_data,
					'message' => __( 'Plan updated successfully.', 'woocommerce-subscriptions' ),
				)
			);

		} catch ( WCS_ATT_Plan_Exception $e ) {
			return $this->plan_exception_to_wp_error( $e, 'woocommerce_rest_update_plan_error' );
		} catch ( Exception $e ) {
			$this->log_request_exception( $e, $request );
			return new WP_Error(
				'woocommerce_rest_update_plan_error',
				__( 'An unexpected error occurred while updating the plan.', 'woocommerce-subscriptions' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Delete a subscription plan.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_plan( $request ) {
		try {
			$plan_id    = $request->get_param( $this->get_plan_id_param() );
			$product_id = $this->get_plan_context( $request );
			$manager    = $this->make_manager();
			$manager->delete( $plan_id, $product_id );

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Plan deleted successfully.', 'woocommerce-subscriptions' ),
				)
			);

		} catch ( WCS_ATT_Plan_Exception $e ) {
			return $this->plan_exception_to_wp_error( $e, 'woocommerce_rest_delete_plan_error' );
		} catch ( Exception $e ) {
			$this->log_request_exception( $e, $request );
			return new WP_Error(
				'woocommerce_rest_delete_plan_error',
				__( 'An unexpected error occurred while deleting the plan.', 'woocommerce-subscriptions' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Reorder subscription plans.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function reorder_plans( $request ) {
		try {
			$plan_ids = $request->get_param( 'plan_ids' );

			if ( empty( $plan_ids ) || ! is_array( $plan_ids ) ) {
				return new WP_Error(
					'invalid_plan_ids',
					__( 'Plan IDs must be provided as an array.', 'woocommerce-subscriptions' ),
					array( 'status' => 400 )
				);
			}

			$product_id = $this->get_plan_context( $request );
			$manager    = $this->make_manager();
			$manager->reorder( $plan_ids, $product_id );

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Plans reordered successfully.', 'woocommerce-subscriptions' ),
				)
			);

		} catch ( WCS_ATT_Plan_Exception $e ) {
			return $this->plan_exception_to_wp_error( $e, 'woocommerce_rest_reorder_plans_error' );
		} catch ( Exception $e ) {
			$this->log_request_exception( $e, $request );
			return new WP_Error(
				'woocommerce_rest_reorder_plans_error',
				__( 'An unexpected error occurred while reordering the plans.', 'woocommerce-subscriptions' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Convert a WCS_ATT_Plan_Exception into a WP_Error with the correct HTTP status.
	 *
	 * @since 9.0.0
	 *
	 * @param  WCS_ATT_Plan_Exception $e          The plan exception.
	 * @param  string                 $error_code REST-level error code for the response.
	 * @return WP_Error
	 */
	private function plan_exception_to_wp_error( $e, $error_code ) {
		$data = array( 'status' => $e->get_status() );

		if ( ! empty( $e->get_details() ) ) {
			$data['details'] = $e->get_details();
		}

		return new WP_Error( $e->get_error_code(), $e->getMessage(), $data );
	}

	/**
	 * Log a caught exception with full request context to aid debugging.
	 *
	 * Includes the plan type, request route, storage context (e.g. product ID),
	 * the derived plan ID and operation, the exception message, and a full stack
	 * trace — so that log entries are actionable even when third-party filter hooks
	 * throw generic exceptions deep inside the manager.
	 *
	 * @since 9.0.0
	 *
	 * @param  Exception       $e       The caught exception.
	 * @param  WP_REST_Request $request The current REST request.
	 */
	private function log_request_exception( $e, $request ) {
		$plan_type = $this->get_plan_type();
		$context   = $this->get_plan_context( $request );
		$route     = $request->get_route();
		$method    = $request->get_method();

		if ( false !== strpos( $route, '/reorder' ) ) {
			$operation = 'reorder';
		} elseif ( 'DELETE' === $method ) {
			$operation = 'delete';
		} elseif ( 'POST' === $method ) {
			$operation = 'create';
		} else {
			$operation = 'update';
		}

		$message = sprintf(
			'REST Plans API — unhandled exception during %1$s. Plan type: %2$s. Route: %3$s.',
			$operation,
			$plan_type,
			$route
		);

		if ( $context ) {
			$message .= sprintf( ' Product ID: %d.', $context );
		}

		$plan_id = $request->get_param( $this->get_plan_id_param() );
		if ( $plan_id ) {
			$message .= sprintf( ' Plan ID: %s.', $plan_id );
		}

		$logger = wc_get_logger();
		$logger->error(
			$message,
			array(
				'source' => 'wcs_att',
				'error'  => $e,
			)
		);
	}

	/**
	 * Instantiate a WCS_ATT_Plans_Manager for the current plan type.
	 *
	 * @since 9.0.0
	 *
	 * @return WCS_ATT_Plans_Manager
	 */
	protected function make_manager() {
		return new WCS_ATT_Plans_Manager( $this->get_plan_type() );
	}

	/**
	 * Return the storage context needed by the manager for the current request.
	 *
	 * Storewide plans need no context (returns null). Override in child classes
	 * that require a context, e.g. the product plans controller returns the product ID.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Current request.
	 * @return int|null
	 */
	protected function get_plan_context( $request ) {
		return null;
	}

	/**
	 * Return the plan type string used to construct the manager and select filter hooks.
	 *
	 * @since 9.0.0
	 *
	 * @return string 'storewide' or 'product'.
	 */
	abstract protected function get_plan_type();

	/**
	 * Get the URL parameter name that identifies a single plan in route patterns.
	 *
	 * @since 9.0.0
	 *
	 * @return string e.g. 'id' or 'plan_id'.
	 */
	abstract protected function get_plan_id_param();

	/**
	 * Extract and return the plan field values from the request.
	 *
	 * By the time this is called the WP REST API has already applied the
	 * sanitize_callbacks defined in the item schema, so implementations only
	 * need to call $request->get_param() — no additional sanitization required.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Current request.
	 * @return array Plan data array ready to pass to the manager.
	 */
	abstract protected function get_plan_data_from_request( $request );

	/**
	 * Prepare plan data for the REST response.
	 *
	 * @since 9.0.0
	 *
	 * @param  WCS_ATT_Scheme $scheme    Scheme object built from the persisted plan data.
	 * @param  array          $plan_data Persisted plan data.
	 * @return array Response data array.
	 */
	abstract protected function prepare_plan_for_response( $scheme, $plan_data );
}
