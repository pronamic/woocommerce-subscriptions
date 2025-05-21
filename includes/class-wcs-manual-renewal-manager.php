<?php
/**
 * A class for managing the manual renewal feature.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Manual_Renewal_Manager {

	/**
	 * Initalise the class and attach callbacks.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ), 5 );
	}

	/**
	 * Adds the manual renewal settings.
	 *
	 * @since 4.0.0
	 * @param $settings The full subscription settings array.
	 * @return array
	 */
	public static function add_settings( $settings ) {

		$manual_renewal_settings = array(
			array(
				'name' => _x( 'Renewals', 'option section heading', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'desc' => '',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_renewal_options',
			),
			array(
				'name'            => __( 'Manual Renewal Payments', 'woocommerce-subscriptions' ),
				'desc'            => __( 'Accept Manual Renewals', 'woocommerce-subscriptions' ),
				'id'              => WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals',
				'default'         => 'no',
				'type'            => 'checkbox',
				// translators: placeholders are opening and closing link tags
				'desc_tip'        => sprintf( __( 'With manual renewals, a customer\'s subscription is put on-hold until they login and pay to renew it. %1$sLearn more%2$s.', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/document/subscriptions/store-manager-guide/#accept-manual-renewals">', '</a>' ),
				'checkboxgroup'   => 'start',
				'show_if_checked' => 'option',
			),

			array(
				'desc'            => __( 'Turn off Automatic Payments', 'woocommerce-subscriptions' ),
				'id'              => WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments',
				'default'         => 'no',
				'type'            => 'checkbox',
				// translators: placeholders are opening and closing link tags
				'desc_tip'        => sprintf( __( 'If you don\'t want new subscription purchases to automatically charge renewal payments, you can turn off automatic payments. Existing automatic subscriptions will continue to charge customers automatically. %1$sLearn more%2$s.', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/document/subscriptions/store-manager-guide/#turn-off-automatic-payments">', '</a>' ),
				'checkboxgroup'   => 'end',
				'show_if_checked' => 'yes',
			),

			array(
				'type' => 'sectionend',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_renewal_options',
			),
		);

		if ( ! WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Admin::$option_prefix . '_role_options', $manual_renewal_settings, 'multiple_settings', 'sectionend' ) ) {
			$settings = array_merge( $settings, $manual_renewal_settings );
		}

		return $settings;
	}

	/**
	 * Checks if manual renewals are required - automatic renewals are disabled.
	 *
	 * @since 4.0.0
	 * @return bool Weather manual renewal is required.
	 */
	public static function is_manual_renewal_required() {
		return 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' );
	}

	/**
	 * Checks if manual renewals are enabled.
	 *
	 * @since 4.0.0
	 * @return bool Weather manual renewal is enabled.
	 */
	public static function is_manual_renewal_enabled() {
		return 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no' );
	}
}
