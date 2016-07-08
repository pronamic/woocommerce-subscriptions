<?php
/**
 * Cancelled Subscription email
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
	// translators: $1: customer's billing first name, $2: customer's billing last name
	printf( esc_html__( 'A subscription belonging to %1$s %2$s has been cancelled. Their subscription\'s details are as follows:', 'woocommerce-subscriptions' ), esc_html( $subscription->billing_first_name ), esc_html( $subscription->billing_last_name ) );
	?>
</p>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
	<thead>
		<tr>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Last Payment', 'table heading', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'End of Prepaid Term', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr class="order">
			<td width="1%" style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<a href="<?php echo esc_url( wcs_get_edit_post_link( $subscription->id ) ); ?>"><?php echo esc_html( $subscription->get_order_number() ); ?></a>
			</td>
			<td style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
			</td>
			<td style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo esc_html( $subscription->get_date_to_display( 'last_payment' ) ); ?>
			</td>
			<td style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'end', 'site' ) ) ); ?>
			</td>
		</tr>
	</tbody>
</table>
<br/>
<?php do_action( 'woocommerce_email_before_subscription_table', $subscription , true, false ); ?>
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

<?php do_action( 'woocommerce_email_customer_details', $subscription, true, false ); ?>

<?php do_action( 'woocommerce_email_footer' ); ?>
