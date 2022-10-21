<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Class for integrating with WooCommerce Blocks
 *
 * @package WooCommerce Subscriptions
 * @since   3.1.0
 */
class WCS_Blocks_Integration implements IntegrationInterface {
	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'subscriptions';
	}

	/**
	 * When called invokes any initialization/setup for the integration.
	 */
	public function initialize() {
		$script_path = 'build/index.js';
		$style_path  = 'build/index.css';

		$script_url = \WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( $script_path );
		$style_url  = \WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( $style_path );

		$script_asset_path = \WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'build/index.asset.php' );
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_asset_path ),
			);

		wp_register_script(
			'wc-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
		wp_set_script_translations(
			'wc-blocks-integration',
			'woocommerce-subscriptions',
			\WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'languages' )
		);
		wp_enqueue_style(
			'wc-blocks-integration',
			$style_url,
			'',
			$this->get_file_version( \WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'build/index.css' ) ),
			'all'
		);
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'wc-blocks-integration' );
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'wc-blocks-integration' );
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array(
			'woocommerce-subscriptions-blocks' => 'active',
		);
	}

	/**
	 * Get the file modified time as a cache buster if we're in dev mode.
	 *
	 * @param string $file Local path to the file.
	 * @return string The cache buster value to use for the given file.
	 */
	protected function get_file_version( $file ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return filemtime( $file );
		}
		return \WC_Subscriptions_Core_Plugin::instance()->get_plugin_version();
	}
}
