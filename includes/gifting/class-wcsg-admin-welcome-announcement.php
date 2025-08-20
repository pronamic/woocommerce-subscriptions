<?php
/**
 * Gifting Admin Announcement Handler Class
 *
 * @package  WooCommerce Subscriptions
 * @since    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCSG_Admin_Welcome_Announcement {

	/**
	 * Initialize the tour handler
	 */
	public static function init() {
		// Register scripts and styles
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Add the tour HTML to the admin footer
		add_action( 'admin_footer', array( __CLASS__, 'output_tour' ) );
	}

	/**
	 * Enqueue required scripts and styles
	 */
	public static function enqueue_scripts() {
		$screen = get_current_screen();

		// Only load on WooCommerce admin pages
		if ( ! $screen || 'woocommerce_page_wc-admin' !== $screen->id ) {
			return;
		}

		wp_enqueue_script(
			'wcs-gifting-welcome-announcement',
			plugins_url( '/build/gifting-welcome-announcement.js', WC_Subscriptions::$plugin_file ),
			array( 'wp-components', 'wp-i18n' ),
			WC_Subscriptions::$version,
			true
		);

		wp_localize_script(
			'wcs-gifting-welcome-announcement',
			'wcsGiftingSettings',
			array(
				'imagesPath'                 => plugins_url( '/assets/images', WC_Subscriptions::$plugin_file ),
				'pluginsUrl'                 => admin_url( 'plugins.php' ),
				'subscriptionsUrl'           => WC_Subscriptions_Admin::settings_tab_url() . '#woocommerce_subscriptions_gifting_enable_gifting',
				'isStandaloneGiftingEnabled' => is_plugin_active( 'woocommerce-subscriptions-gifting/woocommerce-subscriptions-gifting.php' ),
			)
		);

		wp_enqueue_style( 'wcs-gifting-welcome-announcement', plugins_url( '/build/style-gifting-welcome-announcement.css', WC_Subscriptions::$plugin_file ), array(), WC_Subscriptions::$version );

		wp_set_script_translations(
			'wcs-gifting-welcome-announcement',
			'woocommerce-subscriptions',
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'languages'
		);
	}

	/**
	 * Output the tour HTML in the admin footer
	 */
	public static function output_tour() {
		$screen = get_current_screen();

		// Only load on WooCommerce admin pages
		if ( ! $screen || 'woocommerce_page_wc-admin' !== $screen->id ) {
			return;
		}

		// Add a div for the tour to be rendered into
		echo '<div id="wcs-gifting-welcome-announcement-root" class="woocommerce-tour-kit"></div>';
	}

	/**
	 * Checks if the welcome tour has been dismissed.
	 *
	 * @return bool
	 */
	public static function is_welcome_announcement_dismissed() {
		return '1' === get_option(
			'woocommerce_subscriptions_gifting_is_welcome_announcement_dismissed',
			''
		);
	}
}
