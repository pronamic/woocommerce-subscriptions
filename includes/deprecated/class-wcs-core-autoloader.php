<?php
/**
 * WooCommerce Subscriptions Core Autoloader (deprecated).
 *
 * This class previously implemented bespoke class autoloading for
 * WooCommerce Subscriptions. Autoloading now goes through Composer's classmap;
 * this shell is retained only so that third-party integrations performing a
 * `class_exists( 'WCS_Core_Autoloader' )` check continue to see the symbol.
 *
 * @package    WC_Subscriptions
 * @deprecated 8.8.0 Composer handles class autoloading; this class is no longer used.
 */

defined( 'ABSPATH' ) || exit;

// Top-level deprecation notice — fires on `class_exists( ..., true )` lookups
// via the registered autoloader, even when the class is never instantiated.
_deprecated_class( 'WCS_Core_Autoloader', '8.8.0' );

/**
 * @deprecated 8.8.0 Composer handles class autoloading; this class is no longer used.
 */
class WCS_Core_Autoloader {

	/**
	 * Accepts and discards any constructor arguments the legacy signature took.
	 *
	 * @param mixed $base_path Unused. Retained so existing call sites do not error.
	 */
	// @phpstan-ignore constructor.unusedParameter
	public function __construct( $base_path = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	}

	/**
	 * Catch-all for instance method calls on the deprecated class.
	 *
	 * @param string $name      The method name.
	 * @param array  $arguments The arguments passed to the method.
	 */
	public function __call( $name, $arguments ) {
		wcs_deprecated_function( static::class . "::{$name}", '8.8.0' );
	}

	/**
	 * Catch-all for static method calls on the deprecated class.
	 *
	 * @param string $name      The method name.
	 * @param array  $arguments The arguments passed to the method.
	 */
	public static function __callStatic( $name, $arguments ) {
		wcs_deprecated_function( static::class . "::{$name}", '8.8.0' );
	}

	/**
	 * Catch-all for property reads on the deprecated class.
	 *
	 * @param string $name The property name.
	 */
	public function __get( $name ) {
		wcs_deprecated_function( static::class . "::\${$name}", '8.8.0' );
		return null;
	}

	/**
	 * Catch-all for property writes on the deprecated class.
	 *
	 * @param string $name  The property name.
	 * @param mixed  $value The value being assigned.
	 */
	public function __set( $name, $value ) {
		wcs_deprecated_function( static::class . "::\${$name}", '8.8.0' );
	}
}
