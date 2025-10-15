<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Customer Notification: Automated Subscription Renewal.
 *
 * An email sent to the customer when a subscription will be renewed automatically.
 *
 * @class WCS_Email_Customer_Notification_Auto_Renewal
 * @version 1.0.0
 * @package WooCommerce_Subscriptions/Classes/Emails
 */
class WCS_Email_Customer_Notification_Auto_Renewal extends WCS_Email_Customer_Notification {

	/**
	 * Create an instance of the class.
	 */
	public function __construct() {
		$this->plugin_id = 'woocommerce-subscriptions_';

		$this->id          = 'customer_notification_auto_renewal';
		$this->title       = __( 'Customer Notification: Automatic renewal notice', 'woocommerce-subscriptions' );
		$this->description = __( 'Customer Notification: Automatic renewal notice emails are sent when customer\'s subscription is about to be renewed automatically.', 'woocommerce-subscriptions' );

		$this->heading = __( 'Automatic renewal notice', 'woocommerce-subscriptions' );

		$this->subject = sprintf(
			// translators: $1: {site_title}, $2: {customers_first_name}, $3: {time_until_renewal}, variables that will be substituted when email is sent out
			_x( '[%1$s] %2$s, your subscription automatically renews in %3$s!', 'default email subject for subscription\'s automatic renewal notice', 'woocommerce-subscriptions' ),
			'{site_title}',
			'{customers_first_name}',
			'{time_until_renewal}'
		);

		$this->template_html  = 'emails/customer-notification-auto-renewal.php';
		$this->template_plain = 'emails/plain/customer-notification-auto-renewal.php';
		$this->template_base  = WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' );

		$this->customer_email = true;

		// Constructor in parent uses the values above in the initialization.
		parent::__construct();
	}

	public function get_relevant_date_type() {
		return 'next_payment';
	}

	/**
	 * Default content to show below main email content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Thank you for being a loyal customer, {customers_first_name} — we appreciate your business.', 'woocommerce-subscriptions' );
	}
}
