<?php
/**
 * A class for handling the WC_Subscriptions class's deprecated functions.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   WooCommerce
 * @since    4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WC_Subscriptions_Deprecation_Handler extends WCS_Deprecated_Functions_Handler {

	/**
	 * This class handles WC_Subscriptions.
	 *
	 * @var string
	 */
	protected $class = 'WC_Subscriptions';

	/**
	 * Deprecated WC_Subscriptions functions.
	 *
	 * @var array[]
	 */
	protected $deprecated_functions = array(
		'add_months' => array(
			'replacement' => 'wcs_add_months',
			'version'     => '2.0.0',
		),
		'is_large_site' => array(
			'replacement' => array( __CLASS__, '_is_large_site' ),
			'version'     => '2.0.0',
		),
		'get_subscription_status_counts' => array(
			'replacement' => array( __CLASS__, '_get_subscription_status_counts' ),
			'version'     => '2.0.0',
		),
		'get_subscriptions' => array(
			'replacement' => array( __CLASS__, '_get_subscriptions' ),
			'version'     => '2.0.0',
		),
		'format_total' => array(
			'replacement' => 'wc_format_decimal',
			'version'     => '2.0.0',
		),
		'woocommerce_dependancy_notice' => array(
			'replacement' => array( 'WC_Subscriptions', 'woocommerce_inactive_notice' ),
			'version'     => '2.1.0',
		),
		'add_notice' => array(
			'replacement' => 'wc_add_notice',
			'version'     => '2.2.16',
		),
		'print_notices' => array(
			'replacement' => 'wc_print_notices',
			'version'     => '2.2.16',
		),
		'get_product' => array(
			'replacement' => 'wc_get_product',
			'version'     => '2.4.0',
		),
		'maybe_empty_cart' => array(
			'replacement' => array( 'WC_Subscriptions_Cart_Validator', 'maybe_empty_cart' ),
			'version'     => '2.6.0',
		),
		'remove_subscriptions_from_cart' => array(
			'replacement' => array( 'WC_Subscriptions_Cart', 'remove_subscriptions_from_cart' ),
			'version'     => '2.6.0',
		),
		'enqueue_styles' => array(
			'replacement' => array( 'WC_Subscriptions_Frontend_Scripts', 'enqueue_styles' ),
			'version'     => '3.1.3',
		),
		'enqueue_frontend_scripts' => array(
			'replacement' => array( 'WC_Subscriptions_Frontend_Scripts', 'enqueue_scripts' ),
			'version'     => '3.1.3',
		),
		'get_customer_orders' => array(
			'replacement' => array( 'WCS_Meta_Box_Subscription_Data', 'get_customer_orders' ),
			'version'     => '4.0.0',
		),
		'get_my_subscriptions_template' => array(
			'replacement' => array( 'WCS_Template_Loader', 'get_my_subscriptions' ),
			'version'     => '4.0.0',
		),
		'redirect_to_cart' => array(
			'replacement' => array( __CLASS__, '_redirect_to_cart' ),
			'version'     => '4.0.0',
		),
		'get_longest_period' => array(
			'replacement' => 'wcs_get_longest_period',
			'version'     => '4.0.0',
		),
		'get_shortest_period' => array(
			'replacement' => 'wcs_get_shortest_period',
			'version'     => '4.0.0',
		),
		'append_numeral_suffix' => array(
			'replacement' => 'wcs_append_numeral_suffix',
			'version'     => '4.0.0',
		),
		'subscription_add_to_cart' => array(
			'replacement' => array( 'WCS_Template_Loader', 'get_subscription_add_to_cart' ),
			'version'     => '4.0.0',
		),
		'variable_subscription_add_to_cart' => array(
			'replacement' => array( 'WCS_Template_Loader', 'get_variable_subscription_add_to_cart' ),
			'version'     => '4.0.0',
		),
		'wcopc_subscription_add_to_cart' => array(
			'replacement' => array( 'WCS_Template_Loader', 'get_opc_subscription_add_to_cart' ),
			'version'     => '4.0.0',
		),
		'add_to_cart_redirect' => array(
			'replacement' => array( 'WC_Subscriptions_Cart', 'add_to_cart_redirect' ),
			'version'     => '4.0.0',
		),
		'is_woocommerce_pre' => array(
			'replacement' => 'wcs_is_woocommerce_pre',
			'version'     => '4.0.0',
		),
		'woocommerce_site_change_notice' => array(
			'replacement' => array( 'WCS_Staging', 'handle_site_change_notice' ),
			'version'     => '4.0.0',
		),
		'get_current_sites_duplicate_lock' => array(
			'replacement' => array( 'WCS_Staging', 'get_duplicate_site_lock_key' ),
			'version'     => '4.0.0',
		),
		'set_duplicate_site_url_lock' => array(
			'replacement' => array( 'WCS_Staging', 'set_duplicate_site_url_lock' ),
			'version'     => '4.0.0',
		),
		'is_duplicate_site' => array(
			'replacement' => array( 'WCS_Staging', 'is_duplicate_site' ),
			'version'     => '4.0.0',
		),
		'show_downgrade_notice' => array(
			'replacement' => array( __CLASS__, '_show_downgrade_notice' ),
			'version'     => '4.0.0',
		),
		'get_site_url' => array(
			'replacement' => array( 'WCS_Staging', 'get_live_site_url' ),
			'version'     => '4.0.0',
		),
		'get_site_url_from_source' => array(
			'replacement' => array( 'WCS_Staging', 'get_site_url_from_source' ),
			'version'     => '4.0.0',
		),
		'redirect_ajax_add_to_cart' => array(
			'replacement' => array( 'WC_Subscriptions_Cart_Validator', 'add_to_cart_ajax_redirect' ),
			'version'     => '4.0.0',
		),
		'order_button_text' => array(
			'replacement' => array( 'WC_Subscriptions_Checkout', 'order_button_text' ),
			'version'     => '4.0.0',
		),
		'load_dependant_classes' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'init_version_dependant_classes' ),
			'version'     => '4.0.0',
		),
		'attach_dependant_hooks' => array(
			'version' => '4.0.0',
		),
		'register_order_types' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'register_order_types' ),
			'version'     => '4.0.0',
		),
		'add_data_stores' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'add_data_stores' ),
			'version'     => '4.0.0',
		),
		'register_post_status' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'register_post_statuses' ),
			'version'     => '4.0.0',
		),
		'deactivate_woocommerce_subscriptions' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'deactivate_plugin' ),
			'version'     => '4.0.0',
		),
		'load_plugin_textdomain' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'load_plugin_textdomain' ),
			'version'     => '4.0.0',
		),
		'action_links' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'add_plugin_action_links' ),
			'version'     => '4.0.0',
		),
		'update_notice' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'update_notice' ),
			'version'     => '4.0.0',
		),
		'setup_blocks_integration' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'setup_blocks_integration' ),
			'version'     => '4.0.0',
		),
		'maybe_activate_woocommerce_subscriptions' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'activate_plugin' ),
			'version'     => '4.0.0',
		),
		'action_scheduler_multisite_batch_size' => array(
			'replacement' => array( array( 'WC_Subscriptions_Core_Plugin', 'instance' ), 'reduce_multisite_action_scheduler_batch_size' ),
			'version'     => '4.0.0',
		),
	);

	/**
	 * Deprecated Function Replacements
	 */

	/**
	 * Deprecation handling of the original WC_Subscriptions::is_large_site() function.
	 *
	 * Not to be called directly.
	 *
	 * @deprecated
	 */
	protected function _is_large_site() {
		return apply_filters( 'woocommerce_subscriptions_is_large_site', false );
	}

	/**
	 * Deprecation handling of the original WC_Subscriptions::get_subscription_status_counts() function.
	 *
	 * Not to be called directly.
	 *
	 * @deprecated
	 */
	protected function _get_subscription_status_counts() {
		$results = wp_count_posts( 'shop_subscription' );
		$counts  = array();

		foreach ( $results as $status => $count ) {

			if ( in_array( $status, array_keys( wcs_get_subscription_statuses() ) ) || in_array( $status, array( 'trash', 'draft' ) ) ) {
				$counts[ $status ] = $count;
			}
		}

		// Order with 'all' at the beginning, then alphabetically
		ksort( $counts );
		$counts = array( 'all' => array_sum( $counts ) ) + $counts;

		return apply_filters( 'woocommerce_subscription_status_counts', $counts );
	}

	/**
	 * Deprecation handling of the original WC_Subscriptions::redirect_to_cart() function.
	 *
	 * Not to be called directly.
	 *
	 * @deprecated
	 */
	protected function _redirect_to_cart( $permalink, $product_id ) {
		return wc_get_cart_url();
	}

	/**
	 * Deprecation handling of the original WC_Subscriptions::show_downgrade_notice() function.
	 *
	 * Not to be called directly.
	 *
	 * @deprecated
	 */
	public static function _show_downgrade_notice() {
		if ( version_compare( get_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '0' ), self::$version, '>' ) ) {
			echo '<div class="update-nag">';
			// translators: placeholder is Subscriptions version number.
			echo sprintf( esc_html__( 'Warning! You are running version %s of WooCommerce Subscriptions plugin code but your database has been upgraded to Subscriptions version 2.0. This will cause major problems on your store.', 'woocommerce-subscriptions' ), esc_html( self::$version ) ) . '<br />';
			// translators: opening/closing <a> tags - linked to ticket form.
			echo sprintf( esc_html__( 'Please upgrade the WooCommerce Subscriptions plugin to version 2.0 or newer immediately. If you need assistance, after upgrading to Subscriptions v2.0, please %1$sopen a support ticket%2$s.', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/my-account/marketplace-ticket-form/">', '</a>' );
			echo '</div> ';
		}
	}

	/**
	 * Deprecation handling of the original WC_Subscriptions::show_downgrade_notice() function.
	 *
	 * Not to be called directly.
	 *
	 * @deprecated
	 */
	protected function _get_subscriptions( $args = array() ) {

		if ( isset( $args['orderby'] ) ) {
			// Although most of these weren't public orderby values, they were used internally so may have been used by developers
			switch ( $args['orderby'] ) {
				case '_subscription_status':
					_deprecated_argument( __METHOD__, '2.0', 'The "_subscription_status" orderby value is deprecated. Use "status" instead.' );
					$args['orderby'] = 'status';
					break;
				case '_subscription_start_date':
					_deprecated_argument( __METHOD__, '2.0', 'The "_subscription_start_date" orderby value is deprecated. Use "start_date" instead.' );
					$args['orderby'] = 'start_date';
					break;
				case 'expiry_date':
				case '_subscription_expiry_date':
				case '_subscription_end_date':
					_deprecated_argument( __METHOD__, '2.0', 'The expiry date orderby value is deprecated. Use "end_date" instead.' );
					$args['orderby'] = 'end_date';
					break;
				case 'trial_expiry_date':
				case '_subscription_trial_expiry_date':
					_deprecated_argument( __METHOD__, '2.0', 'The trial expiry date orderby value is deprecated. Use "trial_end_date" instead.' );
					$args['orderby'] = 'trial_end_date';
					break;
				case 'name':
					_deprecated_argument( __METHOD__, '2.0', 'The "name" orderby value is deprecated - subscriptions no longer have just one name as they may contain multiple items.' );
					break;
			}
		}

		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscriptions( $args )' );

		$subscriptions = wcs_get_subscriptions( $args );

		$subscriptions_in_deprecated_structure = array();

		// Get the subscriptions in the backward compatible structure
		foreach ( $subscriptions as $subscription ) {
			$subscriptions_in_deprecated_structure[ wcs_get_old_subscription_key( $subscription ) ] = wcs_get_subscription_in_deprecated_structure( $subscription );
		}

		return apply_filters( 'woocommerce_get_subscriptions', $subscriptions_in_deprecated_structure, $args );
	}
}
