<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Customer Notification: Free Trial Expiring Subscription Email
 *
 * An email sent to the customer when a free trial is about to end.
 *
 * @class WCS_Email_Customer_Notification_Free_Trial_Expiry
 * @version 1.0.0
 * @package WooCommerce_Subscriptions/Classes/Emails
 * @extends WC_Email
 */
class WCS_Email_Customer_Notification_Auto_Trial_Expiration extends WCS_Email_Customer_Notification {

	/**
	 * Create an instance of the class.
	 */
	public function __construct() {
		$this->plugin_id = 'woocommerce-subscriptions_';

		$this->id          = 'customer_notification_auto_trial_expiry';
		$this->title       = __( 'Customer Notification: Free trial expiration: automatic payment notice', 'woocommerce-subscriptions' );
		$this->description = __( 'Free trial expiry notification emails are sent when customer\'s free trial for an automatically renewd subscription is about to expire.', 'woocommerce-subscriptions' );

		$this->heading = __( 'Free trial expiration: automatic payment notice', 'woocommerce-subscriptions' );
		// translators: $1: {site_title}, $2: {customers_first_name}, variables that will be substituted when email is sent out
		$this->subject = sprintf( _x( '[%1$s] %2$s, your paid subscription starts soon!', 'default email subject for free trial expiry notification emails sent to the customer', 'woocommerce-subscriptions' ), '{site_title}', '{customers_first_name}' );

		$this->template_html  = 'emails/customer-notification-auto-trial-ending.php';
		$this->template_plain = 'emails/plain/customer-notification-auto-trial-ending.php';
		$this->template_base  = WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' );

		$this->customer_email = true;

		// Constructor in parent uses the values above in the initialization.
		parent::__construct();
	}

	public function get_relevant_date_type() {
		return 'trial_end';
	}
}
