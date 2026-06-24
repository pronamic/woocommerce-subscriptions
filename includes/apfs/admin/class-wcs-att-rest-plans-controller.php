<?php
/**
 * REST API Plans Controller
 *
 * Handles requests to the /wc/v3/subscriptions/storewide-plans endpoint.
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    9.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for storewide subscription plans.
 *
 * Registers routes, enforces permissions, extracts request values, and formats
 * responses. All business logic is handled by WCS_ATT_Plans_Manager.
 */
class WCS_ATT_REST_Plans_Controller extends WCS_ATT_REST_Plans_Base_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'subscriptions/storewide-plans';

	/**
	 * Register the routes for the storewide plans endpoint.
	 *
	 * @since 9.0.0
	 */
	public function register_routes() {
		// Collection route (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_plan' ),
					'permission_callback' => array( $this, 'manage_plans_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Reorder route — must be registered before the single-item route to avoid pattern conflicts.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reorder',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'reorder_plans' ),
					'permission_callback' => array( $this, 'manage_plans_permissions_check' ),
					'args'                => array(
						'plan_ids' => array(
							'required'    => true,
							'type'        => 'array',
							'description' => __( 'Array of plan IDs in the desired order.', 'woocommerce-subscriptions' ),
							'items'       => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				),
			)
		);

		// Single item route (PUT, DELETE).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9\-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_plan' ),
					'permission_callback' => array( $this, 'manage_plans_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_plan' ),
					'permission_callback' => array( $this, 'manage_plans_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check if a given request has access to manage storewide plans.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has manage access, WP_Error object otherwise.
	 */
	public function manage_plans_permissions_check( $request ) {
		if ( ! wc_rest_check_manager_permissions( 'settings' ) ) {
			return new WP_Error(
				'woocommerce_rest_cannot_manage',
				__( 'Sorry, you cannot manage storewide subscription plans.', 'woocommerce-subscriptions' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves the query params for the plans collection.
	 *
	 * @since 9.0.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		return array(
			'context' => $this->get_context_param( array( 'default' => 'view' ) ),
		);
	}

	/**
	 * Return the plan type string used to construct the manager.
	 *
	 * @since 9.0.0
	 *
	 * @return string
	 */
	protected function get_plan_type() {
		return 'storewide';
	}

	/**
	 * Get the URL parameter name for a single plan.
	 *
	 * @since 9.0.0
	 *
	 * @return string
	 */
	protected function get_plan_id_param() {
		return 'id';
	}

	/**
	 * Extract storewide plan field values from the request.
	 *
	 * Schema sanitize_callbacks have already run by this point, so no additional
	 * sanitization is needed here.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Current request.
	 * @return array Plan data array.
	 */
	protected function get_plan_data_from_request( $request ) {
		$plan_data = array(
			'subscription_period'            => $request->get_param( 'subscription_period' ),
			'subscription_period_interval'   => $request->get_param( 'subscription_period_interval' ),
			'subscription_length'            => $request->get_param( 'subscription_length' ),
			'subscription_trial_period'      => $request->get_param( 'subscription_trial_period' ),
			'subscription_trial_length'      => $request->get_param( 'subscription_trial_length' ),
			'subscription_signup_fee'        => $request->get_param( 'subscription_signup_fee' ),
			'subscription_discount'          => $request->get_param( 'subscription_discount' ),
			'subscription_payment_sync_date' => $this->convert_sync_date_for_storage( $request->get_param( 'subscription_payment_sync_date' ) ),
		);

		// Include subscription_pricing_method when present; default to 'inherit' for backward compatibility.
		$pricing_method                           = $request->get_param( 'subscription_pricing_method' );
		$plan_data['subscription_pricing_method'] = $pricing_method ? $pricing_method : 'inherit';

		return $plan_data;
	}

	/**
	 * Prepare storewide plan data for the REST response.
	 *
	 * @since 9.0.0
	 *
	 * @param  WCS_ATT_Scheme $scheme    Scheme object.
	 * @param  array          $plan_data Persisted plan data.
	 * @return array Response data array.
	 */
	protected function prepare_plan_for_response( $scheme, $plan_data ) {
		$response = $this->get_base_response_data( $scheme, $plan_data );

		// Storewide plans support inherit (percentage) and fixed_discount pricing.
		$response['subscription_pricing_method'] = $scheme->get_pricing_mode();
		$response['subscription_discount']       = $scheme->get_discount();

		return $response;
	}

	/**
	 * Retrieves the plan's schema, conforming to JSON Schema.
	 *
	 * @since 9.0.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		$properties = $this->get_base_schema_properties();

		// Storewide plans support inherit (percentage) and fixed_discount pricing; override is not supported.
		$properties['subscription_pricing_method'] = array(
			'description'       => __( 'Pricing method for storewide plans. Storewide plans always inherit pricing from products; only a discount is supported.', 'woocommerce-subscriptions' ),
			'type'              => 'string',
			'enum'              => array( 'inherit', 'fixed_discount' ),
			'context'           => array( 'view', 'edit' ),
			'default'           => 'inherit',
			'sanitize_callback' => 'sanitize_text_field',
		);
		$properties['subscription_discount']       = array(
			'description'       => __( 'Discount to apply to product price. Used for both percentage (inherit) and fixed amount (fixed_discount) pricing modes.', 'woocommerce-subscriptions' ),
			'type'              => 'number',
			'context'           => array( 'view', 'edit' ),
			'default'           => 0,
			'sanitize_callback' => 'wc_format_decimal',
		);

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'subscription_plan',
			'type'       => 'object',
			'properties' => $properties,
		);

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}
}
