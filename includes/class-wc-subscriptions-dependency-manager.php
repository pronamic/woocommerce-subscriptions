<?php
/**
 * WC_Subscriptions_Dependency_Manager class
 *
 * @package WooCommerce Subscriptions
 * @since 5.0.0
 */

defined( 'ABSPATH' ) || exit;

class WC_Subscriptions_Dependency_Manager {

	/**
	 * The minimum supported WooCommerce version.
	 *
	 * @var string
	 */
	private $minimum_supported_wc_version;

	/**
	 * @var string|null The active WooCommerce version, or null if WooCommerce is not active.
	 */
	private $wc_active_version = null;

	/**
	 * @var bool Whether the active WooCommerce version has been cached.
	 */
	private $wc_version_cached = false;

	/**
	 * @var boolean Whether to skip the class_exists and WC_VERSION constant checks.
	 */
	private $skip_class_exists_and_wc_version_constant_checks = false;

	/**
	 * Constructor.
	 */
	public function __construct( $minimum_supported_wc_version ) {
		$this->minimum_supported_wc_version = $minimum_supported_wc_version;
		/**
		 * Filter allows to skip the class_exists and WC_VERSION constant checks.
		 *
		 * @since 7.8.0
		 *
		 * @param bool $use_class_exists Whether to use the class_exists and WC_VERSION constant checks.
		 *
		 * @return bool false to use the class_exists and WC_VERSION checks, true to skip them.
		 */
		if ( defined( 'WCS_ENVIRONMENT_TYPE' ) && WCS_ENVIRONMENT_TYPE === 'tests' && apply_filters( 'woocommerce_subscriptions_skip_class_exists_and_wc_version_constant_checks', false ) ) {
			$this->skip_class_exists_and_wc_version_constant_checks = true;
		}
	}

	/**
	 * Checks if the required dependencies are met.
	 *
	 * @since 5.0.0
	 * @return bool True if the required dependencies are met. Otherwise, false.
	 */
	public function has_valid_dependencies() {
		// We don't need to check is_woocommerce_active() here because is_woocommerce_version_supported() will return false if WooCommerce is not active.
		return $this->is_woocommerce_version_supported();
	}

	/**
	 * Determines if the WooCommerce plugin is active.
	 *
	 * @since 5.0.0
	 * @return bool True if the plugin is active, false otherwise.
	 */
	public function is_woocommerce_active() {
		if ( class_exists( 'WooCommerce' ) && ! $this->skip_class_exists_and_wc_version_constant_checks ) {
			return true;
		}

		return $this->get_woocommerce_active_version() !== null;
	}

	/**
	 * Determines if the WooCommerce version is supported by Subscriptions.
	 *
	 * The minimum supported WooCommerce version is defined in the WC_Subscriptions::$wc_minimum_supported_version property.
	 *
	 * @return bool true if the WooCommerce version is supported, false otherwise.
	 */
	public function is_woocommerce_version_supported() {
		return version_compare(
			// In php8.2+ version_compare requires a string so ensure we always pass a string.
			// version_compare treats an empty string as less than 0.
			$this->get_woocommerce_active_version() ?? '',
			$this->minimum_supported_wc_version,
			'>='
		);
	}

	/**
	 * This method detects the active version of WooCommerce.
	 *
	 * If the WC_VERSION constant is already defined, use that as a first preference.
	 * If it's not defined, fetch the version based on the WooCommerce plugin data.
	 *
	 * The WooCommerce plugin is determined by this logic:
	 * 1. Installed at 'woocommerce/woocommerce.php'
	 * 2. Installed at any '{x}/woocommerce.php' where the plugin name is 'WooCommerce'
	 *
	 * @return string|null The active WooCommerce version, or null if WooCommerce is not active.
	 */
	private function get_woocommerce_active_version() {
		if ( defined( 'WC_VERSION' ) && ! $this->skip_class_exists_and_wc_version_constant_checks ) {
			return WC_VERSION;
		}

		// Use a cached value to avoid calling get_plugins() and looping multiple times.
		if ( true === $this->wc_version_cached ) {
			return $this->wc_active_version;
		}

		$this->wc_version_cached = true;

		// Load plugin.php if it's not already loaded.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Loop through all active plugins and check if WooCommerce is active.
		foreach ( get_plugins() as $plugin_slug => $plugin_data ) {
			$is_woocommerce = false;

			/**
			 * The WooCommerce plugin can be installed in two supported ways:
			 *   1. Installed at 'woocommerce/woocommerce.php'
			 *   2. Installed at any '{x}/woocommerce.php' where the plugin name is 'WooCommerce'
			 */
			if ( 'woocommerce/woocommerce.php' === $plugin_slug ) {
				$is_woocommerce = true;
			} elseif ( 'woocommerce.php' === basename( $plugin_slug ) && 'WooCommerce' === $plugin_data['Name'] ) {
				$is_woocommerce = true;
			}

			if ( $is_woocommerce && is_plugin_active( $plugin_slug ) ) {
				$this->wc_active_version = $plugin_data['Version'];
			}
		}

		return $this->wc_active_version;
	}

	/**
	 * Displays an admin notice if the required dependencies are not met.
	 *
	 * @since 5.0.0
	 */
	public function display_dependency_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$admin_notice_content = '';

		if ( ! $this->is_woocommerce_active() ) {
			$install_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'install-plugin',
						'plugin' => 'woocommerce',
					),
					admin_url( 'update.php' )
				),
				'install-plugin_woocommerce'
			);

			// translators: 1$-2$: opening and closing <strong> tags, 3$-4$: link tags, takes to woocommerce plugin on wp.org, 5$-6$: opening and closing link tags, leads to plugins.php in admin
			$admin_notice_content = sprintf( esc_html__( '%1$sWooCommerce Subscriptions is inactive.%2$s The %3$sWooCommerce plugin%4$s must be active for WooCommerce Subscriptions to work. Please %5$sinstall & activate WooCommerce &raquo;%6$s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="' . esc_url( $install_url ) . '">', '</a>' );
		} elseif ( ! $this->is_woocommerce_version_supported() ) {
			// translators: 1$-2$: opening and closing <strong> tags, 3$: minimum supported WooCommerce version, 4$-5$: opening and closing link tags, leads to plugin admin
			$admin_notice_content = sprintf( esc_html__( '%1$sWooCommerce Subscriptions is inactive.%2$s This version of Subscriptions requires WooCommerce %3$s or newer. Please %4$supdate WooCommerce to version %3$s or newer &raquo;%5$s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', $this->minimum_supported_wc_version, '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' );
		}

		if ( $admin_notice_content ) {
			echo '<div class="error">';
			echo '<p>' . wp_kses_post( $admin_notice_content ) . '</p>';
			echo '</div>';
		}
	}
}
