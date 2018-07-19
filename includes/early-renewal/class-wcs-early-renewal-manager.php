<?php
/**
 * Subscription Early Renewal Manager Class
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Early_Renewal
 * @category   Class
 * @since      2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Early_Renewal_Manager {

	/**
	 * The early renewal enabled setting ID.
	 *
	 * @var string
	 */
	protected static $setting_id;

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 2.3.0
	 */
	public static function init() {
		self::$setting_id = WC_Subscriptions_Admin::$option_prefix . '_enable_early_renewal';

		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ) );
	}

	/**
	 * Add a setting to enable/disable the early renewal feature.
	 *
	 * @since 2.3.0
	 * @param array Settings array.
	 * @return array
	 */
	public static function add_settings( $settings ) {
		WC_Subscriptions_Admin::insert_setting_after( $settings, 'woocommerce_subscriptions_turn_off_automatic_payments', array(
			'id'       => self::$setting_id,
			'name'     => __( 'Early Renewal', 'woocommerce-subscriptions' ),
			'desc'     => __( 'Accept Early Renewal Payments', 'woocommerce-subscriptions' ),
			'desc_tip' => __( 'With early renewals enabled, customers can renew their subscriptions before the next payment date.', 'woocommerce-subscriptions' ),
			'default'  => 'no',
			'type'     => 'checkbox',
		) );

		return $settings;
	}

	/**
	 * A helper function to check if the early renewal feature is enabled or not.
	 *
	 * If the setting hasn't been set yet, by default it is off for existing stores and on for new stores.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_early_renewal_enabled() {
		$enabled = get_option( self::$setting_id );

		if ( false === $enabled ) {
			$enabled = wcs_do_subscriptions_exist() ? 'no' : 'yes';
			update_option( self::$setting_id, $enabled );
		}

		return apply_filters( 'wcs_is_early_renewal_enabled', 'yes' === $enabled );
	}
}
