<?php
/**
 * Entry migration abstract class.
 *
 * @author       Prospress
 * @category     Class
 * @package      WooCommerce Subscriptions
 * @since        1.0.0 - Migrated from WooCommerce Subscriptions v2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

abstract class WCS_Migrator {
	/**
	 * @var mixed
	 */
	protected $source_store;

	/**
	 * @var mixed
	 */
	protected $destination_store;

	/**
	 * @var  WC_Logger_Interface
	 */
	protected $logger;

	/**
	 * @var string
	 */
	protected $log_handle;

	/**
	 * WCS_Migrator constructor.
	 *
	 * @param mixed     $source_store      Source store.
	 * @param mixed     $destination_store $destination store.
	 * @param WC_Logger $logger            Logger component.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4
	 */
	public function __construct( $source_store, $destination_store, $logger ) {
		$this->source_store      = $source_store;
		$this->destination_store = $destination_store;
		$this->logger            = $logger;
	}

	/**
	 * Should this entry be migrated.
	 *
	 * @param int $entry_id
	 *
	 * @return bool
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4
	 */
	abstract public function should_migrate_entry( $entry_id );

	/**
	 * Gets the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return mixed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4
	 */
	abstract public function get_source_store_entry( $entry_id );

	/**
	 * save the item to the destination store.
	 *
	 * @param int $entry_id
	 *
	 * @return mixed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4
	 */
	abstract public function save_destination_store_entry( $entry_id );

	/**
	 * deletes the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return mixed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4
	 */
	abstract public function delete_source_store_entry( $entry_id );

	/**
	 * Runs after a entry has been migrated.
	 *
	 * @param int $old_entry_id
	 * @param mixed $new_entry
	 *
	 * @return mixed
	 */
	abstract protected function migrated_entry( $old_entry_id, $new_entry );

	/**
	 * Migrates our entry.
	 *
	 * @param int $entry_id
	 *
	 * @return mixed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4
	 */
	public function migrate_entry( $entry_id ) {
		$source_store_item = $this->get_source_store_entry( $entry_id );
		if ( $source_store_item ) {
			$destination_store_item = $this->save_destination_store_entry( $entry_id );
			$this->delete_source_store_entry( $entry_id );

			$this->migrated_entry( $entry_id, $destination_store_item );

			return $destination_store_item;
		}

		return false;
	}

	/**
	 * Add a message to the log
	 *
	 * @param string $message The message to be logged
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 */
	protected function log( $message ) {
		$this->logger->add( $this->log_handle, $message );
	}
}
