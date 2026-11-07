<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Customer Cancelled Subscription Email
 *
 * An email sent to the customer when their subscription is cancelled or set to pending cancellation. It is sent again
 * if a subscription that is pending cancellation then ends before its end date.
 *
 * @class   WCS_Email_Customer_Cancelled_Subscription
 * @version 9.4.0
 * @package WooCommerce_Subscriptions/Classes/Emails
 */
class WCS_Email_Customer_Cancelled_Subscription extends WC_Email {

	/**
	 * A pending-cancel subscription that ends within this many seconds of its end date ends on time.
	 *
	 * This allows for a gateway that ends the subscription itself, with a clock that differs a little from the store's.
	 */
	private const EARLY_END_MARGIN = 3600;

	/**
	 * Create an instance of the class.
	 */
	public function __construct() {
		$this->id             = 'customer_cancelled_subscription';
		$this->customer_email = true;
		$this->title          = __( 'Customer Cancelled Subscription', 'woocommerce-subscriptions' );
		$this->description    = __( 'Sent to the customer when their subscription is cancelled or set to pending cancellation, and again if it then ends before its end date. It states when the cancellation was received and when the subscription ends.', 'woocommerce-subscriptions' );

		$this->template_html  = 'emails/customer-cancelled-subscription.php';
		$this->template_plain = 'emails/plain/customer-cancelled-subscription.php';
		$this->template_base  = WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' );

		add_action( 'cancelled_subscription_notification', [ $this, 'trigger' ], 10, 3 );

		parent::__construct();
	}

	/**
	 * Get the default email subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} subscription has been cancelled', 'woocommerce-subscriptions' );
	}

	/**
	 * Get the default email heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Subscription cancelled', 'woocommerce-subscriptions' );
	}

	/**
	 * Send the email if the status change is a cancellation the customer should hear about.
	 *
	 * @param WC_Subscription|mixed $subscription The subscription whose status changed.
	 * @param string|mixed          $new_status   The new status.
	 * @param string|mixed          $old_status   The old status.
	 *
	 * @return void
	 */
	public function trigger( $subscription, $new_status = '', $old_status = '' ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		if ( ! $this->is_cancellation_to_confirm( $subscription, (string) $new_status, (string) $old_status ) ) {
			return;
		}

		// Set the object before is_enabled(), so that callbacks on its filter get the subscription.
		$this->object    = $subscription;
		$this->recipient = $subscription->get_billing_email();

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->setup_locale();

		try {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		} finally {
			$this->restore_locale();
		}
	}

	/**
	 * Decide if a status change is a cancellation that the customer should get an email about.
	 *
	 * That is a live subscription being cancelled or set to pending cancellation, or a pending-cancel subscription
	 * ending before its end date.
	 *
	 * @param WC_Subscription $subscription The subscription whose status changed.
	 * @param string          $new_status   The new status.
	 * @param string          $old_status   The old status.
	 *
	 * @return bool
	 */
	private function is_cancellation_to_confirm( WC_Subscription $subscription, string $new_status, string $old_status ): bool {
		// The status may have changed again since this status change, for example in an earlier callback.
		if ( '' === $new_status || ! $subscription->has_status( $new_status ) ) {
			return false;
		}

		// update_status() sets both dates on every cancellation. A status set without it, for example through the
		// status field of the REST API, has no dates for the email to state.
		if ( ! $subscription->get_time( 'cancelled' ) || ! $subscription->get_time( 'end' ) ) {
			return false;
		}

		if ( in_array( $old_status, [ 'active', 'on-hold' ], true ) ) {
			return in_array( $new_status, [ 'pending-cancel', 'cancelled' ], true );
		}

		return 'pending-cancel' === $old_status
			&& 'cancelled' === $new_status
			&& WC_Subscriptions_Email::get_end_time_before_cancellation( $subscription->get_id() ) > time() + self::EARLY_END_MARGIN;
	}

	/**
	 * Get the content for the HTML version of the email.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->get_template_args( false ),
			'',
			$this->template_base
		);
	}

	/**
	 * Get the content for the plain text version of the email.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_template_args( true ),
			'',
			$this->template_base
		);
	}

	/**
	 * Get the arguments for the email templates.
	 *
	 * @param bool $plain_text Whether the arguments are for the plain text template.
	 *
	 * @return array
	 */
	private function get_template_args( bool $plain_text ): array {
		return [
			'subscription'       => $this->object,
			'date_received'      => $this->get_formatted_date_time( 'cancelled' ),
			'end_date'           => $this->get_formatted_date_time( 'end' ),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		];
	}

	/**
	 * Get one of the subscription's dates, with its time, in the site's timezone and format.
	 *
	 * @param string $date_type The date type, for example 'cancelled' or 'end'.
	 *
	 * @return string An empty string if the subscription does not have the date.
	 */
	private function get_formatted_date_time( string $date_type ): string {
		$time = $this->object instanceof WC_Subscription ? $this->object->get_time( $date_type, 'site' ) : 0;

		if ( ! $time ) {
			return '';
		}

		return sprintf(
			/* translators: 1: date, 2: time. */
			_x( '%1$s at %2$s', 'date and time', 'woocommerce-subscriptions' ),
			date_i18n( wc_date_format(), $time ),
			date_i18n( wc_time_format(), $time )
		);
	}

	/**
	 * Initialise the settings form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		// Off by default: stores that already send their own cancellation email would otherwise send two.
		$this->form_fields['enabled']['default'] = 'no';
	}
}
