<?php
/**
 * Blocks integration.
 *
 * @package WooCommerce Subscriptions Gifting
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class for blocks integration.
 */
class WCSG_Blocks_Integration implements IntegrationInterface {
	public function get_name() {
		return 'wcsg_subscriptions';
	}

	public function initialize() {
		$script_path = 'build/gifting-blocks-checkout.js';
		$style_path  = 'build/gifting-blocks-checkout.css';

		$script_url = \WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( $script_path );
		$style_url  = \WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( $style_path );

		$script_asset_path = \WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'build/gifting-blocks-checkout.asset.php' );
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_asset_path ),
			);

		wp_register_script(
			'wcsg-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_register_style(
			'wcsg-blocks-integration',
			$style_url,
			array(),
			$this->get_file_version( $style_path )
		);

		wp_enqueue_script( 'wcsg-blocks-integration' );
		wp_enqueue_style( 'wcsg-blocks-integration' );
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'wcsg-blocks-integration' );
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'wcsg-blocks-integration' );
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
		$gifting_checkbox_text = apply_filters(
			'wcsg_enable_gifting_checkbox_label',
			get_option(
				WCSG_Admin::$option_prefix . '_gifting_checkbox_text',
				__(
					'This is a gift',
					'woocommerce-subscriptions'
				)
			)
		);

		return array(
			'gifting_checkbox_text' => $gifting_checkbox_text,
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
		return \WC_Subscriptions_Core_Plugin::instance()->get_library_version();
	}
}
