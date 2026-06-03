<?php
/**
 * WooCommerce Subscriptions Autoloader (deprecated).
 *
 * This class previously implemented bespoke class autoloading for
 * WooCommerce Subscriptions. Autoloading now goes through Composer's classmap;
 * this shell is retained only so that third-party integrations performing a
 * `class_exists( 'WCS_Autoloader' )` check continue to see the symbol.
 *
 * The magic-method catch-alls (`__call`, `__callStatic`, `__get`, `__set`)
 * are inherited from {@see WCS_Core_Autoloader}.
 *
 * @package    WC_Subscriptions
 * @deprecated 8.8.0 Composer handles class autoloading; this class is no longer used.
 */

defined( 'ABSPATH' ) || exit;

// Top-level deprecation notice — fires on `class_exists( ..., true )` lookups
// via the registered autoloader, even when the class is never instantiated.
_deprecated_class( 'WCS_Autoloader', '8.8.0' );

/**
 * @deprecated 8.8.0 Composer handles class autoloading; this class is no longer used.
 */
class WCS_Autoloader extends WCS_Core_Autoloader {
}
