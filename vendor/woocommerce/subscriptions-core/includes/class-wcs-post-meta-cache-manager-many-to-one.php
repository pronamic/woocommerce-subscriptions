<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for managing caches of post meta data that have a many-to-one relationship, meaning
 * only one cache should exist for the meta value. This differs to WCS_Post_Meta_Cache_Manager
 * which allows multiple caches for the same meta value i.e. a many-to-many relationship.
 *
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 * @category Class
 * @author   Prospress
 */
class WCS_Post_Meta_Cache_Manager_Many_To_One extends WCS_Post_Meta_Cache_Manager {

	/**
	 * When post meta is updated, check if this class instance cares about updating its cache
	 * to reflect the change. Always pass the previous value, to make sure that any existing
	 * relationships are also deleted because we know the data should not allow relationships
	 * with multiple other values. e.g. a subscription can only belong to one customer.
	 *
	 * @param int $meta_id The ID of the post meta row in the database.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The value being deleted from the database.
	 */
	public function meta_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		$this->meta_updated_with_previous( null, $post_id, $meta_key, $meta_value, get_post_meta( $post_id, $meta_key, true ) );
	}
}
