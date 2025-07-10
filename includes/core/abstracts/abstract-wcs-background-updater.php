<?php
/**
 * Debug Tool with methods to update data in the background
 *
 * Add tools for debugging and managing Subscriptions to the
 * WooCommerce > System Status > Tools administration screen.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.3
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * WCS_Background_Updater Class
 *
 * Provide APIs for a debug tool to update data in the background using Action Scheduler.
 */
abstract class WCS_Background_Updater {

	/**
	 * @var int The amount of time, in seconds, to give the background process to run the update.
	 */
	protected $time_limit;

	/**
	 * @var string The hook used to schedule background updates.
	 */
	protected $scheduled_hook;

	/**
	 * Attach callbacks to hooks
	 */
	public function init() {

		// Make sure child classes have defined a scheduled hook, otherwise we can't do background updates.
		if ( is_null( $this->scheduled_hook ) ) {
			throw new RuntimeException( __CLASS__ . ' must assign a hook to $this->scheduled_hook' );
		}

		if ( is_null( $this->time_limit ) ) {

			$this->time_limit = 60;

			// Allow more time for CLI requests, as they're not beholden to script timeouts
			if ( $this->is_wp_cli_request() ) {
				$this->time_limit *= 3;
			}
		}

		// Allow for each class's time limit to be customised by 3rd party code, as well as all tools' time limits
		$this->time_limit = apply_filters( 'wcs_debug_tools_time_limit', $this->time_limit, $this );

		// Action scheduled in Action Scheduler for updating data in the background
		add_action( $this->scheduled_hook, array( $this, 'run_update' ) );
	}

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 */
	abstract protected function get_items_to_update();

	/**
	 * Run the update for a single item.
	 *
	 * @param mixed $item The item to update.
	 */
	abstract protected function update_item( $item );

	/**
	 * Update a set of items in the background.
	 *
	 * This method will loop over until there are no more items to update, or the process has been running for the
	 * time limit set on the class @see $this->time_limit, which is 60 seconds by default (wall clock time, not
	 * execution time).
	 *
	 * The $scheduler_hook is rescheduled before updating any items something goes wrong when processing a batch - it's
	 * scheduled for $this->time_limit in future, so there's little chance of duplicate processes running at the same
	 * time with WP Cron, but importantly, there is some chance so it should not be used for critical data, like
	 * payments. Instead, it is intended for use for things like cache updates. It's also a good idea to use an atomic
	 * update methods to avoid updating something that has already been updated in a separate request.
	 *
	 * Importantly, the overlap between the next scheduled update and the current batch is also useful for running
	 * Action Scheduler via WP CLI, because it will allow for continuous execution of updates (i.e. updating a new
	 * batch as soon as one batch has exceeded the time limit rather than having to run Action Scheduler via WP CLI
	 * again later).
	 */
	public function run_update() {

		$this->schedule_background_update();

		// If the update is being run via WP CLI, we don't need to worry about the request time, just the processing time for this method
		// @phpstan-ignore constant.notFound
		$start_time = $this->is_wp_cli_request() ? (int) gmdate( 'U' ) : WCS_INIT_TIMESTAMP;

		do {

			$items = $this->get_items_to_update();

			foreach ( $items as $item ) {

				$this->update_item( $item );

				$time_elapsed = (int) gmdate( 'U' ) - $start_time;

				if ( $time_elapsed >= $this->time_limit ) {
					break 2;
				}
			}
		} while ( ! empty( $items ) );

		// If we stopped processing the batch because we ran out of items to process, not because we ran out of time, we don't need to run any other batches
		if ( empty( $items ) ) {
			$this->unschedule_background_updates();
		}
	}

	/**
	 * Schedule the instance's hook to run in $this->time_limit seconds, if it's not already scheduled.
	 */
	protected function schedule_background_update() {
		// A timestamp is returned if there's a pending action already scheduled. Otherwise true if its running or false if one doesn't exist.
		if ( ! is_numeric( as_next_scheduled_action( $this->scheduled_hook ) ) ) {
			as_schedule_single_action( (int) gmdate( 'U' ) + $this->time_limit, $this->scheduled_hook );
		}
	}

	/**
	 * Unschedule the instance's hook in Action Scheduler
	 */
	protected function unschedule_background_updates() {
		as_unschedule_action( $this->scheduled_hook );
	}

	/**
	 * Check whether the current request is via WP CLI
	 *
	 * @return bool
	 */
	protected function is_wp_cli_request() {
		return ( defined( 'WP_CLI' ) && WP_CLI );
	}
}
