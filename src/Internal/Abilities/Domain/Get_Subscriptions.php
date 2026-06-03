<?php
/**
 * Get subscriptions ability definition.
 *
 * @package WooCommerce Subscriptions
 */

// @phan-file-suppress PhanUndeclaredClassMethod, PhanUndeclaredFunction @phan-suppress-current-line UnusedSuppression -- Abilities API + AbilityDefinition added in WC 10.9; suppression covers older-WC compat runs where this class never loads.

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce_Subscriptions\Internal\Abilities\Abilities_Registrar;

/**
 * Registers the woocommerce-subscriptions/get-subscriptions ability.
 *
 * List subscriptions with filters (status, customer, product, parent order,
 * date range, search, paginate). Backs the merchant question "which
 * subscriptions are <status> for customer <id>?" or "show subscriptions
 * renewing this week" in a single call.
 *
 * The backing controller (WC_REST_Subscriptions_Controller) handles
 * `/wc/v3/subscriptions` and accepts get_collection_params() on its route
 * (including page + per_page), always setting X-WP-Total / X-WP-TotalPages
 * response headers.
 *
 * WIRE-FORMAT NOTE: The legacy ability exposed a `limit` parameter. This
 * migration replaces it with `page` + `per_page` (via
 * Abstract_WCS_Ability::get_pagination_input_properties()). The input_schema's
 * `additionalProperties: false` REJECTS any caller that passes `limit`.
 * Acceptable because the abilities feature is flag-gated default-off and
 * there are no production consumers yet. The new shape aligns with WC 10.9's
 * OrdersQuery convention.
 *
 * @internal Only loaded when WooCommerce 10.9+ is active. The
 *           Abilities_Registrar short-circuits before referencing this
 *           class on earlier WC versions.
 */
class Get_Subscriptions extends Abstract_WCS_Ability implements AbilityDefinition {

	public static function get_name(): string {
		return 'woocommerce-subscriptions/get-subscriptions';
	}

	public static function get_registration_args(): array {
		return [
			'label'               => __( 'Get subscriptions', 'woocommerce-subscriptions' ),
			'description'         => __( 'List subscriptions with optional filters: status, customer, product, parent order, date range, search, and pagination. Returns subscription-shaped objects with billing schedule, status, dates, and customer.', 'woocommerce-subscriptions' ),
			'category'            => self::CATEGORY_SLUG,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => (object) [],
				'properties'           => array_merge(
					[
						'status'   => [
							'type'        => 'string',
							'description' => __( 'Limit results to subscriptions in a specific status. Accepts both the bare slug (e.g. "active") and the wc- prefixed form ("wc-active"); "any" returns subscriptions in any status.', 'woocommerce-subscriptions' ),
						],
						'customer' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Limit results to subscriptions owned by this user (customer) ID.', 'woocommerce-subscriptions' ),
						],
						'product'  => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Limit results to subscriptions whose line items include this product ID.', 'woocommerce-subscriptions' ),
						],
						'parent'   => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Limit results to subscriptions created from this parent order ID.', 'woocommerce-subscriptions' ),
						],
						'after'    => [
							'type'        => 'string',
							'format'      => 'date-time',
							'description' => __( 'Limit results to subscriptions created after the given ISO 8601 date-time.', 'woocommerce-subscriptions' ),
						],
						'before'   => [
							'type'        => 'string',
							'format'      => 'date-time',
							'description' => __( 'Limit results to subscriptions created before the given ISO 8601 date-time.', 'woocommerce-subscriptions' ),
						],
						'search'   => [
							'type'        => 'string',
							'description' => __( 'Free-text search across the subscription record (billing email, customer name, IDs).', 'woocommerce-subscriptions' ),
						],
						'order'    => [
							'type'        => 'string',
							'enum'        => [ 'asc', 'desc' ],
							'description' => __( 'Sort direction. Defaults to descending.', 'woocommerce-subscriptions' ),
						],
						'orderby'  => [
							'type'        => 'string',
							'description' => __( 'Field to sort by (e.g. date, id, modified, title). The accepted enum mirrors the underlying /wc/v3/subscriptions endpoint.', 'woocommerce-subscriptions' ),
						],
					],
					self::get_pagination_input_properties( 10, 100 )
				),
				'additionalProperties' => false,
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Abilities_Registrar::class, 'can_read_subscriptions' ],
			'output_schema'       => self::get_collection_output_schema(
				'subscriptions',
				[
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'id'          => [
							'type'        => 'integer',
							'description' => __( 'Subscription ID.', 'woocommerce-subscriptions' ),
						],
						'status'      => [
							'type'        => 'string',
							'description' => __( 'Subscription status slug.', 'woocommerce-subscriptions' ),
						],
						'customer_id' => [
							'type'        => 'integer',
							'description' => __( 'Customer (user) ID.', 'woocommerce-subscriptions' ),
						],
						'total'       => [
							'type'        => 'string',
							'description' => __( 'Subscription grand total.', 'woocommerce-subscriptions' ),
						],
					],
				]
			),
			'meta'                => [
				'annotations'  => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
				'mcp'          => [
					'public' => true,
				],
			],
		];
	}

	/**
	 * Execute callback for woocommerce-subscriptions/get-subscriptions.
	 *
	 * All input fields are optional. Passes any provided filter parameters
	 * (status, customer, product, parent, after, before, search, order,
	 * orderby) and pagination parameters (page, per_page) through to the
	 * backing REST controller.
	 *
	 * The backing controller (WC_REST_Subscriptions_Controller) sets
	 * X-WP-Total / X-WP-TotalPages response headers, which
	 * extract_total_from_response() uses to compute total_pages accurately.
	 *
	 * @param mixed $input Optional; ability input matching the input_schema.
	 * @return array|\WP_Error Paginated envelope { subscriptions, total_pages, page, per_page }, or WP_Error on failure.
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$params = [
			'page'     => $page,
			'per_page' => $per_page,
		];

		// Pass through optional filter properties verbatim.
		foreach ( [ 'status', 'customer', 'product', 'parent', 'after', 'before', 'search', 'order', 'orderby' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$params[ $key ] = $input[ $key ];
			}
		}

		$response = self::delegate_to_rest_controller(
			'\\WC_REST_Subscriptions_Controller',
			'GET',
			'/wc/v3/subscriptions',
			$params,
			true
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rows  = (array) $response->get_data();
		$total = self::extract_total_from_response( $response, $rows );

		return [
			'subscriptions' => array_values( $rows ),
			'total_pages'   => self::compute_total_pages( $total, $per_page ),
			'page'          => $page,
			'per_page'      => $per_page,
		];
	}
}
