<?php
/**
 * Repair subscriptions data to v2.0
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * @deprecated
 */
class WCS_Repair_2_0 {

	/**
	 * Takes care of undefine notices in the upgrade process
	 *
	 * @param  array $order_item item meta
	 * @return array             repaired item meta
	 */
	public static function maybe_repair_order_item( $order_item ) {
		foreach ( array( 'qty', 'tax_class', 'product_id', 'variation_id', 'recurring_line_subtotal', 'recurring_line_total', 'recurring_line_subtotal_tax', 'recurring_line_tax' ) as $key ) {
			if ( ! array_key_exists( $key, $order_item ) ) {
				$order_item[ $key ] = '';
			}
		}

		return $order_item;
	}

	/**
	 * Does sanity check on every subscription, and repairs them as needed
	 *
	 * @param  array $subscription subscription data to be upgraded
	 * @param  integer $item_id      id of order item meta
	 * @return array               a repaired subscription array
	 */
	public static function maybe_repair_subscription( $subscription, $item_id ) {
		global $wpdb;

		$item_meta = get_metadata( 'order_item', $item_id );

		foreach ( self::integrity_check( $subscription ) as $function ) {
			$subscription = call_user_func( 'WCS_Repair_2_0::repair_' . $function, $subscription, $item_id, $item_meta );
		}

		return $subscription;
	}

	/**
	 * Checks for missing data on a subscription
	 *
	 * @param  array $subscription data about the subscription
	 * @return array               a list of repair functions to run on the subscription
	 */
	public static function integrity_check( $subscription ) {
		$repairs_needed = array();

		foreach (
			array(
				'order_id',
				'product_id',
				'variation_id',
				'subscription_key',
				'status',
				'period',
				'interval',
				'length',
				'start_date',
				'trial_expiry_date',
				'expiry_date',
				'end_date',
			) as $meta ) {
				if ( ! array_key_exists( $meta, $subscription ) || '' === $subscription[ $meta ] ) {
					$repairs_needed[] = $meta;
				}
		}

		return $repairs_needed;
	}

	/**
	 * 'order_id': a subscription can exist without an original order in v2.0, so technically the order ID is no longer required.
	 * However, if some or all order item meta data that constitutes a subscription exists without a corresponding parent order,
	 * we can deem the issue to be that the subscription meta data was not deleted, not that the subscription should exist. Meta
	 * data could be orphaned in v1.n if the order row in the wp_posts table was deleted directly in the database, or the
	 * subscription/order were for a customer that was deleted in WordPress administration interface prior to Subscriptions v1.3.8.
	 * In both cases, the subscription, including meta data, should have been permanently deleted. However, deleting data is not a
	 * good idea during an upgrade. So I propose instead that we create a subscription without a parent order, but move it to the trash.
	 *
	 * Additional idea was to check whether the given order_id exists, but since that's another database read, it would slow down a lot of things.
	 *
	 * A subscription will not make it to this point if it doesn't have an order id, so this function will practically never be run
	 *
	 * @param  array $subscription data about the subscription
	 * @return array               repaired data about the subscription
	 */
	public static function repair_order_id( $subscription ) {
		WCS_Upgrade_Logger::add( '-- Repairing order_id for subscription that is missing order id: Status changed to trash' );
		WCS_Upgrade_Logger::add( '-- Shop owner: please review new trashed subscriptions. There is at least one with missing order id.' );

		$subscription['status'] = 'trash';

		return $subscription;
	}

	/**
	 * Combined functionality for the following functions:
	 * - repair_product_id
	 * - repair_variation_id
	 * - repair_recurring_line_total
	 * - repair_recurring_line_tax
	 * - repair_recurring_line_subtotal
	 * - repair_recurring_line_subtotal_tax
	 *
	 * @param  array   $subscription          data about the subscription
	 * @param  numeric $item_id               the id of the product we're missing the id for
	 * @param  array   $item_meta             meta data about the product
	 * @param  string  $item_meta_key         the meta key for the data on the item meta
	 * @param  string  $subscription_meta_key the meta key for the data on the subscription
	 * @return array                          repaired data about the subscription
	 */
	public static function repair_from_item_meta( array $subscription, $item_id, $item_meta, $subscription_meta_key = null, $item_meta_key = null, $default_value = '' ) {
		if ( ! is_array( $subscription ) || ! is_numeric( $item_id ) || ! is_array( $item_meta ) || ! is_string( $subscription_meta_key ) || ! is_string( $item_meta_key ) || ( ! is_string( $default_value ) && ! is_numeric( $default_value ) ) ) {
			return $subscription;
		}

		if ( array_key_exists( $item_meta_key, $item_meta ) && ! empty( $item_meta[ $item_meta_key ] ) ) {
			// only do the copy if the value on item meta is actually different to what the subscription has
			// otherwise it'd be an extra line in the log file for no actual use
			if ( ! array_key_exists( $subscription_meta_key, $subscription ) || $item_meta[ $item_meta_key ][0] != $subscription[ $subscription_meta_key ] ) {
				WCS_Upgrade_Logger::add( sprintf( '-- For order %d: copying %s from item_meta to %s on subscription.', $subscription['order_id'], $item_meta_key, $subscription_meta_key ) );
				$subscription[ $subscription_meta_key ] = $item_meta[ $item_meta_key ][0];
			}
		} elseif ( ! array_key_exists( $item_meta_key, $item_meta ) ) {
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: setting an empty %s on old subscription, item meta was not helpful.', $subscription['order_id'], $subscription_meta_key ) );
			$subscription[ $subscription_meta_key ] = $default_value;
		}

		return $subscription;
	}

	/**
	 * '_product_id': the only way to derive a order item's product ID would be to match the order item's name to a product name/title.
	 * This is quite hacky, so we may be better copying the empty product ID to the new subscription. A subscription to a deleted
	 * produced should be able to exist.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing the id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_product_id( $subscription, $item_id, $item_meta ) {
		return self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'product_id', '_product_id' );
	}

	/**
	 * '_variation_id': the only way to derive a order item's product ID would be to match the order item's name to a product name/title.
	 * This is quite hacky, so we may be better copying the empty product ID to the new subscription. A subscription to a deleted produced
	 * should be able to exist.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_variation_id( $subscription, $item_id, $item_meta ) {
		return self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'variation_id', '_variation_id' );
	}

	/**
	 * If the subscription does not have a subscription key for whatever reason (probably because the product_id was missing), then this one
	 * fills in the blank.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_subscription_key( $subscription, $item_id, $item_meta ) {
		if ( ! is_numeric( $item_id ) ) {
			// because item_id can be either product id or variation id, we can't use
			// item meta to backfill this
			$subscription['subscription_key'] = '';
		} else {
			$subscription['subscription_key'] = $subscription['order_id'] . '_' . $item_id;
		}

		return $subscription;
	}

	/**
	 * '_subscription_status': we could default to cancelled (and then potentially trash) if no status exists because the cancelled status
	 * is irreversible. But we can also take this a step further. If the subscription has a '_subscription_expiry_date' value and a
	 * '_subscription_end_date' value, and they are within a few minutes of each other, we can assume the subscription's status should be
	 * expired. If there is a '_subscription_end_date' value that is different to the '_subscription_expiry_date' value (either because the
	 * expiration value is 0 or some other date), then we can assume the status should be cancelled). If there is no end date value, we're
	 * a bit lost as technically the subscription hasn't ended, but we should make sure it is not active, so cancelled is still the best
	 * default.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_status( $subscription, $item_id, $item_meta ) {
		// only reset this if we didn't repair the order_id
		if ( ! array_key_exists( 'order_id', $subscription ) || empty( $subscription['order_id'] ) ) {
			WCS_Upgrade_Logger::add( '-- Tried to repair status. Previously set it to trash with order_id missing, bailing.' );
			return $subscription;
		}
		WCS_Upgrade_Logger::add( sprintf( '-- For order %d: repairing status for subscription.', $subscription['order_id'] ) );

		// if expiry_date and end_date are within 4 minutes (arbitrary), let it be expired
		if ( array_key_exists( 'expiry_date', $subscription ) && ! empty( $subscription['expiry_date'] ) && array_key_exists( 'end_date', $subscription ) && ! empty( $subscription['end_date'] ) && ( 4 * MINUTE_IN_SECONDS ) >= self::time_diff( $subscription['expiry_date'], $subscription['end_date'] ) ) {
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: there are end dates and expiry dates, they are close to each other, setting status to "expired" and returning.', $subscription['order_id'] ) );
			$subscription['status'] = 'expired';
		} else {
			// default to cancelled
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: setting the default to "cancelled".', $subscription['order_id'] ) );
			$subscription['status'] = 'cancelled';
		}
		self::log_store_owner_review( $subscription );
		WCS_Upgrade_Logger::add( sprintf( '-- For order %d: returning the status with %s', $subscription['order_id'], $subscription['status'] ) );
		return $subscription;
	}

	/**
	 * '_subscription_period': we can attempt to derive this from the time between renewal orders. For example, if there are two renewal
	 * orders found 3 months apart, the billing period would be month. If there are not two or more renewal orders (we can't use a single
	 * renewal order because that would account for the free trial) and a _product_id value , if the product still exists, we can use the
	 * current value set on that product. It won't always be correct, but it's the closest we can get to an accurate estimate.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_period( $subscription, $item_id, $item_meta ) {
		WCS_Upgrade_Logger::add( sprintf( '-- For order %d: repairing period for subscription', $subscription['order_id'] ) );

		// Get info from the product
		$subscription = self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'period', '_subscription_period', '' );

		if ( '' !== $subscription['period'] ) {
			return $subscription;
		}

		// let's get the renewal orders
		$renewal_orders = self::get_renewal_orders( $subscription );

		if ( count( $renewal_orders ) < 2 ) {
			// default to month. Because we're defaulting, we also need to cancel this to avoid charging customers on a schedule they didn't
			// agree to.
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: setting default subscription period to month.', $subscription['order_id'] ) );
			self::log_store_owner_review( $subscription );
			$subscription['period'] = 'month';
			$subscription['status'] = 'cancelled';
			return $subscription;
		}

		// let's get the last 2 renewal orders
		$last_renewal_order       = array_shift( $renewal_orders );
		$last_renewal_date        = wcs_get_datetime_utc_string( wcs_get_objects_property( $last_renewal_order, 'date_created' ) );
		$last_renewal_timestamp   = wcs_date_to_time( $last_renewal_date );

		$second_renewal_order     = array_shift( $renewal_orders );
		$second_renewal_date      = wcs_get_datetime_utc_string( wcs_get_objects_property( $second_renewal_order, 'date_created' ) );
		$second_renewal_timestamp = wcs_date_to_time( $second_renewal_date );

		$interval = 1;

		// if we have an interval, let's pass this along too, because then it's a known variable
		if ( array_key_exists( 'interval', $subscription ) && ! empty( $subscription['interval'] ) ) {
			$interval = $subscription['interval'];
		}

		WCS_Upgrade_Logger::add( sprintf( '-- For order %d: calling wcs_estimate_period_between().', $subscription['order_id'] ) );
		$period = wcs_estimate_period_between( $last_renewal_date, $second_renewal_date, $interval );

		// if we have 3 renewal orders, do a double check
		if ( ! empty( $renewal_orders ) ) {
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: we have 3 renewal orders, trying to make sure we are right.', $subscription['order_id'] ) );

			$third_renewal_order = array_shift( $renewal_orders );
			$third_renewal_date = wcs_get_datetime_utc_string( wcs_get_objects_property( $third_renewal_order, 'date_created' ) );

			$period2 = wcs_estimate_period_between( $second_renewal_date, $third_renewal_date, $interval );

			if ( $period == $period2 ) {
				WCS_Upgrade_Logger::add( sprintf( '-- For order %d: second check confirmed, we are very confident period is %s.', $subscription['order_id'], $period ) );
				$subscription['period'] = $period;
			}
		}

		$subscription['period'] = $period;

		return $subscription;
	}

	/**
	 * '_subscription_interval': we can attempt to derive this from the time between renewal orders. For example, if there are two renewal
	 * orders found 3 months apart, the billing period would be month. If there are not two or more renewal orders (we can't use a single
	 * renewal order because that would account for the free trial) and a _product_id value , if the product still exists, we can use the
	 * current value set on that product. It won't always be correct, but it's the closest we can get to an accurate estimate.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_interval( $subscription, $item_id, $item_meta ) {

		// Get info from the product
		if ( array_key_exists( '_subscription_interval', $item_meta ) && ! empty( $item_meta['_subscription_interval'] ) ) {
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: getting interval from item meta and returning.', $subscription['order_id'] ) );

			$subscription['interval'] = $item_meta['_subscription_interval'][0];
			return $subscription;
		}

		// by this time we already have a period on our hand
		// let's get the renewal orders
		$renewal_orders = self::get_renewal_orders( $subscription );

		if ( count( $renewal_orders ) < 2 ) {
			// default to 1
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: setting default subscription interval to 1.', $subscription['order_id'] ) );
			self::log_store_owner_review( $subscription );
			$subscription['interval'] = 1;
			$subscription['status']   = 'cancelled';
			return $subscription;
		}

		// let's get the last 2 renewal orders
		$last_renewal_order       = array_shift( $renewal_orders );
		$last_renewal_date        = wcs_get_datetime_utc_string( wcs_get_objects_property( $last_renewal_order, 'date_created' ) );
		$last_renewal_timestamp   = wcs_date_to_time( $last_renewal_date );

		$second_renewal_order     = array_shift( $renewal_orders );
		$second_renewal_date      = wcs_get_datetime_utc_string( wcs_get_objects_property( $second_renewal_order, 'date_created' ) );
		$second_renewal_timestamp = wcs_date_to_time( $second_renewal_date );

		$subscription['interval'] = wcs_estimate_periods_between( $second_renewal_timestamp, $last_renewal_timestamp, $subscription['period'] );

		return $subscription;
	}

	/**
	 * '_subscription_length': if there are '_subscription_expiry_date' and '_subscription_start_date' values, we can use those to
	 * determine how many billing periods fall between them, and therefore, the length of the subscription. This data is low value however as
	 * it is no longer stored in v2.0 and mainly used to determine the expiration date.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_length( $subscription, $item_id, $item_meta ) {
		// Let's see if the item meta has that
		$subscription = self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'length', '_subscription_length', '' );

		if ( '' !== $subscription['length'] ) {
			return $subscription;
		}

		$effective_start_date = self::get_effective_start_date( $subscription );

		// If we can calculate it from the effective date and expiry date
		if ( 'expired' == $subscription['status'] && array_key_exists( 'expiry_date', $subscription ) && ! empty( $subscription['expiry_date'] ) && null !== $effective_start_date && array_key_exists( 'period', $subscription ) && ! empty( $subscription['period'] ) && array_key_exists( 'interval', $subscription ) && ! empty( $subscription['interval'] ) ) {
			$intervals = wcs_estimate_periods_between( wcs_date_to_time( $effective_start_date ), wcs_date_to_time( $subscription['expiry_date'] ), $subscription['period'], 'floor' );
			$subscription['length'] = $intervals;
		} else {
			$subscription['length'] = 0;
		}

		return $subscription;
	}

	/**
	 * '_subscription_start_date': the original order's '_paid_date' value (stored in post meta) can be used as the subscription's start date.
	 * If no '_paid_date' exists, because the order used a payment method that doesn't call $order->payment_complete(), like BACs or Cheque,
	 * then we can use the post_date_gmt column in the wp_posts table of the original order.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_start_date( $subscription, $item_id, $item_meta ) {
		global $wpdb;

		$start_date = get_post_meta( $subscription['order_id'], '_paid_date', true );

		WCS_Upgrade_Logger::add( sprintf( 'Repairing start_date for order %d: Trying to use the _paid date for start date.', $subscription['order_id'] ) );

		if ( empty( $start_date ) ) {
			WCS_Upgrade_Logger::add( '-- start_date from _paid date failed. Using post_date_gmt' );

			$start_date = $wpdb->get_var( $wpdb->prepare( "SELECT post_date_gmt FROM {$wpdb->posts} WHERE ID = %d", $subscription['order_id'] ) );
		}

		$subscription['start_date'] = $start_date;
		return $subscription;
	}

	/**
	 * '_subscription_trial_expiry_date': if the subscription has at least one renewal order, we can set the trial expiration date to the date
	 * of the first renewal order. However, this is generally safe to default to 0 if it is not set. Especially if the subscription is
	 * inactive and/or has 1 or more renewals (because its no longer used and is simply for record keeping).
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_trial_expiry_date( $subscription, $item_id, $item_meta ) {
		$subscription['trial_expiry_date'] = self::maybe_get_date_from_action_scheduler( 'scheduled_subscription_trial_end', $subscription );
		return $subscription;
	}

	/**
	 * '_subscription_expiry_date': if the subscription has a '_subscription_length' value, that can be used to calculate the expiration date
	 * (from the '_subscription_start_date' or '_subscription_trial_expiry_date' if one is set). If no length is set, but the subscription has
	 * an expired status, the '_subscription_end_date' can be used. In most other cases, this is generally safe to default to 0 if the
	 * subscription is cancelled because its no longer used and is simply for record keeping.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_expiry_date( $subscription, $item_id, $item_meta ) {
		$subscription['expiry_date'] = self::maybe_get_date_from_action_scheduler( 'scheduled_subscription_expiration', $subscription );
		return $subscription;
	}

	/**
	 * '_subscription_end_date': if the subscription has a '_subscription_length' value and status of expired, the length can be used to
	 * calculate the end date as it will be the same as the expiration date. If no length is set, or the subscription has a cancelled status,
	 * some time within 24 hours after the last renewal order's date can be used to provide a rough estimate.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_end_date( $subscription, $item_id, $item_meta ) {

		$subscription = self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'end_date', '_subscription_end_date', '' );

		if ( '' !== $subscription['end_date'] ) {
			return $subscription;
		}

		if ( 'expired' == $subscription['status'] && array_key_exists( 'expiry_date', $subscription ) && ! empty( $subscription['expiry_date'] ) ) {

			$subscription['end_date'] = $subscription['expiry_date'];

		} elseif ( 'cancelled' == $subscription['status'] || ! array_key_exists( 'length', $subscription ) || empty( $subscription['length'] ) ) {

			// get renewal orders
			$renewal_orders = self::get_renewal_orders( $subscription );
			$last_order = array_shift( $renewal_orders );

			if ( empty( $last_order ) ) {

				$subscription['end_date'] = 0;

			} else {

				$subscription['end_date'] = wcs_add_time( 5, 'hours', wcs_get_objects_property( $last_order, 'date_created' )->getTimestamp() );

			}
		} else {

			// if everything failed, let's have an empty one
			$subscription['end_date'] = 0;

		}

		return $subscription;
	}

	/**
	 * _recurring_line_total': if the subscription has at least one renewal order, this value can be derived from the '_line_total' value of
	 * that order. If no renewal orders exist, it can be derived roughly by deducting the '_subscription_sign_up_fee' value from the original
	 * order's total if there is no trial expiration date.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_recurring_line_total( $subscription, $item_id, $item_meta ) {
		return self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'recurring_line_total', '_line_total', 0 );
	}

	/**
	 * _recurring_line_total': if the subscription has at least one renewal order, this value can be derived from the '_line_total' value
	 * of that order. If no renewal orders exist, it can be derived roughly by deducting the '_subscription_sign_up_fee' value from the
	 * original order's total if there is no trial expiration date.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_recurring_line_tax( $subscription, $item_id, $item_meta ) {
		return self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'recurring_line_tax', '_line_tax', 0 );
	}

	/**
	 * _recurring_line_total': if the subscription has at least one renewal order, this value can be derived from the '_line_total' value of
	 * that order. If no renewal orders exist, it can be derived roughly by deducting the '_subscription_sign_up_fee' value from the original
	 * order's total if there is no trial expiration date
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_recurring_line_subtotal( $subscription, $item_id, $item_meta ) {
		return self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'recurring_line_subtotal', '_line_subtotal', 0 );
	}

	/**
	 * _recurring_line_total': if the subscription has at least one renewal order, this value can be derived from the '_line_total' value of
	 * that order. If no renewal orders exist, it can be derived roughly by deducting the '_subscription_sign_up_fee' value from the original
	 * order's total if there is no trial expiration date.
	 *
	 * @param  array $subscription data about the subscription
	 * @param  numeric $item_id    the id of the product we're missing variation id for
	 * @param  array $item_meta    meta data about the product
	 * @return array               repaired data about the subscription
	 */
	public static function repair_recurring_line_subtotal_tax( $subscription, $item_id, $item_meta ) {
		return self::repair_from_item_meta( $subscription, $item_id, $item_meta, 'recurring_line_subtotal_tax', '_line_subtotal_tax', 0 );
	}

	/**
	 * Utility function to calculate the seconds between two timestamps. Order is not important, it's just the difference.
	 *
	 * @param  string $to   mysql timestamp
	 * @param  string $from mysql timestamp
	 * @return integer       number of seconds between the two
	 */
	private static function time_diff( $to, $from ) {
		$to   = wcs_date_to_time( $to );
		$from = wcs_date_to_time( $from );

		return abs( $to - $from );
	}

	/**
	 * Utility function to get all renewal orders in the old structure.
	 *
	 * @param  array $subscription the sub we're looking for the renewal orders
	 * @return array               of WC_Orders
	 */
	private static function get_renewal_orders( $subscription ) {
		$related_orders = array();

		$related_post_ids = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => 'shop_order',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_parent'    => $subscription['order_id'],
		) );

		foreach ( $related_post_ids as $post_id ) {
			$related_orders[ $post_id ] = wc_get_order( $post_id );
		}

		return $related_orders;
	}

	/**
	 * Utility method to check the action scheduler for dates
	 *
	 * @param  string $type             the type of scheduled action
	 * @param  string $subscription_key key of subscription in the format of order_id_item_id
	 * @return string                   either 0 or mysql date
	 */
	private static function maybe_get_date_from_action_scheduler( $type, $subscription ) {
		$action_args = array(
			'user_id'          => intval( $subscription['user_id'] ),
			'subscription_key' => $subscription['subscription_key'],
		);

		WCS_Upgrade_Logger::add( sprintf( '-- For order %d: Repairing date type "%s" from action scheduler...', $subscription['order_id'], $type ) );
		WCS_Upgrade_Logger::add( '-- This is the arguments: ' . PHP_EOL . print_r( array( $action_args, 'hook' => $type ), true ) . PHP_EOL ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$next_date_timestamp = as_next_scheduled_action( $type, $action_args );

		if ( false === $next_date_timestamp ) {
			// set it to 0 as default
			$formatted_date = 0;
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: Repairing date type "%s": fetch of date unsuccessful: no action present. Date is 0.', $subscription['order_id'], $type ) );
		} else {
			$formatted_date = gmdate( 'Y-m-d H:i:s', $next_date_timestamp );
			WCS_Upgrade_Logger::add( sprintf( '-- For order %d: Repairing date type "%s": fetch of date successful. New date is %s', $subscription['order_id'], $type, $formatted_date ) );
		}

		return $formatted_date;
	}

	/**
	 * Utility function to return the effective start date for interval calculations (end of trial period -> start date -> null )
	 *
	 * @param  array $subscription subscription data
	 * @return mixed               mysql formatted date, or null if none found
	 */
	public static function get_effective_start_date( $subscription ) {

		if ( array_key_exists( 'trial_expiry_date', $subscription ) && ! empty( $subscription['trial_expiry_date'] ) ) {

			$effective_date = $subscription['trial_expiry_date'];

		} elseif ( array_key_exists( 'trial_period', $subscription ) && ! empty( $subscription['trial_period'] ) && array_key_exists( 'trial_length', $subscription ) && ! empty( $subscription['trial_length'] ) && array_key_exists( 'start_date', $subscription ) && ! empty( $subscription['start_date'] ) ) {

			// calculate the end of trial from interval, period and start date
			$effective_date = gmdate( 'Y-m-d H:i:s', wcs_add_time( $subscription['trial_length'], $subscription['trial_period'], wcs_date_to_time( $subscription['start_date'] ) ) );

		} elseif ( array_key_exists( 'start_date', $subscription ) && ! empty( $subscription['start_date'] ) ) {

			$effective_date = $subscription['start_date'];

		} else {

			$effective_date = null;

		}

		return $effective_date;
	}


	/**
	 * Logs an entry for the store owner to review an issue.
	 *
	 * @param array $subscription subscription data
	 */
	protected static function log_store_owner_review( $subscription ) {
		WCS_Upgrade_Logger::add( sprintf( '-- For order %d: shop owner please review subscription.', $subscription['order_id'] ) );
	}
}
