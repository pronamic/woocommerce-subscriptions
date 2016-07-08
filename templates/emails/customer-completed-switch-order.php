<?php
/**
 * Customer completed subscription change email
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<?php do_action( 'woocommerce_email_header', $email_heading ); ?>

<p>
	<?php
	// translators: placeholder is the name of the site
	printf( esc_html__( 'Hi there. You have successfully changed your subscription items on %s. Your new order and subscription details are shown below for your reference:', 'woocommerce-subscriptions' ), esc_html( get_option( 'blogname' ) ) );
	?>
</p>

<?php do_action( 'woocommerce_email_before_order_table', $order, false, false ); ?>

<h2><?php echo esc_html__( 'Order Details', 'woocommerce-subscriptions' ); ?></h2>
<p>
	<?php
	// translators: placeholder is the order's number
	echo wp_kses_post( sprintf( __( 'Order: %s', 'woocommerce-subscriptions' ), '<a href="' . esc_url( $order->get_view_order_url() ) . '">' . esc_html( $order->get_order_number() ) . '</a>' ) );
	?>
</p>
<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
	<thead>
		<tr>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php echo wp_kses_post( WC_Subscriptions_Email::email_order_items_table( $order, array( 'show_download_links' => true, 'show_sku' => false, 'show_purchase_note' => true ) ) ); ?>
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

<?php do_action( 'woocommerce_email_after_order_table', $order, false, false ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, false, false ); ?>

<h2><?php echo esc_html__( 'New Subscription Details', 'woocommerce-subscriptions' ); ?></h2>
<?php foreach ( $subscriptions as $subscription ) : ?>
	<?php do_action( 'woocommerce_email_before_subscription_table', $subscription, false, false ); ?>

	<p><?php printf( esc_html__( 'Subscription %s', 'woocommerce-subscriptions' ), '<a href="' . esc_url( $subscription->get_view_order_url() ) . '">' . esc_html( $subscription->get_order_number() ) . '</a>' ); ?></p>

	<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
		<thead>
			<tr>
				<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php echo wp_kses_post( WC_Subscriptions_Email::email_order_items_table( $subscription, array( 'show_download_links' => true, 'show_sku' => false, 'show_purchase_note' => true ) ) ); ?>
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
	<?php do_action( 'woocommerce_email_after_subscription_table', $subscription, false, false ); ?>
<?php endforeach; ?>

<?php do_action( 'woocommerce_email_customer_details', $order, false, false ); ?>

<?php do_action( 'woocommerce_email_footer' ); ?>
