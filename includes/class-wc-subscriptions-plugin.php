<?php
/**
 * WooCommerce Subscriptions setup
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WC_Subscriptions_Plugin extends WC_Subscriptions_Core_Plugin {

	/**
	 * Initialise the WC Subscriptions plugin.
	 *
	 * @since 4.0.0
	 */
	public function init() {
		parent::init();
		WC_Subscriptions_Switcher::init();
		new WCS_Cart_Switch();
		WCS_Manual_Renewal_Manager::init();
		WCS_Customer_Suspension_Manager::init();
		WCS_Drip_Downloads_Manager::init();
		WCS_Zero_Initial_Payment_Checkout_Manager::init();
		WCS_Retry_Manager::init();
		WCS_Early_Renewal_Modal_Handler::init();
		WCS_Limited_Recurring_Coupon_Manager::init();
		WCS_Call_To_Action_Button_Text_Manager::init();
		WCS_Subscriber_Role_Manager::init();
		WCS_Upgrade_Notice_Manager::init();

		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_show_welcome_message' ) );
	}

	/**
	 * Initialises classes which need to be loaded after other plugins have loaded.
	 *
	 * Hooked onto 'plugins_loaded' by @see WC_Subscriptions_Core_Plugin::init()
	 *
	 * @since 4.0.0
	 */
	public function init_version_dependant_classes() {
		parent::init_version_dependant_classes();
		WCS_API::init();
		new WCS_Auth();
		WCS_Webhooks::init();
		new WCS_Admin_Reports();
		new WCS_Report_Cache_Manager();

		if ( class_exists( 'WCS_Early_Renewal' ) ) {
			$notice = new WCS_Admin_Notice( 'error' );

			// translators: 1-2: opening/closing <b> tags, 3: Subscriptions version.
			$notice->set_simple_content( sprintf( __( '%1$sWarning!%2$s We can see the %1$sWooCommerce Subscriptions Early Renewal%2$s plugin is active. Version %3$s of %1$sWooCommerce Subscriptions%2$s comes with that plugin\'s functionality packaged into the core plugin. Please deactivate WooCommerce Subscriptions Early Renewal to avoid any conflicts.', 'woocommerce-subscriptions' ), '<b>', '</b>', $this->get_plugin_version() ) );
			$notice->set_actions(
				array(
					array(
						'name' => __( 'Installed Plugins', 'woocommerce-subscriptions' ),
						'url'  => admin_url( 'plugins.php' ),
					),
				)
			);

			$notice->display();
		} else {
			WCS_Early_Renewal_Manager::init();

			require_once $this->get_plugin_directory( 'includes/early-renewal/wcs-early-renewal-functions.php' );

			if ( WCS_Early_Renewal_Manager::is_early_renewal_enabled() ) {
				new WCS_Cart_Early_Renewal();
			}
		}
	}

	/**
	 * Gets the plugin's directory url.
	 *
	 * @since 4.0.0
	 * @param string $path Optional. The path to append.
	 * @return string
	 */
	public function get_plugin_directory_url( $path = '' ) {
		return plugin_dir_url( WC_Subscriptions::$plugin_file ) . $path;
	}

	/**
	 * Gets the plugin's directory.
	 *
	 * @since 4.0.0
	 * @param string $path Optional. The path to append.
	 * @return string
	 */
	public function get_plugin_directory( $path = '' ) {
		return plugin_dir_path( WC_Subscriptions::$plugin_file ) . $path;
	}

	/**
	 * Gets the activation transient name.
	 *
	 * @since 4.0.0
	 * @return string The transient name used to record when the plugin was activated.
	*/
	public function get_activation_transient() {
		return WC_Subscriptions::$activation_transient;
	}

	/**
	 * Gets the product type name.
	 *
	 * @since 4.0.0
	 * @return string The product type name.
	 */
	public function get_product_type_name() {
		return WC_Subscriptions::$name;
	}

	/**
	 * Gets the plugin's version
	 *
	 * @since 4.0.0
	 * @return string The plugin version.
	 */
	public function get_plugin_version() {
		return WC_Subscriptions::$version;
	}

	/**
	 * Gets the plugin file name
	 *
	 * @since 4.0.0
	 * @return string The plugin file
	 */
	public function get_plugin_file() {
		return WC_Subscriptions::$plugin_file;
	}

	/**
	 * Gets the Payment Gateways handler class
	 *
	 * @since 4.0.0
	 * @return string
	 */
	public function get_gateways_handler_class() {
		return 'WC_Subscriptions_Payment_Gateways';
	}


	/**
	 * Adds welcome message after activating the plugin
	 */
	public function maybe_show_welcome_message() {
		$plugin_has_just_been_activated = (bool) get_transient( WC_Subscriptions_Core_Plugin::instance()->get_activation_transient() );

		// Maybe add the admin notice.
		if ( $plugin_has_just_been_activated ) {

			$woocommerce_plugin_dir_file = WC_Subscriptions_Admin::get_woocommerce_plugin_dir_file();

			// check if subscription products exist in the store.
			$subscription_product = wc_get_products(
				array(
					'type'   => array( 'subscription', 'variable-subscription' ),
					'limit'  => 1,
					'return' => 'ids',
				)
			);

			if ( ! empty( $woocommerce_plugin_dir_file ) && 0 === count( $subscription_product ) ) {

				wp_enqueue_style( 'woocommerce-activation', plugins_url( '/assets/css/activation.css', $woocommerce_plugin_dir_file ), [], WC_Subscriptions_Core_Plugin::instance()->get_plugin_version() );

				if ( ! isset( $_GET['page'] ) || 'wcs-about' !== $_GET['page'] ) {
					add_action( 'admin_notices', array( $this, 'admin_installed_notice' ) );
				}
			}
			delete_transient( WC_Subscriptions_Core_Plugin::instance()->get_activation_transient() );
		}
	}

	/**
	 * Outputs a welcome message. Called when the Subscriptions extension is activated.
	 *
	 * @since 1.0
	 */
	public function admin_installed_notice() {
		?>
		<div id="message" class="updated woocommerce-message wc-connect woocommerce-subscriptions-activated">
			<div class="squeezer">
				<h4>
					<?php
					echo wp_kses(
						sprintf(
							// translators: $1-$2: opening and closing <strong> tags, $3-$4: opening and closing <em> tags.
							__(
								'%1$sWooCommerce Subscriptions Installed%2$s &#8211; %3$sYou\'re ready to start selling subscriptions!%4$s',
								'woocommerce-subscriptions'
							),
							'<strong>',
							'</strong>',
							'<em>',
							'</em>'
						),
						[
							'strong' => true,
							'em'     => true,
						]
					);
					?>
				</h4>

				<p class="submit">
					<a href="<?php echo esc_url( WC_Subscriptions_Admin::add_subscription_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Add a Subscription Product', 'woocommerce-subscriptions' ); ?></a>
					<a href="<?php echo esc_url( WC_Subscriptions_Admin::settings_tab_url() ); ?>" class="docs button button-primary"><?php esc_html_e( 'Settings', 'woocommerce-subscriptions' ); ?></a>
					<a href="https://twitter.com/share" class="twitter-share-button" data-url="http://www.woocommerce.com/products/woocommerce-subscriptions/" data-text="Woot! I can sell subscriptions with #WooCommerce" data-via="WooCommerce" data-size="large">Tweet</a>
					<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src="//platform.twitter.com/widgets.js";fjs.parentNode.insertBefore(js,fjs);}}(document,"script","twitter-wjs");</script>
				</p>
			</div>
		</div>
		<?php
	}
}
