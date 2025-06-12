<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for managing caches of object data that have a many-to-one relationship.
 *
 * This applies to caches where only one should exist for the meta value. This differs to WCS_Object_Data_Cache_Manager
 * which allows multiple caches for the same meta value i.e. a many-to-many relationship.
 *
 * @version  5.2.0
 * @category Class
 */
class WCS_Object_Data_Cache_Manager_Many_To_One extends WCS_Object_Data_Cache_Manager {

	/**
	 * Triggers the update cache hook for an object change.
	 *
	 * In a one-to-many relationship, we need to pass the previous value to the hook so that
	 * any existing relationships are also deleted because we know the data should not allow
	 * relationships with multiple other values. e.g. a subscription can only belong to one customer.
	 *
	 * @param WC_Data $object The object that was changed.
	 * @param string  $key    The object's key that was changed. Can be a base property ('customer_id') or a meta key ('_subscription_renewal').
	 * @param array   $change {
	 *     Data about the change that was made to the object.
	 *
	 *     @type mixed  $new      The new value.
	 *     @type mixed  $previous The previous value before it was changed.
	 *     @type string $type     The type of change. Can be 'update', 'add' or 'delete'.
	 * }
	 */
	protected function trigger_update_cache_hook_from_change( $object, $key, $change ) {
		$previous_value = ! empty( $change['previous'] ) ? $change['previous'] : '';
		// When a meta is being deleted, the `new` key is not set.
		$new = ! empty( $change['new'] ) ? $change['new'] : '';
		$this->trigger_update_cache_hook( $change['type'], $object->get_id(), $key, $new, $previous_value );
	}
}
