<?php
/**
 * Retry migration class.
 *
 * @author      Prospress
 * @category    Class
 * @package     WooCommerce Subscriptions
 * @subpackage  WCS_Retry_Migrator
 * @since       2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Retry_Migrator extends WCS_Migrator {
	/**
	 * @var WCS_Retry_Store
	 */
	protected $source_store;

	/**
	 * @var WCS_Retry_Store
	 */
	protected $destination_store;

	/**
	 * @var string
	 */
	protected $log_handle = 'wcs-retry-migrator';

	/**
	 * @var string
	 */
	static protected $needs_migration_option_name = 'wcs_payment_retry_needs_migration';

	/**
	 * Should this retry be migrated.
	 *
	 * @param int $retry_id
	 *
	 * @return bool
	 * @since 2.4
	 */
	public function should_migrate_entry( $retry_id ) {
		return ! $this->destination_store->get_retry( $retry_id );
	}

	/**
	 * Gets the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return WCS_Retry
	 * @since 2.4
	 */
	public function get_source_store_entry( $entry_id ) {
		return $this->source_store->get_retry( $entry_id );
	}

	/**
	 * save the item to the destination store.
	 *
	 * @param int $entry_id
	 *
	 * @return mixed
	 * @since 2.4
	 */
	public function save_destination_store_entry( $entry_id ) {
		$source_retry = $this->get_source_store_entry( $entry_id );

		return $this->destination_store->save( $source_retry );
	}

	/**
	 * deletes the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return bool
	 * @since 2.4
	 */
	public function delete_source_store_entry( $entry_id ) {
		return $this->source_store->delete_retry( $entry_id );
	}

	/**
	 * Add a message to the log
	 *
	 * @param int $old_retry_id Old retry id.
	 * @param int $new_retry_id New retry id.
	 */
	protected function migrated_entry( $old_retry_id, $new_retry_id ) {
		$this->log( sprintf( 'Retry ID %d migrated to %s with ID %d.', $old_retry_id, WCS_Retry_Stores::get_database_store()->get_full_table_name(), $new_retry_id ) );
	}

	/**
	 * If options exists, we need to run migration.
	 *
	 * @since 2.4.1
	 * @return bool
	 */
	public static function needs_migration() {
		return apply_filters( self::$needs_migration_option_name, ( 'true' === get_option( self::$needs_migration_option_name ) ) );
	}

	/**
	 * Sets needs migration option.
	 *
	 * @since 2.4.1
	 */
	public static function set_needs_migration() {
		if ( WCS_Retry_Stores::get_post_store()->get_retries( array( 'limit' => 1 ), 'ids' ) ) {
			update_option( self::$needs_migration_option_name, 'true' );
		} else {
			delete_option( self::$needs_migration_option_name );
		}
	}
}

