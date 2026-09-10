<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Subscription Downloads Install.
 *
 * @package  WC_Subscription_Downloads_Install
 * @category Install
 * @author   WooThemes
 */
class WC_Subscription_Downloads_Install {

	/**
	 * Run the install.
	 */
	public function __construct() {
		$this->create_table();
	}

	/**
	 * Install the plugin table.
	 *
	 * @return void
	 */
	protected function create_table() {
		update_option( 'woocommerce_subscription_downloads_version', WC_Subscriptions::$version );

		// Always run the (idempotent) DDL, so a deactivate/reactivate cycle repairs
		// a missing table regardless of the version option's state.
		self::ensure_table_exists();
	}

	/**
	 * Ensure the subscription downloads mapping table exists.
	 *
	 * Runs an idempotent dbDelta unconditionally and verifies the result. The
	 * 'woocommerce_subscription_downloads_version' option is deliberately not used
	 * as a gate here: it stores the version number and cannot prove the table
	 * exists (a table can be lost to events WordPress never observes, such as a
	 * partial database restore), while dbDelta is cheap on the rare events that
	 * call this method.
	 *
	 * dbDelta's return value is a descriptive array, not a success signal, so the
	 * table's existence is probed afterwards. A missing table (for example, when
	 * the database user lacks the CREATE privilege) is logged as an error.
	 *
	 * @since 9.2.0
	 *
	 * @return bool True if the table exists after the call, false otherwise.
	 */
	public static function ensure_table_exists() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		$create = "
			CREATE TABLE {$wpdb->prefix}woocommerce_subscription_downloads (
				id bigint(20) NOT NULL auto_increment,
				product_id bigint(20) NOT NULL,
				subscription_id bigint(20) NOT NULL,
				PRIMARY KEY (id)
			) $collate;
		";

		dbDelta( $create );

		$table_name = $wpdb->prefix . 'woocommerce_subscription_downloads';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			wc_get_logger()->error(
				'The subscription downloads table is still missing after dbDelta - sharing downloadable files with subscription products will fail. Check that the database user can CREATE tables.',
				array( 'source' => 'wc-subscription-downloads' )
			);

			return false;
		}

		return true;
	}
}
