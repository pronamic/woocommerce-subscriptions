<?php
/**
 * Outputs a subscription variation's payment date synchronisation fields for WooCommerce 2.3+
 *
 * @version 2.1.0
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
<div class="variable_subscription_sync show_if_variable-subscription variable_subscription_pricing_2_3">
	<div class="form-row form-row-full">
		<div class="subscription_sync_week_month" style="<?php echo esc_attr( $display_week_month_select ); ?>">
			<label for="variable_subscription_payment_sync_date[<?php echo esc_attr( $loop ); ?>]">
				<?php echo esc_html( WC_Subscriptions_Synchroniser::$sync_field_label ); ?>
				<?php echo wcs_help_tip( WC_Subscriptions_Synchroniser::$sync_description ); ?>
			</label>
			<select name="variable_subscription_payment_sync_date[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_payment_sync">
			<?php foreach ( WC_Subscriptions_Synchroniser::get_billing_period_ranges( $subscription_period ) as $key => $value ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $payment_day ); ?>><?php echo esc_html( $value ); ?></option>
			<?php endforeach; ?>
			</select>
		</div>
		<div class="subscription_sync_annual" style="<?php echo esc_attr( $display_annual_select ); ?>">
			<label for="variable_subscription_payment_sync_date_day[<?php esc_attr( $loop ); ?>]">
				<?php echo esc_html( WC_Subscriptions_Synchroniser::$sync_field_label ); ?>
				<?php echo wcs_help_tip( WC_Subscriptions_Synchroniser::$sync_description_year ); ?>
			</label>
			<input type="number" class="wc_input_subscription_payment_sync wc_input_subscription_payment_sync_day" name="variable_subscription_payment_sync_date_day[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $payment_day ); ?>" placeholder="<?php echo esc_attr_x( 'Day', 'input field placeholder for day field for annual subscriptions', 'woocommerce-subscriptions' ); ?>" step="1" min="0" max="31">
			<select name="variable_subscription_payment_sync_date_month[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_payment_sync wc_input_subscription_payment_sync_month">
			<?php foreach ( $wp_locale->month as $key => $value ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $payment_month ); ?>><?php echo esc_html( $value ); ?></option>
			<?php endforeach; ?>
			</select>
		</div>
	</div>
</div>
