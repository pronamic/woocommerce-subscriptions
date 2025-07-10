<?php
/**
 * WooCommerce Compatibility functions
 *
 * Functions to take advantage of APIs added to new versions of WooCommerce while maintaining backward compatibility.
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
 * Display a tooltip in the WordPress administration area.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1.0
 *
 * @param string $tip        The content to display in the tooltip.
 * @param bool   $allow_html Allow sanitized HTML if true or escape. Optional. False by default.
 * @param string $class      The help tip's class attribute. Optional. Default is 'woocommerce-help-tip'.
 *
 * @return string The helptip HTML.
 */
function wcs_help_tip( $tip, $allow_html = false, $class = 'woocommerce-help-tip' ) {
	$help_tip = wc_help_tip( $tip, $allow_html );

	if ( 'woocommerce-help-tip' !== $class ) {
		$help_tip = str_replace( 'woocommerce-help-tip', esc_attr( $class ), $help_tip );
	}

	return $help_tip;
}

/**
 * Access an object's property in a way that is compatible with CRUD and non-CRUD APIs for different versions of WooCommerce.
 *
 * We don't want to force the use of a custom legacy class for orders, similar to WC_Subscription_Legacy, because 3rd party
 * code may expect the object type to be WC_Order with strict type checks.
 *
 * A note on dates: in WC 3.0+, dates are returned a timestamps in the site's timezone :upside_down_face:. In WC < 3.0, they were
 * returned as MySQL strings in the site's timezone. We return them from here as MySQL strings in UTC timezone because that's how
 * dates are used in Subscriptions in almost all cases, for sanity's sake.
 *
 * @param WC_Order|WC_Product|WC_Subscription $object   The object whose property we want to access.
 * @param string                              $property The property name.
 * @param string                              $single   Whether to return just the first piece of meta data with the given property key, or all meta data.
 * @param mixed                               $default  (optional) The value to return if no value is found - defaults to single -> null, multiple -> array().
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @deprecated 2.4.0 Use of this compatibility function is no longer required, getters should be used on the objects instead. Please note there may be differences in dates between this function and the getter.
 * @return mixed
 */
function wcs_get_objects_property( $object, $property, $single = 'single', $default = null ) {
	$value = ! is_null( $default ) ? $default : ( ( 'single' === $single ) ? null : array() );

	if ( ! is_object( $object ) ) {
		return $value;
	}

	$prefixed_key          = wcs_maybe_prefix_key( $property );
	$property_function_map = array(
		'order_version'  => 'version',
		'order_currency' => 'currency',
		'order_date'     => 'date_created',
		'date'           => 'date_created',
		'cart_discount'  => 'total_discount',
	);

	if ( isset( $property_function_map[ $property ] ) ) {
		$property = $property_function_map[ $property ];
	}

	switch ( $property ) {
		case 'post':
			// In order to keep backwards compatibility it's required to use the parent data for variations.
			if ( method_exists( $object, 'is_type' ) && $object->is_type( 'variation' ) ) {
				$value = get_post( wcs_get_objects_property( $object, 'parent_id' ) );
			} else {
				$value = get_post( wcs_get_objects_property( $object, 'id' ) );
			}
			break;

		case 'post_status':
			$value = wcs_get_objects_property( $object, 'post' )->post_status;
			break;

		case 'variation_data':
			$value = wc_get_product_variation_attributes( wcs_get_objects_property( $object, 'id' ) );
			break;

		default:
			$function_name = 'get_' . $property;

			if ( is_callable( array( $object, $function_name ) ) ) {
				$value = $object->$function_name();
			} else {
				// If we don't have a method for this specific property, but we are using WC 3.0, use $object->get_meta().
				if ( method_exists( $object, 'get_meta' ) ) {
					if ( $object->meta_exists( $prefixed_key ) ) {
						if ( 'single' === $single ) {
							$value = $object->get_meta( $prefixed_key, true );
						} else {
							// WC_Data::get_meta() returns an array of stdClass objects with id, key & value properties when meta is available.
							$value = wp_list_pluck( $object->get_meta( $prefixed_key, false ), 'value' );
						}
					}
				} elseif ( 'single' === $single && isset( $object->$property ) ) { // WC < 3.0.
					$value = $object->$property;
				} elseif ( strtolower( $property ) !== 'id' && metadata_exists( 'post', wcs_get_objects_property( $object, 'id' ), $prefixed_key ) ) {
					// If we couldn't find a property or function, fallback to using post meta as that's what many __get() methods in WC < 3.0 did.
					if ( 'single' === $single ) {
						$value = get_post_meta( wcs_get_objects_property( $object, 'id' ), $prefixed_key, true );
					} else {
						// Get all the meta values.
						$value = get_post_meta( wcs_get_objects_property( $object, 'id' ), $prefixed_key, false );
					}
				}
			}
			break;
	}

	return $value;
}

/**
 * Set an object's property in a way that is compatible with CRUD and non-CRUD APIs for different versions of WooCommerce.
 *
 * @param WC_Order|WC_Product|WC_Subscription $object The object whose property we want to access.
 * @param string $key The meta key name without '_' prefix
 * @param mixed $value The data to set as the value of the meta
 * @param string $save Whether to write the data to the database or not. Use 'save' to write to the database, anything else to only update it in memory.
 * @param int $meta_id The meta ID of existing meta data if you wish to overwrite an existing piece of meta.
 * @param string $prefix_meta_key Whether the key should be prefixed with an '_' when stored in meta. Defaulted to 'prefix_meta_key', pass any other value to bypass automatic prefixing (optional)
 * @deprecated 2.4.0 Use of this compatibility function is no longer required, setters should be used on the objects instead.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @return mixed
 */
function wcs_set_objects_property( &$object, $key, $value, $save = 'save', $meta_id = 0, $prefix_meta_key = 'prefix_meta_key' ) {

	$prefixed_key = wcs_maybe_prefix_key( $key );

	// WC will automatically set/update these keys when a shipping/billing address attribute changes so we can ignore these keys
	if ( in_array( $prefixed_key, array( '_shipping_address_index', '_billing_address_index' ) ) ) {
		return;
	}

	// Special cases where properties with setters which don't map nicely to their function names
	$meta_setters_map = array(
		'_cart_discount'         => 'set_discount_total',
		'_cart_discount_tax'     => 'set_discount_tax',
		'_customer_user'         => 'set_customer_id',
		'_order_tax'             => 'set_cart_tax',
		'_order_shipping'        => 'set_shipping_total',
		'_sale_price_dates_from' => 'set_date_on_sale_from',
		'_sale_price_dates_to'   => 'set_date_on_sale_to',
	);

	// If we have an object with a predefined setter function, use it
	if ( isset( $meta_setters_map[ $prefixed_key ] ) && is_callable( array( $object, $meta_setters_map[ $prefixed_key ] ) ) ) {
		$function = $meta_setters_map[ $prefixed_key ];
		$object->$function( $value );

	} elseif ( is_callable( array( $object, 'set' . $prefixed_key ) ) ) { // If we have an object, use the setter if available
		// Prices include tax is stored as a boolean in props but saved in the database as a string yes/no, so we need to normalise it here to make sure if we have a string (which can be passed to it by things like wcs_copy_order_meta()) that it's converted to a boolean before being set
		if ( '_prices_include_tax' === $prefixed_key && ! is_bool( $value ) ) {
			$value = 'yes' === $value;
		}

		$object->{ "set$prefixed_key" }( $value );

		// If there is a setter without the order prefix (eg set_order_total -> set_total)
	} elseif ( is_callable( array( $object, 'set' . str_replace( '_order', '', $prefixed_key ) ) ) ) {
		$function_name = 'set' . str_replace( '_order', '', $prefixed_key );
		$object->$function_name( $value );

	} else { // If there is no setter, treat as meta within the data object
		$meta_key = ( 'prefix_meta_key' === $prefix_meta_key ) ? $prefixed_key : $key;
		$object->update_meta_data( $meta_key, $value, $meta_id );
	}

	// Save the data
	if ( 'save' === $save ) {
		$object->save();
	}
}

/**
 * Delete an object's property in a way that is compatible with CRUD and non-CRUD APIs for different versions of WooCommerce.
 *
 * @param WC_Order|WC_Product|WC_Subscription $object The object whose property we want to access.
 * @param string $key The meta key name without '_' prefix
 * @param string $save Whether to save the data or not, 'save' to save the data, otherwise it won't be saved.
 * @param int $meta_id The meta ID.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @deprecated 2.4.0 Use of this compatibility function is no longer required, setters should be used on the objects instead.
 * @return mixed
 */
function wcs_delete_objects_property( &$object, $key, $save = 'save', $meta_id = 0 ) {

	$prefixed_key = wcs_maybe_prefix_key( $key );

	if ( ! empty( $meta_id ) && is_callable( array( $object, 'delete_meta_data_by_mid' ) ) ) {
		$object->delete_meta_data_by_mid( $meta_id );
	} elseif ( is_callable( array( $object, 'delete_meta_data' ) ) ) {
		$object->delete_meta_data( $prefixed_key );
	} elseif ( isset( $object->$key ) ) {
		unset( $object->$key );
	}

	// Save the data
	if ( 'save' === $save ) {
		if ( is_callable( array( $object, 'save' ) ) ) { // WC 3.0+
			$object->save();
		} elseif ( ! empty( $meta_id ) ) {
			delete_metadata_by_mid( 'post', $meta_id );
		} else {
			delete_post_meta( wcs_get_objects_property( $object, 'id' ), $prefixed_key );
		}
	}
}

/**
 * Check whether an order is a standard order (i.e. not a refund or subscription) in version compatible way.
 *
 * WC 3.0 has the $order->get_type() API which returns 'shop_order', while WC < 3.0 provided the $order->order_type
 * property which returned 'simple', so we need to check for both.
 *
 * @param WC_Order $order
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @return bool
 */
function wcs_is_order( $order ) {

	if ( is_callable( array( $order, 'get_type' ) ) ) {
		$is_order = ( 'shop_order' === $order->get_type() );
	} else {
		$is_order = ( isset( $order->order_type ) && 'simple' === $order->order_type );
	}

	return $is_order;
}

/**
 * Find and return the value for a deprecated property property.
 *
 * Product properties should not be accessed directly with WooCommerce 3.0+, because of that, a lot of properties
 * have been deprecated/removed in the subscription product type classes. This function centralises the handling
 * of deriving deprecated properties. This saves duplicating the __get() method in WC_Product_Subscription,
 * WC_Product_Variable_Subscription and WC_Product_Subscription_Variation.
 *
 * @param string $property
 * @param WC_Product $product
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @return mixed
 */
function wcs_product_deprecated_property_handler( $property, $product ) {

	$message_prefix = 'Product properties should not be accessed directly with WooCommerce 3.0+.';
	$function_name  = 'get_' . str_replace( 'subscription_', '', str_replace( 'subscription_period_', '', $property ) );
	$class_name     = get_class( $product );
	$value          = null;

	if ( in_array( $property, array( 'product_type', 'parent_product_type', 'limit_subscriptions', 'subscription_limit', 'subscription_payment_sync_date', 'subscription_one_time_shipping' ) ) || ( is_callable( array( 'WC_Subscriptions_Product', $function_name ) ) && false !== strpos( $property, 'subscription' ) ) ) {

		switch ( $property ) {
			case 'product_type':
				$value       = $product->get_type();
				$alternative = $class_name . '::get_type()';
				break;

			case 'parent_product_type':
				if ( $product->is_type( 'subscription_variation' ) ) {
					$value       = 'variation';
					$alternative = 'WC_Product_Variation::get_type()';
				} else {
					$value       = 'variable';
					$alternative = 'WC_Product_Variable::get_type()';
				}
				break;

			case 'limit_subscriptions':
			case 'subscription_limit':
				$value       = wcs_get_product_limitation( $product );
				$alternative = 'wcs_get_product_limitation( $product )';
				break;

			case 'subscription_one_time_shipping':
				$value       = WC_Subscriptions_Product::needs_one_time_shipping( $product );
				$alternative = 'WC_Subscriptions_Product::needs_one_time_shipping( $product )';
				break;

			case 'subscription_payment_sync_date':
				$value       = WC_Subscriptions_Synchroniser::get_products_payment_day( $product );
				$alternative = 'WC_Subscriptions_Synchroniser::get_products_payment_day( $product )';
				break;

			case 'max_variation_period':
			case 'max_variation_period_interval':
				$meta_key = '_' . $property;
				if ( '' === $product->get_meta( $meta_key ) ) {
					WC_Product_Variable::sync( $product->get_id() );
				}
				$value       = $product->get_meta( $meta_key );
				$alternative = $class_name . '::get_meta( ' . $meta_key . ' ) or wcs_get_min_max_variation_data( $product )';
				break;

			default:
				$value       = call_user_func( array( 'WC_Subscriptions_Product', $function_name ), $product );
				$alternative = sprintf( 'WC_Subscriptions_Product::%s( $product )', $function_name );
				break;
		}

		wcs_deprecated_argument( $class_name . '::$' . $property, '2.2.0', sprintf( '%s Use %s', $message_prefix, $alternative ) );
	}

	return $value;
}

/**
 * Access a coupon's property in a way that is compatible with CRUD and non-CRUD APIs for different versions of WooCommerce.
 *
 * Similar to @see wcs_get_objects_property
 *
 * @param WC_Coupon $coupon The coupon whose property we want to access.
 * @param string $property The property name.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2
 * @return mixed
 */
function wcs_get_coupon_property( $coupon, $property ) {

	$value = '';

	if ( wcs_is_woocommerce_pre( '3.0' ) ) {
		$value = $coupon->$property;
	} else {
		// Some coupon properties don't map nicely to their corresponding getter function. This array contains those exceptions.
		$property_to_getter_map = array(
			'type'                       => 'get_discount_type',
			'exclude_product_ids'        => 'get_excluded_product_ids',
			'expiry_date'                => 'get_date_expires',
			'exclude_product_categories' => 'get_excluded_product_categories',
			'customer_email'             => 'get_email_restrictions',
			'enable_free_shipping'       => 'get_free_shipping',
			'coupon_amount'              => 'get_amount',
		);

		switch ( true ) {
			case 'exists' == $property:
				$value = $coupon->get_id() > 0;
				break;
			case isset( $property_to_getter_map[ $property ] ) && is_callable( array( $coupon, $property_to_getter_map[ $property ] ) ):
				$function = $property_to_getter_map[ $property ];
				$value    = $coupon->$function();
				break;
			case is_callable( array( $coupon, 'get_' . $property ) ):
				$value = $coupon->{ "get_$property" }();
				break;
		}
	}

	return $value;
}

/**
 * Set a coupon's property in a way that is compatible with CRUD and non-CRUD APIs for different versions of WooCommerce.
 *
 * Similar to @see wcs_set_objects_property
 *
 * @param WC_Coupon $coupon The coupon whose property we want to set.
 * @param string $property The property name.
 * @param mixed $value The data to set as the value
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2
 */
function wcs_set_coupon_property( &$coupon, $property, $value ) {

	if ( wcs_is_woocommerce_pre( '3.0' ) ) {
		$coupon->$property = $value;
	} else {
		// Some coupon properties don't map nicely to their corresponding setter function. This array contains those exceptions.
		$property_to_setter_map = array(
			'type'                       => 'set_discount_type',
			'exclude_product_ids'        => 'set_excluded_product_ids',
			'expiry_date'                => 'set_date_expires',
			'exclude_product_categories' => 'set_excluded_product_categories',
			'customer_email'             => 'set_email_restrictions',
			'enable_free_shipping'       => 'set_free_shipping',
			'coupon_amount'              => 'set_amount',
		);

		switch ( true ) {
			case 'individual_use' == $property:
				// set_individual_use expects a boolean, the individual_use property use to be either 'yes' or 'no' so we need to accept both types
				if ( ! is_bool( $value ) ) {
					$value = 'yes' === $value;
				}

				$coupon->set_individual_use( $value );
				break;
			case isset( $property_to_setter_map[ $property ] ) && is_callable( array( $coupon, $property_to_setter_map[ $property ] ) ):
				$function = $property_to_setter_map[ $property ];
				$coupon->$function( $value );

				break;
			case is_callable( array( $coupon, 'set_' . $property ) ):
				$coupon->{ "set_$property" }( $value );
				break;
		}
	}
}

/**
 * Generate an order/subscription key.
 *
 * This is a compatibility wrapper for @see wc_generate_order_key() which was introduced in WC 3.5.4.
 *
 * @return string $order_key.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
 */
function wcs_generate_order_key() {

	if ( function_exists( 'wc_generate_order_key' ) ) {
		$order_key = wc_generate_order_key();
	} else {
		$order_key = 'wc_' . apply_filters( 'woocommerce_generate_order_key', 'order_' . wp_generate_password( 13, false ) );
	}

	return $order_key;
}

/**
 * Update a single option for a WC_Settings_API object.
 *
 * This is a compatibility wrapper for @see WC_Settings_API::update_option() which was introduced in WC 3.4.0.
 *
 * @param WC_Settings_API $settings_api The object to update the option for.
 * @param string $key Option key.
 * @param mixed $value Value to set.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.1
 */
function wcs_update_settings_option( $settings_api, $key, $value ) {

	// WooCommerce 3.4+
	if ( is_callable( array( $settings_api, 'update_option' ) ) ) {
		$settings_api->update_option( $key, $value );
	} else {
		if ( empty( $settings_api->settings ) ) {
			$settings_api->init_settings();
		}

		$settings_api->settings[ $key ] = $value;

		return update_option( $settings_api->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $settings_api->id, $settings_api->settings ), 'yes' );
	}
}

/**
 * Determines if the request is a non-legacy REST API request.
 *
 * This function is a compatibility wrapper for WC()->is_rest_api_request() which was introduced in WC 3.6.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.7
 *
 * @return bool True if it's a REST API request, false otherwise.
 */
function wcs_is_rest_api_request() {

	if ( function_exists( 'WC' ) && is_callable( array( WC(), 'is_rest_api_request' ) ) ) {
		return WC()->is_rest_api_request();
	}

	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$rest_prefix = trailingslashit( rest_get_url_prefix() );
	// @phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$is_rest_api_request = ( false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix ) );

	return apply_filters( 'woocommerce_is_rest_api_request', $is_rest_api_request );
}

/**
 * Determines if the current request is to any or a specific Checkout blocks REST API endpoint.
 *
 * @see Automattic\WooCommerce\Blocks\StoreApi\RoutesController::initialize() for a list of routes.
 *
 * @since 1.7.0
 * @param string $endpoint The checkout/checkout blocks endpoint. Optional. Can be empty (any checkout blocks API) or a specific endpoint ('checkout', 'cart', 'products' etc)
 * @return bool Whether the current request is for a cart/checkout blocks REST API endpoint.
 */
function wcs_is_checkout_blocks_api_request( $endpoint = '' ) {

	if ( ! wcs_is_rest_api_request() || empty( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$endpoint    = empty( $endpoint ) ? '' : '/' . $endpoint;
	$rest_prefix = trailingslashit( rest_get_url_prefix() );
	$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

	return false !== strpos( $request_uri, $rest_prefix . 'wc/store' . $endpoint );
}

/**
 * Determines whether the current request is a WordPress cron request.
 *
 * This function is a compatibility wrapper for wp_doing_cron() which was introduced in WP 4.8.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.7
 *
 * @return bool True if it's a WordPress cron request, false otherwise.
 */
function wcs_doing_cron() {
	return function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : defined( 'DOING_CRON' ) && DOING_CRON;
}

/**
 * Determines whether the current request is a WordPress Ajax request.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.7
 *
 * @return bool True if it's a WordPress Ajax request, false otherwise.
 */
function wcs_doing_ajax() {
	return function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : defined( 'DOING_AJAX' ) && DOING_AJAX;
}

/**
 * A wrapper function for getting an order's used coupon codes.
 *
 * WC 3.7 deprecated @see WC_Abstract_Order::get_used_coupons() in favour of WC_Abstract_Order::get_coupon_codes().
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 *
 * @param WC_Abstract_Order $order An order or subscription object to get the coupon codes for.
 * @return array The coupon codes applied to the $order.
 */
function wcs_get_used_coupon_codes( $order ) {
	return is_callable( array( $order, 'get_coupon_codes' ) ) ? $order->get_coupon_codes() : $order->get_used_coupons();
}

/**
 * Attach a function callback for a certain WooCommerce versions.
 *
 * Enables attaching a callback if WooCommerce is before, after, equal or not equal to a given version.
 * This function is a wrapper for @see WCS_Dependent_Hook_Manager::add_woocommerce_dependent_action().
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 *
 * @param string $tag The action or filter tag to attach the callback too.
 * @param string|array $function The callable function to attach to the hook.
 * @param string $woocommerce_version The WooCommerce version to do a compare on. For example '3.0.0'.
 * @param string $operator The version compare operator to use. @see https://www.php.net/manual/en/function.version-compare.php
 * @param integer $priority The priority to attach this callback to.
 * @param integer $number_of_args The number of arguments to pass to the callback function
 */
function wcs_add_woocommerce_dependent_action( $tag, $function, $woocommerce_version, $operator, $priority = 10, $number_of_args = 1 ) {
	WCS_Dependent_Hook_Manager::add_woocommerce_dependent_action( $tag, $function, $woocommerce_version, $operator, $priority, $number_of_args );
}

/**
 * Checks if the installed version of WooCommerce is older than a specified version.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 *
 * @param string $version The version string to check in a version_compare() compatible format.
 * @return bool   Whether the installed version of WC is prior to the given version string.
 */
function wcs_is_woocommerce_pre( $version ) {
	return ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, $version, '<' );
}

/**
 * Checks if the WooCommerce feature is enabled using WC's new FeaturesUtil class.
 *
 * @param string $feature_name The name of the WC feature to check if enabled.
 *
 * @return bool
 */
function wcs_is_wc_feature_enabled( $feature_name ) {
	$feature_is_enabled = false;

	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( $feature_name ) ) {
		$feature_is_enabled = true;
	}

	return $feature_is_enabled;
}

/**
 * Determines whether custom order tables usage is enabled.
 *
 * Custom order table feature can be enabled but the store is still using WP posts as the authoriative source of order data,
 * therefore this function will only return true if:
 *  - the HPOS feature is enabled
 *  - the HPOS tables have been generated
 *  - HPOS is the authoriative source of order data
 *
 * @return bool
 */
function wcs_is_custom_order_tables_usage_enabled() {
	return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Determines whether the order tables are synchronized with WP posts.
 *
 * @return bool True if the order tables are synchronized with WP posts, false otherwise.
 */
function wcs_is_custom_order_tables_data_sync_enabled() {
	if ( ! class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer' ) ) {
		return false;
	}

	$data_synchronizer = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );

	return $data_synchronizer && $data_synchronizer->data_sync_is_enabled();
}

/**
 * Sets the address on an order or subscription using WC 7.1 functions if they exist.
 *
 * For stores pre WC 7.1, use the individual address type and key setter i.e. `set_billing_address_1()` method.
 *
 * @since 5.2.0
 *
 * @param WC_Order|WC_Subscription $order        The order or subscription object to set the address on.
 * @param string                   $address_type The address type to set. Either 'billing' or 'shipping'.
 * @param array                    $address      The address to set.
 */
function wcs_set_order_address( $order, $address, $address_type = 'billing' ) {
	if ( method_exists( $order, "set_{$address_type}" ) ) {
		$order->{"set_{$address_type}"}( $address );
	} else {
		foreach ( $address as $key => $value ) {
			if ( method_exists( $order, "set_{$address_type}_{$key}" ) ) {
				$order->{"set_{$address_type}_{$key}"}( $value );
			}
		}
	}
}

/**
 * Gets an object's admin page screen ID in a WC version compatible way.
 *
 * This function is a version compatible wrapper for wc_get_page_screen_id().
 *
 * @param string $object_type The object type. eg 'shop_subscription', 'shop_order'.
 * @return string The page screen ID. On CPT stores, the screen ID is equal to the post type. On HPOS, the screen ID is generated by WC and fetched via wc_get_page_screen_id().
 */
function wcs_get_page_screen_id( $object_type ) {
	if ( ! function_exists( 'wc_get_page_screen_id' ) || wcs_is_woocommerce_pre( '7.3.0' ) ) {
		return $object_type;
	}

	return wc_get_page_screen_id( $object_type );
}

/**
 * Outputs a select input box.
 *
 * This function is a compatibility wrapper for woocommerce_wp_select() which introduced the second parameter necessary for working with HPOS in WC 6.9.0.
 *
 * @since 5.2.0
 *
 * @param array   $field_args Field data.
 * @param WC_Data $object     The WC object to get the field value from. Only used in WC 6.9.0+. On older versions of WC, the value is fetched from the global $post object.
 */
function wcs_woocommerce_wp_select( $field_args, $object ) {
	if ( wcs_is_woocommerce_pre( '6.9.0' ) ) {
		woocommerce_wp_select( $field_args );
	} else {
		woocommerce_wp_select( $field_args, $object );
	}
}
