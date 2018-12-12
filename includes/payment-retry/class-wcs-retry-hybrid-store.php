<?php
/**
 * Hybrid wrapper around post and database store.
 *
 * @package        WooCommerce Subscriptions
 * @subpackage     WCS_Retry_Store
 * @category       Class
 * @author         Prospress
 * @since          2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WCS_Retry_Hybrid_Store extends WCS_Retry_Store {
	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var WCS_Retry_Store
	 */
	private $database_store;

	/**
	 * Where the data comes from.
	 *
	 * @var WCS_Retry_Store
	 */
	private $post_store;

	/**
	 * Our migration class.
	 *
	 * @var WCS_Migrator
	 */
	private $migrator;

	/**
	 * Setup the class, if required
	 *
	 * @since 2.4
	 */
	public function init() {
		$this->database_store = WCS_Retry_Stores::get_database_store();
		$this->post_store     = WCS_Retry_Stores::get_post_store();

		$migrator_class = apply_filters( 'wcs_retry_retry_migrator_class', 'WCS_Retry_Migrator' );
		$this->migrator = new $migrator_class( $this->post_store, $this->database_store, new WC_Logger() );
	}

	/**
	 * Save the details of a retry to the database
	 *
	 * @param WCS_Retry $retry Retry to save.
	 *
	 * @return int the retry's ID
	 * @since 2.4
	 */
	public function save( WCS_Retry $retry ) {
		$retry_id = $retry->get_id();
		if ( $this->migrator->should_migrate_entry( $retry_id ) ) {
			$retry_id = $this->migrator->migrate_entry( $retry_id );
		}

		return $this->database_store->save( new WCS_Retry( array(
			'id'       => $retry_id,
			'order_id' => $retry->get_order_id(),
			'status'   => $retry->get_status(),
			'date_gmt' => $retry->get_date_gmt(),
			'rule_raw' => $retry->get_rule()->get_raw_data(),
		) ) );
	}

	/**
	 * Get the details of a retry from the database, and migrates when necessary.
	 *
	 * @param int $retry_id Retry we want to get.
	 *
	 * @return WCS_Retry
	 * @since 2.4
	 */
	public function get_retry( $retry_id ) {
		if ( $this->migrator->should_migrate_entry( $retry_id ) ) {
			$retry_id = $this->migrator->migrate_entry( $retry_id );
		}

		return $this->database_store->get_retry( $retry_id );
	}

	/**
	 * Deletes a retry.
	 *
	 * @param int $retry_id
	 *
	 * @return bool
	 * @since 2.4
	 */
	public function delete_retry( $retry_id ) {
		if ( $this->migrator->should_migrate_entry( $retry_id ) ) {
			return $this->migrator->delete_source_store_entry( $retry_id );
		}

		return $this->database_store->delete_retry( $retry_id );
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
	public function get_retries( $args = array(), $return = 'objects' ) {
		$source_store_retries = $this->post_store->get_retries( $args, $return );

		foreach ( $source_store_retries as $source_store_retry_id => $source_store_retry ) {
			if ( $this->migrator->should_migrate_entry( $source_store_retry_id ) ) {
				$this->migrator->migrate_entry( $source_store_retry_id );
			}
		}

		return $this->database_store->get_retries( $args, $return );
	}

	/**
	 * Get the IDs of all retries from the database for a given order
	 *
	 * @param int $order_id order we want to look for.
	 *
	 * @return array
	 * @since 2.4
	 */
	public function get_retry_ids_for_order( $order_id ) {
		$source_store_retries = $this->post_store->get_retry_ids_for_order( $order_id );

		foreach ( $source_store_retries as $source_store_retry_id ) {
			if ( $this->migrator->should_migrate_entry( $source_store_retry_id ) ) {
				$this->migrator->migrate_entry( $source_store_retry_id );
			}
		}

		return $this->database_store->get_retry_ids_for_order( $order_id );
	}

}
