<?php
/**
 * Legacy Subscription Product Handler
 *
 * Ensures subscription products work with versions of WooCommerce prior to 3.0 by loading
 * legacy classes to provide CRUD methods only added with WC 3.0.
 *
 * @package WooCommerce Subscriptions
 * @subpackage WCS_Product_Legacy
 * @category Class
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 */
class WCS_Product_Legacy {

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 **/
	public static function init() {

		// Use our legacy product classes when WC 3.0+ is not active
		add_filter( 'woocommerce_product_class', __CLASS__ . '::set_product_class', 100, 4 );
	}

	/**
	 * Use legacy classes for WC < 3.0
	 *
	 * @return string $classname The name of the WC_Product_* class which should be instantiated to create an instance of this product.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public static function set_product_class( $classname, $product_type, $post_type, $product_id ) {

		if ( wcs_is_woocommerce_pre( '3.0' ) && in_array( $classname, array( 'WC_Product_Subscription', 'WC_Product_Variable_Subscription', 'WC_Product_Subscription_Variation' ) ) ) {
			$classname .= '_Legacy';
		}

		return $classname;
	}

}
