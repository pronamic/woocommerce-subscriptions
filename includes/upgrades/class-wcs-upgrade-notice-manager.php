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
	protected static $version = '3.0.0';

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

		if ( isset( $_GET['_wcsnonce'] ) && wp_verify_nonce( $_GET['_wcsnonce'], 'dismiss_upgrade_notice' ) && ! empty( $_GET['dismiss_upgrade_notice'] ) && self::$version === $_GET['dismiss_upgrade_notice'] ) {
			delete_option( self::$option_name );
			return;
		}

		if ( ! self::display_notice() ) {
			return;
		}

		$version     = _x( '3.0', 'plugin version number used in admin notice', 'woocommerce-subscriptions' );
		$dismiss_url = wp_nonce_url( add_query_arg( 'dismiss_upgrade_notice', self::$version ), 'dismiss_upgrade_notice', '_wcsnonce' );
		$notice      = new WCS_Admin_Notice( 'notice notice-info', array(), $dismiss_url );
		$features    = array(
			array(
				'title'       => __( 'Improved scheduled action data storage', 'woocommerce-subscriptions' ),
				'description' => __( 'Scheduled action data, which was previously stored in the WordPress post tables, has been moved to a custom database table. Amongst other benefits, this will greatly improve the performance of processing scheduled actions such as subscription payments.', 'woocommerce-subscriptions' ),
			),
			array(
				'title'       => __( 'Increased processing rate for scheduled payments', 'woocommerce-subscriptions' ),
				'description' => sprintf(
					// translators: 1-2: opening/closing <a> tags - link to documentation.
					__( 'Previous versions of Subscriptions relied on %1$sWP Cron%2$s to process subscription payments and other scheduled events. In 3.0, these events will now run on admin request with loopback support. This will significantly increase the throughput of payment processing.', 'woocommerce-subscriptions' ),
					'<a href="https://docs.woocommerce.com/document/subscriptions/develop/complete-guide-to-scheduled-events-with-subscriptions/#section-2">', '</a>'
				),
			),
		);

		// translators: placeholder is Subscription version string ('2.3')
		$notice->set_heading( sprintf( __( 'Welcome to WooCommerce Subscriptions %s!', 'woocommerce-subscriptions' ), $version ) );
		$notice->set_content_template( 'update-welcome-notice.php', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'includes/upgrades/templates/', array(
			'version'  => $version,
			'features' => $features,
		) );
		$notice->set_actions( array(
			array(
				'name' => __( 'Learn more', 'woocommerce-subscriptions' ),
				'url'  => 'https://docs.woocommerce.com/document/subscriptions/whats-new-in-subscriptions-3-0/',
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
