<?php
/**
 * Class for managing and displaying an admin notice displayed after upgrading Subscriptions.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Upgrades
 * @version  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_Notice_Manager {

	/**
	 * The version this notice relates to.
	 *
	 * @var string
	 */
	protected static $version = '3.1.0';

	/**
	 * The number of times the notice will be displayed before being dismissed automatically.
	 *
	 * @var int
	 */
	protected static $display_count = 2;

	/**
	 * The option name which stores information about the admin notice.
	 *
	 * @var string
	 */
	protected static $option_name = 'wcs_display_upgrade_notice';

	/**
	 * Attach callbacks.
	 *
	 * @since 2.3.0
	 */
	public static function init() {
		add_action( 'woocommerce_subscriptions_upgraded', array( __CLASS__, 'maybe_record_upgrade' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_admin_notice' ) );
	}

	/**
	 * Store an option to display an upgrade notice when the store is upgraded.
	 *
	 * @param  string $current_version           The new version the site has been updated to.
	 * @param  string $previously_active_version The version of Subscriptions the store was running prior to upgrading.
	 * @since 2.3.0
	 */
	public static function maybe_record_upgrade( $current_version, $previously_active_version ) {
		if ( '0' !== $previously_active_version && version_compare( $previously_active_version, self::$version, '<' ) && version_compare( $current_version, self::$version, '>=' ) ) {
			update_option( self::$option_name, array(
				'version'       => self::$version,
				'display_count' => 0,
			) );
		}
	}

	/**
	 * Display the upgrade notice including details about the update if it hasn't been dismissed.
	 *
	 * @since 2.3.0
	 */
	public static function maybe_show_admin_notice() {

		if ( isset( $_GET['_wcsnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wcsnonce'] ) ) , 'dismiss_upgrade_notice' ) && ! empty( $_GET['dismiss_upgrade_notice'] ) && self::$version === $_GET['dismiss_upgrade_notice'] ) {
			delete_option( self::$option_name );
			return;
		}

		if ( ! self::display_notice() ) {
			return;
		}

		$version     = _x( '3.1', 'plugin version number used in admin notice', 'woocommerce-subscriptions' );
		$dismiss_url = wp_nonce_url( add_query_arg( 'dismiss_upgrade_notice', self::$version ), 'dismiss_upgrade_notice', '_wcsnonce' );
		$notice      = new WCS_Admin_Notice( 'notice notice-info', array(), $dismiss_url );
		$features    = array(
			array(
				'title'       => __( 'v3 REST API endpoint support', 'woocommerce-subscriptions' ),
				'description' => sprintf(
					// translators: 1-3: opening/closing <a> tags - link to documentation.
					__( 'Webhook and REST API users can now use v3 subscription endpoints. Click here to %1$slearn more%2$s about the REST API and check out the technical API docs %3$shere%2$s.', 'woocommerce-subscriptions' ),
					'<a href="https://woocommerce.com/document/woocommerce-rest-api/">',
					'</a>',
					'<a href="https://woocommerce.github.io/subscriptions-rest-api-docs/">'
				),
			),
			array(
				'title'       => __( 'WooCommerce checkout and cart blocks integration', 'woocommerce-subscriptions' ),
				'description' => sprintf(
					// translators: 1-2: opening/closing <a> tags - link to documentation.
					__( 'Subscriptions is now compatible with the WooCommerce cart and checkout blocks. You can learn more about the compatibility status of the cart & checkout blocks %1$shere%2$s.', 'woocommerce-subscriptions' ),
					'<a href="https://woocommerce.com/document/woocommerce-store-editing/customizing-cart-and-checkout/#compatible-extensions">', '</a>'
				),
			),
		);

		// translators: placeholder is Subscription version string ('3.1')
		$notice->set_heading( sprintf( __( 'Welcome to WooCommerce Subscriptions %s!', 'woocommerce-subscriptions' ), $version ) );
		$notice->set_content_template( 'update-welcome-notice.php', WC_Subscriptions_Plugin::instance()->get_plugin_directory() . '/includes/upgrades/templates/', array(
			'version'  => $version,
			'features' => $features,
		) );
		$notice->set_actions( array(
			array(
				'name' => __( 'Learn more', 'woocommerce-subscriptions' ),
				'url'  => 'https://docs.woocommerce.com/document/subscriptions/whats-new-in-subscriptions-3-1/',
			),
		) );

		$notice->display();
		self::increment_display_count();
	}

	/**
	 * Determine if this admin notice should be displayed.
	 *
	 * @return bool Whether this admin notice should be displayed.
	 * @since 2.3.0
	 */
	protected static function display_notice() {
		$option         = get_option( self::$option_name );
		$display_notice = false;

		if ( isset( $option['version'] ) ) {
			$display_notice = $option['version'] === self::$version;
		}

		return $display_notice;
	}

	/**
	 * Increment the notice display counter signalling the notice has been displayed.
	 *
	 * The option triggering this notice will be deleted if the display count has been reached.
	 *
	 * @since 2.3.0
	 */
	protected static function increment_display_count() {
		$option = get_option( self::$option_name );
		$count  = isset( $option['display_count'] ) ? (int) $option['display_count'] : 0;
		$count++;

		// If we've reached the display count, delete the option so the notice isn't displayed again.
		if ( $count >= self::$display_count ) {
			delete_option( self::$option_name );
		} else {
			$option['display_count'] = $count;
			update_option( self::$option_name, $option );
		}
	}
}
