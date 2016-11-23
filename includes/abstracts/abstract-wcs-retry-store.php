<?php
/**
 * An interface for creating a store for retry details.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WCS_Retry_Store
 * @category	Class
 * @author		Prospress
 * @since		2.1
 */

abstract class WCS_Retry_Store {

	/** @var ActionScheduler_Store */
	private static $store = null;

	/**
	 * Save the details of a retry to the database
	 *
	 * @param WCS_Retry $retry
	 * @return int the retry's ID
	 */
	abstract public function save( WCS_Retry $retry );

	/**
	 * Get the details of a retry from the database
	 *
	 * @param int $retry_id
	 * @return WCS_Retry
	 */
	abstract public function get_retry( $retry_id );

	/**
	 * Get a set of retries from the database
	 *
	 * @param array $args A set of filters:
	 *			'status': filter to only retries of a certain status, either 'pending', 'processing', 'failed' or 'complete'. Default: 'any', which will return all retries.
	 *			'date_query': array of dates to filter retries those that occur 'after' or 'before' a certain (or inbetween those two dates). Should be a MySQL formated date/time string.
	 * @return array An array of WCS_Retry objects
	 */
	abstract public function get_retries( $args );

	/**
	 * Get the IDs of all retries from the database for a given order
	 *
	 * @param int $order_id
	 * @return array
	 */
	abstract protected function get_retry_ids_for_order( $order_id );

	/**
	 * Setup the class, if required
	 *
	 * @return null
	 */
	abstract public function init();

	/**
	 * Get the details of all retries (if any) for a given order
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function get_retries_for_order( $order_id ) {

		$retries = array();

		foreach ( $this->get_retry_ids_for_order( $order_id ) as $retry_id ) {
			$retries[ $retry_id ] = $this->get_retry( $retry_id );
		}

		return $retries;
	}

	/**
	 * Get the details of the last retry (if any) recorded for a given order
	 *
	 * @param int $order_id
	 * @return WCS_Retry | null
	 */
	public function get_last_retry_for_order( $order_id ) {

		$retry_ids = $this->get_retry_ids_for_order( $order_id );

		if ( ! empty( $retry_ids ) ) {
			$last_retry_id = array_pop( $retry_ids );
			$last_retry    = $this->get_retry( $last_retry_id );
		} else {
			$last_retry = null;
		}

		return $last_retry;
	}

	/**
	 * Get the number of retries stored in the database for a given order
	 *
	 * @param int $order_id
	 * @return int
	 */
	public function get_retry_count_for_order( $order_id ) {

		$retry_post_ids = $this->get_retry_ids_for_order( $order_id );

		return count( $retry_post_ids );
	}
}
