<?php
/**
 * Automatic Failed Payment Retries Meta Box
 *
 * Display the automatic failed payment retries on the Edit Order screen for an order that has been automatically retried.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Meta Boxes
 * @version  2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WCS_Meta_Box_Payment_Retries Class
 */
class WCS_Meta_Box_Payment_Retries {

	/**
	 * Output the metabox
	 */
	public static function output( $post ) {

		WC()->mailer();

		$retries = WCS_Retry_Manager::store()->get_retries_for_order( $post->ID );

		include_once( dirname( __FILE__ ) . '/html-retries-table.php' );

		do_action( 'woocommerce_subscriptions_retries_meta_box', $post->ID, $retries );
	}
}
