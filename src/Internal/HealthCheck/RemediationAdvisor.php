<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use WC_Subscription;

/**
 * Given a subscription id, decide which Health Check test case it falls
 * into (TC-S1a, TC-S2a, TC-S2b) and return a merchant-facing payload
 * with an explanation and the two recommended action tool-names.
 *
 * Test cases:
 *
 *   - TC-S1a (manual flag stuck) — subscription is on manual renewal
 *     with a valid token on a gateway that supports automatic renewal;
 *     action is "switch to automatic." Copy varies by two orthogonal
 *     signals (prior opt-out, failed/pending latest renewal) into a
 *     2x2 inline matrix of variants.
 *   - TC-S2a (missing schedule) — no scheduled next payment date;
 *     action is "reschedule next renewal."
 *   - TC-S2b (past-due, no renewal) — a renewal cycle was missed;
 *     action is "process missed renewal now."
 *
 * Resolution order is severity-driven and root-cause-aware:
 *   TC-S2b > TC-S1a > TC-S2a.
 *
 * Returns null when the subscription matches no case under the current
 * Detector signals — callers treat that as "nothing to advise."
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class RemediationAdvisor {

	public const CASE_S1A = 'TC-S1a';
	public const CASE_S2A = 'TC-S2a';
	public const CASE_S2B = 'TC-S2b';

	public const ACTION_SWITCH_TO_AUTOMATIC = 'switch_to_automatic_renewal';
	public const ACTION_PROCESS_RENEWAL_NOW = 'process_renewal_now';

	/**
	 * Detector instance providing the canonical per-id classification.
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * @param Detector|null $detector Optional injection point for tests.
	 */
	public function __construct( ?Detector $detector = null ) {
		$this->detector = $detector ?? new Detector();
	}

	/**
	 * Map an action constant to a merchant-facing button label.
	 *
	 * @param string $action One of the ACTION_* constants.
	 *
	 * @return string Translated button label.
	 */
	public function action_label( string $action ): string {
		switch ( $action ) {
			case self::ACTION_SWITCH_TO_AUTOMATIC:
				return __( 'Switch to automatic renewal', 'woocommerce-subscriptions' );
			case self::ACTION_PROCESS_RENEWAL_NOW:
				return __( 'Process renewal now', 'woocommerce-subscriptions' );
			default:
				return $action;
		}
	}

	/**
	 * Suggest remediation for a single subscription.
	 *
	 * When `$signal_type` is supplied the advisor only considers that
	 * signal's classification — used by per-view re-classify so a row
	 * promoted to a different signal (e.g. an S1a auto-switch produced
	 * an S2a missing-renewal residue) is reported as `stale` for the
	 * originating view rather than silently switched to a different
	 * remediation. When null, the legacy severity-ordered selection
	 * applies (TC-S2b > TC-S1a > TC-S2a).
	 *
	 * @param int         $subscription_id Subscription post / order id.
	 * @param string|null $signal_type     Optional signal-type scope
	 *                                     (`CandidateStore::SIGNAL_TYPE_*`).
	 *                                     Null falls back to severity order.
	 *
	 * @return array{
	 *   subscriptionId: int,
	 *   case: string,
	 *   explanation: string,
	 *   primaryAction: string,
	 *   secondaryAction: string|null
	 * }|null
	 */
	public function suggest_remediation( int $subscription_id, ?string $signal_type = null ): ?array {
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			return null;
		}

		$classifications = $this->detector->classify_all_signals( $subscription );
		$missing_renewal = $classifications[ CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL ];
		$supports_auto   = $classifications[ CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL ];

		// Per-view scope: zero in on a single signal. The view determines
		// which remediation a merchant sees so that re-classify produces a
		// `stale` outcome (signal vanished or transformed into another
		// view's signal) rather than swapping a different remediation in.
		if ( CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL === $signal_type ) {
			$supports_auto = null;
		} elseif ( CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL === $signal_type ) {
			$missing_renewal = null;
		}

		$missing_state = $missing_renewal ? ( $missing_renewal['details']['next_payment_state'] ?? null ) : null;

		// Severity-ordered selection: TC-S2b > TC-S1 > TC-S2a. Returns the
		// first builder that produces a candidate; all builders now ship a
		// non-empty action, so no fallthrough is needed.
		if ( 'past_due' === $missing_state ) {
			$result = $this->build_for_missing_renewal( $subscription, $missing_renewal );
		} elseif ( null !== $supports_auto ) {
			$result = $this->build_for_supports_auto_renewal( $subscription, $supports_auto );
		} elseif ( 'missing' === $missing_state ) {
			$result = $this->build_for_missing_renewal( $subscription, $missing_renewal );
		} else {
			return null;
		}

		$result['subscriptionUrl'] = esc_url( $subscription->get_edit_order_url() );
		return $result;
	}

	/**
	 * Build the TC-S1a advisory from a Supports-auto-renewal
	 * classification. Composes copy from two orthogonal signals — whether
	 * the subscriber previously opted out of automatic renewal, and
	 * whether the most recent renewal payment failed — into a 2x2
	 * inline matrix of variants (Default, Opted out, Failed renewal,
	 * Combined). All variants share the same case (S1a) and primary
	 * action (Switch to automatic renewal); only the body copy differs.
	 *
	 * The earlier S1b split has been folded into the Failed-renewal
	 * variant: the action is no longer "switch and retry" because
	 * retries are owned by the gateway / scheduled payment pipeline.
	 *
	 * @param WC_Subscription                                                 $subscription   Subscription under analysis.
	 * @param array{signals: string[], details: array<string, mixed>}         $classification Detector payload for this sub.
	 *
	 * @return array{
	 *   subscriptionId: int,
	 *   case: string,
	 *   title: string,
	 *   explanation: string,
	 *   primaryAction: string,
	 *   secondaryAction: string|null,
	 *   cancelLabel: string
	 * }
	 */
	private function build_for_supports_auto_renewal( WC_Subscription $subscription, array $classification ): array {
		$details         = $classification['details'];
		$subscription_id = (int) $subscription->get_id();

		$is_opted_out = 'opted_out' === ( $details['renewal_preference'] ?? null );
		$is_failed    = in_array(
			$details['latest_renewal_status'] ?? '',
			array( 'failed', 'pending' ),
			true
		);

		if ( $is_failed && $is_opted_out ) {
			$explanation = __( "This subscription's most recent renewal payment failed, and automatic renewal was previously turned off. Switching the billing mode will update the renewal setting only — it won't retry the payment. Automatic renewals will resume once the failed renewal is resolved.", 'woocommerce-subscriptions' )
				. "\n\n"
				. __( 'Switch the billing mode on this subscription?', 'woocommerce-subscriptions' );
		} elseif ( $is_failed ) {
			$explanation = __( "This subscription's most recent renewal payment failed. Switching the billing mode will update the renewal setting only — it won't retry the payment. Automatic renewals will resume once the failed renewal is resolved.", 'woocommerce-subscriptions' )
				. "\n\n"
				. __( 'Switch the billing mode on this subscription?', 'woocommerce-subscriptions' );
		} elseif ( $is_opted_out ) {
			$explanation = __( 'Automatic renewal was previously turned off on this subscription. Switching the billing mode will turn it back on and begin charging the saved payment method on the next renewal date.', 'woocommerce-subscriptions' )
				. "\n\n"
				. __( 'Switch the billing mode on this subscription?', 'woocommerce-subscriptions' );
		} else {
			$explanation = __( 'This subscription is set to manual renewal. Switching the billing mode will activate automatic renewal and begin charging the saved payment method on the next renewal date.', 'woocommerce-subscriptions' )
				. "\n\n"
				. __( 'Switch the billing mode on this subscription?', 'woocommerce-subscriptions' );
		}

		return array(
			'subscriptionId'  => $subscription_id,
			'case'            => self::CASE_S1A,
			'title'           => __( 'Switch billing mode', 'woocommerce-subscriptions' ),
			'explanation'     => $explanation,
			'primaryAction'   => self::ACTION_SWITCH_TO_AUTOMATIC,
			'secondaryAction' => null,
			'cancelLabel'     => __( 'Cancel', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * Build the TC-S2a / TC-S2b advisory from a Missing-renewal
	 * classification. Pivots on `next_payment_state`:
	 *
	 *   - `'missing'` -> TC-S2a (no scheduled date at all). Secondary
	 *     button depends on `billing_mode` — auto subs get
	 *     "process renewal now", manual subs get "send invoice."
	 *   - `'past_due'` -> TC-S2b (date is set but stale and no renewal
	 *     order matched it).
	 *
	 * @param WC_Subscription                                                 $subscription   Subscription under analysis.
	 * @param array{signals: string[], details: array<string, mixed>}         $classification Detector payload for this sub.
	 *
	 * @return array{
	 *   subscriptionId: int,
	 *   case: string,
	 *   explanation: string,
	 *   primaryAction: string,
	 *   secondaryAction: string|null
	 * }|null
	 */
	private function build_for_missing_renewal( WC_Subscription $subscription, array $classification ): ?array {
		$details = $classification['details'];
		$state   = (string) ( $details['next_payment_state'] ?? '' );

		if ( 'missing' === $state ) {
			return $this->build_for_missing_next_payment_date( $subscription, $details );
		}

		if ( 'past_due' === $state ) {
			return $this->build_for_past_due_next_payment_date( $subscription, $details );
		}

		return null;
	}

	/**
	 * TC-S2a — the sub has no scheduled next payment AND no future end
	 * date, so it will neither renew nor expire on its own.
	 *
	 * The single primary action is "Process renewal now". The modal
	 * body copy varies by billing mode because the downstream effect
	 * differs (auto: charge saved method; manual: send invoice).
	 *
	 * @param WC_Subscription      $subscription Subscription under analysis.
	 * @param array<string, mixed> $details      Detector details payload.
	 *
	 * @return array{
	 *   subscriptionId: int,
	 *   case: string,
	 *   explanation: string,
	 *   primaryAction: string,
	 *   secondaryAction: string|null
	 * }
	 */
	private function build_for_missing_next_payment_date( WC_Subscription $subscription, array $details ): array {
		$subscription_id = (int) $subscription->get_id();
		$is_manual       = 'manual' === (string) ( $details['billing_mode'] ?? 'auto' );

		$lead        = $is_manual
			? __( "This subscription has no scheduled next payment date — it won't renew or expire on its own. Processing the renewal now will send the customer a renewal invoice. Billing resumes once they pay it.", 'woocommerce-subscriptions' )
			: __( "This subscription has no scheduled next payment date — it won't renew or expire on its own. Processing the renewal now will charge the saved payment method and resume billing for the subscription.", 'woocommerce-subscriptions' );
		$explanation = $lead . "\n\n" . __( 'Process the renewal now?', 'woocommerce-subscriptions' );

		return array(
			'subscriptionId'  => $subscription_id,
			'case'            => self::CASE_S2A,
			'title'           => __( 'Process renewal', 'woocommerce-subscriptions' ),
			'explanation'     => $explanation,
			'primaryAction'   => self::ACTION_PROCESS_RENEWAL_NOW,
			'secondaryAction' => null,
			'cancelLabel'     => __( 'Cancel', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * TC-S2b — the sub has a next payment date strictly in the past
	 * (beyond the tolerance window) AND no renewal order was created
	 * near that date.
	 *
	 * @param WC_Subscription      $subscription Subscription under analysis.
	 * @param array<string, mixed> $details      Detector details payload.
	 *
	 * @return array{
	 *   subscriptionId: int,
	 *   case: string,
	 *   explanation: string,
	 *   primaryAction: string,
	 *   secondaryAction: string|null
	 * }
	 */
	private function build_for_past_due_next_payment_date( WC_Subscription $subscription, array $details ): array {
		$subscription_id = (int) $subscription->get_id();
		$is_manual       = 'manual' === (string) ( $details['billing_mode'] ?? 'auto' );

		$lead        = $is_manual
			? __( "This subscription's next payment date has passed but its renewal hasn't been processed. Processing the renewal now will send the customer a renewal invoice. Billing resumes once they pay it.", 'woocommerce-subscriptions' )
			: __( "This subscription's next payment date has passed but its renewal hasn't been processed. Processing the renewal now will charge the saved payment method and resume billing for the subscription.", 'woocommerce-subscriptions' );
		$explanation = $lead . "\n\n" . __( 'Process the renewal now?', 'woocommerce-subscriptions' );

		return array(
			'subscriptionId'  => $subscription_id,
			'case'            => self::CASE_S2B,
			'title'           => __( 'Process renewal', 'woocommerce-subscriptions' ),
			'explanation'     => $explanation,
			'primaryAction'   => self::ACTION_PROCESS_RENEWAL_NOW,
			'secondaryAction' => null,
			'cancelLabel'     => __( 'Cancel', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * Add action labels to a payload.
	 *
	 * @param array $result Payload to augment.
	 * @return array Payload with action labels.
	 */
	public function apply_action_labels( array $result ): array {
		$result['primaryActionLabel']   = ! empty( $result['primaryAction'] )
			? $this->action_label( $result['primaryAction'] )
			: null;
		$result['secondaryActionLabel'] = ! empty( $result['secondaryAction'] )
			? $this->action_label( $result['secondaryAction'] )
			: null;

		return $result;
	}
}
