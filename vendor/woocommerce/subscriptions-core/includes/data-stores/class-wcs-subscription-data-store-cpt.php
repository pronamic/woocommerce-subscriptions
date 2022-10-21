<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscription Data Store: Stored in CPT.
 *
 * Extends WC_Order_Data_Store_CPT to make sure subscription related meta data is read/updated.
 *
 * @version  2.2.0
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
	 * @since 2.2.0
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
	 * @since 2.2.0
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
	 * @since 2.2.0
	 */
	public function create( &$subscription ) {
		parent::create( $subscription );
		do_action( 'woocommerce_new_subscription', $subscription->get_id() );
	}

	/**
	 * Returns an array of meta for an object.
	 *
	 * Ignore meta data that we don't want accessible on the object via meta APIs.
	 *
	 * @since  2.3.0
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
	 * @since 2.2.0
	 */
	protected function read_order_data( &$subscription, $post_object ) {

		// Set all order meta data, as well as data defined by WC_Subscription::$extra_keys which has corresponding setter methods
		parent::read_order_data( $subscription, $post_object );

		$props_to_set = $dates_to_set = array();

		foreach ( $this->subscription_meta_keys_to_props as $meta_key => $prop_key ) {
			if ( 0 === strpos( $prop_key, 'schedule' ) || in_array( $meta_key, $this->subscription_internal_meta_keys ) ) {

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
			$props_to_set['customer_id'] = get_post_meta( $subscription->get_id(), '_customer_user', true );
		}

		$subscription->update_dates( $dates_to_set );
		$subscription->set_props( $props_to_set );
	}

	/**
	 * Update subscription in the database.
	 *
	 * @param WC_Subscription $subscription
	 * @since 2.2.0
	 */
	public function update( &$subscription ) {

		// We don't want to call parent here becuase WC_Order_Data_Store_CPT includes a JIT setting of the paid date which is not needed for subscriptions, and also very resource intensive
		Abstract_WC_Order_Data_Store_CPT::update( $subscription );

		// We used to call parent::update() above, which triggered this hook, so we trigger it manually here for backward compatibilty (and to improve compatibility with 3rd party code which may run validation or additional operations on it which should also be applied to a subscription)
		do_action( 'woocommerce_update_order', $subscription->get_id() );

		do_action( 'woocommerce_update_subscription', $subscription->get_id() );
	}

	/**
	 * Update post meta for a subscription based on it's settings in the WC_Subscription class.
	 *
	 * @param WC_Subscription $subscription
	 * @since 2.2.0
	 */
	protected function update_post_meta( &$subscription ) {

		$updated_props = array();

		foreach ( $this->get_props_to_update( $subscription, $this->subscription_meta_keys_to_props ) as $meta_key => $prop ) {
			$meta_value = ( 'schedule_' == substr( $prop, 0, 9 ) ) ? $subscription->get_date( $prop ) : $subscription->{"get_$prop"}( 'edit' );

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
	 * Get amount refunded for all related orders.
	 *
	 * @param WC_Subscription $subscription
	 * @return string
	 * @since 2.2.0
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
	 * @since 2.2.0
	 */
	public function get_total_tax_refunded( $subscription ) {

		$total = 0;

		foreach ( $subscription->get_related_orders() as $order ) {
			$total += parent::get_total_tax_refunded( $order );
		}

		return abs( $total );
	}

	/**
	 * Get the total shipping refunded for all related orders.
	 *
	 * @param WC_Subscription $subscription
	 * @return float
	 * @since 2.2.0
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
	 * @since 2.2.0
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
	 * @since 2.2.0
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
	 * @since 2.2.6
	 */
	public function save_dates( $subscription ) {
		$saved_dates    = array();
		$changes        = $subscription->get_changes();
		$date_meta_keys = array(
			'_schedule_trial_end',
			'_schedule_next_payment',
			'_schedule_cancelled',
			'_schedule_end',
			'_schedule_payment_retry',
			'_schedule_start',
		);

		// Add any custom date types to the date meta keys we need to save.
		foreach ( wcs_get_subscription_date_types() as $date_type => $date_name ) {
			if ( 'last_payment' === $date_type ) {
				continue;
			}

			$date_meta_key = wcs_get_date_meta_key( $date_type );

			if ( ! in_array( $date_meta_key, $date_meta_keys ) ) {
				$date_meta_keys[] = $date_meta_key;
			}
		}

		$date_meta_keys_to_props = array_intersect_key( $this->subscription_meta_keys_to_props, array_flip( $date_meta_keys ) );

		// Save the changes to scheduled dates
		foreach ( $this->get_props_to_update( $subscription, $date_meta_keys_to_props ) as $meta_key => $prop ) {
			update_post_meta( $subscription->get_id(), $meta_key, $subscription->get_date( $prop ) );
			$saved_dates[ $prop ] = wcs_get_datetime_from( $subscription->get_time( $prop ) );
		}

		$post_data = array();

		// Save any changes to the created date
		if ( isset( $changes['date_created'] ) ) {
			$post_data['post_date']      = gmdate( 'Y-m-d H:i:s', $subscription->get_date_created( 'edit' )->getOffsetTimestamp() );
			$post_data['post_date_gmt']  = gmdate( 'Y-m-d H:i:s', $subscription->get_date_created( 'edit' )->getTimestamp() );
			$saved_dates['date_created'] = $subscription->get_date_created();
		}

		// Save any changes to the modified date
		if ( isset( $changes['date_modified'] ) ) {
			$post_data['post_modified']     = gmdate( 'Y-m-d H:i:s', $subscription->get_date_modified( 'edit' )->getOffsetTimestamp() );
			$post_data['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', $subscription->get_date_modified( 'edit' )->getTimestamp() );
			$saved_dates['date_modified']   = $subscription->get_date_modified();
		}

		if ( ! empty( $post_data ) ) {
			$post_data['ID'] = $subscription->get_id();
			wp_update_post( $post_data );
		}

		return $saved_dates;
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
	 * @since 2.3.0
	 */
	public function search_subscriptions( $term ) {
		global $wpdb;

		$subscription_ids = array();

		$search_fields = array_map( 'wc_clean', apply_filters( 'woocommerce_shop_subscription_search_fields', array(
			'_order_key',
			'_billing_address_index',
			'_shipping_address_index',
			'_billing_email',
		) ) );

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
	 * @since 3.4.3
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
}
