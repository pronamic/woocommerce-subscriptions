<?php
/**
 * Email addresses for the purchaser of a gifted subscription.
 *
 * Shows the purchaser their own billing details. The shipping address is the recipient's,
 * so a notice is shown in its place.
 *
 * @var WC_Order $order         The gifted subscription, or one of its renewal orders.
 * @var bool     $sent_to_admin Whether the email is sent to the store's admin.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails
 * @version 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$address                    = $order->get_formatted_billing_address();
$show_shipping              = ! wc_ship_to_billing_address_only() && $order->needs_shipping_address();
$email_improvements_enabled = wcs_is_wc_feature_enabled( 'email_improvements' );

/**
 * Filter whether to display the section divider in the email body. Documented in WooCommerce's templates/emails/email-addresses.php.
 *
 * @since 10.6.0
 * @param bool $display_section_divider Whether to display the section divider. Default true.
 */
$display_section_divider = (bool) apply_filters( 'woocommerce_email_body_display_section_divider', true );

?>
<?php if ( $display_section_divider ) : ?>
	<hr style="border: 0; border-top: 1px solid #1E1E1E; border-top-color: rgba(30, 30, 30, 0.2); margin: 20px 0;">
<?php endif; ?>
<table id="addresses" cellspacing="0" cellpadding="0" style="width: 100%; vertical-align: top; margin-bottom: <?php echo $email_improvements_enabled ? '0' : '40px'; ?>; padding:0;" border="0" role="presentation">
	<tr>
		<td class="font-family text-align-left" style="border:0; padding:0;" valign="top" width="50%">
			<?php if ( $email_improvements_enabled ) { ?>
				<b class="address-title"><?php esc_html_e( 'Billing address', 'woocommerce-subscriptions' ); ?></b>
			<?php } else { ?>
				<h2><?php esc_html_e( 'Billing address', 'woocommerce-subscriptions' ); ?></h2>
			<?php } ?>

			<address class="address">
				<?php echo wp_kses_post( $address ? $address : esc_html__( 'N/A', 'woocommerce-subscriptions' ) ); ?>
				<?php if ( $order->get_billing_phone() ) : ?>
					<br/><?php echo wc_make_phone_clickable( $order->get_billing_phone() ); ?>
				<?php endif; ?>
				<?php if ( $order->get_billing_email() ) : ?>
					<br/><?php echo esc_html( $order->get_billing_email() ); ?>
				<?php endif; ?>
				<?php
				/**
				 * Fires after the core address fields in emails. Documented in WooCommerce's templates/emails/email-addresses.php.
				 *
				 * @since 8.6.0
				 *
				 * @param string   $type          Address type. Either 'billing' or 'shipping'.
				 * @param WC_Order $order         Order instance.
				 * @param bool     $sent_to_admin If this email is being sent to the admin or not.
				 * @param bool     $plain_text    If this email is plain text or not.
				 */
				do_action( 'woocommerce_email_customer_address_section', 'billing', $order, $sent_to_admin, false );
				?>
			</address>
		</td>
		<?php if ( $show_shipping ) : ?>
			<td class="font-family text-align-left" style="padding:0;" valign="top" width="50%">
				<?php if ( $email_improvements_enabled ) { ?>
					<b class="address-title"><?php esc_html_e( 'Shipping address', 'woocommerce-subscriptions' ); ?></b>
				<?php } else { ?>
					<h2><?php esc_html_e( 'Shipping address', 'woocommerce-subscriptions' ); ?></h2>
				<?php } ?>

				<?php // WooCommerce's email styles give .address word-break: break-all, which splits a sentence mid-word in this column. ?>
				<address class="address" style="word-break: normal;">
					<?php echo esc_html( WCSG_Recipient_Addresses::get_withheld_shipping_address_message() ); ?>
				</address>
			</td>
		<?php endif; ?>
	</tr>
</table>
<?php echo $email_improvements_enabled ? '<br>' : ''; ?>
