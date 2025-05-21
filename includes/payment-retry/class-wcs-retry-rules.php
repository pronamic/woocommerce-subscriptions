<?php
/**
 * Setup the rules for retrying failed automatic renewal payments and provide methods for working with them.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  WCS_Retry_Rules
 * @category    Class
 * @author      Prospress
 * @since       2.1
 */

class WCS_Retry_Rules {

	/* the class used to instantiate an individual retry rule */
	protected $retry_rule_class;

	/* the rules that control the retry schedule and behaviour of each retry */
	protected $default_retry_rules = array();

	/**
	 * Set up the retry rules
	 *
	 * @since 2.1
	 */
	public function __construct() {

		$this->retry_rule_class = apply_filters( 'wcs_retry_rule_class', 'WCS_Retry_Rule' );

		$this->default_retry_rules = apply_filters( 'wcs_default_retry_rules', array(
			array(
				'retry_after_interval'            => DAY_IN_SECONDS / 2, // how long to wait before retrying
				'email_template_customer'         => '', // don't bother the customer yet
				'email_template_admin'            => 'WCS_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => DAY_IN_SECONDS / 2,
				'email_template_customer'         => 'WCS_Email_Customer_Payment_Retry',
				'email_template_admin'            => 'WCS_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => DAY_IN_SECONDS,
				'email_template_customer'         => '', // avoid spamming the customer by not sending them an email this time either
				'email_template_admin'            => 'WCS_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => DAY_IN_SECONDS * 2,
				'email_template_customer'         => 'WCS_Email_Customer_Payment_Retry',
				'email_template_admin'            => 'WCS_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => DAY_IN_SECONDS * 3,
				'email_template_customer'         => 'WCS_Email_Customer_Payment_Retry',
				'email_template_admin'            => 'WCS_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
		) );
	}

	/**
	 * Check if a retry rule exists for a certain stage of the retry process.
	 *
	 * @param int $retry_number The retry queue position to check for a rule
	 * @param int $order_id The ID of a WC_Order object to which the failed payment relates
	 * @return bool
	 * @since 2.1
	 */
	public function has_rule( $retry_number, $order_id ) {
		return null !== $this->get_rule( $retry_number, $order_id );
	}

	/**
	 * Get an instance of a retry rule for a given order and stage of the retry queue (if any).
	 *
	 * @param int $retry_number The retry queue position to check for a rule
	 * @param int $order_id The ID of a WC_Order object to which the failed payment relates
	 * @return null|WCS_Retry_Rule If a retry rule exists for this stage of the retry queue and order, WCS_Retry_Rule, otherwise null.
	 * @since 2.1
	 */
	public function get_rule( $retry_number, $order_id ) {

		$rule = null;

		$rule_array = ( isset( $this->default_retry_rules[ $retry_number ] ) ) ? $this->default_retry_rules[ $retry_number ] : array();
		$rule_array = apply_filters( 'wcs_get_retry_rule_raw', $rule_array, $retry_number, $order_id );

		if ( ! empty( $rule_array ) ) {
			$rule = new $this->retry_rule_class( $rule_array );
		}

		return apply_filters( 'wcs_get_retry_rule', $rule, $retry_number, $order_id );
	}

	/**
	 * Get the PHP class used ti instaniate a set of raw retry rule data.
	 *
	 * @since 2.1
	 */
	public function get_rule_class() {
		return $this->retry_rule_class;
	}
}
