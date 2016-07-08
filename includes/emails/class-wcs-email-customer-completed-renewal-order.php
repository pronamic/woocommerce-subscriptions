<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Customer Completed Order Email
 *
 * Order complete emails are sent to the customer when the order is marked complete and usual indicates that the order has been shipped.
 *
 * @class 		WC_Email_Customer_Completed_Order
 * @version		2.0.0
 * @package		WooCommerce/Classes/Emails
 * @author 		WooThemes
 * @extends 	WC_Email
 */
class WCS_Email_Completed_Renewal_Order extends WC_Email_Customer_Completed_Order {

	/**
	 * Constructor
	 */
	function __construct() {

		// Call override values
		$this->id             = 'customer_completed_renewal_order';
		$this->title          = __( 'Completed Renewal Order', 'woocommerce-subscriptions' );
		$this->description    = __( 'Renewal order complete emails are sent to the customer when a subscription renewal order is marked complete and usually indicates that the item for that renewal period has been shipped.', 'woocommerce-subscriptions' );
		$this->customer_email = true;

		$this->heading        = _x( 'Your renewal order is complete', 'Default email heading for email to customer on completed renewal order', 'woocommerce-subscriptions' );
		// translators: $1: {blogname}, $2: {order_date}, variables that will be substituted when email is sent out
		$this->subject        = sprintf( _x( 'Your %1$s renewal order from %2$s is complete', 'Default email subject for email to customer on completed renewal order', 'woocommerce-subscriptions' ), '{blogname}', '{order_date}' );

		$this->template_html  = 'emails/customer-completed-renewal-order.php';
		$this->template_plain = 'emails/plain/customer-completed-renewal-order.php';
		$this->template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/';

		// Other settings
		$this->heading_downloadable = $this->get_option( 'heading_downloadable', _x( 'Your subscription renewal order is complete - download your files', 'Default email heading for email with downloadable files in it', 'woocommerce-subscriptions' ) );
		// translators: $1: {blogname}, $2: {order_date}, variables will be substituted when email is sent out
		$this->subject_downloadable = $this->get_option( 'subject_downloadable', sprintf( _x( 'Your %1$s subscription renewal order from %2$s is complete - download your files', 'Default email subject for email with downloadable files in it', 'woocommerce-subscriptions' ), '{blogname}', '{order_date}' ) );

		// Triggers for this email
		add_action( 'woocommerce_order_status_completed_renewal_notification', array( $this, 'trigger' ) );

		// We want most of the parent's methods, with none of its properties, so call its parent's constructor
		WC_Email::__construct();
	}

	/**
	 * trigger function.
	 *
	 * We need to override WC_Email_Customer_Completed_Order's trigger method because it expects to be run only once
	 * per request (but multiple subscription renewal orders can be generated per request).
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $order_id ) {

		if ( $order_id ) {
			$this->object    = new WC_Order( $order_id );
			$this->recipient = $this->object->billing_email;

			$order_date_index = array_search( '{order_date}', $this->find );
			if ( false === $order_date_index ) {
				$this->find[] = '{order_date}';
				$this->replace[] = date_i18n( wc_date_format(), strtotime( $this->object->order_date ) );
			} else {
				$this->replace[ $order_date_index ] = date_i18n( wc_date_format(), strtotime( $this->object->order_date ) );
			}

			$order_number_index = array_search( '{order_number}', $this->find );
			if ( false === $order_number_index ) {
				$this->find[] = '{order_number}';
				$this->replace[] = $this->object->get_order_number();
			} else {
				$this->replace[ $order_number_index ] = $this->object->get_order_number();
			}
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * get_subject function.
	 *
	 * @access public
	 * @return string
	 */
	function get_subject() {
		return apply_filters( 'woocommerce_subscriptions_email_subject_customer_completed_renewal_order', parent::get_subject(), $this->object );
	}

	/**
	 * get_heading function.
	 *
	 * @access public
	 * @return string
	 */
	function get_heading() {
		return apply_filters( 'woocommerce_email_heading_customer_renewal_order', parent::get_heading(), $this->object );
	}

	/**
	 * get_content_html function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_html() {
		ob_start();
		wc_get_template(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}

	/**
	 * get_content_plain function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_plain() {
		ob_start();
		wc_get_template(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}
}
