<?php
/**
 * WooCommerce Subscriptions Permalink Manager
 *
 * Handles and allows WCS related permalinks/endpoints.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    2.5.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class WCS_Permalink_Manager
 */
class WCS_Permalink_Manager {
	/**
	 * If the notice has been trigger, set to true to avoid duplicate notices.
	 *
	 * @var bool
	 * @since 2.5.3
	 */
	protected static $notice_triggered = false;

	/**
	 * The options saved in DB related to permalinks.
	 *
	 * @var array
	 * @since 2.5.3
	 */
	protected static $permalink_options = array(
		'woocommerce_myaccount_subscriptions_endpoint',
		'woocommerce_myaccount_view_subscription_endpoint',
		'woocommerce_myaccount_subscription_payment_method_endpoint',
	);

	/**
	 * Hooks.
	 *
	 * @since 2.5.3
	 */
	public static function init() {
		add_filter( 'pre_update_option', array( __CLASS__, 'maybe_allow_permalink_update' ), 10, 3 );
	}

	/**
	 * Validates that we're not passing the same endpoint.
	 *
	 * @param mixed  $value     The new desired value.
	 * @param string $option    The option being updated.
	 * @param mixed  $old_value The previous option value.
	 *
	 * @return mixed
	 * @since 2.5.3
	 */
	public static function maybe_allow_permalink_update( $value, $option, $old_value ) {
		// If is updating a permalink option.
		if ( isset( $_POST[ $option ] ) && in_array( $option, self::$permalink_options, true ) ) { // @codingStandardsIgnoreLine WordPress.CSRF.NonceVerification.NoNonceVerification
			foreach ( self::$permalink_options as $permalink_option ) {
				if ( $permalink_option === $option ) {
					continue;
				}

				if ( isset( $_POST[ $permalink_option ] ) && $value === $_POST[ $permalink_option ] ) { // @codingStandardsIgnoreLine WordPress.CSRF.NonceVerification.NoNonceVerification
					self::show_duplicate_permalink_notice();

					return $old_value;
				}
			}
		}

		return $value;
	}

	/**
	 * Display a warning informing that the endpoints changes has been ignored.
	 *
	 * @since 2.5.3
	 */
	protected static function show_duplicate_permalink_notice() {
		if ( ! self::$notice_triggered ) {
			self::$notice_triggered = true;
			$notice                 = new WCS_Admin_Notice( 'error' );

			$notice->set_simple_content(
				// translators: 1$-2$: opening and closing <strong> tags.
				sprintf( esc_html__( 'Error saving Subscriptions endpoints: %1$sSubscriptions%2$s, %1$sView subscription%2$s and %1$sSubscription payment method%2$s cannot be the same. The changes have been reverted.', 'woocommerce-subscriptions' ), '<strong>', '</strong>' )
			);
			$notice->display();
		}
	}
}
