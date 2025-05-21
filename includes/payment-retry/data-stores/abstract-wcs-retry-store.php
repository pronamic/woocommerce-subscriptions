<?php
/**
 * An interface for creating a store for retry details.
 *
 * @package        WooCommerce Subscriptions
 * @subpackage     WCS_Retry_Store
 * @category       Class
 * @author         Prospress
 * @since          2.1
 */

abstract class WCS_Retry_Store {

	private static $store = null;

	/**
	 * Save the details of a retry to the database
	 *
	 * @param WCS_Retry $retry
	 *
	 * @return int the retry's ID
	 */
	abstract public function save( WCS_Retry $retry );

	/**
	 * Get the details of a retry from the database
	 *
	 * @param int $retry_id
	 *
	 * @return WCS_Retry
	 */
	abstract public function get_retry( $retry_id );

	/**
	 * Deletes a retry.
	 *
	 * @param int $retry_id
	 *
	 * @since 2.4
	 */
	public function delete_retry( $retry_id ) {
		wcs_doing_it_wrong( __FUNCTION__, sprintf( "Method '%s' must be overridden.", __METHOD__ ), '2.4' );
	}

	/**
	 * Get a set of retries from the database
	 *
	 * @param array  $args   A set of filters:
	 *                       'status': filter to only retries of a certain status, either 'pending', 'processing', 'failed' or 'complete'. Default: 'any', which will return all retries.
	 *                       'date_query': array of dates to filter retries to those that occur 'after' or 'before' a certain date (or between those two dates). Should be a MySQL formated date/time string.
	 *                       'orderby': Order by which property?
	 *                       'order': Order in ASC/DESC.
	 *                       'order_id': filter retries to those which belong to a certain order ID.
	 *                       'limit': How many retries we want to get.
	 * @param string $return Defines in which format return the entries. options:
	 *                       'objects': Returns an array of WCS_Retry objects
	 *                       'ids': Returns an array of ids.
	 *
	 * @return array An array of WCS_Retry objects or ids.
	 * @since 2.4
	 */
	abstract public function get_retries( $args = array(), $return = 'objects' );

	/**
	 * Get the IDs of all retries from the database for a given order
	 *
	 * @param int $order_id
	 *
	 * @return array
	 * @since 2.4
	 */
	public function get_retry_ids_for_order( $order_id ) {
		return array_values( $this->get_retries( array(
			'order_id' => $order_id,
			'orderby'  => 'ID',
			'order'    => 'ASC',
		), 'ids' ) );
	}

	/**
	 * Setup the class, if required
	 */
	abstract public function init();

	/**
	 * Get the details of all retries (if any) for a given order
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function get_retries_for_order( $order_id ) {
		return $this->get_retries( array( 'order_id' => $order_id ) );
	}

	/**
	 * Get the details of the last retry (if any) recorded for a given order
	 *
	 * @param int $order_id
	 *
	 * @return WCS_Retry | null
	 */
	public function get_last_retry_for_order( $order_id ) {

		$retry_ids  = $this->get_retry_ids_for_order( $order_id );
		$last_retry = null;

		if ( ! empty( $retry_ids ) ) {
			$last_retry_id = array_pop( $retry_ids );
			$last_retry    = $this->get_retry( $last_retry_id );
		}

		return $last_retry;
	}

	/**
	 * Get the number of retries stored in the database for a given order
	 *
	 * @param int $order_id
	 *
	 * @return int
	 */
	public function get_retry_count_for_order( $order_id ) {

		$retry_post_ids = $this->get_retry_ids_for_order( $order_id );

		return count( $retry_post_ids );
	}
}
