<?php
/**
 * E-mails: Processing renewal order.
 *
 * @package WooCommerce Subscriptions Gifting/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles e-mailing of the "Processing Renewal Order" e-mail to recipients.
 */
class WCSG_Email_Processing_Renewal_Order extends WCS_Email_Processing_Renewal_Order {

	/**
	 * Recipient user ID.
	 *
	 * @var int
	 */
	public $wcsg_sending_recipient_email;

	/**
	 * Create an instance of the class.
	 */
	public function __construct() {

		$this->id             = 'gift_recipient_processing_renewal_order';
		$this->title          = __( 'Processing Renewal Order - Recipient', 'woocommerce-subscriptions' );
		$this->description    = __( 'This is an order notification sent to the recipient after payment for a subscription renewal order is completed. It contains the renewal order details.', 'woocommerce-subscriptions' );
		$this->customer_email = true;

		$this->template_html  = 'emails/recipient-processing-renewal-order.php';
		$this->template_plain = 'emails/plain/recipient-processing-renewal-order.php';
		$this->template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/';

		add_action( 'woocommerce_order_status_pending_to_processing_renewal_notification_recipient', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_pending_to_on-hold_renewal_notification_recipient', array( $this, 'trigger' ) );

		WC_Email::__construct();
	}

	/**
	 * Get the default e-mail subject.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
	 * @return string
	 */
	public function get_default_subject( $paid = false ) {
		return __( 'Your {blogname} renewal order receipt from {order_date}', 'woocommerce-subscriptions' );
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
	 * @return string
	 */
	public function get_default_heading( $paid = false ) {
		return __( 'Thank you for your order', 'woocommerce-subscriptions' );
	}


	/**
	 * Trigger function.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object.
	 */
	public function trigger( $order_id, $order = null ) {

		$recipient_id = null;

		if ( $order_id ) {
			$this->object    = wc_get_order( $order_id );
			$subscriptions   = wcs_get_subscriptions_for_renewal_order( $order_id );
			$subscriptions   = array_values( $subscriptions );
			$recipient_id    = WCS_Gifting::get_recipient_user( wcs_get_subscription( $subscriptions[0] ) );
			$this->recipient = get_user_by( 'id', $recipient_id )->user_email;
		}

		$order_date_index = array_search( '{order_date}', $this->find, true );
		$date_format      = is_callable( 'wc_date_format' ) ? wc_date_format() : woocommerce_date_format();
		$order_date_time  = is_callable( array( $this->object, 'get_date_created' ) ) ? $this->object->get_date_created()->getTimestamp() : strtotime( $this->object->order_date );

		if ( false === $order_date_index ) {
			$this->find[]    = '{order_date}';
			$this->replace[] = date_i18n( $date_format, $order_date_time );
		} else {
			$this->replace[ $order_date_index ] = date_i18n( $date_format, $order_date_time );
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->wcsg_sending_recipient_email = $recipient_id;
		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		unset( $this->wcsg_sending_recipient_email );
	}
}
