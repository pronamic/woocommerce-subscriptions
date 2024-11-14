<?php
/**
 * Update Subscriptions to 1.4.0
 *
 * Version 1.4 moved subscription meta out of usermeta and into the new WC2.0 order item meta table.
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * @deprecated subscriptions-core 7.7.0
 */
class WCS_Upgrade_1_4 {

	private static $last_upgraded_user_id = false;

	public static function init() {
		wcs_deprecated_function( __METHOD__, 'subscriptions-core 7.7.0' );
		global $wpdb;

		$subscriptions_meta_key = $wpdb->get_blog_prefix() . 'woocommerce_subscriptions';
		$order_items_table      = $wpdb->get_blog_prefix() . 'woocommerce_order_items';
		$order_item_meta_table  = $wpdb->get_blog_prefix() . 'woocommerce_order_itemmeta';

		// Get the IDs of all users who have a subscription
		$users_to_upgrade = get_users(
			array(
				'meta_key' => $subscriptions_meta_key,
				'fields'   => 'ID',
				'orderby'  => 'ID',
			)
		);

		$users_to_upgrade = array_filter( $users_to_upgrade, __CLASS__ . '::is_user_upgraded' );

		foreach ( $users_to_upgrade as $user_to_upgrade ) {

			// Can't use WC_Subscriptions_Manager::get_users_subscriptions() because it relies on the new structure
			$users_old_subscriptions = get_user_option( $subscriptions_meta_key, $user_to_upgrade );

			foreach ( $users_old_subscriptions as $subscription_key => $subscription ) {

				if ( ! isset( $subscription['order_id'] ) ) { // Subscription created incorrectly with v1.1.2
					continue;
				}

				$order_item_id = WC_Subscriptions_Order::get_item_id_by_subscription_key( $subscription_key );

				if ( empty( $order_item_id ) ) { // Subscription created incorrectly with v1.1.2
					continue;
				}

				if ( ! isset( $subscription['trial_expiry_date'] ) ) {
					$subscription['trial_expiry_date'] = '';
				}

				// Set defaults
				$failed_payments    = isset( $subscription['failed_payments'] ) ? $subscription['failed_payments'] : 0;
				$completed_payments = isset( $subscription['completed_payments'] ) ? $subscription['completed_payments'] : array();
				$suspension_count   = isset( $subscription['suspension_count'] ) ? $subscription['suspension_count'] : 0;
				$trial_expiry_date  = isset( $subscription['trial_expiry_date'] ) ? $subscription['trial_expiry_date'] : '';

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO $order_item_meta_table (order_item_id, meta_key, meta_value)
						VALUES
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s)",
						$order_item_id, '_subscription_status', $subscription['status'],
						$order_item_id, '_subscription_start_date', $subscription['start_date'],
						$order_item_id, '_subscription_expiry_date', $subscription['expiry_date'],
						$order_item_id, '_subscription_end_date', $subscription['end_date'],
						$order_item_id, '_subscription_trial_expiry_date', $trial_expiry_date,
						$order_item_id, '_subscription_failed_payments', $failed_payments,
						$order_item_id, '_subscription_completed_payments', serialize( $completed_payments ),
						$order_item_id, '_subscription_suspension_count', $suspension_count
					)
				);

			}

			update_option( 'wcs_1_4_last_upgraded_user_id', $user_to_upgrade );
			self::$last_upgraded_user_id = $user_to_upgrade;

		}

		// Add an underscore prefix to usermeta key to deprecate, but not delete, subscriptions in user meta
		$wpdb->update(
			$wpdb->usermeta,
			array( 'meta_key' => '_' . $subscriptions_meta_key ),
			array( 'meta_key' => $subscriptions_meta_key )
		);

		// Now set the recurring shipping & payment method on all subscription orders
		$wpdb->query(
			"INSERT INTO $wpdb->postmeta (`post_id`, `meta_key`, `meta_value`)
			SELECT `post_id`, CONCAT('_recurring',`meta_key`), `meta_value`
			FROM $wpdb->postmeta
			WHERE `meta_key` IN ('_shipping_method','_shipping_method_title','_payment_method','_payment_method_title')
			AND `post_id` IN (
				SELECT `post_id` FROM $wpdb->postmeta WHERE `meta_key` = '_order_recurring_total'
			)"
		);

		// Set the recurring shipping total on all subscription orders
		$wpdb->query(
			"INSERT INTO $wpdb->postmeta (`post_id`, `meta_key`, `meta_value`)
			SELECT `post_id`, '_order_recurring_shipping_total', `meta_value`
			FROM $wpdb->postmeta
			WHERE `meta_key` = '_order_shipping'
			AND `post_id` IN (
				SELECT `post_id` FROM $wpdb->postmeta WHERE `meta_key` = '_order_recurring_total'
			)"
		);

		// Get the ID of all orders for a subscription with a free trial and no sign-up fee
		$order_ids = $wpdb->get_col(
			"SELECT order_items.order_id FROM $order_items_table AS order_items
				LEFT JOIN $order_item_meta_table AS itemmeta USING (order_item_id)
				LEFT JOIN $order_item_meta_table AS itemmeta2 USING (order_item_id)
			WHERE itemmeta.meta_key = '_subscription_trial_length'
			AND itemmeta.meta_value > 0
			AND itemmeta2.meta_key = '_subscription_sign_up_fee'
			AND itemmeta2.meta_value > 0"
		);

		$order_ids = implode( ',', array_map( 'absint', array_unique( $order_ids ) ) );

		// Now set the order totals to $0 (can't use $wpdb->update as it only allows joining WHERE clauses with AND)
		if ( ! empty( $order_ids ) ) {
			$wpdb->query(
				"UPDATE $wpdb->postmeta
				SET `meta_value` = 0
				WHERE `meta_key` IN ( '_order_total', '_order_tax', '_order_shipping_tax', '_order_shipping', '_order_discount', '_cart_discount' )
				AND `post_id` IN ( $order_ids )"
			);

			// Now set the line totals to $0
			$wpdb->query(
				"UPDATE $order_item_meta_table
				 SET `meta_value` = 0
				 WHERE `meta_key` IN ( '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', 'tax_amount', 'shipping_tax_amount' )
				 AND `order_item_id` IN (
					SELECT `order_item_id` FROM $order_items_table
					WHERE `order_item_type` IN ('tax','line_item')
					AND `order_id` IN ( $order_ids )
				)"
			);
		}

		update_option( 'wcs_1_4_upgraded_order_ids', explode( ',', $order_ids ) );
	}

	/**
	 * Used to check if a user ID is greater than the last user upgraded to version 1.4.
	 *
	 * Needs to be a separate function so that it can use a static variable (and therefore avoid calling get_option() thousands
	 * of times when iterating over thousands of users).
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4
	 * @deprecated subscriptions-core 7.7.0
	 */
	public static function is_user_upgraded( $user_id ) {
		wcs_deprecated_function( __METHOD__, 'subscriptions-core 7.7.0' );
		if ( false === self::$last_upgraded_user_id ) {
			self::$last_upgraded_user_id = get_option( 'wcs_1_4_last_upgraded_user_id', 0 );
		}

		return $user_id > self::$last_upgraded_user_id;
	}
}
