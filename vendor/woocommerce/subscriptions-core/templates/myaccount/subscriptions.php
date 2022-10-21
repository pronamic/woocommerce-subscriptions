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

WCS_Template_Loader::get_my_subscriptions( $current_page );
