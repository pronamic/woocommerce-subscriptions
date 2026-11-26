<?php
/**
 * Tracks Store API requests that reuse a subscription renewal order as the checkout draft.
 *
 * @package WooCommerce Subscriptions
 * @since   9.4.2
 */

namespace Automattic\WooCommerce_Subscriptions\Internal\Checkout;

use Automattic\WooCommerce\StoreApi\Routes\V1\AbstractCartRoute;
use Automattic\WooCommerce\StoreApi\Routes\V1\Checkout;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @internal This class may be modified, moved or removed in future releases.
 */
class Store_Api_Draft_Order_Context {

	/**
	 * Whether order reuse is active for each REST callback being served. Batch and nested requests shadow their caller.
	 *
	 * @var array<int, array{request: mixed, can_reuse: bool}>
	 */
	private static $requests = array();

	/**
	 * Register the request observers.
	 */
	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'note_rest_request' ), PHP_INT_MIN, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'forget_rest_request' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_hydration_dispatch_request', array( __CLASS__, 'hydrate_renewal_checkout' ), PHP_INT_MAX, 4 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'finish_order_sync' ), 10, 0 );
		add_action( 'woocommerce_store_api_cart_update_order_from_request', array( __CLASS__, 'finish_order_sync' ), PHP_INT_MIN, 0 );
	}

	/**
	 * Remember the matched controller, even when the request must temporarily hide an outer checkout.
	 *
	 * @param mixed $response Response passed through unchanged.
	 * @param mixed $handler  Matched REST handler.
	 * @param mixed $request  Request about to be served.
	 * @return mixed
	 */
	public static function note_rest_request( $response, $handler, $request ) {
		$callback         = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
		$controller       = is_array( $callback ) ? ( $callback[0] ?? null ) : null;
		self::$requests[] = array(
			'request'   => $request,
			'can_reuse' => $request instanceof WP_REST_Request && self::can_route_reuse_renewal( $controller, $request->get_method() ),
		);

		return $response;
	}

	/**
	 * Run a preloaded checkout GET inside its own request scope. Blocks invokes the controller directly
	 * while rendering the checkout page, so WordPress never fires the usual REST request hooks.
	 *
	 * @param mixed $response Response supplied by an earlier filter, or null to run the controller.
	 * @param mixed $request  Hydration request.
	 * @param mixed $path     Request path.
	 * @param mixed $handler  Matched handler.
	 * @return mixed
	 */
	public static function hydrate_renewal_checkout( $response, $request, $path, $handler ) {
		if ( null !== $response || ! $request instanceof WP_REST_Request || WP_REST_Server::READABLE !== $request->get_method() ) {
			return $response;
		}

		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
		if ( ! is_array( $callback ) || ! ( ( $callback[0] ?? null ) instanceof Checkout )
			|| 'get_response' !== ( $callback[1] ?? null ) || ! is_callable( $callback ) ) {
			return $response;
		}

		// The Checkout block preloads the cart first. Let WooCommerce dispatch ordinary checkout preloads.
		if ( ! wcs_cart_contains_renewal() ) {
			return $response;
		}

		self::note_rest_request( $response, $handler, $request );
		try {
			return $callback( $request );
		} finally {
			self::forget_rest_request( $response, $handler, $request );
		}
	}

	/**
	 * Whether the matched route can reuse a renewal order.
	 *
	 * @param mixed  $controller Object serving the route.
	 * @param string $method     HTTP method.
	 * @return bool
	 */
	private static function can_route_reuse_renewal( $controller, $method ) {
		if ( $controller instanceof Checkout ) {
			// Opening and updating checkout can synchronize the renewal before the shopper places it.
			return in_array( $method, array( 'GET', 'HEAD', 'PUT', 'PATCH', 'POST' ), true );
		}

		// Before 11.3.1 cart mutations could also synchronize the draft.
		return $controller instanceof AbstractCartRoute && wcs_is_woocommerce_pre( '11.3.1' )
			&& in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true )
			&& 0 === strpos( $controller->get_path(), '/cart/' );
	}

	/**
	 * Restore the calling request's scope. A nested dispatch that throws skips its after-hook.
	 * If checkout catches that exception and continues, the nested scope can hide checkout until this cleanup.
	 *
	 * @param mixed $response Response passed through unchanged.
	 * @param mixed $handler  Matched REST handler.
	 * @param mixed $request  Request that is returning.
	 * @return mixed
	 */
	public static function forget_rest_request( $response, $handler, $request ) {
		for ( $i = count( self::$requests ) - 1; $i >= 0; --$i ) {
			if ( self::$requests[ $i ]['request'] === $request ) {
				array_splice( self::$requests, $i );
				break;
			}
		}

		return $response;
	}

	/**
	 * Whether the current Store API operation can reuse the customer's renewal order.
	 *
	 * @return bool
	 */
	public static function can_reuse_renewal_order() {
		return ! empty( end( self::$requests )['can_reuse'] );
	}

	/**
	 * End the current decision once the order is synchronized. Gateway callbacks then see its real status.
	 */
	public static function finish_order_sync() {
		if ( self::$requests ) {
			self::$requests[ count( self::$requests ) - 1 ]['can_reuse'] = false;
		}
	}
}
