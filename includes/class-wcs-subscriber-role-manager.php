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
				'name' => __( 'Roles', 'woocommerce-subscriptions' ),
				'type' => 'title',
				// translators: placeholders are <em> tags
				'desc' => sprintf( __( 'Choose the default roles to assign to active and inactive subscribers. For record keeping purposes, a user account must be created for subscribers. Users with the %1$sadministrator%2$s role, such as yourself, will never be allocated these roles to prevent locking out administrators.', 'woocommerce-subscriptions' ), '<em>', '</em>' ),
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_role_options',
			),
			array(
				'name'     => __( 'Subscriber Default Role', 'woocommerce-subscriptions' ),
				'desc'     => __( 'When a subscription is activated, either manually or after a successful purchase, new users will be assigned this role.', 'woocommerce-subscriptions' ),
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
				'name'     => __( 'Inactive Subscriber Role', 'woocommerce-subscriptions' ),
				'desc'     => __( 'If a subscriber\'s subscription is manually cancelled or expires, she will be assigned this role.', 'woocommerce-subscriptions' ),
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
