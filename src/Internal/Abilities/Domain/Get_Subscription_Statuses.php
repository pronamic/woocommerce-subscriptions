<?php
/**
 * Get subscription statuses ability definition.
 *
 * @package WooCommerce Subscriptions
 */

// @phan-file-suppress PhanUndeclaredClassMethod, PhanUndeclaredFunction @phan-suppress-current-line UnusedSuppression -- Abilities API + AbilityDefinition added in WC 10.9; suppression covers older-WC compat runs where this class never loads.

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce_Subscriptions\Internal\Abilities\Abilities_Registrar;

/**
 * Registers the woocommerce-subscriptions/get-subscription-statuses ability.
 *
 * Zero-arg read that returns the vocabulary of subscription statuses
 * (`wc-active`, `wc-on-hold`, `wc-cancelled`, etc.) keyed to their human
 * labels. Reference ability — establishes the registration shape (helper
 * + execute callback + permission callback) the remaining reads copy.
 *
 * @internal Only loaded when WooCommerce 10.9+ is active. The
 *           Abilities_Registrar short-circuits before referencing this
 *           class on earlier WC versions.
 */
class Get_Subscription_Statuses extends Abstract_WCS_Ability implements AbilityDefinition {

	public static function get_name(): string {
		return 'woocommerce-subscriptions/get-subscription-statuses';
	}

	public static function get_registration_args(): array {
		return [
			'label'               => __( 'Get subscription statuses', 'woocommerce-subscriptions' ),
			'description'         => __( 'List the vocabulary of valid subscription statuses keyed to their human-readable labels. Typical values include active, on-hold, cancelled, expired, pending-cancel, pending, and switched; the actual set comes from wcs_get_subscription_statuses() and may be extended by third-party code.', 'woocommerce-subscriptions' ),
			'category'            => self::CATEGORY_SLUG,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => (object) [],
				'properties'           => [],
				'additionalProperties' => false,
			],
			'execute_callback'    => [ self::class, 'execute' ],
			// The backing REST route (GET /wc/v3/subscriptions/statuses) uses
			// `permission_callback => '__return_true'` because the status
			// vocabulary is genuinely public information. The ability layer
			// deliberately does *not* propagate that: every ability in this
			// surface gates on can_read_subscriptions() so a future write
			// added next to this read can't be silently mis-copied with a
			// `__return_true` gate. Agents and MCP clients are typically
			// authenticated, so the stricter gate is a no-op for the
			// legitimate use case while removing a known footgun.
			'permission_callback' => [ Abilities_Registrar::class, 'can_read_subscriptions' ],
			// output_schema deliberately omitted — the payload shape comes straight from wcs_get_subscription_statuses() and we don't want to couple this registrar to a specific structure here.
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
	 * Execute callback for woocommerce-subscriptions/get-subscription-statuses.
	 *
	 * Returns the same status map the REST controller exposes at
	 * GET /wc/v3/subscriptions/statuses. Calls wcs_get_subscription_statuses()
	 * directly rather than round-tripping through rest_do_request() — the
	 * source function is a thin canonical helper with no side effects, so the
	 * REST bootstrap cost would only buy us extra overhead.
	 *
	 * @param mixed $input Optional; ability input. Unused for this ability (empty input_schema) but accepted to match the Abilities API execute_callback signature.
	 * @return array|\WP_Error Status map (status_slug => human label) or WP_Error when WCS is not initialized.
	 */
	public static function execute( $input = null ) {
		unset( $input );

		if ( ! function_exists( 'wcs_get_subscription_statuses' ) ) {
			return new \WP_Error(
				'woocommerce_subscriptions_not_initialized',
				__( 'WooCommerce Subscriptions is not initialized.', 'woocommerce-subscriptions' )
			);
		}

		return wcs_get_subscription_statuses();
	}
}
