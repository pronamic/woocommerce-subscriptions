<?php
/**
 * Downloads Admin Announcement Handler Class
 *
 * @package  WooCommerce Subscriptions
 * @since    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Subscription_Downloads_Admin_Welcome_Announcement {

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
		if ( ! self::is_woocommerce_admin_or_subscriptions_listing() ) {
			return;
		}

		$screen = get_current_screen();

		wp_localize_script(
			'wcs-admin',
			'wcsDownloadsSettings',
			array(
				'imagesPath'                   => plugins_url( '/assets/images', WC_Subscriptions::$plugin_file ),
				'pluginsUrl'                   => admin_url( 'plugins.php' ),
				'subscriptionsUrl'             => WC_Subscriptions_Admin::settings_tab_url() . '#woocommerce_subscriptions_downloads_enable',
				'isStandaloneDownloadsEnabled' => is_plugin_active( 'woocommerce-subscription-downloads/woocommerce-subscription-downloads.php' ),
				'isSubscriptionsListing'       => 'woocommerce_page_wc-orders--shop_subscription' === $screen->id,
			)
		);
	}

	/**
	 * Output the tour HTML in the admin footer
	 */
	public static function output_tour() {
		if ( ! self::is_woocommerce_admin_or_subscriptions_listing() ) {
			return;
		}

		// Add a div for the tour to be rendered into
		echo '<div id="wcs-downloads-welcome-announcement-root" class="woocommerce-tour-kit"></div>';
	}

	/**
	 * Checks if the welcome tour has been dismissed.
	 *
	 * @return bool
	 */
	public static function is_welcome_announcement_dismissed() {
		return '1' === get_option(
			'woocommerce_subscriptions_downloads_is_welcome_announcement_dismissed',
			''
		);
	}

	/**
	 * Checks if the current screen is WooCommerce Admin or subscriptions listing.
	 *
	 * @return bool
	 */
	private static function is_woocommerce_admin_or_subscriptions_listing() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action_param = isset( $_GET['action'] ) ? wc_clean( wp_unslash( $_GET['action'] ) ) : '';

		$is_woocommerce_admin     = 'woocommerce_page_wc-admin' === $screen->id;
		$is_subscriptions_listing = 'woocommerce_page_wc-orders--shop_subscription' === $screen->id && empty( $action_param );

		return $is_woocommerce_admin || $is_subscriptions_listing;
	}
}
