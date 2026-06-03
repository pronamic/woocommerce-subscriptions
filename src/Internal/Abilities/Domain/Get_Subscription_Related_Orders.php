<?php
/**
 * Get subscription related orders ability definition.
 *
 * @package WooCommerce Subscriptions
 */

// @phan-file-suppress PhanUndeclaredClassMethod, PhanUndeclaredFunction @phan-suppress-current-line UnusedSuppression -- Abilities API + AbilityDefinition added in WC 10.9; suppression covers older-WC compat runs where this class never loads.

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce_Subscriptions\Internal\Abilities\Abilities_Registrar;

/**
 * Registers the woocommerce-subscriptions/get-subscription-related-orders ability.
 *
 * List the parent, renewal, and switch orders related to a subscription —
 * backs "show me the payment history for subscription #N?". Returns a
 * paginated envelope with total_pages, page, and per_page so callers can
 * iterate large order histories without loading everything at once.
 *
 * The backing controller (WC_REST_Subscriptions_Controller::get_subscription_orders)
 * accepts get_collection_params() on its route (including page + per_page) and
 * always sets X-WP-Total / X-WP-TotalPages response headers.
 *
 * @internal Only loaded when WooCommerce 10.9+ is active. The
 *           Abilities_Registrar short-circuits before referencing this
 *           class on earlier WC versions.
 */
class Get_Subscription_Related_Orders extends Abstract_WCS_Ability implements AbilityDefinition {

	public static function get_name(): string {
		return 'woocommerce-subscriptions/get-subscription-related-orders';
	}

	public static function get_registration_args(): array {
		return [
			'label'               => __( 'Get subscription related orders', 'woocommerce-subscriptions' ),
			'description'         => __( 'List the parent, renewal, and switch orders associated with a subscription. Each returned order carries an order_type field ∈ {parent_order, renewal_order, switch_order}.', 'woocommerce-subscriptions' ),
			'category'            => self::CATEGORY_SLUG,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => (object) [],
				'properties'           => array_merge(
					[
						'id' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Subscription ID.', 'woocommerce-subscriptions' ),
						],
					],
					self::get_pagination_input_properties( 10, 100 )
				),
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Abilities_Registrar::class, 'can_read_subscriptions' ],
			'output_schema'       => self::get_collection_output_schema(
				'orders',
				[
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'id'         => [
							'type'        => 'integer',
							'description' => __( 'Order ID.', 'woocommerce-subscriptions' ),
						],
						'status'     => [
							'type'        => 'string',
							'description' => __( 'Order status slug.', 'woocommerce-subscriptions' ),
						],
						'order_type' => [
							'type'        => 'string',
							'description' => __( 'Relationship to the subscription: parent_order, renewal_order, or switch_order.', 'woocommerce-subscriptions' ),
						],
						'total'      => [
							'type'        => 'string',
							'description' => __( 'Order grand total.', 'woocommerce-subscriptions' ),
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
	 * Execute callback for woocommerce-subscriptions/get-subscription-related-orders.
	 *
	 * @param mixed $input Required input with `id` (subscription ID); optional `page` and `per_page`.
	 * @return array|\WP_Error Paginated envelope { orders, total_pages, page, per_page }, or WP_Error.
	 */
	public static function execute( $input = null ) {
		if ( ! is_array( $input ) || empty( $input['id'] ) ) {
			return new \WP_Error(
				'woocommerce_subscriptions_missing_id',
				__( 'Subscription ID is required.', 'woocommerce-subscriptions' ),
				[ 'status' => 400 ]
			);
		}

		$id = (int) $input['id'];
		if ( $id <= 0 ) {
			return new \WP_Error(
				'woocommerce_subscriptions_invalid_input',
				__( 'Subscription ID must be a positive integer.', 'woocommerce-subscriptions' ),
				[ 'status' => 400 ]
			);
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$response = self::delegate_to_rest_controller(
			'\\WC_REST_Subscriptions_Controller',
			'GET',
			'/wc/v3/subscriptions/' . $id . '/orders',
			[
				'page'     => $page,
				'per_page' => $per_page,
			],
			true
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rows  = (array) $response->get_data();
		$total = self::extract_total_from_response( $response, $rows );

		return [
			'orders'      => array_values( $rows ),
			'total_pages' => self::compute_total_pages( $total, $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		];
	}
}
