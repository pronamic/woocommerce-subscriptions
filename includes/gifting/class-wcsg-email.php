<?php
/**
 * Main class for e-mails.
 *
 * @package WooCommerce Subscriptions Gifting/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles e-mailing inside Gifting.
 */
class WCSG_Email {

	/**
	 * Header/subject and triggers associated to e-mails with downloadable headings/subjects.
	 *
	 * @var array
	 */
	public static $downloadable_email_data = array(
		'customer_completed_order'          => array(
			'trigger_action' => 'woocommerce_order_status_completed_notification',
			'heading_filter' => 'woocommerce_email_heading_customer_completed_order',
			'subject_hook'   => 'woocommerce_email_subject_customer_completed_order',
		),
		'customer_completed_renewal_order'  => array(
			'trigger_action' => 'woocommerce_order_status_completed_renewal_notification',
			'heading_filter' => '', // shares woocommerce_email_heading_customer_completed_order.
			'subject_hook'   => 'woocommerce_subscriptions_email_subject_customer_completed_renewal_order',
		),
		'customer_completed_switch_order'   => array(
			'trigger_action' => 'woocommerce_order_status_completed_switch_notification',
			'heading_filter' => 'woocommerce_email_heading_customer_switch_order',
			'subject_hook'   => 'woocommerce_subscriptions_email_subject_customer_completed_switch_order',
		),
		'recipient_completed_renewal_order' => array(
			'trigger_action' => 'woocommerce_order_status_completed_renewal_notification_recipient',
			'heading_filter' => '', // shares woocommerce_email_heading_customer_completed_order.
			'subject_hook'   => '', // shares woocommerce_subscriptions_email_subject_customer_completed_renewal_order.
		),
	);

	/**
	 * Flag used to indicate that an e-mail with downloadable headings/subjects is being sent.
	 *
	 * @var mixed
	 */
	public static $sending_downloadable_email;

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', __CLASS__ . '::add_new_recipient_customer_email', 11, 1 );
		add_filter( 'wcs_email_classes', __CLASS__ . '::add_new_recipient_customer_email', 11, 1 );
		add_action( 'woocommerce_init', __CLASS__ . '::hook_email' );
		add_action( 'wcs_gifting_email_order_details', array( __CLASS__, 'order_details' ), 10, 4 );
		add_action( 'woocommerce_subscriptions_gifting_recipient_email_details', array( __CLASS__, 'get_related_subscriptions_table' ), 10, 3 );
		add_action( 'woocommerce_subscriptions_gifting_recipient_email_details', array( __CLASS__, 'get_address_table' ), 11, 3 );
	}

	/**
	 * Add WCS Gifting email classes.
	 *
	 * @param WC_Email[] $email_classes E-mail classes.
	 */
	public static function add_new_recipient_customer_email( $email_classes ) {
		$email_classes['WCSG_Email_Customer_New_Account']        = new WCSG_Email_Customer_New_Account();
		$email_classes['WCSG_Email_Completed_Renewal_Order']     = new WCSG_Email_Completed_Renewal_Order();
		$email_classes['WCSG_Email_Processing_Renewal_Order']    = new WCSG_Email_Processing_Renewal_Order();
		$email_classes['WCSG_Email_Recipient_New_Initial_Order'] = new WCSG_Email_Recipient_New_Initial_Order();

		return $email_classes;
	}

	/**
	 * Hooks up all of WCS Gifting emails after the WooCommerce object is constructed.
	 */
	public static function hook_email() {
		add_action( 'subscriptions_activated_for_order', __CLASS__ . '::maybe_send_recipient_order_emails', 11, 1 );

		$renewal_notification_actions = array(
			'woocommerce_order_status_pending_to_processing_renewal_notification',
			'woocommerce_order_status_pending_to_on-hold_renewal_notification',
			'woocommerce_order_status_completed_renewal_notification',
		);

		foreach ( $renewal_notification_actions as $action ) {
			add_action( $action, __CLASS__ . '::maybe_send_recipient_renewal_notification', 12, 1 );
		}

		// WC 3.1 removed the email subjects and headings which reference downloadable files. Post 3.1 we don't need to worry about reformatting them.
		if ( wcsg_is_woocommerce_pre( '3.1' ) ) {
			foreach ( self::$downloadable_email_data as $email_id => $hook_data ) {

				// Hook on just before default to store a flag of the email being sent.
				add_action( $hook_data['trigger_action'], __CLASS__ . '::set_sending_downloadable_email_flag', 9 );
				add_action( $hook_data['trigger_action'], __CLASS__ . '::remove_sending_downloadable_email_flag', 11 );

				// Hook the subject and heading hooks.
				if ( ! empty( $hook_data['heading_filter'] ) ) {
					add_filter( $hook_data['heading_filter'], __CLASS__ . '::maybe_change_download_email_heading', 10, 2 );
				}

				if ( ! empty( $hook_data['subject_hook'] ) ) {
					add_filter( $hook_data['subject_hook'], __CLASS__ . '::maybe_change_download_email_heading', 10, 2 );
				}
			}

			// Hook onto emails sent via order actions.
			add_action( 'woocommerce_before_resend_order_emails', __CLASS__ . '::set_sending_downloadable_email_flag', 9 );
			add_action( 'woocommerce_after_resend_order_email', __CLASS__ . '::remove_sending_downloadable_email_flag', 11 );
		}
	}

	/**
	 * If an order contains subscriptions with recipient data send an email to the recipient
	 * notifying them on their new subscription(s)
	 *
	 * @param WC_Order|int $order Order ID or instance.
	 */
	public static function maybe_send_recipient_order_emails( $order ) {
		$order_id             = $order instanceof WC_Order ? $order->get_id() : $order;
		$subscriptions        = wcs_get_subscriptions( array( 'order_id' => $order_id ) );
		$processed_recipients = array();

		if ( empty( $subscriptions ) ) {
			return;
		}

		WC()->mailer();

		foreach ( $subscriptions as $subscription ) {
			if ( ! WCS_Gifting::is_gifted_subscription( $subscription ) ) {
				continue;
			}

			$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );

			if ( in_array( $recipient_user_id, $processed_recipients ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
				continue;
			}

			$recipient_subscriptions = WCSG_Recipient_Management::get_recipient_subscriptions( $recipient_user_id, $order_id );
			do_action( 'wcsg_new_order_recipient_notification', $recipient_user_id, $recipient_subscriptions );
			$processed_recipients[] = $recipient_user_id;
		}
	}

	/**
	 * Generates purchaser new recipient user email.
	 *
	 * @param int $purchaser_user_id Subscription purchaser user id.
	 * @param int $recipient_user_id Subscription recipient user id.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	public static function generate_new_recipient_user_email( $purchaser_user_id, $recipient_user_id ) {
		$key = get_password_reset_key( get_userdata( $recipient_user_id ) );

		if ( ! is_wp_error( $key ) ) {
			$subscription_purchaser_name = WCS_Gifting::get_user_display_name( $purchaser_user_id );

			WC()->mailer();
			do_action( 'wcsg_created_customer_notification', $recipient_user_id, $key, $subscription_purchaser_name );
		}
	}

	/**
	 * This will get the necessary data to resend the new recipient new email.
	 *
	 * @param WC_Order $subscription The subscription we're using to get the purchaser and recipient data.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	public static function resend_new_recipient_user_email( $subscription ) {
		$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );

		if ( ! empty( $recipient_user_id ) ) {
			self::generate_new_recipient_user_email( $subscription->get_customer_id(), $recipient_user_id );
		}
	}

	/**
	 * If the order contains a subscription that is being gifted, init the mailer and call the notification for recipient renewal notices.
	 *
	 * @param int $order_id The ID of the renewal order with a new status of processing/completed.
	 */
	public static function maybe_send_recipient_renewal_notification( $order_id ) {

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );

		if ( ! empty( $subscriptions ) && is_array( $subscriptions ) ) {
			$subscription = reset( $subscriptions );

			if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {
				WC()->mailer();
				do_action( current_filter() . '_recipient', $order_id );
			}
		}
	}

	/**
	 * Formats an email's heading and subject so that the correct one is displayed.
	 * If for instance the email recipient doesn't have downloads for this order fallback
	 * to the normal heading and subject,
	 *
	 * @param string $heading The email heading or subject.
	 * @param object $order   Order object.
	 * @return string
	 */
	public static function maybe_change_download_email_heading( $heading, $order ) {

		if ( empty( self::$sending_downloadable_email ) ) {
			return $heading;
		}

		$user_id = $order->get_user_id();
		$mailer  = WC()->mailer();
		$sending_email = null;

		foreach ( $mailer->emails as $email ) {
			if ( self::$sending_downloadable_email == $email->id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				$sending_email = $email;

				if ( isset( $email->wcsg_sending_recipient_email ) ) {
					$user_id = $email->wcsg_sending_recipient_email;
				}

				break;
			}
		}

		$order_downloads = WCSG_Download_Handler::get_user_downloads_for_order( $order, $user_id );

		$string_to_format = strpos( current_filter(), 'email_heading' ) ? 'heading' : 'subject';

		if ( isset( $sending_email ) && empty( $order_downloads ) && isset( $sending_email->{$string_to_format} ) ) {
			$heading = $sending_email->format_string( $sending_email->{$string_to_format} );
		}

		return $heading;
	}

	/**
	 * Set a flag to indicate that an email with downloadable headings and subjects is being sent.
	 * hooked just before the email's trigger function.
	 */
	public static function set_sending_downloadable_email_flag() {

		$current_filter = current_filter();

		if ( 'woocommerce_before_resend_order_emails' === $current_filter && ! empty( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) && ! empty( $_POST['wc_order_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$action = wc_clean( $_POST['wc_order_action'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			self::$sending_downloadable_email = str_replace( 'send_email_', '', $action );
		} else {
			foreach ( self::$downloadable_email_data as $email_id => $hook_data ) {
				if ( $current_filter == $hook_data['trigger_action'] ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
					self::$sending_downloadable_email = $email_id;
				}
			}
		}
	}

	/**
	 * Removes the downloadable email being sent flag. Hooked just after the email's trigger function.
	 */
	public static function remove_sending_downloadable_email_flag() {
		self::$sending_downloadable_email = '';
	}

	/**
	 * Overrides the email order items template in recipient emails
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $args Email arguments.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function recipient_email_order_items_table( $order, $args ) {
		$defaults = array(
			'show_sku'      => false,
			'show_image'    => false,
			'image_size'    => array( 32, 32 ),
			'plain_text'    => false,
			'sent_to_admin' => false,
		);

		$args     = wp_parse_args( $args, $defaults );
		$template = $args['plain_text'] ? 'emails/plain/recipient-email-order-items.php' : 'emails/recipient-email-order-items.php';

		wc_get_template(
			$template,
			array(
				'order'               => $order,
				'items'               => $order->get_items(),
				'show_download_links' => $order->is_download_permitted() && ! $args['sent_to_admin'],
				'show_sku'            => $args['show_sku'],
				'show_purchase_note'  => $order->is_paid() && ! $args['sent_to_admin'],
				'show_image'          => $args['show_image'],
				'image_size'          => $args['image_size'],
				'plain_text'          => $args['plain_text'],
				'sent_to_admin'       => $args['sent_to_admin'],
			),
			'',
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/'
		);
	}

	/**
	 * Show the order details table
	 *
	 * @param WC_Order $order         Order object.
	 * @param bool     $sent_to_admin Whether the email is sent to admin - defaults to false.
	 * @param bool     $plain_text    Whether the email should use plain text templates - defaults to false.
	 * @param WC_Email $email         E-mail instance.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function order_details( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		$template_path = ( $plain_text ) ? 'emails/plain/recipient-email-order-details.php' : 'emails/recipient-email-order-details.php';

		if ( wcs_is_subscription( $order ) ) {
			// Translators: placeholder is a subscription ID.
			$title = sprintf( _x( 'Subscription #%s', 'Used in email heading before line items table, placeholder is subscription ID', 'woocommerce-subscriptions' ), $order->get_order_number() );
		} else {
			// Translators: placeholder is an order ID.
			$title = sprintf( _x( 'Order #%s', 'Used in email heading before line items table, placeholder is order ID', 'woocommerce-subscriptions' ), $order->get_order_number() );
		}

		wc_get_template(
			$template_path,
			array(
				'order'         => $order,
				'sent_to_admin' => $sent_to_admin,
				'plain_text'    => $plain_text,
				'email'         => $email,
				'title'         => $title,
			),
			'',
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/'
		);
	}

	/**
	 * Get the related subscription details table for emails sent to recipients.
	 *
	 * @param WC_Order $order         The order object the email be sent relates to.
	 * @param bool     $sent_to_admin Whether the email is sent to admin users.
	 * @param bool     $plain_text    Whether the email template is plain text or HTML.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function get_related_subscriptions_table( $order, $sent_to_admin, $plain_text ) {
		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
		$template      = ( $plain_text ) ? 'emails/plain/recipient-email-subscriptions-table.php' : 'emails/recipient-email-subscriptions-table.php';

		// Only display the table if there are related subscriptions.
		if ( ! empty( $subscriptions ) ) {
			wc_get_template(
				$template,
				array( 'subscriptions' => $subscriptions ),
				'',
				plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/'
			);
		}
	}

	/**
	 * Get the order's address details table for emails sent to recipients.
	 *
	 * @param WC_Order $order         The order object the email be sent relates to.
	 * @param bool     $sent_to_admin Whether the email is sent to admin users.
	 * @param bool     $plain_text    Whether the email template is plain text.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function get_address_table( $order, $sent_to_admin, $plain_text ) {
		$template = ( $plain_text ) ? 'emails/plain/recipient-email-address-table.php' : 'emails/recipient-email-address-table.php';

		wc_get_template(
			$template,
			array( 'order' => $order ),
			'',
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/'
		);
	}

	/**
	 * Hooks into the WooCommerce created customer action to prevent sending the core WooCommerce new customer email and send the Gifting new recipient user email instead.
	 */
	public static function use_gifting_new_account_email() {
		add_action( 'woocommerce_created_customer', __CLASS__ . '::remove_wc_new_customer_email', 9, 0 );
		add_action( 'woocommerce_created_customer', __CLASS__ . '::send_new_recipient_user_email', 10, 1 );
		add_action( 'woocommerce_created_customer', __CLASS__ . '::reattach_wc_new_customer_email', 11, 0 );
	}

	/**
	 * Prevent sending the core WooCommerce new customer email.
	 */
	public static function remove_wc_new_customer_email() {
		remove_action( current_filter(), array( 'WC_Emails', 'send_transactional_email' ) );
	}

	/**
	 * Sends the Gifting new recipient user email. Overriding the core WooCommerce new customer email.
	 *
	 * @param int $customer_id The ID of the new customer being created.
	 */
	public static function send_new_recipient_user_email( $customer_id ) {
		self::generate_new_recipient_user_email( get_current_user_id(), $customer_id );
	}

	/**
	 * Reattaches the core WooCommerce new customer email after sending the gifting new account email.
	 */
	public static function reattach_wc_new_customer_email() {
		add_action( current_filter(), array( 'WC_Emails', 'send_transactional_email' ) );
	}
}
