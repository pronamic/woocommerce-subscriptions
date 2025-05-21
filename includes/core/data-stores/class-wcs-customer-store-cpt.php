<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer data store for subscriptions.
 *
 * This class is responsible for getting subscriptions for users.
 *
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 */
class WCS_Customer_Store_CPT extends WCS_Customer_Store {

	/**
	 * The post meta key used to link a customer with a subscription.
	 *
	 * @var string
	 */
	private $meta_key = '_customer_user';

	/**
	 * The object data key (property) used to link a customer with a subscription.
	 *
	 * @var string
	 */
	private $data_key = 'customer_id';

	/**
	 * Gets the post meta key used to link a customer with a subscription.
	 *
	 * @return string The customer user post meta key.
	 */
	protected function get_meta_key() {
		return $this->meta_key;
	}

	/**
	 * Gets the data key used to link the customer with a subscription.
	 *
	 * This can be the post meta key on stores using the WP Post architecture and the property name on HPOS architecture.
	 *
	 * @return string The customer user post meta key or the customer ID property key.
	 */
	protected function get_data_key() {
		return wcs_is_custom_order_tables_usage_enabled() ? $this->data_key : $this->meta_key;
	}

	/**
	 * Get the IDs for a given user's subscriptions.
	 *
	 * @param int $user_id The id of the user whose subscriptions you want.
	 * @return array
	 */
	public function get_users_subscription_ids( $user_id ) {

		if ( 0 === $user_id ) {
			return array();
		}

		return wcs_get_orders_with_meta_query(
			[
				'type'        => 'shop_subscription',
				'customer_id' => $user_id,
				'limit'       => -1,
				'status'      => 'any',
				'return'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'DESC',
			]
		);
	}
}
