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
		<div class="billing-schedule-edit wcs-date-input">
			<?php
				// Subscription Period Interval
				wcs_woocommerce_wp_select(
					array(
						'id'      => '_billing_interval',
						'class'   => 'billing_interval',
						'label'   => __( 'Payment:', 'woocommerce-subscriptions' ),
						'value'   => $the_subscription->get_billing_interval(),
						'options' => wcs_get_subscription_period_interval_strings(),
					),
					$the_subscription
				);

				// Billing Period
				wcs_woocommerce_wp_select(
					array(
						'id'      => '_billing_period',
						'class'   => 'billing_period',
						'label'   => __( 'Billing Period', 'woocommerce-subscriptions' ),
						'value'   => $the_subscription->get_billing_period(),
						'options' => wcs_get_subscription_period_strings(),
					),
					$the_subscription
				);
			?>
			<input type="hidden" name="wcs-lengths" id="wcs-lengths" data-subscription_lengths="<?php echo esc_attr( wcs_json_encode( wcs_get_subscription_ranges() ) ); ?>">
		</div>
		<?php else : ?>
		<strong><?php esc_html_e( 'Recurring:', 'woocommerce-subscriptions' ); ?></strong>
		<?php printf( '%s %s', esc_html( wcs_get_subscription_period_interval_strings( $the_subscription->get_billing_interval() ) ), esc_html( wcs_get_subscription_period_strings( 1, $the_subscription->get_billing_period() ) ) ); ?>
	<?php endif; ?>
	</div>
	<?php do_action( 'wcs_subscription_schedule_after_billing_schedule', $the_subscription ); ?>
	<?php foreach ( wcs_get_subscription_date_types() as $date_key => $date_label ) : ?>
		<?php $internal_date_key = wcs_normalise_date_type_key( $date_key ) ?>
		<?php if ( false === wcs_display_date_type( $date_key, $the_subscription ) ) : ?>
			<?php continue; ?>
		<?php endif; ?>
	<div id="subscription-<?php echo esc_attr( $date_key ); ?>-date" class="date-fields">
		<strong><?php echo esc_html( $date_label ); ?>:</strong>
		<input type="hidden" name="<?php echo esc_attr( $date_key ); ?>_timestamp_utc" id="<?php echo esc_attr( $date_key ); ?>_timestamp_utc" value="<?php echo esc_attr( $the_subscription->get_time( $internal_date_key, 'gmt' ) ); ?>"/>
		<?php if ( $the_subscription->can_date_be_updated( $internal_date_key ) ) : ?>
			<?php echo wp_kses( wcs_date_input( $the_subscription->get_time( $internal_date_key, 'site' ), array( 'name_attr' => $date_key ) ), array( 'input' => array( 'type' => array(), 'class' => array(), 'placeholder' => array(), 'name' => array(), 'id' => array(), 'maxlength' => array(), 'size' => array(), 'value' => array(), 'patten' => array() ), 'div' => array( 'class' => array() ), 'span' => array(), 'br' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound ?>
		<?php else : ?>
			<?php echo esc_html( $the_subscription->get_date_to_display( $internal_date_key ) ); ?>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
	<p><?php esc_html_e( 'Timezone:', 'woocommerce-subscriptions' ); ?> <span id="wcs-timezone"><?php esc_html_e( 'Error: unable to find timezone of your browser.', 'woocommerce-subscriptions' ); ?></span></p>
</div>
