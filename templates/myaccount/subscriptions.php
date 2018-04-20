<?php
/**
 * My Account > Subscriptions page
 *
 * @author   Prospress
 * @category WooCommerce Subscriptions/Templates
 * @version  2.0.15
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

WC_Subscriptions::get_my_subscriptions_template( $current_page );
