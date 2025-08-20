<?php
/**
 * Order details table shown in emails.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo esc_html( $title ) . "\n\n";

echo wp_kses_post(
	WCSG_Email::recipient_email_order_items_table(
		$order,
		array(
			'show_sku'      => $sent_to_admin,
			'show_image'    => '',
			'image_size'    => '',
			'plain_text'    => $plain_text,
			'sent_to_admin' => $sent_to_admin,
		)
	)
);

echo "----------\n";
