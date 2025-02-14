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
 * Registers an admin notice to be displayed once to the current (or other specified) user.
 *
 * @see   wcs_display_admin_notices()
 * @since 1.0.0 Migrated from WooCommerce Subscriptions v2.0.
 * @since 7.2.0 Added support for specifying the target user and context.
 *
 * @param string      $message     The message to display.
 * @param string      $notice_type Either 'success' or 'error'.
 * @param int|null    $user_id     The specific user who should see this message. If not specified, defaults to the current user.
 * @param string|null $screen_id   The screen ID for which the message should be displayed. If not specified, it will show on the next admin page load.
 *
 * @return void
 */
function wcs_add_admin_notice( $message, $notice_type = 'success', $user_id = null, $screen_id = null ) {
	$user_id = (int) ( null === $user_id ? get_current_user_id() : $user_id );

	if ( $user_id < 1 ) {
		wc_get_logger()->warning(
			sprintf(
				/* Translators: %1$s: notice type ('success' or 'error'), %2$s: notice text. */
				'Admin notices can only be added if a user is currently logged in. Attempted (%1$s) notice: "%2$s"',
				$notice_type,
				$message
			),
			array(
				'backtrace' => true,
				'user_id'   => $user_id,
			)
		);

		return;
	}

	$notices = get_transient( '_wcs_admin_notices_' . $user_id );

	if ( ! is_array( $notices ) ) {
		$notices = array();
	}

	$notices[ $notice_type ][] = array(
		'message'   => $message,
		'screen_id' => $screen_id,
	);

	set_transient( '_wcs_admin_notices_' . $user_id, $notices, HOUR_IN_SECONDS );
}

/**
 * Display any admin notices added with wcs_add_admin_notice().
 *
 * @see   wcs_add_admin_notice()
 * @since 1.0.0 Migrated from WooCommerce Subscriptions v2.0.
 * @since 7.2.0 Supports contextual awareness of the user and screen.
 *
 * @param bool $clear If the message queue should be cleared after rendering the message(s). Defaults to true.
 *
 * @return void
 */
function wcs_display_admin_notices( $clear = true ) {
	$user_id = get_current_user_id();
	$notices = get_transient( '_wcs_admin_notices_' . $user_id );

	if ( ! is_array( $notices ) || empty( $notices ) ) {
		return;
	}

	/**
	 * Normalizes, sanitizes and outputs the provided notices.
	 *
	 * @param array  &$notices The notice data.
	 * @param string $class    The CSS notice class to be applied (typically 'updated' or 'error').
	 *
	 * @return void
	 */
	$handle_notices = static function ( &$notices, $class ) {
		$notice_output = array();
		$screen_id     = false;

		foreach ( $notices as $index => $notice ) {
			// Ensure the notice data now has the expected shape. If it does not, remove it.
			if ( ! is_array( $notice ) || ! isset( $notice['message'] ) || ! array_key_exists( 'screen_id', $notice ) ) {
				unset( $notices[ $index ] );
				continue;
			}

			// We only need to determine the current screen ID once.
			if ( false === $screen_id ) {
				$screen    = get_current_screen();
				$screen_id = $screen instanceof WP_Screen ? $screen->id : '';
			}

			// Should the notice display in the current screen context?
			if ( is_string( $notice['screen_id'] ) && $screen_id !== $notice['screen_id'] ) {
				continue;
			}

			$notice_output[] = $notice['message'];
			unset( $notices[ $index ] );
		}

		// $notice_output may be empty if some notices were withheld, due to not matching the screen context.
		if ( ! empty( $notice_output ) ) {
			echo '<div id="moderated" class="' . esc_attr( $class ) . '"><p>' . wp_kses_post( implode( "</p>\n<p>", $notice_output ) ) . '</p></div>';
		}
	};

	if ( ! empty( $notices['success'] ) ) {
		$handle_notices( $notices['success'], 'updated' );
	}

	if ( ! empty( $notices['error'] ) ) {
		$handle_notices( $notices['error'], 'error' );
	}

	// Under certain circumstances, the caller may not wish for the rendered messages to be cleared from the queue.
	if ( false === $clear ) {
		return;
	}

	// If all notices were rendered, clear the queue. If only some were rendered, clear what we can.
	if ( empty( $notices['success'] ) && empty( $notices['error'] ) ) {
		wcs_clear_admin_notices();
	} else {
		set_transient( '_wcs_admin_notices_' . $user_id, $notices, HOUR_IN_SECONDS );
	}
}

add_action( 'admin_notices', 'wcs_display_admin_notices' );

/**
 * Delete any admin notices we stored for display later.
 *
 * @since 1.0.0 Migrated from WooCommerce Subscriptions v2.0.
 * @since 7.2.0 Became user aware.
 */
function wcs_clear_admin_notices() {
	delete_transient( '_wcs_admin_notices_' . get_current_user_id() );
}
