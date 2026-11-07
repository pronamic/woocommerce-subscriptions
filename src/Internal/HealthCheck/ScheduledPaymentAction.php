<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use WC_Subscription;

/**
 * Coordinates detection and repair of a missing WCS payment action.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class ScheduledPaymentAction {

	/**
	 * Use only the actual stock scheduler and its initialized date-type policy.
	 */
	public function schedules_next_payment(): bool {
		$scheduler = $this->stock_scheduler();
		return null !== $scheduler && $scheduler->schedules_date_type( 'next_payment' );
	}

	/**
	 * The scan and click-time repair share this future-payment boundary.
	 */
	public function is_eligible_future_payment( WC_Subscription $subscription ): bool {
		if ( 'active' !== $subscription->get_status() || $subscription->is_manual() || $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) {
			return false;
		}

		$payment_timestamp = (int) $subscription->get_time( 'next_payment' );
		$end_timestamp     = (int) $subscription->get_time( 'end' );
		if ( $payment_timestamp <= time() || ( $end_timestamp > 0 && $end_timestamp <= $payment_timestamp ) ) {
			return false;
		}

		return $this->schedules_next_payment();
	}

	/**
	 * Get the scheduler already installed by the core plugin, if it is unmodified.
	 */
	private function stock_scheduler(): ?\WCS_Action_Scheduler {
		$core = \WC_Subscriptions_Core_Plugin::instance();
		if ( ! $core instanceof \WC_Subscriptions_Core_Plugin ) {
			return null;
		}

		$scheduler = $core->get_scheduler();
		return is_object( $scheduler ) && \WCS_Action_Scheduler::class === get_class( $scheduler ) ? $scheduler : null;
	}

	/**
	 * @return array{hook: string, args: array}|null
	 */
	public function identity( WC_Subscription $subscription ): ?array {
		$scheduler = $this->stock_scheduler();
		return null === $scheduler ? null : $scheduler->get_scheduled_action_identity( $subscription, 'next_payment' );
	}

	/**
	 * Include pending and running actions in any group, like WCS itself.
	 */
	public function has_equivalent( WC_Subscription $subscription ): bool {
		$identity = $this->identity( $subscription );
		return null !== $identity && $this->has_equivalent_identity( $identity );
	}

	/**
	 * Check an already resolved identity without running extension filters again.
	 *
	 * @param array{hook: string, args: array} $identity Payment action identity.
	 */
	public function has_equivalent_identity( array $identity ): bool {
		return function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( $identity['hook'], $identity['args'] );
	}

	/**
	 * Add the stored future payment to the WCS queue if still absent.
	 *
	 * @return true|\WP_Error
	 */
	public function schedule( WC_Subscription $subscription ) {
		$subscription = wcs_get_subscription( $subscription->get_id() );
		if ( ! $subscription instanceof WC_Subscription ) {
			return new \WP_Error( 'woocommerce_subscriptions_payment_subscription_missing', __( 'The subscription could not be loaded for scheduling.', 'woocommerce-subscriptions' ) );
		}

		if ( ! $this->is_eligible_future_payment( $subscription ) ) {
			return new \WP_Error( 'woocommerce_subscriptions_unschedulable_payment', __( 'This subscription no longer has an eligible future payment to schedule.', 'woocommerce-subscriptions' ) );
		}

		$timestamp = (int) $subscription->get_time( 'next_payment' );
		$identity  = $this->identity( $subscription );
		if ( null === $identity ) {
			return new \WP_Error( 'woocommerce_subscriptions_invalid_payment_action', __( 'The payment action could not be identified.', 'woocommerce-subscriptions' ) );
		}
		if ( $this->has_equivalent_identity( $identity ) ) {
			return true;
		}

		$scheduler = $this->stock_scheduler();
		if ( null === $scheduler || ! function_exists( 'as_schedule_single_action' ) ) {
			return new \WP_Error( 'woocommerce_subscriptions_action_scheduler_unavailable', __( 'Action Scheduler is not available.', 'woocommerce-subscriptions' ) );
		}

		try {
			$action_id = $scheduler->schedule_action_add_only( $timestamp, $identity );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: subscription #%1$d could not schedule renewal action %2$s - %3$s: %4$s',
					$subscription->get_id(),
					$identity['hook'],
					get_class( $e ),
					$e->getMessage()
				),
				array(
					'source'          => 'wcs-health-check',
					'subscription_id' => $subscription->get_id(),
					'action'          => RemediationAdvisor::ACTION_SCHEDULE_RENEWAL,
					'action_hook'     => $identity['hook'],
					'exception'       => $e,
				)
			);

			return new \WP_Error( 'woocommerce_subscriptions_payment_action_not_saved', __( 'The renewal action could not be scheduled. Please try again.', 'woocommerce-subscriptions' ) );
		}

		if ( ! is_numeric( $action_id ) || (int) $action_id <= 0 ) {
			return new \WP_Error( 'woocommerce_subscriptions_payment_action_not_saved', __( 'The renewal action could not be scheduled. Please try again.', 'woocommerce-subscriptions' ) );
		}

		$subscription->add_order_note(
			sprintf(
				/* translators: %s: the subscription's next payment date and time. */
				__( 'Health Check restored the scheduled renewal action for %s.', 'woocommerce-subscriptions' ),
				wp_date( wc_date_format() . ' ' . wc_time_format(), $timestamp )
			)
		);

		return true;
	}
}
