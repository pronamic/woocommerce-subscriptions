<?php
/**
 * REST API Product Plans Controller
 *
 * Handles requests to the /wc/v3/products/{product_id}/subscription-plans endpoint.
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    9.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for product-level subscription plans.
 *
 * Registers routes, enforces permissions, extracts request values, and formats
 * responses. All business logic is handled by WCS_ATT_Plans_Manager.
 * Only available in edit context (product must already exist and have an ID).
 */
class WCS_ATT_REST_Product_Plans_Controller extends WCS_ATT_REST_Plans_Base_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'products/(?P<product_id>[\d]+)/subscription-plans';

	/**
	 * Register the routes for the product plans endpoint.
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
			'/' . $this->rest_base . '/(?P<plan_id>[a-zA-Z0-9\-_]+)',
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
	 * Check if a given request has access to manage product plans.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has manage access, WP_Error object otherwise.
	 */
	public function manage_plans_permissions_check( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );

		if ( ! wc_rest_check_post_permissions( 'product', 'edit', $product_id ) ) {
			return new WP_Error(
				'woocommerce_rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this product\'s subscription plans.', 'woocommerce-subscriptions' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Return the plan type string used to construct the manager.
	 *
	 * @since 9.0.0
	 *
	 * @return string
	 */
	protected function get_plan_type() {
		return 'product';
	}

	/**
	 * Return the product ID as the storage context for the manager.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Current request.
	 * @return int
	 */
	protected function get_plan_context( $request ) {
		return (int) $request->get_param( 'product_id' );
	}

	/**
	 * Get the URL parameter name for a single plan.
	 *
	 * @since 9.0.0
	 *
	 * @return string
	 */
	protected function get_plan_id_param() {
		return 'plan_id';
	}

	/**
	 * Extract product plan field values from the request.
	 *
	 * Schema sanitize_callbacks have already run by this point, so no additional
	 * sanitization is needed here. Pricing fields are included conditionally
	 * based on the chosen pricing method, matching how they are stored and validated.
	 *
	 * @since 9.0.0
	 *
	 * @param  WP_REST_Request $request Current request.
	 * @return array Plan data array.
	 */
	protected function get_plan_data_from_request( $request ) {
		$pricing_method = $request->get_param( 'subscription_pricing_method' );

		$plan_data = array(
			'subscription_period'            => $request->get_param( 'subscription_period' ),
			'subscription_period_interval'   => $request->get_param( 'subscription_period_interval' ),
			'subscription_length'            => $request->get_param( 'subscription_length' ),
			'subscription_trial_period'      => $request->get_param( 'subscription_trial_period' ),
			'subscription_trial_length'      => $request->get_param( 'subscription_trial_length' ),
			'subscription_pricing_method'    => $pricing_method,
			'subscription_payment_sync_date' => $this->convert_sync_date_for_storage( $request->get_param( 'subscription_payment_sync_date' ) ),
		);

		// Signup fee is optional for product plans.
		$signup_fee = $request->get_param( 'subscription_signup_fee' );
		if ( '' !== $signup_fee && null !== $signup_fee ) {
			$plan_data['subscription_signup_fee'] = $signup_fee;
		}

		// Pricing fields depend on the chosen pricing method.
		// For 'override' mode, include regular and sale prices.
		// For 'inherit' and 'fixed_discount' modes, include the discount value.
		if ( 'override' === $pricing_method ) {
			$plan_data['subscription_regular_price'] = $request->get_param( 'subscription_regular_price' );
			$plan_data['subscription_sale_price']    = $request->get_param( 'subscription_sale_price' );
		} else {
			$plan_data['subscription_discount'] = $request->get_param( 'subscription_discount' );
		}

		return $plan_data;
	}

	/**
	 * Prepare product plan data for the REST response.
	 *
	 * @since 9.0.0
	 *
	 * @param  WCS_ATT_Scheme $scheme    Scheme object.
	 * @param  array          $plan_data Persisted plan data.
	 * @return array Response data array.
	 */
	protected function prepare_plan_for_response( $scheme, $plan_data ) {
		$response = $this->get_base_response_data( $scheme, $plan_data );

		$response['subscription_pricing_method'] = $scheme->get_pricing_mode();

		if ( WCS_ATT_Scheme::MODE_INHERIT === $scheme->get_pricing_mode() || WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $scheme->get_pricing_mode() ) {
			$response['subscription_discount'] = $scheme->get_discount();
		} else {
			$response['subscription_regular_price'] = $scheme->get_regular_price();
			$response['subscription_sale_price']    = $scheme->get_sale_price();
		}

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

		// Product plans support inherit (discount), override (regular/sale price), and fixed_discount pricing.
		$properties['subscription_pricing_method'] = array(
			'description'       => __( 'Pricing method (inherit from product, override, or fixed discount).', 'woocommerce-subscriptions' ),
			'type'              => 'string',
			'enum'              => array( 'inherit', 'override', 'fixed_discount' ),
			'context'           => array( 'view', 'edit' ),
			'default'           => 'inherit',
			'sanitize_callback' => 'sanitize_text_field',
		);
		$properties['subscription_discount']       = array(
			'description'       => __( 'Discount to apply when pricing mode is inherit or fixed_discount.', 'woocommerce-subscriptions' ),
			'type'              => 'number',
			'context'           => array( 'view', 'edit' ),
			'sanitize_callback' => 'wc_format_decimal',
		);
		$properties['subscription_regular_price']  = array(
			'description'       => __( 'Regular price when pricing mode is override.', 'woocommerce-subscriptions' ),
			'type'              => 'string',
			'context'           => array( 'view', 'edit' ),
			'sanitize_callback' => 'wc_format_decimal',
		);
		$properties['subscription_sale_price']     = array(
			'description'       => __( 'Sale price when pricing mode is override.', 'woocommerce-subscriptions' ),
			'type'              => 'string',
			'context'           => array( 'view', 'edit' ),
			'sanitize_callback' => 'wc_format_decimal',
		);
		$properties['subscription_price']          = array(
			'description' => __( 'Effective price when pricing mode is override.', 'woocommerce-subscriptions' ),
			'type'        => 'string',
			'context'     => array( 'view' ),
			'readonly'    => true,
		);

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'product_subscription_plan',
			'type'       => 'object',
			'properties' => $properties,
		);

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}
}
