<?php
/**
 * WCS_ATT_Admin_Notices class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin notices handling.
 *
 * @class    WCS_ATT_Admin_Notices
 * @version  9.0.0
 */
class WCS_ATT_Admin_Notices {

	/**
	 * Notices presisting on the next request.
	 *
	 * @var array
	 */
	public static $meta_box_notices = array();

	/**
	 * Notices displayed on the current request.
	 *
	 * @var array
	 */
	public static $admin_notices = array();

	/**
	 * Dismissible notices displayed on the current request.
	 *
	 * @var array
	 */
	public static $dismissed_notices = array();

	/**
	 * Constructor.
	 */
	public static function init() {

		self::$dismissed_notices = get_user_meta( get_current_user_id(), 'wcsatt_dismissed_notices', true );
		self::$dismissed_notices = is_array( self::$dismissed_notices ) ? self::$dismissed_notices : array();

		// Show meta box notices.
		add_action( 'admin_notices', array( __CLASS__, 'output_notices' ) );
		// Save meta box notices.
		add_action( 'shutdown', array( __CLASS__, 'save_notices' ), 100 );
	}

	/**
	 * Add a notice/error.
	 *
	 * @param  string  $text
	 * @param  mixed   $args
	 * @param  boolean $save_notice
	 */
	public static function add_notice( $text, $args, $save_notice = false ) {

		if ( is_array( $args ) ) {
			$type          = $args['type'];
			$dismiss_class = isset( $args['dismiss_class'] ) ? $args['dismiss_class'] : false;
		} else {
			$type          = $args;
			$dismiss_class = false;
		}

		$notice = array(
			'type'          => $type,
			'content'       => $text,
			'dismiss_class' => $dismiss_class,
		);

		if ( $save_notice ) {
			self::$meta_box_notices[] = $notice;
		} else {
			self::$admin_notices[] = $notice;
		}
	}

	/**
	 * Checks if a dismissible notice has been dismissed in the past.
	 *
	 * @param  string $notice_name
	 * @return boolean
	 */
	public static function is_dismissible_notice_dismissed( $notice_name ) {
		return in_array( $notice_name, self::$dismissed_notices );
	}

	/**
	 * Save errors to an option.
	 */
	public static function save_notices() {
		update_option( 'wcsatt_meta_box_notices', self::$meta_box_notices );
	}

	/**
	 * Show any stored error messages.
	 */
	public static function output_notices() {

		$saved_notices = get_option( 'wcsatt_meta_box_notices', array() );
		$notices       = array_merge( self::$admin_notices, $saved_notices );

		if ( ! empty( $notices ) ) {

			foreach ( $notices as $notice ) {

				$notice_classes = array( 'wcsatt_notice', 'notice', 'notice-' . $notice['type'] );
				$dismiss_attr   = $notice['dismiss_class'] ? ' data-dismiss_class="' . esc_attr( $notice['dismiss_class'] ) . '"' : '';

				if ( $notice['dismiss_class'] ) {
					$notice_classes[] = $notice['dismiss_class'];
					$notice_classes[] = 'is-dismissible';
				}

				$output = '<div class="' . esc_attr( implode( ' ', $notice_classes ) ) . '"' . $dismiss_attr . '>' . wpautop( $notice['content'] ) . '</div>';
				echo wp_kses_post( $output );
			}

			$handle = 'wcsatt-admin-notices-dismiss';
			wp_register_script( $handle, '', array( 'jquery' ), false, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
			wp_enqueue_script( $handle );

			wp_add_inline_script(
				$handle,
				// phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired
				"
				( function( $ ) {
				 	$( 'body' ).on( 'click', '.wcsatt_notice .notice-dismiss', function( event ) {
						var data = {
							action: 'woocommerce_dismiss_satt_notice',
							notice: $( this ).parent().data( 'dismiss_class' ),
							security: '" . wp_create_nonce( 'wcsatt_dismiss_notice_nonce' ) . "'
						};

						$.post( '" . WC()->ajax_url() . "', data );
					} );
				} )( jQuery );
				"
			);

			// Clear.
			delete_option( 'wcsatt_meta_box_notices' );
		}
	}

	/**
	 * Add a dimissible notice/error.
	 *
	 * @param  string $text
	 * @param  mixed  $args
	 */
	public static function add_dismissible_notice( $text, $args ) {
		if ( ! isset( $args['dismiss_class'] ) || ! self::is_dismissible_notice_dismissed( $args['dismiss_class'] ) ) {
			self::add_notice( $text, $args );
		}
	}

	/**
	 * Remove a dismissible notice.
	 *
	 * @param  string $notice_name
	 */
	public static function remove_dismissible_notice( $notice_name ) {

		// Remove if not already removed.
		if ( ! self::is_dismissible_notice_dismissed( $notice_name ) ) {
			self::$dismissed_notices = array_merge( self::$dismissed_notices, array( $notice_name ) );
			update_user_meta( get_current_user_id(), 'wcsatt_dismissed_notices', self::$dismissed_notices );
			return true;
		}

		return false;
	}

	/**
	 * Dismisses a notice.
	 *
	 * @param  string $notice
	 */
	public static function dismiss_notice( $notice ) {
		return self::remove_dismissible_notice( $notice );
	}
}
