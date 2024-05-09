<?php
/**
 * Outputs a subscription variation's payment date synchronisation fields for WooCommerce 2.2
 *
 * @var int $loop
 * @var WP_POST $variation
 * @var string $subscription_period
 * @var array $variation_data array of variation data
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
global $wp_locale;

?>
<tr class="variable_subscription_sync show_if_variable-subscription">
	<td colspan="1" class="subscription_sync_week_month"<?php echo esc_attr( $display_week_month_select ); ?>>
		<?php woocommerce_wp_select( array(
			'id'            => 'variable' . WC_Subscriptions_Synchroniser::$post_meta_key . '[' . $loop . ']',
			'class'         => 'wc_input_subscription_payment_sync wc-enhanced-select',
			'wrapper_class' => '_subscription_payment_sync_field',
			'label'         => WC_Subscriptions_Synchroniser::$sync_field_label,
			'options'       => WC_Subscriptions_Synchroniser::get_billing_period_ranges( $subscription_period ),
			'description'   => WC_Subscriptions_Synchroniser::$sync_description,
			'desc_tip'      => true,
			'value'         => $payment_day,
		) );?>
	</td>
	<td colspan="1" class="subscription_sync_annual"<?php echo esc_attr( $display_annual_select ); ?>>
		<label><?php esc_html_e( 'Synchronise Renewals', 'woocommerce-subscriptions' ); ?></label>
		<?php woocommerce_wp_text_input( array(
			'id'            => 'variable' . WC_Subscriptions_Synchroniser::$post_meta_key_day . '[' . $loop . ']',
			'class'         => 'wc_input_subscription_payment_sync wc-enhanced-select',
			'wrapper_class' => '_subscription_payment_sync_field',
			'label'         => WC_Subscriptions_Synchroniser::$sync_field_label,
			'placeholder'   => _x( 'Day', 'input field placeholder for day field for annual subscriptions', 'woocommerce-subscriptions' ),
			'value'         => $payment_day,
			'type'          => 'number',
			'custom_attributes' => array(
				'step' => '1',
				'min'  => '0',
				'max'  => '31',
			),
		) );

		woocommerce_wp_select( array(
			'id'            => 'variable' . WC_Subscriptions_Synchroniser::$post_meta_key_month . '[' . $loop . ']',
			'class'         => 'wc_input_subscription_payment_sync wc-enhanced-select',
			'wrapper_class' => '_subscription_payment_sync_field',
			'label'         => '',
			'options'       => $wp_locale->month,
			'description'   => WC_Subscriptions_Synchroniser::$sync_description_year,
			'desc_tip'      => true,
			'value'         => $payment_month, // Explicitly set value in to ensure backward compatibility
		) );
		?>
	</td>
</tr>
