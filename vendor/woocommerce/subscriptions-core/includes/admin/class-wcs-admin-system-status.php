<?php
/**
 * Subscriptions System Status
 *
 * Adds additional Subscriptions related information to the WooCommerce System Status.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin
 * @category   Class
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 */
class WCS_Admin_System_Status {

	/**
	 * @var int Subscriptions' WooCommerce Marketplace product ID
	 */
	const WCS_PRODUCT_ID = 27147;

	/**
	 * Contains pre-determined SSR report data.
	 *
	 * @var array
	 */
	private static $report_data = [];

	/**
	 * Used to cache the result of the comparatively expensive queries executed by
	 * the get_subscriptions_by_gateway() method.
	 *
	 * This cache is short-lived by design, as we don't necessarily want to cache this
	 * across requests (in some troubleshooting/debug scenarios, that could be confusing
	 * for the troubleshooter), which is why a transient or WP caching functions are not
	 * used.
	 *
	 * @var null|array
	 */
	private static $statuses_by_gateway = null;

	/**
	 * Used to cache the subscriptions-by-status counts.
	 *
	 * As with with self::$statuses_by_gateway, the cache is deliberately short-lived.
	 *
	 * @var null|array
	 */
	private static $subscription_status_counts = null;

	/**
	 * Attach callbacks
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public static function init() {
		add_filter( 'woocommerce_system_status_report', array( __CLASS__, 'render_system_status_items' ) );
	}

	/**
	 * Renders the Subscription information in the WC status page
	 *
	 * @since 1.0.0 Migrated from WooCommerce Subscriptions v2.3.0
	 * @since 7.2.0 Uses supplied report data if available.
	 *
	 * @param mixed $report Pre-determined SSR report data.
	 */
	public static function render_system_status_items( $report = null ) {
		/**
		 * From WooCommerce 9.8.0, we will be supplied with SSR data fetched via a (programmatic)
		 * REST API request. Using this when available can help prevent duplicated work.
		 *
		 * @see WC_REST_Subscription_System_Status_Manager::add_subscription_fields_to_response()
		 */
		if ( is_array( $report ) && is_array( $report['subscriptions'] ) && ! empty( $report['subscriptions'] ) ) {
			self::$report_data = $report['subscriptions'];
		}

		$store_data                            = [];
		$subscriptions_data                    = [];
		$subscriptions_by_payment_gateway_data = [];
		$payment_gateway_data                  = [];

		self::set_debug_mode( $subscriptions_data );
		self::set_staging_mode( $subscriptions_data );
		self::set_live_site_url( $subscriptions_data );
		self::set_library_version( $subscriptions_data );
		self::set_theme_overrides( $subscriptions_data );
		self::set_subscription_statuses( $subscriptions_data );
		self::set_woocommerce_account_data( $subscriptions_data );

		// Subscriptions by Payment Gateway
		self::set_subscriptions_by_payment_gateway( $subscriptions_by_payment_gateway_data );

		// Payment gateway features
		self::set_subscriptions_payment_gateway_support( $payment_gateway_data );

		// Store settings
		self::set_store_location( $store_data );

		$system_status_sections = array(
			array(
				'title'   => __( 'Subscriptions', 'woocommerce-subscriptions' ),
				'tooltip' => __( 'This section shows any information about Subscriptions.', 'woocommerce-subscriptions' ),
				'data'    => apply_filters( 'wcs_system_status', $subscriptions_data ),
			),
			array(
				'title'   => __( 'Store Setup', 'woocommerce-subscriptions' ),
				'tooltip' => __( 'This section shows general information about the store.', 'woocommerce-subscriptions' ),
				'data'    => $store_data,
			),
			array(
				'title'   => __( 'Subscriptions by Payment Gateway', 'woocommerce-subscriptions' ),
				'tooltip' => __( 'This section shows information about Subscription payment methods.', 'woocommerce-subscriptions' ),
				'data'    => $subscriptions_by_payment_gateway_data,
			),
			array(
				'title'   => __( 'Payment Gateway Support', 'woocommerce-subscriptions' ),
				'tooltip' => __( 'This section shows information about payment gateway feature support.', 'woocommerce-subscriptions' ),
				'data'    => $payment_gateway_data,
			),
		);

		foreach ( $system_status_sections as $section ) {
			$section_title   = $section['title'];
			$section_tooltip = $section['tooltip'];
			$debug_data      = $section['data'];

			include WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/admin/status.php' );
		}
	}

	/**
	 * Include WCS_DEBUG flag
	 */
	private static function set_debug_mode( &$debug_data ) {
		$is_wcs_debug = defined( 'WCS_DEBUG' ) ? WCS_DEBUG : false;

		$debug_data['wcs_debug'] = array(
			'name'    => _x( 'WCS_DEBUG', 'label that indicates whether debugging is turned on for the plugin', 'woocommerce-subscriptions' ),
			'label'   => 'WCS_DEBUG',
			'note'    => ( $is_wcs_debug ) ? __( 'Yes', 'woocommerce-subscriptions' ) : __( 'No', 'woocommerce-subscriptions' ),
			'success' => $is_wcs_debug ? 0 : 1,
		);
	}

	/**
	 * Include the staging/live mode the store is running in.
	 *
	 * @param array $debug_data
	 */
	private static function set_staging_mode( &$debug_data ) {
		$debug_data['wcs_staging'] = array(
			'name'    => _x( 'Subscriptions Mode', 'Live or Staging, Label on WooCommerce -> System Status page', 'woocommerce-subscriptions' ),
			'label'   => 'Subscriptions Mode',
			'note'    => '<strong>' . ( ( WCS_Staging::is_duplicate_site() ) ? _x( 'Staging', 'refers to staging site', 'woocommerce-subscriptions' ) : _x( 'Live', 'refers to live site', 'woocommerce-subscriptions' ) ) . '</strong>',
			'success' => ( WCS_Staging::is_duplicate_site() ) ? 0 : 1,
		);
	}

	/**
	 * @param array $debug_data
	 */
	private static function set_live_site_url( &$debug_data ) {
		// Use pre-determined SSR data if possible.
		$site_url = isset( self::$report_data['live_url'] )
			? self::$report_data['live_url']
			: WCS_Staging::get_site_url_from_source( 'subscriptions_install' );

		$debug_data['wcs_live_site_url'] = array(
			'name'      => _x( 'Subscriptions Live URL', 'Live URL, Label on WooCommerce -> System Status page', 'woocommerce-subscriptions' ),
			'label'     => 'Subscriptions Live URL',
			'note'      => '<a href="' . esc_url( $site_url ) . '">' . esc_html( $site_url ) . '</a>',
			'mark'      => '',
			'mark_icon' => '',
		);
	}

	/**
	 * @param array $debug_data
	 */
	private static function set_library_version( &$debug_data ) {
		$debug_data['wcs_subs_core_version'] = array(
			'name'      => _x( 'Subscriptions-core Library Version', 'Subscriptions-core Version, Label on WooCommerce -> System Status page', 'woocommerce-subscriptions' ),
			'label'     => 'Subscriptions-core Library Version',
			'note'      => WC_Subscriptions_Core_Plugin::instance()->get_library_version(),
			'mark'      => '',
			'mark_icon' => '',
		);
	}

	/**
	 * List any Subscriptions template overrides.
	 */
	private static function set_theme_overrides( &$debug_data ) {
		$theme_overrides = self::get_theme_overrides();

		if ( ! empty( $theme_overrides['overrides'] ) ) {
			$debug_data['wcs_theme_overrides'] = array(
				'name'  => _x( 'Subscriptions Template Theme Overrides', 'label for the system status page', 'woocommerce-subscriptions' ),
				'label' => 'Subscriptions Template Theme Overrides',
				'data'  => $theme_overrides['overrides'],
			);

			// Include a note on how to update if the templates are out of date.
			if ( ! empty( $theme_overrides['has_outdated_templates'] ) && true === $theme_overrides['has_outdated_templates'] ) {
				$debug_data['wcs_theme_overrides'] += array(
					'mark_icon' => 'warning',
					// translators: placeholders are opening/closing tags linking to documentation on outdated templates.
					'note'      => sprintf( __( '%1$sLearn how to update%2$s', 'woocommerce-subscriptions' ), '<a href="https://developer.woocommerce.com/docs/how-to-fix-outdated-woocommerce-templates/" target="_blank">', '</a>' ),
				);
			}
		}
	}

	/**
	 * Determine which of our files have been overridden by the theme.
	 *
	 * @return array Theme override data.
	 */
	private static function get_theme_overrides() {
		$wcs_template_dir = WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' );
		$wc_template_path = trailingslashit( wc()->template_path() );
		$theme_root       = trailingslashit( get_theme_root() );
		$overridden       = array();
		$outdated         = false;
		$templates        = WC_Admin_Status::scan_template_files( $wcs_template_dir );

		foreach ( $templates as $file ) {
			$theme_file = false;
			$locations  = array(
				get_stylesheet_directory() . "/{$file}",
				get_stylesheet_directory() . "/{$wc_template_path}{$file}",
				get_template_directory() . "/{$file}",
				get_template_directory() . "/{$wc_template_path}{$file}",
			);

			foreach ( $locations as $location ) {
				if ( is_readable( $location ) ) {
					$theme_file = $location;
					break;
				}
			}

			if ( ! empty( $theme_file ) ) {
				$core_version  = WC_Admin_Status::get_file_version( $wcs_template_dir . $file );
				$theme_version = WC_Admin_Status::get_file_version( $theme_file );

				$overridden_template_output = sprintf( '<code>%s</code>', esc_html( str_replace( $theme_root, '', $theme_file ) ) );

				if ( $core_version && ( empty( $theme_version ) || version_compare( $theme_version, $core_version, '<' ) ) ) {
					$outdated                    = true;
					$overridden_template_output .= sprintf(
						/* translators: %1$s is the file version, %2$s is the core version */
						esc_html__( 'version %1$s is out of date. The core version is %2$s', 'woocommerce-subscriptions' ),
						'<strong style="color:red">' . esc_html( $theme_version ) . '</strong>',
						'<strong>' . esc_html( $core_version ) . '</strong>'
					);
				}

				$overridden['overrides'][] = $overridden_template_output;
			}
		}

		$overridden['has_outdated_templates'] = $outdated;

		return $overridden;
	}

	/**
	 * Add a breakdown of Subscriptions per status.
	 */
	private static function set_subscription_statuses( &$debug_data ) {
		$debug_data['wcs_subscriptions_by_status'] = array(
			'name'      => _x( 'Subscription Statuses', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'     => 'Subscription Statuses',
			'mark'      => '',
			'mark_icon' => '',
			'data'      => self::get_subscription_statuses(),
		);
	}

	/**
	 * Include information about whether the store is linked to a WooCommerce account and whether they have an active WCS product key.
	 */
	private static function set_woocommerce_account_data( &$debug_data ) {

		if ( ! class_exists( 'WC_Helper' ) ) {
			return;
		}

		$woocommerce_account_auth      = WC_Helper_Options::get( 'auth' );
		$woocommerce_account_connected = ! empty( $woocommerce_account_auth );

		$debug_data['wcs_woocommerce_account_connected'] = array(
			'name'    => _x( 'WooCommerce Account Connected', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'   => 'WooCommerce Account Connected',
			'note'    => $woocommerce_account_connected ? 'Yes' : 'No',
			'success' => $woocommerce_account_connected,
		);

		if ( ! $woocommerce_account_connected ) {
			return;
		}

		// Check for an active WooCommerce Subscriptions product key
		$woocommerce_account_subscriptions = WC_Helper::get_subscriptions();
		$site_id                           = absint( $woocommerce_account_auth['site_id'] );
		$has_active_product_key            = false;

		foreach ( $woocommerce_account_subscriptions as $subscription ) {
			if ( isset( $subscription['product_id'] ) && self::WCS_PRODUCT_ID === $subscription['product_id'] ) {
				$has_active_product_key = in_array( $site_id, $subscription['connections'], false ); // phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse -- In case the value from $subscription['connections'] is a string.
				break;
			}
		}

		$debug_data['wcs_active_product_key'] = array(
			'name'    => _x( 'Active Product Key', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'   => 'Active Product Key',
			'note'    => $has_active_product_key ? 'Yes' : 'No',
			'success' => $has_active_product_key,
		);
	}

	/**
	 * Add a breakdown of subscriptions per payment gateway.
	 */
	private static function set_subscriptions_by_payment_gateway( &$debug_data ) {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		$subscriptions_by_gateway = isset( self::$report_data['subscriptions_by_payment_gateway'] )
			? self::$report_data['subscriptions_by_payment_gateway']
			: self::get_subscriptions_by_gateway();

		foreach ( $subscriptions_by_gateway as $payment_method => $status_counts ) {
			if ( isset( $gateways[ $payment_method ] ) ) {
				$payment_method_name  = $gateways[ $payment_method ]->method_title;
				$payment_method_label = $gateways[ $payment_method ]->method_title;
			} else {
				$payment_method_label = 'other';
				$payment_method       = 'other';
				$payment_method_name  = _x( 'Other', 'label for the system status page', 'woocommerce-subscriptions' );
			}

			$key = 'wcs_payment_method_subscriptions_by' . $payment_method;

			$debug_data[ $key ] = array(
				'name'  => $payment_method_name,
				'label' => $payment_method_label,
				'data'  => array(),
			);

			foreach ( $status_counts as $status => $count ) {
				$debug_data[ $key ]['data'][] = "$status: $count";
			}
		}
	}

	/**
	 * List the enabled payment gateways and the features they support.
	 */
	private static function set_subscriptions_payment_gateway_support( &$debug_data ) {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		foreach ( $gateways as $gateway_id => $gateway ) {
			$debug_data[ 'wcs_' . $gateway_id . '_feature_support' ] = array(
				'name'  => $gateway->method_title,
				'label' => $gateway->method_title,
				'data'  => (array) apply_filters( 'woocommerce_subscriptions_payment_gateway_features_list', $gateway->supports, $gateway ),
			);

			if ( 'paypal' === $gateway_id ) {
				$are_reference_transactions_enabled = WCS_PayPal::are_reference_transactions_enabled();

				$debug_data['wcs_paypal_reference_transactions'] = array(
					'name'      => _x( 'PayPal Reference Transactions Enabled', 'label for the system status page', 'woocommerce-subscriptions' ),
					'label'     => 'PayPal Reference Transactions Enabled',
					'mark_icon' => $are_reference_transactions_enabled ? 'yes' : 'warning',
					'note'      => $are_reference_transactions_enabled ? 'Yes' : 'No',
					'success'   => $are_reference_transactions_enabled,
				);
			}
		}
	}

	/**
	 * Add the store's country and state information.
	 */
	private static function set_store_location( &$debug_data ) {
		$store_base_location   = wc_get_base_location();
		$countries             = WC()->countries->get_countries();
		$states                = WC()->countries->get_states( $store_base_location['country'] );
		$store_location_string = '';

		if ( isset( $countries[ $store_base_location['country'] ] ) ) {
			$store_location_string = $countries[ $store_base_location['country'] ];

			if ( ! empty( $states[ $store_base_location['state'] ] ) ) {
				$store_location_string .= ' &mdash; ' . $states[ $store_base_location['state'] ];
			}
		}

		$debug_data['wcs_store_location'] = array(
			'name'      => _x( 'Country / State', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'     => 'Country / State',
			'note'      => $store_location_string,
			'mark'      => '',
			'mark_icon' => '',
		);
	}

	/**
	 * Gets the store's subscription broken down by payment gateway and status.
	 *
	 * @since 1.0.0 Migrated from WooCommerce Subscriptions v3.1.0.
	 * @since 7.2.0 Information is cached per request.
	 *
	 * @return array The subscription gateway and status data array( 'gateway_id' => array( 'status' => count ) );
	 */
	public static function get_subscriptions_by_gateway() {
		// Return cached result if possible.
		if ( isset( self::$statuses_by_gateway ) ) {
			return self::$statuses_by_gateway;
		}

		global $wpdb;
		$subscription_gateway_data = [];
		$is_hpos_in_use            = wcs_is_custom_order_tables_usage_enabled();
		$order_status_column_name  = $is_hpos_in_use ? 'status' : 'post_status';

		// Conduct a different query for HPOS and non-HPOS stores.
		if ( $is_hpos_in_use ) {
			// With HPOS enabled, `payment_method` is a column in the `wc_orders` table.
			$results = $wpdb->get_results(
				"SELECT
					COUNT(subscriptions.id) as count,
					subscriptions.payment_method,
					subscriptions.status
				FROM {$wpdb->prefix}wc_orders as subscriptions
				WHERE subscriptions.type = 'shop_subscription'
				GROUP BY subscriptions.payment_method, subscriptions.status",
				ARRAY_A
			);
		} else {
			// With HPOS disabled, `_payment_method` is a column in the `postmeta` table.
			$results = $wpdb->get_results(
				"SELECT
					COUNT(subscriptions.ID) as count,
					post_meta.meta_value as payment_method,
					subscriptions.post_status
				FROM {$wpdb->prefix}posts as subscriptions
				RIGHT JOIN {$wpdb->prefix}postmeta as post_meta ON post_meta.post_id = subscriptions.ID
				WHERE
					subscriptions.post_type = 'shop_subscription'
					&& post_meta.meta_key = '_payment_method'
				GROUP BY post_meta.meta_value, subscriptions.post_status",
				ARRAY_A
			);
		}

		foreach ( $results as $result ) {
			// Ignore any results that don't have a payment method.
			if ( empty( $result['payment_method'] ) ) {
				continue;
			}
			$subscription_gateway_data[ $result['payment_method'] ][ $result[ $order_status_column_name ] ] = $result['count'];
		}

		self::$statuses_by_gateway = $subscription_gateway_data;
		return $subscription_gateway_data;
	}

	/**
	 * Gets the store's subscriptions by status.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 * @return array
	 */
	public static function get_subscription_statuses() {
		// We don't look inside self::$report_data here, because the REST API report itself
		// also uses self::get_subscription_status_counts().
		$subscriptions_by_status        = self::get_subscription_status_counts();
		$subscriptions_by_status_output = array();

		foreach ( $subscriptions_by_status as $status => $count ) {
			if ( ! empty( $count ) ) {
				$subscriptions_by_status_output[] = $status . ': ' . $count;
			}
		}

		return $subscriptions_by_status_output;
	}

	/**
	 * Returns a cached array of subscription statuses along with the corresponding number
	 * of subscriptions for each (the values).
	 *
	 * Example:
	 *
	 *     [
	 *         'wc-active'    => 100,
	 *         'wc-cancelled' => 200,
	 *         '...'          => 300,
	 *     ]
	 *
	 * @param bool $fresh If cached results should be discarded.
	 *
	 * @return array
	 */
	public static function get_subscription_status_counts( bool $fresh = false ): array {
		// Return cached result if possible.
		if ( ! $fresh && isset( self::$subscription_status_counts ) ) {
			return self::$subscription_status_counts;
		}

		try {
			self::$subscription_status_counts = WC_Data_Store::load( 'subscription' )->get_subscriptions_count_by_status();
		} catch ( Exception $e ) {
			// If an exception was raised, don't cache the result.
			return [];
		}

		return self::$subscription_status_counts;
	}
}
