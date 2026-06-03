<?php
/**
 * Get subscription ability definition.
 *
 * @package WooCommerce Subscriptions
 */

// @phan-file-suppress PhanUndeclaredClassMethod, PhanUndeclaredFunction @phan-suppress-current-line UnusedSuppression -- Abilities API + AbilityDefinition added in WC 10.9; suppression covers older-WC compat runs where this class never loads.

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce_Subscriptions\Internal\Abilities\Abilities_Registrar;

/**
 * Registers the woocommerce-subscriptions/get-subscription ability.
 *
 * Fetch a single subscription by ID with full details (status, dates,
 * billing schedule, customer, line items, payment method) — backs the
 * common question "what's the state of subscription #N?".
 *
 * The response is enriched with the gifting projection `is_gifted`,
 * `recipient_user_id`, `recipient_email`. The WC REST controller does not
 * carry these fields; reading the recipient meta here avoids a follow-up
 * call when an agent needs to know whether a subscription is a gift.
 *
 * @internal Only loaded when WooCommerce 10.9+ is active. The
 *           Abilities_Registrar short-circuits before referencing this
 *           class on earlier WC versions.
 */
class Get_Subscription extends Abstract_WCS_Ability implements AbilityDefinition {

	public static function get_name(): string {
		return 'woocommerce-subscriptions/get-subscription';
	}

	public static function get_registration_args(): array {
		return [
			'label'               => __( 'Get subscription', 'woocommerce-subscriptions' ),
			'description'         => __( 'Fetch a single subscription by ID with status, dates, billing schedule, customer, line items, payment method, resubscribe links, and gifting projection (is_gifted, recipient_user_id, recipient_email — the identity fields are null when the subscription is not gifted or after GDPR erasure).', 'woocommerce-subscriptions' ),
			'category'            => self::CATEGORY_SLUG,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => (object) [],
				'properties'           => [
					'id'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Subscription ID.', 'woocommerce-subscriptions' ),
					],
					'context' => [
						'type'        => 'string',
						'enum'        => [ 'view', 'edit' ],
						'description' => __( 'Schema context: "view" returns merchant-safe fields, "edit" returns the full record including edit-only fields. Defaults to "view".', 'woocommerce-subscriptions' ),
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Abilities_Registrar::class, 'can_read_subscriptions' ],
			// Partial output_schema: declares only the three enrichment fields this class
			// authors via enrich_with_recipient(). additionalProperties: true preserves the
			// pass-through of the backing controller's payload without coupling this
			// registrar to its full shape. MCP clients reading the schema for field
			// discovery see the gifting projection; the rest of the response stays
			// decoupled.
			'output_schema'       => [
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => [
					'is_gifted'         => [
						'type'        => 'boolean',
						'description' => __( 'True when the subscription has a recipient on file (i.e. is a gift).', 'woocommerce-subscriptions' ),
					],
					'recipient_user_id' => [
						'type'        => [ 'integer', 'null' ],
						'description' => __( 'WordPress user ID of the subscription recipient, or null when the subscription is not gifted or the recipient has been erased under GDPR.', 'woocommerce-subscriptions' ),
					],
					'recipient_email'   => [
						'type'        => [ 'string', 'null' ],
						'description' => __( 'Email address of the subscription recipient, or null when the subscription is not gifted, no email was recorded, or the recipient has been erased under GDPR.', 'woocommerce-subscriptions' ),
					],
				],
			],
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
	 * Execute callback for woocommerce-subscriptions/get-subscription.
	 *
	 * @param mixed $input Required input with at least `id`; optional `context`.
	 * @return array|\WP_Error Subscription record enriched with the gifting projection,
	 *                         or WP_Error on failure / not-found / permission.
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

		$params = [];
		if ( ! empty( $input['context'] ) ) {
			$params['context'] = $input['context'];
		}

		$response = self::delegate_to_rest_controller(
			'\\WC_REST_Subscriptions_Controller',
			'GET',
			'/wc/v3/subscriptions/' . $id,
			$params
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::enrich_with_recipient( is_array( $response ) ? $response : [], $id );
	}

	/**
	 * Add the gifting projection (is_gifted, recipient_user_id, recipient_email)
	 * to the REST response.
	 *
	 * The WC REST `shop_subscription` controller does not include the
	 * `_recipient_user` or `_recipient_user_email_address` meta in its
	 * default payload, so an agent that calls get-subscription would
	 * otherwise need a separate call (or a meta scan) to learn whether the
	 * subscription is a gift. Reading the two meta keys here keeps that
	 * answer in the same round trip.
	 *
	 * Treats `_recipient_user` as the source of truth for "this subscription
	 * is a gift" — not `WCS_Gifting::is_gifted_subscription()`, which also
	 * returns true when only the email meta survives. After a GDPR erasure
	 * the privacy eraser deletes `_recipient_user` but leaves
	 * `_recipient_user_email_address` in place, and surfacing the stale
	 * email through this ability would be a re-leak. So if the user-ID meta
	 * is gone, the gifting projection collapses to nulls regardless of what
	 * the email meta contains.
	 *
	 * @param array $response       REST response payload.
	 * @param int   $subscription_id Subscription ID for the meta lookup.
	 * @return array Response merged with is_gifted, recipient_user_id, recipient_email.
	 */
	private static function enrich_with_recipient( array $response, int $subscription_id ): array {
		$is_gifted         = false;
		$recipient_user_id = null;
		$recipient_email   = null;

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;

		if ( $subscription ) {
			$user_meta = (string) $subscription->get_meta( '_recipient_user' );

			if ( '' !== $user_meta ) {
				$email_meta = (string) $subscription->get_meta( '_recipient_user_email_address' );

				$is_gifted         = true;
				$recipient_user_id = (int) $user_meta;
				$recipient_email   = '' === $email_meta ? null : $email_meta;
			}
		}

		$response['is_gifted']         = $is_gifted;
		$response['recipient_user_id'] = $recipient_user_id;
		$response['recipient_email']   = $recipient_email;

		return $response;
	}
}
