<?php
/**
 * Get subscription notes ability definition.
 *
 * @package WooCommerce Subscriptions
 */

// @phan-file-suppress PhanUndeclaredClassMethod, PhanUndeclaredFunction @phan-suppress-current-line UnusedSuppression -- Abilities API + AbilityDefinition added in WC 10.9; suppression covers older-WC compat runs where this class never loads.

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce_Subscriptions\Internal\Abilities\Abilities_Registrar;

/**
 * Registers the woocommerce-subscriptions/get-subscription-notes ability.
 *
 * List a subscription's notes (customer and/or internal) — backs
 * "what's the history on subscription #N?" and surfaces recent operator
 * activity. Returns a paginated envelope with total_pages, page, and
 * per_page for API surface consistency.
 *
 * The backing controller (WC_REST_Subscription_notes_Controller, which
 * extends WC_REST_Order_Notes_V2_Controller) handles
 * `/wc/v3/subscriptions/{id}/notes`. The parent controller's get_items()
 * returns all notes in one flat array without server-side pagination or
 * X-WP-Total headers; extract_total_from_response() falls back to
 * count($rows) in that case, making total_pages always 1 for typical
 * subscription note sets.
 *
 * The input uses `subscription_id` for clarity; the backing REST route's
 * path variable is the vestigial `order_id` inherited from the order
 * notes controller, but it accepts a subscription ID.
 *
 * @internal Only loaded when WooCommerce 10.9+ is active. The
 *           Abilities_Registrar short-circuits before referencing this
 *           class on earlier WC versions.
 */
class Get_Subscription_Notes extends Abstract_WCS_Ability implements AbilityDefinition {

	public static function get_name(): string {
		return 'woocommerce-subscriptions/get-subscription-notes';
	}

	public static function get_registration_args(): array {
		return [
			'label'               => __( 'Get subscription notes', 'woocommerce-subscriptions' ),
			'description'         => __( 'List a subscription\'s notes (customer-facing and/or internal). The subscription_id input parameter accepts a subscription ID (the underlying REST route uses an inherited "order_id" path variable, but it resolves against shop_subscription posts).', 'woocommerce-subscriptions' ),
			'category'            => self::CATEGORY_SLUG,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => (object) [],
				'properties'           => array_merge(
					[
						'subscription_id' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Subscription ID whose notes to retrieve.', 'woocommerce-subscriptions' ),
						],
						'type'            => [
							'type'        => 'string',
							'enum'        => [ 'any', 'customer', 'internal' ],
							'description' => __( 'Filter notes by author type. "customer" returns notes visible to the customer; "internal" returns shop-only notes; "any" (default) returns both.', 'woocommerce-subscriptions' ),
						],
					],
					self::get_pagination_input_properties( 10, 100 )
				),
				'required'             => [ 'subscription_id' ],
				'additionalProperties' => false,
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Abilities_Registrar::class, 'can_read_subscriptions' ],
			'output_schema'       => self::get_collection_output_schema(
				'notes',
				[
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'id'            => [
							'type'        => 'integer',
							'description' => __( 'Note ID.', 'woocommerce-subscriptions' ),
						],
						'author'        => [
							'type'        => 'string',
							'description' => __( 'Note author name, or "system" for WooCommerce-generated notes.', 'woocommerce-subscriptions' ),
						],
						'date_created'  => [
							'type'        => 'string',
							'description' => __( 'Date the note was created, in the site\'s timezone.', 'woocommerce-subscriptions' ),
						],
						'note'          => [
							'type'        => 'string',
							'description' => __( 'Note content.', 'woocommerce-subscriptions' ),
						],
						'customer_note' => [
							'type'        => 'boolean',
							'description' => __( 'Whether this note is visible to the customer.', 'woocommerce-subscriptions' ),
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
	 * Execute callback for woocommerce-subscriptions/get-subscription-notes.
	 *
	 * The ability input uses `subscription_id` for clarity; the backing REST
	 * route's path variable is the vestigial `order_id` inherited from the
	 * order notes controller, but accepts a subscription ID.
	 *
	 * The backing controller (WC_REST_Subscription_notes_Controller) returns
	 * all notes in one flat array without server-side pagination or X-WP-Total
	 * headers. extract_total_from_response() falls back to count($rows) so
	 * total_pages is always 1 for typical subscription note sets.
	 *
	 * @param mixed $input Required input with `subscription_id`; optional `type`, `page`, `per_page`.
	 * @return array|\WP_Error Paginated envelope { notes, total_pages, page, per_page }, or WP_Error.
	 */
	public static function execute( $input = null ) {
		if ( ! is_array( $input ) || empty( $input['subscription_id'] ) ) {
			return new \WP_Error(
				'woocommerce_subscriptions_missing_subscription_id',
				__( 'Subscription ID is required.', 'woocommerce-subscriptions' ),
				[ 'status' => 400 ]
			);
		}

		$id = (int) $input['subscription_id'];
		if ( $id <= 0 ) {
			return new \WP_Error(
				'woocommerce_subscriptions_invalid_input',
				__( 'Subscription ID must be a positive integer.', 'woocommerce-subscriptions' ),
				[ 'status' => 400 ]
			);
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$params = [
			'page'     => $page,
			'per_page' => $per_page,
		];
		if ( ! empty( $input['type'] ) ) {
			$params['type'] = $input['type'];
		}

		$response = self::delegate_to_rest_controller(
			'\\WC_REST_Subscription_notes_Controller',
			'GET',
			'/wc/v3/subscriptions/' . $id . '/notes',
			$params,
			true
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rows  = (array) $response->get_data();
		$total = self::extract_total_from_response( $response, $rows );

		return [
			'notes'       => array_values( $rows ),
			'total_pages' => self::compute_total_pages( $total, $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		];
	}
}
