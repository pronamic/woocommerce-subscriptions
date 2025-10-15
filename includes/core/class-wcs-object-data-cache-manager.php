<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for managing caches of object data.
 *
 * This class will track changes to an object (specified by the object type value) and trigger an action hook for each change to any specific meta key or object (specified by the $data_keys variable).
 * Interested parties (like our cache store classes), can then listen for these hooks and update their caches accordingly.
 *
 * @version  5.2.0
 * @category Class
 */
class WCS_Object_Data_Cache_Manager extends WCS_Post_Meta_Cache_Manager {

	/**
	 * The WC_Data object type this cache manager will track changes to. eg 'order', 'subscription'.
	 *
	 * @var string
	 */
	protected $object_type;

	/**
	 * The object's data keys this cache manager will keep track of changes to. Can be an object property key ('customer_id') or meta key ('_subscription_renewal').
	 *
	 * @var array
	 */
	protected $data_keys;

	/**
	 * An internal record of changes to the object that this manager is tracking.
	 *
	 * This internal record is generated before the object is saved, so we can determine
	 * if the value has changed, what the previous value was, and what the new value is.
	 *
	 * In the event that the object is being created (doesn't have an ID prior to save), this
	 * record will be generated after the object is saved, and all the data this manager
	 * is tracking will be pulled from the created object.
	 *
	 * @var array Each element is keyed by the object's ID, and contains an array of tracked changes {
	 *     Data about the change that was made to the object.
	 *
	 *     @type mixed  $new      The new value.
	 *     @type mixed  $previous The previous value before it was changed.
	 *     @type string $type     The type of change. Can be 'update', 'add' or 'delete'.
	 * }
	 */
	protected $object_changes = [];

	/**
	 * Constructor.
	 *
	 * @param string $object_type The post type this cache manage acts on.
	 * @param array $data_keys The post meta keys this cache manager should act on.
	 */
	public function __construct( $object_type, $data_keys ) {
		$this->object_type = $object_type;
		$this->data_keys   = $data_keys;
	}

	/**
	 * Attaches callbacks to keep the caches up-to-date.
	 */
	public function init() {
		add_action( "woocommerce_before_{$this->object_type}_object_save", [ $this, 'prepare_object_changes' ] );
		add_action( "woocommerce_after_{$this->object_type}_object_save", [ $this, 'action_object_cache_changes' ] );

		add_action( "woocommerce_before_delete_{$this->object_type}", [ $this, 'prepare_object_to_be_deleted' ], 10, 2 );
		add_action( "woocommerce_delete_{$this->object_type}", [ $this, 'deleted' ] );

		add_action( "woocommerce_before_trash_{$this->object_type}", [ $this, 'prepare_object_to_be_deleted' ], 10, 2 );
		add_action( "woocommerce_trash_{$this->object_type}", [ $this, 'trashed' ] );

		add_action( "woocommerce_untrash_{$this->object_type}", [ $this, 'untrashed' ] );
	}

	/**
	 * Generates a set of changes for tracked meta keys and properties.
	 *
	 * This method is hooked onto an action which is fired before the object is saved.
	 * Relevant changes to the object's data is stored in the $this->object_changes property
	 * to be processed after the object is saved. See $this->action_object_cache_changes().
	 *
	 * @param WC_Subscription $subscription        The object which is being saved.
	 * @param string          $generate_type Optional. The data to generate the changes from. Defaults to 'changes_only' which will generate the data from changes to the object. 'all_fields' will fetch data from the object for all tracked data keys.
	 */
	public function prepare_object_changes( $subscription, $generate_type = 'changes_only' ) {
		// If the object hasn't been created yet, we can't do anything yet. We'll have to wait until after the object is saved.
		if ( ! $subscription->get_id() ) {
			return;
		}

		$force_all_fields = 'all_fields' === $generate_type;
		$changes          = $subscription->get_changes();
		$base_data        = $subscription->get_base_data();
		$meta_data        = $subscription->get_meta_data();

		// Deleted meta won't be included in the changes, so we need to fetch the previous value via the raw meta data.
		$data_store       = $subscription->get_data_store();
		$raw_meta_data    = $data_store->read_meta( $subscription );
		$raw_meta_key_map = wp_list_pluck( $raw_meta_data, 'meta_key' );

		// Record the object ID so we know that it has been handled in $this->action_object_cache_changes().
		$this->object_changes[ $subscription->get_id() ] = [];

		foreach ( $this->data_keys as $data_key ) {

			// Check if the data key is a base property and if it has changed.
			if ( isset( $changes[ $data_key ] ) ) {
				$this->object_changes[ $subscription->get_id() ][ $data_key ] = [
					'new'      => $changes[ $data_key ],
					'previous' => isset( $base_data[ $data_key ] ) ? $base_data[ $data_key ] : null,
					'type'     => 'update',
				];

				continue;
			} elseif ( isset( $base_data[ $data_key ] ) && $force_all_fields ) {
				// If we're forcing all fields, fetch the base data as the new value.
				$this->object_changes[ $subscription->get_id() ][ $data_key ] = [
					'new'  => $base_data[ $data_key ],
					'type' => 'add',
				];

				continue;
			}

			// Check if the data key is stored as meta.
			foreach ( $meta_data as $meta ) {
				if ( $meta->key !== $data_key ) {
					continue;
				}

				$previous_meta = $meta->get_data();

				if ( empty( $meta->id ) ) {
					// If the value is being added.
					$this->object_changes[ $subscription->get_id() ][ $data_key ] = [
						'new'  => $meta->value,
						'type' => 'add',
					];
				} elseif ( $meta->get_changes() ) {
					// If the value is being updated.
					$this->object_changes[ $subscription->get_id() ][ $data_key ] = [
						'new'      => $meta->value,
						'previous' => isset( $previous_meta['value'] ) ? $previous_meta['value'] : null,
						'type'     => 'update',
					];
				} elseif ( $force_all_fields ) {
					// If we're forcing all fields to be recorded.
					$this->object_changes[ $subscription->get_id() ][ $data_key ] = [
						'new'  => $meta->value,
						'type' => 'add',
					];
				}

				// We've found the meta data for this data key, so we can move on to the next data key.
				break 2;
			}

			// If we got this far, then the data key is stored as meta and has been deleted.
			// When meta is deleted it won't be returned by $subscription->get_meta_data(). So we need to check the raw meta data.
			if ( in_array( $data_key, $raw_meta_key_map, true ) ) {
				$previous_meta = $raw_meta_data[ array_search( $data_key, $raw_meta_key_map, true ) ]->meta_value;
				$this->object_changes[ $subscription->get_id() ][ $data_key ] = [
					'previous' => $previous_meta,
					'type'     => 'delete',
				];
			}
		}
	}

	/**
	 * Actions all the tracked data changes that were made to the object by triggering the update cache hook.
	 *
	 * This method is hooked onto an action which is fired after the object is saved.
	 *
	 * @param WC_Data $object The object which was saved.
	 */
	public function action_object_cache_changes( $object ) {
		if ( ! $object->get_id() ) {
			return;
		}

		/**
		 * If the object ID hasn't been recorded, this object must have just been created.
		 * Without an ID $this->prepare_object_changes() (ran pre-save) would have skipped it.
		 *
		 * Now that we have an ID, generate the data now and fetch all fields.
		 */
		if ( ! isset( $this->object_changes[ $object->get_id() ] ) ) {
			$this->prepare_object_changes( $object, 'all_fields' );
		}

		if ( empty( $this->object_changes[ $object->get_id() ] ) ) {
			// No changes to record. Unset the object ID to 'reset' $this->object_changes' state.
			unset( $this->object_changes[ $object->get_id() ] );
			return;
		}

		$object_changes = $this->object_changes[ $object->get_id() ];
		unset( $this->object_changes[ $object->get_id() ] );

		foreach ( $object_changes as $key => $change ) {
			$this->trigger_update_cache_hook_from_change( $object, $key, $change );
		}
	}

	/**
	 * When an object is restored from the trash, action on object changes.
	 *
	 * @param int $object_id The object id being restored.
	 */
	public function untrashed( $object_id ) {
		$object = $this->get_object( $object_id );
		if ( null === $object ) {
			return;
		}

		$this->action_object_cache_changes( $object );
	}

	/**
	 * When an object is to be deleted, prepare object changes to update all fields
	 * and mark those changes as deletes.
	 *
	 * @param int   $object_id The id of the object being deleted.
	 * @param mixed $object    The object being deleted.
	 */
	public function prepare_object_to_be_deleted( $object_id, $object ) {
		if ( ! $object->get_id() ) {
			return;
		}

		$this->prepare_object_changes( $object, 'all_fields' );

		if ( ! isset( $this->object_changes[ $object->get_id() ] ) ) {
			return;
		}

		// If the object is being deleted, we want to record all the changes as deletes.
		foreach ( $this->object_changes[ $object->get_id() ] as $data_key => $data ) {
			$this->object_changes[ $object->get_id() ][ $data_key ]['type'] = 'delete';

			if ( ! isset( $this->object_changes[ $object->get_id() ][ $data_key ]['previous'] ) ) {
				$this->object_changes[ $object->get_id() ][ $data_key ]['previous'] = $data['new'];
			}

			if ( isset( $this->object_changes[ $object->get_id() ][ $data_key ]['new'] ) ) {
				unset( $this->object_changes[ $object->get_id() ][ $data_key ]['new'] );
			}
		}
	}

	/**
	 * When an object is trashed, action on object changes.
	 *
	 * @param int $object_id The id of object being restored.
	 */
	public function trashed( $object_id ) {
		$object = $this->get_object( $object_id );
		if ( null === $object ) {
			return;
		}

		$this->action_object_cache_changes( $object );
	}

	/**
	 * When an object has been deleted, trigger update cache hook on all the object changes.
	 * We cannot use action_object_cache_changes(), which requires an object, here because
	 * object has been deleted.
	 *
	 * @param int $object_id The id of the object being deleted.
	 */
	public function deleted( $object_id ) {
		if ( ! isset( $this->object_changes[ $object_id ] ) ) {
			return;
		}

		$object_changes = $this->object_changes[ $object_id ];
		unset( $this->object_changes[ $object_id ] );

		foreach ( $object_changes as $key => $change ) {
			$this->trigger_update_cache_hook( $change['type'], $object_id, $key, $change['previous'] );
		}
	}

	/**
	 * Triggers the update cache hook for an object change.
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
		switch ( $change['type'] ) {
			case 'update':
				$this->trigger_update_cache_hook( $change['type'], $object->get_id(), $key, $change['new'], $change['previous'] );
				break;
			case 'delete':
				$this->trigger_update_cache_hook( $change['type'], $object->get_id(), $key, $change['previous'] );
				break;
			default:
				$this->trigger_update_cache_hook( $change['type'], $object->get_id(), $key, $change['new'] );
				break;
		}
	}

	/**
	 * Fetches an instance of the object with the given ID.
	 *
	 * @param int $id The ID of the object to fetch.
	 *
	 * @return mixed The object instance, or null if it doesn't exist.
	 */
	private function get_object( $id ) {
		switch ( $this->object_type ) {
			case 'order':
				return wc_get_order( $id );
			case 'subscription':
				return wcs_get_subscription( $id );
			default:
				return apply_filters( "wcs_object_data_cache_manager_get_{$this->object_type}_object", null, $id );
		}
	}
}
