<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Subscription Switched Email
 *
 * An email sent to the admin when a customer switches their subscription.
 *
 * @class 		WCS_Email_New_Switch_Order
 * @version		1.5
 * @extends 	WC_Email_New_Order
 */
class WCS_Email_New_Switch_Order extends WC_Email_New_Order {

	/**
	 * Constructor
	 */
	function __construct() {

		$this->id             = 'new_switch_order';
		$this->title          = __( 'Subscription Switched', 'woocommerce-subscriptions' );
		$this->description    = __( 'Subscription switched emails are sent when a customer switches a subscription.', 'woocommerce-subscriptions' );

		$this->heading        = __( 'Subscription Switched', 'woocommerce-subscriptions' );
		$this->subject        = __( '[{blogname}] Subscription Switched ({order_number}) - {order_date}', 'woocommerce-subscriptions' );

		$this->template_html  = 'emails/admin-new-switch-order.php';
		$this->template_plain = 'emails/plain/admin-new-switch-order.php';
		$this->template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/';

		// Triggers for this email
		add_action( 'woocommerce_order_status_pending_to_processing_switch_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_pending_to_completed_switch_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_pending_to_on-hold_switch_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_processing_switch_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_completed_switch_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_on-hold_switch_notification', array( $this, 'trigger' ) );

		// We want all the parent's methods, with none of its properties, so call its parent's constructor, rather than my parent constructor
		WC_Email::__construct();

		// Other settings
		$this->recipient = $this->get_option( 'recipient' );

		if ( ! $this->recipient ) {
			$this->recipient = get_option( 'admin_email' );
		}
	}

	/**
	 * trigger function.
	 *
	 * We need to override WC_Email_New_Order's trigger method because it expects to be run only once
	 * per request.
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $order_id ) {

		if ( $order_id ) {
			$this->object = new WC_Order( $order_id );

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

			$this->subscriptions = wcs_get_subscriptions_for_switch_order( $this->object );
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
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
				'subscriptions' => $this->subscriptions,
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
				'subscriptions' => $this->subscriptions,
				'email_heading' => $this->get_heading(),
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}
}
