<?php
/**
 * My Account Recipient Details Content Container
 *
 * This template is based on WooCommerce Core's @see templates/myaccount/my-account.php.
 * This template doesn't display the My Account navigation and the main content container of this template is full width.
 *
 * @package WooCommerce Subscriptions Gifting/Templates
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wc_print_notices(); ?>

<div class="wcs-gifting-recipient-details-content">
	<?php do_action( 'woocommerce_account_content' ); ?>
</div>
