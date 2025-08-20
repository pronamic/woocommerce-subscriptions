<?php
/**
 * WCS Gifting Compatibility functions.
 *
 * @package WooCommerce Subscriptions Gifting
 */

/**
 * WooCommerce Compatibility functions
 *
 * Functions to take advantage of APIs added to new versions of WooCommerce while maintaining backward compatibility.
 *
 * @package  WooCommerce Subscriptions Gifting/Functions
 * @version  1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Check if the installed version of WooCommerce is older than a specified version.
 *
 * @param string $version Version to check against.
 * @return bool
 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.1.
 */
function wcsg_is_woocommerce_pre( $version ) {

	if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, $version, '<' ) ) {
		$woocommerce_is_pre_version = true;
	} else {
		$woocommerce_is_pre_version = false;
	}

	return $woocommerce_is_pre_version;
}

/**
 * Get an object's property value in a version compatible way.
 *
 * @param object $object   Object.
 * @param string $property Property name.
 * @return mixed
 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.1.
 */
function wcsg_get_objects_property( $object, $property ) {
	$value = '';

	switch ( $property ) {
		case 'order':
			if ( is_callable( array( $object, 'get_parent' ) ) ) {
				$value = $object->get_parent();
			} else {
				$value = $object->order;
			}
			break;
		default:
			$function = 'get_' . $property;

			if ( is_callable( array( $object, $function ) ) ) {
				$value = $object->$function();
			} else {
				$value = $object->$property;
			}
			break;
	}

	return $value;

}

/**
 * Get an object's ID in a version compatible way.
 *
 * @param object $object Object.
 * @return int
 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.1.
 */
function wcsg_get_objects_id( $object ) {

	if ( method_exists( $object, 'get_id' ) ) {
		$id = $object->get_id();
	} else {
		$id = $object->id;
	}

	return $id;
}

/**
 * Check if the active version of WooCommerce Subscriptions is older than the specified version.
 *
 * @param string $version Version to check against.
 * @return bool
 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
 */
function wcsg_is_wc_subscriptions_pre( $version ) {
	if ( ! class_exists( 'WC_Subscriptions_Core_Plugin' ) && ! class_exists( 'WC_Subscriptions' ) ) {
		_doing_it_wrong( __METHOD__, 'This method should not be called before plugins_loaded.', '2.0' );
		return false;
	}
	$subscriptions_version = class_exists( 'WC_Subscriptions_Core_Plugin' ) ? WC_Subscriptions_Core_Plugin::instance()->get_plugin_version() : WC_Subscriptions::$version;
	return version_compare( $subscriptions_version, $version, '<' );
}
