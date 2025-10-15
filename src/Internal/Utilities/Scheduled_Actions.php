<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Utilities;

/**
 * Utilities to ease working with Action Scheduler, and to help safely take advantage of modern Action Scheduler
 * features in a backwards-compatible way.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Scheduled_Actions {
	/**
	 * Schedules a recurring action.
	 *
	 * This method offers two advantages:
	 *
	 * 1. It automatically takes advantage of the `action_scheduler_ensure_recurring_actions` hook, if available, which
	 *    minimizes overhead.
	 * 2. It can be called before Action Scheduler has initialized (in this case, it takes care of registering the
	 *    action later in the request).
	 *
	 * The second point in particular presents a trade-off since, unlike a direct call to as_schedule_recurring_action()
	 * there is no return value to indicate success/failure. When this is needed, you should handle things directly.
	 *
	 * @param int    $timestamp
	 * @param int    $interval_in_seconds
	 * @param string $hook
	 * @param array  $args
	 * @param string $group
	 * @param bool   $unique
	 * @param int    $priority
	 *
	 * @return void
	 */
	public static function schedule_recurring_action(
		int $timestamp,
		int $interval_in_seconds,
		string $hook,
		array $args = array(),
		string $group = '',
		bool $unique = false,
		int $priority = 10
	) {
		// If this method is called too early, Action Scheduler will not be ready. Therefore, defer until it is ready.
		if ( ! did_action( 'action_scheduler_init' ) && ! doing_action( 'action_scheduler_init' ) ) {
			add_action( 'action_scheduler_init', fn() => self::schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args, $group, $unique, $priority ) );
			return;
		}

		$register_action = static function () use ( $timestamp, $interval_in_seconds, $hook, $args, $group, $unique, $priority ) {
			// Older versions of Action Scheduler don't support 'uniqueness', or don't support it evenly (across data
			// stores), so we add an additional safeguard.
			if ( $unique && as_has_scheduled_action( $hook, $args, $group ) ) {
				return 0;
			}

			as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args, $group, $unique, $priority );
		};

		// Since Action Scheduler 3.9.3 it is generally preferable to register recurring actions during the action
		// that the library provides for this purpose.
		$registration_hook = function_exists( 'as_supports' ) && as_supports( 'ensure_recurring_actions_hook' )
			? 'action_scheduler_ensure_recurring_actions'
			: 'admin_init';

		// @phpstan-ignore return.void
		add_action( $registration_hook, $register_action );
	}
}
