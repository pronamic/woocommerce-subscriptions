<?php
/**
 * Display the billing schedule for a subscription
 *
 * @var object $the_subscription The WC_Subscription object to display the billing schedule for
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<div class="wc-metaboxes-wrapper">

	<div id="billing-schedule">
		<?php if ( $the_subscription->can_date_be_updated( 'next_payment' ) ) : ?>
		<div class="billing-schedule-edit wcs-date-input"><?php
			// Subscription Period Interval
			echo woocommerce_wp_select( array(
				'id'          => '_billing_interval',
				'class'       => 'billing_interval',
				'label'       => __( 'Recurring:', 'woocommerce-subscriptions' ),
				'value'       => empty( $the_subscription->billing_interval ) ? 1 : $the_subscription->billing_interval,
				'options'     => wcs_get_subscription_period_interval_strings(),
				)
			);

			// Billing Period
			echo woocommerce_wp_select( array(
				'id'          => '_billing_period',
				'class'       => 'billing_period',
				'label'       => __( 'Billing Period', 'woocommerce-subscriptions' ),
				'value'       => empty( $the_subscription->billing_period ) ? 'month' : $the_subscription->billing_period,
				'options'     => wcs_get_subscription_period_strings(),
				)
			);
			?>
			<input type="hidden" name="wcs-lengths" id="wcs-lengths" data-subscription_lengths="<?php echo esc_attr( wcs_json_encode( wcs_get_subscription_ranges() ) ); ?>">
		</div>
		<?php else : ?>
		<strong><?php esc_html_e( 'Recurring:', 'woocommerce-subscriptions' ); ?></strong>
		<?php printf( '%s %s', esc_html( wcs_get_subscription_period_interval_strings( $the_subscription->billing_interval ) ), esc_html( wcs_get_subscription_period_strings( 1, $the_subscription->billing_period ) ) ); ?>
	<?php endif; ?>
	</div>

	<?php foreach ( wcs_get_subscription_date_types() as $date_key => $date_label ) : ?>
		<?php if ( 'last_payment' === $date_key ) : ?>
			<?php continue; ?>
		<?php endif;?>
	<div id="subscription-<?php echo esc_attr( $date_key ); ?>-date" class="date-fields">
		<strong><?php echo esc_html( $date_label ); ?>:</strong>
		<input type="hidden" name="<?php echo esc_attr( $date_key ); ?>_timestamp_utc" id="<?php echo esc_attr( $date_key ); ?>_timestamp_utc" value="<?php echo esc_attr( $the_subscription->get_time( $date_key, 'gmt' ) ); ?>"/>
		<?php if ( $the_subscription->can_date_be_updated( $date_key ) ) : ?>
			<?php echo wp_kses( wcs_date_input( $the_subscription->get_time( $date_key, 'site' ), array( 'name_attr' => $date_key ) ), array( 'input' => array( 'type' => array(), 'class' => array(), 'placeholder' => array(), 'name' => array(), 'id' => array(), 'maxlength' => array(), 'size' => array(), 'value' => array(), 'patten' => array() ), 'div' => array( 'class' => array() ), 'span' => array(), 'br' => array() ) ); ?>
		<?php else : ?>
			<?php echo esc_html( $the_subscription->get_date_to_display( $date_key ) ); ?>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
	<p><?php esc_html_e( 'Timezone:', 'woocommerce-subscriptions' ); ?> <span id="wcs-timezone"><?php esc_html_e( 'Error: unable to find timezone of your browser.', 'woocommerce-subscriptions' ); ?></span></p>
</div>
