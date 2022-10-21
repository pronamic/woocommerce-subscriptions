<?php
/**
 * WCS_Background_Repairer Class
 *
 * Provide APIs for a repair script to find objects which need repairing, schedule a separate background process for each object using Action Scheduler, and then in that background process update data for that object.
 *
 * @author   WooCommerce
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Upgrades
 * @since    2.6.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WCS_Background_Repairer extends WCS_Background_Upgrader {

	/**
	 * @var string The hook used to schedule background repairs for a specific object.
	 */
	protected $repair_hook;

	/**
	 * An internal cache of items which need to be repaired. Used in cases where the updater runs out of processing time, so we can ensure remaining items are processed in the next request.
	 *
	 * @var array
	 */
	protected $items_to_repair = array();

	/**
	 * Attaches callbacks to hooks.
	 *
	 * @since 2.6.0
	 * @see WCS_Background_Updater::init() for additional hooks and callbacks.
	 */
	public function init() {
		parent::init();
		add_action( $this->repair_hook, array( $this, 'repair_item' ) );
	}

	/**
	 * Schedules the @see $this->scheduled_hook action to run in
	 * @see $this->time_limit seconds (60 seconds by default).
	 *
	 * Sets the page to 1.
	 *
	 * @since 2.6.0
	 */
	public function schedule_repair() {
		$this->set_page( 1 );
		parent::schedule_repair();
	}

	/**
	 * Gets a batch of items which need to be repaired.
	 *
	 * @since 2.6.0
	 * @return array An array of items which need to be repaired.
	 */
	protected function get_items_to_update() {
		$items_to_repair = array();

		// Check if there are items from the last request that we should process first.
		$unprocessed_items = $this->get_unprocessed_items();

		if ( ! empty( $unprocessed_items ) ) {
			$items_to_repair = $unprocessed_items;
			$this->clear_unprocessed_items_cache();
		} elseif ( $page = $this->get_page() ) {
			$items_to_repair = $this->get_items_to_repair( $page );
			$this->set_page( $page + 1 );
		}

		// Store the items as array keys for more performant un-setting.
		$this->items_to_repair = array_flip( $items_to_repair );

		return $items_to_repair;
	}

	/**
	 * Runs the update and save any items which didn't get processed.
	 *
	 * @since 2.6.0
	 */
	public function run_update() {
		parent::run_update();

		// After running the update, save any items which haven't processed so we can handle them in the next request.
		$this->save_unprocessed_items();
	}

	/**
	 * Schedules the repair event for this item.
	 *
	 * @since 2.6.0
	 */
	protected function update_item( $item ) {
		// Schedule the individual repair actions to run in 1 hr to give us the best chance at scheduling all the actions before they start running and clogging up the queue.
		as_schedule_single_action( gmdate( 'U' ) + HOUR_IN_SECONDS, $this->repair_hook, array( 'repair_object' => $item ) );
		unset( $this->items_to_repair[ $item ] );
	}

	/**
	 * Gets the current page number.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	protected function get_page() {
		return absint( get_option( "{$this->repair_hook}_page", 0 ) );
	}

	/**
	 * Sets the current page number.
	 *
	 * @since 2.6.0
	 * @param int $page.
	 */
	protected function set_page( $page ) {
		update_option( "{$this->repair_hook}_page", (string) $page );
	}

	/**
	 * Gets items from the last request which weren't processed.
	 *
	 * @since 2.6.0
	 * @return array
	 */
	protected function get_unprocessed_items() {
		return get_option( "{$this->repair_hook}_unprocessed", array() );
	}

	/**
	 * Saves any items which haven't been handled.
	 *
	 * @since 2.6.0
	 */
	protected function save_unprocessed_items() {
		if ( ! empty( $this->items_to_repair ) ) {
			// The items_to_repair array will have been flipped by get_items_to_update() so flip them back before storing.
			update_option( "{$this->repair_hook}_unprocessed", array_flip( $this->items_to_repair ) );
		}
	}

	/**
	 * Deletes any items stored in the unprocessed cache stored in an option.
	 *
	 * @since 2.6.0
	 */
	protected function clear_unprocessed_items_cache() {
		delete_option( "{$this->repair_hook}_unprocessed" );
	}

	/**
	 * Unschedules the instance's hook in Action Scheduler and deletes the page counter.
	 *
	 * This function is called when there are no longer any items to update.
	 *
	 * @since 2.6.0
	 */
	protected function unschedule_background_updates() {
		parent::unschedule_background_updates();
		delete_option( "{$this->repair_hook}_page" );
	}

	/**
	 * Repairs an item.
	 */
	abstract protected function repair_item( $item );

	/**
	 * Get a batch of items which need to be repaired.
	 *
	 * @param int $page The page number to return results from.
	 * @return array The items to repair. Each item must be a string or int.
	 */
	abstract protected function get_items_to_repair( $page );
}
