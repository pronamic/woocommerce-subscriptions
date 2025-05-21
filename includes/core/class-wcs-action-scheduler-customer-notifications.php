<?php

/**
 * Scheduler for subscription notifications that uses the Action Scheduler
 *
 * @class     WCS_Action_Scheduler_Customer_Notifications
 * @version   7.7.0
 * @package   WooCommerce Subscriptions/Classes
 * @category  Class
 */
class WCS_Action_Scheduler_Customer_Notifications extends WCS_Scheduler {

	/**
	 * @var int Time offset (in whole seconds) between the notification and the action it's notifying about.
	 */
	protected $time_offset;

	/**
	 * @var array|string[] Notifications scheduled by this class.
	 *
	 * Just for reference.
	 */
	protected static $notification_actions = [
		'woocommerce_scheduled_subscription_customer_notification_trial_expiration',
		'woocommerce_scheduled_subscription_customer_notification_expiration',
		'woocommerce_scheduled_subscription_customer_notification_renewal',
	];

	/**
	 * Name of Action Scheduler group used for customer notification actions.
	 *
	 * @var string
	 */
	protected static $notifications_as_group = 'wcs_customer_notifications';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$setting_option = get_option(
			WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string,
			[
				'number' => 3,
				'unit'   => 'days',
			]
		);
		$this->set_time_offset( self::convert_offset_to_seconds( $setting_option ) );

		add_action( 'woocommerce_before_subscription_object_save', [ $this, 'update_notifications' ], 10, 2 );

		add_action( 'update_option_' . WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string, [ $this, 'set_time_offset_from_option' ], 5, 3 );
		add_action( 'add_option_' . WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string, [ $this, 'set_time_offset_from_option' ], 5, 2 );
	}

	/**
	 * Check if the subscription period is too short to send a renewal notification.
	 *
	 * @param $subscription
	 *
	 * @return bool
	 */
	public static function is_subscription_period_too_short( $subscription ) {
		$period   = $subscription->get_billing_period();
		$interval = $subscription->get_billing_interval();

		// By default, there are no shorter periods than days in WCS, so we ignore hours, minutes, etc.
		if ( $interval <= 2 && 'day' === $period ) {
			return true;
		}

		return false;
	}

	/**
	 * Return time offset for notifications for given subscription.
	 *
	 * Generally, there is one offset for all subscriptions, but there's a filter.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $notification_type
	 *
	 * @return mixed|null
	 */
	public function get_time_offset( $subscription, $notification_type ) {
		/**
		 * Offset between a subscription event and related notification.
		 *
		 * @since 7.7.0
		 *
		 * @param int $time_offset In seconds
		 * @param WC_Subscription $subscription
		 * @param string $notification_type Can be 'trial_end', 'next_payment' or 'end'.
		 *
		 * @return int
		 */
		return apply_filters( 'woocommerce_subscription_customer_notification_time_offset', $this->time_offset, $subscription, $notification_type );
	}

	/**
	 * General time offset setter.
	 *
	 * @param int $time_offset In seconds
	 *
	 * @return void
	 */
	public function set_time_offset( $time_offset ) {
		$this->time_offset = $time_offset;
	}

	/**
	 * Set the offset based on new value set in the option.
	 *
	 * @param $_ Unused parameter.
	 * @param $new_option_value
	 *
	 * @return void
	 */
	public function set_time_offset_from_option( $_, $new_option_value ) {
		$this->time_offset = self::convert_offset_to_seconds( $new_option_value );
	}

	/**
	 * Calculate time offset in seconds from the settings array.
	 *
	 * @param array $offset Format: [ 'number' => 3, 'unit' => 'days' ]
	 *
	 * @return int
	 */
	protected static function convert_offset_to_seconds( $offset ) {
		$default_offset = 3 * DAY_IN_SECONDS;

		if ( ! isset( $offset['unit'] ) || ! isset( $offset['number'] ) ) {
			return $default_offset;
		}

		switch ( $offset['unit'] ) {
			case 'days':
				return ( $offset['number'] * DAY_IN_SECONDS );
			case 'weeks':
				return ( $offset['number'] * WEEK_IN_SECONDS );
			case 'months':
				return ( $offset['number'] * MONTH_IN_SECONDS );
			case 'years':
				return ( $offset['number'] * YEAR_IN_SECONDS );
			default:
				return $default_offset;
		}
	}

	/**
	 * Maybe schedule a notification action for given subscription and timestamp.
	 *
	 * Will *not* schedule notification if:
	 *  - the notifications are globally disabled,
	 *  - the subscription isn't active/pending-cancel,
	 *  - the subscription's billing cycle is less than 3 days,
	 *  - there is already the same action scheduled for the same subscription and time.
	 *
	 * If only the time differs, the previous scheduled action will be unscheduled and a new one will replace it.
	 *
	 * @param WC_Subscription $subscription Subscription to schedule the action for.
	 * @param string $action Action ID to schedule.
	 * @param int $timestamp Time to schedule the notification for.
	 *
	 * @return void
	 */
	protected function maybe_schedule_notification( $subscription, $action, $timestamp ) {
		if ( ! WC_Subscriptions_Email_Notifications::notifications_globally_enabled() ) {
			return;
		}

		if ( ! $subscription->has_status( [ 'active', 'pending-cancel' ] ) ) {
			return;
		}

		if ( self::is_subscription_period_too_short( $subscription ) ) {
			return;
		}

		$action_args = self::get_action_args( $subscription );

		$next_scheduled = as_next_scheduled_action( $action, $action_args, self::$notifications_as_group );

		if ( $timestamp === $next_scheduled ) {
			return;
		}

		$this->unschedule_actions( $action, $action_args );

		// Only reschedule if it's in the future
		if ( $timestamp <= time() ) {
			return;
		}

		as_schedule_single_action( $timestamp, $action, $action_args, self::$notifications_as_group );
	}

	/**
	 * Subtract time offset from given datetime based on the settings and subscription properties and return resulting timestamp.
	 *
	 * @param string $datetime
	 * @param WC_Subscription $subscription
	 * @param string $notification_type Can be 'trial_end', 'next_payment' or 'end'.
	 *
	 * @return int
	 */
	protected function subtract_time_offset( $datetime, $subscription, $notification_type ) {
		$dt = new DateTime( $datetime, new DateTimeZone( 'UTC' ) );

		return $dt->getTimestamp() - $this->get_time_offset( $subscription, $notification_type );
	}

	/**
	 * Get the notification action name based on the date type.
	 *
	 * @param string $date_type
	 *
	 * @return string
	 */
	public static function get_action_from_date_type( $date_type ) {
		$action = '';

		switch ( $date_type ) {
			case 'trial_end':
				$action = 'woocommerce_scheduled_subscription_customer_notification_trial_expiration';
				break;
			case 'next_payment':
				$action = 'woocommerce_scheduled_subscription_customer_notification_renewal';
				break;
			case 'end':
				$action = 'woocommerce_scheduled_subscription_customer_notification_expiration';
				break;
		}

		return $action;
	}

	/**
	 * Update notifications when subscription gets updated.
	 *
	 * To make batch processing easier, we need to handle the following use case:
	 * 1. Subscription S1 gets updated.
	 * 2. Notification config gets updated, a batch to fix all subscriptions is started and processes all subscriptions
	 *    with update time before the config got updated.
	 * 3. Subscription S1 gets updated before it gets processed by the batch process.
	 *
	 * Thus, we update notifications for all subscriptions that are being updated after notification config change time
	 * and which have their update time before that.
	 *
	 * As this gets called on Subscription save, the modification timestamp should be updated, too, and thus
	 * the currently updated subscription no longer needs to be processed by the batch process.
	 *
	 * @param WC_Subscription $subscription
	 * @param $subscription_data_store
	 *
	 * @return void
	 */
	public function update_notifications( $subscription, $subscription_data_store ) {
		if ( ! $subscription->has_status( 'active' ) && ! $subscription->has_status( 'pending-cancel' ) ) {
			return;
		}

		// Here, we need the 'old' update timestamp for comparison, so can't use get_date_modified() method.
		$subscription_update_time_raw = array_key_exists( 'date_modified', $subscription->get_data() ) ? $subscription->get_data()['date_modified'] : $subscription->get_date_created();
		if ( ! $subscription_update_time_raw ) {
			$subscription_update_utc_timestamp = 0;
		} else {
			$subscription_update_time_raw->setTimezone( new DateTimeZone( 'UTC' ) );
			$subscription_update_utc_timestamp = $subscription_update_time_raw->getTimestamp();
		}

		$notification_settings_update_utc_timestamp = get_option( 'wcs_notification_settings_update_time', 0 );

		if ( $subscription_update_utc_timestamp < $notification_settings_update_utc_timestamp ) {
			$this->schedule_all_notifications( $subscription );
		}
	}

	/**
	 * Schedule a notification with given type for given subscription.
	 *
	 * Date/time is determined automatically based on notification type, dates stored on the subscription,
	 * and offset WCS_Action_Scheduler_Customer_Notifications::$time_offset.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $notification_type
	 *
	 * @return void
	 */
	protected function schedule_notification( $subscription, $notification_type ) {
		$action_name = self::get_action_from_date_type( $notification_type );

		$event_date = $subscription->get_date( $notification_type );
		$timestamp  = $this->subtract_time_offset( $event_date, $subscription, $notification_type );

		$this->maybe_schedule_notification(
			$subscription,
			$action_name,
			$timestamp
		);
	}

	/**
	 * Schedule all notifications for a subscription based on the dates defined on the subscription.
	 *
	 * Which notifications are needed for the subscription is determined by \WCS_Action_Scheduler_Customer_Notifications::get_valid_notifications.
	 *
	 * @param WC_Subscription $subscription
	 *
	 * @return void
	 */
	protected function schedule_all_notifications( $subscription ) {
		$valid_notifications  = self::get_valid_notifications( $subscription );
		$actual_notifications = $this->get_notifications( $subscription );

		// Unschedule notifications that aren't valid for this subscription.
		$notifications_to_unschedule = array_diff( $actual_notifications, $valid_notifications );
		foreach ( $notifications_to_unschedule as $notification_type ) {
			$this->unschedule_actions( self::get_action_from_date_type( $notification_type ), self::get_action_args( $subscription ) );
		}

		// Schedule/check scheduling for valid notifications.
		foreach ( $valid_notifications as $notification_type ) {
			$this->schedule_notification( $subscription, $notification_type );
		}
	}

	/**
	 * Set which date types are affecting the notifications.
	 *
	 * Currently, only trial_end, end and next_payment are being used.
	 *
	 * @return void
	 */
	public function set_date_types_to_schedule() {
		$this->date_types_to_schedule = [
			'trial_end',
			'next_payment',
			'end',
		];
	}

	/**
	 * Schedule notifications if the date has changed.
	 *
	 * @param object $subscription An instance of a WC_Subscription object
	 * @param string $date_type Can be 'trial_end', 'next_payment', 'payment_retry', 'end', 'end_of_prepaid_term' or a custom date type
	 * @param string $datetime A MySQL formatted date/time string in the GMT/UTC timezone.
	 */
	public function update_date( $subscription, $date_type, $datetime ) {
		if ( in_array( $date_type, $this->get_date_types_to_schedule(), true ) ) {
			$this->schedule_all_notifications( $subscription );
		}
	}

	/**
	 * Schedule notifications if the date has been deleted.
	 *
	 * @param WC_Subscription $subscription An instance of a WC_Subscription object
	 * @param string $date_type Can be 'trial_end', 'next_payment', 'end', 'end_of_prepaid_term' or a custom date type
	 */
	public function delete_date( $subscription, $date_type ) {
		$action = $this->get_action_from_date_type( $date_type );
		if ( $action ) {
			$this->unschedule_actions( $action, self::get_action_args( $subscription ) );
		}

		$this->schedule_all_notifications( $subscription );
	}

	/**
	 * Unschedule all notifications for a subscription.
	 *
	 * @param object $subscription An instance of a WC_Subscription object
	 * @param array $exceptions Array of notification actions to not unschedule
	 *
	 * @return void
	 */
	public function unschedule_all_notifications( $subscription = null, $exceptions = [] ) {
		foreach ( self::$notification_actions as $action ) {
			if ( in_array( $action, $exceptions, true ) ) {
				continue;
			}

			$this->unschedule_actions( $action, self::get_action_args( $subscription ) );
		}
	}

	/**
	 * When a subscription's status is updated, maybe schedule an event
	 *
	 * @param object $subscription An instance of a WC_Subscription object
	 * @param string $new_status New subscription status
	 * @param string $old_status Previous subscription status
	 */
	public function update_status( $subscription, $new_status, $old_status ) {

		switch ( $new_status ) {
			case 'active':
				// Schedule new notifications (will also unschedule unneeded ones).
				$this->schedule_all_notifications( $subscription );
				break;
			case 'pending-cancel':
				// Unschedule all except expiration notification.
				$this->unschedule_all_notifications( $subscription, [ 'woocommerce_scheduled_subscription_customer_notification_expiration' ] );
				break;
			case 'on-hold':
			case 'cancelled':
			case 'switched':
			case 'expired':
			case 'trash':
				$this->unschedule_all_notifications( $subscription );
				break;
		}
	}

	/**
	 * Get the args to set on the scheduled action.
	 *
	 * @param WC_Subscription|null $subscription An instance of WC_Subscription to get the hook for
	 *
	 * @return array Array of name => value pairs stored against the scheduled action.
	 */
	public static function get_action_args( $subscription ) {
		if ( ! $subscription ) {
			return [];
		}

		$action_args = [ 'subscription_id' => $subscription->get_id() ];

		return $action_args;
	}

	/**
	 * Get the args to set on the scheduled action.
	 *
	 * @param string $action_hook Name of event used as the hook for the scheduled action.
	 * @param array $action_args Array of name => value pairs stored against the scheduled action.
	 */
	protected function unschedule_actions( $action_hook, $action_args = [] ) {
		as_unschedule_all_actions( $action_hook, $action_args, self::$notifications_as_group );
	}

	/**
	 * Returns true if given date for subscription is now or in the future.
	 *
	 * @param WC_Subscription $subscription Subscription whose date is examined.
	 * @param string $date_type Date type to evaluate.
	 *
	 * @return bool
	 */
	protected static function is_date_in_the_future_or_now( $subscription, $date_type ) {
		$dt        = new DateTime( $subscription->get_date( $date_type ), new DateTimeZone( 'UTC' ) );
		$timestamp = $dt->getTimestamp();

		return $timestamp >= time();
	}

	/**
	 * Return an array of notifications valid for given subscription based on the dates set on the subscription.
	 *
	 * This method doesn't take status into account. That's done in \WCS_Action_Scheduler_Customer_Notifications::update_status.
	 *
	 * Possible values in the array: 'end', 'trial_end', 'next_payment'.
	 *
	 * @param WC_Subscription $subscription
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function get_valid_notifications( $subscription ) {
		$notifications = [];

		if ( $subscription->get_date( 'end' ) && self::is_date_in_the_future_or_now( $subscription, 'end' ) ) {
			$notifications[] = 'end';
		}

		if ( $subscription->get_date( 'trial_end' ) && self::is_date_in_the_future_or_now( $subscription, 'trial_end' ) ) {
			$notifications[] = 'trial_end';
		}

		if ( $subscription->get_date( 'next_payment' ) ) {

			// Renewal notification is only valid after the trial ended.
			$trial_end = $subscription->get_date( 'trial_end' );
			if ( $trial_end ) {
				$trial_end_dt        = new DateTime( $trial_end, new DateTimeZone( 'UTC' ) );
				$trial_end_timestamp = $trial_end_dt->getTimestamp();

				if ( $trial_end_timestamp < time() && self::is_date_in_the_future_or_now( $subscription, 'next_payment' ) ) {
					$notifications[] = 'next_payment';
				}
			} elseif ( self::is_date_in_the_future_or_now( $subscription, 'next_payment' ) ) {
				$notifications[] = 'next_payment';
			}
		}

		/**
		 * Filter: `woocommerce_subscription_valid_customer_notification_types`.
		 *
		 * Allows filtering the list of notification types that will be scheduled for a particular subscription.
		 *
		 * Default array format returned:
		 *
		 * array(
		 *     'next_payment', // Exists if the subscription contains a next payment date in the future.
		 *     'trial_end',    // Exists if the subscription contains a trial end date in the future.
		 *     'end'           // Exists if the subscription contains an end date in the future.
		 * )
		 *
		 * @since 7.2.0
		 *
		 * @param array           $notifications Array of valid notification types.
		 * @param WC_Subscription $subscription  Subscription object.
		 */
		return (array) apply_filters( 'woocommerce_subscription_valid_customer_notification_types', $notifications, $subscription );
	}

	/**
	 * Returns a list of currently scheduled notifications for a subscription.
	 *
	 * Notifications are identified by the date type of the subscription.
	 * I.e. possible values are: 'end', 'trial_end' and 'next_payment'.
	 *
	 * @param $subscription
	 *
	 * @return array
	 */
	public function get_notifications( $subscription ) {
		$notifications = [];

		$date_types = $this->get_date_types_to_schedule();

		foreach ( $date_types as $date_type ) {
			$next_scheduled = as_next_scheduled_action(
				self::get_action_from_date_type( $date_type ),
				self::get_action_args( $subscription ),
				self::$notifications_as_group
			);
			if ( $next_scheduled ) {
				$notifications[] = $date_type;
			}
		}

		return $notifications;
	}
}
