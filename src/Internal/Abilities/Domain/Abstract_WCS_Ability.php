<?php
/**
 * Abstract base class for WooCommerce Subscriptions ability definitions.
 *
 * @package WooCommerce Subscriptions
 */

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities\Domain;

/**
 * Shared helpers for WCS ability definitions.
 *
 * Mirrors the shape of Woo Core's `Internal\Abilities\Domain\AbstractDomainAbility`
 * (introduced in WooCommerce 10.9 via #64606) without coupling WCS to that
 * class — Woo Core's lives under `Internal\`, which we treat as off-limits for
 * cross-plugin reuse. Update this base in sync if Woo Core's helper shape
 * meaningfully diverges.
 *
 * @internal Subscription-internal base; intended for use by classes in this
 *           Domain namespace, not third-party code.
 */
abstract class Abstract_WCS_Ability {

	/**
	 * Ability category slug shared across every WCS Domain ability.
	 *
	 * The `woocommerce` category is owned and registered by WooCommerce
	 * Core (10.9+). Plugin ownership is carried by the ability namespace
	 * (`woocommerce-subscriptions/*`), not the category. Mirrors
	 * `Abilities_Registrar::CATEGORY_SLUG`; Domain classes reference this
	 * constant via `self::CATEGORY_SLUG` to avoid the cross-namespace
	 * static call.
	 *
	 * @var string
	 */
	public const CATEGORY_SLUG = 'woocommerce';

	/**
	 * Build a paginated collection-output schema.
	 *
	 * @param string $collection_key Property key naming the array of items
	 *                               (e.g. `subscriptions`, `orders`, `notes`).
	 * @param array  $item_schema    JSON schema describing a single item in
	 *                               the collection.
	 * @return array
	 */
	protected static function get_collection_output_schema( string $collection_key, array $item_schema ): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				$collection_key => [
					'type'        => 'array',
					'description' => sprintf(
						/* translators: %s: Collection key, such as subscriptions or orders. */
						__( 'Returned %s for the current page.', 'woocommerce-subscriptions' ),
						$collection_key
					),
					'items'       => $item_schema,
				],
				'total_pages'   => [
					'type'        => 'integer',
					'description' => __( 'Total number of result pages available for the current query.', 'woocommerce-subscriptions' ),
				],
				'page'          => [
					'type'        => 'integer',
					'description' => __( 'Current result page.', 'woocommerce-subscriptions' ),
				],
				'per_page'      => [
					'type'        => 'integer',
					'description' => __( 'Maximum number of items requested per page.', 'woocommerce-subscriptions' ),
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the standard pagination input properties for inclusion in an
	 * ability's `input_schema['properties']` array.
	 *
	 * @param int $default_per_page Default page size when caller omits `per_page`.
	 * @param int $max_per_page     Hard cap on page size.
	 * @return array
	 */
	protected static function get_pagination_input_properties( int $default_per_page = 10, int $max_per_page = 100 ): array {
		return [
			'page'     => [
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Page number to return (1-indexed).', 'woocommerce-subscriptions' ),
			],
			'per_page' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $max_per_page,
				'default'     => $default_per_page,
				'description' => __( 'Maximum number of items per page.', 'woocommerce-subscriptions' ),
			],
		];
	}

	/**
	 * Compute total_pages from a total count + per_page.
	 *
	 * @param int $total    Total result count.
	 * @param int $per_page Page size in effect.
	 * @return int
	 */
	protected static function compute_total_pages( int $total, int $per_page ): int {
		if ( $total <= 0 || $per_page <= 0 ) {
			return 0;
		}
		return (int) ceil( $total / $per_page );
	}

	/**
	 * Extract the X-WP-Total header from a REST response, with a row-count fallback.
	 *
	 * WP_REST_Server adds X-WP-Total / X-WP-TotalPages to paginated
	 * collection responses when the controller's `get_items()` sets them
	 * (most do by inheriting from WP_REST_Controller's pagination plumbing).
	 * When the header is absent — either because the controller didn't set
	 * it or because the response was filtered — we fall back to the count of
	 * the returned rows. The fallback under-reports for sliced responses
	 * (it only sees the current page's rows), but it never lies about the
	 * current page existing.
	 *
	 * @param \WP_REST_Response $response Response object returned by
	 *                                    delegate_to_rest_controller( ..., true ).
	 * @param array             $rows     Already-extracted data array used as the fallback total.
	 * @return int Total result count.
	 */
	protected static function extract_total_from_response( \WP_REST_Response $response, array $rows ): int {
		$headers = $response->get_headers();
		if ( isset( $headers['X-WP-Total'] ) ) {
			return (int) $headers['X-WP-Total'];
		}
		return count( $rows );
	}

	/**
	 * Execute a backing REST controller route and return its unwrapped response.
	 *
	 * Used by abilities whose backing is a WC REST controller. Builds a
	 * WP_REST_Request, calls rest_do_request(), then unwraps WP_REST_Response
	 * (success → data; error → WP_Error) and raw-array return shapes. The
	 * controller_class argument is informational — used to surface a clear
	 * error if the class has not loaded — because rest_do_request() routes
	 * by registered route, not class.
	 *
	 * Outside a live REST request, rest_do_request() lazy-instantiates
	 * WP_REST_Server and fires rest_api_init once per PHP process — the
	 * first delegating call pays that cost. Acceptable for the read surface
	 * registered here (low-stakes, no telemetry on the backing callbacks);
	 * future writes should consider a shared-service shape instead.
	 *
	 * Visibility is `protected` so Domain subclasses inherit this helper via
	 * `self::delegate_to_rest_controller(...)` and spy test subclasses can
	 * reach it to assert the helper's contract independently of any one
	 * ability. This is not a public extension point.
	 *
	 * @param string $controller_class Fully-qualified backing controller class (informational; surfaces a clear error when not loaded).
	 * @param string $method           HTTP method (GET, POST, PUT, DELETE).
	 * @param string $route            Resolved route path with concrete IDs substituted (e.g. /wc/v3/subscriptions/123/orders).
	 * @param array  $params           Request parameters (query or body), passed to set_param().
	 * @param bool   $return_response  When true, return the WP_REST_Response object on success instead of
	 *                                 unwrapping to its data array. Callers that need response headers
	 *                                 (e.g. X-WP-Total for pagination) must pass true. WP_Error is always
	 *                                 returned as-is regardless of this flag.
	 * @return array|\WP_REST_Response|\WP_Error Unwrapped response data (default), WP_REST_Response
	 *                                            (when $return_response is true), or WP_Error on failure.
	 */
	protected static function delegate_to_rest_controller( string $controller_class, string $method, string $route, array $params = [], bool $return_response = false ) {
		if ( ! class_exists( $controller_class ) ) {
			return new \WP_Error(
				'woocommerce_subscriptions_missing_controller',
				sprintf(
					/* translators: %s: fully-qualified class name of the missing REST controller. */
					__( 'REST controller %s is not loaded.', 'woocommerce-subscriptions' ),
					$controller_class
				),
				[ 'status' => 500 ]
			);
		}

		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response instanceof \WP_REST_Response ) {
			if ( $response->is_error() ) {
				return $response->as_error();
			}
			if ( $return_response ) {
				return $response;
			}
			return $response->get_data();
		}

		return is_array( $response ) ? $response : [ $response ];
	}
}
