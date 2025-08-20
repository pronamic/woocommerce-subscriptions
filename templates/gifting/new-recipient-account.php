<?php
/**
 * New recipient account template.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

wc_print_notices(); ?>

<p>
<?php
	esc_html_e( 'We just need a few details from you to complete your account creation.', 'woocommerce-subscriptions' );
?>
<br />
	<?php
	printf(
		/* translators: 1$: user's email, 2$-3$: opening and closing link tags, logs the user out. */
		esc_html__( '(not %1$s? %2$sSign out%3$s)', 'woocommerce-subscriptions' ),
		esc_html( wp_get_current_user()->user_email ),
		'<a href="' . esc_url( wc_get_endpoint_url( 'customer-logout', '', wc_get_page_permalink( 'myaccount' ) ) ) . '">',
		'</a>'
	);
	?>
</p>
<form action="" method="post">
<?php

$form_fields = WCSG_Recipient_Details::get_new_recipient_account_form_fields( WC()->countries->get_base_country() );

foreach ( $form_fields as $key => $field ) {
	if ( 'shipping_company' === $key ) {
		?>
		<h3>
		<?php
		esc_html_e( 'Shipping Address', 'woocommerce-subscriptions' );
		?>
		</h3>
		<?php
	}
	$value = isset( $field['default'] ) ? $field['default'] : '';

	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	if ( ! empty( $_POST[ $key ] ) && ! empty( $_POST['_wcsgnonce'] ) && wp_verify_nonce( $_POST['_wcsgnonce'], 'wcsg_new_recipient_data' ) ) {
		$value = wc_clean( $_POST[ $key ] );
	}
	woocommerce_form_field( $key, $field, $value );
	// phpcs:enable
}
wp_nonce_field( 'wcsg_new_recipient_data', '_wcsgnonce' );

?>
<input type="hidden" name="wcsg_new_recipient_customer" value="<?php echo esc_attr( get_current_user_id() ); ?>" />
<input type="submit" class="button" name="save_address" value="<?php esc_html_e( 'Save', 'woocommerce-subscriptions' ); ?>" />

</form>
