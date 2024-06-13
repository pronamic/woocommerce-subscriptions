<?php
/**
 * WooCommerce Subscriptions CLI class.
 *
 * @since 6.3.0
 */
defined( 'ABSPATH' ) || exit;


class WC_Subscriptions_CLI {

	/**
	 * Loads WooCommerce Subscriptions CLI related hooks.
	 */
	public function __construct() {
		WP_CLI::add_hook( 'before_invoke:wc shop_order subscriptions create', [ $this, 'abort_create_subscriptions_from_order' ] );
	}

	/**
	 * Return an error when the `wc shop_order subscriptions create` WP CLI command is used.
	 *
	 * WooCommerce core adds WP CLI commands for each WC REST API endpoints beginning with /wc/v2. This means all of our subscription
	 * REST API endpoints are added. While the `wc shop_order subscriptions create` CLI command technically works, WooCommerce doesn't have support for
	 * batch creation via CLI and results in the success message not being displayed correctly.
	 *
	 * @param string $command The command name.
	 */
	public function abort_create_subscriptions_from_order( $command ) {
		WP_CLI::error( "The '{$command}' command isn't supported via WP CLI." );
	}
}
