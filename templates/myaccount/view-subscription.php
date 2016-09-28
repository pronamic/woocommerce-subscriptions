<?php
/**
 * View Subscription
 *
 * Shows the details of a particular subscription on the account page
 *
 * @author    Prospress
 * @package   WooCommerce_Subscription/Templates
 * @version   2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( empty( $subscription ) ) {
	global $wp;

	if ( ! isset( $wp->query_vars['view-subscription'] ) || 'shop_subscription' != get_post_type( absint( $wp->query_vars['view-subscription'] ) ) || ! current_user_can( 'view_order', absint( $wp->query_vars['view-subscription'] ) ) ) {
		echo '<div class="woocommerce-error">' . esc_html__( 'Invalid Subscription.', 'woocommerce-subscriptions' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" class="wc-forward">'. esc_html__( 'My Account', 'woocommerce-subscriptions' ) .'</a>' . '</div>';
		return;
	}

	$subscription = wcs_get_subscription( $wp->query_vars['view-subscription'] );
}

wc_print_notices();
?>

<table class="shop_table subscription_details">
	<tr>
		<td><?php esc_html_e( 'Status', 'woocommerce-subscriptions' ); ?></td>
		<td><?php echo esc_html( wcs_get_subscription_status_name( $subscription->get_status() ) ); ?></td>
	</tr>
	<tr>
		<td><?php echo esc_html_x( 'Start Date', 'table heading',  'woocommerce-subscriptions' ); ?></td>
		<td><?php echo esc_html( $subscription->get_date_to_display( 'start' ) ); ?></td>
	</tr>
	<?php foreach ( array(
		'last_payment' => _x( 'Last Payment Date', 'admin subscription table header', 'woocommerce-subscriptions' ),
		'next_payment' => _x( 'Next Payment Date', 'admin subscription table header', 'woocommerce-subscriptions' ),
		'end'          => _x( 'End Date', 'table heading', 'woocommerce-subscriptions' ),
		'trial end'    => _x( 'Trial End Date', 'admin subscription table header', 'woocommerce-subscriptions' ),
		) as $date_type => $date_title ) : ?>
		<?php $date = $subscription->get_date( $date_type ); ?>
		<?php if ( ! empty( $date ) ) : ?>
			<tr>
				<td><?php echo esc_html( $date_title ); ?></td>
				<td><?php echo esc_html( $subscription->get_date_to_display( $date_type ) ); ?></td>
			</tr>
		<?php endif; ?>
	<?php endforeach; ?>
	<?php do_action( 'woocommerce_subscription_before_actions', $subscription ); ?>
	<?php $actions = wcs_get_all_user_actions_for_subscription( $subscription, get_current_user_id() ); ?>
	<?php if ( ! empty( $actions ) ) : ?>
		<tr>
			<td><?php esc_html_e( 'Actions', 'woocommerce-subscriptions' ); ?></td>
			<td>
				<?php foreach ( $actions as $key => $action ) : ?>
					<a href="<?php echo esc_url( $action['url'] ); ?>" class="button <?php echo sanitize_html_class( $key ) ?>"><?php echo esc_html( $action['name'] ); ?></a>
				<?php endforeach; ?>
			</td>
		</tr>
	<?php endif; ?>
	<?php do_action( 'woocommerce_subscription_after_actions', $subscription ); ?>
</table>
<?php if ( $notes = $subscription->get_customer_order_notes() ) :
	?>
	<h2><?php esc_html_e( 'Subscription Updates', 'woocommerce-subscriptions' ); ?></h2>
	<ol class="commentlist notes">
		<?php foreach ( $notes as $note ) : ?>
		<li class="comment note">
			<div class="comment_container">
				<div class="comment-text">
					<p class="meta"><?php echo esc_html( date_i18n( _x( 'l jS \o\f F Y, h:ia', 'date on subscription updates list. Will be localized', 'woocommerce-subscriptions' ), strtotime( $note->comment_date ) ) ); ?></p>
					<div class="description">
						<?php echo wp_kses_post( wpautop( wptexturize( $note->comment_content ) ) ); ?>
					</div>
	  				<div class="clear"></div>
	  			</div>
				<div class="clear"></div>
			</div>
		</li>
		<?php endforeach; ?>
	</ol>
<?php endif; ?>
<?php $allow_remove_item = wcs_can_items_be_removed( $subscription ); ?>
<h2><?php esc_html_e( 'Subscription Totals', 'woocommerce-subscriptions' ); ?></h2>
<table class="shop_table order_details">
	<thead>
		<tr>
			<?php if ( $allow_remove_item ) : ?>
			<th class="product-remove" style="width: 3em;">&nbsp;</th>
			<?php endif; ?>
			<th class="product-name"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th class="product-total"><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		if ( sizeof( $subscription_items = $subscription->get_items() ) > 0 ) {

			foreach ( $subscription_items as $item_id => $item ) {
				$_product  = apply_filters( 'woocommerce_subscriptions_order_item_product', $subscription->get_product_from_item( $item ), $item );
				$item_meta = wcs_get_order_item_meta( $item, $_product );
				if ( apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $subscription ) ); ?>">
						<?php if ( $allow_remove_item ) : ?>
							<td class="remove_item"><a href="<?php echo esc_url( WCS_Remove_Item::get_remove_url( $subscription->id, $item_id ) );?>" class="remove" onclick="return confirm('<?php printf( esc_html__( 'Are you sure you want remove this item from your subscription?', 'woocommerce-subscriptions' ) ); ?>');">&times;</a></td>
						<?php endif; ?>
						<td class="product-name">
							<?php
							if ( $_product && ! $_product->is_visible() ) {
								echo esc_html( apply_filters( 'woocommerce_order_item_name', $item['name'], $item ) );
							} else {
								echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', sprintf( '<a href="%s">%s</a>', get_permalink( $item['product_id'] ), $item['name'] ), $item ) );
							}

							echo wp_kses_post( apply_filters( 'woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf( '&times; %s', $item['qty'] ) . '</strong>', $item ) );

							// Allow other plugins to add additional product information here
							do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $subscription );

							$item_meta->display();

							if ( $_product && $_product->exists() && $_product->is_downloadable() && $subscription->is_download_permitted() ) {

								$download_files = $subscription->get_item_downloads( $item );
								$i              = 0;
								$links          = array();

								foreach ( $download_files as $download_id => $file ) {
									$i++;
									// translators: %1$s is the number of the file (only in plural!), %2$s: the name of the file
									$link_text = sprintf( _nx( 'Download file: %2$s', 'Download file %1$s: %2$s', count( $download_files ), 'Used as link text in view-subscription template', 'woocommerce-subscriptions' ), $i, $file['name'] );
									$links[] = '<small><a href="' . esc_url( $file['download_url'] ) . '">' . esc_html( $link_text ) . '</a></small>';
								}

								echo '<br/>' . wp_kses_post( implode( '<br/>', $links ) );
							}

							// Allow other plugins to add additional product information here
							do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $subscription );
							?>
						</td>
						<td class="product-total">
							<?php echo wp_kses_post( $subscription->get_formatted_line_subtotal( $item ) ); ?>
						</td>
					</tr>
					<?php
				}

				if ( $subscription->has_status( array( 'completed', 'processing' ) ) && ( $purchase_note = get_post_meta( $_product->id, '_purchase_note', true ) ) ) {
					?>
					<tr class="product-purchase-note">
						<td colspan="3"><?php echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) ); ?></td>
					</tr>
					<?php
				}
			}
		}
		?>
	</tbody>
		<tfoot>
		<?php
		$has_refund = false;

		if ( $total_refunded = $subscription->get_total_refunded() ) {
			$has_refund = true;
		}

		if ( $totals = $subscription->get_order_item_totals() ) {
			foreach ( $totals as $key => $total ) {
				$value = $total['value'];

				// Check for refund
				if ( $has_refund && 'order_total' === $key ) {
					$refunded_tax_del = '';
					$refunded_tax_ins = '';

					// Tax for inclusive prices
					if ( wc_tax_enabled() && 'incl' == $subscription->tax_display_cart ) {

						$tax_del_array = array();
						$tax_ins_array = array();

						if ( 'itemized' == get_option( 'woocommerce_tax_total_display' ) ) {

							foreach ( $subscription->get_tax_totals() as $code => $tax ) {
								$tax_del_array[] = sprintf( '%s %s', $tax->formatted_amount, $tax->label );
								$tax_ins_array[] = sprintf( '%s %s', wc_price( $tax->amount - $subscription->get_total_tax_refunded_by_rate_id( $tax->rate_id ), array( 'currency' => $subscription->get_order_currency() ) ), $tax->label );
							}
						} else {
							$tax_del_array[] = sprintf( '%s %s', wc_price( $subscription->get_total_tax(), array( 'currency' => $subscription->get_order_currency() ) ), WC()->countries->tax_or_vat() );
							$tax_ins_array[] = sprintf( '%s %s', wc_price( $subscription->get_total_tax() - $subscription->get_total_tax_refunded(), array( 'currency' => $subscription->get_order_currency() ) ), WC()->countries->tax_or_vat() );
						}

						if ( ! empty( $tax_del_array ) ) {
							// translators: placeholder is price string, denotes tax included in cart/order total
							$refunded_tax_del .= ' ' . sprintf( _x( '(Includes %s)', 'includes tax', 'woocommerce-subscriptions' ), implode( ', ', $tax_del_array ) );
						}

						if ( ! empty( $tax_ins_array ) ) {
							// translators: placeholder is price string, denotes tax included in cart/order total
							$refunded_tax_ins .= ' ' . sprintf( _x( '(Includes %s)', 'includes tax', 'woocommerce-subscriptions' ), implode( ', ', $tax_ins_array ) );
						}
					}

					$value = '<del>' . strip_tags( $subscription->get_formatted_order_total() ) . $refunded_tax_del . '</del> <ins>' . wc_price( $subscription->get_total() - $total_refunded, array( 'currency' => $subscription->get_order_currency() ) ) . $refunded_tax_ins . '</ins>';
				}
				?>
			<tr>
				<th scope="row" <?php echo ( $allow_remove_item ) ? 'colspan="2"' : ''; ?>><?php echo esc_html( $total['label'] ); ?></th>
				<td><?php echo wp_kses_post( $value ); ?></td>
			</tr>
				<?php
			}
		}

		// Check for refund
		if ( $has_refund ) { ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Refunded:', 'woocommerce-subscriptions' ); ?></th>
				<td>-<?php echo wp_kses_post( wc_price( $total_refunded, array( 'currency' => $subscription->get_order_currency() ) ) ); ?></td>
			</tr>
			<?php
		}

		// Check for customer note
		if ( '' != $subscription->customer_note ) { ?>
			<tr>
				<th scope="row"><?php echo esc_html_x( 'Note:', 'customer note', 'woocommerce-subscriptions' ); ?></th>
				<td><?php echo wp_kses_post( wptexturize( $subscription->customer_note ) ); ?></td>
			</tr>
		<?php } ?>
	</tfoot>
</table>

<?php do_action( 'woocommerce_subscription_details_after_subscription_table', $subscription ); ?>

<header>
	<h2><?php esc_html_e( 'Customer details', 'woocommerce-subscriptions' ); ?></h2>
</header>
<table class="shop_table shop_table_responsive customer_details">
	<?php
	if ( $subscription->billing_email ) {
		// translators: there is markup here, hence can't use Email: %s
		echo '<tr><th>' . esc_html_x( 'Email', 'heading in customer details on subscription detail page', 'woocommerce-subscriptions' ) . '</th><td data-title="' . esc_attr_x( 'Email', 'Used in data attribute for a td tag, escaped.', 'woocommerce-subscriptions' ) . '">' . esc_html( $subscription->billing_email ) . '</td></tr>';
	}

	if ( $subscription->billing_phone ) {
		// translators: there is markup here, hence can't use Email: %s
		echo '<tr><th>' . esc_html_x( 'Tel', 'heading in customer details on subscription detail page', 'woocommerce-subscriptions' ) . '</th><td data-title="' . esc_attr_x( 'Telephone', 'Used in data attribute for a td tag, escaped.', 'woocommerce-subscriptions' ) . '">' . esc_html( $subscription->billing_phone ) . '</td></tr>';
	}

	// Additional customer details hook
	do_action( 'woocommerce_order_details_after_customer_details', $subscription );
	?>
</table>

<?php if ( ! wc_ship_to_billing_address_only() && $subscription->needs_shipping_address() && get_option( 'woocommerce_calc_shipping' ) !== 'no' ) : ?>

<div class="col2-set addresses">

	<div class="col-1">

<?php endif; ?>

		<header class="title">
			<h3><?php esc_html_e( 'Billing Address', 'woocommerce-subscriptions' ); ?></h3>
		</header>
		<address>
			<?php
			if ( ! $subscription->get_formatted_billing_address() ) {
				echo esc_html_x( 'N/A', 'no information about something', 'woocommerce-subscriptions' );
			} else {
				echo wp_kses_post( $subscription->get_formatted_billing_address() );
			}
			?>
		</address>

<?php if ( ! wc_ship_to_billing_address_only() && $subscription->needs_shipping_address() && get_option( 'woocommerce_calc_shipping' ) !== 'no' ) : ?>

	</div><!-- /.col-1 -->

	<div class="col-2">

		<header class="title">
			<h3><?php esc_html_e( 'Shipping Address', 'woocommerce-subscriptions' ); ?></h3>
		</header>
		<address>
			<?php
			if ( ! $subscription->get_formatted_shipping_address() ) {
				echo esc_html_x( 'N/A', 'no information about something', 'woocommerce-subscriptions' );
			} else {
				echo wp_kses_post( $subscription->get_formatted_shipping_address() );
			}
			?>
		</address>

	</div><!-- /.col-2 -->

</div><!-- /.col2-set -->

<?php endif; ?>

<div class="clear"></div>
