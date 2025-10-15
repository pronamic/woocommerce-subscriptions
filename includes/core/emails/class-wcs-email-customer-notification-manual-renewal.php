<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Customer Notification: Manual Subscription Renewal.
 *
 * An email sent to the customer when a subscription needs to be renewed manually.
 *
 * @class WCS_Email_Customer_Notification_Manual_Renewal
 * @version 1.0.0
 * @package WooCommerce_Subscriptions/Classes/Emails
 */
class WCS_Email_Customer_Notification_Manual_Renewal extends WCS_Email_Customer_Notification {

	/**
	 * Create an instance of the class.
	 */
	public function __construct() {
		$this->plugin_id = 'woocommerce-subscriptions_';

		$this->id          = 'customer_notification_manual_renewal';
		$this->title       = __( 'Customer Notification: Manual renewal notice', 'woocommerce-subscriptions' );
		$this->description = __( 'Customer Notification: Manual renewal notice are sent when customer\'s subscription needs to be manually renewed.', 'woocommerce-subscriptions' );

		$this->heading = __( 'Manual renewal notice', 'woocommerce-subscriptions' );
		// translators: $1: {site_title}, $2: {customers_first_name}, variables that will be substituted when email is sent out
		$this->subject = sprintf( _x( '[%1$s] %2$s, your subscription is ready to be renewed!', 'default email subject for notification for a manually renewed subscription sent to the customer', 'woocommerce-subscriptions' ), '{site_title}', '{customers_first_name}' );

		$this->template_html  = 'emails/customer-notification-manual-renewal.php';
		$this->template_plain = 'emails/plain/customer-notification-manual-renewal.php';
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
		return __( 'Thanks again for choosing {site_title}.', 'woocommerce-subscriptions' );
	}

}
