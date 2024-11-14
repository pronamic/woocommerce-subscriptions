<?php
/**
 * Manage the emails sent as part of the retry process
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  WCS_Retry_Email
 * @category    Class
 * @author      Prospress
 * @since       2.1
 */

class WCS_Retry_Email {

	/* a property to cache the order ID when detaching/reattaching default emails in favour of retry emails */
	protected static $removed_emails_for_order_id;

	/**
	 * Attach callbacks and set the retry rules
	 *
	 * @since 2.1
	 */
	public static function init() {
		add_action( 'woocommerce_email_classes', array( __CLASS__, 'add_emails' ), 12, 1 );

		add_action( 'woocommerce_subscriptions_after_apply_retry_rule', array( __CLASS__, 'send_email' ), 0, 2 );

		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'maybe_detach_email' ), 9 );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_reattach_email' ), 100, 3 );
	}

	/**
	 * Add default retry email classes to the available WooCommerce emails
	 *
	 * @since 2.1
	 */
	public static function add_emails( $email_classes ) {
		$email_classes['WCS_Email_Customer_Payment_Retry'] = new WCS_Email_Customer_Payment_Retry();
		$email_classes['WCS_Email_Payment_Retry']          = new WCS_Email_Payment_Retry();

		return $email_classes;
	}

	/**
	 * After a retry rule has been applied, send relevant emails for that rule.
	 *
	 * Attached to 'woocommerce_subscriptions_after_apply_retry_rule' with a low priority.
	 *
	 * @param WCS_Retry_Rule $retry_rule The retry rule applied.
	 * @param WC_Order $last_order       The order to which the retry rule was applied.
	 * @since 2.1
	 */
	public static function send_email( $retry_rule, $last_order ) {
		WC()->mailer();

		// maybe send emails about the renewal payment failure
		foreach ( array( 'customer', 'admin' ) as $recipient ) {
			if ( $retry_rule->has_email_template( $recipient ) ) {
				$email_class = $retry_rule->get_email_template( $recipient );
				if ( class_exists( $email_class ) ) {
					$email = new $email_class();
					$email->trigger( $last_order->get_id(), $last_order );
				}
			}
		}
	}

	/**
	 * Don't send the renewal order invoice email to the customer or failed order email to the admin
	 * when a payment fails if there are retry rules to apply as they define which email/s to send.
	 *
	 * @since 2.1
	 */
	public static function maybe_detach_email( $order_id ) {

		// We only want to detach the email if there is a retry
		if ( wcs_order_contains_renewal( $order_id ) && WCS_Retry_Manager::rules()->has_rule( WCS_Retry_Manager::store()->get_retry_count_for_order( $order_id ), $order_id ) ) {

			// Remove email sent to customer email, which is sent by Subscriptions, which already removes the WooCommerce equivalent email
			remove_action( 'woocommerce_order_status_failed', 'WC_Subscriptions_Email::send_renewal_order_email', 10 );

			// Remove email sent to admin, which is sent by WooCommerce
			WC_Subscriptions_Email::detach_woocommerce_transactional_email( 'woocommerce_order_status_pending_to_failed' );
			WC_Subscriptions_Email::detach_woocommerce_transactional_email( 'woocommerce_order_status_on-hold_to_failed' );

			self::$removed_emails_for_order_id = $order_id;
		}
	}

	/**
	 * Check if we removed emails for a given order, and if we did, reattach them to the corresponding hooks
	 *
	 * @since 2.1
	 */
	public static function maybe_reattach_email( $order_id, $old_status, $new_status ) {

		if ( 'failed' === $new_status && $order_id == self::$removed_emails_for_order_id ) {

			// Reattach email sent to customer email by Subscriptions, but only reattach it once
			add_action( 'woocommerce_order_status_failed', 'WC_Subscriptions_Email::send_renewal_order_email' );

			// Reattach email sent to admin, which is sent by WooCommerce
			WC_Subscriptions_Email::attach_woocommerce_transactional_email( 'woocommerce_order_status_pending_to_failed' );
			WC_Subscriptions_Email::attach_woocommerce_transactional_email( 'woocommerce_order_status_on-hold_to_failed' );

			self::$removed_emails_for_order_id = null;
		}
	}
}
