<?php
/**
 * Subscription details table
 *
 * @author  Prospress
 * @package WooCommerce_Subscription/Templates
 * @since 2.2.19
 * @version 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<table class="shop_table subscription_details">
	<tr>
		<td><?php esc_html_e( 'Status', 'woocommerce-subscriptions' ); ?></td>
		<td><?php echo esc_html( wcs_get_subscription_status_name( $subscription->get_status() ) ); ?></td>
	</tr>
	<tr>
		<td><?php echo esc_html_x( 'Start Date', 'table heading',  'woocommerce-subscriptions' ); ?></td>
		<td><?php echo esc_html( $subscription->get_date_to_display( 'start_date' ) ); ?></td>
	</tr>
	<?php foreach ( array(
		'last_order_date_created' => _x( 'Last Order Date', 'admin subscription table header', 'woocommerce-subscriptions' ),
		'next_payment'            => _x( 'Next Payment Date', 'admin subscription table header', 'woocommerce-subscriptions' ),
		'end'                     => _x( 'End Date', 'table heading', 'woocommerce-subscriptions' ),
		'trial_end'               => _x( 'Trial End Date', 'admin subscription table header', 'woocommerce-subscriptions' ),
		) as $date_type => $date_title ) : ?>
		<?php $date = $subscription->get_date( $date_type ); ?>
		<?php if ( ! empty( $date ) ) : ?>
			<tr>
				<td><?php echo esc_html( $date_title ); ?></td>
				<td><?php echo esc_html( $subscription->get_date_to_display( $date_type ) ); ?></td>
			</tr>
		<?php endif; ?>
	<?php endforeach; ?>
	<?php if ( WCS_My_Account_Auto_Renew_Toggle::can_subscription_auto_renewal_be_changed( $subscription ) ) : ?>
		<tr>
			<td><?php esc_html_e( 'Auto Renew', 'woocommerce-subscriptions' ); ?></td>
			<td>
				<div class="wcs-auto-renew-toggle">
					<?php if ( $subscription->is_manual() ) : ?>
						<a href="#"
							class="subscription-auto-renew-toggle subscription-auto-renew-toggle--off subscription-auto-renew-toggle--hidden" aria-label="<?php echo esc_attr__( 'Enable auto renew', 'woocommerce-subscriptions' ); ?>">
							<i class="subscription-auto-renew-toggle__i" aria-hidden="true">&nbsp;</i>
						</a>
					<?php else : ?>
						<a href="#"
							class="subscription-auto-renew-toggle subscription-auto-renew-toggle--on subscription-auto-renew-toggle--hidden" aria-label="<?php echo esc_attr__( 'Disable auto renew', 'woocommerce-subscriptions' ); ?>">
							<i class="subscription-auto-renew-toggle__i" aria-hidden="true">&nbsp;</i>
						</a>
					<?php endif; ?>
				</div>
			</td>
		</tr>
	<?php endif; ?>
	<?php if ( $subscription->get_time( 'next_payment' ) > 0 ) : ?>
		<tr>
			<td><?php esc_html_e( 'Payment', 'woocommerce-subscriptions' ); ?></td>
			<td>
				<span class="subscription-payment-method"><?php echo esc_attr( $subscription->get_payment_method_to_display( 'customer' ) ); ?></span>
			</td>
		</tr>
	<?php endif; ?>
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
					<p class="meta"><?php echo esc_html( date_i18n( _x( 'l jS \o\f F Y, h:ia', 'date on subscription updates list. Will be localized', 'woocommerce-subscriptions' ), wcs_date_to_time( $note->comment_date ) ) ); ?></p>
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
