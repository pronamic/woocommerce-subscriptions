<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Class for managing caches of post meta.
 *
 * This class is intended to be used on stores using WP post architecture.
 * Post related APIs and references in this class are expected, and shouldn't be replaced with CRUD equivalents.
 *
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 * @category Class
 */
class WCS_Post_Meta_Cache_Manager {

	/** @var string The post type this cache manage acts on. */
	protected $post_type;

	/** @var array The post meta keys this cache manager should act on. */
	protected $meta_keys;

	/**
	 * Constructor
	 *
	 * @param string The post type this cache manage acts on.
	 * @param array The post meta keys this cache manager should act on.
	 */
	public function __construct( $post_type, $meta_keys ) {
		$this->post_type = $post_type;

		// We store the meta keys as the array keys to take advantage of the better query performance of isset() vs. in_array()
		$this->meta_keys = array_flip( $meta_keys );
	}

	/**
	 * Attach callbacks to keep related order caches up-to-date.
	 */
	public function init() {

		// When the post for a related order is deleted or untrashed, make sure the corresponding related order cache is updated
		add_action( 'before_delete_post', array( $this, 'post_deleted' ) );
		add_action( 'trashed_post', array( $this, 'post_deleted' ) );
		add_action( 'untrashed_post', array( $this, 'post_untrashed' ) );

		// When a related order post meta flag is modified, make sure the corresponding related order cache is updated
		add_action( 'added_post_meta', array( $this, 'meta_added' ), 10, 4 );
		add_action( 'update_post_meta', array( $this, 'meta_updated' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'meta_deleted' ), 10, 4 );

		// Special handling for meta updates containing a previous order ID to make sure we also delete any previously linked relationship
		add_action( 'update_post_metadata', array( $this, 'meta_updated_with_previous' ), 10, 5 );

		// Special handling for meta deletion on all posts/orders, not a specific post/order ID
		add_action( 'delete_post_metadata', array( $this, 'meta_deleted_all' ), 100, 5 );
	}

	/**
	 * Check if the post meta change is one to act on or ignore, based on the post type and meta key being changed.
	 *
	 * One gotcha here: the 'delete_post_metadata' hook can be triggered with a $post_id of null. This is done when
	 * meta is deleted by key (i.e. delete_post_meta_by_key()) or when meta is being deleted for a specific value for
	 * all posts (as done by wp_delete_attachment() to remove the attachment from posts). To handle these cases,
	 * we only check the post type when the $post_id is non-null.
	 *
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @return bool False if the change should not be ignored, true otherwise.
	 */
	protected function is_change_to_ignore( $post_id, $meta_key = '' ) {
		if ( ! is_null( $post_id ) && false === $this->is_managed_post_type( $post_id ) ) {
			return true;
		} elseif ( empty( $meta_key ) || ! isset( $this->meta_keys[ $meta_key ] ) ) {
			return true;
		} else {
			return false;
		}
	}

	/* Callbacks for post meta hooks */

	/**
	 * When post meta is added, check if this class instance cares about updating its cache
	 * to reflect the change.
	 *
	 * @param int $meta_id The ID of the post meta row in the database.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The value being set in the database.
	 */
	public function meta_added( $meta_id, $post_id, $meta_key, $meta_value ) {
		$this->maybe_trigger_update_cache_hook( 'add', $post_id, $meta_key, $meta_value );
	}

	/**
	 * When post meta is deleted, check if this class instance cares about updating its cache
	 * to reflect the change.
	 *
	 * @param int $meta_id The ID of the post meta row in the database.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The value being delete from the database.
	 */
	public function meta_deleted( $meta_id, $post_id, $meta_key, $meta_value ) {
		$this->maybe_trigger_update_cache_hook( 'delete', $post_id, $meta_key, $meta_value );
	}

	/**
	 * When post meta is updated from a previous value, check if this class instance cares about
	 * updating its cache to reflect the change.
	 *
	 * @param mixed $check Whether to update the meta or not. By default, this is null, meaning it will be updated. Callbacks may override it to prevent that.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The new value being saved in the database.
	 * @param mixed $prev_value The previous value stored in the database.
	 * @return mixed $check This method is attached to the "update_{$meta_type}_metadata" filter, which is used as a pre-check on whether to update meta data, so it needs to return the $check value passed in.
	 */
	public function meta_updated_with_previous( $check, $post_id, $meta_key, $meta_value, $prev_value ) {

		// If the meta data isn't actually being changed, we don't need to do anything. The use of == instead of === is deliberate to account for typecasting that can happen in WC's CRUD classes (e.g. ints cast as strings or bools as ints)
		if ( $check || $prev_value == $meta_value ) {
			return $check;
		}

		$this->maybe_trigger_update_cache_hook( 'update', $post_id, $meta_key, $meta_value, $prev_value );

		return $check;
	}

	/**
	 * When post meta is updated, check if this class instance cares about updating its cache
	 * to reflect the change.
	 *
	 * @param int $meta_id The ID of the post meta row in the database.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The value being deleted from the database.
	 */
	public function meta_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		$this->meta_updated_with_previous( null, $post_id, $meta_key, $meta_value, '' );
	}

	/**
	 * When all post meta rows for a given key are about to be deleted, check if this class instance
	 * cares about updating its cache to reflect the change.
	 *
	 * WordPress has special handling for meta deletion on all posts rather than a specific post ID.
	 * This method handles that case.
	 *
	 * @param mixed $check Whether to delete the meta or not. By default, this is null, meaning it will be deleted. Callbacks may override it to prevent that.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The value being deleted from the database.
	 * @param bool $delete_all Whether meta data is being deleted on all posts, not a specific post.
	 * @return mixed $check This method is attached to the "update_{$meta_type}_metadata" filter, which is used as a pre-check on whether to update meta data, so it needs to return the $check value passed in.
	 */
	public function meta_deleted_all( $check, $post_id, $meta_key, $meta_value, $delete_all ) {

		if ( $delete_all && null === $check && false === $this->is_change_to_ignore( $post_id, $meta_key ) ) {
			$this->trigger_delete_all_caches_hook( $meta_key );
		}

		return $check;
	}

	/* Callbacks for post hooks */

	/**
	 * When a post object is restored from the trash, check if this class instance cares about updating its cache
	 * to reflect the change.
	 *
	 * @param int $post_id The post being restored.
	 */
	public function post_untrashed( $post_id ) {
		$this->maybe_update_for_post_change( 'add', $post_id );
	}

	/**
	 * When a post object is deleted or trashed, check if this class instance cares about updating its cache
	 * to reflect the change.
	 *
	 * @param int $post_id The post being restored.
	 */
	public function post_deleted( $post_id ) {
		$this->maybe_update_for_post_change( 'delete', $post_id );
	}

	/**
	 * When a post object is changed, check if this class instance cares about updating its cache
	 * to reflect the change.
	 *
	 * @param string $update_type The type of update to check. Only 'add' or 'delete' should be used.
	 * @param int $post_id The post being changed.
	 * @throws InvalidArgumentException If the given update type is not 'add' or 'delete'.
	 */
	protected function maybe_update_for_post_change( $update_type, $post_id ) {

		if ( ! in_array( $update_type, array( 'add', 'delete' ) ) ) {
			// translators: %s: invalid type of update argument.
			throw new InvalidArgumentException( sprintf( __( 'Invalid update type: %s. Post update types supported are "add" or "delete". Updates are done on post meta directly.', 'woocommerce-subscriptions' ), $update_type ) );
		}

		$object = ( 'shop_order' === $this->post_type ) ? wc_get_order( $post_id ) : get_post( $post_id );

		foreach ( $this->meta_keys as $meta_key => $value ) {
			$property   = preg_replace( '/^_/', '', $meta_key );
			$meta_value = ( 'add' === $update_type ) ? wcs_get_objects_property( $object, $property ) : '';

			$this->maybe_trigger_update_cache_hook( $update_type, $post_id, $meta_key, $meta_value );
		}
	}

	/**
	 * When post data is changed, check if this class instance cares about updating its cache
	 * to reflect the change.
	 *
	 * @param string $update_type The type of update to check. Only 'add' or 'delete' should be used.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The meta value.
	 * @param mixed $prev_value The previous value stored in the database. Optional.
	 */
	protected function maybe_trigger_update_cache_hook( $update_type, $post_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( false === $this->is_change_to_ignore( $post_id, $meta_key ) ) {
			$this->trigger_update_cache_hook( $update_type, $post_id, $meta_key, $meta_value, $prev_value );
		}
	}

	/**
	 * Trigger a hook to allow 3rd party code to update its cache for data that it cares about.
	 *
	 * @param string $update_type The type of update to check. Only 'add' or 'delete' should be used.
	 * @param int $post_id The post the meta is being changed on.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $meta_value The meta value.
	 * @param mixed $prev_value The previous value stored in the database. Optional.
	 */
	protected function trigger_update_cache_hook( $update_type, $post_id, $meta_key, $meta_value, $prev_value = '' ) {
		do_action( 'wcs_update_post_meta_caches', $update_type, $post_id, $meta_key, $meta_value, $prev_value );
	}

	/**
	 * Trigger a hook to allow 3rd party code to delete its cache for data that it cares about.
	 *
	 * @param string $meta_key The post meta key being changed.
	 */
	protected function trigger_delete_all_caches_hook( $meta_key ) {
		do_action( 'wcs_delete_all_post_meta_caches', $meta_key );
	}

	/**
	 * Abstract the check against get_post_type() so that it can be mocked for unit tests.
	 *
	 * @param int $post_id Post ID or post object.
	 * @return bool Whether the post type for the given post ID is the post type this instance manages.
	 */
	protected function is_managed_post_type( $post_id ) {
		return $this->post_type === get_post_type( $post_id );
	}
}
