<?php
/**
 * Admin new switch order email.
 *
 * Based on the WooCommerce core admin-new-order.php template.
 *
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 7.3.0 - Updated for WC core email improvements.
 */

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = wcs_is_wc_feature_enabled( 'email_improvements' );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );

$switched_count = count( $subscriptions );

echo $email_improvements_enabled ? '<div class="email-introduction">' : '';
?>
<p>
<?php
if ( 1 === $switched_count ) {
	/* translators: $1: customer's first name and last name */
	echo esc_html( sprintf( _x( 'Customer %1$s has switched their subscription. The details of their new subscription are as follows:', 'Used in switch notification admin email', 'woocommerce-subscriptions' ), $order->get_formatted_billing_full_name() ) );
} else {
	/* translators: $1: customer's first name and last name, $2: how many subscriptions customer switched */
	echo esc_html( sprintf( _x( 'Customer %1$s has switched %2$d of their subscriptions. The details of their new subscriptions are as follows:', 'Used in switch notification admin email', 'woocommerce-subscriptions' ), $order->get_formatted_billing_full_name(), $switched_count ) );
}
?>
</p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<h2><?php esc_html_e( 'Switch Order Details', 'woocommerce-subscriptions' ); ?></h2>

<?php
/**
 * @hooked WC_Subscriptions_Email::order_details() Shows the order details table.
 * @hooked WC_Subscriptions_Email::order_download_details() Shows the order downloads table.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1.0
 */
do_action( 'woocommerce_subscriptions_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
?>

<h2><?php esc_html_e( 'New subscription details', 'woocommerce-subscriptions' ); ?></h2>
<?php

foreach ( $subscriptions as $subscription ) {
	do_action( 'woocommerce_subscriptions_email_order_details', $subscription, $sent_to_admin, $plain_text, $email );
}

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<div class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</div>' : '';
}

do_action( 'woocommerce_email_footer', $email );
