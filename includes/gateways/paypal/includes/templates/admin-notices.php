<?php
/**
 * Show PayPal admin notices
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	Gateways/PayPal
 * @category	Class
 * @author		Prospress
 * @since		2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

foreach ( $notices as $notice ) : ?>
	<?php $notice['type'] = ! isset( $notice['type'] ) ? 'error' : $notice['type']; ?>
	<?php
	switch ( $notice['type'] ) {
		case 'warning' :
			echo '<div class="updated" style="border-left: 4px solid #ffba00">';
			break;
		case 'error' :
			echo '<div class="updated error">';
			break;
		case 'confirmation' :
		default :
			echo '<div class="updated">';
			break;
	} ?>
	<p><?php echo wp_kses_post( $notice['text'] ); ?></p>
</div>
<?php endforeach;
