<?php
/**
 * Admin new switch order email
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 1.5
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<?php do_action( 'woocommerce_email_header', $email_heading ); ?>

<p>
	<?php
	$count = count( $subscriptions );
	// translators: $1: customer's first name, $2: customer's last name, $3: how many subscriptions customer switched
	echo esc_html( sprintf( _nx( 'Customer %1$s %2$s has switched their subscription. The details of their new subscription are as follows:', 'Customer %1$s %2$s has switched %3$d of their subscriptions. The details of their new subscriptions are as follows:', $count, 'Used in switch notification admin email', 'woocommerce-subscriptions' ), $order->billing_first_name, $order->billing_last_name, $count ) );
	?>
</p>

<h2><?php esc_html_e( 'Switch Order Details', 'woocommerce-subscriptions' ); ?></h2>

<p>
	<?php
	// translators: placeholder is the order's number
	echo wp_kses_post( sprintf( __( 'Order: %s', 'woocommerce-subscriptions' ), '<a href="' . esc_url( wcs_get_edit_post_link( $order->id ) ) . '">' . esc_html( $order->get_order_number() ) .'</a>' ) );
	?>
</p>

<?php do_action( 'woocommerce_email_before_order_table', $order, true, false ); ?>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
	<thead>
		<tr>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php echo wp_kses_post( WC_Subscriptions_Email::email_order_items_table( $order, array( 'show_download_links' => false, 'show_sku' => true ) ) ); ?>
	</tbody>
	<tfoot>
		<?php
		if ( $totals = $order->get_order_item_totals() ) {
			$i = 0;
			foreach ( $totals as $total ) {
				$i++;
				?>
				<tr>
					<th scope="row" colspan="2" style="text-align:left; border: 1px solid #eee; <?php if ( 1 == $i ) { echo 'border-top-width: 4px;'; } ?>"><?php echo wp_kses_post( $total['label'] ); ?></th>
					<td style="text-align:left; border: 1px solid #eee; <?php if ( 1 == $i ) { echo 'border-top-width: 4px;'; } ?>"><?php echo wp_kses_post( $total['value'] ); ?></td>
				</tr>
				<?php
			}
		}
		?>
	</tfoot>
</table>

<?php do_action( 'woocommerce_email_after_order_table', $order, true, false ); ?>
<?php do_action( 'woocommerce_email_order_meta', $order, true, false ); ?>

<h2><?php esc_html_e( 'New Subscription Details', 'woocommerce-subscriptions' ); ?></h2>

<?php foreach ( $subscriptions as $subscription ) : ?>
	<?php do_action( 'woocommerce_email_before_subscription_table', $subscription , true, false ); ?>
	<p><?php printf( esc_html__( 'Subscription %s', 'woocommerce-subscriptions' ), '<a href="' . esc_url( wcs_get_edit_post_link( $subscription->id ) ) . '">' . esc_html( $subscription->get_order_number() ) .'</a>' ); ?></p>

	<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
		<thead>
			<tr>
				<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php echo wp_kses_post( WC_Subscriptions_Email::email_order_items_table( $subscription, array( 'show_download_links' => false, 'show_sku' => true ) ) ); ?>
		</tbody>
		<tfoot>
			<?php
			if ( $totals = $subscription->get_order_item_totals() ) {
				$i = 0;
				foreach ( $totals as $total ) {
					$i++;
					?>
					<tr>
						<th scope="row" colspan="2" style="text-align:left; border: 1px solid #eee; <?php if ( 1 == $i ) { echo 'border-top-width: 4px;'; } ?>"><?php echo wp_kses_post( $total['label'] ); ?></th>
						<td style="text-align:left; border: 1px solid #eee; <?php if ( 1 == $i ) { echo 'border-top-width: 4px;'; } ?>"><?php echo wp_kses_post( $total['value'] ); ?></td>
					</tr>
					<?php
				}
			}
			?>
		</tfoot>
	</table>
	<?php do_action( 'woocommerce_email_after_subscription_table', $subscription , true, false ); ?>
<?php endforeach; ?>

<?php do_action( 'woocommerce_email_customer_details', $order, true, false ); ?>

<?php do_action( 'woocommerce_email_footer' ); ?>
