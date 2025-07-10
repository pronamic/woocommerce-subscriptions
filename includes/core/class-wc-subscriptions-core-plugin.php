<?php
/**
 * WooCommerce Subscriptions setup
 *
 * @package WooCommerce Subscriptions
 * @since   1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/class-wcs-core-autoloader.php';

class WC_Subscriptions_Core_Plugin {

	/**
	 * The version of subscriptions-core library.
	 * @var string
	 */
	protected $library_version = '8.3.0'; // WRCS: DEFINED_VERSION.

	/**
	 * The subscription scheduler instance.
	 *
	 * @var WCS_Action_Scheduler
	 */
	protected $scheduler = null;

	/**
	 * Notification scheduler instance.
	 *
	 * @var WCS_Action_Scheduler_Customer_Notifications
	 */
	public $notifications_scheduler = null;

	/**
	 * The plugin's autoloader instance.
	 *
	 * @var WCS_Autoloader
	 */
	protected $autoloader = null;

	/**
	 * The plugin's cache manager instance.
	 *
	 * @var WCS_Cache_Manager
	 */
	public $cache = null;

	/**
	 * The subscriptions instance.
	 *
	 * @var WC_Subscriptions_Core_Plugin
	 */
	protected static $instance = null;

	/**
	 * An array of cart handler objects.
	 *
	 * Use @see WC_Subscriptions_Core_Plugin::instance()->get_cart_handler( '{class}' ) to fetch a cart handler instance.
	 * eg WC_Subscriptions_Core_Plugin::instance()->get_cart_handler( 'WCS_Cart_Renewal' ).
	 *
	 * @var WCS_Cart_Renewal[]
	 */
	protected $cart_handlers = [];

	/**
	 * Initialise class and attach callbacks.
	 */
	public function __construct( $autoloader = null ) {
		if ( $autoloader ) {
			$this->autoloader = $autoloader;
		} else {
			$this->autoloader = new WCS_Core_Autoloader( $this->get_subscriptions_core_directory() );
			$this->autoloader->register();
		}

		// Load the Order Tables/Data Store Controller class early.
		new WCS_Orders_Table_Data_Store_Controller();

		$this->define_constants();
		$this->includes();
		$this->init();
		$this->init_hooks();

		// Store this instance so we can access it globally.
		self::$instance = $this;
	}

	/**
	 * Gets the Subscriptions Core instance.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @return WC_Subscriptions_Core_Plugin
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			// Doing it wrong. WC_Subscriptions_Core_Plugin::instance() should not be called before an instance has been created.
		}

		return self::$instance;
	}

	/**
	 * Defines WC Subscriptions constants.
	 */
	protected function define_constants() {
		define( 'WCS_INIT_TIMESTAMP', gmdate( 'U' ) );
	}

	/**
	 * Includes required files.
	 */
	protected function includes() {
		// Load function files.
		require_once $this->get_subscriptions_core_directory( 'wcs-functions.php' );
		require_once $this->get_subscriptions_core_directory( 'gateways/paypal/includes/wcs-paypal-functions.php' );
	}

	/**
	 * Initialise the plugin.
	 */
	public function init() {
		$payment_gateways_handler = $this->get_gateways_handler_class();

		WC_Subscriptions_Coupon::init();
		WC_Subscriptions_Product::init();
		WC_Subscriptions_Admin::init();
		WC_Subscriptions_Manager::init();
		WC_Subscriptions_Cart::init();
		WC_Subscriptions_Cart_Validator::init();
		WC_Subscriptions_Order::init();
		WC_Subscriptions_Renewal_Order::init();
		WC_Subscriptions_Checkout::init();
		WC_Subscriptions_Email::init();
		WC_Subscriptions_Email_Notifications::init();
		WC_Subscriptions_Addresses::init();
		WC_Subscriptions_Change_Payment_Gateway::init();
		$payment_gateways_handler::init();
		WCS_PayPal_Standard_Change_Payment_Method::init();
		WC_Subscriptions_Tracker::init();
		WCS_Upgrade_Logger::init();
		$this->add_cart_handler( new WCS_Cart_Renewal() );
		$this->add_cart_handler( new WCS_Cart_Resubscribe() );
		$this->add_cart_handler( new WCS_Cart_Initial_Payment() );
		WCS_Download_Handler::init();
		WCS_Limiter::init();
		WCS_Admin_System_Status::init();
		WCS_Staging::init();
		WCS_Permalink_Manager::init();
		WCS_Custom_Order_Item_Manager::init();
		WCS_Dependent_Hook_Manager::init();
		WCS_Admin_Product_Import_Export_Manager::init();
		WC_Subscriptions_Frontend_Scripts::init();
		WCS_Admin_Empty_List_Content_Manager::init();

		add_action( 'init', array( 'WC_Subscriptions_Synchroniser', 'init' ) );
		add_action( 'after_setup_theme', array( 'WC_Subscriptions_Upgrader', 'init' ), 11 );
		add_action( 'init', array( 'WC_PayPal_Standard_Subscriptions', 'init' ), 11 );

		// Attach the callback to load version dependant classes.
		add_action( 'plugins_loaded', array( $this, 'init_version_dependant_classes' ) );

		// Initialised the related order and customter data store instances.
		// @phpstan-ignore return.void
		add_action( 'plugins_loaded', 'WCS_Related_Order_Store::instance' );
		// @phpstan-ignore return.void
		add_action( 'plugins_loaded', 'WCS_Customer_Store::instance' );

		// Initialise the batch processing controller.
		// @phpstan-ignore return.void
		add_action( 'init', 'WCS_Batch_Processing_Controller::instance' );

		// Initialise the scheduler.
		$scheduler_class = apply_filters( 'woocommerce_subscriptions_scheduler', 'WCS_Action_Scheduler' );
		$this->scheduler = new $scheduler_class();

		// Customer notifications scheduler.
		$this->notifications_scheduler = new WCS_Action_Scheduler_Customer_Notifications();

		// Initialise the cache.
		$this->cache = WCS_Cache_Manager::get_instance();

		// When WooCommerceBlocks is loaded, set up the Integration class.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'setup_blocks_integration' ) );
		add_action( 'woocommerce_blocks_loaded', array( 'WC_Subscriptions_Extend_Store_Endpoint', 'init' ) );

		if ( ! $payment_gateways_handler::are_zero_total_subscriptions_allowed() ) {
			WC_Subscriptions_Gateway_Restrictions_Manager::init();
		}
	}

	/**
	 * Initialises classes which need to be loaded after other plugins have loaded.
	 *
	 * Hooked onto 'plugins_loaded' by @see WC_Subscriptions_Plugin::init()
	 */
	public function init_version_dependant_classes() {
		new WCS_Admin_Post_Types();
		new WCS_Admin_Meta_Boxes();
		WCS_Template_Loader::init();
		WCS_Remove_Item::init();
		WCS_User_Change_Status_Handler::init();
		WCS_My_Account_Payment_Methods::init();
		WCS_My_Account_Auto_Renew_Toggle::init();
		new WCS_Deprecated_Filter_Hooks();
		new WC_Subscriptions_Email_Preview();

		// On some loads the WC_Query doesn't exist. To avoid a fatal, only load the WCS_Query class when it exists.
		if ( class_exists( 'WC_Query' ) ) {
			new WCS_Query();
		}

		$failed_scheduled_action_manager = new WCS_Failed_Scheduled_Action_Manager( new WC_Logger() );
		$failed_scheduled_action_manager->init();

		/**
		 * Allow third-party code to enable running v2.0 hook deprecation handling for stores that might want to check for deprecated code.
		 *
		 * @param bool $value Whether the hook deprecation handlers should be loaded. False by default.
		 */
		if ( apply_filters( 'woocommerce_subscriptions_load_deprecation_handlers', false ) ) {
			new WCS_Action_Deprecator();
			new WCS_Filter_Deprecator();
			new WCS_Dynamic_Action_Deprecator();
			new WCS_Dynamic_Filter_Deprecator();
		}

		// Only load privacy handling on WC applicable versions.
		if ( class_exists( 'WC_Abstract_Privacy' ) ) {
			new WCS_Privacy();
		}

		// Loads Subscriptions support for the WooCommerce Navigation feature. This feature was removed in WC 9.3.
		if ( wcs_is_woocommerce_pre( '9.3' ) ) {
			add_action( 'init', array( 'WCS_WC_Admin_Manager', 'init' ), 11 );
		}
	}

	/**
	 * Attaches the hooks to init/setup the plugin.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function init_hooks() {
		register_deactivation_hook( $this->get_plugin_file(), array( $this, 'deactivate_plugin' ) );

		// Register our custom subscription order type after WC_Post_types::register_post_types()
		add_action( 'init', array( $this, 'register_order_types' ), 6 );

		add_filter( 'woocommerce_data_stores', array( $this, 'add_data_stores' ) );

		// Register our custom subscription order statuses before WC_Post_types::register_post_status()
		add_action( 'init', array( $this, 'register_post_statuses' ), 9 );

		// Load translation files
		add_action( 'init', array( $this, 'load_plugin_textdomain' ), 3 );

		// Add the "Settings | Documentation" links on the Plugins administration screen
		add_filter( 'plugin_action_links_' . plugin_basename( $this->get_plugin_file() ), array( $this, 'add_plugin_action_links' ) );
		add_action( 'in_plugin_update_message-' . plugin_basename( $this->get_plugin_file() ), array( $this, 'update_notice' ), 10, 2 );

		add_action( 'init', array( $this, 'activate_plugin' ) );

		add_filter( 'action_scheduler_queue_runner_batch_size', array( $this, 'reduce_multisite_action_scheduler_batch_size' ) );

		add_action( 'init', array( $this, 'init_notification_batch_processor' ) );
	}

	/**
	 * Gets the subscriptions core directory.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @param string $path Optional. The path to append.
	 * @return string
	 */
	public function get_subscriptions_core_directory( $path = '' ) {
		return $path ? trailingslashit( __DIR__ ) . $path : dirname( __DIR__ );
	}

	/**
	 * Gets the subscriptions core directory url.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @param string $path Optional. The path to append.
	 * @return string
	 */
	public function get_subscriptions_core_directory_url( $path = '' ) {
		return plugin_dir_url( WC_Subscriptions::$plugin_file ) . $path;
	}

	/**
	 * Gets the plugin's version
	 *
	 * @deprecated 5.0.0 This function is no longer recommended for version detection. Use get_library_version() instead.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function get_plugin_version() {
		return $this->library_version;
	}

	/**
	 * Gets the subscription-core library version.
	 *
	 * @since 5.0.0
	 */
	public function get_library_version() {
		return $this->library_version;
	}

	/**
	 * Gets the plugin file name
	 *
	 * @return string The plugin file
	 */
	public function get_plugin_file() {
		return __FILE__;
	}

	/**
	 * Gets the autoloader instance.
	 *
	 * @return WCS_Autoloader
	 */
	public function get_autoloader() {
		return $this->autoloader;
	}

	/**
	 * Gets the product type name.
	 *
	 * @return string The product type name.
	 */
	public function get_product_type_name() {
		return 'subscription';
	}

	/**
	 * Gets the activation transient name.
	 *
	 * @return string The transient name used to record when the plugin was activated.
	 */
	public function get_activation_transient() {
		return 'woocommerce_subscriptions_activated';
	}

	/**
	 * Gets the core Payment Gateways handler class
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @return string
	 */
	public function get_gateways_handler_class() {
		return 'WC_Subscriptions_Core_Payment_Gateways';
	}

	/**
	 * Gets the cart handler instance.
	 *
	 * @param string $class The class name of the cart handler. eg 'WCS_Cart_Renewal'.
	 * @return WCS_Cart_Renewal|null The cart handler instance or null if not found.
	 */
	public function get_cart_handler( $class ) {
		if ( ! isset( $this->cart_handlers[ $class ] ) ) {
			return null;
		}

		return $this->cart_handlers[ $class ];
	}

	/**
	 * Adds a cart handler instance.
	 *
	 * This is used to add cart handlers for different cart types. For example, renewal, resubscribe, initial, switch etc.
	 * To access a cart handler instance, use WC_Subscriptions_Core_Plugin::instance()->get_cart_handler( $class ).
	 *
	 * @param WCS_Cart_Renewal $cart_handler An instance of a cart handler.
	 */
	protected function add_cart_handler( $cart_handler ) {
		$this->cart_handlers[ get_class( $cart_handler ) ] = $cart_handler;
	}

	/**
	 * Registers Subscriptions order types.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function register_order_types() {

		wc_register_order_type(
			'shop_subscription',
			apply_filters(
				'woocommerce_register_post_type_subscription',
				array(
					// register_post_type() params
					'labels'                           => array(
						'name'               => __( 'Subscriptions', 'woocommerce-subscriptions' ),
						'singular_name'      => __( 'Subscription', 'woocommerce-subscriptions' ),
						'add_new'            => _x( 'Add Subscription', 'custom post type setting', 'woocommerce-subscriptions' ),
						'add_new_item'       => _x( 'Add New Subscription', 'custom post type setting', 'woocommerce-subscriptions' ),
						'edit'               => _x( 'Edit', 'custom post type setting', 'woocommerce-subscriptions' ),
						'edit_item'          => _x( 'Edit Subscription', 'custom post type setting', 'woocommerce-subscriptions' ),
						'new_item'           => _x( 'New Subscription', 'custom post type setting', 'woocommerce-subscriptions' ),
						'view'               => _x( 'View Subscription', 'custom post type setting', 'woocommerce-subscriptions' ),
						'view_item'          => _x( 'View Subscription', 'custom post type setting', 'woocommerce-subscriptions' ),
						'search_items'       => __( 'Search Subscriptions', 'woocommerce-subscriptions' ),
						'not_found'          => WCS_Admin_Empty_List_Content_Manager::get_content(),
						'not_found_in_trash' => _x( 'No Subscriptions found in trash', 'custom post type setting', 'woocommerce-subscriptions' ),
						'parent'             => _x( 'Parent Subscriptions', 'custom post type setting', 'woocommerce-subscriptions' ),
						'menu_name'          => __( 'Subscriptions', 'woocommerce-subscriptions' ),
					),
					'description'                      => __( 'This is where subscriptions are stored.', 'woocommerce-subscriptions' ),
					'public'                           => false,
					'show_ui'                          => true,
					'capability_type'                  => 'shop_order',
					'map_meta_cap'                     => true,
					'publicly_queryable'               => false,
					'exclude_from_search'              => true,
					'show_in_menu'                     => current_user_can( 'manage_woocommerce' ) ? 'woocommerce' : true,
					'hierarchical'                     => false,
					'show_in_nav_menus'                => false,
					'rewrite'                          => false,
					'query_var'                        => false,
					'supports'                         => array( 'title', 'comments', 'custom-fields' ),
					'has_archive'                      => false,

					// wc_register_order_type() params
					'exclude_from_orders_screen'       => true,
					'add_order_meta_boxes'             => true,
					'exclude_from_order_count'         => true,
					'exclude_from_order_views'         => true,
					'exclude_from_order_webhooks'      => true,
					'exclude_from_order_reports'       => true,
					'exclude_from_order_sales_reports' => true,
					'class_name'                       => 'WC_Subscription',
				)
			)
		);
	}

	/**
	 * Registers data stores.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @return string[]
	 */
	public function add_data_stores( $data_stores ) {
		// Our custom data stores.
		$data_stores['subscription']                  = 'WCS_Subscription_Data_Store_CPT';
		$data_stores['product-variable-subscription'] = 'WCS_Product_Variable_Data_Store_CPT';

		// Use WC core data stores for our products.
		$data_stores['product-subscription_variation']      = 'WC_Product_Variation_Data_Store_CPT';
		$data_stores['order-item-line_item_pending_switch'] = 'WC_Order_Item_Product_Data_Store';

		return $data_stores;
	}

	/**
	 * Registers our custom post statuses, used for subscription statuses.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function register_post_statuses() {
		$subscription_statuses = wcs_get_subscription_statuses();
		$registered_statuses   = apply_filters(
			'woocommerce_subscriptions_registered_statuses',
			array(
				// translators: placeholder is a post count.
				'wc-active'         => _nx_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'post status label including post count', 'woocommerce-subscriptions' ),
				// translators: placeholder is a post count.
				'wc-switched'       => _nx_noop( 'Switched <span class="count">(%s)</span>', 'Switched <span class="count">(%s)</span>', 'post status label including post count', 'woocommerce-subscriptions' ),
				// translators: placeholder is a post count.
				'wc-expired'        => _nx_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'post status label including post count', 'woocommerce-subscriptions' ),
				// translators: placeholder is a post count.
				'wc-pending-cancel' => _nx_noop( 'Pending Cancellation <span class="count">(%s)</span>', 'Pending Cancellation <span class="count">(%s)</span>', 'post status label including post count', 'woocommerce-subscriptions' ),
			)
		);

		if ( is_array( $subscription_statuses ) && is_array( $registered_statuses ) ) {
			foreach ( $registered_statuses as $status => $label_count ) {
				register_post_status(
					$status,
					array(
						'label'                     => $subscription_statuses[ $status ], // use same label/translations as wcs_get_subscription_statuses()
						'public'                    => false,
						'exclude_from_search'       => false,
						'show_in_admin_all_list'    => true,
						'show_in_admin_status_list' => true,
						'label_count'               => $label_count,
					)
				);
			}
		}
	}

	/**
	 * Runs the required processes when the plugin is deactivated.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function deactivate_plugin() {
		delete_option( WC_Subscriptions_Admin::$option_prefix . '_is_active' );
		flush_rewrite_rules();
		do_action( 'woocommerce_subscriptions_deactivated' );
	}

	/**
	 * Runs the required process on plugin activation.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function activate_plugin() {
		$is_active = get_option( WC_Subscriptions_Admin::$option_prefix . '_is_active', false );

		if ( false === $is_active ) {

			// Add the "Subscriptions" product type
			if ( ! get_term_by( 'slug', $this->get_product_type_name(), 'product_type' ) ) {
				wp_insert_term( $this->get_product_type_name(), 'product_type' );
			}

			// Maybe add the "Variable Subscriptions" product type
			if ( ! get_term_by( 'slug', 'variable-subscription', 'product_type' ) ) {
				wp_insert_term( __( 'Variable Subscription', 'woocommerce-subscriptions' ), 'product_type' );
			}

			// If no Subscription settings exist, its the first activation, so add defaults
			if ( ! WC_Subscriptions_Admin::has_settings() ) {
				WC_Subscriptions_Admin::add_default_settings();
			}

			// if this is the first time activating WooCommerce Subscription we want to enable PayPal debugging by default.
			if ( '0' == get_option( WC_Subscriptions_Admin::$option_prefix . '_previous_version', '0' ) && false == get_option( WC_Subscriptions_Admin::$option_prefix . '_paypal_debugging_default_set', false ) ) {
				$paypal_settings          = get_option( 'woocommerce_paypal_settings' );
				$paypal_settings['debug'] = 'yes';
				update_option( 'woocommerce_paypal_settings', $paypal_settings );
				update_option( WC_Subscriptions_Admin::$option_prefix . '_paypal_debugging_default_set', 'true' );
			}

			// Enable customer notifications by default for new stores.
			if ( '0' === get_option( WC_Subscriptions_Admin::$option_prefix . '_previous_version', '0' ) && 'no' === get_option( WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$switch_setting_string, 'no' ) ) {
				update_option( WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$switch_setting_string, 'yes' );
			}

			update_option( WC_Subscriptions_Admin::$option_prefix . '_is_active', true );

			set_transient( $this->get_activation_transient(), true, 60 * 60 );

			flush_rewrite_rules();

			do_action( 'woocommerce_subscriptions_activated' );
		}
	}

	/**
	 * Registers plugin translation files.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function load_plugin_textdomain() {
		$plugin_rel_path = apply_filters( 'woocommerce_subscriptions_translation_file_rel_path', $this->get_subscriptions_core_directory() . '/languages' );

		// Then check for a language file in /wp-content/plugins/woocommerce-subscriptions/languages/ (this will be overridden by any file already loaded)
		load_plugin_textdomain( 'woocommerce-subscriptions', false, $plugin_rel_path );
	}

	/**
	 * Adds the settings, docs and support links to the plugin screen.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param string[] $links The plugin's links displayed on the plugin screen.
	 * @return string[]
	 */
	public function add_plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . WC_Subscriptions_Admin::settings_tab_url() . '">' . __( 'Settings', 'woocommerce-subscriptions' ) . '</a>',
			'<a href="http://docs.woocommerce.com/document/subscriptions/">' . _x( 'Docs', 'short for documents', 'woocommerce-subscriptions' ) . '</a>',
			'<a href="https://woocommerce.com/my-account/marketplace-ticket-form/">' . __( 'Support', 'woocommerce-subscriptions' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Displays an upgrade notice for stores upgrading to 2.0.0.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param array $plugin_data Information about the plugin.
	 * @param array $r response from the server about the new version.
	 */
	public function update_notice( $plugin_data, $r ) {

		// Bail if the update notice is not relevant (new version is not yet 2.0 or we're already on 2.0)
		if ( version_compare( '2.0.0', $plugin_data['new_version'], '>' ) || version_compare( '2.0.0', $plugin_data['Version'], '<=' ) ) {
			return;
		}

		$update_notice = '<div class="wc_plugin_upgrade_notice">';
		// translators: placeholders are opening and closing tags. Leads to docs on upgrading WooCommerce Subscriptions
		$update_notice .= sprintf( __( 'Warning! Version 2.0 is a major update to the WooCommerce Subscriptions extension. Before updating, please create a backup, update all WooCommerce extensions and test all plugins, custom code and payment gateways with version 2.0 on a staging site. %1$sLearn more about updating older versions of WooCommerce Subscriptions &raquo;%2$s', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/document/upgrade-instructions/">', '</a>' );
		$update_notice .= '</div> ';

		echo wp_kses_post( $update_notice );
	}

	/**
	 * Sets up the Blocks integration class.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public function setup_blocks_integration() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Package' ) || ! version_compare( \Automattic\WooCommerce\Blocks\Package::get_version(), '4.4.0', '>' ) ) {
			return;
		}
		/**
		 * Filter the compatible blocks for WooCommerce Subscriptions.
		 */
		$compatible_blocks = apply_filters(
			'wcs_compatible_blocks',
			[ 'cart', 'checkout', 'mini-cart' ]
		);

		foreach ( $compatible_blocks as $block_name ) {
			add_action(
				"woocommerce_blocks_{$block_name}_block_registration",
				function( $integration_registry ) {
					$integration_registry->register( new WCS_Blocks_Integration() );
				}
			);
		}
	}

	/**
	 * Reduces the default Action Scheduler batch size on multi-sites.
	 *
	 * Renewals use a lot more memory on WordPress multisite (10-15mb instead of 0.1-1mb) so
	 * we need to reduce the number of renewals run in each request.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param int $batch_size The default Action Scheduler batch size.
	 * @return int
	 */
	public function reduce_multisite_action_scheduler_batch_size( $batch_size ) {
		if ( is_multisite() ) {
			$batch_size = 10;
		}

		return $batch_size;
	}

	/**
	 * Initialize batch processing for subscription notifications.
	 *
	 * @return void
	 */
	public function init_notification_batch_processor() {
		// Background processing for notifications
		$notifications_batch_processor      = new WCS_Notifications_Batch_Processor();
		$notifications_debug_tool_processor = new WCS_Notifications_Debug_Tool_Processor();
	}
}
