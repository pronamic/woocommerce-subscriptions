<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Customer On Hold Renewal Order Email.
 *
 * Order On Hold emails are sent to the customer when the renewal order is marked on-hold and usually indicates that the order is awaiting payment confirmation.
 *
 * @class   WCS_Email_On-hold_Renewal_Order
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.0
 * @package WooCommerce_Subscriptions/Includes/Emails
 * @author  WooCommerce.
 */
class WCS_Email_Customer_On_Hold_Renewal_Order extends WC_Email_Customer_On_Hold_Order {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'customer_on_hold_renewal_order';
		$this->customer_email = true;
		$this->title          = __( 'On-hold Renewal Order', 'woocommerce-subscriptions' );
		$this->description    = __( 'This is an order notification sent to customers containing order details after a renewal order is placed on-hold.', 'woocommerce-subscriptions' );
		$this->subject        = __( 'Your {site_title} renewal order has been received!', 'woocommerce-subscriptions' );
		$this->heading        = __( 'Thank you for your renewal order', 'woocommerce-subscriptions' );
		$this->template_html  = 'emails/customer-on-hold-renewal-order.php';
		$this->template_plain = 'emails/plain/customer-on-hold-renewal-order.php';
		$this->template_base  = WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' );
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		// Triggers for this email.
		add_action( 'woocommerce_order_status_pending_to_on-hold_renewal_notification', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed_to_on-hold_renewal_notification', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled_to_on-hold_renewal_notification', array( $this, 'trigger' ), 10, 2 );

		// We want most of the parent's methods, with none of its properties, so call its parent's constructor
		WC_Email::__construct();
	}

	/**
	 * Get the default e-mail subject.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.0
	 * @return string
	 */
	public function get_default_subject() {
		return $this->subject;
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.0
	 * @return string
	 */
	public function get_default_heading() {
		return $this->heading;
	}

	/**
	 * Get content html.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Get content plain.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}
}
