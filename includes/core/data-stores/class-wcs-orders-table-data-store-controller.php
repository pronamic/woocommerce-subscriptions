<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions Order Tables Data Store Controller class
 *
 * The purpose of this class is to:
 *  - control when our subscriptions datastore class is loaded and used
 *  - handle any other code that relates to the HPOS/COT feature and our datastore
 */
class WCS_Orders_Table_Data_Store_Controller {

	/**
	 * The data store object to use.
	 *
	 * @var WCS_Orders_Table_Subscription_Data_Store
	 */
	private $data_store;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialise WCS_Orders_Table_Data_Store_Controller class hooks.
	 *
	 * @return void
	 */
	public function init_hooks() {
		add_filter( 'woocommerce_subscription_data_store', array( $this, 'get_orders_table_data_store' ), 10 );
	}

	/**
	 * Returns an instance of the Subscriptions Order Table data store object to use.
	 * If an instance doesn't exist, create one.
	 *
	 * @return WCS_Orders_Table_Subscription_Data_Store
	 */
	private function get_data_store_instance() {
		if ( ! isset( $this->data_store ) ) {
			$this->data_store = new WCS_Orders_Table_Subscription_Data_Store();
			$this->data_store->init(
				wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class ),
				wc_get_container()->get( \Automattic\WooCommerce\Internal\Utilities\DatabaseUtil::class ),
				wc_get_container()->get( \Automattic\WooCommerce\Proxies\LegacyProxy::class )
			);
		}

		return $this->data_store;
	}

	/**
	 * When the custom_order_tables feature is enabled, return the subscription datastore class.
	 *
	 * @param string $default_data_store The data store class name.
	 *
	 * @return string
	 */
	public function get_orders_table_data_store( $default_data_store ) {
		return wcs_is_custom_order_tables_usage_enabled() ? $this->get_data_store_instance() : $default_data_store;
	}
}
