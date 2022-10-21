<?php
/**
 * The template for displaying an admin notice to report fatal errors which ocurred while processing PayPal IPNs.
 *
 * @version 2.4.0
 * @var string $last_ipn_error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<p>
	<?php
	// translators: $1 and $2 are opening link tags, $3 is a closing link tag.
	printf(
		esc_html__( 'A fatal error has occurred while processing a recent subscription payment with PayPal. Please %1$sopen a new ticket at WooCommerce Support%3$s immediately to get this resolved. %2$sLearn more &raquo;%3$s', 'woocommerce-subscriptions' ),
		'<a href="https://woocommerce.com/my-account/marketplace-ticket-form/" target="_blank">',
		'<a href="https://docs.woocommerce.com/document/debug-subscriptions-paypal-ipn-issues/#section-1">',
		'</a>'
	);
	?>
</p>
<p>
	<?php
	// translators: $1 and $2 are opening link tags, $3 is a closing link tag.
	printf(
		esc_html__( 'To resolve this as quickly as possible, please create a %1$stemporary administrator account%3$s with the user email woologin@woocommerce.com and share the credentials with us via %2$sQuickForget.com%3$s.', 'woocommerce-subscriptions' ),
		'<a href="https://docs.woocommerce.com/document/create-new-admin-account-wordpress/" target="_blank">',
		'<a href="https://quickforget.com/" target="_blank">',
		'</a>'
	);
	?>
</p>
<?php esc_html_e( 'Last recorded error:', 'woocommerce-subscriptions' ); ?>
<code>
<?php
	echo esc_html( $last_ipn_error );
?>
</code>
<p>
	<?php
	// translators: $1 is the log file name. $2 and $3 are opening and closing link tags, respectively.
	printf(
		esc_html__( 'To see the full error, view the %1$s log file from the %2$sWooCommerce logs screen.%3$s.', 'woocommerce-subscriptions' ),
		'<code>' . esc_html( $failed_ipn_log_handle ) . '</code>',
		'<a href="' . esc_url( $log_file_url ) . '">',
		'</a>'
	);
	?>
</p>
