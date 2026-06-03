<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Telemetry;

use ActionScheduler_Store;
use WCS_Action_Scheduler;

/**
 * Operational telemetry about Action Scheduler health, as observed from this site.
 *
 * Unlike its sibling collectors (Orders, Products, Subscriptions), this class does not describe the merchant's
 * subscription portfolio. It describes whether background processing is keeping up: specifically, how many AS
 * actions are past-due (overall, and within the WooCommerce Subscriptions group). It is useful for spotting
 * stuck queues in aggregate without needing to ship full action lists.
 *
 * "Past-due" follows AS's own definition — pending status with a scheduled run-time more than
 * `action_scheduler_pastdue_actions_seconds` (default 1 day) in the past. Applying that same filter here
 * keeps the metric consistent with whatever threshold the site operator has tuned.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Background_Processing {
	/**
	 * Count of past-due pending actions across all groups (site-wide).
	 *
	 * @return int
	 */
	public function get_past_due_action_count(): int {
		return $this->count_past_due_actions( null );
	}

	/**
	 * Count of past-due pending actions in the WooCommerce Subscriptions action group
	 * ({@see WCS_Action_Scheduler::ACTION_GROUP}).
	 *
	 * @return int
	 */
	public function get_past_due_action_count_in_subscriptions_group(): int {
		return $this->count_past_due_actions( WCS_Action_Scheduler::ACTION_GROUP );
	}

	/**
	 * Shared implementation that asks the AS store for past-due pending actions, optionally restricted to a
	 * single group.
	 *
	 * @param string|null $group AS group slug to restrict the count to, or null for site-wide.
	 *
	 * @return int
	 */
	private function count_past_due_actions( ?string $group ): int {
		if ( ! class_exists( ActionScheduler_Store::class, false ) ) {
			return 0;
		}

		$threshold_seconds = (int) apply_filters( 'action_scheduler_pastdue_actions_seconds', DAY_IN_SECONDS );

		$query = array(
			'date'     => as_get_datetime_object( time() - $threshold_seconds ),
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		);

		if ( null !== $group ) {
			$query['group'] = $group;
		}

		$count = ActionScheduler_Store::instance()->query_actions( $query, 'count' );

		return max( 0, (int) $count );
	}
}
