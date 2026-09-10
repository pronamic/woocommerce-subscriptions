<?php
/**
 * A class for managing the drip downloads feature.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Drip_Downloads_Manager {

	/**
	 * Initialise the class.
	 *
	 * @since 4.0.0
	 */
	public static function init() {
		add_filter( 'woocommerce_process_product_file_download_paths_grant_access_to_new_file', array( __CLASS__, 'maybe_revoke_immediate_access' ), 10, 4 );
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_setting' ) );
	}

	/**
	 * Checks if the drip downloads feature is enabled.
	 *
	 * @since 4.0.0
	 * @return bool Whether download dripping is enabled or not.
	 */
	public static function are_drip_downloads_enabled() {
		return 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_drip_downloadable_content_on_renewal', 'no' );
	}

	/**
	 * Prevent granting download permissions to subscriptions and related-orders when new files are added to a product.
	 *
	 * @since 4.0.0
	 *
	 * @param bool     $grant_access Whether to grant access to the file/download ID.
	 * @param string   $download_id  The ID of the download being added.
	 * @param int      $product_id   The ID of the downloadable product.
	 * @param WC_Order $order        The order/subscription's ID.
	 *
	 * @return bool Whether to grant access to the file/download ID.
	 */
	public static function maybe_revoke_immediate_access( $grant_access, $download_id, $product_id, $order ) {

		if ( self::are_drip_downloads_enabled() && ( wcs_is_subscription( $order->get_id() ) || wcs_order_contains_subscription( $order, 'any' ) ) ) {
			$grant_access = false;
		}

		return $grant_access;
	}

	/**
	 * Adds the Drip Downloadable Content setting.
	 *
	 * @since 4.0.0
	 *
	 * @param array $settings The WC Subscriptions settings array.
	 * @return array Settings.
	 */
	public static function add_setting( $settings ) {
		$setting = array(
			'name'     => __( 'Drip Downloadable Content', 'woocommerce-subscriptions' ),
			'desc'     => __( 'Release new downloadable files after each renewal', 'woocommerce-subscriptions' ),
			'id'       => WC_Subscriptions_Admin::$option_prefix . '_drip_downloadable_content_on_renewal',
			'default'  => 'no',
			'type'     => 'checkbox',
			'class'    => \Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings\Classic_Renderer::CLASS_HIDE_CHECKBOX_TITLE,
			'desc_tip' => __( 'When you add the new downloadable files to a product, subscribers will only get access to them after their next renewal is processed.', 'woocommerce-subscriptions' ),
		);

		WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Admin::$option_prefix . '_miscellaneous', $setting );
		return $settings;
	}
}
