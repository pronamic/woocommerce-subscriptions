<?php

use Automattic\Jetpack\Constants;

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
	 * Check if WooCommerce Subscription Downloads plugin is enabled and add a warning about the bundled feature if it is.
	 *
	 * @since 8.0.0
	 */
	public static function add_notice_about_bundled_feature() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_subscriptions_settings_page = 'woocommerce_page_wc-settings' === $screen->id && isset( $_GET['tab'] ) && 'subscriptions' === sanitize_text_field( wp_unslash( $_GET['tab'] ) );

		// Only show notice on plugins page or subscriptions settings page.
		if ( 'plugins' !== $screen->id && ! $is_subscriptions_settings_page ) {
			return;
		}

		if ( Constants::get_constant( 'WC_SUBSCRIPTION_DOWNLOADS_VERSION' ) ) {
			$message = __( 'WooCommerce Subscription Downloads is now part of WooCommerce Subscriptions — no extra plugin needed. You can deactivate and uninstall WooCommerce Subscription Downloads via the plugin admin screen.', 'woocommerce-subscriptions' );

			wp_admin_notice(
				$message,
				array(
					'type'        => 'warning',
					'dismissible' => true,
				)
			);
		}
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
				'name'      => __( 'Enable downloadable file sharing', 'woocommerce-subscriptions' ),
				'desc'      => __( 'Allow downloadable files from simple and variable products to be shared with subscription products so they are available to active subscribers.', 'woocommerce-subscriptions' ),
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
