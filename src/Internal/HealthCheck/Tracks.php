<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use WC_Tracks;

/**
 * Thin Tracks emitter for Health Check telemetry.
 *
 * Four events are defined:
 *
 *   - `wcs_health_check_scan_completed` — fires once per completed
 *     scan run with aggregate metrics (scanned count, candidate
 *     counts, duration, batches, who triggered it).
 *   - `wcs_health_check_circuit_breaker_tripped` — fires when the
 *     breaker self-disables after the consecutive-failure threshold
 *     is crossed.
 *   - `wcs_health_check_manual_scan_triggered` — fires when the
 *     merchant clicks "Run scan now" and the request passes the
 *     nonce + lock gates.
 *   - `wcs_health_check_scan_cancelled` - fires when the merchant
 *     cancels an in-flight scan via the Cancel scan button.
 *
 * Every event carries a canonical `source` label so downstream
 * processors can route health-check events without pattern-matching
 * the event name. The label is applied AFTER the caller's props so a
 * buggy caller can't spoof another subsystem's identity.
 *
 * The recorder callable is injectable for testability. In production
 * it delegates to `WC_Tracks::record_event()` when the WooCommerce
 * tracking stack is available; in tests it's a capture closure. The
 * same pattern used by `Internal\Telemetry\Events`.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Tracks {

	/**
	 * Canonical source tag applied to every emitted payload.
	 */
	public const SOURCE = 'wcs-health-check';

	/**
	 * Event name: completed scan run.
	 */
	public const EVENT_SCAN_COMPLETED = 'wcs_health_check_scan_completed';

	/**
	 * Event name: breaker tripped itself after consecutive failures.
	 */
	public const EVENT_CIRCUIT_BREAKER_TRIPPED = 'wcs_health_check_circuit_breaker_tripped';

	/**
	 * Event name: merchant clicked Run-scan-now.
	 */
	public const EVENT_MANUAL_SCAN_TRIGGERED = 'wcs_health_check_manual_scan_triggered';

	/**
	 * Event name: merchant cancelled an in-flight scan via the
	 * Status tab. Distinct from EVENT_SCAN_COMPLETED so consumers can
	 * differentiate finished runs from terminated ones.
	 */
	public const EVENT_SCAN_CANCELLED = 'wcs_health_check_scan_cancelled';

	/**
	 * Callable invoked to record events. Signature:
	 * `function( string $event, array $props ): void`.
	 *
	 * @var callable
	 */
	private $recorder;

	/**
	 * @param callable|null $recorder Optional recorder. Defaults to a
	 *                                closure that forwards to
	 *                                `WC_Tracks::record_event()` when the
	 *                                tracking stack is available.
	 */
	public function __construct( ?callable $recorder = null ) {
		$this->recorder = $recorder ?? static function ( string $event, array $props ): void {
			// Guard on the class we're actually about to call, not a
			// sibling. `WC_Site_Tracking` and `WC_Tracks` are loaded
			// by the same WC bootstrap today, but an autoloader
			// change that split them could leave the guard passing
			// while the call site fatals. Matches the pattern in
			// `includes/admin/class-wcs-admin-reports.php`.
			if ( ! class_exists( 'WC_Tracks' ) ) {
				return;
			}

			WC_Tracks::record_event( $event, $props );
		};
	}

	/**
	 * Fire the scan-completed event. Expected payload keys:
	 *   run_id, total_scanned, candidates_found,
	 *   duration_seconds, batches_processed, triggered_by,
	 *   plus one `candidates_<signal>` key per entry in
	 *   `CandidateStore::all_signal_types()` (currently
	 *   `candidates_supports_auto_renewal` and
	 *   `candidates_missing_renewal`). The per-signal keys
	 *   sum to `candidates_found`.
	 *
	 * @param array<string, mixed> $props
	 *
	 * @return void
	 */
	public function scan_completed( array $props ): void {
		$this->emit( self::EVENT_SCAN_COMPLETED, $props );
	}

	/**
	 * Fire the breaker-tripped event. Expected payload keys:
	 *   reason, consecutive_failures, heartbeat_age_seconds.
	 *
	 * @param array<string, mixed> $props
	 *
	 * @return void
	 */
	public function circuit_breaker_tripped( array $props ): void {
		$this->emit( self::EVENT_CIRCUIT_BREAKER_TRIPPED, $props );
	}

	/**
	 * Fire the manual-scan-triggered event. Expected payload keys:
	 *   run_id.
	 *
	 * @param array<string, mixed> $props
	 *
	 * @return void
	 */
	public function manual_scan_triggered( array $props ): void {
		$this->emit( self::EVENT_MANUAL_SCAN_TRIGGERED, $props );
	}

	/**
	 * Fire the scan-cancelled event. Expected payload keys:
	 *   run_id, triggered_by, subscriptions_scanned,
	 *   total_subscriptions.
	 *
	 * @param array<string, mixed> $props
	 *
	 * @return void
	 */
	public function scan_cancelled( array $props ): void {
		$this->emit( self::EVENT_SCAN_CANCELLED, $props );
	}

	/**
	 * Core emit path: stamp the canonical source and hand off to the recorder.
	 *
	 * @param string               $event
	 * @param array<string, mixed> $props
	 *
	 * @return void
	 */
	private function emit( string $event, array $props ): void {
		// `source` is applied LAST so a caller that (mistakenly or
		// maliciously) supplied their own cannot spoof the origin of
		// the event.
		$payload           = $props;
		$payload['source'] = self::SOURCE;

		// Direct-invocation form replaces `call_user_func()` — PHP 7.4+
		// supports `($callable)(...)` on a property-held callable
		// without the function-call overhead.
		( $this->recorder )( $event, $payload );
	}
}
