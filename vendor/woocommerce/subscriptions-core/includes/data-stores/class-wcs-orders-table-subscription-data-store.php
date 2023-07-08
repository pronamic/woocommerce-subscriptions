<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subscription Data Store: Stored in Custom Order Tables.
 *
 * Extends OrdersTableDataStore to make sure subscription related meta data is read/updated.
 */
class WCS_Orders_Table_Subscription_Data_Store extends \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore {

	/**
	 * Define subscription specific data which augments the meta of an order.
	 *
	 * The meta keys here determine the prop data that needs to be manually set. We can't use
	 * the $internal_meta_keys property from OrdersTableDataStore because we want its value
	 * too, so instead we create our own and merge it into $internal_meta_keys in __construct.
	 *
	 * @var array
	 */
	protected $subscription_internal_meta_keys = array(
		'_schedule_trial_end',
		'_schedule_next_payment',
		'_schedule_cancelled',
		'_schedule_end',
		'_schedule_payment_retry',
		'_subscription_switch_data',
		'_schedule_start',
	);

	/**
	 * Array of subscription specific data which augments the meta of an order in the form meta_key => prop_key
	 *
	 * Used to read/update props on the subscription.
	 *
	 * @var array
	 */
	protected $subscription_meta_keys_to_props = array(
		'_billing_period'           => 'billing_period',
		'_billing_interval'         => 'billing_interval',
		'_suspension_count'         => 'suspension_count',
		'_cancelled_email_sent'     => 'cancelled_email_sent',
		'_requires_manual_renewal'  => 'requires_manual_renewal',
		'_trial_period'             => 'trial_period',

		'_schedule_trial_end'       => 'schedule_trial_end',
		'_schedule_next_payment'    => 'schedule_next_payment',
		'_schedule_cancelled'       => 'schedule_cancelled',
		'_schedule_end'             => 'schedule_end',
		'_schedule_payment_retry'   => 'schedule_payment_retry',
		'_schedule_start'           => 'schedule_start',

		'_subscription_switch_data' => 'switch_data',
	);

	/**
	 * Table column to WC_Subscription mapping for wc_orders table.
	 *
	 * All columns are inherited from orders except the `transaction_id` column isn't used for subscriptions.
	 *
	 * @var \string[][]
	 */
	protected $order_column_mapping = array(
		'id'                   => array(
			'type' => 'int',
			'name' => 'id',
		),
		'status'               => array(
			'type' => 'string',
			'name' => 'status',
		),
		'type'                 => array(
			'type' => 'string',
			'name' => 'type',
		),
		'currency'             => array(
			'type' => 'string',
			'name' => 'currency',
		),
		'tax_amount'           => array(
			'type' => 'decimal',
			'name' => 'cart_tax',
		),
		'total_amount'         => array(
			'type' => 'decimal',
			'name' => 'total',
		),
		'customer_id'          => array(
			'type' => 'int',
			'name' => 'customer_id',
		),
		'billing_email'        => array(
			'type' => 'string',
			'name' => 'billing_email',
		),
		'date_created_gmt'     => array(
			'type' => 'date',
			'name' => 'date_created',
		),
		'date_updated_gmt'     => array(
			'type' => 'date',
			'name' => 'date_modified',
		),
		'parent_order_id'      => array(
			'type' => 'int',
			'name' => 'parent_id',
		),
		'payment_method'       => array(
			'type' => 'string',
			'name' => 'payment_method',
		),
		'payment_method_title' => array(
			'type' => 'string',
			'name' => 'payment_method_title',
		),
		'ip_address'           => array(
			'type' => 'string',
			'name' => 'customer_ip_address',
		),
		'user_agent'           => array(
			'type' => 'string',
			'name' => 'customer_user_agent',
		),
		'customer_note'        => array(
			'type' => 'string',
			'name' => 'customer_note',
		),
	);

	/**
	 * Table column to WC_Subscription mapping for wc_operational_data table.
	 *
	 * For subscriptions, all columns are inherited from orders except for the following columns:
	 *
	 * - cart_hash
	 * - new_order_email_sent
	 * - order_stock_reduced
	 * - date_paid_gmt
	 * - recorded_sales
	 * - date_completed_gmt
	 *
	 * @var \string[][]
	 */
	protected $operational_data_column_mapping = array(
		'id'                          => array( 'type' => 'int' ),
		'order_id'                    => array( 'type' => 'int' ),
		'created_via'                 => array(
			'type' => 'string',
			'name' => 'created_via',
		),
		'woocommerce_version'         => array(
			'type' => 'string',
			'name' => 'version',
		),
		'prices_include_tax'          => array(
			'type' => 'bool',
			'name' => 'prices_include_tax',
		),
		'coupon_usages_are_counted'   => array(
			'type' => 'bool',
			'name' => 'recorded_coupon_usage_counts',
		),
		'download_permission_granted' => array(
			'type' => 'bool',
			'name' => 'download_permissions_granted',
		),
		'order_key'                   => array(
			'type' => 'string',
			'name' => 'order_key',
		),
		'shipping_tax_amount'         => array(
			'type' => 'decimal',
			'name' => 'shipping_tax',
		),
		'shipping_total_amount'       => array(
			'type' => 'decimal',
			'name' => 'shipping_total',
		),
		'discount_tax_amount'         => array(
			'type' => 'decimal',
			'name' => 'discount_tax',
		),
		'discount_total_amount'       => array(
			'type' => 'decimal',
			'name' => 'discount_total',
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register any custom date types as internal meta keys and props.
		foreach ( wcs_get_subscription_date_types() as $date_type => $date_name ) {
			// The last payment date is derived from other sources and shouldn't be stored on a subscription.
			if ( 'last_payment' === $date_type ) {
				continue;
			}

			$meta_key = wcs_get_date_meta_key( $date_type );

			// Skip any dates which are already core date types. We don't want custom date types to override them.
			if ( isset( $this->subscription_meta_keys_to_props[ $meta_key ] ) ) {
				continue;
			}

			$this->subscription_meta_keys_to_props[ $meta_key ] = wcs_maybe_prefix_key( $date_type, 'schedule_' );
			$this->subscription_internal_meta_keys[]            = $meta_key;
		}

		// Exclude the subscription related meta data we set and manage manually from the objects "meta" data.
		$this->internal_meta_keys = array_merge( $this->internal_meta_keys, $this->subscription_internal_meta_keys );
	}

	/**
	 * Returns data store object to use backfilling.
	 *
	 * @return \WCS_Subscription_Data_Store_CPT
	 */
	protected function get_post_data_store_for_backfill() {
		return new \WCS_Subscription_Data_Store_CPT();
	}

	/**
	 * Gets amount refunded for all related orders.
	 *
	 * @param \WC_Subscription $subscription
	 *
	 * @return string
	 */
	public function get_total_refunded( $subscription ) {
		$total = 0;

		foreach ( $subscription->get_related_orders( 'all' ) as $order ) {
			$total += parent::get_total_refunded( $order );
		}

		return $total;
	}

	/**
	 * Gets the total tax refunded for all related orders.
	 *
	 * @param \WC_Subscription $subscription
	 *
	 * @return float
	 */
	public function get_total_tax_refunded( $subscription ) {
		$total = 0;

		foreach ( $subscription->get_related_orders() as $order ) {
			$total += parent::get_total_tax_refunded( $order );
		}

		return abs( $total );
	}

	/**
	 * Gets the total shipping refunded for all related orders.
	 *
	 * @param \WC_Subscription $subscription The subscription object.
	 *
	 * @return float
	 */
	public function get_total_shipping_refunded( $subscription ) {
		$total = 0;

		foreach ( $subscription->get_related_orders( 'all' ) as $order ) {
			$total += parent::get_total_shipping_refunded( $order );
		}

		return abs( $total );
	}

	/**
	 * Returns count of subscriptions with a specific status.
	 *
	 * @param string $status Subscription status. The wcs_get_subscription_statuses() function returns a list of valid statuses.
	 *
	 * @return int The number of subscriptions with a specific status.
	 */
	public function get_order_count( $status ) {
		global $wpdb;
		$orders_table = self::get_orders_table_name();

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_table} WHERE type = 'shop_subscription' AND status = %s", $status ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get all subscriptions matching the passed in args.
	 *
	 * @param array $args
	 *
	 * @return array of orders
	 */
	public function get_orders( $args = [] ) {
		$args        = wp_parse_args(
			$args,
			[
				'type'   => 'shop_subscription',
				'return' => 'objects',
			]
		);
		$parent_args = $args;

		// We only want IDs from the parent method
		$parent_args['return'] = 'ids';

		$subscriptions = wc_get_orders( $parent_args );

		if ( isset( $args['paginate'] ) && $args['paginate'] ) {

			if ( 'objects' === $args['return'] ) {
				$return = array_map( 'wcs_get_subscription', $subscriptions->orders );
			} else {
				$return = $subscriptions->orders;
			}

			return (object) [
				'orders'        => $return,
				'total'         => $subscriptions->total,
				'max_num_pages' => $subscriptions->max_num_pages,
			];

		} else {

			if ( 'objects' === $args['return'] ) {
				$return = array_map( 'wcs_get_subscription', $subscriptions );
			} else {
				$return = $subscriptions;
			}

			return $return;
		}
	}

	/**
	 * Attempts to restore the specified subscription back to its original status (after having been trashed).
	 *
	 * @param \WC_Subscription $order The order to be untrashed.
	 *
	 * @return bool If the operation was successful.
	 */
	public function untrash_order( WC_Order $subscription ): bool {
		$id     = $subscription->get_id();
		$status = $subscription->get_status();

		if ( 'trash' !== $status ) {
			wc_get_logger()->warning(
				sprintf(
					/* translators: 1: subscription ID, 2: subscription status */
					__( 'Subscription %1$d cannot be restored from the trash: it has already been restored to status "%2$s".', 'woocommerce-subscriptions' ),
					$id,
					$status
				)
			);
			return false;
		}

		$previous_status           = $subscription->get_meta( '_wp_trash_meta_status' );
		$valid_statuses            = wcs_get_subscription_statuses();
		$previous_state_is_invalid = ! array_key_exists( $previous_status, $valid_statuses );
		$pending_is_valid_status   = array_key_exists( 'wc-pending', $valid_statuses );

		if ( $previous_state_is_invalid && $pending_is_valid_status ) {
			// If the previous status is no longer valid, let's try to restore it to "pending" instead.
			wc_get_logger()->warning(
				sprintf(
					/* translators: 1: subscription ID, 2: subscription status */
					__( 'The previous status of subscription %1$d ("%2$s") is invalid. It has been restored to "pending" status instead.', 'woocommerce-subscriptions' ),
					$id,
					$previous_status
				)
			);

			$previous_status = 'pending';
		} elseif ( $previous_state_is_invalid ) {
			// If we cannot restore to pending, we should probably stand back and let the merchant intervene some other way.
			wc_get_logger()->warning(
				sprintf(
					/* translators: 1: subscription ID, 2: subscription status */
					__( 'The previous status of subscription %1$d ("%2$s") is invalid. It could not be restored.', 'woocommerce-subscriptions' ),
					$id,
					$previous_status
				)
			);

			return false;
		}

		/**
		 * Fires before a subscription is restored from the trash.
		 *
		 * @since 5.2.0
		 *
		 * @param int    $subscription_id Subscription ID.
		 * @param string $previous_status The status of the subscription before it was trashed.
		 */
		do_action( 'woocommerce_untrash_subscription', $subscription->get_id(), $previous_status );

		$subscription->set_status( $previous_status );
		$subscription->save();

		// Was the status successfully restored? Let's clean up the meta and indicate success...
		if ( 'wc-' . $subscription->get_status() === $previous_status ) {
			$subscription->delete_meta_data( '_wp_trash_meta_status' );
			$subscription->delete_meta_data( '_wp_trash_meta_time' );
			$subscription->delete_meta_data( '_wp_trash_meta_comments_status' );
			$subscription->save_meta_data();

			$data_synchronizer = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
			if ( $data_synchronizer->data_sync_is_enabled() ) {
				//The previous $subscription->save() will have forced a sync to the posts table,
				//this implies that the post status is not "trash" anymore, and thus
				//wp_untrash_post would do nothing.
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'trash',
					)
				);

				wp_untrash_post( $id );
			}

			return true;
		}

		// ...Or log a warning and bail.
		wc_get_logger()->warning(
			sprintf(
				/* translators: 1: subscription ID, 2: subscription status */
				__( 'Something went wrong when trying to restore subscription %d from the trash. It could not be restored.', 'woocommerce-subscriptions' ),
				$id
			)
		);

		return false;
	}

	/**
	 * Method to delete a subscription from the database.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param array            $args Array of args to pass to the delete method.
	 *
	 * @return void
	 */
	public function delete( &$subscription, $args = array() ) {
		$subscription_id = $subscription->get_id();

		if ( ! $subscription_id ) {
			return;
		}

		if ( ! empty( $args['force_delete'] ) ) {

			/**
			 * Fires immediately before a subscription is deleted from the database.
			 *
			 * @since 5.2.0
			 *
			 * @param int             $subscription_id ID of the subscription about to be deleted.
			 * @param WC_Subscription $subscription    Instance of the subscription that is about to be deleted.
			 */
			do_action( 'woocommerce_before_delete_subscription', $subscription_id, $subscription );

			$this->delete_order_data_from_custom_order_tables( $subscription_id );

			$subscription->set_id( 0 );

			// If this datastore method is called while the posts table is authoritative, refrain from deleting post data.
			if ( $subscription->get_data_store()->get_current_class_name() !== self::class ) {
				return;
			}

			// Delete the associated post, which in turn deletes the subscription items, etc. through {@see WC_Post_Data}.
			// Once we stop creating placehold_order in posts, we should do the cleanup here instead.
			wp_delete_post( $subscription_id );

			/**
			 * Fires immediately after a subscription is deleted from the database.
			 *
			 * Also calls `woocommerce_subscription_deleted` hook for backwards compatibility @see WC_Subscriptions_Manager::trigger_subscription_deleted_hook()
			 *
			 * @since 5.2.0
			 *
			 * @param int $subscription_id ID of the subscription about to be deleted.
			 */
			do_action( 'woocommerce_delete_subscription', $subscription_id );
			do_action( 'woocommerce_subscription_deleted', $subscription_id );
		} else {
			/**
			 * Fires immediately before a subscription is trashed.
			 *
			 * @since 5.2.0
			 *
			 * @param int             $subscription_id ID of the subscription about to be trashed.
			 * @param WC_Subscription $subscription    Instance of the subscription that is about to be trashed.
			 */
			do_action( 'woocommerce_before_trash_subscription', $subscription_id, $subscription );

			$this->trash_order( $subscription );

			/**
			 * Fires immediately after a subscription is trashed.
			 *
			 * Also calls `woocommerce_subscription_trashed` for backwards compatibility @see WC_Subscriptions_Manager::trigger_subscription_trashed_hook()
			 *
			 * @since 5.2.0
			 *
			 * @param int $subscription_id ID of the order about to be deleted.
			 */
			do_action( 'woocommerce_trash_subscription', $subscription_id );
			do_action( 'woocommerce_subscription_trashed', $subscription_id );
		}
	}

	/**
	 * Creates a new subscription in the database.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 */
	public function create( &$subscription ) {
		parent::create( $subscription );
		do_action( 'woocommerce_new_subscription', $subscription->get_id() );
	}

	/**
	 * Updates a subscription in the database.
	 *
	 * @param \WC_Subscription $subscription Subscription object
	 */
	public function update( &$subscription ) {
		// We don't want to call parent::update() here because OrdersTableDataStore includes a JIT setting of the paid date which is not needed for subscriptions, and also very resource intensive due to needed to search related orders to get the latest orders paid date.
		if ( null === $subscription->get_date_created( 'edit' ) ) {
			$subscription->set_date_created( time() );
		}

		$subscription->set_version( \Automattic\Jetpack\Constants::get_constant( 'WC_VERSION' ) );

		// Fetch changes.
		$changes = $subscription->get_changes();
		$this->persist_updates( $subscription );

		// Update download permissions if necessary.
		if ( array_key_exists( 'billing_email', $changes ) || array_key_exists( 'customer_id', $changes ) ) {
			$data_store = \WC_Data_Store::load( 'customer-download' );
			$data_store->update_user_by_order_id( $subscription->get_id(), $subscription->get_customer_id(), $subscription->get_billing_email() );
		}

		// Mark user account as active.
		if ( array_key_exists( 'customer_id', $changes ) ) {
			wc_update_user_last_active( $subscription->get_customer_id() );
		}

		$subscription->apply_changes();
		$this->clear_caches( $subscription );

		// For backwards compatibility we trigger the `woocommerce_update_order` hook.
		do_action( 'woocommerce_update_order', $subscription->get_id(), $subscription );

		do_action( 'woocommerce_update_subscription', $subscription->get_id(), $subscription );
	}

	/**
	 * Saves a subscription to the database.
	 *
	 * When a subscription is saved to the database we need to ensure we also save core subscription properties. The
	 * parent::persist_order_to_db() will create and save the WC_Order inherited data, this method will save the
	 * subscription core properties.
	 *
	 * @param WC_Subscription $subscription The subscription to save.
	 * @param bool            $force_all_fields Optional. Whether to force all fields to be saved. Default false.
	 */
	protected function persist_order_to_db( &$subscription, bool $force_all_fields = false ) {
		$is_update = ( 0 !== absint( $subscription->get_id() ) );

		// Call the parent function first so WC can get an ID if this a new subscription.
		parent::persist_order_to_db( $subscription, $force_all_fields );

		// Get the subscription's current raw metadata.
		$subscription_meta_data = array_column( $this->data_store_meta->read_meta( $subscription ), null, 'meta_key' );

		// Determine what fields need to be saved. Forcing all fields to be saved is only allowed when updating.
		if ( $force_all_fields && $is_update ) {
			$props_to_save = $this->subscription_meta_keys_to_props;
		} else {
			$props_to_save = $this->get_props_to_update( $subscription, $this->subscription_meta_keys_to_props );
		}

		foreach ( $props_to_save as $meta_key => $prop ) {
			$is_date_prop = ( 'schedule_' === substr( $prop, 0, 9 ) );

			if ( $is_date_prop ) {
				$meta_value = $subscription->get_date( $prop );
			} else {
				$meta_value = $subscription->{"get_$prop"}( 'edit' );
			}

			// Store as a string of the boolean for backward compatibility (yep, it's gross)
			if ( 'requires_manual_renewal' === $prop ) {
				$meta_value = wc_string_to_bool( $meta_value ) ? 'true' : 'false';
			}

			$existing_meta_data = $subscription_meta_data[ $meta_key ] ?? false;
			$new_meta_data      = [
				'key'   => $meta_key,
				'value' => $meta_value,
			];

			if ( empty( $existing_meta_data ) ) {
				$this->data_store_meta->add_meta( $subscription, (object) $new_meta_data );
			} elseif ( $existing_meta_data->meta_value !== $new_meta_data['value'] ) {
				$new_meta_data['id'] = $existing_meta_data->meta_id;
				$this->data_store_meta->update_meta( $subscription, (object) $new_meta_data );
			}
		}
	}

	/**
	 * Initializes the subscription based on data received from the database.
	 *
	 * @param WC_Abstract_Order $subscription      The subscription object.
	 * @param int               $subscription_id   The subscription's ID.
	 * @param stdClass          $subscription_data All the subscription's data, retrieved from the database.
	 */
	protected function init_order_record( \WC_Abstract_Order &$subscription, int $subscription_id, \stdClass $subscription_data ) {
		// Call the parent version of this function which will set all the core order properties that a subscription inherits.
		parent::init_order_record( $subscription, $subscription_id, $subscription_data );

		if ( empty( $subscription_data->meta_data ) ) {
			return;
		}

		// Flag the subscription as still being read from the database while we set our subscription properties.
		$subscription->set_object_read( false );

		// Set subscription specific properties that we store in meta.
		$meta_data    = wp_list_pluck( $subscription_data->meta_data, 'meta_value', 'meta_key' );
		$dates_to_set = [];
		$props_to_set = [];

		foreach ( $this->subscription_meta_keys_to_props as $meta_key => $prop_key ) {
			$is_scheduled_date = 0 === strpos( $prop_key, 'schedule' );
			$is_internal_meta  = in_array( $meta_key, $this->internal_meta_keys, true );

			// We only need to set props that are internal meta keys or dates. Everything else is treated as meta.
			if ( ! $is_scheduled_date && ! $is_internal_meta ) {
				continue;
			}

			// If we're setting the start date and it's missing, we set it to the created date.
			if ( 'schedule_start' === $prop_key && empty( $meta_data[ $meta_key ] ) ) {
				$meta_data[ $meta_key ] = $subscription->get_date( 'date_created' );
			}

			// If there's no meta data, we don't need to set anything.
			if ( ! isset( $meta_data[ $meta_key ] ) ) {
				continue;
			}

			if ( $is_scheduled_date ) {
				$dates_to_set[ $prop_key ] = $meta_data[ $meta_key ];
			} else {
				$props_to_set[ $prop_key ] = maybe_unserialize( $meta_data[ $meta_key ] );
			}
		}

		// Set the dates and props.
		if ( $dates_to_set ) {
			$subscription->update_dates( $dates_to_set );
		}

		$subscription->set_props( $props_to_set );

		// Flag the subscription as read.
		$subscription->set_object_read( true );
	}

	/**
	 * Updates subscription dates in the database.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 *
	 * @return DateTime[] The date properties which were saved to the database in array format: [ $prop_name => DateTime Object ]
	 */
	public function save_dates( $subscription ) {
		$dates_to_save  = [];
		$changes        = $subscription->get_changes();
		$date_meta_keys = [
			'_schedule_payment_retry' => 'schedule_payment_retry', // This is the only date potentially missing from wcs_get_subscription_date_types().
		];

		// Add any custom date types to the date meta keys we need to save.
		foreach ( wcs_get_subscription_date_types() as $date_type => $date_name ) {
			if ( 'last_payment' === $date_type ) {
				continue;
			}

			$date_meta_keys[ wcs_get_date_meta_key( $date_type ) ] = wcs_maybe_prefix_key( $date_type, 'schedule_' );
		}

		$date_meta_keys_to_props = array_intersect_key( $this->subscription_meta_keys_to_props, $date_meta_keys );

		// Save the changes to scheduled dates.
		foreach ( $this->get_props_to_update( $subscription, $date_meta_keys_to_props ) as $prop ) {
			$dates_to_save[] = $prop;
		}

		// Record any changes to the created date.
		if ( isset( $changes['date_created'] ) ) {
			$dates_to_save[] = 'date_created';
		}

		// Record any changes to the modified date.
		if ( isset( $changes['date_modified'] ) ) {
			$dates_to_save[] = 'date_modified';
		}

		// Backfill the saved dates if syncing is enabled.
		$data_synchronizer = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
		if ( $data_synchronizer && $data_synchronizer->data_sync_is_enabled() ) {
			$this->get_post_data_store_for_backfill()->write_dates_to_database( $subscription, $dates_to_save );
		}

		return $this->write_dates_to_database( $subscription, $dates_to_save );
	}

	/**
	 * Writes subscription dates to the database.
	 *
	 * @param WC_Subscription $subscription  The subscription to write date changes for.
	 * @param array           $dates_to_save The dates to write to the database.
	 *
	 * @return WC_DateTime[] The date properties saved to the database in the format: array( $prop_name => WC_DateTime Object ).
	 */
	public function write_dates_to_database( $subscription, $dates_to_save ) {
		global $wpdb;
		$dates_to_save      = array_flip( $dates_to_save );
		$dates_saved        = [];
		$order_update_query = [];

		if ( isset( $dates_to_save['date_created'] ) ) {
			$order_update_query[] = $wpdb->prepare( '`date_created_gmt` = %s', gmdate( 'Y-m-d H:i:s', $subscription->get_date_created( 'edit' )->getTimestamp() ) );

			// Mark the created date as saved.
			unset( $dates_to_save['date_created'] );
			$dates_saved['date_created'] = $subscription->get_date_created();
		}

		if ( isset( $dates_to_save['date_modified'] ) ) {
			$order_update_query[] = $wpdb->prepare( '`date_updated_gmt` = %s', gmdate( 'Y-m-d H:i:s', $subscription->get_date_modified( 'edit' )->getTimestamp() ) );

			// Mark the modified date as saved.
			unset( $dates_to_save['date_modified'] );
			$dates_saved['date_modified'] = $subscription->get_date_modified();
		}

		// Manually update the order's created and/or modified date if it has changed.
		if ( ! empty( $order_update_query ) ) {
			$table_name = self::get_orders_table_name();
			$set        = implode( ', ', $order_update_query );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table_name} SET {$set} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$subscription->get_id()
				)
			);
		}

		$subscription_meta_data = array_column( $this->data_store_meta->read_meta( $subscription ), null, 'meta_key' );

		// Write the remaining dates to meta.
		foreach ( $dates_to_save as $date_prop => $index ) {
			$date_type          = wcs_normalise_date_type_key( $date_prop );
			$meta_key           = wcs_get_date_meta_key( $date_type );
			$existing_meta_data = $subscription_meta_data[ $meta_key ] ?? false;
			$new_meta_data      = [
				'key'   => $meta_key,
				'value' => $subscription->get_date( $date_type ),
			];

			if ( ! empty( $existing_meta_data ) ) {
				$new_meta_data['id'] = $existing_meta_data->meta_id;
				$this->data_store_meta->update_meta( $subscription, (object) $new_meta_data );
			} else {
				$this->data_store_meta->add_meta( $subscription, (object) $new_meta_data );
			}

			$dates_saved[ $date_prop ] = wcs_get_datetime_from( $subscription->get_time( $date_type ) );
		}

		return $dates_saved;
	}

	/**
	 * Searches subscription data for a term and returns subscription IDs.
	 *
	 * @param string $term Term to search.
	 *
	 * @return array A list of subscriptions IDs that match the search term.
	 */
	public function search_subscriptions( $term ) {
		add_filter( 'woocommerce_order_table_search_query_meta_keys', [ $this, 'get_subscription_order_table_search_fields' ] );

		$subscription_ids = wc_get_orders(
			[
				's'      => $term,
				'type'   => 'shop_subscription',
				'status' => array_keys( wcs_get_subscription_statuses() ),
				'return' => 'ids',
				'limit'  => -1,
			]
		);

		remove_filter( 'woocommerce_order_table_search_query_meta_keys', [ $this, 'get_subscription_order_table_search_fields' ] );

		return apply_filters( 'woocommerce_shop_subscription_search_results', $subscription_ids, $term, $this->get_subscription_order_table_search_fields() );
	}

	/**
	 * Gets the subscription search fields.
	 *
	 * This function is hooked onto the 'woocommerce_order_table_search_query_meta_keys' filter.
	 *
	 * @param array The default order search fields.
	 *
	 * @return array The subscription search fields.
	 */
	public function get_subscription_order_table_search_fields( $search_fields = [] ) {
		return array_map(
			'wc_clean',
			apply_filters(
				'woocommerce_shop_subscription_search_fields',
				[
					'_billing_address_index',
					'_shipping_address_index',
				]
			)
		);
	}

	/**
	 * Gets user IDs for customers who have a subscription.
	 *
	 * @return array An array of user IDs.
	 */
	public function get_subscription_customer_ids() {
		global $wpdb;
		$table_name = self::get_orders_table_name();

		return $wpdb->get_col( "SELECT DISTINCT customer_id FROM {$table_name} WHERE type = 'shop_subscription'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes all rows in the postmeta table with the given meta key.
	 *
	 * @param string $meta_key The meta key to delete.
	 */
	public function delete_all_metadata_by_key( $meta_key ) {
		global $wpdb;

		$wpdb->delete( self::get_meta_table_name(), [ 'meta_key' => $meta_key ], [ '%s' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * Count subscriptions by status.
	 *
	 * @return array
	 */
	public function get_subscriptions_count_by_status() {
		global $wpdb;

		$table   = self::get_orders_table_name();
		$results = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} WHERE type = 'shop_subscription' GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $results ? array_combine( array_column( $results, 'status' ), array_map( 'absint', array_column( $results, 'cnt' ) ) ) : array();
	}
}
