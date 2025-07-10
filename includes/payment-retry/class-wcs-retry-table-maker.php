<?php
/**
 * Class that handles our retries custom tables creation.
 *
 * @package        WooCommerce Subscriptions
 * @subpackage     WCS_Retry_Table_Maker
 * @category       Class
 * @author         Prospress
 * @since          2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WCS_Retry_Table_Maker extends WCS_Table_Maker {
	/**
	 * @inheritDoc
	 */
	protected $schema_version = 1;

	/**
	 * WCS_Retry_Table_Maker constructor.
	 */
	public function __construct() {
		$this->tables = array(
			WCS_Retry_Stores::get_database_store()->get_table_name(),
		);
	}

	/**
	 * @param string $table
	 *
	 * @return string
	 * @since 2.4
	 */
	protected function get_table_definition( $table ) {
		global $wpdb;
		// phpcs:disable QITStandard.DB.DynamicWpdbMethodCall.DynamicMethod
		$table_name      = $wpdb->$table;
		$charset_collate = $wpdb->get_charset_collate();

		switch ( $table ) {
			case WCS_Retry_Stores::get_database_store()->get_table_name():
				return "
				CREATE TABLE {$table_name} (
					retry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					order_id BIGINT UNSIGNED NOT NULL,
					status varchar(255) NOT NULL,
					date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
					rule_raw text,
					PRIMARY KEY  (retry_id),
					KEY order_id (order_id)
				) $charset_collate;
						";
			default:
				return '';
		}
	}
}
