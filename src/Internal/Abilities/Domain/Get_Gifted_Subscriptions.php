<?php
/**
 * Get gifted subscriptions ability definition.
 *
 * @package WooCommerce Subscriptions
 */

// @phan-file-suppress PhanUndeclaredClassMethod, PhanUndeclaredFunction @phan-suppress-current-line UnusedSuppression -- Abilities API + AbilityDefinition added in WC 10.9; suppression covers older-WC compat runs where this class never loads.

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce_Subscriptions\Internal\Abilities\Abilities_Registrar;

/**
 * Registers the woocommerce-subscriptions/get-gifted-subscriptions ability.
 *
 * List gifted subscriptions on this store with optional status / per_page /
 * orderby / order filters and real server-side pagination. Distinct from the
 * existing woocommerce-subscriptions/get-subscriptions ability, which does NOT
 * expose a gifted filter at the REST layer.
 *
 * Returns a paginated envelope with total_pages, page, and per_page so callers
 * can iterate large gifted-subscription datasets without loading everything at
 * once. Each item carries a small summary projection: id, status,
 * parent_order_id, recipient_user_id, recipient_email.
 *
 * WIRE-FORMAT NOTE: The legacy `limit` parameter accepted by the pre-migration
 * execute callback has been removed. Callers must switch to `per_page`. The
 * `additionalProperties: false` constraint on the input_schema enforces this
 * at the schema-validation layer.
 *
 * @internal Only loaded when WooCommerce 10.9+ is active. The
 *           Abilities_Registrar short-circuits before referencing this
 *           class on earlier WC versions.
 */
class Get_Gifted_Subscriptions extends Abstract_WCS_Ability implements AbilityDefinition {

	public static function get_name(): string {
		return 'woocommerce-subscriptions/get-gifted-subscriptions';
	}

	public static function get_registration_args(): array {
		return [
			'label'               => __( 'Get gifted subscriptions', 'woocommerce-subscriptions' ),
			'description'         => __( 'List gifted subscriptions (subscriptions with a recipient) on this store with an optional status filter, pagination controls (page / per_page, default 10, max 100), and orderby/order controls. Returns a paginated envelope with total_pages, page, per_page, and a subscriptions array. Each item carries: id, status, parent_order_id, recipient_user_id, recipient_email (may be null under privacy configurations). Use get-subscription for the full record.', 'woocommerce-subscriptions' ),
			'category'            => self::CATEGORY_SLUG,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => (object) [],
				'properties'           => array_merge(
					[
						'status'  => [
							'type'        => 'string',
							'description' => __( 'Limit results to subscriptions in a specific status (e.g. "active", "on-hold", "cancelled"). Accepts the bare slug; "any" (default) returns subscriptions in any status.', 'woocommerce-subscriptions' ),
						],
						'orderby' => [
							'type'        => 'string',
							'enum'        => [ 'date', 'id' ],
							'description' => __( 'Field to order by. Defaults to date.', 'woocommerce-subscriptions' ),
						],
						'order'   => [
							'type'        => 'string',
							'enum'        => [ 'asc', 'desc' ],
							'description' => __( 'Sort direction. Defaults to descending.', 'woocommerce-subscriptions' ),
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
					'additionalProperties' => false,
					'properties'           => [
						'id'                => [
							'type'        => 'integer',
							'description' => __( 'Subscription ID.', 'woocommerce-subscriptions' ),
						],
						'status'            => [
							'type'        => 'string',
							'description' => __( 'Subscription status slug (without the wc- prefix).', 'woocommerce-subscriptions' ),
						],
						'parent_order_id'   => [
							'type'        => 'integer',
							'description' => __( 'ID of the parent order that created this subscription.', 'woocommerce-subscriptions' ),
						],
						'recipient_user_id' => [
							'type'        => [ 'integer', 'null' ],
							'description' => __( 'WordPress user ID of the subscription recipient. The list is filtered to subscriptions with a recipient, so this is null only when the recipient user-ID meta has been removed (e.g. by the GDPR personal-data eraser).', 'woocommerce-subscriptions' ),
						],
						'recipient_email'   => [
							'type'        => [ 'string', 'null' ],
							'description' => __( 'Email address of the subscription recipient. Null when the recipient has been erased under GDPR, or when no email was recorded against the recipient.', 'woocommerce-subscriptions' ),
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
	 * Execute callback for woocommerce-subscriptions/get-gifted-subscriptions.
	 *
	 * @param mixed $input Optional input array with keys status, orderby, order, page, per_page.
	 * @return array|\WP_Error Paginated envelope { subscriptions, total_pages, page, per_page } on success,
	 *                         or WP_Error when gifting is not initialized.
	 */
	public static function execute( $input = null ) {
		if ( ! class_exists( '\\WCS_Gifting' )
			|| ! method_exists( '\\WCS_Gifting', 'get_gifted_subscriptions' ) ) {
			return new \WP_Error(
				'woocommerce_subscriptions_not_initialized',
				__( 'WooCommerce Subscriptions gifting is not initialized.', 'woocommerce-subscriptions' )
			);
		}

		$input = is_array( $input ) ? $input : [];

		// Validate status/orderby/order at the execute layer too — the schema enum is
		// the primary defence, but direct PHP callers bypass schema validation.
		// Falling back to defaults (rather than erroring) keeps the surface
		// forgiving while still preventing arbitrary strings from reaching the
		// backing helper.
		$allowed_orderby = [ 'date', 'id' ];
		$allowed_order   = [ 'asc', 'desc' ];
		$orderby         = isset( $input['orderby'] ) ? (string) $input['orderby'] : 'date';
		$order           = isset( $input['order'] ) ? strtolower( (string) $input['order'] ) : 'desc';

		// Normalize `status` to the bare-slug form the ability description
		// documents. `wcs_get_subscription_statuses()` returns the live status
		// vocabulary (slug => label, keys carry the `wc-` prefix for core
		// statuses). Strip the `wc-` prefix to build a canonical bare-slug
		// allowlist; accept and silently strip `wc-`-prefixed input for
		// callers that pass keys verbatim from the same source. Plus the
		// synthetic `"any"` filter the backing helper supports.
		$status_vocabulary = function_exists( 'wcs_get_subscription_statuses' ) ? wcs_get_subscription_statuses() : [];
		$allowed_status    = [ 'any' ];
		foreach ( array_keys( $status_vocabulary ) as $status_key ) {
			$allowed_status[] = preg_replace( '/^wc-/', '', (string) $status_key );
		}
		$raw_status      = isset( $input['status'] ) ? (string) $input['status'] : 'any';
		$bare_status     = preg_replace( '/^wc-/', '', $raw_status );
		$resolved_status = in_array( $bare_status, $allowed_status, true ) ? $bare_status : 'any';

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// `paginate => true` lets the underlying order query return both the
		// page-sliced rows and the total in one round trip, so the pagination
		// envelope and the gifted-list criterion stay on a single source of
		// truth (the `_recipient_user EXISTS` meta_query inside
		// get_gifted_subscriptions).
		$result = \WCS_Gifting::get_gifted_subscriptions(
			[
				'status'   => $resolved_status,
				'limit'    => $per_page,
				'orderby'  => in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'date',
				'order'    => in_array( $order, $allowed_order, true ) ? $order : 'desc',
				'offset'   => $offset,
				'paginate' => true,
			]
		);

		// wc_get_orders() returns a stdClass {orders, total, max_num_pages} when
		// paginate is true. Fall back to an empty page on any other shape (e.g.
		// a future WC change or an error path returning a bare array) so the
		// documented envelope shape stays homogeneous. Log a warning on the
		// degraded path so an on-call engineer can tell a contract drift apart
		// from a legitimate zero-result.
		$has_expected_shape = is_object( $result ) && isset( $result->orders ) && is_array( $result->orders );
		if ( ! $has_expected_shape && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf(
					'WCS_Gifting::get_gifted_subscriptions( paginate: true ) returned an unexpected shape: %s. Falling back to empty page.',
					gettype( $result )
				),
				[ 'source' => 'woocommerce-subscriptions' ]
			);
		}
		$subscriptions = $has_expected_shape ? $result->orders : [];
		$total         = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;

		$rows = [];
		foreach ( $subscriptions as $subscription ) {
			$row = self::project_summary( $subscription );
			if ( null === $row ) {
				// Skip rows that didn't pass the type guard so the response
				// stays homogeneous with the documented projection shape.
				continue;
			}
			$rows[] = $row;
		}

		return [
			'subscriptions' => array_values( $rows ),
			'total_pages'   => self::compute_total_pages( $total, $per_page ),
			'page'          => $page,
			'per_page'      => $per_page,
		];
	}

	/**
	 * Project a subscription object to the gifted-subscription summary shape.
	 *
	 * Inlined from Abilities_Registrar::project_gifted_subscription_summary()
	 * which was used only by the pre-migration execute callback and has been
	 * removed from the registrar as part of this migration.
	 *
	 * WCS_Gifting::get_gifted_subscriptions() returns WC_Subscription / WC_Order
	 * instances; both extend WC_Abstract_Order and always expose the methods used
	 * below. Guard only against a non-object slipping through (defensive boundary
	 * for direct callers passing arbitrary input). Return null so the caller can
	 * skip the row rather than emit an empty object that breaks the documented shape.
	 *
	 * Treats `_recipient_user` as the source of truth for "this row is a gift":
	 * after a GDPR erasure the user-ID meta is gone but the email meta survives,
	 * and the gifted-list `meta_query` (EXISTS on `_recipient_user`) already
	 * shields the listing — but a defensive null-out below means a stale or
	 * cached row that slips past the filter cannot re-leak the erased email.
	 *
	 * @param \WC_Order|null $subscription Subscription row from WCS_Gifting::get_gifted_subscriptions().
	 * @return array|null Projection on success, null on non-WC_Order input (caller should skip).
	 */
	private static function project_summary( $subscription ): ?array {
		if ( ! $subscription instanceof \WC_Order ) {
			return null;
		}

		$recipient_user_id = (string) $subscription->get_meta( '_recipient_user' );

		if ( '' === $recipient_user_id ) {
			$recipient_email = null;
		} else {
			$email_meta      = (string) $subscription->get_meta( '_recipient_user_email_address' );
			$recipient_email = '' === $email_meta ? null : $email_meta;
		}

		return [
			'id'                => (int) $subscription->get_id(),
			'status'            => (string) $subscription->get_status(),
			'parent_order_id'   => (int) $subscription->get_parent_id(),
			'recipient_user_id' => '' === $recipient_user_id ? null : (int) $recipient_user_id,
			'recipient_email'   => $recipient_email,
		];
	}
}
