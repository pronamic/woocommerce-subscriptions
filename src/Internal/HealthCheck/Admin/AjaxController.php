<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Admin;

use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\CandidateStore;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Detector;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\RemediationAdvisor;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\RemediationLock;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\RunStore;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\ScanProgress;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\ToolRunner;
use WC_Subscription;

/**
 * AJAX endpoints for the Health Check admin surface.
 *
 * Handles suggest-remediation (GET) and tool-call (POST) requests from the
 * resolve-dialog modal, delegating to RemediationAdvisor and ToolRunner
 * for the actual work.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class AjaxController {

	/** @var RemediationLock */
	private RemediationLock $lock;

	/** @var CandidateStore */
	private CandidateStore $candidate_store;

	/** @var RunStore */
	private RunStore $run_store;

	/** @var RemediationAdvisor */
	private RemediationAdvisor $advisor;

	/** @var ToolRunner */
	private ToolRunner $runner;

	/**
	 * List-table renderer used by ajax_tool_call() to produce the
	 * transformed-row HTML when a successful action moves the
	 * subscription into a different signal. Optional so existing
	 * tests don't have to construct a list table.
	 *
	 * @var CandidatesListTable|null
	 */
	private ?CandidatesListTable $candidates_table;

	/**
	 * Single source of truth for the in-flight scan-progress reading,
	 * shared with StatusTab so the background poll and the server render
	 * report the same count + copy. Defaulted so existing callers
	 * (Bootstrap, tests) need no construction change.
	 *
	 * @var ScanProgress
	 */
	private ScanProgress $scan_progress;

	/**
	 * @param RemediationLock         $lock             Lock for serialising concurrent remediation requests.
	 * @param CandidateStore          $candidate_store  Candidate persistence layer.
	 * @param RunStore                $run_store        Scan-run persistence layer.
	 * @param RemediationAdvisor      $advisor          Classification advisor.
	 * @param ToolRunner              $runner           Remediation tool executor.
	 * @param CandidatesListTable|null $candidates_table List-table renderer for transformed-row HTML (optional).
	 * @param ScanProgress|null       $scan_progress    In-flight scan-progress reader for the status poll (optional).
	 */
	public function __construct(
		RemediationLock $lock,
		CandidateStore $candidate_store,
		RunStore $run_store,
		RemediationAdvisor $advisor,
		ToolRunner $runner,
		?CandidatesListTable $candidates_table = null,
		?ScanProgress $scan_progress = null
	) {
		$this->lock             = $lock;
		$this->candidate_store  = $candidate_store;
		$this->run_store        = $run_store;
		$this->advisor          = $advisor;
		$this->runner           = $runner;
		$this->candidates_table = $candidates_table;
		$this->scan_progress    = $scan_progress ?? new ScanProgress();
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_wcs_health_check_suggest_remediation', array( $this, 'ajax_suggest_remediation' ) );
		add_action( 'wp_ajax_wcs_health_check_tool_call', array( $this, 'ajax_tool_call' ) );
		add_action( 'wp_ajax_wcs_health_check_scan_status', array( $this, 'ajax_scan_status' ) );
	}

	/**
	 * Script data for the dialog JS — AJAX URL and nonces.
	 *
	 * Called by Bootstrap during asset enqueueing to provide the
	 * values `wp_localize_script()` passes to the client.
	 *
	 * @return array<string, string>
	 */
	public function get_script_data(): array {
		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'wcs_health_check_suggest_remediation' ),
			'toolNonce'   => wp_create_nonce( 'wcs_health_check_tool_call' ),
			'statusNonce' => wp_create_nonce( 'wcs_health_check_scan_status' ),
		);
	}

	/**
	 * AJAX handler: suggest remediation for a subscription and return the advisory JSON.
	 *
	 * @since 8.7.0
	 *
	 * @return void
	 */
	public function ajax_suggest_remediation(): void {
		check_ajax_referer( 'wcs_health_check_suggest_remediation', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woocommerce-subscriptions' ) ), 403 );
		}

		$subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
		$view            = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 0 === $subscription_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing subscription ID.', 'woocommerce-subscriptions' ) ), 400 );
		}

		// Narrow to subscriptions flagged by a scan run — mirrors the
		// guard in ajax_tool_call() so this endpoint can't be used to
		// probe arbitrary subscription IDs.
		$run_id = $this->run_store->get_latest_scan_run_id();

		if ( $run_id <= 0 || ! $this->candidate_store->is_pending_candidate( $run_id, $subscription_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This subscription is not a pending Health Check candidate. The list may be out of date — refresh the page and try again.', 'woocommerce-subscriptions' ) ),
				403
			);
		}

		// Per-view re-classify scope: 'missing_renewals'/'supports_auto_renewal' views pin the
		// advisor to their signal so a row promoted to another signal
		// returns null (surfaced as 'stale' to the client). 'all' and
		// missing-view values map to empty string -> null pass-through.
		$signal_type    = CandidatesListTable::signal_type_for_view( $view );
		$signal_type_or = '' === $signal_type ? null : $signal_type;
		$result         = $this->advisor->suggest_remediation( $subscription_id, $signal_type_or );

		// Stale at modal-open: the subscription drifted out of the
		// originating view's signal between the scan and the click.
		// Mark the candidate fixed (mirrors the ajax_tool_call stale
		// branch so a refresh doesn't resurrect the row) and ship the
		// same envelope shape with outcome='stale'. routeOutcome() on
		// the client side closes the modal, fades the row, and injects
		// the info notice — no second response shape to handle.
		if ( null === $result ) {
			// Scope the mark_fixed to the originating view's signal so
			// a stale row on one tab doesn't take a sibling row on the
			// other tab down with it.
			$this->candidate_store->mark_fixed( $run_id, $subscription_id, $signal_type_or );
			wp_send_json_success(
				$this->build_response_envelope( 'stale', $subscription_id, $run_id, $view )
			);
		}

		$result['runId'] = $run_id;
		$result          = $this->advisor->apply_action_labels( $result );

		// Ready: classification payload travels under the envelope's
		// `classification` key so the dialog can render the modal body
		// from a uniform `routeOutcome` entry point.
		$envelope                   = $this->build_response_envelope( 'ready', $subscription_id, $run_id, $view );
		$envelope['classification'] = $result;

		wp_send_json_success( $envelope );
	}

	/**
	 * AJAX handler: execute a remediation tool on a subscription.
	 *
	 * After the tool runs, re-classifies the subscription. If the issue
	 * is resolved, marks the candidate as fixed in the database.
	 *
	 * @since 8.7.0
	 *
	 * @return void
	 */
	public function ajax_tool_call(): void {
		check_ajax_referer( 'wcs_health_check_tool_call', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woocommerce-subscriptions' ) ), 403 );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$action          = isset( $_POST['tool_action'] ) ? sanitize_key( wp_unslash( $_POST['tool_action'] ) ) : '';
		$view            = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'supports_auto_renewal';

		// Derive the run id server-side rather than trusting $_POST['run_id'].
		// run_id drives the authorization gate (is_pending_candidate), the
		// mark_fixed target, and the badge counts; trusting a client value
		// would let an admin replay a stale run id to act on a subscription
		// already resolved in the current scan. Using the latest scan run is
		// also more correct: it evaluates against current state, so a sub a
		// newer scan has cleared correctly fails the pending-candidate gate.
		// Mirrors ajax_suggest_remediation().
		$run_id = $this->run_store->get_latest_scan_run_id();

		if ( 0 === $subscription_id || '' === $action ) {
			wp_send_json_error( array( 'message' => __( 'Missing subscription ID or action.', 'woocommerce-subscriptions' ) ), 400 );
		}

		// Defense-in-depth: narrow the authorization surface from
		// "any subscription a manage_woocommerce admin can name" down
		// to "subscriptions this scan run flagged as pending."
		// Rejects (a) crafted requests for arbitrary IDs, (b) stale
		// browser tabs trying to remediate a candidate a parallel
		// admin or cron has already resolved.
		if ( $run_id <= 0 || ! $this->candidate_store->is_pending_candidate( $run_id, $subscription_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This subscription is not a pending Health Check candidate. The list may be out of date — refresh the page and try again.', 'woocommerce-subscriptions' ) ),
				403
			);
		}

		// Serialise concurrent remediation requests for the same
		// subscription. ToolRunner has its own per-action idempotency
		// guards, but those read a pre-mutation snapshot from the
		// containing run() call and can be bypassed by two AJAX requests
		// that both load the snapshot before either has saved. The
		// HTTP-layer lock closes that millisecond window.
		if ( ! $this->lock->acquire( $subscription_id ) ) {
			// Surface lock contention so a flood of 409s on the client
			// side has a correlated server-side trail. Without this,
			// "every Resolve click fails" reports come in with nothing
			// to grep for in the WC log.
			wc_get_logger()->warning(
				sprintf(
					'Health Check: lock contention — action=%s subscription=%d user=%d',
					$action,
					$subscription_id,
					get_current_user_id()
				),
				array(
					'source'          => 'wcs-health-check',
					'action'          => $action,
					'subscription_id' => $subscription_id,
					'user_id'         => get_current_user_id(),
				)
			);
			wp_send_json_error(
				array( 'message' => __( 'Another remediation action is already in progress for this subscription. Please wait a moment and try again.', 'woocommerce-subscriptions' ) ),
				409
			);
		}

		try {
			$signal_type    = CandidatesListTable::signal_type_for_view( $view );
			$signal_type_or = '' === $signal_type ? null : $signal_type;
			$result         = $this->runner->run( $action, $subscription_id, $signal_type_or );
			$outcome        = (string) ( $result['outcome'] ?? 'failed' );

			// Mark the candidate as fixed when the action resolved the
			// signal OR when the click-time re-classify found the
			// subscription no longer matches any signal (stale). Both
			// outcomes mean this candidate row should not surface again
			// — without the stale branch, a page reload resurrects the
			// row and the merchant clicks it repeatedly with no effect.
			// Use the run_id passed from the classification response so we
			// mark the correct scan run, not a potentially newer one.
			// Scope to the originating view's signal so a sub flagged on
			// both tabs only loses the row the merchant acted on; its
			// sibling row on the other tab is independent (a Switch
			// action doesn't clear Missing renewals, and vice versa).
			// `mark_fixed()` logs its own DB failure; no need to surface
			// a separate warning on the response — the client routes on
			// `envelope.outcome` + `envelope.notice` and would not render
			// a warning field.
			if ( ! empty( $result['resolved'] ) || 'stale' === $outcome ) {
				$this->candidate_store->mark_fixed( $run_id, $subscription_id, $signal_type_or );
			} elseif ( null !== $signal_type_or && in_array( $outcome, array( 'transformed', 'failed' ), true ) ) {
				// The row stays in the list, but the action changed the
				// subscription (status flipped to on-hold, a renewal order
				// was created, etc.), so the stored candidate snapshot —
				// captured at scan time — is now stale. The in-place row
				// swap shows fresh data, but a page reload / search /
				// pagination re-renders from storage and would show e.g. a
				// stale renewal-order status, misleading the merchant into
				// re-processing a sub that already has a pending order.
				// Re-classify and overwrite the stored row so persisted
				// state matches the live swap. A no-longer-matching signal
				// returns nothing here (that's the resolved/stale branch
				// above), so this only fires when the row genuinely stays.
				$reclassified = ( new Detector() )->classify_ids( array( $subscription_id ), $signal_type_or );
				if ( isset( $reclassified[ $subscription_id ] ) ) {
					$this->candidate_store->add( $run_id, $subscription_id, $reclassified[ $subscription_id ], $signal_type_or );
				}
			}

			$envelope = $this->build_response_envelope(
				$outcome,
				$subscription_id,
				$run_id,
				$view
			);
		} finally {
			$this->lock->release( $subscription_id );
		}

		// Always wp_send_json_success — the JS routes on envelope.outcome,
		// not HTTP status. A 'failed' outcome still represents a fully-
		// handled response with a structured notice, not a transport error.
		wp_send_json_success( $envelope );
	}

	/**
	 * AJAX handler: report the current scan-progress reading for the background poll.
	 *
	 * Read-only — it mutates no scan state. health-check-admin.js polls this while a scan
	 * is in flight to update the inline "N of M subscriptions scanned" count in place
	 * (instead of the legacy 8 s full-page reload) and reloads the page once when the
	 * response reports `in_flight === false` (terminal state).
	 *
	 * @since 8.8.0
	 *
	 * @return void
	 */
	public function ajax_scan_status(): void {
		check_ajax_referer( 'wcs_health_check_scan_status', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woocommerce-subscriptions' ) ), 403 );
		}

		$status = $this->scan_progress->get_status();

		wp_send_json_success(
			array(
				'in_flight'     => (bool) $status['in_flight'],
				'run_id'        => (int) $status['run_id'],
				'scanned'       => (int) $status['scanned'],
				'total'         => (int) $status['total'],
				'progress_html' => (string) ( ScanProgress::format_label( (int) $status['scanned'], (int) $status['total'] ) ?? '' ),
				'progress_text' => (string) ( ScanProgress::format_text( (int) $status['scanned'], (int) $status['total'] ) ?? '' ),
			)
		);
	}

	/**
	 * Shape the uniform AJAX response envelope. Same shape for every
	 * terminal outcome; transformed adds `row_html`. The JS routes on
	 * `envelope.outcome`; no legacy field passthrough — the feature is
	 * unreleased so there is no back-compat surface to preserve.
	 *
	 * @param string $outcome         One of 'ready'|'resolved'|'transformed'|'failed'|'stale'.
	 *                                'ready' is the modal-open success
	 *                                outcome — callers should attach a
	 *                                `classification` payload separately.
	 * @param int    $subscription_id Subscription id.
	 * @param int    $run_id          Current scan run id (for badge counts).
	 * @param string $view            Current candidates-table view slug.
	 *
	 * @return array
	 */
	public function build_response_envelope( string $outcome, int $subscription_id, int $run_id, string $view ): array {
		$envelope = array(
			'outcome'         => $outcome,
			'subscription_id' => $subscription_id,
			'notice'          => $this->build_notice_payload( $outcome, $subscription_id ),
			'badges'          => $this->build_badge_counts( $run_id ),
		);

		// Re-render the row for both 'transformed' (a different signal
		// still applies, so the row stays but its data may have changed)
		// AND 'failed' (the action dispatched but didn't clear the
		// original signal — the row stays and may now carry updated
		// payment-retry or order-status data). Keeping the JS in sync
		// for both outcomes prevents stale row content surviving in the
		// list table.
		if ( 'transformed' === $outcome || 'failed' === $outcome ) {
			$row_html = $this->render_transformed_row( $subscription_id, $view );
			if ( '' !== $row_html ) {
				$envelope['row_html'] = $row_html;
			}
		}

		return $envelope;
	}

	/**
	 * Per-signal + total candidate counts for the given scan run.
	 *
	 * @param int $run_id Current scan run id.
	 *
	 * @return array{all: int, missing_renewal: int, supports_auto_renewal: int}
	 */
	private function build_badge_counts( int $run_id ): array {
		if ( $run_id <= 0 ) {
			return array(
				'all'                   => 0,
				'missing_renewal'       => 0,
				'supports_auto_renewal' => 0,
			);
		}

		$counts = $this->candidate_store->count_by_run_grouped( $run_id );
		return array(
			'all'                   => (int) ( $counts['total'] ?? 0 ),
			'missing_renewal'       => (int) ( $counts[ CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL ] ?? 0 ),
			'supports_auto_renewal' => (int) ( $counts[ CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL ] ?? 0 ),
		);
	}

	/**
	 * Render the post-action `<tr>` for a transformed subscription via
	 * the list-table helper. Returns an empty string when the helper
	 * isn't available (constructor was called without a list table) or
	 * when the subscription can't be loaded.
	 *
	 * @param int    $subscription_id Subscription id.
	 * @param string $view            Current view slug.
	 *
	 * @return string
	 */
	private function render_transformed_row( int $subscription_id, string $view ): string {
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			return '';
		}

		$table = $this->resolve_candidates_table();
		if ( ! $table instanceof CandidatesListTable ) {
			return '';
		}

		return $table->render_row_for( $subscription, $view );
	}

	/**
	 * Lazy-construct (or return the injected) CandidatesListTable.
	 *
	 * Eager construction in Bootstrap fatals because WP_List_Table's
	 * parent constructor calls `convert_to_screen()`, which is only
	 * defined after `wp-admin/includes/screen.php` loads — too late
	 * for the `init` hook where Bootstrap runs. Building the table
	 * here defers that work to AJAX-handler timing, by which time
	 * the wp-admin context is fully resolved.
	 *
	 * The injected-table branch keeps unit tests deterministic.
	 *
	 * @return CandidatesListTable|null
	 */
	private function resolve_candidates_table(): ?CandidatesListTable {
		if ( $this->candidates_table instanceof CandidatesListTable ) {
			return $this->candidates_table;
		}

		if ( ! class_exists( '\WP_List_Table' ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				return null;
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$this->candidates_table = new CandidatesListTable( $this->run_store, $this->candidate_store );
		return $this->candidates_table;
	}

	/**
	 * Build the notice payload that the client-side helper injects as
	 * a WP admin notice after a Resolve action terminates. Outcome
	 * drives both the notice class and the copy:
	 *
	 *   - 'resolved' / 'transformed' -> notice-success
	 *       "Subscription #N was successfully updated."
	 *   - 'failed'                   -> notice-error
	 *       "Subscription #N could not be updated. Please try again."
	 *   - 'stale'                    -> notice-info
	 *       "Subscription #N has been updated since the last scan
	 *        and is no longer flagged. The row has been removed
	 *        from the list."
	 *
	 * `#N` is rendered as a link to the subscription edit screen so
	 * the merchant can jump straight to the subscription from the
	 * notice. Returns an empty array for unknown outcomes so the
	 * caller can omit the notice key from the envelope.
	 *
	 * @param string $outcome         One of 'resolved', 'transformed', 'failed', 'stale'.
	 *                                'ready' (modal-open success) maps to an
	 *                                empty payload — no notice is rendered
	 *                                because the modal itself is the
	 *                                merchant-facing surface in that case.
	 * @param int    $subscription_id Subscription id rendered into the link.
	 *
	 * @return array{type: string, html: string}|array{}
	 */
	public function build_notice_payload( string $outcome, int $subscription_id ): array {
		if ( $subscription_id <= 0 ) {
			return array();
		}

		$edit_url = admin_url( 'post.php?post=' . $subscription_id . '&action=edit' );
		$link     = sprintf(
			'<a href="%1$s">#%2$d</a>',
			esc_url( $edit_url ),
			$subscription_id
		);

		switch ( $outcome ) {
			case 'resolved':
				return array(
					'type' => 'success',
					'html' => wp_kses_post(
						sprintf(
							/* translators: %s: Linked subscription id (e.g. #123). */
							__( 'Subscription %s was successfully updated.', 'woocommerce-subscriptions' ),
							$link
						)
					),
				);
			case 'transformed':
				// Distinct from 'resolved' so the merchant sees that the
				// renewal dispatched but the row stayed in the list — most
				// commonly because a manual sub is awaiting invoice payment.
				return array(
					'type' => 'success',
					'html' => wp_kses_post(
						sprintf(
							/* translators: %s: Linked subscription id (e.g. #123). */
							__( 'Subscription %s: renewal in progress. Awaiting customer payment.', 'woocommerce-subscriptions' ),
							$link
						)
					),
				);
			case 'failed':
				return array(
					'type' => 'error',
					'html' => wp_kses_post(
						sprintf(
							/* translators: %s: Linked subscription id (e.g. #123). */
							__( 'Subscription %s could not be updated. Please try again.', 'woocommerce-subscriptions' ),
							$link
						)
					),
				);
			case 'stale':
				return array(
					'type' => 'info',
					'html' => wp_kses_post(
						sprintf(
							/* translators: %s: Linked subscription id (e.g. #123). */
							__( 'Subscription %s has been updated since the last scan and is no longer flagged. The row has been removed from the list.', 'woocommerce-subscriptions' ),
							$link
						)
					),
				);
		}

		return array();
	}
}
