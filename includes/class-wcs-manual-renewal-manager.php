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
				'desc' => sprintf(
					/* translators: %1$s: a "Learn more" documentation link. */
					__( 'Configure renewal payment options for subscribers. %1$s', 'woocommerce-subscriptions' ),
					\Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings\Settings_Layout::learn_more_link( 'https://woocommerce.com/document/subscriptions/store-manager-guide/#renewals' )
				),
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_renewal_options',
			),
			array(
				'name'            => __( 'Manual Renewal Payments', 'woocommerce-subscriptions' ),
				'desc'            => __( 'Allow manual renewals at checkout', 'woocommerce-subscriptions' ),
				'id'              => WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals',
				'default'         => 'no',
				'type'            => 'checkbox',
				'class'           => \Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings\Classic_Renderer::CLASS_HIDE_CHECKBOX_TITLE,
				'desc_tip'        => __( 'Allow customers to pay via payment gateways that do not support recurring payments. Subscriptions with manual renewal will be placed on hold until the subscriber logs in and pays for the renewal.', 'woocommerce-subscriptions' ),
				'checkboxgroup'   => 'start',
				'show_if_checked' => 'option',
			),

			array(
				'desc'            => __( 'Turn off automatic payments', 'woocommerce-subscriptions' ),
				'id'              => WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments',
				'default'         => 'no',
				'type'            => 'checkbox',
				'desc_tip'        => __( 'Prevent automatic payment processing for new subscriptions. Existing subscriptions remain unchanged and will continue to renew automatically.', 'woocommerce-subscriptions' ),
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
	 * "Turn off automatic payments" only takes effect while manual renewals are accepted: forcing new
	 * subscriptions to manual renewal is a refinement of accepting manual renewal payments, not an
	 * independent mode. The two options themselves are stored independently - the settings form keeps
	 * them consistent (its save handler discards the child value when the parent is unchecked), while
	 * write paths that save one option at a time (the REST settings API, WP-CLI) may store any
	 * combination and are expected to manage their own consistency; a stored child value without the
	 * parent is simply inert here. Stores that held that combination before 9.2.0 - when this method
	 * read the child option alone - are normalized on upgrade to preserve their effective behavior:
	 * see WCS_Plugin_Upgrade_9_2_0::maybe_normalize_manual_renewal_options().
	 *
	 * @since 4.0.0
	 * @return bool Weather manual renewal is required.
	 */
	public static function is_manual_renewal_required() {
		return self::is_manual_renewal_enabled() && 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' );
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
