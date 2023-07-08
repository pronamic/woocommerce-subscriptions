<?php
/**
 * Privacy Background Updater.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions\Privacy
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Privacy_Background_Updater {

	/**
	 * @var string The hook used to schedule subscription anonymization.
	 */
	protected $ended_subscription_anonymization_hook = 'woocommerce_subscriptions_privacy_anonymize_ended_subscriptions';

	/**
	 * @var string The hook used to schedule subscription related order anonymization.
	 */
	protected $subscription_orders_anonymization_hook = 'woocommerce_subscriptions_privacy_anonymize_subscription_orders';

	/**
	 * @var string The hook used to schedule individual order anonymization.
	 */
	protected $order_anonymization_hook = 'woocommerce_subscriptions_privacy_anonymize_subscription_order';

	/**
	 * Attach callbacks.
	 */
	public function init() {
		add_action( $this->ended_subscription_anonymization_hook, array( $this, 'anonymize_ended_subscriptions' ) );
		add_action( $this->subscription_orders_anonymization_hook, array( $this, 'schedule_subscription_orders_anonymization_events' ), 10, 1 );
		add_action( $this->order_anonymization_hook, array( $this, 'anonymize_order' ), 10, 1 );
	}

	/**
	 * Schedule ended subscription anonymization, if it's not already scheduled.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public function schedule_ended_subscription_anonymization() {
		if ( false === as_next_scheduled_action( $this->ended_subscription_anonymization_hook ) ) {
			as_schedule_single_action( time(), $this->ended_subscription_anonymization_hook );
		}
	}

	/**
	 * Unschedule the ended subscription anonymization hook.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	protected function unschedule_ended_subscription_anonymization() {
		as_unschedule_action( $this->ended_subscription_anonymization_hook );
	}

	/**
	 * Schedule subscription related order anonymization, if it's not already scheduled.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param int The subscription ID.
	 */
	protected function schedule_subscription_orders_anonymization( $subscription_id ) {
		$action_args = array( 'subscription_id' => intval( $subscription_id ) );

		if ( false === as_next_scheduled_action( $this->subscription_orders_anonymization_hook, $action_args ) ) {
			as_schedule_single_action( time(), $this->subscription_orders_anonymization_hook, $action_args );
		}
	}

	/**
	 * Unschedule a specific subscription's related order anonymization hook.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param int The subscription ID.
	 */
	protected function unschedule_subscription_orders_anonymization( $subscription_id ) {
		as_unschedule_action( $this->subscription_orders_anonymization_hook, array( 'subscription_id' => intval( $subscription_id ) ) );
	}

	/**
	 * Schedule a specific order's anonymization action.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param int The order ID.
	 */
	protected function schedule_order_anonymization( $order_id ) {
		as_schedule_single_action( time(), $this->order_anonymization_hook, array( 'order_id' => intval( $order_id ) ) );
	}

	/**
	 * Check if an order has a scheduled anonymization action.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param int The order ID.
	 * @return bool Wether the order has a scheduled anonymization action.
	 */
	protected function order_anonymization_is_scheduled( $order_id ) {
		return false !== as_next_scheduled_action( $this->order_anonymization_hook, array( 'order_id' => intval( $order_id ) ) );
	}

	/**
	 * Anonymize old ended subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public function anonymize_ended_subscriptions() {
		$option = wc_parse_relative_date_option( get_option( 'woocommerce_anonymize_ended_subscriptions' ) );

		if ( empty( $option['number'] ) ) {
			return;
		}

		// Reschedule the cleanup now just in case something goes wrong.
		$this->schedule_ended_subscription_anonymization();

		$batch_size = 20;

		// Get the ended_statuses and removes pending-cancel.
		$subscription_ended_statuses = array_diff( wcs_get_subscription_ended_statuses(), array( 'pending-cancel' ) );

		$subscriptions = wcs_get_subscriptions( array(
			'subscriptions_per_page' => $batch_size,
			'subscription_status'    => $subscription_ended_statuses,
			'meta_query'             => array(
				array(
					'key'     => '_schedule_end',
					'compare' => '<',
					'value'   => gmdate( 'Y-m-d H:i:s', wc_string_to_timestamp( '-' . $option['number'] . ' ' . $option['unit'] ) ),
					'type'    => 'DATETIME',
				),
				array(
					'key'     => '_anonymized',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		foreach ( $subscriptions as $subscription ) {
			if ( $subscription ) {
				$this->schedule_subscription_orders_anonymization( $subscription->get_id() );
				WCS_Privacy_Erasers::remove_subscription_personal_data( $subscription );
			}
		}

		// If we haven't processed a full batch, we don't have any more subscriptions to process so there's no need to run any other batches.
		if ( count( $subscriptions ) !== $batch_size ) {
			$this->unschedule_ended_subscription_anonymization();
		}
	}

	/**
	 * Schedule related order anonymization events for a specific subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public function schedule_subscription_orders_anonymization_events( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return;
		}

		// Reschedule the cleanup just in case something goes wrong.
		$this->schedule_subscription_orders_anonymization( $subscription_id );

		$related_orders = $subscription->get_related_orders( 'ids', array( 'parent', 'renewal', 'switch' ) );
		$count          = 0;
		$limit          = 20;

		foreach ( $related_orders as $order_id ) {

			// If this order already has an anonymization event scheduled, there's no need to proceed.
			if ( $this->order_anonymization_is_scheduled( $order_id ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( ! is_a( $order, 'WC_Abstract_Order' ) || 'yes' === $order->get_meta( '_anonymized', true ) ) {
				continue;
			}

			// We want to prevent orders being anonymized if there is a related subscription which hasn't been anonymized.
			foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
				if ( 'yes' !== $subscription->get_meta( '_anonymized', true ) ) {
					continue 2;
				}
			}

			$this->schedule_order_anonymization( $order_id );
			$count++;

			if ( $count >= $limit ) {
				break;
			}
		}

		// If we haven't processed a full batch, we don't have any more related orders to process so there's no need to run any other batches.
		if ( $limit > $count ) {
			$this->unschedule_subscription_orders_anonymization( $subscription_id );
		}
	}

	/**
	 * Anonymize an order.
	 *
	 * @param int The ID of the order to be anonymized.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public function anonymize_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			WC_Privacy_Erasers::remove_order_personal_data( $order );
		}
	}
}
