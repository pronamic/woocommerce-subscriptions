<?php
/**
 * Base class for creating a scheduler
 *
 * Schedulers are responsible for triggering subscription events/action, like when a payment is due
 * or subscription expires.
 *
 * @class     WCS_Scheduler
 * @version   2.0.0
 * @package   WooCommerce Subscriptions/Abstracts
 * @category  Abstract Class
 * @author    Prospress
 */
abstract class WCS_Scheduler {

	/** @protected array The types of dates which this class should schedule */
	protected $date_types_to_schedule;

	public function __construct() {
		add_action( 'init', array( $this, 'set_date_types_to_schedule' ) );

		add_action( 'woocommerce_subscription_date_updated', array( &$this, 'update_date' ), 10, 3 );

		add_action( 'woocommerce_subscription_date_deleted', array( &$this, 'delete_date' ), 10, 2 );

		add_action( 'woocommerce_subscription_status_updated', array( &$this, 'update_status' ), 10, 3 );
	}

	public function set_date_types_to_schedule() {
		$date_types_to_schedule = wcs_get_subscription_date_types();
		unset( $date_types_to_schedule['start'], $date_types_to_schedule['last_payment'] );

		$this->date_types_to_schedule = apply_filters( 'woocommerce_subscriptions_date_types_to_schedule', array_keys( $date_types_to_schedule ) );
	}

	protected function get_date_types_to_schedule() {
		return $this->date_types_to_schedule;
	}

	/**
	 * When a subscription's date is updated, maybe schedule an event
	 *
	 * @param object $subscription An instance of a WC_Subscription object
	 * @param string $date_type Can be 'trial_end', 'next_payment', 'end', 'end_of_prepaid_term' or a custom date type
	 * @param string $datetime A MySQL formatted date/time string in the GMT/UTC timezone.
	 */
	abstract public function update_date( $subscription, $date_type, $datetime );

	/**
	 * When a subscription's date is deleted, clear it from the scheduler
	 *
	 * @param object $subscription An instance of a WC_Subscription object
	 * @param string $date_type Can be 'trial_end', 'next_payment', 'end', 'end_of_prepaid_term' or a custom date type
	 */
	abstract public function delete_date( $subscription, $date_type );

	/**
	 * When a subscription's status is updated, maybe schedule an event
	 *
	 * @param object $subscription An instance of a WC_Subscription object
	 * @param string $new_status A valid subscription status
	 * @param string $old_status A valid subscription status
	 */
	abstract public function update_status( $subscription, $new_status, $old_status );
}
