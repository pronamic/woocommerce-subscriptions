<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

use ActionScheduler_Store;
use Automattic\WooCommerce_Subscriptions\Internal\Utilities\Request;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint that lets an external web cron service trigger an Action Scheduler queue run scoped to the
 * supplied groups. One instance corresponds to one registered endpoint.
 *
 * Inert until {@see setup()} is called: the constructor only captures dependencies and never registers a route
 * or otherwise reaches into WordPress / Action Scheduler.
 *
 * See `README.md` in this directory for the subsystem's motivation and the cooperation model with the other
 * Queue_Management classes.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class External_Trigger_Endpoint {

	/**
	 * REST API namespace the endpoint is registered under. We piggy-back on WC's stable public namespace so
	 * the URL structure aligns with other Subscriptions / WooCommerce endpoints.
	 */
	private const REST_NAMESPACE = 'wc/v3';

	/**
	 * Path relative to the namespace.
	 */
	private const REST_ROUTE = '/subscriptions/job-queue';

	/**
	 * Query parameter name that carries the secret token.
	 */
	private const TOKEN_QUERY_PARAM = 'wcs_token';

	/**
	 * Default minimum number of seconds between dispatched runs. Filterable at runtime via
	 * {@see wcs_external_trigger_rate_limit_window}.
	 */
	private const DEFAULT_RATE_LIMIT_WINDOW = 60;

	/**
	 * WC Logger source for diagnostic entries.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-external-trigger';

	/**
	 * Action Scheduler groups the dispatched run will be scoped to.
	 *
	 * @var string[]
	 */
	private array $groups;

	/**
	 * @param string[] $groups Action Scheduler group(s) the dispatched run should be scoped to. Typically a
	 *                         one-element array containing `WCS_Action_Scheduler::ACTION_GROUP`.
	 */
	public function __construct( array $groups ) {
		$this->groups = $groups;
	}

	/**
	 * Register the REST route. Called by {@see Manager} only when the feature is enabled — the route does not
	 * exist on the site at all when the feature is off.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the REST route. Public because it's a hook callback; not intended for direct consumption.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => array( 'GET', 'POST', 'PUT' ),
				'callback'            => array( $this, 'handle_request' ),
				// Auth is handled in the callback (token + rate limit) so we can return our own response
				// shape rather than WP's generic 401. Always allow through to the handler.
				'permission_callback' => '__return_true',
				'args'                => array(
					self::TOKEN_QUERY_PARAM => array(
						'description' => __( 'Endpoint token configured in WC > Settings > Subscriptions.', 'woocommerce-subscriptions' ),
						'type'        => 'string',
						'required'    => false,
					),
				),
			)
		);
	}

	/**
	 * Handle an incoming request. Four-step decision:
	 *
	 *  1. Feature gate — `disabled` 403 if the option is off (defensive; the route is normally not registered
	 *     in this state, but this protects against test paths or future code that bypasses Manager).
	 *  2. Token check — `invalid_token` 403 on mismatch or missing.
	 *  3. Rate limit — `rate_limited` 200 if within the window since the last dispatch.
	 *  4. Dispatch — `dispatched` 200, record the timestamp, and register a shutdown callback that runs the
	 *     queue scoped to our groups after the response has been sent.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_enabled() ) {
			$this->log( 'External trigger rejected: feature is disabled.' );
			return new WP_REST_Response( array( 'status' => 'disabled' ), 403 );
		}

		if ( ! $this->is_token_valid( (string) $request->get_param( self::TOKEN_QUERY_PARAM ) ) ) {
			// Do not log the provided value — log aggregators are a recipe for accidental token disclosure.
			$this->log( 'External trigger rejected: invalid token.' );
			return new WP_REST_Response( array( 'status' => 'invalid_token' ), 403 );
		}

		$now           = time();
		$window        = $this->rate_limit_window();
		$last_dispatch = (int) get_option( External_Trigger_Settings::OPTION_LAST_DISPATCH, 0 );

		if ( ! $this->is_rate_limit_bypassed() && $last_dispatch > 0 && ( $now - $last_dispatch ) < $window ) {
			$next_eligible_at = $last_dispatch + $window;
			$this->log(
				sprintf(
					'External trigger rate-limited. Last dispatch: %s. Next eligible: %s.',
					gmdate( 'c', $last_dispatch ),
					gmdate( 'c', $next_eligible_at )
				)
			);
			return new WP_REST_Response(
				array(
					'status'           => 'rate_limited',
					'next_eligible_at' => gmdate( 'c', $next_eligible_at ),
				),
				200
			);
		}

		update_option( External_Trigger_Settings::OPTION_LAST_DISPATCH, $now, false );
		$next_eligible_at = $now + $window;

		$this->log(
			sprintf(
				'External trigger dispatched. Last dispatch: %s. Next eligible: %s.',
				$last_dispatch > 0 ? gmdate( 'c', $last_dispatch ) : 'never',
				gmdate( 'c', $next_eligible_at )
			)
		);

		$this->schedule_shutdown_dispatch();

		return new WP_REST_Response(
			array(
				'status'           => 'dispatched',
				'next_eligible_at' => gmdate( 'c', $next_eligible_at ),
			),
			200
		);
	}

	/**
	 * Shutdown-time callback that flushes the response to the client (where the SAPI permits), then runs the
	 * AS queue with the claim filter scoped to our groups. Public because WordPress's hook system invokes it.
	 *
	 * The scope is set unconditionally for this run (not subject to the dedicated-queue rotation logic): the
	 * external trigger's contract is "run subscription work right now," not "maybe run subscription work."
	 *
	 * @return void
	 */
	public function run_dispatched_queue(): void {
		Request::release_client();

		$store   = ActionScheduler_Store::instance();
		$capable = is_callable( array( $store, 'set_claim_filter' ) ) && is_callable( array( $store, 'get_claim_filter' ) );

		if ( $capable ) {
			$store->set_claim_filter( 'group', $this->groups );
		}

		do_action( 'action_scheduler_run_queue', 'External Trigger' );

		// Best-effort cleanup, mirroring the dedicated-queue's pattern: only clear if the value is still ours,
		// so we don't clobber a filter set by something else mid-run.
		if ( $capable && $this->groups === $store->get_claim_filter( 'group' ) ) {
			$store->set_claim_filter( 'group', '' );
		}
	}

	/**
	 * Whether the feature is enabled. Default `'no'`.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return 'yes' === get_option( External_Trigger_Settings::OPTION_ENABLED, 'no' );
	}

	/**
	 * Constant-time comparison of the supplied token against the stored token. Returns false if no token has
	 * been stored yet (defence against a misconfigured install where the option is missing).
	 *
	 * @param string $supplied The token as supplied by the caller.
	 *
	 * @return bool
	 */
	private function is_token_valid( string $supplied ): bool {
		$expected = (string) get_option( External_Trigger_Settings::OPTION_TOKEN, '' );
		if ( '' === $expected || '' === $supplied ) {
			return false;
		}
		return hash_equals( $expected, $supplied );
	}

	/**
	 * Rate-limit window in seconds. Filterable so ops can widen or narrow it without UI changes.
	 *
	 * @return int
	 */
	private function rate_limit_window(): int {
		/**
		 * Filter the minimum number of seconds between dispatched external-trigger runs.
		 *
		 * @since 8.8.0
		 *
		 * @param int $seconds Default {@see DEFAULT_RATE_LIMIT_WINDOW}.
		 */
		$seconds = (int) apply_filters( 'wcs_external_trigger_rate_limit_window', self::DEFAULT_RATE_LIMIT_WINDOW );
		return max( 1, $seconds );
	}

	/**
	 * Whether the rate-limit should be bypassed for this request. Useful for ops who manage rate-limiting
	 * upstream (load balancer rule, fronting proxy, etc.) and want the application-level limit out of the way.
	 *
	 * @return bool
	 */
	private function is_rate_limit_bypassed(): bool {
		/**
		 * Filter to bypass the application-level rate limit for the current external-trigger request.
		 *
		 * @since 8.8.0
		 *
		 * @param bool $bypass Default false.
		 */
		return (bool) apply_filters( 'wcs_external_trigger_rate_limit_bypass', false );
	}

	/**
	 * Schedule the queue dispatch on `shutdown` at an early priority so we run before AS's own
	 * `maybe_dispatch_async_request` (default priority 10). This means: response has been sent (where the
	 * SAPI allows), then we dispatch our scoped queue run, then AS gets to decide whether to chain another
	 * unscoped runner via its normal async-request flow.
	 *
	 * @return void
	 */
	private function schedule_shutdown_dispatch(): void {
		add_action( 'shutdown', array( $this, 'run_dispatched_queue' ), 1 );
	}

	/**
	 * Emit a debug-level entry to the WC logger.
	 *
	 * @param string $message
	 *
	 * @return void
	 */
	private function log( string $message ): void {
		wc_get_logger()->debug( $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
