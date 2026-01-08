<?php
/**
 * WooCommerce Subscriptions Autoloader.
 *
 * @package WC_Subscriptions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WCS_Autoloader extends WCS_Core_Autoloader {

	/**
	 * Whether to use the legacy API classes.
	 *
	 * @var bool
	 */
	protected $legacy_api = false;

	/**
	 * The classes the Subscriptions plugin has ownership of.
	 *
	 * Note: needs to be lowercase.
	 *
	 * @var array
	 */
	private $classes = array(
		'wc_subscriptions_plugin'                   => true,
		'wc_subscriptions_switcher'                 => true,
		'wcs_cart_switch'                           => true,
		'wcs_switch_totals_calculator'              => true,
		'wcs_switch_cart_item'                      => true,
		'wcs_add_cart_item'                         => true,
		'wc_order_item_pending_switch'              => true,
		'wcs_manual_renewal_manager'                => true,
		'wcs_customer_suspension_manager'           => true,
		'wcs_drip_downloads_manager'                => true,
		'wcs_zero_initial_payment_checkout_manager' => true,
		'wcs_meta_box_payment_retries'              => true,
		'wcs_limited_recurring_coupon_manager'      => true,
		'wcs_call_to_action_button_text_manager'    => true,
		'wcs_subscriber_role_manager'               => true,
		'wc_subscriptions_payment_gateways'         => true,
		'wcs_api'                                   => true,
		'wcs_webhooks'                              => true,
		'wcs_auth'                                  => true,
		'wcs_upgrade_notice_manager'                => true,
		'wcs_admin_assets'                          => true,
		'wc_subscriptions_cli'                      => true,
	);

	/**
	 * The substrings of the classes that the Subscriptions plugin has ownership of.
	 *
	 * @var array
	 */
	private $class_substrings = array(
		'wc_reports',
		'report',
		'retry',
		'early_renewal',
		'rest_subscription',
		'wc_api_subscriptions',
	);

	/**
	 * Gets the class's base path.
	 *
	 * If the a class is one the plugin is responsible for, we return the plugin's path. Otherwise we let the library handle it.
	 *
	 * @since 4.0.0
	 * @return string
	 */
	public function get_class_base_path( $class ) {
		if ( $this->is_plugin_class( $class ) ) {
			return dirname( WC_Subscriptions::$plugin_file );
		}

		return parent::get_class_base_path( $class );
	}

	/**
	 * Get the relative path for the class location.
	 *
	 * @param string $class The class name.
	 * @return string The relative path (from the plugin root) to the class file.
	 */
	protected function get_relative_class_path( $class ) {
		if ( ! $this->is_plugin_class( $class ) ) {
			return parent::get_relative_class_path( $class );
		}

		$path = '/includes';

		if ( stripos( $class, 'switch' ) !== false || 'wcs_add_cart_item' === $class ) {
			$path .= '/switching';
		} elseif ( false !== strpos( $class, 'wcs_report' ) ) {
			$path .= '/admin/reports';
		} elseif ( false !== strpos( $class, 'retry' ) || false !== strpos( $class, 'retries' ) ) {
			$path .= $this->get_payment_retry_class_relative_path( $class );
		} elseif ( false !== strpos( $class, 'admin' ) ) {
			$path .= '/admin';
		} elseif ( false !== strpos( $class, 'early' ) ) {
			$path .= '/early-renewal';
		} elseif ( false !== strpos( $class, 'gateways' ) ) {
			$path .= '/gateways';
		} elseif ( false !== strpos( $class, 'rest' ) ) {
			$path .= $this->legacy_api ? '/api/legacy' : $this->get_rest_api_directory( $class );
		} elseif ( false !== strpos( $class, 'api' ) && 'wcs_api' !== $class ) {
			$path .= '/api/legacy';
		}

		return trailingslashit( $path );
	}

	/**
	 * Determine whether we should autoload a given class.
	 *
	 * @param string $class The class name.
	 * @return bool
	 */
	protected function should_autoload( $class ) {
		static $legacy = array(
			'wc_order_item_pending_switch' => 1,
		);

		return isset( $legacy[ $class ] ) ? true : parent::should_autoload( $class );
	}

	/**
	 * Is the given class found in the Subscriptions plugin
	 *
	 * @since 4.0.0
	 * @param string $class
	 * @return bool
	 */
	private function is_plugin_class( $class ) {
		if ( isset( $this->classes[ $class ] ) ) {
			return true;
		}

		foreach ( $this->class_substrings as $substring ) {
			if ( false !== stripos( $class, $substring ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets a retry class's relative path.
	 *
	 * @param string $class The retry class being loaded.
	 * @return string The relative path to the retry class.
	 */
	private function get_payment_retry_class_relative_path( $class ) {
		$relative_path = '/payment-retry';

		if ( false !== strpos( $class, 'admin' ) || false !== strpos( $class, 'meta_box' ) ) {
			$relative_path .= '/admin';
		} elseif ( false !== strpos( $class, 'email' ) ) {
			$relative_path .= '/emails';
		} elseif ( false !== strpos( $class, 'store' ) ) {
			$relative_path .= '/data-stores';
		}

		return $relative_path;
	}

	/**
	 * Determine if the class is one of our abstract classes.
	 *
	 * @param string $class The class name.
	 * @return bool
	 */
	protected function is_class_abstract( $class ) {
		static $abstracts = array(
			'wcs_retry_store' => true,
		);

		return isset( $abstracts[ $class ] ) || parent::is_class_abstract( $class );
	}

	/**
	 * Set whether the legacy API should be used.
	 *
	 * @author Jeremy Pry
	 *
	 * @param bool $use_legacy_api Whether to use the legacy API classes.
	 *
	 * @return $this
	 */
	public function use_legacy_api( $use_legacy_api ) {
		$this->legacy_api = (bool) $use_legacy_api;

		return $this;
	}

	/**
	 * Gets the correct subdirectory for a version of the a REST API class.
	 *
	 * @param string $class The rest API class name.
	 * @return string The subdirectory for a rest API class.
	 */
	protected function get_rest_api_directory( $class ) {
		$directory = '/api';

		// Check for an API version in the class name.
		preg_match( '/v\d/', $class, $matches );

		if ( ! empty( $matches ) ) {
			$directory .= "/{$matches[0]}";
		}

		return $directory;
	}
}
