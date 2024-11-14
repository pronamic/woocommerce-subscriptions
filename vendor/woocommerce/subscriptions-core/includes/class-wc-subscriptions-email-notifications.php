<?php

/**
 * Subscriptions Email Notifications Class
 *
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Email
 * @category   Class
 */
class WC_Subscriptions_Email_Notifications {

	/**
	 * @var string Offset setting option identifier.
	 */
	public static $offset_setting_string = '_customer_notifications_offset';

	/**
	 * @var string Enabled/disabled setting option identifier.
	 */
	public static $switch_setting_string = '_customer_notifications_enabled';

	/**
	 * Init.
	 */
	public static function init() {

		add_action( 'woocommerce_email_classes', [ __CLASS__, 'add_emails' ], 10, 1 );

		add_action( 'woocommerce_init', [ __CLASS__, 'hook_notification_emails' ] );

		// Add notification actions to the admin edit subscriptions page.
		add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_notification_actions' ], 10, 1 );

		// Trigger actions from Edit order screen.
		add_action( 'woocommerce_order_action_wcs_customer_notification_free_trial_expiration', [ __CLASS__, 'forward_action' ], 10, 1 );
		add_action( 'woocommerce_order_action_wcs_customer_notification_subscription_expiration', [ __CLASS__, 'forward_action' ], 10, 1 );
		add_action( 'woocommerce_order_action_wcs_customer_notification_renewal', [ __CLASS__, 'forward_action' ], 10, 1 );

		// Add settings UI.
		add_filter( 'woocommerce_subscription_settings', [ __CLASS__, 'add_settings' ], 20 );

		// Add admin notice.
		add_action( 'admin_notices', [ __CLASS__, 'maybe_add_admin_notice' ] );

		// Bump settings update time whenever related options change.
		add_action( 'update_option_' . WC_Subscriptions_Admin::$option_prefix . self::$offset_setting_string, [ __CLASS__, 'set_notification_settings_update_time' ], 10, 3 );
		add_action( 'update_option_' . WC_Subscriptions_Admin::$option_prefix . self::$switch_setting_string, [ __CLASS__, 'set_notification_settings_update_time' ], 10, 3 );
		add_action( 'add_option_' . WC_Subscriptions_Admin::$option_prefix . self::$offset_setting_string, [ __CLASS__, 'set_notification_settings_update_time' ], 10, 2 );
		add_action( 'add_option_' . WC_Subscriptions_Admin::$option_prefix . self::$switch_setting_string, [ __CLASS__, 'set_notification_settings_update_time' ], 10, 2 );
	}

	/**
	 * Map and forward Edit order screen action to the correct reminder.
	 *
	 * @param $order
	 *
	 * @return void
	 */
	public static function forward_action( $order ) {
		$trigger_action = '';
		$current_action = current_action();
		switch ( $current_action ) {
			case 'woocommerce_order_action_wcs_customer_notification_free_trial_expiration':
				$trigger_action = 'woocommerce_scheduled_subscription_customer_notification_trial_expiration';
				break;
			case 'woocommerce_order_action_wcs_customer_notification_subscription_expiration':
				$trigger_action = 'woocommerce_scheduled_subscription_customer_notification_expiration';
				break;
			case 'woocommerce_order_action_wcs_customer_notification_renewal':
				$trigger_action = 'woocommerce_scheduled_subscription_customer_notification_renewal';
				break;
		}

		if ( $trigger_action ) {
			do_action( $trigger_action, $order->get_id() );
		}
	}

	/**
	 * Sets the update time when any of the settings that affect notifications change and triggers update of subscriptions.
	 *
	 * When time offset or global on/off switch change values, this method gets triggered and it:
	 * 1. Updates the wcs_notification_settings_update_time option so that the code knows which subscriptions to update
	 * 2. Triggers rescheduling/unscheduling of existing notifications.
	 * 3. Adds a notice with info about the actions that got triggered to the store manager.
	 *
	 * Side note: offset gets updated in WCS_Action_Scheduler_Customer_Notifications::set_time_offset_from_option.
	 *
	 * @return void
	 */
	public static function set_notification_settings_update_time() {
		update_option( 'wcs_notification_settings_update_time', time() );

		// Shortcut to unschedule all notifications more efficiently instead of processing them subscription by subscription.
		if ( ! self::notifications_globally_enabled() ) {
			as_unschedule_all_actions( null, [], 'wcs_customer_notifications' );
		} else {
			$message      = WCS_Notifications_Batch_Processor::enqueue();
			$admin_notice = new WCS_Admin_Notice( 'updated' );
			$admin_notice->set_simple_content( $message );
			$admin_notice->display();
		}
	}

	/**
	 * Add Subscriptions notifications' email classes.
	 */
	public static function add_emails( $email_classes ) {

		$email_classes['WCS_Email_Customer_Notification_Auto_Trial_Expiration']   = new WCS_Email_Customer_Notification_Auto_Trial_Expiration();
		$email_classes['WCS_Email_Customer_Notification_Manual_Trial_Expiration'] = new WCS_Email_Customer_Notification_Manual_Trial_Expiration();
		$email_classes['WCS_Email_Customer_Notification_Subscription_Expiration'] = new WCS_Email_Customer_Notification_Subscription_Expiration();
		$email_classes['WCS_Email_Customer_Notification_Manual_Renewal']          = new WCS_Email_Customer_Notification_Manual_Renewal();
		$email_classes['WCS_Email_Customer_Notification_Auto_Renewal']            = new WCS_Email_Customer_Notification_Auto_Renewal();

		return $email_classes;
	}

	/**
	 * Hook the notification emails with our custom trigger.
	 */
	public static function hook_notification_emails() {
		add_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', [ __CLASS__, 'send_notification' ] );
		add_action( 'woocommerce_scheduled_subscription_customer_notification_trial_expiration', [ __CLASS__, 'send_notification' ] );
		add_action( 'woocommerce_scheduled_subscription_customer_notification_expiration', [ __CLASS__, 'send_notification' ] );
	}

	/**
	 * Send the notification emails.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function send_notification( $subscription_id ) {

		// Init email classes.
		$emails = WC()->mailer()->get_emails();

		if ( ! ( $emails['WCS_Email_Customer_Notification_Auto_Renewal'] instanceof WCS_Email_Customer_Notification_Auto_Renewal
				&& $emails['WCS_Email_Customer_Notification_Manual_Renewal'] instanceof WCS_Email_Customer_Notification_Manual_Renewal
				&& $emails['WCS_Email_Customer_Notification_Subscription_Expiration'] instanceof WCS_Email_Customer_Notification_Subscription_Expiration
				&& $emails['WCS_Email_Customer_Notification_Manual_Trial_Expiration'] instanceof WCS_Email_Customer_Notification_Manual_Trial_Expiration
				&& $emails['WCS_Email_Customer_Notification_Auto_Trial_Expiration'] instanceof WCS_Email_Customer_Notification_Auto_Trial_Expiration
			)
		) {
			return;
		}
		$notification = null;
		switch ( current_action() ) {
			case 'woocommerce_scheduled_subscription_customer_notification_renewal':
				$subscription = wcs_get_subscription( $subscription_id );
				if ( $subscription->is_manual() ) {
					$notification = $emails['WCS_Email_Customer_Notification_Manual_Renewal'];
				} else {
					$notification = $emails['WCS_Email_Customer_Notification_Auto_Renewal'];
				}
				break;
			case 'woocommerce_scheduled_subscription_customer_notification_trial_expiration':
				$subscription = wcs_get_subscription( $subscription_id );
				if ( $subscription->is_manual() ) {
					$notification = $emails['WCS_Email_Customer_Notification_Manual_Trial_Expiration'];
				} else {
					$notification = $emails['WCS_Email_Customer_Notification_Auto_Trial_Expiration'];
				}
				break;
			case 'woocommerce_scheduled_subscription_customer_notification_expiration':
				$notification = $emails['WCS_Email_Customer_Notification_Subscription_Expiration'];
				break;
		}

		if ( $notification ) {
			$notification->trigger( $subscription_id );
		}
	}

	/**
	 * Is the notifications feature enabled?
	 *
	 * @return bool
	 */
	public static function notifications_globally_enabled() {
		return ( 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . self::$switch_setting_string )
				&& get_option( WC_Subscriptions_Admin::$option_prefix . self::$offset_setting_string ) );
	}

	/**
	 * Should the emails be sent out?
	 *
	 * @return bool
	 */
	public static function should_send_notification() {
		$notification_enabled = true;

		if ( WCS_Staging::is_duplicate_site() ) {
			$notification_enabled = false;
		}

		$allowed_env_types = [
			'production',
		];
		if ( ! in_array( wp_get_environment_type(), $allowed_env_types, true ) ) {
			$notification_enabled = false;
		}

		// If Customer notifications are disabled in the settings by a global switch, or there is no offset set, don't send notifications.
		if ( ! self::notifications_globally_enabled() ) {
			$notification_enabled = false;
		}

		return $notification_enabled;
	}

	/**
	 * Adds actions to the admin edit subscriptions page.
	 *
	 * @param array $actions An array of available actions
	 * @return array An array of updated actions
	 */
	public static function add_notification_actions( $actions ) {
		global $theorder;

		if ( ! self::notifications_globally_enabled() ) {
			return $actions;
		}

		if ( ! wcs_is_subscription( $theorder ) ) {
			return $actions;
		}

		if ( ! $theorder->has_status( [ 'active', 'on-hold', 'pending-cancel' ] ) ) {
			return $actions;
		}

		$valid_notifications = WCS_Action_Scheduler_Customer_Notifications::get_valid_notifications( $theorder );

		if ( in_array( 'trial_end', $valid_notifications, true ) ) {
			$actions['wcs_customer_notification_free_trial_expiration'] = esc_html__( 'Send trial is ending notification', 'woocommerce-subscriptions' );
		}

		if ( in_array( 'end', $valid_notifications, true ) ) {
			$actions['wcs_customer_notification_subscription_expiration'] = esc_html__( 'Send upcoming subscription expiration notification', 'woocommerce-subscriptions' );
		}

		if ( in_array( 'next_payment', $valid_notifications, true ) ) {
			$actions['wcs_customer_notification_renewal'] = esc_html__( 'Send upcoming renewal notification', 'woocommerce-subscriptions' );
		}

		return $actions;
	}

	/**
	 * Adds the subscription notification setting.
	 *
	 * @param  array $settings Subscriptions settings.
	 * @return array Subscriptions settings.
	 */
	public static function add_settings( $settings ) {

		$notification_settings = [
			[
				'name' => __( 'Customer Notifications', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_customer_notifications',
				/* translators: Link to WC Settings > Email. */
				'desc' => sprintf( __( 'To enable/disable individual notifications and customize templates, visit the <a href="%s">Email settings</a>.', 'woocommerce-subscriptions' ), admin_url( 'admin.php?page=wc-settings&tab=email' ) ),
			],
			[
				'name'     => __( 'Enable Reminders', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Send notification emails to customers for subscription renewals and expirations.', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . self::$switch_setting_string,
				'desc_tip' => false,
				'type'     => 'checkbox',
				'default'  => 'no',
				'autoload' => false,
			],
			[
				'name'        => __( 'Reminder Timing', 'woocommerce-subscriptions' ),
				'desc'        => __( 'How long before the event should the notification be sent.', 'woocommerce-subscriptions' ),
				'tip'         => '',
				'id'          => WC_Subscriptions_Admin::$option_prefix . self::$offset_setting_string,
				'desc_tip'    => true,
				'type'        => 'relative_date_selector',
				'placeholder' => __( 'N/A', 'woocommerce-subscriptions' ),
				'default'     => [
					'number' => '3',
					'unit'   => 'days',
				],
				'autoload'    => false,
			],
			[
				'type' => 'sectionend',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_customer_notifications',
			],
		];

		WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Admin::$option_prefix . '_miscellaneous', $notification_settings, 'multiple_settings', 'sectionend' );
		return $settings;
	}

	/**
	 * Maybe add an admin notice to inform the store manager about the existance of the notifications feature.
	 */
	public static function maybe_add_admin_notice() {

		// If the notifications feature is enabled, don't show the notice.
		if ( self::notifications_globally_enabled() ) {
			return;
		}

		// Prevent showing the notice on the Subscriptions settings page.
		if ( isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'subscriptions' === $_GET['tab'] ) {
			return;
		}

		$option_name = 'wcs_hide_customer_notifications_notice';
		$nonce       = '_wcsnonce';
		$action      = 'wcs_hide_customer_notifications_notice_action';

		// First, check if the notice is being dismissed.
		$nonce_argument = sanitize_text_field( wp_unslash( $_GET[ $nonce ] ?? '' ) );
		if ( isset( $_GET[ $action ], $nonce_argument ) && wp_verify_nonce( $nonce_argument, $action ) ) {
			update_option( $option_name, 'yes' );
			wp_safe_redirect( remove_query_arg( [ $action, $nonce ] ) );
			return;
		}

		if ( 'yes' === get_option( $option_name ) ) {
			return;
		}

		$admin_notice   = new WCS_Admin_Notice( 'notice', array(), wp_nonce_url( add_query_arg( $action, 'dismiss' ), $action, $nonce ) );
		$notice_title   = __( 'WooCommerce Subscriptions: Introducing customer email notifications!', 'woocommerce-subscriptions' );
		$notice_content = __( 'You can now send email notifications for subscription renewals, expirations, and free trials. Go to the "Customer Notifications" settings section to configure when your customers receive these important updates.', 'woocommerce-subscriptions' );
		$html_content   = sprintf( '<p class="main"><strong>%1$s</strong></p><p>%2$s</p>', $notice_title, $notice_content );
		$admin_notice->set_html_content( $html_content );
		$admin_notice->set_actions(
			array(
				array(
					'name'  => __( 'Manage settings', 'woocommerce-subscriptions' ),
					'url'   => admin_url( 'admin.php?page=wc-settings&tab=subscriptions' ),
					'class' => 'button button-primary',
				),
				array(
					'name'  => __( 'Learn more', 'woocommerce-subscriptions' ),
					'url'   => 'https://woocommerce.com/document/subscriptions/subscriptions-notifications/',
					'class' => 'button',
				),
			)
		);

		$admin_notice->display();
	}
}
