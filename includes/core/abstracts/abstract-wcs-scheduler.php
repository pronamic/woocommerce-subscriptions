<?php
/**
 * Base class for creating a scheduler
 *
 * Schedulers are responsible for triggering subscription events/action, like when a payment is due
 * or subscription expires.
 *
 * @class     WCS_Scheduler
 * @version   1.0.0 - Migrated from WooCommerce Subscriptions v2.0.0
 * @package   WooCommerce Subscriptions/Abstracts
 * @category  Abstract Class
 * @author    Prospress
 */
abstract class WCS_Scheduler {
	/**
	 * Reason codes recognised by log_unscheduled_subscription_event().
	 *
	 * Each value is written to the log context as the 'reason' field, so changing a value changes what
	 * anyone grepping the failed-scheduled-actions log has to search for.
	 *
	 * @internal Not part of the public API. The set of recognised codes, and their values, may change at any time.
	 * @since 9.2.0
	 */
	public const REASON_NO_ACTION_ID = 'action_scheduler_returned_no_id';
	public const REASON_DATE_IN_PAST = 'requested_date_in_past';

	/**
	 * How each recognised reason code is reported: its log level, and the explanation added to the message.
	 *
	 * Action Scheduler refusing to store the action is a fault; a date we were given that has already passed
	 * may well be deliberate, so it is reported as a warning rather than an error.
	 *
	 * @internal
	 * @since 9.2.0
	 */
	private const REASON_REPORTING = array(
		self::REASON_NO_ACTION_ID => array(
			'level'       => 'error',
			'explanation' => 'Action Scheduler returned no action ID, so the event could not be stored.',
		),
		self::REASON_DATE_IN_PAST => array(
			'level'       => 'warning',
			'explanation' => 'The date requested had already passed, so the existing action was cleared and not replaced.',
		),
	);

	/**
	 * How an unrecognised reason code is reported.
	 *
	 * Not keyed by any reason code: this is what a caller passing something outside REASON_REPORTING gets, so
	 * that the event is still recorded even when we cannot say why it was not scheduled.
	 *
	 * @internal
	 * @since 9.2.0
	 */
	private const REASON_REPORTING_DEFAULT = array(
		'level'       => 'error',
		'explanation' => 'The reason was not recorded.',
	);

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

	/**
	 * Record that a subscription event we were asked to schedule was not scheduled.
	 *
	 * Both schedulers clear a subscription's existing action before scheduling its replacement, so when the
	 * replacement does not happen the subscription is left holding a date with nothing behind it. Action Scheduler
	 * does write something of its own in the store-failure case - a raw error_log() line from
	 * ActionScheduler_ActionFactory::create(), or a _doing_it_wrong() notice when it is not yet initialised - but
	 * neither names the subscription, and neither reaches the WooCommerce logs where a merchant or support engineer
	 * would look. This entry is what makes the lost event attributable.
	 *
	 * The log source matches WCS_Failed_Scheduled_Action_Manager (see its log() method, which uses the same
	 * 'failed-scheduled-actions' string), so a failure to schedule a subscription event and a failure to run one
	 * land in the same log file. Keep the two in step.
	 *
	 * Logging is strictly a side effect here: it must never interfere with scheduling, so every failure inside this
	 * method is swallowed. This code runs in cron and other background contexts where an uncaught error would abort
	 * the remainder of the scheduling run.
	 *
	 * @internal Not part of the public API. Provided for the schedulers in this plugin only; the signature and the
	 *           recognised reason codes may change at any time.
	 * @since 9.2.0
	 *
	 * @param string $reason_code Why the event was not scheduled. One of the self::REASON_* constants. An
	 *                            unrecognised code is still logged, but without an explanation.
	 * @param string $action_hook Name of the event used as the hook for the scheduled action.
	 * @param array  $action_args Array of name => value pairs stored against the scheduled action.
	 * @param int    $timestamp   Unix timestamp the action was intended to run at.
	 * @return void
	 */
	protected function log_unscheduled_subscription_event( $reason_code, $action_hook, $action_args, $timestamp ) {
		try {
			$reason      = self::REASON_REPORTING[ $reason_code ] ?? self::REASON_REPORTING_DEFAULT;
			$action_args = is_array( $action_args ) ? $action_args : array();
			$timestamp   = is_numeric( $timestamp ) ? (int) $timestamp : 0;

			// The action args are filterable, and the payment retry hook stores an order rather than a
			// subscription, so identify the subject from whatever we actually have. Note that get_last_order()
			// returns false when a subscription has no renewal order, so test the value, not just the key.
			if ( ! empty( $action_args['subscription_id'] ) && is_numeric( $action_args['subscription_id'] ) ) {
				$subject = sprintf( 'subscription %1$d', absint( $action_args['subscription_id'] ) );
			} elseif ( ! empty( $action_args['order_id'] ) && is_numeric( $action_args['order_id'] ) ) {
				$subject = sprintf( 'order %1$d', absint( $action_args['order_id'] ) );
			} else {
				$subject = 'an unidentified subscription';
			}

			// Built before the logger is touched, so that the fallback below still has something to report if
			// obtaining or calling the logger is what fails.
			$message = sprintf(
				'Subscriptions did not schedule the %1$s event for %2$s. %3$s Nothing will run at the intended time unless the event is scheduled again. Intended run time: %4$s UTC (timestamp %5$d).',
				$action_hook,
				$subject,
				$reason['explanation'],
				gmdate( 'Y-m-d H:i:s', $timestamp ),
				$timestamp
			);

			wc_get_logger()->log(
				$reason['level'],
				$message,
				array(
					'source'      => 'failed-scheduled-actions',
					'reason'      => $reason_code,
					'action_hook' => $action_hook,
					'action_args' => $action_args,
					'timestamp'   => $timestamp,
				)
			);
		} catch ( Throwable $throwable ) {
			// Deliberately swallowed. A logger that is unavailable or that throws must not stop us scheduling.
			if ( isset( $message ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, QITStandard.PHP.DebugCode.DebugFunctionFound -- The WooCommerce logger is what just failed.
				error_log( 'WooCommerce Subscriptions: ' . $message . ' (could not be written to the WooCommerce logs: ' . $throwable->getMessage() . ')' );
			}

			return;
		}
	}
}
