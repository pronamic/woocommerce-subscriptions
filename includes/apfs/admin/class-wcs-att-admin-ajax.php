<?php
/**
 * WCS_ATT_Admin_Ajax class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin includes and hooks.
 *
 * @class    WCS_ATT_Admin_Ajax
 * @version  3.2.1
 */
class WCS_ATT_Admin_Ajax {

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Add hooks.
	 */
	private static function add_hooks() {

		/*
		 * Notices.
		 */

		// Dismiss notices.
		add_action( 'wp_ajax_woocommerce_dismiss_satt_notice', array( __CLASS__, 'dismiss_notice' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Notices.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Dismisses notices.
	 *
	 * @since  APFS 2.2.0
	 *
	 * @return void
	 */
	public static function dismiss_notice() {

		$failure = array(
			'result' => 'failure',
		);

		if ( ! check_ajax_referer( 'wcsatt_dismiss_notice_nonce', 'security', false ) ) {
			wp_send_json( $failure );
		}

		if ( empty( $_POST['notice'] ) ) {
			wp_send_json( $failure );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( $failure );
		}

		$dismissed = WCS_ATT_Admin_Notices::dismiss_notice( wc_clean( $_POST['notice'] ) );

		if ( ! $dismissed ) {
			wp_send_json( $failure );
		}

		$response = array(
			'result' => 'success',
		);

		wp_send_json( $response );
	}
}
