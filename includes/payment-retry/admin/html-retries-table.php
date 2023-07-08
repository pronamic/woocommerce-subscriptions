<?php
/**
 * Display the automatic failed payment retires for an order
 *
 * @var array $retries An array of WCS_Retry objects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<div class="woocommerce_subscriptions_related_orders">
	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Retry Date', 'woocommerce-subscriptions' ); ?></th>
				<th>
					<?php esc_html_e( 'Retry Status', 'woocommerce-subscriptions' ); ?>
					<?php echo wc_help_tip( __( 'The status of the automatic payment retry: pending means the retry will be processed in the future, failed means the payment was not successful when retried and completed means the payment succeeded when retried.', 'woocommerce-subscriptions' ) ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Status of Order', 'woocommerce-subscriptions' ); ?>
					<?php echo wc_help_tip( __( 'The status applied to the order for the time between when the renewal payment failed or last retry occurred and when this retry was processed.', 'woocommerce-subscriptions' ) ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Status of Subscription', 'woocommerce-subscriptions' ); ?>
					<?php echo wc_help_tip( __( 'The status applied to the subscription for the time between when the renewal payment failed or last retry occurred and when this retry was processed.', 'woocommerce-subscriptions' ) ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Email', 'woocommerce-subscriptions' ); ?>
					<?php echo wc_help_tip( __( 'The email sent to the customer when the renewal payment or payment retry failed to notify them that the payment would be retried.', 'woocommerce-subscriptions' ) ); ?>
				</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $retries as $retry ) : ?>
				<?php $rule = $retry->get_rule(); ?>
			<tr>
				<td>
					<?php
					if ( $retry->get_time() > 0 ) {
						// translators: php date format
						$t_time          = date( _x( 'Y/m/d g:i:s A', 'post date', 'woocommerce-subscriptions' ), $retry->get_time() );
						$date_to_display = ucfirst( wcs_get_human_time_diff( $retry->get_time() ) );
					} else {
						$t_time = $date_to_display = __( 'Unpublished', 'woocommerce-subscriptions' );
					} ?>
					<abbr title="<?php echo esc_attr( $t_time ); ?>">
						<?php echo esc_html( apply_filters( 'post_date_column_time', $date_to_display, $retry->get_id() ) ); ?>
					</abbr>
				</td>
				<td>
					<?php echo esc_html( ucwords( $retry->get_status() ) ); ?>
				</td>
				<?php
				foreach ( array( 'order', 'subscription' ) as $object_type ) :
					$status       = $rule->get_status_to_apply( $object_type );
					$css_classes  = array( 'order-status', 'status-' . $status );

					if ( 'subscription' === $object_type ) :
						$status_name   = wcs_get_subscription_status_name( $status );
						$css_classes[] = 'subscription-status';
					else :
						$status_name = wc_get_order_status_name( $status );
					endif;
				?>
				<td>
					<mark class="<?php echo esc_attr( implode( ' ', $css_classes ) ); ?>"><span><?php echo esc_html( $status_name ); ?></span></mark>
				</td>
				<?php endforeach; ?>
				<td>
					<?php $email_class = $rule->get_email_template(); ?>
					<?php if ( ! empty( $email_class ) && class_exists( $email_class ) ) : ?>
						<?php $email = new $email_class(); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( $email_class ) ) ); ?>">
							<?php echo esc_html( $email->get_title() ); ?>
						</a>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
