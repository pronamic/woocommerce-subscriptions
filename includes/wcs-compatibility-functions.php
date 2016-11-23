<?php
/**
 * WooCommerce Compatibility functions
 *
 * Functions to take advantage of APIs added to new versions of WooCommerce while maintaining backward compatibility.
 *
 * @author 		Prospress
 * @category 	Core
 * @package 	WooCommerce Subscriptions/Functions
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Display a tooltip in the WordPress administration area.
 *
 * Uses wc_help_tip() when WooCommerce 2.5+ is active, otherwise it manually prints the HTML for a tooltip.
 *
 * @param string $tip The content to display in the tooltip.
 * @since  2.1.0
 * @return string
 */
function wcs_help_tip( $tip, $allow_html = false ) {

	if ( function_exists( 'wc_help_tip' ) ) {

		$help_tip = wc_help_tip( $tip, $allow_html );

	} else {

		if ( $allow_html ) {
			$tip = wc_sanitize_tooltip( $tip );
		} else {
			$tip = esc_attr( $tip );
		}

		$help_tip = sprintf( '<img class="help_tip" data-tip="%s" src="%s/assets/images/help.png" height="16" width="16" />', $tip, esc_url( WC()->plugin_url() ) );
	}

	return $help_tip;
}
