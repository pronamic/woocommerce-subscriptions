<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

/**
 * Adjunct to the dedicated-queue feature: raises Action Scheduler's allowed concurrent batches from the
 * out-of-the-box default of 1 to 2, leaving any non-default value (whether set by AS itself in future or by
 * another filter callback) untouched.
 *
 * The boost matters specifically when a dedicated WCS turn lands at the same time as a regular AS run — with
 * only one concurrent batch allowed, the dedicated turn would have to wait for the regular run to finish (or
 * vice versa), undermining the very thing the dedicated queue is trying to achieve. Letting two batches run
 * concurrently is enough to absorb that overlap without otherwise changing AS behaviour.
 *
 * Hooked at priority 1000 so we observe the value after any earlier callbacks have had their say; we only
 * intervene in the truly-default case to avoid silently overriding an explicit configuration choice.
 *
 * The class is inert until {@see setup()} is called. Wired up by {@see Manager} only when the merchant has
 * opted into "Dedicated processing" (see {@see Settings::OPTION_ENABLED}).
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Concurrent_Batches_Booster {

	/**
	 * Filter hook name owned by Action Scheduler. See
	 * `classes/abstracts/ActionScheduler_Abstract_QueueRunner.php::get_allowed_concurrent_batches()`.
	 */
	private const FILTER = 'action_scheduler_queue_runner_concurrent_batches';

	/**
	 * Filter priority. Late enough that any earlier callback's value is observed; only the still-default value
	 * of 1 is altered.
	 */
	private const PRIORITY = 1000;

	/**
	 * Register the filter callback. The class is inert until this is called.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_filter( self::FILTER, array( $this, 'maybe_bump' ), self::PRIORITY );
	}

	/**
	 * If the inbound concurrent-batches value is the AS default (1), bump it to 2; otherwise pass through
	 * unchanged. Non-integer inputs are also passed through — bumping a value we don't recognise risks
	 * stomping on a third-party convention.
	 *
	 * Public because WordPress's hook system invokes it; not intended for direct consumption.
	 *
	 * @param mixed $value Current concurrent-batches value as supplied by Action Scheduler / earlier filters.
	 *
	 * @return mixed
	 */
	public function maybe_bump( $value ) {
		if ( ! is_int( $value ) ) {
			return $value;
		}
		return 1 === $value ? 2 : $value;
	}
}
