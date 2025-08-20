<?php
/**
 * Un-editable gift recipient details fields for displaying the recipient of a cart item.
 *
 * @package WooCommerce Subscriptions Gifting/Templates
 * @version 2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<fieldset id="woocommerce_subscriptions_gifting_field">
	<label class="woocommerce_subscriptions_gifting_recipient_email">
		<?php esc_html_e( 'Recipient: ', 'woocommerce-subscriptions' ); ?>
	</label>
	<?php echo esc_html( $email ); ?>
</fieldset>
