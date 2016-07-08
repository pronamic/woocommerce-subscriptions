<?php
/**
 * Outputs a subscription variation's pricing fields for WooCommerce 2.3
 *
 * @var int $loop
 * @var WP_POST $variation
 * @var string $subscription_period
 * @var array $variation_data array of variation data
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$chosen_interval     = get_post_meta( $variation->ID, '_subscription_period_interval', true );
$chosen_length       = get_post_meta( $variation->ID, '_subscription_length', true );
$chosen_trial_period = WC_Subscriptions_Product::get_trial_period( $variation->ID );
?>
<div class="variable_subscription_trial variable_subscription_pricing_2_3 show_if_variable-subscription variable_subscription_trial_sign_up">
	<p class="form-row form-row-first form-field show_if_variable-subscription sign-up-fee-cell">
		<label for="variable_subscription_sign_up_fee[<?php echo esc_attr( $loop ); ?>]"><?php printf( esc_html__( 'Sign-up Fee: (%s)', 'woocommerce-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) ); ?></label>
		<input type="text" class="wc_input_subscription_intial_price" name="variable_subscription_sign_up_fee[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( get_post_meta( $variation->ID, '_subscription_sign_up_fee', true ) ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. 9.90', 'example price', 'woocommerce-subscriptions' ); ?>">
	</p>
	<p class="form-row form-row-last show_if_variable-subscription">
		<label for="variable_subscription_trial_length[<?php echo esc_attr( $loop ); ?>]">
			<?php esc_html_e( 'Free Trial:', 'woocommerce-subscriptions' ); ?> <a class="tips" data-tip="<?php
				// translators: placeholder is trial period validation message if passed an invalid value (e.g. "Trial period can not exceed 4 weeks")
				printf( esc_attr_x( 'An optional period of time to wait before charging the first recurring payment. Any sign up fee will still be charged at the outset of the subscription. %s', 'Trial period dropdown\'s description in pricing fields', 'woocommerce-subscriptions' ), esc_html( self::get_trial_period_validation_message() ) );
			?>" href="#">[?]</a>
		</label>
		<input type="text" class="wc_input_subscription_trial_length" name="variable_subscription_trial_length[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( get_post_meta( $variation->ID, '_subscription_trial_length', true ) ); ?>">

		<label for="variable_subscription_period[<?php echo esc_attr( $loop ); ?>]" class="wcs_hidden_label"><?php esc_html_e( 'Subscription Trial Period:', 'woocommerce-subscriptions' ); ?></label>
		<select name="variable_subscription_trial_period[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_trial_period">
		<?php foreach ( wcs_get_available_time_periods() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $chosen_trial_period ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
	</p>
</div>
<div class="variable_subscription_pricing variable_subscription_pricing_2_3 show_if_variable-subscription">
	<p class="form-row form-row-first form-field show_if_variable-subscription _subscription_price_field">
		<label for="variable_subscription_price[<?php echo esc_attr( $loop ); ?>]">
			<?php
			// translators: placeholder is a currency symbol / code
			printf( esc_html__( 'Subscription Price (%s)', 'woocommerce-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) );
			?>
		</label>
		<input type="text" class="wc_input_subscription_price" name="variable_subscription_price[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( get_post_meta( $variation->ID, '_subscription_price', true ) ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. 9.90', 'example price', 'woocommerce-subscriptions' ); ?>">
		<label for="variable_subscription_period[<?php echo esc_attr( $loop ); ?>]" class="wcs_hidden_label"><?php esc_html_e( 'Billing Period:', 'woocommerce-subscriptions' ); ?></label>
		<select name="variable_subscription_period[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_period">
		<?php foreach ( wcs_get_subscription_period_strings() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $subscription_period ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>

		<label for="variable_subscription_period_interval[<?php echo esc_attr( $loop ); ?>]" class="wcs_hidden_label"><?php esc_html_e( 'Billing Interval:', 'woocommerce-subscriptions' ); ?></label>
		<select name="variable_subscription_period_interval[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_period_interval">
		<?php foreach ( wcs_get_subscription_period_interval_strings() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $chosen_interval ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
	</p>
	<p class="form-row form-row-last show_if_variable-subscription _subscription_length_field">
		<label for="variable_subscription_length[<?php echo esc_attr( $loop ); ?>]"><?php esc_html_e( 'Subscription Length:', 'woocommerce-subscriptions' ); ?></label>
		<select name="variable_subscription_length[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_length">
		<?php foreach ( wcs_get_subscription_ranges( $subscription_period ) as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $chosen_length ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
	</p>
</div>
