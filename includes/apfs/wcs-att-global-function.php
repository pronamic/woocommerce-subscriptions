<?php
/**
 * Legacy global function for accessing the WCS_ATT singleton.
 *
 * Declared in a standalone file so it can be conditionally required by
 * WC_Subscriptions_Plugin::init_apfs() without triggering PHPStan's
 * "inner named functions not supported" error (phpstan/phpstan#165).
 *
 * @package WooCommerce Subscriptions
 * @since   9.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'WCS_ATT' ) ) {
	/**
	 * Returns the main instance of WCS_ATT to prevent the need to use globals.
	 *
	 * @since  APFS 1.0.0
	 * @return WCS_ATT
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Legacy public API from the standalone APFS plugin.
	function WCS_ATT() {
		return WCS_ATT::instance();
	}
}
