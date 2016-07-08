<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Cancelled Subscription Email
 *
 * An email sent to the admin when a subscription is cancelled (either by a store manager, or the customer).
 *
 * @class 	WCS_Email_Cancelled_Subscription
 * @version	1.4
 * @package	WooCommerce_Subscriptions/Classes/Emails
 * @author 	Brent Shepherd
 * @extends WC_Email
 */
class WCS_Email_Cancelled_Subscription extends WC_Email {

	/**
	 * Create an instance of the class.
	 *
	 * @access public
	 * @return void
	 */
	function __construct() {

		$this->id          = 'cancelled_subscription';
		$this->title       = __( 'Cancelled Subscription', 'woocommerce-subscriptions' );
		$this->description = __( 'Cancelled Subscription emails are sent when a customer\'s subscription is cancelled (either by a store manager, or the customer).', 'woocommerce-subscriptions' );

		$this->heading     = __( 'Subscription Cancelled', 'woocommerce-subscriptions' );
		// translators: placeholder is {blogname}, a variable that will be substituted when email is sent out
		$this->subject     = sprintf( _x( '[%s] Subscription Cancelled', 'default email subject for cancelled emails sent to the admin', 'woocommerce-subscriptions' ), '{blogname}' );

		$this->template_html  = 'emails/cancelled-subscription.php';
		$this->template_plain = 'emails/plain/cancelled-subscription.php';
		$this->template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/';

		add_action( 'cancelled_subscription_notification', array( $this, 'trigger' ) );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient' );

		if ( ! $this->recipient ) {
			$this->recipient = get_option( 'admin_email' );
		}
	}

	/**
	 * trigger function.
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $subscription ) {
		$this->object = $subscription;

		if ( ! is_object( $subscription ) ) {
			_deprecated_argument( __METHOD__, '2.0', 'The subscription key is deprecated. Use a subscription post ID' );
			$subscription = wcs_get_subscription_from_key( $subscription );
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		update_post_meta( $subscription->id, '_cancelled_email_sent', 'true' );
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
				'subscription'      => $this->object,
				'email_heading'     => $this->get_heading(),
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
				'subscription'        => $this->object,
				'email_heading'       => $this->get_heading(),
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}

	/**
	 * Initialise Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'         => _x( 'Enable/Disable', 'an email notification', 'woocommerce-subscriptions' ),
				'type'          => 'checkbox',
				'label'         => __( 'Enable this email notification', 'woocommerce-subscriptions' ),
				'default'       => 'no',
			),
			'recipient' => array(
				'title'         => _x( 'Recipient(s)', 'of an email', 'woocommerce-subscriptions' ),
				'type'          => 'text',
				// translators: placeholder is admin email
				'description'   => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to <code>%s</code>.', 'woocommerce-subscriptions' ), esc_attr( get_option( 'admin_email' ) ) ),
				'placeholder'   => '',
				'default'       => '',
			),
			'subject' => array(
				'title'         => _x( 'Subject', 'of an email', 'woocommerce-subscriptions' ),
				'type'          => 'text',
				'description'   => sprintf( __( 'This controls the email subject line. Leave blank to use the default subject: <code>%s</code>.', 'woocommerce-subscriptions' ), $this->subject ),
				'placeholder'   => '',
				'default'       => '',
			),
			'heading' => array(
				'title'         => _x( 'Email Heading', 'Name the setting that controls the main heading contained within the email notification', 'woocommerce-subscriptions' ),
				'type'          => 'text',
				'description'   => sprintf( __( 'This controls the main heading contained within the email notification. Leave blank to use the default heading: <code>%s</code>.', 'woocommerce-subscriptions' ), $this->heading ),
				'placeholder'   => '',
				'default'       => '',
			),
			'email_type' => array(
				'title'         => _x( 'Email type', 'text, html or multipart', 'woocommerce-subscriptions' ),
				'type'          => 'select',
				'description'   => __( 'Choose which format of email to send.', 'woocommerce-subscriptions' ),
				'default'       => 'html',
				'class'         => 'email_type',
				'options'       => array(
					'plain'         => _x( 'Plain text', 'email type', 'woocommerce-subscriptions' ),
					'html'          => _x( 'HTML', 'email type', 'woocommerce-subscriptions' ),
					'multipart'     => _x( 'Multipart', 'email type', 'woocommerce-subscriptions' ),
				),
			),
		);
	}
}
