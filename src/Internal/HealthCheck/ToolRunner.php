<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use WC_Subscription;

/**
 * Executes Health Check remediation actions on a subscription.
 *
 * Each action constant from RemediationAdvisor maps to a concrete
 * mutation method. After executing the action, `run()` re-classifies
 * the subscription to determine whether the issue was resolved or
 * transformed into a different case.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class ToolRunner {

	private RemediationAdvisor $advisor;

	/**
	 * Snapshot of the advisor's classification of the subscription
	 * captured at the entry point of `run()`, before the action executes.
	 * Consumed by `classify_outcome()` (T6) to detect resolved vs
	 * transformed vs failed transitions.
	 *
	 * Reset to null on each invocation of `run()`.
	 *
	 * @var array|null
	 */
	private ?array $pre_dispatch_classification = null;

	public function __construct( ?RemediationAdvisor $advisor = null ) {
		$this->advisor = $advisor ?? new RemediationAdvisor();
	}

	/**
	 * Execute a remediation action and return the post-action state.
	 *
	 * @param string      $action          One of the RemediationAdvisor::ACTION_* constants.
	 * @param int         $subscription_id Subscription id.
	 * @param string|null $signal_type     Optional originating view signal type
	 *                                     (`CandidateStore::SIGNAL_TYPE_*`).
	 *                                     Forwarded to both the pre- and
	 *                                     post-dispatch advisor calls so the
	 *                                     re-classify guard scopes to the
	 *                                     view's signal. When null, severity
	 *                                     ordering applies as before.
	 *
	 * @return array{resolved: bool, explanation: string, ...}
	 */
	public function run( string $action, int $subscription_id, ?string $signal_type = null ): array {
		$this->pre_dispatch_classification = null;
		$user_id                           = get_current_user_id();

		// Audit-log the invocation up-front so an entry exists even
		// if a fatal kills the request before the result is computed.
		// WC order notes are merchant-facing context, not queryable
		// server logs — without this entry support has no way to
		// correlate a "my customer was double-charged" report with
		// the admin click that triggered it.
		wc_get_logger()->info(
			sprintf(
				'Health Check: remediation invoked — action=%s subscription=%d user=%d',
				$action,
				$subscription_id,
				$user_id
			),
			array(
				'source'          => 'wcs-health-check',
				'action'          => $action,
				'subscription_id' => $subscription_id,
				'user_id'         => $user_id,
			)
		);

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: remediation could not load subscription — action=%s subscription=%d',
					$action,
					$subscription_id
				),
				array(
					'source'          => 'wcs-health-check',
					'action'          => $action,
					'subscription_id' => $subscription_id,
				)
			);
			return array(
				'resolved'    => false,
				'outcome'     => 'failed',
				'explanation' => __( 'Subscription not found.', 'woocommerce-subscriptions' ),
			);
		}

		// Click-time re-classify guard. The advisor's suggest_remediation()
		// already drives Detector::classify_all_signals(); if it returns
		// null the subscription no longer matches any Health Check
		// signal — the state drifted between modal-open and click and
		// the action must not run.
		$this->pre_dispatch_classification = $this->advisor->suggest_remediation( $subscription_id, $signal_type );
		if ( null === $this->pre_dispatch_classification ) {
			wc_get_logger()->info(
				sprintf(
					'Health Check: stale candidate at click — action=%s subscription=%d user=%d',
					$action,
					$subscription_id,
					$user_id
				),
				array(
					'source'          => 'wcs-health-check',
					'action'          => $action,
					'subscription_id' => $subscription_id,
					'user_id'         => $user_id,
				)
			);
			return array(
				'resolved'    => false,
				'stale'       => true,
				'outcome'     => 'stale',
				'explanation' => __( 'This subscription has been updated since the last scan and is no longer flagged. The action was not performed.', 'woocommerce-subscriptions' ),
			);
		}

		try {
			$result = $this->execute( $action, $subscription );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: uncaught exception — action=%s subscription=%d — %s: %s',
					$action,
					$subscription_id,
					get_class( $e ),
					$e->getMessage()
				),
				array(
					'source'          => 'wcs-health-check',
					'subscription_id' => $subscription_id,
					'action'          => $action,
					'exception'       => $e,
				)
			);

			return array(
				'resolved'    => false,
				'outcome'     => 'failed',
				'explanation' => __( 'An unexpected error occurred while processing this subscription. Please try again or update manually.', 'woocommerce-subscriptions' ),
			);
		}

		if ( is_wp_error( $result ) ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: remediation returned WP_Error — action=%s subscription=%d code=%s',
					$action,
					$subscription_id,
					$result->get_error_code()
				),
				array(
					'source'          => 'wcs-health-check',
					'action'          => $action,
					'subscription_id' => $subscription_id,
					'error_code'      => $result->get_error_code(),
					'error_message'   => $result->get_error_message(),
				)
			);
			return array(
				'resolved'    => false,
				'outcome'     => 'failed',
				'explanation' => $result->get_error_message(),
			);
		}

		// Re-classify to see if the issue persists.
		$classification = $this->advisor->suggest_remediation( $subscription_id, $signal_type );
		$outcome        = self::classify_outcome( $this->pre_dispatch_classification, $classification );

		if ( 'resolved' === $outcome ) {
			return array(
				'resolved'        => true,
				'outcome'         => 'resolved',
				'explanation'     => sprintf(
					/* translators: %d: subscription ID */
					__( 'Subscription #%d has been resolved successfully.', 'woocommerce-subscriptions' ),
					$subscription_id
				),
				'subscriptionId'  => $subscription_id,
				'subscriptionUrl' => $subscription->get_edit_order_url(),
			);
		}

		// Add the localised button labels before returning. Without
		// this, the modal receives raw ACTION_* constants in
		// `primaryAction` / `secondaryAction` but no matching
		// `*Label` fields — jQuery's `.text(undefined)` is a no-op,
		// so the buttons keep the previous action's label while
		// `data-action` silently updates underneath, and the merchant
		// activates an action that doesn't match what the button says.
		$classification = $this->advisor->apply_action_labels( $classification );

		return array_merge(
			array(
				'resolved' => false,
				'outcome'  => $outcome,
			),
			$classification
		);
	}

	/**
	 * Compare a pre-action and post-action advisor classification to
	 * determine the click outcome.
	 *
	 * Under iteration 2's per-view re-classify scope, both the pre and
	 * post advisor calls are pinned to the originating view's signal,
	 * so the post payload (when non-null) always reports the same
	 * signal as pre. "Same signal still applies" is now the canonical
	 * `transformed` case (manual sub: invoice sent, sub on-hold,
	 * next-payment still past-due → row data updates, row stays).
	 *
	 *   - 'resolved'    — post is null. The action cleared the view's
	 *                     signal.
	 *   - 'transformed' — post is non-null. The action ran without
	 *                     throwing but the row's data changed and the
	 *                     view's signal still applies.
	 *
	 * `failed` is NOT produced here: a failed dispatch returns a
	 * WP_Error and `run()` sets `outcome` directly before this helper
	 * is reached.
	 *
	 * @param array|null $pre  Pre-action classification (advisor payload, or null).
	 * @param array|null $post Post-action classification (advisor payload, or null).
	 *
	 * @return string One of 'resolved', 'transformed'.
	 */
	public static function classify_outcome( ?array $pre, ?array $post ): string {
		unset( $pre );
		return null === $post ? 'resolved' : 'transformed';
	}

	/**
	 * Dispatch to the concrete tool method.
	 *
	 * @param string          $action       Action constant.
	 * @param WC_Subscription $subscription Subscription object.
	 *
	 * @return true|\WP_Error
	 */
	private function execute( string $action, WC_Subscription $subscription ) {
		switch ( $action ) {
			case RemediationAdvisor::ACTION_SWITCH_TO_AUTOMATIC:
				return $this->switch_to_automatic( $subscription );

			case RemediationAdvisor::ACTION_PROCESS_RENEWAL_NOW:
				return $this->process_renewal( $subscription );

			default:
				return new \WP_Error(
					'unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown remediation action: %s', 'woocommerce-subscriptions' ),
						$action
					)
				);
		}
	}

	/**
	 * Turn off manual renewal flag.
	 *
	 * @param WC_Subscription $subscription Subscription to modify.
	 *
	 * @return true|\WP_Error
	 */
	private function switch_to_automatic( WC_Subscription $subscription ) {
		// Re-fetch to guard against concurrent requests that already
		// cleared the manual flag — without this, two AJAX requests
		// that race past run()'s stale guard would both hold the pre-
		// mutation snapshot and both save the flag flip, producing
		// duplicate "Switched to automatic" order notes.
		$subscription = wcs_get_subscription( $subscription->get_id() );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return true;
		}

		if ( ! $subscription->is_manual() ) {
			return true;
		}

		$subscription->set_requires_manual_renewal( false );

		try {
			$subscription->save();
		} catch ( \Throwable $e ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: subscription #%1$d save failed after switch_to_automatic — %2$s: %3$s',
					$subscription->get_id(),
					get_class( $e ),
					$e->getMessage()
				),
				array(
					'source'          => 'wcs-health-check',
					'subscription_id' => $subscription->get_id(),
					'action'          => 'switch_to_automatic',
					'exception'       => $e,
				)
			);

			return new \WP_Error(
				'wcs_health_check_save_failed',
				__( 'The subscription could not be updated. Please try again or update manually.', 'woocommerce-subscriptions' )
			);
		}

		// Add the audit-trail note only after the manual flag is durably
		// persisted. add_order_note() writes immediately via
		// wp_insert_comment() and is not rolled back if save() throws —
		// adding it before save would leave a misleading "Switched to
		// automatic" trail on subscriptions that never actually flipped.
		$subscription->add_order_note(
			__( 'Switched to automatic renewal via Health Check.', 'woocommerce-subscriptions' )
		);

		return true;
	}

	/**
	 * Process the subscription's renewal: idempotency + status guards, then
	 * delegate the actual work to `trigger_renewal()`.
	 *
	 * We deliberately do NOT reactivate the subscription first (the earlier
	 * activate-then-dispatch approach). `update_status('active')` eagerly
	 * recalculates `next_payment` whenever the stored value is in the past
	 * or within two hours of now — advancing the schedule before any
	 * payment happened (hiding a still-broken sub from Missing renewals)
	 * and, when we tried to restore the stale timestamp afterwards, throwing
	 * "next_payment date must occur after the start date" for any sub whose
	 * start post-dates the stale next_payment (e.g. subscription #267).
	 * `trigger_renewal()` instead drives WCS's own `process_renewal()`
	 * scoped to the current status, which never recalculates next_payment
	 * for an on-hold transition.
	 *
	 * @param WC_Subscription $subscription Subscription to process.
	 *
	 * @return true|\WP_Error
	 */
	private function process_renewal( WC_Subscription $subscription ) {
		// Idempotency: skip if a renewal order was created in the last 60s.
		if ( $this->has_recent_renewal( $subscription, 60 ) ) {
			return true;
		}

		// Only active or on-hold subs can be renewed. Guard with a positive
		// allow-list rather than passing an arbitrary status through to
		// process_renewal(): pending-cancel subs are mid-teardown, and any
		// other status (cancelled, expired, pending, switched) means the
		// row drifted out of a renewable state between scan and click.
		// Return a clear error rather than acting on it or failing silently.
		if ( ! $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
			return new \WP_Error(
				'wcs_hc_unsupported_status',
				__( 'Processing the renewal is only available for active or on-hold subscriptions.', 'woocommerce-subscriptions' )
			);
		}

		return $this->trigger_renewal( $subscription );
	}

	/**
	 * Trigger the subscription's renewal: bail if a renewal is already in
	 * flight, drive the renewal through WCS's own
	 * `WC_Subscriptions_Manager::process_renewal()`, and — only once an order
	 * exists — cancel the stale queued action and charge.
	 *
	 * Order of operations matters:
	 *
	 *   1. If a `woocommerce_scheduled_subscription_payment` action is RUNNING,
	 *      a concurrent AS queue runner is renewing this sub right now — bail
	 *      with a WP_Error rather than racing it into a double charge.
	 *   2. `WC_Subscriptions_Manager::process_renewal()` runs with the sub's
	 *      CURRENT status (active S2a or on-hold S2b), which is what makes the
	 *      on-hold case work where the scheduled action's hardcoded 'active'
	 *      does not. It creates the order ($0 fast path, create-order retry,
	 *      manual-invoice vs auto-payment-method branch), flips the sub to
	 *      on-hold internally (on-hold transitions never recalculate
	 *      next_payment, so the schedule is preserved), and RETURNS the order
	 *      — or `false` when it declined to act (a gateway that bills on its
	 *      own schedule). A `false` return is surfaced as a failure; we do
	 *      NOT cancel the queued action in that case, so a sub we couldn't
	 *      renew keeps its schedule intact.
	 *   3. With an order in hand, cancel any still-PENDING queued action so it
	 *      can't re-fire and duplicate the renewal. (Running the queued action
	 *      ourselves wouldn't help — its `prepare_renewal` hardcodes
	 *      `process_renewal($id, 'active', …)`, which no-ops for an on-hold
	 *      sub.) Cancelling only after the order exists is what avoids
	 *      stranding a non-renewable sub.
	 *   4. Charge via `gateway_scheduled_subscription_payment()`, called
	 *      directly rather than by firing `woocommerce_scheduled_subscription_payment`:
	 *      this is a manual renewal, not a scheduled one. The handler is
	 *      self-guarding (skips manual / ended / already-paid).
	 *
	 * @param WC_Subscription $subscription Subscription to renew (status
	 *                                      guaranteed active or on-hold).
	 *
	 * @return true|\WP_Error
	 */
	private function trigger_renewal( WC_Subscription $subscription ) {
		// Args + group must match how WCS schedules the action in
		// WCS_Action_Scheduler (see get_action_args() and ACTION_GROUP).
		$subscription_id = (int) $subscription->get_id();
		$hook            = 'woocommerce_scheduled_subscription_payment';
		$args            = array( 'subscription_id' => $subscription_id );
		$group           = 'wc_subscription_scheduled_event';

		// A scheduled-payment action already in-progress means a concurrent
		// AS runner is renewing this sub right now — don't race it.
		$running_action = \ActionScheduler::store()->query_action(
			array(
				'hook'   => $hook,
				'args'   => $args,
				'group'  => $group,
				'status' => \ActionScheduler_Store::STATUS_RUNNING,
			)
		);
		if ( ! empty( $running_action ) ) {
			return new \WP_Error(
				'wcs_hc_renewal_in_progress',
				__( 'A scheduled renewal payment is already in progress for this subscription. Please wait for it to finish, then refresh the list.', 'woocommerce-subscriptions' )
			);
		}

		// Drive the renewal via WCS's own routine, scoped to the sub's
		// current status. process_renewal() creates the order (handling the
		// $0-total fast path, the create-order retry, and the manual-invoice
		// vs auto-payment-method branch) and returns it — or returns false
		// when it declines to act, which happens for a gateway that manages
		// its own scheduled payments (it only creates an order for manual /
		// $0 / no-method / non-gateway-scheduled subs). It throws if order
		// creation fails twice.
		try {
			$renewal_order = \WC_Subscriptions_Manager::process_renewal(
				$subscription_id,
				$subscription->get_status(),
				_x( 'Renewal processed via Health Check.', 'order note', 'woocommerce-subscriptions' )
			);
		} catch ( \Throwable $e ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: renewal processing failed for subscription #%1$d — %2$s: %3$s',
					$subscription_id,
					get_class( $e ),
					$e->getMessage()
				),
				array(
					'source'          => 'wcs-health-check',
					'subscription_id' => $subscription_id,
					'action'          => 'process_renewal',
					'exception'       => $e,
				)
			);

			return new \WP_Error(
				'wcs_hc_renewal_failed',
				__( 'The renewal could not be processed. Please review the subscription and retry from its edit screen.', 'woocommerce-subscriptions' )
			);
		}

		// A false return means nothing was created — process_renewal declined
		// to act (e.g. a gateway that bills on its own schedule). Surface it as
		// a failure rather than reporting success, and crucially do NOT touch
		// the queued action below: we have no renewal of our own to protect
		// against duplicating, and cancelling it would strand a subscription
		// whose renewal we couldn't drive.
		if ( ! $renewal_order instanceof \WC_Order ) {
			wc_get_logger()->warning(
				sprintf(
					'Health Check: process_renewal created no order for subscription #%d; nothing to charge.',
					$subscription_id
				),
				array(
					'source'          => 'wcs-health-check',
					'subscription_id' => $subscription_id,
					'action'          => 'process_renewal',
				)
			);

			return new \WP_Error(
				'wcs_hc_renewal_failed',
				__( 'The renewal could not be processed. Please review the subscription and retry from its edit screen.', 'woocommerce-subscriptions' )
			);
		}

		// A renewal order now exists. Cancel any still-pending scheduled-payment
		// action for this sub so it can't re-fire later and create a duplicate
		// renewal. We do this only now (after the order exists) so a sub we
		// couldn't renew above keeps its queued action intact.
		as_unschedule_all_actions( $hook, $args, $group );

		// Charge the order. gateway_scheduled_subscription_payment() re-fetches
		// the sub and is self-guarding — it skips manual subs, ended subs, a
		// missing renewal order, and orders that don't need payment (e.g. a $0
		// renewal process_renewal already completed) — so we call it
		// unconditionally. This is the priority-10 listener of a real scheduled
		// renewal; we invoke it directly rather than firing the scheduled-event
		// hook because this is a manual renewal, not a scheduled one.
		try {
			\WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment( $subscription_id );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: gateway renewal charge failed for subscription #%1$d — %2$s: %3$s',
					$subscription_id,
					get_class( $e ),
					$e->getMessage()
				),
				array(
					'source'          => 'wcs-health-check',
					'subscription_id' => $subscription_id,
					'action'          => 'process_renewal',
					'exception'       => $e,
				)
			);

			return new \WP_Error(
				'wcs_hc_renewal_failed',
				__( 'The renewal order was created but the gateway charge could not be dispatched. Please review the subscription and retry from its edit screen.', 'woocommerce-subscriptions' )
			);
		}

		return true;
	}

	/**
	 * Whether a renewal order was created for this subscription within the
	 * last `$window_seconds`. Used as an idempotency guard by actions that
	 * create renewal orders — if the user clicks the button twice (e.g.
	 * after a network timeout on the first attempt), the second call
	 * short-circuits instead of creating a duplicate renewal (and, for
	 * actions that also fire `woocommerce_scheduled_subscription_payment`,
	 * double-charging the customer).
	 *
	 * @param WC_Subscription $subscription    Subscription to check.
	 * @param int             $window_seconds  Lookback window in seconds.
	 *
	 * @return bool
	 */
	private function has_recent_renewal( WC_Subscription $subscription, int $window_seconds ): bool {
		$cutoff = time() - $window_seconds;

		// Renewal IDs are returned newest-first (arsort by ID). Check
		// only until we pass the cutoff — no need to load older orders.
		foreach ( $subscription->get_related_orders( 'ids', 'renewal' ) as $renewal_id ) {
			$renewal = wc_get_order( $renewal_id );
			if ( ! $renewal instanceof \WC_Order ) {
				continue;
			}
			$created = $renewal->get_date_created();
			if ( ! $created ) {
				continue;
			}
			if ( $created->getTimestamp() >= $cutoff ) {
				return true;
			}
			// Older than cutoff — all remaining are older too.
			break;
		}

		return false;
	}
}
