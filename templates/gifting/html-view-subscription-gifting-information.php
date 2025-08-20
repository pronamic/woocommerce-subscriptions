<?php
/**
 * Gift information template.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

<table class="woocommerce-table woocommerce-table--subscription-gifting-information shop_table subscription_gifting_information">
	<tr>
		<th><?php echo esc_html( $user_title ); ?>:</th>
		<td data-title="<?php echo esc_attr( $user_title ); ?>"> <?php echo wp_kses( $name, wp_kses_allowed_html( 'user_description' ) ); ?></td>
	</tr>
</table>
