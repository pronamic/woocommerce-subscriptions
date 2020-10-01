<?php
/**
 * Retry Background Updater.
 *
 * @author      Prospress
 * @category    Class
 * @package     WooCommerce Subscriptions
 * @subpackage  WCS_Retry_Backgound_Migrator
 * @since       2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class WCS_Retry_Background_Migrator.
 *
 * Updates our retries on background.
 * @since 2.4.0
 */
class WCS_Retry_Background_Migrator extends WCS_Background_Upgrader {
	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var WCS_Retry_Store
	 */
	private $destination_store;

	/**
	 * Where the data comes from.
	 *
	 * @var WCS_Retry_Store
	 */
	private $source_store;

	/**
	 * Our migration class.
	 *
	 * @var WCS_Retry_Migrator
	 */
	private $migrator;

	/**
	 * construct.
	 *
	 * @param WC_Logger_Interface $logger The WC_Logger instance.
	 *
	 * @since 2.4.0
	 */
	public function __construct( WC_Logger_Interface $logger ) {
		$this->scheduled_hook = 'wcs_retries_migration_hook';
		$this->time_limit     = 30;

		$this->destination_store = WCS_Retry_Stores::get_database_store();
		$this->source_store      = WCS_Retry_Stores::get_post_store();

		$migrator_class = apply_filters( 'wcs_retry_retry_migrator_class', 'WCS_Retry_Migrator' );
		$this->migrator = new $migrator_class( $this->source_store, $this->destination_store, new WC_Logger() );

		$this->log_handle = 'wcs-retries-background-migrator';
		$this->logger     = $logger;
	}

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 * @since 2.4.0
	 */
	protected function get_items_to_update() {
		return $this->source_store->get_retries( array( 'limit' => 10 ) );
	}

	/**
	 * Run the update for a single item.
	 *
	 * @param WCS_Retry $retry The item to update.
	 *
	 * @return int|null
	 * @since 2.4.0
	 */
	protected function update_item( $retry ) {
		try {
			if ( ! is_a( $retry, 'WCS_Retry' ) ) {
				throw new Exception( 'The $retry parameter must be a valid WCS_Retry instance.' );
			}

			$new_item_id = $this->migrator->migrate_entry( $retry->get_id() );

			$this->log( sprintf( 'Payment retry ID: %d, has been migrated to custom table with new ID: %d.', $retry->get_id(), $new_item_id ) );

			return $new_item_id;
		} catch ( Exception $e ) {
			if ( is_object( $retry ) ) {
				$retry_description = get_class( $retry ) . '(id=' . wcs_get_objects_property( $retry, 'id' ) . ')';
			} else {
				$retry_description = wp_json_encode( $retry );
			}

			$this->log( sprintf( '--- Exception caught migrating Payment retry %s - exception message: %s ---', $retry_description, $e->getMessage() ) );

			return null;
		}
	}

	/**
	 * Unscheduled the instance's hook in Action Scheduler
	 * @since 2.4.1
	 */
	protected function unschedule_background_updates() {
		parent::unschedule_background_updates();

		$this->migrator->set_needs_migration();
	}
}
