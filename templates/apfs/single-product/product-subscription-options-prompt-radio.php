<?php
/**
 * Product Subscription Options Radio Prompt Template.
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/product-subscription-options-prompt-radio.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 4.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<fieldset class="wcsatt-options-prompt-fieldset">
	<legend class="screen-reader-text"><?php esc_html_e( 'Choose purchase type', 'woocommerce-subscriptions' ); ?></legend>
	<ul class="wcsatt-options-prompt-radios">
		<li class="wcsatt-options-prompt-radio">
			<label class="wcsatt-options-prompt-label wcsatt-options-prompt-label-one-time">
				<input class="wcsatt-options-prompt-action-input" type="radio" name="subscribe-to-action-input" value="no" />
				<span class="wcsatt-options-prompt-action"><?php echo wp_kses_post( $one_time_cta ); ?></span>
			</label>
		</li>
		<li class="wcsatt-options-prompt-radio">
			<label class="wcsatt-options-prompt-label wcsatt-options-prompt-label-subscription">
				<input class="wcsatt-options-prompt-action-input" type="radio" name="subscribe-to-action-input" value="yes" />
				<span class="wcsatt-options-prompt-action"><?php echo wp_kses_post( $subscription_cta ); ?></span>
			</label>
		</li>
	</ul>
</fieldset>
