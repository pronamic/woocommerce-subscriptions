<?php
/**
 * Class WCS_Admin_Empty_List_Content_Manager
 *
 * @package WooCommerce Subscriptions
 * @since 6.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class for managing the content displayed in the WooCommerce → Subscriptions admin list table when no results are found.
 */
class WCS_Admin_Empty_List_Content_Manager {

	/**
	 * Initializes the class and attach callbacks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts_and_styles' ) );
	}

	/**
	 * Gets the content to display in the WooCommerce → Subscriptions admin list table when no results are found.
	 *
	 * @return string The HTML content for the empty state if no subscriptions exist, otherwise a string indicating no results.
	 */
	public static function get_content() {
		$content = __( 'No subscriptions found.', 'woocommerce-subscriptions' );

		if ( self::should_display_empty_state() ) {
			$content = wc_get_template_html(
				'html-admin-empty-list-table.php',
				[],
				'',
				WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/admin/' )
			);
		}

		// Backwards compatibility for the woocommerce_subscriptions_not_found_label filter.
		if ( has_action( 'woocommerce_subscriptions_not_found_label' ) ) {
			wcs_deprecated_hook( 'woocommerce_subscriptions_not_found_label', '6.2.0', 'woocommerce_subscriptions_not_found_content' );

			/**
			 * Filters the HTML for the empty state.
			 *
			 * The woocommerce_subscriptions_not_found_label filter no longer makes sense as the HTML is now
			 * more complex - it is no longer just a string. For backwards compatibility we still filter the
			 * full content shown in the empty state.
			 *
			 * @deprecated 6.2.0 Use the woocommerce_subscriptions_not_found_html filter instead.
			 * @param string $content The HTML for the empty state.
			 */
			$content = apply_filters( 'woocommerce_subscriptions_not_found_label', $content );
		}

		/**
		 * Filters the HTML for the empty state.
		 *
		 * @since 6.2.0
		 * @param string $html The HTML for the empty state.
		 */
		return apply_filters( 'woocommerce_subscriptions_not_found_content', $content );
	}

	/**
	 * Enqueues the scripts and styles for the empty state.
	 */
	public static function enqueue_scripts_and_styles() {
		$screen = get_current_screen();

		// Only enqueue the scripts on the admin subscriptions screen.
		if ( ! $screen || 'edit-shop_subscription' !== $screen->id || ! self::should_display_empty_state() ) {
			return;
		}

		wp_register_style(
			'Woo-Subscriptions-Empty-State',
			WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( 'assets/css/admin-empty-state.css' ),
			[],
			WC_Subscriptions_Core_Plugin::instance()->get_library_version()
		);

		wp_enqueue_style( 'Woo-Subscriptions-Empty-State' );
	}

	/**
	 * Determines if the empty state content should be displayed.
	 *
	 * Uses the `woocommerce_subscriptions_not_empty` filter to determine if subscriptions exist on the store.
	 *
	 * @return bool True if subscriptions don't exist and the empty state should be displayed, otherwise false.
	 */
	private static function should_display_empty_state() {
		return ! (bool) apply_filters( 'woocommerce_subscriptions_not_empty', WC_Subscriptions_Core_Plugin::instance()->cache->cache_and_get( 'wcs_do_subscriptions_exist', 'wcs_do_subscriptions_exist' ) );
	}
}
