<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Customer Invoice
 *
 * An email sent to the customer via admin.
 *
 * @class WCS_Email_Customer_Renewal_Invoice
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v1.4
 * @package WooCommerce_Subscriptions/Includes/Emails
 * @author Prospress
 */
class WCS_Email_Customer_Renewal_Invoice extends WC_Email_Customer_Invoice {

	/**
	 * Strings to find in subjects/headings.
	 * @var array
	 */
	public $find = array();

	/**
	 * Strings to replace in subjects/headings.
	 * @var array
	 */
	public $replace = array();

	// fields used in WC_Email_Customer_Invoice this class doesn't need
	var $subject_paid = null;
	var $heading_paid = null;

	/**
	 * Constructor
	 */
	function __construct() {

		$this->id             = 'customer_renewal_invoice';
		$this->title          = __( 'Customer Renewal Invoice', 'woocommerce-subscriptions' );
		$this->description    = __( 'Sent to a customer when the subscription is due for renewal and the renewal requires a manual payment, either because it uses manual renewals or the automatic recurring payment failed for the initial attempt and all automatic retries (if any). The email contains renewal order information and payment links.', 'woocommerce-subscriptions' );
		$this->customer_email = true;

		$this->template_html  = 'emails/customer-renewal-invoice.php';
		$this->template_plain = 'emails/plain/customer-renewal-invoice.php';
		$this->template_base  = WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' );

		// Triggers for this email
		add_action( 'woocommerce_generated_manual_renewal_order_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_renewal_notification', array( $this, 'trigger' ) );

		// We want all the parent's methods, with none of its properties, so call its parent's constructor, rather than my parent constructor
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
		return __( 'Invoice for renewal order {order_number} from {order_date}', 'woocommerce-subscriptions' );
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
	 * @return string
	 */
	public function get_default_heading( $paid = false ) {
		return __( 'Invoice for renewal order {order_number}', 'woocommerce-subscriptions' );
	}

	/**
	 * trigger function.
	 *
	 * We need to override WC_Email_Customer_Invoice's trigger method because it expects to be run only once
	 * per request (but multiple subscription renewal orders can be generated per request).
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $order_id, $order = null ) {

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object    = $order;
			$this->recipient = wcs_get_objects_property( $this->object, 'billing_email' );

			$order_date_index = array_search( '{order_date}', $this->find );
			if ( false === $order_date_index ) {
				$this->find['order_date']    = '{order_date}';
				$this->replace['order_date'] = wcs_format_datetime( wcs_get_objects_property( $this->object, 'date_created' ) );
			} else {
				$this->replace[ $order_date_index ] = wcs_format_datetime( wcs_get_objects_property( $this->object, 'date_created' ) );
			}

			$order_number_index = array_search( '{order_number}', $this->find );
			if ( false === $order_number_index ) {
				$this->find['order_number']    = '{order_number}';
				$this->replace['order_number'] = $this->object->get_order_number();
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
		return apply_filters( 'woocommerce_subscriptions_email_subject_new_renewal_order', $this->format_string( $this->get_option( 'subject', $this->get_default_subject() ) ), $this->object );
	}

	/**
	 * get_heading function.
	 *
	 * @access public
	 * @return string
	 */
	function get_heading() {
		return apply_filters( 'woocommerce_email_heading_customer_renewal_order', $this->format_string( $this->get_option( 'heading', $this->get_default_heading() ) ), $this->object );
	}

	/**
	 * get_content_html function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_html() {
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
	 * get_content_plain function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_plain() {
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

	/**
	 * Initialise Settings Form Fields, but add an enable/disable field
	 * to this email as WC doesn't include that for customer Invoices.
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {

		parent::init_form_fields();

		if ( isset( $this->form_fields['heading_paid'] ) ) {
			unset( $this->form_fields['heading_paid'] );
		}

		if ( isset( $this->form_fields['subject_paid'] ) ) {
			unset( $this->form_fields['subject_paid'] );
		}

		$this->form_fields = array_merge(
			array(
				'enabled' => array(
					'title'   => _x( 'Enable/Disable', 'an email notification', 'woocommerce-subscriptions' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'woocommerce-subscriptions' ),
					'default' => 'yes',
				),
			),
			$this->form_fields
		);
	}
}
