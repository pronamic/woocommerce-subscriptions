<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Customer Invoice
 *
 * An email sent to the customer via admin.
 *
 * @class 		WC_Email_Customer_Invoice
 * @version		2.0.0
 * @package		WooCommerce/Classes/Emails
 * @author 		WooThemes
 * @extends 	WC_Email
 */
class WCS_Email_Customer_Renewal_Invoice extends WC_Email_Customer_Invoice {

	var $find;
	var $replace;

	/**
	 * Constructor
	 */
	function __construct() {

		$this->id             = 'customer_renewal_invoice';
		$this->title          = __( 'Customer Renewal Invoice', 'woocommerce-subscriptions' );
		$this->description    = __( 'Sent to a customer when the subscription is due for renewal and the renewal requires a manual payment, either because it uses manual renewals or the automatic recurring payment failed. The email contains renewal order information and payment links.', 'woocommerce-subscriptions' );
		$this->customer_email = true;

		$this->template_html  = 'emails/customer-renewal-invoice.php';
		$this->template_plain = 'emails/plain/customer-renewal-invoice.php';
		$this->template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/';

		$this->subject        = __( 'Invoice for renewal order {order_number} from {order_date}', 'woocommerce-subscriptions' );
		$this->heading        = __( 'Invoice for renewal order {order_number}', 'woocommerce-subscriptions' );

		$this->subject_paid   = __( 'Your {blogname} renewal order from {order_date}', 'woocommerce-subscriptions' );
		$this->heading_paid   = __( 'Renewal order {order_number} details', 'woocommerce-subscriptions' );

		// Triggers for this email
		add_action( 'woocommerce_generated_manual_renewal_order_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_renewal_notification', array( $this, 'trigger' ) );

		// We want all the parent's methods, with none of its properties, so call its parent's constructor, rather than my parent constructor
		WC_Email::__construct();
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
	function trigger( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( absint( $order ) );
		}

		if ( $order ) {
			$this->object    = $order;
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
		return apply_filters( 'woocommerce_subscriptions_email_subject_new_renewal_order', parent::get_subject(), $this->object );
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

	/**
	 * Initialise Settings Form Fields, but add an enable/disable field
	 * to this email as WC doesn't include that for customer Invoices.
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {

		parent::init_form_fields();

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
