<?php
/**
 * E-mails: New initial order.
 *
 * @package WooCommerce Subscriptions Gifting/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles e-mailing of the "New Initial Order" e-mail to recipients.
 */
class WCSG_Email_Recipient_New_Initial_Order extends WC_Email {

	/**
	 * Subscription owner name.
	 *
	 * @var string
	 */
	public $subscription_owner;

	/**
	 * Array of subscription post objects.
	 *
	 * @var WP_Post[]
	 */
	public $subscriptions;

	/**
	 * Recipient user ID.
	 *
	 * @var int
	 */
	public $wcsg_sending_recipient_email;

	/**
	 * Recipient user.
	 *
	 * @var WP_User
	 */
	public $recipient_user;

	/**
	 * Create an instance of the class.
	 */
	public function __construct() {

		$this->id             = 'recipient_completed_order';
		$this->title          = __( 'New Initial Order - Recipient', 'woocommerce-subscriptions' );
		$this->description    = __( 'This email is sent to recipients notifying them of subscriptions purchased for them.', 'woocommerce-subscriptions' );
		$this->customer_email = true;

		$this->template_html  = 'emails/recipient-new-initial-order.php';
		$this->template_plain = 'emails/plain/recipient-new-initial-order.php';
		$this->template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/';

		// Trigger for this email.
		add_action( 'wcsg_new_order_recipient_notification', array( $this, 'trigger' ), 10, 2 );

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
		return __( 'Your new subscriptions at {site_title}', 'woocommerce-subscriptions' );
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
	 * @return string
	 */
	public function get_default_heading( $paid = false ) {
		return __( 'New Order', 'woocommerce-subscriptions' );
	}

	/**
	 * Trigger function.
	 *
	 * @param int       $recipient_user          User ID.
	 * @param WP_Post[] $recipient_subscriptions Array of subscription post objects.
	 */
	public function trigger( $recipient_user, $recipient_subscriptions ) {

		if ( $recipient_user ) {
			$this->recipient_user     = get_user_by( 'id', $recipient_user );
			$this->recipient          = stripslashes( $this->recipient_user->user_email );
			$subscription             = wcs_get_subscription( $recipient_subscriptions[0] );
			$this->subscription_owner = WCS_Gifting::get_user_display_name( $subscription->get_user_id() );
			$this->subscriptions      = $recipient_subscriptions;
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->wcsg_sending_recipient_email = $recipient_user;
		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		unset( $this->wcsg_sending_recipient_email );
	}

	/**
	 * Returns the content for the HTML version of the e-mail.
	 */
	public function get_content_html() {
		// Handle the email preview.
		if ( empty( $this->subscription_owner ) ) {
			$this->set_preview_data();
		}

		ob_start();
		wc_get_template(
			$this->template_html,
			array(
				'email_heading'          => $this->get_heading(),
				'blogname'               => $this->get_blogname(),
				'recipient_user'         => $this->recipient_user,
				'subscription_purchaser' => $this->subscription_owner,
				'subscriptions'          => $this->subscriptions,
				'sent_to_admin'          => false,
				'plain_text'             => false,
				'email'                  => $this,
				'additional_content'     => $this->get_additional_content(),
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}

	/**
	 * Returns the content for the plain text version of the e-mail.
	 */
	public function get_content_plain() {
		// Handle the email preview.
		if ( empty( $this->subscription_owner ) ) {
			$this->set_preview_data();
		}

		ob_start();
		wc_get_template(
			$this->template_plain,
			array(
				'email_heading'          => $this->get_heading(),
				'blogname'               => $this->get_blogname(),
				'recipient_user'         => $this->object,
				'subscription_purchaser' => $this->subscription_owner,
				'subscriptions'          => $this->subscriptions,
				'sent_to_admin'          => false,
				'plain_text'             => true,
				'email'                  => $this,
				'additional_content'     => $this->get_additional_content(),
			),
			'',
			$this->template_base
		);

		return ob_get_clean();
	}

	/**
	 * Set WooCommerce email preview data.
	 */
	public function set_preview_data() {
		$this->subscription_owner = WCS_Gifting::get_user_display_name( $this->subscriptions[0]->get_user_id() );
	}
}
