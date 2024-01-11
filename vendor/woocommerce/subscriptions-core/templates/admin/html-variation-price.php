<?php
/**
 * Outputs a subscription variation's pricing fields for WooCommerce 2.3+
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.12
 *
 * @var int $loop
 * @var WP_POST $variation
 * @var WC_Product_Subscription_Variation $variation_product
 * @var string $billing_period
 * @var array $variation_data array of variation data
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="variable_subscription_trial variable_subscription_pricing_2_3 show_if_variable-subscription variable_subscription_trial_sign_up" style="display: none">
	<p class="form-row form-row-first form-field show_if_variable-subscription sign-up-fee-cell">
		<label for="variable_subscription_sign_up_fee[<?php echo esc_attr( $loop ); ?>]"><?php printf( esc_html__( 'Sign-up fee (%s)', 'woocommerce-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) ); ?></label>
		<input type="text" class="wc_input_price wc_input_subscription_intial_price wc_input_subscription_initial_price" name="variable_subscription_sign_up_fee[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( WC_Subscriptions_Product::get_sign_up_fee( $variation_product ) ) ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. 9.90', 'example price', 'woocommerce-subscriptions' ); ?>">
	</p>
	<p class="form-row form-row-last show_if_variable-subscription">
		<label for="variable_subscription_trial_length[<?php echo esc_attr( $loop ); ?>]">
			<?php esc_html_e( 'Free trial', 'woocommerce-subscriptions' ); ?>
			<?php // translators: placeholder is trial period validation message if passed an invalid value (e.g. "Trial period can not exceed 4 weeks") ?>
			<?php echo wcs_help_tip( sprintf( _x( 'An optional period of time to wait before charging the first recurring payment. Any sign up fee will still be charged at the outset of the subscription. %s', 'Trial period dropdown\'s description in pricing fields', 'woocommerce-subscriptions' ), self::get_trial_period_validation_message() ) ); ?>
		</label>
		<input type="text" class="wc_input_subscription_trial_length" name="variable_subscription_trial_length[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( WC_Subscriptions_Product::get_trial_length( $variation_product ) ); ?>">

		<label for="variable_subscription_trial_period[<?php echo esc_attr( $loop ); ?>]" class="wcs_hidden_label"><?php esc_html_e( 'Subscription trial period:', 'woocommerce-subscriptions' ); ?></label>
		<select name="variable_subscription_trial_period[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_trial_period wc-enhanced-select">
		<?php foreach ( wcs_get_available_time_periods() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, WC_Subscriptions_Product::get_trial_period( $variation_product ) ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
	</p>
</div>
<div class="variable_subscription_pricing variable_subscription_pricing_2_3 show_if_variable-subscription" style="display: none">
	<p class="form-row form-row-first form-field show_if_variable-subscription _subscription_price_field">
		<label for="variable_subscription_price[<?php echo esc_attr( $loop ); ?>]">
			<?php
			// translators: placeholder is a currency symbol / code
			printf( esc_html__( 'Subscription price (%s)', 'woocommerce-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) );
			?>
		</label>
		<input type="text" class="wc_input_price wc_input_subscription_price" name="variable_subscription_price[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( WC_Subscriptions_Product::get_regular_price( $variation_product ) ) ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. 9.90', 'example price', 'woocommerce-subscriptions' ); ?>">

		<label for="variable_subscription_period_interval[<?php echo esc_attr( $loop ); ?>]" class="wcs_hidden_label"><?php esc_html_e( 'Billing interval:', 'woocommerce-subscriptions' ); ?></label>
		<select name="variable_subscription_period_interval[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_period_interval wc-enhanced-select">
		<?php foreach ( wcs_get_subscription_period_interval_strings() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, WC_Subscriptions_Product::get_interval( $variation_product ) ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>

		<label for="variable_subscription_period[<?php echo esc_attr( $loop ); ?>]" class="wcs_hidden_label"><?php esc_html_e( 'Billing Period:', 'woocommerce-subscriptions' ); ?></label>
		<select name="variable_subscription_period[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_period wc-enhanced-select">
		<?php foreach ( wcs_get_subscription_period_strings() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $billing_period ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>

	</p>
	<p class="form-row form-row-last show_if_variable-subscription _subscription_length_field" style="display: none">
		<label for="variable_subscription_length[<?php echo esc_attr( $loop ); ?>]">
			<?php esc_html_e( 'Stop renewing after', 'woocommerce-subscriptions' ); ?>
			<?php echo wcs_help_tip( _x( 'Automatically stop renewing the subscription after this length of time. This length is in addition to any free trial or amount of time provided before a synchronised first renewal date.', 'Subscription Length dropdown\'s description in pricing fields', 'woocommerce-subscriptions' ) ); ?>
		</label>
		<select name="variable_subscription_length[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_length wc-enhanced-select">
		<?php foreach ( wcs_get_subscription_ranges( $billing_period ) as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, WC_Subscriptions_Product::get_length( $variation_product ) ); ?>> <?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
	</p>
</div>
