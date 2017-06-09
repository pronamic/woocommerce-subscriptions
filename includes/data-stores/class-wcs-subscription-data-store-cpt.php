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

		'_subscription_switch_data' => 'switch_data',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
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
			if ( 0 === strpos( $prop_key, 'schedule' ) || in_array( $meta_key, $this->subscription_internal_meta_keys )  ) {

				$meta_value = get_post_meta( $subscription->get_id(), $meta_key, true );

				// Dates are set via update_dates() to make sure relationships between dates are validated
				if ( 0 === strpos( $prop_key, 'schedule' ) ) {
					$date_type = str_replace( 'schedule_', '', $prop_key );
					$dates_to_set[ $date_type ] = ( false == $meta_value ) ? 0 : $meta_value;
				} else {
					$props_to_set[ $prop_key ] = $meta_value;
				}
			}
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
		parent::update( $subscription );
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

		$subscriptions = parent::get_orders( $parent_args );

		if ( $args['paginate'] ) {

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
		);

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
}
