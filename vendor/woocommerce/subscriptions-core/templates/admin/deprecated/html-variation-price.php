<?php
/**
 * Outputs a subscription variation's pricing fields for WooCommerce prior to 2.3
 *
 * @var int $loop
 * @var WP_POST $variation
 * @var string $subscription_period
 * @var array $variation_data array of variation data
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<tr class="variable_subscription_pricing show_if_variable-subscription">
	<td colspan="2">
		<label>
			<?php
			// translators: placeholder is a currency symbol / code
			printf( esc_html__( 'Subscription Price (%s)', 'woocommerce-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) );
			?>
		</label>
		<?php
		// Subscription Price
		woocommerce_wp_text_input( array(
			'id'            => 'variable_subscription_price[' . $loop . ']',
			'class'         => 'wc_input_subscription_price wc_input_price',
			'wrapper_class' => '_subscription_price_field',
			// translators: placeholder is a currency symbol / code
			'label'         => sprintf( __( 'Subscription Price (%s)', 'woocommerce-subscriptions' ), get_woocommerce_currency_symbol() ),
			'placeholder'   => _x( 'e.g. 9.90', 'example price', 'woocommerce-subscriptions' ),
			'value'         => get_post_meta( $variation->get_id(), '_subscription_price', true ),
			'type'          => 'number',
			'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
			)
		);

		// Subscription Period Interval
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_period_interval[' . $loop . ']',
			'class'         => 'wc_input_subscription_period_interval wc-enhanced-select',
			'wrapper_class' => '_subscription_period_interval_field',
			'label'         => __( 'Subscription Periods', 'woocommerce-subscriptions' ),
			'options'       => wcs_get_subscription_period_interval_strings(),
			'value'         => get_post_meta( $variation->get_id(), '_subscription_period_interval', true ),
			)
		);

		// Billing Period
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_period[' . $loop . ']',
			'class'         => 'wc_input_subscription_period wc-enhanced-select',
			'wrapper_class' => '_subscription_period_field',
			'label'         => __( 'Billing Period', 'woocommerce-subscriptions' ),
			'value'         => $subscription_period,
			'description'   => _x( 'for', 'Edit product screen, between the Billing Period and Subscription Length dropdowns', 'woocommerce-subscriptions' ),
			'options'       => wcs_get_subscription_period_strings(),
			)
		);

		// Subscription Length
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_length[' . $loop . ']',
			'class'         => 'wc_input_subscription_length wc-enhanced-select',
			'wrapper_class' => '_subscription_length_field',
			'label'         => __( 'Subscription Length', 'woocommerce-subscriptions' ),
			'options'       => wcs_get_subscription_ranges( $subscription_period ),
			'value'         => get_post_meta( $variation->get_id(), '_subscription_length', true ),
			)
		);
?>
	</td>
</tr>
<tr class="variable_subscription_trial show_if_variable-subscription variable_subscription_trial_sign_up">
	<td class="sign-up-fee-cell show_if_variable-subscription">
<?php
		// Sign-up Fee
		woocommerce_wp_text_input( array(
			'id'            => 'variable_subscription_sign_up_fee[' . $loop . ']',
			'class'         => 'wc_input_subscription_intial_price',
			'wrapper_class' => '_subscription_sign_up_fee_field',
			'label'         => sprintf( __( 'Sign-up Fee (%s)', 'woocommerce-subscriptions' ), get_woocommerce_currency_symbol() ),
			'placeholder'   => _x( 'e.g. 9.90', 'example price', 'woocommerce-subscriptions' ),
			'value'         => get_post_meta( $variation->get_id(), '_subscription_sign_up_fee', true ),
			'type'          => 'number',
			'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
			)
		);
?>	</td>
	<td colspan="1" class="show_if_variable-subscription">
		<label><?php esc_html_e( 'Free Trial', 'woocommerce-subscriptions' ); ?></label>
<?php
		// Trial Length
		woocommerce_wp_text_input( array(
			'id'            => 'variable_subscription_trial_length[' . $loop . ']',
			'class'         => 'wc_input_subscription_trial_length',
			'wrapper_class' => '_subscription_trial_length_field',
			'label'         => __( 'Free Trial', 'woocommerce-subscriptions' ),
			'placeholder'   => _x( 'e.g. 3', 'example number of days / weeks / months', 'woocommerce-subscriptions' ),
			'value'         => get_post_meta( $variation->get_id(), '_subscription_trial_length', true ),
			)
		);

		// Trial Period
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_trial_period[' . $loop . ']',
			'class'         => 'wc_input_subscription_trial_period wc-enhanced-select',
			'wrapper_class' => '_subscription_trial_period_field',
			'label'         => __( 'Subscription Trial Period', 'woocommerce-subscriptions' ),
			'options'       => wcs_get_available_time_periods(),
			// translators: placeholder is trial period validation message if passed an invalid value (e.g. "Trial period can not exceed 4 weeks")
			'description'   => sprintf( _x( 'An optional period of time to wait before charging the first recurring payment. Any sign up fee will still be charged at the outset of the subscription. %s', 'Trial period dropdown\'s description in pricing fields', 'woocommerce-subscriptions' ), self::get_trial_period_validation_message() ),
			'desc_tip'      => true,
			'value'         => WC_Subscriptions_Product::get_trial_period( $variation->get_id() ), // Explicity set value in to ensure backward compatibility
			)
		);?>
	</td>
</tr>
