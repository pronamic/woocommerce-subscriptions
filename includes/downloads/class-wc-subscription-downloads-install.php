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
		global $wpdb;

		$version = get_option( 'woocommerce_subscription_downloads_version' );

		if ( ! $version ) {
			add_option( 'woocommerce_subscription_downloads_version', WC_Subscriptions::$version );

			$collate = '';

			if ( $wpdb->has_cap( 'collation' ) ) {
				if ( ! empty( $wpdb->charset ) ) {
					$collate .= "DEFAULT CHARACTER SET $wpdb->charset";
				}
				if ( ! empty( $wpdb->collate ) ) {
					$collate .= " COLLATE $wpdb->collate";
				}
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$create = "
				CREATE TABLE {$wpdb->prefix}woocommerce_subscription_downloads (
					id bigint(20) NOT NULL auto_increment,
					product_id bigint(20) NOT NULL,
					subscription_id bigint(20) NOT NULL,
					PRIMARY KEY (id)
				) $collate;
			";

			dbDelta( $create );
		} else {
			update_option( 'woocommerce_subscription_downloads_version', WC_Subscriptions::$version );
		}
	}
}
