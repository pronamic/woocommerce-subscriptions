<?php
/**
 * A class for managing custom active and inactive subscriber roles via a setting.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Subscriber_Role_Manager {

	/**
	 * Initialise the class.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ), 20 );
	}

	/**
	 * Adds the subscription customer role setting.
	 *
	 * @since 4.0.0
	 *
	 * @param  array $settings Subscriptions settings.
	 * @return array Subscriptions settings.
	 */
	public static function add_settings( $settings ) {
		$roles_options = array();

		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		foreach ( get_editable_roles() as $role => $details ) {
			$roles_options[ $role ] = translate_user_role( $details['name'] );
		}

		$role_settings = array(
			array(
				'name' => __( 'Subscriber roles', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'desc' => sprintf(
					/* translators: %1$s: a "Learn more" documentation link. */
					__( 'Choose the default roles to assign to active and inactive subscribers. %1$s', 'woocommerce-subscriptions' ),
					\Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings\Settings_Layout::learn_more_link( 'https://woocommerce.com/document/subscriptions/store-manager-guide/#subscriber-roles' )
				),
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_role_options',
			),
			array(
				'name'     => __( 'Subscriber default role', 'woocommerce-subscriptions' ),
				'desc'     => __( 'If a customer has one or more active subscriptions, they will be assigned to this role.', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_subscriber_role',
				'css'      => 'min-width:150px;',
				'default'  => 'subscriber',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => $roles_options,
				'desc_tip' => true,
			),
			array(
				'name'     => __( 'Inactive subscriber role', 'woocommerce-subscriptions' ),
				'desc'     => __( 'If a customer has no active subscriptions, they will be assigned this role.', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_cancelled_role',
				'css'      => 'min-width:150px;',
				'default'  => 'customer',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => $roles_options,
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_role_options',
			),
		);

		if ( ! WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Admin::$option_prefix . '_button_text', $role_settings, 'multiple_settings', 'sectionend' ) ) {
			$settings = array_merge( $settings, $role_settings );
		}

		return $settings;
	}

	/**
	 * Gets the subscriber role.
	 *
	 * @since 4.0.0
	 *
	 * @return string The role to apply to subscribers.
	 */
	public static function get_subscriber_role() {
		return get_option( WC_Subscriptions_Admin::$option_prefix . '_subscriber_role', 'subscriber' );
	}

	/**
	 * Gets the inactive subscriber role.
	 *
	 * @since 4.0.0
	 *
	 * @return string The role to apply to inactive subscribers.
	 */
	public static function get_inactive_subscriber_role() {
		return get_option( WC_Subscriptions_Admin::$option_prefix . '_cancelled_role', 'customer' );
	}
}
