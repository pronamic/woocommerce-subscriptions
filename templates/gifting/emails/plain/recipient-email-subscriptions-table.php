<?php
/**
 * Recipient e-mail: subscriptions table.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

foreach ( $subscriptions as $subscription ) {
	// Translators: placeholder is a subscription number.
	echo sprintf( esc_html__( 'Subscription #%s', 'woocommerce-subscriptions' ), esc_html( $subscription->get_order_number() ) ) . "\n";

	// Translators: placeholder is a date.
	echo sprintf( esc_html__( 'Start Date: %s', 'woocommerce-subscriptions' ), esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'date_created', 'site' ) ) ) ) . "\n";

	// Translators: placeholder is a date.
	echo sprintf( esc_html__( 'End Date: %s', 'woocommerce-subscriptions' ), ( 0 < $subscription->get_time( 'end' ) ) ? esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'end', 'site' ) ) ) : esc_html_x( 'When Cancelled', 'Used as end date for an indefinite subscription', 'woocommerce-subscriptions' ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	$subscription_details = array(
		'recurring_amount'      => '',
		'subscription_period'   => $subscription->get_billing_period(),
		'subscription_interval' => $subscription->get_billing_interval(),
		'initial_amount'        => '',
		'use_per_slash'         => false,
	);
	$subscription_details = apply_filters( 'woocommerce_subscription_price_string_details', $subscription_details, $subscription );

	// Translators: placeholder is a subscription's price string. For example, "$5 / month for 12 months".
	echo sprintf( esc_html__( 'Period: %s', 'woocommerce-subscriptions' ), wp_kses_post( wcs_price_string( $subscription_details ) ) ) . "\n";

	echo "----------\n";
}
