<?php
/**
 * WooCommerce Subscriptions Admin Functions
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Store a message to display via @see wcs_display_admin_notices().
 *
 * @param string The message to display
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_add_admin_notice( $message, $notice_type = 'success' ) {

	$notices = get_transient( '_wcs_admin_notices' );

	if ( false === $notices ) {
		$notices = array();
	}

	$notices[ $notice_type ][] = $message;

	set_transient( '_wcs_admin_notices', $notices, 60 * 60 );
}

/**
 * Display any notices added with @see wcs_add_admin_notice()
 *
 * This method is also hooked to 'admin_notices' to display notices there.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_display_admin_notices( $clear = true ) {

	$notices = get_transient( '_wcs_admin_notices' );

	if ( false !== $notices && ! empty( $notices ) ) {

		if ( ! empty( $notices['success'] ) ) {
			array_walk( $notices['success'], 'esc_html' );
			echo '<div id="moderated" class="updated"><p>' . wp_kses_post( implode( "</p>\n<p>", $notices['success'] ) ) . '</p></div>';
		}

		if ( ! empty( $notices['error'] ) ) {
			array_walk( $notices['error'], 'esc_html' );
			echo '<div id="moderated" class="error"><p>' . wp_kses_post( implode( "</p>\n<p>", $notices['error'] ) ) . '</p></div>';
		}
	}

	if ( false !== $clear ) {
		wcs_clear_admin_notices();
	}
}
add_action( 'admin_notices', 'wcs_display_admin_notices' );

/**
 * Delete any admin notices we stored for display later.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_clear_admin_notices() {
	delete_transient( '_wcs_admin_notices' );
}
