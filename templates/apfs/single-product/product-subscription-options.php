<?php
/**
 * Product Subscription Options Template.
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/product-subscription-options.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 9.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure template variables are available with safe defaults when not passed by older callers.
$trial_length = isset( $trial_length ) ? $trial_length : 0;
$trial_period = isset( $trial_period ) ? $trial_period : 'day';
$signup_fee   = isset( $signup_fee ) ? $signup_fee : 0;
// Pre-formatted trial label (e.g. "1 week"). Falls back to the raw period string for older callers that don't pass it.
$trial_label  = isset( $trial_label ) && '' !== $trial_label ? $trial_label : wcs_get_subscription_period_strings( $trial_length, $trial_period );
?>

<div class="wcsatt-options-wrapper <?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>" data-sign_up_text="<?php echo esc_attr( $sign_up_text ); ?>" <?php echo $hide_wrapper ? 'style="display:none;"' : ''; ?>>
	<div class="wcsatt-options-product-prompt <?php echo esc_attr( implode( ' ', $prompt_classes ) ); ?>" data-prompt_type="<?php echo esc_attr( $prompt_type ); ?>">
		<?php
			// At this point, we're echoing template parts that can be overriden by themes.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $prompt;
		?>
	</div>
	<div class="wcsatt-options-product-wrapper" <?php echo in_array( 'closed', $wrapper_classes ) ? 'style="display:none;"' : ''; ?>>
															<?php

															if ( $display_dropdown ) {

																$select_id = 'wcsatt-options-product-dropdown-' . absint( $product_id );

																if ( $dropdown_label ) {
																	?>
				<label class="wcsatt-options-product-dropdown-label" for="<?php echo esc_attr( $select_id ); ?>"><?php echo wp_kses_post( $dropdown_label ); ?></label>
																	<?php
																} else {
																	// Provide a screen-reader-only label if no visible label is set.
																	?>
				<label class="wcsatt-options-product-dropdown-label screen-reader-text" for="<?php echo esc_attr( $select_id ); ?>"><?php esc_html_e( 'Select subscription option', 'woocommerce-subscriptions' ); ?></label>
																	<?php
																}

																?>
			<select class="wcsatt-options-product-dropdown" id="<?php echo esc_attr( $select_id ); ?>" name="convert_to_sub_dropdown<?php echo absint( $product_id ); ?>">
																<?php
																foreach ( $options as $option ) {

																	if ( ! $option['value'] ) {
																				continue;
																	}

																	?>
					<option <?php echo $option['selected'] ? 'selected="true"' : ''; ?>value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['dropdown'] ); ?></option>
																	<?php
																}
																?>
			</select>
			<span class="wcsatt-options-product-details" aria-live="polite" aria-atomic="true">
																<?php if ( $trial_length > 0 ) : ?>
					<p class="woocommerce-subscriptions-apfs-product__trial">
																	<?php
																	/* translators: %s: trial length string, e.g. "7 days" or "1 week" */
																	echo esc_html( sprintf( __( 'Free trial: %s', 'woocommerce-subscriptions' ), $trial_label ) );
																	?>
					</p>
				<?php endif; ?>
																<?php if ( $signup_fee > 0 ) : ?>
					<p class="woocommerce-subscriptions-apfs-product__signup-fee">
																	<?php
																	/* translators: %s: formatted signup fee amount */
																	echo wp_kses_post( sprintf( __( 'Sign-up fee: %s', 'woocommerce-subscriptions' ), wc_price( $signup_fee ) ) );
																	?>
					</p>
				<?php endif; ?>
			</span>
																<?php
															}

															?>
		<ul class="wcsatt-options-product wcsatt-options-product--<?php echo $display_dropdown ? 'hidden' : ''; ?>">
		<?php
		foreach ( $options as $option ) {
			?>
				<li class="<?php echo esc_attr( $option['class'] ); ?>">
					<label>
						<input type="radio" name="convert_to_sub_<?php echo absint( $product_id ); ?>" data-custom_data="<?php echo esc_attr( json_encode( $option['data'] ) ); ?>" value="<?php echo esc_attr( $option['value'] ); ?>" <?php checked( $option['selected'], true, true ); ?> />
						<span class="<?php echo esc_attr( $option['class'] ) . '-details'; ?>"><?php echo wp_kses_post( $option['description'] ); ?></span>
			<?php echo ''; ?>
					</label>
				</li>
				<?php
		}
		?>
		</ul>
	</div>
	<?php
		/**
		 * 'wcsatt_display_subscriptions_matching_cart' action.
		 *
		 * @since  3.1.25
		 */
		do_action( 'wcsatt_after_product_subscription_options' );
	?>
</div>
