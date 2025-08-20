<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Class for integrating with WooCommerce Blocks
 *
 * @package WooCommerce Subscriptions
 * @since   1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
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

		$script_asset_path = \WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'build/index.asset.php' );
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
			\WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'languages' )
		);
		wp_enqueue_style(
			'wc-blocks-integration',
			$style_url,
			'',
			$this->get_file_version( \WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'build/index.css' ) ),
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
			'place_order_override'             => $this->get_place_order_button_text_override(),
			'gifting_checkbox_text'            => apply_filters( 'wcsg_enable_gifting_checkbox_label', get_option( WCSG_Admin::$option_prefix . '_gifting_checkbox_text', __( 'This is a gift', 'woocommerce-subscriptions' ) ) ),
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

	/**
	 * Fetches the place order button text if it has been overridden by one of Woo Subscription's methods.
	 *
	 * @return string|null The overridden place order button text or null if it hasn't been overridden.
	 */
	protected function get_place_order_button_text_override() {
		$default           = null;
		$order_button_text = $default;

		// Check if any of our button text override functions (hooked onto 'woocommerce_order_button_text') change the default text.
		$callbacks = [
			[ 'WC_Subscriptions_Checkout', 'order_button_text' ],
			[ \WC_Subscriptions_Core_Plugin::instance()->get_cart_handler( 'WCS_Cart_Renewal' ), 'order_button_text' ],
			[ \WC_Subscriptions_Core_Plugin::instance()->get_cart_handler( 'WCS_Cart_Switch' ), 'order_button_text' ],
			[ \WC_Subscriptions_Core_Plugin::instance()->get_cart_handler( 'WCS_Cart_Resubscribe' ), 'order_button_text' ],
		];

		foreach ( $callbacks as $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}

			$order_button_text = call_user_func( $callback, $default );

			if ( $order_button_text !== $default ) {
				break;
			}
		}

		return $order_button_text;
	}
}
