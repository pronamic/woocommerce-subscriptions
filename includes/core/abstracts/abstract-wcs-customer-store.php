<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define requirements for a customer data store and provide method for accessing active data store.
 *
 * A unified way to query customer data for subscriptions makes it possible to add a caching layer
 * to that data in the short term, and in the longer term seamlessly handle different storage for
 * customer data defined by WooCommerce core. This is important because at the time of writing,
 * the customer ID for an order is stored in a post meta field with the key '_customer_user', but
 * it is being moved to use the 'post_author' column of the posts table from WC v2.4 or v2.5. It
 * will eventually also be moved quite likely to custom tables.
 *
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 * @category Class
 * @author   Prospress
 */
abstract class WCS_Customer_Store {

	/** @var WCS_Customer_Store */
	private static $instance = null;

	/**
	 * Get the IDs for a given user's subscriptions.
	 *
	 * @param int $user_id The id of the user whose subscriptions you want.
	 * @return array
	 */
	abstract public function get_users_subscription_ids( $user_id );

	/**
	 * Get the active customer data store.
	 *
	 * @return WCS_Customer_Store
	 */
	final public static function instance() {

		if ( empty( self::$instance ) ) {
			if ( ! did_action( 'plugins_loaded' ) ) {
				wcs_doing_it_wrong( __METHOD__, 'This method was called before the "plugins_loaded" hook. It applies a filter to the customer data store instantiated. For that to work, it should first be called after all plugins are loaded.', '2.3.0' );
			}

			$class          = apply_filters( 'wcs_customer_store_class', 'WCS_Customer_Store_Cached_CPT' );
			self::$instance = new $class();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Stub for initialising the class outside the constructor, for things like attaching callbacks to hooks.
	 */
	protected function init() {}
}
