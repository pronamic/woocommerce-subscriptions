<?php
/**
 * Subscriptions Email Class
 *
 * Modifies the base WooCommerce email class and extends it to send subscription emails.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Email
 * @category   Class
 * @author     Prospress
 */
class WC_Subscriptions_Email {
	/**
	 * Used to capture the ID of a subscription that is potentially being reactivated.
	 */
	private static int $subscription_being_reactivated_id = -1;

	/**
	 * End times, before cancellation, of pending-cancel subscriptions that are being cancelled. Keyed by subscription ID.
	 *
	 * @var int[]
	 */
	private static array $end_times_before_cancellation = [];

	/**
	 * List of all core subscription email classes.
	 *
	 * @var array
	 */
	public static $email_classes = [
		'WCS_Email_New_Renewal_Order'               => true,
		'WCS_Email_New_Switch_Order'                => true,
		'WCS_Email_Processing_Renewal_Order'        => true,
		'WCS_Email_Completed_Renewal_Order'         => true,
		'WCS_Email_Customer_On_Hold_Renewal_Order'  => true,
		'WCS_Email_Completed_Switch_Order'          => true,
		'WCS_Email_Customer_Renewal_Invoice'        => true,
		'WCS_Email_Cancelled_Subscription'          => true,
		'WCS_Email_Customer_Cancelled_Subscription' => true,
		'WCS_Email_Expired_Subscription'            => true,
		'WCS_Email_On_Hold_Subscription'            => true,
		'WCS_Email_Reactivated_Subscription'        => true,
	];

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function init() {

		add_action( 'woocommerce_email_classes', __CLASS__ . '::add_emails', 10, 1 );

		add_action( 'woocommerce_init', __CLASS__ . '::hook_transactional_emails' );

		add_filter( 'woocommerce_resend_order_emails_available', __CLASS__ . '::renewal_order_emails_available', -1 ); // run before other plugins so we don't remove their emails

		add_action( 'woocommerce_subscriptions_email_order_details', __CLASS__ . '::order_download_details', 10, 4 );
		add_action( 'woocommerce_subscriptions_email_order_details', __CLASS__ . '::order_details', 10, 4 );

		add_action( 'woocommerce_subscription_status_pending-cancel_to_active', __CLASS__ . '::maybe_clear_cancelled_email_flag', 10 );
	}

	/**
	 * Add Subscriptions' email classes.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4
	 */
	public static function add_emails( $email_classes ) {
		foreach ( self::$email_classes as $email_class => $_ ) {
			$email_classes[ $email_class ] = new $email_class();
		}

		return $email_classes;
	}

	/**
	 * Hooks up all of Subscription's transaction emails after the WooCommerce object is constructed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4
	 */
	public static function hook_transactional_emails() {

		$order_email_actions = array(
			// WC New Order Emails (Admin) -- other hooks below also trigger this email.
			'woocommerce_order_status_pending_to_completed',
			'woocommerce_order_status_failed_to_completed',
			'woocommerce_order_status_cancelled_to_completed',

			// WC Order Processing Emails.
			'woocommerce_order_status_pending_to_processing',
			'woocommerce_order_status_failed_to_processing',
			'woocommerce_order_status_cancelled_to_processing',
			'woocommerce_order_status_on-hold_to_processing',

			// WC Order Completed Emails.
			'woocommerce_order_status_completed',

			// WC On-hold Order Emails.
			'woocommerce_order_status_pending_to_on-hold',
			'woocommerce_order_status_failed_to_on-hold',
			'woocommerce_order_status_cancelled_to_on-hold',

			// WCS Order Invoice Emails.
			'woocommerce_generated_manual_renewal_order',
			'woocommerce_order_status_failed',
		);

		// Don't send subscriptions emails or WC core emails for subscription-related events on staging sites.
		if ( WCS_Staging::is_duplicate_site() && ! defined( 'WCS_FORCE_EMAIL' ) ) {
			// Unhook WC order core emails which would normally be replaced by a subscription version on a live site.
			foreach ( $order_email_actions as $action ) {
				add_action( $action, __CLASS__ . '::maybe_remove_woocommerce_email', 9 );
			}
			return;
		}

		add_action( 'woocommerce_before_customer_changed_subscription_to_active', __CLASS__ . '::watch_for_reactivations' );
		add_action( 'woocommerce_customer_changed_subscription_to_active', __CLASS__ . '::maybe_send_reactivation_email' );
		add_action( 'woocommerce_subscription_pre_update_status', __CLASS__ . '::record_end_time_before_cancellation', 10, 3 );
		add_action( 'woocommerce_subscription_status_updated', __CLASS__ . '::send_cancelled_email', 10, 3 );
		add_action( 'woocommerce_subscription_status_expired', __CLASS__ . '::send_expired_email', 10 );
		add_action( 'woocommerce_customer_changed_subscription_to_on-hold', __CLASS__ . '::send_on_hold_email', 10 );
		add_action( 'woocommerce_subscriptions_switch_completed', __CLASS__ . '::send_switch_order_email', 10 );

		foreach ( $order_email_actions as $action ) {
			add_action( $action, __CLASS__ . '::maybe_remove_woocommerce_email', 9 );
			add_action( $action, __CLASS__ . '::send_renewal_order_email', 10 );
			add_action( $action, __CLASS__ . '::maybe_reattach_woocommerce_email', 11 );
		}
	}

	/**
	 * Init the mailer and call for the cancelled email notification hook.
	 *
	 * @param WC_Subscription $subscription The subscription being examined.
	 * @param string          $new_status   The subscription's new status. Since 9.4.0.
	 * @param string          $old_status   The subscription's old status. Since 9.4.0.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function send_cancelled_email( $subscription, $new_status = '', $old_status = '' ) {
		WC()->mailer();
		do_action( 'cancelled_subscription_notification', $subscription, $new_status, $old_status );

		// The recorded end time applies only to this status change.
		if ( $subscription instanceof WC_Subscription ) {
			unset( self::$end_times_before_cancellation[ $subscription->get_id() ] );
		}
	}

	/**
	 * Record the end time of a pending-cancel subscription that is being cancelled.
	 *
	 * By the time the status change hooks fire, the end date is already the time of cancellation. The customer
	 * cancelled subscription email uses the recorded time to tell an early end from the end of the prepaid term.
	 *
	 * @internal This method may be moved or renamed without notice.
	 * @since    9.4.0
	 *
	 * @param string|mixed          $old_status   The subscription's old status.
	 * @param string|mixed          $new_status   The subscription's new status.
	 * @param WC_Subscription|mixed $subscription The subscription being updated.
	 */
	public static function record_end_time_before_cancellation( $old_status, $new_status, $subscription ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		if ( 'pending-cancel' === $old_status && 'cancelled' === $new_status ) {
			self::$end_times_before_cancellation[ $subscription->get_id() ] = (int) $subscription->get_time( 'end' );
		} else {
			unset( self::$end_times_before_cancellation[ $subscription->get_id() ] );
		}
	}

	/**
	 * Get the end time recorded by record_end_time_before_cancellation() for the status change in progress.
	 *
	 * @internal This method may be moved or renamed without notice.
	 * @since    9.4.0
	 *
	 * @param int $subscription_id The subscription ID.
	 *
	 * @return int The end time as a timestamp, or 0 if none was recorded.
	 */
	public static function get_end_time_before_cancellation( int $subscription_id ): int {
		return self::$end_times_before_cancellation[ $subscription_id ] ?? 0;
	}

	/**
	 * Run a callback without sending the customer cancelled subscription email for one subscription.
	 *
	 * Use this around a status change that cancels a subscription for a reason the customer needs no email about,
	 * such as a switch that moves all of its items to a new subscription.
	 *
	 * @internal This method may be moved or renamed without notice.
	 * @since    9.4.0
	 *
	 * @param int      $subscription_id The subscription that must not get the email.
	 * @param callable $callback        The code that changes the subscription's status.
	 *
	 * @return mixed The callback's return value.
	 */
	public static function run_without_customer_cancelled_email( int $subscription_id, callable $callback ) {
		$skip = static function ( $enabled, $email_object ) use ( $subscription_id ) {
			return ( $email_object instanceof WC_Subscription && $email_object->get_id() === $subscription_id ) ? false : $enabled;
		};

		add_filter( 'woocommerce_email_enabled_customer_cancelled_subscription', $skip, PHP_INT_MAX, 2 );

		try {
			return $callback();
		} finally {
			remove_filter( 'woocommerce_email_enabled_customer_cancelled_subscription', $skip, PHP_INT_MAX );
		}
	}

	/**
	 * Init the mailer and call for the expired email notification hook.
	 *
	 * @param $subscription WC Subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	 */
	public static function send_expired_email( $subscription ) {
		WC()->mailer();

		do_action( 'expired_subscription_notification', $subscription );
	}

	/**
	 * Init the mailer and call for the suspended email notification hook.
	 *
	 * @param $subscription WC Subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	 */
	public static function send_on_hold_email( $subscription ) {
		WC()->mailer();

		do_action( 'on-hold_subscription_notification', $subscription );
	}

	/**
	 * Init the mailer and call the notifications for the renewal orders.
	 *
	 * @param int $order_id The order ID.
	 */
	public static function send_renewal_order_email( $order_id ) {
		WC()->mailer();

		if ( wcs_order_contains_renewal( $order_id ) ) {
			do_action( current_filter() . '_renewal_notification', $order_id );
		}
	}

	/**
	 * Listen for subscriptions being reactivated by the customer (not the admin).
	 *
	 * This method expects to run when a customer reactivates a subscription, which specifically means the customer has
	 * taken action to move a subscription from 'pending-cancel' to 'active'.
	 *
	 * @internal This method may be moved or renamed without notice.
	 * @since    8.4.0
	 *
	 * @param WC_Subscription|mixed $subscription The subscription being examined.
	 */
	public static function watch_for_reactivations( $subscription ) {
		// If we aren't working with an actual subscription (anything can be passed to a callback), or if the
		// subscription is not pending-cancel, then we don't want to send an email.
		if ( ! $subscription instanceof WC_Subscription || ! $subscription->has_status( 'pending-cancel' ) ) {
			self::$subscription_being_reactivated_id = -1;
			return;
		}

		// Capture the subscription ID, so that we can be confident we are examining the same subscription from our
		// second callback (::maybe_send_reactivation_email()).
		self::$subscription_being_reactivated_id = $subscription->get_id();
	}

	/**
	 * Trigger the email reactivation email.
	 *
	 * This method expects to run when a customer reactivates a subscription, which specifically means the customer has
	 * taken action to move a subscription from 'pending-cancel' to 'active'.
	 *
	 * @internal This method may be moved or renamed without notice.
	 * @since    8.4.0
	 *
	 * @param WC_Subscription|mixed $subscription The subscription being examined.
	 */
	public static function maybe_send_reactivation_email( $subscription ) {
		if ( $subscription->get_id() === self::$subscription_being_reactivated_id ) {
			WC()->mailer();
			do_action( 'reactivated_subscription_notification', $subscription );
			self::$subscription_being_reactivated_id = -1;
		}
	}

	/**
	 * If the order is a renewal order or a switch order, don't send core emails.
	 *
	 * @param int $order_id The order ID.
	 */
	public static function maybe_remove_woocommerce_email( $order_id ) {
		if ( wcs_order_contains_renewal( $order_id ) || wcs_order_contains_switch( $order_id ) ) {
			self::detach_woocommerce_transactional_email();
		}
	}

	/**
	 * If the order is a renewal order or a switch order, send core emails
	 *
	 * @param int $order_id The order ID.
	 */
	public static function maybe_reattach_woocommerce_email( $order_id ) {
		if ( wcs_order_contains_renewal( $order_id ) || wcs_order_contains_switch( $order_id ) ) {
			self::attach_woocommerce_transactional_email();
		}
	}

	/**
	 * If viewing a renewal order on the the Edit Order screen, set the available email actions for the order to use
	 * renewal order emails, not core WooCommerce order emails.
	 *
	 * @param array $available_emails The emails available to send from the edit order screen.
	 */
	public static function renewal_order_emails_available( $available_emails ) {
		global $theorder;

		if ( wcs_order_contains_renewal( wcs_get_objects_property( $theorder, 'id' ) ) ) {
			$available_emails = array(
				'new_renewal_order',
				'customer_processing_renewal_order',
				'customer_completed_renewal_order',
			);

			if ( $theorder->needs_payment() ) {
				array_push( $available_emails, 'customer_renewal_invoice' );
			}
		}

		return $available_emails;
	}

	/**
	 * Init the mailer and call the notifications for subscription switch orders.
	 *
	 * @param int $order_id The order ID.
	 */
	public static function send_switch_order_email( $order_id ) {
		WC()->mailer();

		if ( wcs_order_contains_switch( $order_id ) ) {
			do_action( current_filter() . '_switch_notification', $order_id );
		}
	}

	/**
	 * Generate an order items table using a WC compatible version of the function.
	 *
	 * @param object $order
	 * @param array $args {
	 *     @type bool  'show_download_links'
	 *     @type bool  'show_sku'
	 *     @type bool  'show_purchase_note'
	 *     @type array 'image_size'
	 *     @type bool  'plain_text'
	 * }
	 * @return string email order items table html
	 */
	public static function email_order_items_table( $order, $args = array() ) {
		$items_table = '';

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
			return $items_table;
		}

		$show_download_links_callback = ( isset( $args['show_download_links'] ) && $args['show_download_links'] ) ? '__return_true' : '__return_false';
		$show_purchase_note_callback  = ( isset( $args['show_purchase_note'] ) && $args['show_purchase_note'] ) ? '__return_true' : '__return_false';

		unset( $args['show_download_links'] );
		unset( $args['show_purchase_note'] );

		add_filter( 'woocommerce_order_is_download_permitted', $show_download_links_callback );
		add_filter( 'woocommerce_order_is_paid', $show_purchase_note_callback );

		$items_table = wc_get_email_order_items( $order, $args );

		remove_filter( 'woocommerce_order_is_download_permitted', $show_download_links_callback );
		remove_filter( 'woocommerce_order_is_paid', $show_purchase_note_callback );

		return $items_table;
	}

	/**
	 * Show the order details table
	 *
	 * @param WC_Order $order
	 * @param bool $sent_to_admin Whether the email is sent to admin - defaults to false
	 * @param bool $plain_text Whether the email should use plain text templates - defaults to false
	 * @param string $email
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	 */
	public static function order_details( $order, $sent_to_admin = false, $plain_text = false, $email = '' ) {
		$email_improvements_enabled = wcs_is_wc_feature_enabled( 'email_improvements' );
		$image_size                 = $email_improvements_enabled ? 48 : 32; // These image sizes are defaults for WC core emails. @see wc_get_email_order_items().
		$order_items_table_args     = array(
			'show_download_links' => ( $sent_to_admin ) ? false : $order->is_download_permitted(),
			'show_sku'            => $sent_to_admin,
			'show_purchase_note'  => ( $sent_to_admin ) ? false : $order->has_status( apply_filters( 'woocommerce_order_is_paid_statuses', array( 'processing', 'completed' ) ) ),
			'show_image'          => $email_improvements_enabled,
			'image_size'          => array( $image_size, $image_size ),
			'plain_text'          => $plain_text,
		);

		$template_path = ( $plain_text ) ? 'emails/plain/email-order-details.php' : 'emails/email-order-details.php';
		$order_type    = ( wcs_is_subscription( $order ) ) ? 'subscription' : 'order';

		wc_get_template(
			$template_path,
			array(
				'order'                  => $order,
				'sent_to_admin'          => $sent_to_admin,
				'plain_text'             => $plain_text,
				'email'                  => $email,
				'order_type'             => $order_type,
				'order_items_table_args' => $order_items_table_args,
			),
			'',
			WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' )
		);
	}

	/**
	 * Show the subscription details table.
	 *
	 * @param WC_Subscription[] $subscriptions        List of subscriptions. Also accepts a single subscription.
	 * @param WC_Order|null     $order                The order related to the subscription - defaults to parent order.
	 * @param bool              $sent_to_admin        Whether the email is sent to admin - defaults to false.
	 * @param bool              $plain_text           Whether the email should use plain text templates - defaults to false.
	 * @param bool              $skip_my_account_link Whether to skip displaying the My Account link - defaults to false.
	 */
	public static function subscription_details( $subscriptions, $order = null, $sent_to_admin = false, $plain_text = false, $skip_my_account_link = false ) {
		$template = ( $plain_text ) ? 'emails/plain/subscription-info.php' : 'emails/subscription-info.php';

		if ( ! is_array( $subscriptions ) ) {
			$subscriptions = [ $subscriptions ];
		}

		$order = ! $order && ! empty( $subscriptions ) ? reset( $subscriptions )->get_parent() : $order;

		wc_get_template(
			$template,
			array(
				'order'                => $order,
				'subscriptions'        => $subscriptions,
				'is_admin_email'       => $sent_to_admin,
				'plain_text'           => $plain_text,
				'skip_my_account_link' => $skip_my_account_link,
			),
			'',
			WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' )
		);
	}

	/**
	 * Detach WC transactional emails from a specific hook.
	 *
	 * @param string $hook Optional. The action hook or filter to detach WC core's transactional emails from. Defaults to the current filter.
	 * @param int $priority Optional. The priority the function runs on. Default 10.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.3
	 */
	public static function detach_woocommerce_transactional_email( $hook = '', $priority = 10 ) {

		if ( '' === $hook ) {
			$hook = current_filter();
		}

		// Emails might be queued or sent immediately so we need to remove both
		remove_action( $hook, array( 'WC_Emails', 'queue_transactional_email' ), $priority );
		remove_action( $hook, array( 'WC_Emails', 'send_transactional_email' ), $priority );
	}

	/**
	 * Attach WC transactional emails to a specific hook.
	 *
	 * @param string $hook Optional. The action hook or filter to attach WC core's transactional emails to. Defaults to the current filter.
	 * @param int $priority Optional. The priority the function should run on. Default 10.
	 * @param int $accepted_args Optional. The number of arguments the function accepts. Default 10.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.3
	 */
	public static function attach_woocommerce_transactional_email( $hook = '', $priority = 10, $accepted_args = 10 ) {

		if ( '' === $hook ) {
			$hook = current_filter();
		}

		if ( false === wcs_is_woocommerce_pre( '3.0' ) && apply_filters( 'woocommerce_defer_transactional_emails', false ) ) {
			add_action( $hook, array( 'WC_Emails', 'queue_transactional_email' ), $priority, $accepted_args );
		} else {
			add_action( $hook, array( 'WC_Emails', 'send_transactional_email' ), $priority, $accepted_args );
		}
	}

	/**
	 * With WooCommerce 3.2+ active display the downloads table.
	 *
	 * @param WC_Order $order
	 * @param bool $sent_to_admin Whether the email is sent to admin - defaults to false
	 * @param bool $plain_text Whether the email should use plain text templates - defaults to false
	 * @param string $email
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.17
	 */
	public static function order_download_details( $order, $sent_to_admin = false, $plain_text = false, $email = '' ) {
		if ( is_callable( array( 'WC_Emails', 'order_downloads' ) ) ) {
			WC_Emails::instance()->order_downloads( $order, $sent_to_admin, $plain_text, $email );
		}
	}

	/**
	 * If the subscription was cancelled before, reset the cancelled email sent flag so the customer can be notified of a future cancellation.
	 *
	 * @param $subscription WC_Subscription The subscription object.
	 * @return void
	 */
	public static function maybe_clear_cancelled_email_flag( $subscription ) {
		$subscription->set_cancelled_email_sent( 'false' );
		$subscription->save();
	}

	/**
	 * Init the mailer and call the notifications for the current filter.
	 *
	 * @param int $user_id The ID of the user who the subscription belongs to
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @return void
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function send_subscription_email( $user_id, $subscription_key ) {
		_deprecated_function( __FUNCTION__, '2.0' );
	}
}
