<?php
/**
 * An instance of a failed payment retry rule.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  WCS_Retry_Rule
 * @category    Class
 * @author      Prospress
 * @since       2.1
 */

class WCS_Retry_Rule {

	/* the rule_data that control the retry schedule and behaviour of each retry */
	protected $rule_data = array();

	/**
	 * Set up the retry rules
	 *
	 * @since 2.1
	 */
	public function __construct( $rule_data ) {
		foreach ( $rule_data as $rule_key => $rule_value ) {
			$this->rule_data[ $rule_key ] = $rule_value;
		}
	}

	/**
	 * Get the time to wait between when this rule is applied (i.e. payment failed) and the retry
	 * should be processed.
	 *
	 * @return int
	 * @since 2.1
	 */
	public function get_retry_interval() {
		return ( isset( $this->rule_data['retry_after_interval'] ) ) ? $this->rule_data['retry_after_interval'] : 0;
	}

	/**
	 * Check if this rule has an email template defined for sending to a specified recipient.
	 *
	 * @param string $recipient The email type based on recipient, either 'customer' or 'admin'
	 * @return bool
	 * @since 2.1
	 */
	public function has_email_template( $recipient = 'customer' ) {
		return isset( $this->rule_data[ 'email_template_' . $recipient ] ) && ! empty( $this->rule_data[ 'email_template_' . $recipient ] );
	}

	/**
	 * Get the email template this rule defined for sending to a specified recipient.
	 *
	 * @param string $recipient The email type based on recipient, either 'customer' or 'admin'
	 * @return string
	 * @since 2.1
	 */
	public function get_email_template( $recipient = 'customer' ) {

		if ( $this->has_email_template( $recipient ) ) {
			$email_template = $this->rule_data[ 'email_template_' . $recipient ];
		} else {
			$email_template = '';
		}

		return $email_template;
	}

	/**
	 * Get the status to apply to one of the related objects when this rule is applied.
	 *
	 * @param string $object The object type the status should be applied to, either 'order' or 'subscription'
	 * @return string
	 * @since 2.1
	 */
	public function get_status_to_apply( $object = 'order' ) {

		if ( isset( $this->rule_data[ 'status_to_apply_to_' . $object ] ) ) {
			$status = $this->rule_data[ 'status_to_apply_to_' . $object ];
		} else {
			$status = '';
		}

		return $status;
	}

	/**
	 * Get rule data as a raw array.
	 *
	 * @return array
	 * @since 2.1
	 */
	public function get_raw_data() {
		return $this->rule_data;
	}
}
