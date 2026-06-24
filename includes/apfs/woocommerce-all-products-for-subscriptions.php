<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * @class    WCS_ATT
 * @version  9.0.0
 */
class WCS_ATT extends WCS_ATT_Abstract_Module {

	/* Plugin version. */
	const VERSION = '6.0.7';

	/* Required WC version. */
	const REQ_WC_VERSION = '8.2.0';

	/* Required WC version. */
	const REQ_WCS_VERSION = '6.1.0';

	/* Required WC Payments version. */
	const REQ_WCPAY_VERSION = '3.2.0';

	/**
	 * @var WCS_ATT - the single instance of the class.
	 *
	 * @since APFS 1.0.0
	 */
	protected static $_instance = null;

	/**
	 * Product data object.
	 *
	 * @var WCS_ATT_Product_Data
	 *
	 * @since APFS 5.0.0
	 */
	public $product_data;

	/**
	 * Main WCS_ATT Instance.
	 *
	 * Ensures only one instance of WCS_ATT is loaded or can be loaded.
	 *
	 * @static
	 * @see WCS_ATT()
	 * @return WCS_ATT - Main instance
	 * @since APFS 1.0.0
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since APFS 1.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Foul!', 'woocommerce-subscriptions' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since APFS 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Foul!', 'woocommerce-subscriptions' ), '1.0.0' );
	}

	/**
	 * Do some work.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 15 );
	}

	/**
	 * The plugin URL.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', WC_Subscriptions::$plugin_file ) );
	}

	/**
	 * The plugin path.
	 *
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Plugin version getter.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  boolean $base
	 * @param  string  $version
	 * @return string
	 */
	public function plugin_version( $base = false, $version = '' ) {

		$version = $version ? $version : self::VERSION;

		if ( $base ) {
			$version_parts = explode( '-', $version );
			$version       = count( $version_parts ) > 1 ? $version_parts[0] : $version;
		}

		return $version;
	}

	/**
	 * Define constants if not present.
	 *
	 * @since  APFS 3.1.7
	 *
	 * @return boolean
	 */
	protected function maybe_define_constant( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Plugin base path name getter.
	 *
	 * @return string
	 */
	public function plugin_basename() {
		return plugin_basename( __FILE__ );
	}

	/**
	 * Bootstrap.
	 */
	public function plugins_loaded() {

		$this->define_constants();

		$notice = '';

		// Subs version check.
		if ( class_exists( 'WC_Subscriptions' ) ) {

			// Notice about plugin version when WooCommerce Subscriptions is active.
			if ( ! defined( 'WCS_INIT_TIMESTAMP' ) || version_compare( WC_Subscriptions::$version, self::REQ_WCS_VERSION ) < 0 ) {
				$notice = sprintf( __( 'All Products for WooCommerce Subscriptions requires at least <a href="%1$s" target="_blank">WooCommerce Subscriptions</a> version <strong>%2$s</strong>.', 'woocommerce-subscriptions' ), self::get_resource_url( 'wcs' ), self::REQ_WCS_VERSION );
			}
		} elseif ( class_exists( 'WC_Payments' ) && class_exists( 'WC_Payments_Features' ) ) {

			if ( ! defined( 'WCS_INIT_TIMESTAMP' ) || ! WC_Payments_Features::is_wcpay_subscriptions_enabled() ) {
				if ( version_compare( WCPAY_VERSION_NUMBER, self::REQ_WCPAY_VERSION ) < 0 ) {
					// Notice about plugin version when WooPayments is active.
					$notice = sprintf( __( 'All Products for WooCommerce Subscriptions requires at least <a href="%1$s" target="_blank">WooPayments</a> version <strong>%2$s</strong>.', 'woocommerce-subscriptions' ), self::get_resource_url( 'wcpay' ), self::REQ_WCPAY_VERSION );
				} else {
					// Notice about disabled Subscription features in WooPayments.
					$notice = sprintf( __( 'All Products for WooCommerce Subscriptions requires Subscriptions to be enabled in the <strong>WooPayments</strong> <a href="%1$s" target="_blank">settings</a>.', 'woocommerce-subscriptions' ), self::get_resource_url( 'wcpay-settings' ) );
				}
			}
		} else {
			// Notice about disabled Subscriptions core not being loaded.
			$notice = sprintf( __( 'All Products for WooCommerce Subscriptions requires at least <a href="%1$s" target="_blank">WooPayments</a> version <strong>%2$s</strong> or <a href="%3$s" target="_blank">WooCommerce Subscriptions</a> version <strong>%4$s</strong>.', 'woocommerce-subscriptions' ), self::get_resource_url( 'wcpay' ), self::REQ_WCPAY_VERSION, self::get_resource_url( 'wcs' ), self::REQ_WCS_VERSION );
		}

		if ( ! empty( $notice ) ) {
			WCS_ATT_Admin_Notices::init();
			WCS_ATT_Admin_Notices::add_notice( $notice, 'error' );
			return false;
		}

		// PHP version check.
		if ( ! function_exists( 'phpversion' ) || version_compare( phpversion(), '7.4.0', '<' ) ) {
			$notice = sprintf(
				__(
					'All Products for WooCommerce Subscriptions requires at least PHP <strong>%1$s</strong>. Learn <a href="%2$s">how to update PHP</a>.',
					'woocommerce-subscriptions'
				),
				'7.4.0',
				'https://woocommerce.com/document/how-to-update-your-php-version/'
			);
			WCS_ATT_Admin_Notices::init();
			WCS_ATT_Admin_Notices::add_notice( $notice, 'error' );
		}

		// WC check.
		if ( ! function_exists( 'WC' ) || version_compare( WC()->version, self::REQ_WC_VERSION ) < 0 ) {
			$notice = __( 'All Products for WooCommerce Subscriptions requires at least WooCommerce version <strong>%1$s</strong>. %2$s', 'woocommerce-subscriptions' );
			if ( ! function_exists( 'WC' ) ) {
				$notice = sprintf( $notice, self::REQ_WC_VERSION, __( 'Please install and activate WooCommerce.', 'woocommerce-subscriptions' ) );
			} else {
				$notice = sprintf( $notice, self::REQ_WC_VERSION, __( 'Please update WooCommerce.', 'woocommerce-subscriptions' ) );
			}
			WCS_ATT_Admin_Notices::init();
			WCS_ATT_Admin_Notices::add_notice( $notice, 'error' );

			return false;
		}

		// Add init hooks.
		add_action( 'init', array( $this, 'init_plugin' ) );
		$this->includes();
	}

	/**
	 * Define constants.
	 *
	 * @return void
	 */
	protected function define_constants() {
		$this->maybe_define_constant( 'WCS_ATT_VERSION', self::VERSION );
		$this->maybe_define_constant( 'WCS_ATT_ABSPATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
	}

	/**
	 * Include plugin files.
	 *
	 * @return void
	 */
	public function includes() {

		// Data modules
		$this->product_data = WCS_ATT_Product_Data::instance();

		// Classes.
		add_action( 'plugins_loaded', array( 'WCS_ATT_Integrations', 'init' ), 20 );
		WCS_ATT_Product::init();
		WCS_ATT_Cart::init();
		WCS_ATT_Order::init();
		WCS_ATT_Sync::init();

		// Modules.
		$this->register_modules();
		$this->initialize_modules();

		// Load display components.
		WCS_ATT_Display::init();
		$this->register_component_hooks( 'display' );

		// Load form handling components.
		$this->register_component_hooks( 'form' );

		// Admin includes.
		if ( is_admin() ) {
			$this->admin_includes();
		}
	}

	/**
	 * Include submodules.
	 *
	 * @since  APFS 2.1.0
	 *
	 * @return void
	 */
	public function register_modules() {
		$modules = array();

		/*
			* Important: Switching Subscriptions and adding products/carts to existing Subscriptions
			* is only available with WooCommerce Subscriptions. These features are disabled when
			* Subscriptions core is loaded via WooPayments.
			*
			* See: https://woocommerce.com/document/payments/subscriptions/comparison/#feature-matrix
			*/
		if ( class_exists( 'WC_Subscriptions_Switcher' ) && class_exists( 'WC_Subscriptions' ) ) {
			$modules = array( 'manage' => 'WCS_ATT_Management' );
		}
		$this->modules = apply_filters( 'wcsatt_modules', $modules );
	}

	/**
	 * Register all module hooks associated with a named SATT component.
	 *
	 * @since  APFS 2.1.0
	 *
	 * @param  string $component
	 */
	protected function register_component_hooks( $component ) {

		foreach ( $this->modules as $module ) {
			$module->register_hooks( $component );
		}
	}

	/**
	 * Loads the Admin & AJAX filters / hooks.
	 *
	 * @return void
	 */
	public function admin_includes() {
		// Admin notices handling.
		WCS_ATT_Admin_Notices::init();
		// Addmin settings/metaboxes.
		WCS_ATT_Admin::init();

		// Subscription Plans welcome announcement. Skip initialization when the current
		// user has already dismissed it so no hooks or footer markup are emitted.
		if ( ! WCS_ATT_Admin_Welcome_Announcement::is_welcome_announcement_dismissed() ) {
			WCS_ATT_Admin_Welcome_Announcement::init();
		}
	}

	/**
	 * Initialize plugin.
	 *
	 * @since APFS 3.4.0
	 *
	 * @return void
	 */
	public function init_plugin() {
		$this->activate();
	}

	/**
	 * Store plugin version.
	 *
	 * @return void
	 */
	public function activate() {

		$version = get_option( 'apfs_version', false );

		if ( ! $version ) {

			add_option( 'apfs_version', self::VERSION );

		} elseif ( version_compare( $version, self::VERSION, '<' ) ) {

			// If adding carts to subscriptions is allowed and cart plans do not exist when updating to version 3.4.0, turn off the feature for backwards compatibility.
			if ( version_compare( $version, '3.4.0', '<' ) ) {
				if ( 'off' !== get_option( 'wcsatt_add_cart_to_subscription', 'off' ) && empty( get_option( 'wcsatt_subscribe_to_cart_schemes', false ) ) ) {
					update_option( 'wcsatt_add_cart_to_subscription', 'off' );
				}
			}

			// Clean up orphaned maintenance notices option removed in 9.0.0.
			delete_option( 'wcsatt_maintenance_notices' );

			update_option( 'apfs_version', self::VERSION );
		}
	}

	/**
	 * Clean-up on de-activation.
	 *
	 * @since APFS 3.1.5
	 *
	 * @return void
	 */
	/**
	 * Product types supported by the plugin.
	 *
	 * @return array
	 */
	public function get_supported_product_types() {
		return apply_filters( 'wcsatt_supported_product_types', array( 'simple', 'variable', 'variation', 'mix-and-match', 'bundle', 'composite' ) );
	}

	/**
	 * Log important stuff.
	 *
	 * @param  string $message
	 * @param  string $level
	 * @return void
	 */
	public function log( $message, $level ) {
		$logger = wc_get_logger();
		$logger->log( $level, $message, array( 'source' => 'wcs_att' ) );
	}

	/**
	 * Returns URL to a doc or support resource.
	 *
	 * @since  APFS 4.0.0
	 *
	 * @param  string $handle
	 * @return string
	 */
	public function get_resource_url( $handle ) {

		$resource = false;

		if ( 'update-php' === $handle ) {
			$resource = 'https://woocommerce.com/document/how-to-update-your-php-version/';
		} elseif ( 'docs-contents' === $handle ) {
			$resource = 'https://woocommerce.com/document/all-products-for-woocommerce-subscriptions/';
		} elseif ( 'docs-configuration' === $handle ) {
			$resource = 'https://woocommerce.com/document/all-products-for-woocommerce-subscriptions/store-owners-guide/#configuration';
		} elseif ( 'max-input-vars' === $handle ) {
			$resource = 'https://woocommerce.com/document/bundles/bundles-faq/#faq_bundled_items_dont_save';
		} elseif ( 'updating' === $handle ) {
			$resource = 'https://woocommerce.com/document/how-to-update-woocommerce/';
		} elseif ( 'global-plan-settings' === $handle ) {
			// The `wcsatt_subscribe_to_cart_options_pre` settings section
			// (and its `-description` anchor) was removed when the storewide
			// plans surface moved to the 2-col DataView layout (WOOSUBS-1673).
			// Link to the Subscriptions tab without the stale fragment.
			$resource = admin_url( 'admin.php?page=wc-settings&tab=subscriptions' );
		} elseif ( 'wcpay-settings' === $handle ) {
			$resource = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments' );
		} elseif ( 'wcs' === $handle ) {
			$resource = 'https://woocommerce.com/products/woocommerce-subscriptions/';
		} elseif ( 'wcpay' === $handle ) {
			$resource = 'https://woocommerce.com/products/woocommerce-payments/';
		} elseif ( 'ticket-form' === $handle ) {
			$resource = 'https://woocommerce.com/my-account/marketplace-ticket-form/';
		}

		return $resource;
	}
}
