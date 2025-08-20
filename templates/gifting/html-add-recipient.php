<?php
/**
 * Add recipient details
 *
 * @package WooCommerce Subscriptions Gifting/Templates
 * @version 2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<div class="wcsg_add_recipient_fields_container">
	<input type="checkbox" id="gifting_<?php echo esc_attr( $id ); ?>_option" class="woocommerce_subscription_gifting_checkbox <?php echo esc_attr( implode( ' ', $checkbox_field_args['class'] ) ); ?>" style="<?php echo esc_attr( implode( '; ', $checkbox_field_args['style_attributes'] ) ); ?>" value="gift" <?php checked( $checkbox_field_args['checked'] ); ?> <?php disabled( $checkbox_field_args['disabled'] ); ?> />
	<label for="gifting_<?php echo esc_attr( $id ); ?>_option">
		<?php echo esc_html( apply_filters( 'wcsg_enable_gifting_checkbox_label', get_option( WCSG_Admin::$option_prefix . '_gifting_checkbox_text', __( 'This is a gift', 'woocommerce-subscriptions' ) ) ) ); ?>
	</label>
	<div class="wcsg_add_recipient_fields <?php echo esc_attr( implode( ' ', $container_css_class ) ); ?>" style="<?php echo esc_attr( implode( ' ', $container_style_attributes ) ); ?>">
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<p class="form-row form-row <?php echo esc_attr( implode( ' ', $email_field_args['class'] ) ); ?>" style="<?php echo esc_attr( implode( '; ', $email_field_args['style_attributes'] ) ); ?>">
			<input 
				aria-label="<?php echo esc_attr( __(
					'Gifting recipient',
					'woocommerce-subscriptions'
				) ); ?>"
				data-recipient="<?php echo esc_attr( $email ); ?>"
				type="email"
				class="input-text recipient_email"
				name="recipient_email[<?php echo esc_attr( $id ); ?>]" id="recipient_email[<?php echo esc_attr( $id ); ?>]"
				placeholder="<?php echo esc_attr( $email_field_args['placeholder'] ); ?>"
				value="<?php echo esc_attr( $email );
			?>
		"/>
		</p>
		<?php do_action( 'wcsg_add_recipient_fields' ); ?>
		<div class="wc-shortcode-components-validation-error" role="alert">
			<p id="shortcode-validate-error-invalid-gifting-recipient">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 24 24" width="24" height="24" aria-hidden="true" focusable="false">
					<path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z">

					</path>
				</svg>
				<span><?php esc_html_e( 'Please enter a valid email address', 'woocommerce-subscriptions' ); ?></span>
			</p>
		</div>
	</div>
</div>
