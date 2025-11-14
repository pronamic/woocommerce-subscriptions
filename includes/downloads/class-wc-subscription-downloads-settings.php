<?php

/**
 * Registers and manages settings related to linked downloadable files functionality.
 *
 * @internal This class is used internally by WooCommerce Subscriptions. It is not intended for third party use, and may change at any time.
 */
class WC_Subscription_Downloads_Settings {
	public function __construct() {
		add_filter( 'woocommerce_subscription_settings', array( $this, 'add_settings' ) );
	}

	/**
	 * Adds our settings to the main subscription settings page.
	 *
	 * @param array $settings The full subscription settings array.
	 *
	 * @return array
	 */
	public function add_settings( array $settings ): array {
		$download_settings = array(
			array(
				'name' => __( 'Downloads', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_downloads_settings',
			),
			array(
				'name'      => __( 'Enable product linking to subscriptions', 'woocommerce-subscriptions' ),
				'desc'      => __( 'Allow simple and variable downloadable products to be included with subscription products.', 'woocommerce-subscriptions' ),
				'id'        => WC_Subscriptions_Admin::$option_prefix . '_enable_downloadable_file_linking',
				'default'   => 'no',
				'type'      => 'checkbox',
				'row_class' => 'enable-downloadable-file-linking',
			),
			array(
				'type' => 'sectionend',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_downloads_settings',
			),
		);

		// Insert the switch settings in after the synchronisation section otherwise add them to the end.
		if ( ! WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Admin::$option_prefix . '_gifting', $download_settings, 'multiple-settings', 'sectionend' ) ) {
			$settings = array_merge( $settings, $download_settings );
		}

		return $settings;
	}

	/**
	 * Check if Subscriptions Downloads is enabled.
	 * @since 8.1.0
	 * @return bool
	 */
	public static function is_enabled() {
		return get_option( WC_Subscriptions_Admin::$option_prefix . '_enable_downloadable_file_linking', 'no' ) === 'yes';
	}
}
