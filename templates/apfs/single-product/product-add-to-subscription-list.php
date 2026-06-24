<?php
/**
 * Single-Product Add-to-Subscription List Template.
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/product-add-to-subscription-list.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 3.4.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wc_get_template(
	'cart/cart-add-to-subscription-list.php',
	array(
		'subscriptions' => $subscriptions,
		'context'       => 'product',
	),
	false,
	plugin_dir_path( WC_Subscriptions::$plugin_file ) . '/templates/apfs/'
);
