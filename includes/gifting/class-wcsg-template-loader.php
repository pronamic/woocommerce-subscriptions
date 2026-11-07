<?php
/**
 * WCS Gifting Template Loader
 *
 * @package WooCommerce Subscriptions Gifting
 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Locates Gifting templates for use through `wc_get_template()`.
 */
class WCSG_Template_Loader {

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'wc_get_template', array( __CLASS__, 'get_recent_orders_template' ), 1, 3 );

		add_filter( 'wc_get_template', array( __CLASS__, 'get_subscription_totals_template' ), 1, 3 );

		add_filter( 'wc_get_template', array( __CLASS__, 'get_customer_details_template' ), 1, 3 );

		add_filter( 'wc_get_template', array( __CLASS__, 'get_email_addresses_template' ), 1, 3 );
	}

	/**
	 * Overrides the default recent order template for gifted subscriptions
	 *
	 * @param string $located       Path to template.
	 * @param string $template_name Template name.
	 * @param array  $args          Arguments.
	 * @return string Path for including template.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function get_recent_orders_template( $located, $template_name, $args ) {
		if ( 'myaccount/related-orders.php' === $template_name ) {
			$subscription = $args['subscription'];
			if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {
				$located = wc_locate_template( 'related-orders.php', '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
			}
		}
		return $located;
	}

	/**
	 * Overrides subscription totals template.
	 *
	 * @param string $located       Path to template.
	 * @param string $template_name Template name.
	 * @param array  $args          Arguments.
	 * @return string Path for including template.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function get_subscription_totals_template( $located, $template_name, $args ) {
		if ( ! wcsg_is_wc_subscriptions_pre( '2.2.19' ) && 'myaccount/subscription-totals.php' === $template_name ) {
			$subscription = $args['subscription'];
			if ( WCS_Gifting::is_gifted_subscription( $subscription ) && get_current_user_id() == WCS_Gifting::get_recipient_user( $subscription ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				$located = wc_locate_template( 'subscription-totals.php', '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
			}
		}
		return $located;
	}

	/**
	 * Overrides the customer details template on the view subscription and view order pages for gifted subscriptions.
	 *
	 * The recipient is shown the shipping address but not the purchaser's billing details. Once the shipping address
	 * is the recipient's (see WCSG_Recipient_Addresses::is_shipping_address_withheld()), the purchaser is shown their
	 * billing details and a notice in place of the address, on the subscription and on its renewal orders alike.
	 *
	 * @param string $located       Path to template.
	 * @param string $template_name Template name.
	 * @param array  $args          Arguments.
	 * @return string Path for including template.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 * @since 9.4.0 Withholds the recipient's shipping address from the purchaser.
	 */
	public static function get_customer_details_template( $located, $template_name, $args ) {
		if ( 'order/order-details-customer.php' === $template_name && isset( $args['order'] ) && ! wcsg_is_wc_subscriptions_pre( '2.2.19' ) ) {
			$order             = $args['order'];
			$current_user_id   = get_current_user_id();
			$gifting_templates = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/';

			if ( WCS_Gifting::is_gifted_subscription( $order ) && $current_user_id && (int) WCS_Gifting::get_recipient_user( $order ) === $current_user_id ) {
				$located = wc_locate_template( 'order-details-customer.php', '', $gifting_templates );
			} elseif ( WCSG_Recipient_Addresses::is_shipping_address_withheld( $order, $current_user_id ) ) {
				$located = wc_locate_template( 'purchaser-order-details-customer.php', '', $gifting_templates );
			}
		}
		return $located;
	}

	/**
	 * Overrides the addresses block of the emails sent to the purchaser about a gifted subscription and its renewal
	 * orders, once the shipping address is the recipient's.
	 *
	 * Emails sent to the store's admin are untouched, as are the recipient's own emails, which use Gifting's
	 * recipient templates rather than this one.
	 *
	 * @param string $located       Path to template.
	 * @param string $template_name Template name.
	 * @param array  $args          Arguments.
	 * @return string Path for including template.
	 * @since 9.4.0
	 */
	public static function get_email_addresses_template( $located, $template_name, $args ) {
		if ( ! in_array( $template_name, array( 'emails/email-addresses.php', 'emails/plain/email-addresses.php' ), true ) || ! empty( $args['sent_to_admin'] ) || empty( $args['order'] ) ) {
			return $located;
		}

		$order = $args['order'];

		if ( ! $order instanceof WC_Order || ! WCSG_Recipient_Addresses::is_shipping_address_withheld( $order, $order->get_customer_id() ) ) {
			return $located;
		}

		$template = 'emails/plain/email-addresses.php' === $template_name ? 'emails/plain/purchaser-email-addresses.php' : 'emails/purchaser-email-addresses.php';

		return wc_locate_template( $template, '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
	}
}
