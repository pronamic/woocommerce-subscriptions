<?php
/**
 * WCS_Table_Maker Class
 *
 * Provide APIs for create custom tables.
 *
 * @author   Prospress
 * @category Abstract Class
 * @package  WooCommerce Subscriptions/Abstracts
 * @since    2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

abstract class WCS_Table_Maker {
	/**
	 * @var int Increment this value to trigger a schema update
	 */
	protected $schema_version = 1;

	/**
	 * @var array Names of tables that will be registered by this class
	 */
	protected $tables = array();

	/**
	 * Register tables with WordPress, and create them if needed
	 */
	public function register_tables() {
		global $wpdb;

		// make WP aware of our tables
		foreach ( $this->tables as $table ) {
			$wpdb->tables[] = $table;
			$name           = $this->get_full_table_name( $table );
			$wpdb->$table   = $name;
		}

		// create the tables
		if ( $this->schema_update_required() ) {
			foreach ( $this->tables as $table ) {
				$this->update_table( $table );
			}
			$this->mark_schema_update_complete();
		}
	}

	/**
	 * @param string $table The name of the table
	 *
	 * @return string The CREATE TABLE statement, suitable for passing to dbDelta
	 */
	abstract protected function get_table_definition( $table );

	/**
	 * Determine if the database schema is out of date
	 * by comparing the integer found in $this->schema_version
	 * with the option set in the WordPress options table
	 *
	 * @return bool
	 */
	private function schema_update_required() {
		$version_found_in_db = $this->get_schema_option();

		return version_compare( $version_found_in_db, $this->schema_version, '<' );
	}

	/**
	 * Gets the schema version name.
	 *
	 * @return string
	 */
	private function get_schema_option_name() {
		return 'wcs-schema-' . get_class( $this );
	}

	/**
	 * Gets the schema version we have.
	 *
	 * @return mixed
	 */
	private function get_schema_option() {
		return get_option( $this->get_schema_option_name(), 0 );
	}

	/**
	 * Update the option in WordPress to indicate that
	 * our schema is now up to date
	 */
	private function mark_schema_update_complete() {
		$option_name = $this->get_schema_option_name();

		// work around race conditions and ensure that our option updates
		$value_to_save = (string) $this->schema_version . '.0.' . time();

		update_option( $option_name, $value_to_save );
	}

	/**
	 * Update the schema for the given table
	 *
	 * @param string $table The name of the table to update
	 */
	private function update_table( $table ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$definition = $this->get_table_definition( $table );
		if ( $definition ) {
			$updated = dbDelta( $definition );
			foreach ( $updated as $updated_table => $update_description ) {
				if ( strpos( $update_description, 'Created table' ) === 0 ) {
					do_action( 'wcs_created_table', $updated_table, $table );
				}
			}
		}
	}

	/**
	 * @param string $table
	 *
	 * @return string The full name of the table, including the
	 *                table prefix for the current blog
	 */
	protected function get_full_table_name( $table ) {
		return $GLOBALS['wpdb']->prefix . $table;
	}
}
