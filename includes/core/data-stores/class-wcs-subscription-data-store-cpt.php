<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscription Data Store: Stored in CPT (posts table).
 *
 * Extends WC_Order_Data_Store_CPT to make sure subscription related meta data is read/updated.
 *
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @category Class
 * @author   Prospress
 */
class WCS_Subscription_Data_Store_CPT extends WC_Order_Data_Store_CPT implements WC_Object_Data_Store_Interface, WC_Order_Data_Store_Interface {

	/**
	 * Define subscription specific data which augments the meta of an order.
	 *
	 * The meta keys here determine the prop data that needs to be manually set. We can't use
	 * the $internal_meta_keys property from WC_Order_Data_Store_CPT because we want its value
	 * too, so instead we create our own and merge it into $internal_meta_keys in __construct.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @var array
	 */
	protected $subscription_meta_keys_to_props = array(
		'_billing_period'           => 'billing_period',
		'_billing_interval'         => 'billing_interval',
		'_suspension_count'         => 'suspension_count',
		'_cancelled_email_sent'     => 'cancelled_email_sent',
		'_requires_manual_renewal'  => 'requires_manual_renewal',
		'_trial_period'             => 'trial_period',
		'_last_order_date_created'  => 'last_order_date_created',

		'_schedule_trial_end'       => 'schedule_trial_end',
		'_schedule_next_payment'    => 'schedule_next_payment',
		'_schedule_cancelled'       => 'schedule_cancelled',
		'_schedule_end'             => 'schedule_end',
		'_schedule_payment_retry'   => 'schedule_payment_retry',
		'_schedule_start'           => 'schedule_start',

		'_subscription_switch_data' => 'switch_data',
	);

	/**
	 * Custom setters for subscription internal props in the form meta_key => set_|get_{value}.
	 *
	 * @var string[]
	 */
	protected $internal_data_store_key_getters = array(
		'_schedule_start'                           => 'schedule_start',
		'_schedule_trial_end'                       => 'schedule_trial_end',
		'_schedule_next_payment'                    => 'schedule_next_payment',
		'_schedule_cancelled'                       => 'schedule_cancelled',
		'_schedule_end'                             => 'schedule_end',
		'_schedule_payment_retry'                   => 'schedule_payment_retry',
		'_subscription_renewal_order_ids_cache'     => 'renewal_order_ids_cache',
		'_subscription_resubscribe_order_ids_cache' => 'resubscribe_order_ids_cache',
		'_subscription_switch_order_ids_cache'      => 'switch_order_ids_cache',
	);

	/**
	 * The data store instance for the custom order tables.
	 *
	 * @var WCS_Orders_Table_Subscription_Data_Store
	 */
	protected $orders_table_data_store;

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

		// Exclude the subscription related meta data we set and manage manually from the objects "meta" data
		$this->internal_meta_keys = array_merge( $this->internal_meta_keys, $this->subscription_internal_meta_keys );
	}

	/**
	 * Create a new subscription in the database.
	 *
	 * @param WC_Subscription $subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function create( &$subscription ) {

		$subscription_status = $subscription->get_status( 'edit' );

		if ( ! $subscription_status ) {
			$subscription->set_status( 'wc' . apply_filters( 'woocommerce_default_subscription_status', 'pending' ) );
		}

		/**
		 * This function is called on the `woocommerce_new_order_data` filter.
		 * We hook into this function, calling our own filter `woocommerce_new_subscription_data` to allow overriding the default subscription data.
		 */
		$new_subscription_data = function ( $args ) {
			return apply_filters( 'woocommerce_new_subscription_data', $args );
		};
		add_filter( 'woocommerce_new_order_data', $new_subscription_data );
		parent::create( $subscription );
		remove_filter( 'woocommerce_new_order_data', $new_subscription_data );

		do_action( 'woocommerce_new_subscription', $subscription->get_id() );
	}

	/**
	 * Returns an array of meta for an object.
	 *
	 * Ignore meta data that we don't want accessible on the object via meta APIs.
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 * @param  WC_Data $object
	 * @return array
	 */
	public function read_meta( &$object ) {
		$meta_data = parent::read_meta( $object );

		$props_to_ignore = $this->get_props_to_ignore();

		foreach ( $meta_data as $index => $meta_object ) {
			if ( array_key_exists( $meta_object->meta_key, $props_to_ignore ) ) {
				unset( $meta_data[ $index ] );
			}
		}

		return $meta_data;
	}

	/**
	 * Read subscription data.
	 *
	 * @param WC_Subscription $subscription
	 * @param object $post_object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	protected function read_order_data( &$subscription, $post_object ) {

		// Set all order meta data, as well as data defined by WC_Subscription::$extra_keys which has corresponding setter methods
		parent::read_order_data( $subscription, $post_object );

		$props_to_set = $dates_to_set = array();

		foreach ( $this->subscription_meta_keys_to_props as $meta_key => $prop_key ) {
			if ( 0 === strpos( $prop_key, 'schedule' ) || in_array( $meta_key, $this->subscription_internal_meta_keys ) ) {
				// Keeping this occurrence of `get_post_meta()` as get_post here does not work well.
				$meta_value = get_post_meta( $subscription->get_id(), $meta_key, true );

				// Dates are set via update_dates() to make sure relationships between dates are validated
				if ( 0 === strpos( $prop_key, 'schedule' ) ) {
					$date_type = str_replace( 'schedule_', '', $prop_key );

					if ( 'start' === $date_type && ! $meta_value ) {
						$meta_value = $subscription->get_date( 'date_created' );
					}

					$dates_to_set[ $date_type ] = ( false == $meta_value ) ? 0 : $meta_value;
				} else {
					$props_to_set[ $prop_key ] = $meta_value;
				}
			}
		}

		/**
		 * WC 3.5.0 and our 2.4.0 post author upgrade scripts didn't account for subscriptions created manually by admin users with a user ID not equal to 1.
		 * This resulted in those subscription post author columns not being updated and so linked to the admin user who created them, not the customer.
		 *
		 * To make sure all subscriptions are linked to the correct customer, we revert to the previous behavior of
		 * getting the customer user from post meta.
		 * The fix is only applied on WC 3.5.0 because 3.5.1 brought back the old way (pre 3.5.0) of getting the
		 * customer ID for orders.
		 *
		 * @see https://github.com/Prospress/woocommerce-subscriptions/issues/3036
		 */
		if ( '3.5.0' === WC()->version ) {
			$props_to_set['customer_id'] = $subscription->get_meta( '_customer_user', true );
		}

		$subscription->update_dates( $dates_to_set );
		$subscription->set_props( $props_to_set );
	}

	/**
	 * Update subscription in the database.
	 *
	 * @param WC_Subscription $subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function update( &$subscription ) {

		// We don't want to call parent here because WC_Order_Data_Store_CPT includes a JIT setting of the paid date which is not needed for subscriptions, and also very resource intensive
		Abstract_WC_Order_Data_Store_CPT::update( $subscription );

		// We used to call parent::update() above, which triggered this hook, so we trigger it manually here for backward compatibility (and to improve compatibility with 3rd party code which may run validation or additional operations on it which should also be applied to a subscription)
		do_action( 'woocommerce_update_order', $subscription->get_id(), $subscription );

		do_action( 'woocommerce_update_subscription', $subscription->get_id(), $subscription );
	}

	/**
	 * Update post meta for a subscription based on it's settings in the WC_Subscription class.
	 *
	 * @param WC_Subscription $subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	protected function update_post_meta( &$subscription ) {

		$updated_props = array();

		foreach ( $this->get_props_to_update( $subscription, $this->subscription_meta_keys_to_props ) as $meta_key => $prop ) {
			$meta_value = ( 'schedule_' == substr( $prop, 0, 9 ) ) ? $subscription->get_date( $prop ) : $subscription->{"get_$prop"}( 'edit' );

			if ( 'schedule_start' === $prop && ! $meta_value ) {
				$meta_value = $subscription->get_date( 'date_created' );
			}

			// Store as a string of the boolean for backward compatibility (yep, it's gross)
			if ( 'requires_manual_renewal' === $prop ) {
				$meta_value = $meta_value ? 'true' : 'false';
			}

			update_post_meta( $subscription->get_id(), $meta_key, $meta_value );
			$updated_props[] = $prop;
		}

		do_action( 'woocommerce_subscription_object_updated_props', $subscription, $updated_props );

		parent::update_post_meta( $subscription );
	}

	/**
	 * Get the subscription's post title
	 */
	protected function get_post_title() {
		// @codingStandardsIgnoreStart
		/* translators: %s: Order date */
		return sprintf( __( 'Subscription &ndash; %s', 'woocommerce-subscriptions' ), ( new DateTime( 'now' ) )->format( _x( 'M d, Y @ h:i A', 'Order date parsed by DateTime::format', 'woocommerce-subscriptions' ) ) );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Excerpt for post.
	 *
	 * @param  \WC_Subscription $order Subscription object.
	 * @return string
	 */
	protected function get_post_excerpt( $order ) {
		return $order->get_customer_note();
	}

	/**
	 * Get amount refunded for all related orders.
	 *
	 * @param WC_Subscription $subscription
	 * @return string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function get_total_refunded( $subscription ) {

		$total = 0;

		foreach ( $subscription->get_related_orders( 'all' ) as $order ) {
			$total += parent::get_total_refunded( $order );
		}

		return $total;
	}

	/**
	 * Get the total tax refunded for all related orders.
	 *
	 * @param WC_Subscription $subscription
	 * @return float
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function get_total_tax_refunded( $subscription ) {

		$total = 0;

		foreach ( $subscription->get_related_orders( 'all' ) as $order ) {
			$total += parent::get_total_tax_refunded( $order );
		}

		return abs( $total );
	}

	/**
	 * Get the total shipping refunded for all related orders.
	 *
	 * @param WC_Subscription $subscription
	 * @return float
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function get_total_shipping_refunded( $subscription ) {

		$total = 0;

		foreach ( $subscription->get_related_orders( 'all' ) as $order ) {
			$total += parent::get_total_shipping_refunded( $order );
		}

		return abs( $total );
	}

	/**
	 * Return count of subscriptions with type.
	 *
	 * @param  string $type
	 * @return int
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function get_order_count( $status ) {
		global $wpdb;
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( * ) FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' AND post_status = %s", $status ) ) );
	}

	/**
	 * Get all subscriptions matching the passed in args.
	 *
	 * @see    wc_get_orders()
	 * @param  array $args
	 * @return array of orders
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function get_orders( $args = array() ) {

		$parent_args = $args = wp_parse_args( $args, array(
			'type'   => 'shop_subscription',
			'return' => 'objects',
		) );

		// We only want IDs from the parent method
		$parent_args['return'] = 'ids';

		$subscriptions = wc_get_orders( $parent_args );

		if ( isset( $args['paginate'] ) && $args['paginate'] ) {

			if ( 'objects' === $args['return'] ) {
				$return = array_map( 'wcs_get_subscription', $subscriptions->orders );
			} else {
				$return = $subscriptions->orders;
			}

			return (object) array(
				'orders'        => $return,
				'total'         => $subscriptions->total,
				'max_num_pages' => $subscriptions->max_num_pages,
			);

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
	 * Update subscription dates in the database.
	 *
	 * @param WC_Subscription $subscription
	 * @return array The date properties saved to the database in the format: array( $prop_name => DateTime Object )
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.6
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

		// Get the date meta keys we need to save.
		$date_meta_keys_to_props = array_intersect_key( $this->subscription_meta_keys_to_props, $date_meta_keys );

		// Save the changes to scheduled dates.
		foreach ( $this->get_props_to_update( $subscription, $date_meta_keys_to_props ) as $prop ) {
			$dates_to_save[] = $prop;
		}

		// Save any changes to the created date.
		if ( isset( $changes['date_created'] ) ) {
			$dates_to_save[] = 'date_created';
		}

		// Save any changes to the modified date.
		if ( isset( $changes['date_modified'] ) ) {
			$dates_to_save[] = 'date_modified';
		}

		return $this->write_dates_to_database( $subscription, $dates_to_save );
	}

	/**
	 * Writes subscription dates to the database.
	 *
	 * @param WC_Subscription $subscription  The subscription to write date changes for.
	 * @param array           $dates_to_save The dates to write to the database.
	 *
	 * @return WC_DateTime[] The date properties saved to the database in the format: array( $prop_name => WC_DateTime Object )
	 */
	public function write_dates_to_database( $subscription, $dates_to_save ) {
		// Flip the dates for easier access and removal.
		$dates_to_save = array_flip( $dates_to_save );
		$dates_saved   = [];
		$post_data     = [];

		if ( isset( $dates_to_save['date_created'] ) ) {
			$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $subscription->get_date_created( 'edit' )->getOffsetTimestamp() );
			$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $subscription->get_date_created( 'edit' )->getTimestamp() );

			// Mark the created date as saved.
			$dates_saved['date_created'] = $subscription->get_date_created();
			unset( $dates_to_save['date_created'] );
		}

		if ( isset( $dates_to_save['date_modified'] ) ) {
			$post_data['post_modified']     = gmdate( 'Y-m-d H:i:s', $subscription->get_date_modified( 'edit' )->getOffsetTimestamp() );
			$post_data['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', $subscription->get_date_modified( 'edit' )->getTimestamp() );

			// Mark the modified date as saved.
			$dates_saved['date_modified'] = $subscription->get_date_modified();
			unset( $dates_to_save['date_modified'] );
		}

		// Write the dates stored on in post data.
		if ( ! empty( $post_data ) ) {
			$post_data['ID'] = $subscription->get_id();
			wp_update_post( $post_data );
		}

		// Write the remaining dates to meta.
		foreach ( $dates_to_save as $date_prop => $index ) {
			$date_type = wcs_normalise_date_type_key( $date_prop );

			update_post_meta( $subscription->get_id(), wcs_get_date_meta_key( $date_type ), $subscription->get_date( $date_type ) );
			$dates_saved[ $date_prop ] = wcs_get_datetime_from( $subscription->get_time( $date_type ) );
		}

		return $dates_saved;
	}

	/**
	 * Get the props to update, and remove order meta data that isn't used on a subscription.
	 *
	 * Important for performance, because it avoids calling getters/setters on props that don't need
	 * to be get/set, which in the case for get_date_paid(), or get_date_completed(), can be quite
	 * resource intensive as it requires doing a related orders query. Also just avoids filling up the
	 * post meta table more than is needed.
	 *
	 * @param  WC_Data $object              The WP_Data object (WC_Coupon for coupons, etc).
	 * @param  array   $meta_key_to_props   A mapping of meta keys => prop names.
	 * @param  string  $meta_type           The internal WP meta type (post, user, etc).
	 * @return array                        A mapping of meta keys => prop names, filtered by ones that should be updated.
	 */
	protected function get_props_to_update( $object, $meta_key_to_props, $meta_type = 'post' ) {
		$props_to_update = parent::get_props_to_update( $object, $meta_key_to_props, $meta_type );
		$props_to_ignore = $this->get_props_to_ignore();

		foreach ( $props_to_ignore as $meta_key => $prop ) {
			unset( $props_to_update[ $meta_key ] );
		}

		return $props_to_update;
	}

	/**
	 * Get the props set on a subscription which we don't want used on a subscription, which may be
	 * inherited order meta data, or other values using the post meta data store but not as props.
	 *
	 * @return array A mapping of meta keys => prop names
	 */
	protected function get_props_to_ignore() {

		$props_to_ignore = array(
			'_transaction_id' => 'transaction_id',
			'_date_completed' => 'date_completed',
			'_date_paid'      => 'date_paid',
			'_cart_hash'      => 'cart_hash',
		);

		return apply_filters( 'wcs_subscription_data_store_props_to_ignore', $props_to_ignore, $this );
	}

	/**
	 * Search subscription data for a term and returns subscription ids
	 *
	 * @param string $term Term to search
	 * @return array of subscription ids
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public function search_subscriptions( $term ) {
		global $wpdb;

		$subscription_ids = array();

		$search_fields = array_map(
			'wc_clean',
			apply_filters(
				'woocommerce_shop_subscription_search_fields',
				[
					'_billing_address_index',
					'_shipping_address_index',
				]
			)
		);

		if ( is_numeric( $term ) ) {
			$subscription_ids[] = absint( $term );
		}

		if ( ! empty( $search_fields ) ) {

			$subscription_ids = array_unique( array_merge(
				$wpdb->get_col(
					$wpdb->prepare( "
						SELECT DISTINCT p1.post_id
						FROM {$wpdb->postmeta} p1
						WHERE p1.meta_value LIKE '%%%s%%'", $wpdb->esc_like( wc_clean( $term ) ) ) . " AND p1.meta_key IN ('" . implode( "','", array_map( 'esc_sql', $search_fields ) ) . "')"
				),
				$wpdb->get_col(
					$wpdb->prepare( "
						SELECT order_id
						FROM {$wpdb->prefix}woocommerce_order_items as order_items
						WHERE order_item_name LIKE '%%%s%%'
						",
						$wpdb->esc_like( wc_clean( $term ) )
					)
				),
				$wpdb->get_col(
					$wpdb->prepare( "
						SELECT p1.ID
						FROM {$wpdb->posts} p1
						INNER JOIN {$wpdb->postmeta} p2 ON p1.ID = p2.post_id
						INNER JOIN {$wpdb->users} u ON p2.meta_value = u.ID
						WHERE u.user_email LIKE '%%%s%%'
						AND p2.meta_key = '_customer_user'
						AND p1.post_type = 'shop_subscription'
						",
						esc_attr( $term )
					)
				),
				$subscription_ids
			) );
		}

		return apply_filters( 'woocommerce_shop_subscription_search_results', $subscription_ids, $term, $search_fields );
	}

	/**
	 * Get the user IDs for customers who have a subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.4.3
	 * @return array The user IDs.
	 */
	public function get_subscription_customer_ids() {
		global $wpdb;

		return $wpdb->get_col(
			"SELECT DISTINCT meta_value
			FROM {$wpdb->postmeta} AS subscription_meta INNER JOIN {$wpdb->posts} AS posts ON subscription_meta.post_id = posts.ID
			WHERE subscription_meta.meta_key = '_customer_user' AND posts.post_type = 'shop_subscription'"
		);
	}

	/**
	 * Deletes all rows in the postmeta table with the given meta key.
	 *
	 * @param string $meta_key The meta key to delete.
	 */
	public function delete_all_metadata_by_key( $meta_key ) {
		// Set variables to define ambiguous parameters of delete_metadata()
		$id         = null; // All IDs.
		$meta_value = null; // Delete any values.
		$delete_all = true;
		delete_metadata( 'post', $id, $meta_key, $meta_value, $delete_all );

		// If custom order tables is not enabled, but Data Syncing is enabled, delete the meta from the custom order tables.
		if ( ! wcs_is_custom_order_tables_usage_enabled() && wcs_is_custom_order_tables_data_sync_enabled() ) {
			$this->get_cot_data_store_instance()->delete_all_metadata_by_key( $meta_key );
		}
	}

	/**
	 * Count subscriptions by status.
	 *
	 * @return array
	 */
	public function get_subscriptions_count_by_status() {
		return (array) wp_count_posts( 'shop_subscription' );
	}

	/**
	 * Sets the subscription's start date.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $date
	 */
	public function set_schedule_start( $subscription, $date ) {
		update_post_meta( $subscription->get_id(), '_schedule_start', $date );
	}

	/**
	 * Sets the subscription's trial end date.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $date
	 */
	public function set_schedule_trial_end( $subscription, $date ) {
		update_post_meta( $subscription->get_id(), '_schedule_trial_end', $date );
	}

	/**
	 * Sets the subscription's next payment date.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $date
	 */
	public function set_schedule_next_payment( $subscription, $date ) {
		update_post_meta( $subscription->get_id(), '_schedule_next_payment', $date );
	}

	/**
	 * Sets the subscription's cancelled date.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $date
	 */
	public function set_schedule_cancelled( $subscription, $date ) {
		update_post_meta( $subscription->get_id(), '_schedule_cancelled', $date );
	}

	/**
	 * Sets the subscription's end date.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $date
	 */
	public function set_schedule_end( $subscription, $date ) {
		update_post_meta( $subscription->get_id(), '_schedule_end', $date );
	}

	/**
	 * Sets the subscription's payment retry date.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $date
	 */
	public function set_schedule_payment_retry( $subscription, $date ) {
		update_post_meta( $subscription->get_id(), '_schedule_payment_retry', $date );
	}

	/**
	 * Manually sets the list of subscription's renewal order IDs stored in cache.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param array           $renewal_order_ids
	 */
	public function set_renewal_order_ids_cache( $subscription, $renewal_order_ids ) {
		$this->cleanup_backfill_related_order_cache_duplicates( $subscription, 'renewal' );

		if ( '' !== $renewal_order_ids ) {
			update_post_meta( $subscription->get_id(), '_subscription_renewal_order_ids_cache', $renewal_order_ids );
		}
	}

	/**
	 * Manually sets the list of subscription's resubscribe order IDs stored in cache.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param array           $resubscribe_order_ids
	 */
	public function set_resubscribe_order_ids_cache( $subscription, $resubscribe_order_ids ) {
		$this->cleanup_backfill_related_order_cache_duplicates( $subscription, 'resubscribe' );

		if ( '' !== $resubscribe_order_ids ) {
			update_post_meta( $subscription->get_id(), '_subscription_resubscribe_order_ids_cache', $resubscribe_order_ids );
		}
	}

	/**
	 * Manually sets the list of subscription's switch order IDs stored in cache.
	 *
	 * This method is not intended for public use and is called by @see OrdersTableDataStore::backfill_post_record()
	 * when backfilling subscription data to the WP_Post database.
	 *
	 * @param WC_Subscription $subscription
	 * @param array           $switch_order_ids
	 */
	public function set_switch_order_ids_cache( $subscription, $switch_order_ids ) {
		$this->cleanup_backfill_related_order_cache_duplicates( $subscription, 'switch' );

		if ( '' !== $switch_order_ids ) {
			update_post_meta( $subscription->get_id(), '_subscription_switch_order_ids_cache', $switch_order_ids );
		}
	}

	/**
	 * Deletes a subscription's related order cache - including any duplicates.
	 *
	 * WC core between v8.1 and v8.4 would duplicate related order cache meta when backfilling the post record. This method deletes all
	 * instances of a order type cache (duplicates included). It is intended to be called before setting the cache manually.
	 *
	 * Note: this function assumes that the fix to WC (listed below) will be included in 8.4. If it's pushed back, this function will need to be updated,
	 * if it's brought forward to 8.3, it can be updated but is not strictly required.
	 *
	 * @see https://github.com/woocommerce/woocommerce/pull/41281
	 * @see https://github.com/Automattic/woocommerce-subscriptions-core/pull/538
	 *
	 * @param WC_Subscription $subscription      The Subscription.
	 * @param string          $relationship_type The type of subscription related order relationship to delete. One of: 'renewal', 'resubscribe', 'switch'.
	 */
	private function cleanup_backfill_related_order_cache_duplicates( $subscription, $relationship_type ) {
		// Delete the related order cache on versions of WC after 8.1 but before 8.4.
		if ( ! wcs_is_woocommerce_pre( '8.1' ) && wcs_is_woocommerce_pre( '8.4' ) ) {
			delete_post_meta( $subscription->get_id(), "_subscription_{$relationship_type}_order_ids_cache" );
		}
	}

	/**
	 * Get the data store instance for Order Tables data store.
	 *
	 * @return WCS_Orders_Table_Subscription_Data_Store
	 */
	public function get_cot_data_store_instance() {
		if ( ! isset( $this->orders_table_data_store ) ) {
			$this->orders_table_data_store = new WCS_Orders_Table_Subscription_Data_Store();
		}

		return $this->orders_table_data_store;
	}
}
