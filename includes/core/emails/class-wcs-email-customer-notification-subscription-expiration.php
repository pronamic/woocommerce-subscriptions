<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Customer Notification: Subscription Expiring email
 *
 * An email sent to the customer when a subscription is about to expire.
 *
 * @class WCS_Email_Customer_Notification_Subscription_Expiring
 * @version 1.0.0
 * @package WooCommerce_Subscriptions/Classes/Emails
 * @extends WC_Email
 */
class WCS_Email_Customer_Notification_Subscription_Expiration extends WCS_Email_Customer_Notification {

	/**
	 * Create an instance of the class.
	 */
	public function __construct() {
		$this->plugin_id = 'woocommerce-subscriptions_';

		$this->id          = 'customer_notification_subscription_expiry';
		$this->title       = __( 'Customer Notification: Subscription expiration notice', 'woocommerce-subscriptions' );
		$this->description = __( 'Subscription expiration notification emails are sent when customer\'s subscription is about to expire.', 'woocommerce-subscriptions' );

		$this->heading = __( 'Subscription expiration notice', 'woocommerce-subscriptions' );
		// translators: $1: {site_title}, $2: {customers_first_name}, variables that will be substituted when email is sent out
		$this->subject = sprintf( _x( '[%1$s] %2$s, your subscription is about to expire!', 'default email subject for subscription expiry notification email sent to the customer', 'woocommerce-subscriptions' ), '{site_title}', '{customers_first_name}' );

		$this->template_html  = 'emails/customer-notification-expiring-subscription.php';
		$this->template_plain = 'emails/plain/customer-notification-expiring-subscription.php';
		$this->template_base  = WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' );

		$this->customer_email = true;

		// Constructor in parent uses the values above in the initialization.
		parent::__construct();
	}

	public function get_relevant_date_type() {
		return 'end';
	}

	/**
	 * Default content to show below main email content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Thank you for choosing {site_title}, {customers_first_name}.', 'woocommerce-subscriptions' );
	}
}
