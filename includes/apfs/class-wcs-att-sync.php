<?php
/**
 * WCS_ATT_Sync class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles synchronization.
 *
 * @class    WCS_ATT_Sync
 * @version  4.1.0
 */
class WCS_ATT_Sync {

	/**
	 * Initialization.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Hook-in.
	 */
	private static function add_hooks() {

		if ( is_admin() ) {

			// Process and save the necessary meta.
			add_filter( 'wcsatt_processed_scheme_data', array( __CLASS__, 'process_scheme_sync_data' ), 10, 2 );
			add_filter( 'wcsatt_processed_cart_scheme_data', array( __CLASS__, 'process_scheme_sync_data' ), 10 );

			// Add the translated fields to the Subscriptions admin script when viewing schemes on the 'WooCommerce > Settings' page.
			add_filter( 'woocommerce_subscriptions_admin_script_parameters', array( __CLASS__, 'admin_script_parameters' ), 10 );

		}

		// Remember to set sync meta when setting a subscription scheme on a product object.
		add_action( 'wcsatt_set_product_subscription_scheme', array( __CLASS__, 'set_product_subscription_scheme_sync_date' ), 0, 3 );
	}

	/**
	 * Determines if the first payment of a product is prorated, assuming a scheme is set on it.
	 *
	 * @since  APFS 2.1.0
	 *
	 * @param  WC_Product            $product  Product object to check.
	 * @param  string|WCS_ATT_Scheme $scheme   Optional scheme key when checking against one of the schemes already tied to the object, or an arbitrary 'WCS_ATT_Scheme' object to check against.
	 * @return boolean                          Result.
	 */
	public static function is_first_payment_prorated( $product, $scheme = '' ) {

		// Resolve the scheme object to check.
		if ( is_a( $scheme, 'WCS_ATT_Scheme' ) ) {
			$scheme_to_check = $scheme;
		} else {
			$scheme_key      = '' === $scheme ? WCS_ATT_Product_Schemes::get_subscription_scheme( $product ) : $scheme;
			$scheme_to_check = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $scheme_key );
		}

		if ( ! $scheme_to_check ) {
			return false;
		}

		// Compute proration directly from scheme data - no scheme switching needed.
		// This mirrors the logic in WC_Subscriptions_Synchroniser::is_product_prorated().
		if ( ! WC_Subscriptions_Synchroniser::is_sync_proration_enabled() ) {
			return false;
		}

		if ( ! $scheme_to_check->is_synced() ) {
			return false;
		}

		$trial_length = (int) $scheme_to_check->get_trial_length();

		if ( WC_Subscriptions_Synchroniser::should_prorate_virtual_products() && $product->is_virtual() && 0 === $trial_length ) {
			return true;
		}

		if ( WC_Subscriptions_Synchroniser::should_prorate_physical_products() && ! $product->is_virtual() && 0 === $trial_length ) {
			return true;
		}

		return false;
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Renders subscription scheme synchronization options.
	 *
	 * @param  int   $index
	 * @param  array $scheme_data
	 * @param  int   $post_id
	 * @return void
	 */
	/**
	 * Keep it short. Rename "Do not synchronise" to "Disabled". Pointless but blame OCD.
	 *
	 * @param  array $range_data
	 * @return array
	 */
	private static function rename_subscription_billing_period_range_data( $range_data ) {

		if ( isset( $range_data[0] ) ) {
			$range_data[0] = __( 'Disabled', 'woocommerce-subscriptions' );
		} elseif ( is_array( $range_data ) ) {
			foreach ( $range_data as $key => $data ) {
				$range_data[ $key ] = self::rename_subscription_billing_period_range_data( $data );
			}
		}

		return $range_data;
	}

	/**
	 * Save subscription sync options.
	 *
	 * @param  array $scheme
	 * @return void
	 */
	public static function process_scheme_sync_data( $scheme_data ) {

		$subscription_period = isset( $scheme_data['subscription_period'] ) ? $scheme_data['subscription_period'] : '';

		if ( 'year' === $subscription_period ) {

			if ( empty( $scheme_data['subscription_payment_sync_date_month'] ) || empty( $scheme_data['subscription_payment_sync_date_day'] ) ) {

				$scheme_data['subscription_payment_sync_date'] = 0;

			} else {

				$days_in_month = cal_days_in_month( CAL_GREGORIAN, absint( $scheme_data['subscription_payment_sync_date_month'] ), 2001 );

				if ( absint( $scheme_data['subscription_payment_sync_date_day'] ) > $days_in_month ) {
					$scheme_data['subscription_payment_sync_date_day'] = 1;
				}

				$scheme_data['subscription_payment_sync_date'] = array(
					'day'   => absint( $scheme_data['subscription_payment_sync_date_day'] ),
					'month' => strval( $scheme_data['subscription_payment_sync_date_month'] ),
				);
			}
		} elseif ( empty( $scheme_data['subscription_payment_sync_date'] ) ) {

				$scheme_data['subscription_payment_sync_date'] = 0;
		}

		return $scheme_data;
	}

	/**
	 * Add translated syncing options for our client side script.
	 *
	 * @param  array $script_parameters
	 */
	public static function admin_script_parameters( $script_parameters ) {

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( $screen_id === WCS_ATT_Core_Compatibility::get_formatted_screen_id( 'woocommerce_page_wc-settings' ) && isset( $_GET['tab'] ) && $_GET['tab'] === 'subscriptions' ) {

			$billing_period_strings = self::rename_subscription_billing_period_range_data( WC_Subscriptions_Synchroniser::get_billing_period_ranges() );

			$script_parameters['syncOptions'] = array(
				'week'  => $billing_period_strings['week'],
				'month' => $billing_period_strings['month'],
			);

		}

		return $script_parameters;
	}

	/**
	 * Set subscription payment sync data on product objects.
	 *
	 * @param  string     $scheme_key
	 * @param  string     $active_scheme_key
	 * @param  WC_Product $product
	 */
	public static function set_product_subscription_scheme_sync_date( $scheme_key, $active_scheme_key, $product ) {

		$schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );

		if ( ! empty( $scheme_key ) && is_array( $schemes ) && isset( $schemes[ $scheme_key ] ) && $scheme_key !== $active_scheme_key ) {

			$scheme_to_set = $schemes[ $scheme_key ];

			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_payment_sync_date', $scheme_to_set->get_sync_date() );

		} elseif ( empty( $scheme_key ) ) {

			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_payment_sync_date', 0 );
		}
	}
}
