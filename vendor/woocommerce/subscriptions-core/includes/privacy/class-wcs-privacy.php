<?php
/**
 * Privacy/GDPR related functionality which ties into WordPress functionality.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions\Privacy
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Privacy extends WC_Abstract_Privacy {

	/**
	 * Background updater to process personal data removal from subscriptions and related orders.
	 *
	 * @var WCS_Privacy_Background_Updater
	 */
	protected static $background_process;

	/**
	 * A flag which is set when WC is doing a user inactivity cleanup.
	 * Used to exclude subscription customers from the inactive user query.
	 *
	 * @var bool
	 */
	protected static $doing_user_inactivity_query = false;

	/**
	 * WCS_Privacy constructor.
	 */
	public function __construct() {
		if ( ! self::$background_process ) {
			self::$background_process = new WCS_Privacy_Background_Updater();
		}

		parent::__construct();

		add_action( 'init', array( $this, 'register_erasers_exporters' ) );
	}

	/**
	 * Register erasers and exporters.
	 */
	public function register_erasers_exporters() {
		$this->name = __( 'WooCommerce Subscriptions', 'woocommerce-subscriptions' );

		// Add our exporters and erasers.
		$this->add_exporter( 'woocommerce-subscriptions-data', __( 'Subscriptions Data', 'woocommerce-subscriptions' ), array( 'WCS_Privacy_Exporters', 'subscription_data_exporter' ) );
		$this->add_eraser( 'woocommerce-subscriptions-data', __( 'Subscriptions Data', 'woocommerce-subscriptions' ), array( 'WCS_Privacy_Erasers', 'subscription_data_eraser' ) );
	}

	/**
	 * Attach callbacks.
	 */
	public function init() {
		parent::init();
		self::$background_process->init();

		add_filter( 'woocommerce_subscription_bulk_actions', array( __CLASS__, 'add_privacy_bulk_action' ) );
		add_action( 'load-edit.php', array( __CLASS__, 'process_bulk_action' ) );
		add_action( 'woocommerce_remove_subscription_personal_data', array( 'WCS_Privacy_Erasers', 'remove_subscription_personal_data' ) );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_admin_notices' ) );

		add_filter( 'woocommerce_account_settings', array( __CLASS__, 'add_caveat_to_order_data_retention_settings' ) );
		add_filter( 'woocommerce_account_settings', array( __CLASS__, 'add_subscription_data_retention_settings' ) );

		// Attach callbacks to prevent subscription related orders being trashed or anonymized
		add_filter( 'woocommerce_trash_pending_orders_query_args', array( __CLASS__, 'remove_subscription_orders_from_anonymization_query' ), 10, 2 );
		add_filter( 'woocommerce_trash_failed_orders_query_args', array( __CLASS__, 'remove_subscription_orders_from_anonymization_query' ), 10, 2 );
		add_filter( 'woocommerce_trash_cancelled_orders_query_args', array( __CLASS__, 'remove_subscription_orders_from_anonymization_query' ), 10, 2 );
		add_filter( 'woocommerce_anonymize_completed_orders_query_args', array( __CLASS__, 'remove_subscription_orders_from_anonymization_query' ), 10, 2 );

		add_action( 'woocommerce_cleanup_personal_data', array( $this, 'queue_cleanup_personal_data' ) );

		// Hook in late so there is less opportunity for our flag to affect other user queries called on this hook.
		add_filter( 'woocommerce_delete_inactive_account_roles', array( __CLASS__, 'flag_subscription_user_exclusion_from_query' ), 1000 );
		add_action( 'pre_get_users', array( __CLASS__, 'maybe_exclude_subscription_customers' ) );
		add_filter( 'woocommerce_account_settings', array( __CLASS__, 'add_inactive_user_retention_note' ) );

		add_action( 'handle_bulk_actions-woocommerce_page_wc-orders--shop_subscription', [ __CLASS__, 'handle_privacy_bulk_actions' ], 10, 3 );
	}

	/**
	 * Spawn events for subscription cleanup.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public function queue_cleanup_personal_data() {
		self::$background_process->schedule_ended_subscription_anonymization();
	}

	/**
	 * Add privacy policy content for the privacy policy page.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public function get_privacy_message() {
		return '' .
		'<p>' . __( 'By using WooCommerce Subscriptions, you may be storing personal data and depending on which third-party payment processors you’re using to take subscription payments, you may be sharing personal data with external sources.', 'woocommerce-subscriptions' ) . '</p>' .
		// translators: placeholders are opening and closing link tags, linking to additional privacy policy documentation.
		'<h3>' . __( 'What we collect and store', 'woocommerce-subscriptions' ) . '</h3>' .
		'<p>' . __( 'For the purposes of processing recurring subscription payments, we store the customer\'s name, billing address, shipping address, email address, phone number and credit card/payment details.', 'woocommerce-subscriptions' ) . '</p>' .
		'<h3>' . __( 'What we share with others', 'woocommerce-subscriptions' ) . '</h3>' .
		'<p>' . __( 'What personal information your store shares with external sources depends on which third-party payment processor plugins you are using to collect subscription payments. We recommend that you consult with their privacy policies to inform this section of your privacy policy.', 'woocommerce-subscriptions' ) . '</p>' .
		// translators: placeholders are opening and closing link tags, linking to additional privacy policy documentation.
		'<p>' . sprintf( __( 'If you are using PayPal Standard or PayPal Reference transactions please see the %1$sPayPal Privacy Policy%2$s for more details.', 'woocommerce-subscriptions' ), '<a href="https://www.paypal.com/us/webapps/mpp/ua/privacy-full">', '</a>' ) . '</p>';
	}

	/**
	 * Adds the option to remove personal data from subscription via a bulk action.
	 *
	 * @since 5.2.0
	 *
	 * @param array $bulk_actions Subscription bulk actions.
	 *
	 * @return array
	 */
	public static function add_privacy_bulk_action( $bulk_actions ) {
		$bulk_actions['wcs_remove_personal_data'] = __( 'Cancel and remove personal data', 'woocommerce-subscriptions' );
		return $bulk_actions;
	}

	/**
	 * Handles the Remove Personal Data bulk action requests for Subscriptions.
	 *
	 * @param string $redirect_url     The default URL to redirect to after handling the bulk action request.
	 * @param string $action           The action to take against the list of subscriptions.
	 * @param array  $subscription_ids The list of subscription to run the action against.
	 */
	public static function handle_privacy_bulk_actions( $redirect_url, $action, $subscription_ids ) {
		if ( 'wcs_remove_personal_data' !== $action ) {
			return $redirect_url;
		}

		$changed       = 0;
		$sendback_args = [
			'bulk_action' => 'wcs_remove_personal_data',
			'ids'         => join( ',', $subscription_ids ),
			'error_count' => 0,
		];

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( is_a( $subscription, 'WC_Subscription' ) ) {
				do_action( 'woocommerce_remove_subscription_personal_data', $subscription );
				$changed++;
			}
		}

		$sendback_args['changed'] = $changed;
		$sendback                 = add_query_arg( $sendback_args, $redirect_url );

		return esc_url_raw( $sendback );
	}

	/**
	 * Process the request to delete personal data from subscriptions via admin bulk action.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public static function process_bulk_action() {
		/**
		 * We only want to deal with shop_subscription bulk actions.
		 *
		 * Note: The nonce checks are ignored below as we are validating the request before returning.
		 */
		if ( ! isset( $_REQUEST['post_type'] ) || 'shop_subscription' !== $_REQUEST['post_type'] || ! isset( $_REQUEST['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer( 'bulk-posts' );

		$action = '';

		if ( isset( $_REQUEST['action'] ) && -1 !== $_REQUEST['action'] ) {
			$action = wc_clean( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && -1 !== $_REQUEST['action2'] ) {
			$action = wc_clean( wp_unslash( $_REQUEST['action2'] ) );
		}

		$subscription_ids  = array_map( 'absint', (array) $_REQUEST['post'] );
		$base_redirect_url = wp_get_referer() ? wp_get_referer() : '';

		$redirect_url = self::handle_privacy_bulk_actions( $base_redirect_url, $action, $subscription_ids );

		wp_safe_redirect( $redirect_url );
		exit();
	}

	/**
	 * Add admin notice after processing personal data removal bulk action.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 */
	public static function bulk_admin_notices() {
		// Nonce verification is not required here because we're just displaying an admin notice after a verified request was made.
		if ( ! isset( $_REQUEST['bulk_action'] ) || ! 'wcs_remove_personal_data' === wc_clean( wp_unslash( $_REQUEST['bulk_action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$current_screen             = get_current_screen();
			$is_subscription_list_table = $current_screen && wcs_get_page_screen_id( 'shop_subscription' ) === $current_screen->id;
		} else {
			global $post_type, $pagenow;
			$is_subscription_list_table = 'edit.php' === $pagenow && 'shop_subscription' === $post_type;
		}

		// Bail out if not on shop subscription list page.
		if ( ! $is_subscription_list_table ) {
			return;
		}

		$changed = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0;
		// translators: %d: number of subscriptions affected.
		$message = sprintf( _n( 'Removed personal data from %d subscription.', 'Removed personal data from %d subscriptions.', $changed, 'woocommerce-subscriptions' ), number_format_i18n( $changed ) );
		echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Add a note to WC Personal Data Retention settings explaining that subscription orders aren't affected.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param array $settings WooCommerce Account and Privacy settings.
	 * @return array Account and Privacy settings.
	 */
	public static function add_caveat_to_order_data_retention_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		foreach ( $settings as &$setting ) {
			if ( isset( $setting['id'], $setting['type'] ) && 'personal_data_retention' === $setting['id'] && 'title' === $setting['type'] ) {
				// translators: placeholders are opening and closing tags.
				$note            = sprintf( __( '%1$sNote:%2$s Orders which are related to subscriptions will not be included in the orders affected by these settings.', 'woocommerce-subscriptions' ), '<b>', '</b>' );
				$setting['desc'] = isset( $setting['desc'] ) ? $setting['desc'] . '<br>' . $note : $note;
			}
		}

		return $settings;
	}

	/**
	 * Add admin setting to turn subscription data removal when processing erasure requests on or off.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param array $settings WooCommerce Account and Privacy settings.
	 * @return array Account and Privacy settings.
	 */
	public static function add_subscription_data_retention_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$erasure_text = esc_html__( 'account erasure request', 'woocommerce-subscriptions' );
		if ( current_user_can( 'manage_privacy_options' ) ) {
			$erasure_text = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'tools.php?page=remove_personal_data' ) ), $erasure_text );
		}

		WC_Subscriptions_Admin::insert_setting_after( $settings, 'woocommerce_erasure_request_removes_order_data', array(
			'desc'          => __( 'Remove personal data from subscriptions', 'woocommerce-subscriptions' ),
			/* Translators: %s URL to erasure request screen. */
			'desc_tip'      => sprintf( __( 'When handling an %s, should personal data within subscriptions be retained or removed?', 'woocommerce-subscriptions' ), $erasure_text ),
			'id'            => 'woocommerce_erasure_request_removes_subscription_data',
			'type'          => 'checkbox',
			'default'       => 'no',
			'checkboxgroup' => '',
			'autoload'      => false,
		) );

		WC_Subscriptions_Admin::insert_setting_after( $settings, 'woocommerce_anonymize_completed_orders', array(
			'title'       => __( 'Retain ended subscriptions', 'woocommerce-subscriptions' ),
			'desc_tip'    => __( 'Retain ended subscriptions and their related orders for a specified duration before anonymizing the personal data within them.', 'woocommerce-subscriptions' ),
			'id'          => 'woocommerce_anonymize_ended_subscriptions',
			'type'        => 'relative_date_selector',
			'placeholder' => __( 'N/A', 'woocommerce-subscriptions' ),
			'default'     => array(
				'number' => '',
				'unit'   => 'months',
			),
			'autoload'    => false,
		) );

		return $settings;
	}

	/**
	 * Remove subscription related order types from the order anonymization query.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param  array $query_args @see wc_get_orders() args.
	 * @return array The args used to get orders to anonymize.
	 */
	public static function remove_subscription_orders_from_anonymization_query( $query_args ) {
		if ( ! is_array( $query_args ) ) {
			return $query_args;
		}

		$query_args['subscription_parent']      = false;
		$query_args['subscription_renewal']     = false;
		$query_args['subscription_switch']      = false;
		$query_args['subscription_resubscribe'] = false;

		return $query_args;
	}

	/**
	 * Add a note to the inactive user data retention setting noting that users with a subscription are excluded.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.4
	 * @param array $settings WooCommerce Account and Privacy settings.
	 * @return array Account and Privacy settings.
	 */
	public static function add_inactive_user_retention_note( $settings ) {
		foreach ( $settings as &$setting ) {
			if ( isset( $setting['id'], $setting['desc_tip'] ) && 'woocommerce_delete_inactive_accounts' === $setting['id'] ) {
				$setting['desc_tip'] .= ' ' . __( 'Customers with a subscription are excluded from this setting.', 'woocommerce-subscriptions' );
				break;
			}
		}

		return $settings;
	}

	/**
	 * Set a flag to record inactive user account deletion.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.4
	 * @param  array $user_roles The user roles included in the inactive user query.
	 * @return array
	 */
	public static function flag_subscription_user_exclusion_from_query( $user_roles ) {
		self::$doing_user_inactivity_query = true;
		return $user_roles;
	}

	/**
	 * Exclude customers who have subscriptions from the inactive user cleanup query.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.4
	 * @param WP_User_Query $user_query
	 */
	public static function maybe_exclude_subscription_customers( $user_query ) {
		if ( ! self::$doing_user_inactivity_query ) {
			return;
		}

		$user_query->set( 'exclude', array_merge(
			(array) $user_query->get( 'exclude' ),
			WC_Data_Store::load( 'subscription' )->get_subscription_customer_ids()
		) );

		self::$doing_user_inactivity_query = false;
	}

	/* Deprecated Functions */

	/**
	 * Add the option to remove personal data from subscription via a bulk action.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.20
	 * @param array $bulk_actions Subscription bulk actions.
	 */
	public static function add_remove_personal_data_bulk_action( $bulk_actions ) {
		wcs_deprecated_function( __METHOD__, 'subscriptions-core 5.2.0', 'WCS_Privacy_Exporters::add_privacy_bulk_action' );
		$bulk_actions['remove_personal_data'] = __( 'Cancel and remove personal data', 'woocommerce-subscriptions' );

		return $bulk_actions;
	}
}
