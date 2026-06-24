<?php
/**
 * Subscription Plans welcome announcement handler.
 *
 * Renders a dismissible "welcome" spotlight announcement in the WooCommerce admin
 * informing merchants that subscription plans (previously provided by the
 * standalone All Products for WooCommerce Subscriptions extension) are now built
 * into WooCommerce Subscriptions.
 *
 * Mirrors the gifting announcement handler (`WCSG_Admin_Welcome_Announcement`)
 * but persists the dismissal state per-user via `WCS_ATT_Admin_Notices` so that
 * each admin sees the announcement once until they dismiss it.
 *
 * @package  WooCommerce Subscriptions
 * @since    9.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WCS_ATT_Admin_Welcome_Announcement class.
 */
class WCS_ATT_Admin_Welcome_Announcement {

	/**
	 * Dismiss-notice key persisted in the `wcsatt_dismissed_notices` user meta array.
	 *
	 * @var string
	 */
	const NOTICE_NAME = 'welcome_subscription_plans';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_footer', array( __CLASS__, 'output_root' ) );
	}

	/**
	 * Localize data for the JS entrypoint.
	 *
	 * Only runs on WC Admin home or the Subscriptions listing; on any other
	 * screen the localization is skipped so the announcement does not mount.
	 */
	public static function enqueue_scripts() {
		if ( ! self::is_woocommerce_admin_or_subscriptions_listing() ) {
			return;
		}

		$screen = get_current_screen();

		wp_localize_script(
			'wcs-admin',
			'wcsSubscriptionPlansWelcomeSettings',
			array(
				'imagesPath'             => plugins_url( '/assets/images', WC_Subscriptions::$plugin_file ),
				'pluginsUrl'             => admin_url( 'plugins.php' ),
				'isStandaloneApfsActive' => is_plugin_active( 'woocommerce-all-products-for-subscriptions/woocommerce-all-products-for-subscriptions.php' ),
				'isSubscriptionsListing' => 'woocommerce_page_wc-orders--shop_subscription' === $screen->id,
				'dismissNotice'          => self::NOTICE_NAME,
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'dismissNonce'           => wp_create_nonce( 'wcsatt_dismiss_notice_nonce' ),
			)
		);
	}

	/**
	 * Echo the root div that the React entrypoint mounts into.
	 */
	public static function output_root() {
		if ( ! self::is_woocommerce_admin_or_subscriptions_listing() ) {
			return;
		}

		echo '<div id="wcs-subscription-plans-welcome-announcement-root" class="woocommerce-tour-kit"></div>';
	}

	/**
	 * Whether the current user has already dismissed the announcement.
	 *
	 * Reads directly from user meta on each call. This is intentional:
	 *
	 * - It does not depend on `WCS_ATT_Admin_Notices::init()` having already
	 *   run, which is required because this method is called from early hook
	 *   positions (e.g. plugin-file load time, `admin_init:5`) before that
	 *   class's `$dismissed_notices` static is populated.
	 * - It avoids a cross-user stale-state bug where the shared
	 *   `WCS_ATT_Admin_Notices::$dismissed_notices` static could leak one
	 *   user's dismissal list to another user if the current user is
	 *   switched mid-request (possible in some REST/cron contexts).
	 *
	 * `get_user_meta()` is served from the WordPress object cache within a
	 * request after the first read, so repeated calls do not hit the database.
	 *
	 * When the APFS notices class is unavailable entirely, treat the
	 * announcement as dismissed (safe fallback: do nothing) rather than
	 * initializing a broken state.
	 *
	 * @return bool
	 */
	public static function is_welcome_announcement_dismissed() {
		if ( ! class_exists( 'WCS_ATT_Admin_Notices' ) ) {
			return true;
		}

		$notices = get_user_meta( get_current_user_id(), 'wcsatt_dismissed_notices', true );

		return is_array( $notices ) && in_array( self::NOTICE_NAME, $notices, true );
	}

	/**
	 * Whether the current admin screen is the WC Admin home or Subscriptions listing.
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
