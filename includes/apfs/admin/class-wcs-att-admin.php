<?php
/**
 * WCS_ATT_Admin class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 1.0.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin includes and hooks.
 *
 * @class    WCS_ATT_Admin
 * @version  6.1.0
 */
class WCS_ATT_Admin {

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Add hooks.
	 */
	private static function add_hooks() {

		/*
		 * Single-Product settings.
		 */

		// Metabox includes.
		add_action( 'init', array( __CLASS__, 'admin_init' ) );

		// Admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_scripts' ) );

		/*
		 * Subscribe-to-Cart settings.
		 */

		// Prepend "Subscription Plans" section in the Subscriptions settings tab.
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ), 1000 );

		// Prepend "Add to Subscription" section (runs before Subscription Plans, so appears after it on page).
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_subscription_management_settings' ), 998 );

		// Display subscription scheme admin metaboxes in the "Subscribe to Cart/Order" section.
		add_action( 'woocommerce_admin_field_subscription_schemes', array( __CLASS__, 'subscription_schemes_content' ) );

		/*
		 * Extra 'Allow Switching' checkboxes.
		 */

		add_filter( 'woocommerce_subscriptions_allow_switching_options', array( __CLASS__, 'allow_switching_options' ) );

		// Add template override scan path in tracking info.
		add_filter( 'woocommerce_template_overrides_scan_paths', array( __CLASS__, 'template_scan_path' ) );

		// Add APFS debug data in the system status.
		add_action( 'woocommerce_system_status_report', array( __CLASS__, 'render_system_status_items' ) );
	}

	/**
	 * Admin init.
	 */
	public static function admin_init() {
		self::includes();
	}

	/**
	 * Include classes.
	 */
	public static function includes() {

		WCS_ATT_Product_Export::init();
		WCS_ATT_Product_Import::init();

		WCS_ATT_Admin_Ajax::init();
		WCS_ATT_Meta_Box_Product_Data::init();
	}

	/**
	 * Add extra 'Allow Switching > 'Between Subscription Plans' option.
	 * In the past there was no option to turn off this feature.
	 *
	 * @param  array $data
	 * @return array
	 */
	public static function allow_switching_options( $data ) {

		$switch_option_product_plans = get_option( 'woocommerce_subscriptions_allow_switching_product_plans', '' );

		if ( '' === $switch_option_product_plans ) {
			update_option( 'woocommerce_subscriptions_allow_switching_product_plans', 'yes' );
		}

		return array_merge(
			$data,
			array(
				array(
					'id'    => 'product_plans',
					'label' => __( 'Between Subscription Plans', 'woocommerce-subscriptions' ),
				),
			)
		);
	}

	/**
	 * Subscriptions schemes admin metaboxes.
	 *
	 * Renders the React root element for the storewide plans React app.
	 *
	 * @param  array $values Settings field values.
	 * @return void
	 */
	public static function subscription_schemes_content( $values ) {
		// Get current value from database.
		$current_schemes = get_option( 'wcsatt_subscribe_to_cart_schemes', array() );
		$schemes_json    = is_array( $current_schemes ) ? wp_json_encode( array_values( $current_schemes ) ) : '[]';
		$description     = isset( $values['desc'] ) ? (string) $values['desc'] : '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php
				/*
				 * `<h2>` (peer to WC's other section H2s like "Roles",
				 * "Renewals") + `<p>` so screen-reader users can jump to
				 * this section with heading-navigation shortcuts and the
				 * description reads as the section's preamble. Figma's
				 * smaller visual weight is restored entirely via CSS.
				 */
				?>
				<h2 class="wcsatt-plans-list-label"><?php echo esc_html( $values['title'] ); ?></h2>
				<?php if ( '' !== $description ) : ?>
					<p class="wcsatt-plans-list-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
			</th>
			<td class="forminp forminp-subscription_schemes_metaboxes">
				<?php /* React root element for StorewidePlansApp */ ?>
				<div id="wcsatt-storewide-plans-root"></div>
				<?php /* Hidden input for form submission - React will update this value */ ?>
				<input type="hidden" name="wcsatt_schemes" value="<?php echo esc_attr( $schemes_json ); ?>" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Append "Subscribe to Cart/Order" section in the Subscriptions settings tab.
	 *
	 * @since  APFS 2.1.0
	 *
	 * @param  array $settings
	 * @return array
	 */
	public static function add_settings( $settings ) {

		// Figma node 3654:27409 — no section H2 above the storewide-plans
		// row, just the 2-col label/description on the left + bordered
		// table card on the right. The previous `Subscription Plans`
		// title field + its descriptive paragraph are removed; the
		// section heading + description now live entirely in the
		// storewide-plans row's `<th>` cell.
		//
		// A `title` field with empty `name` + empty `desc` still opens
		// here so WC emits `<table class="form-table">` to wrap the
		// schemes row's `<tr>` — without it, the row's `<th>`/`<td>`
		// would render as loose blocks (no 2-col layout) because the
		// `:has()` CSS rule that drives the 250/40 grid requires the
		// `<th>` to be inside a `table.form-table`.
		$settings_to_add = array(
			array(
				'name' => '',
				'type' => 'title',
				'desc' => '',
				'id'   => 'wcsatt_subscribe_to_cart_options',
			),
			array(
				'name' => __( 'Storewide subscription plans', 'woocommerce-subscriptions' ),
				// Figma 3654:27413 — exact spec text.
				'desc' => __( 'Create a set of subscription plans that can be easily added to simple and variable products. You can also add custom subscription plans directly within individual product settings.', 'woocommerce-subscriptions' ),
				'id'   => 'wcsatt_subscribe_to_cart_schemes',
				'type' => 'subscription_schemes',
			),
			array(
				'name'        => __( 'Purchase option text', 'woocommerce-subscriptions' ),
				'desc'        => __( 'Optionally display custom text above the purchase options on the product page. Supports HTML and shortcodes.', 'woocommerce-subscriptions' ),
				'desc_at_end' => true,
				'id'          => 'wcsatt_subscribe_to_cart_prompt',
				'type'        => 'textarea',
				'placeholder' => __( 'e.g. "Choose a purchase plan:"', 'woocommerce-subscriptions' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcsatt_subscribe_to_cart_options',
			),
		);

		if ( ! empty( $settings_to_add ) ) {
			$settings = array_merge( $settings_to_add, $settings );
		}

		return $settings;
	}

	/**
	 * Add "Add to Subscription" settings section.
	 *
	 * @param  array $settings
	 * @return array
	 */
	public static function add_subscription_management_settings( $settings ) {

		if ( ! WCS_ATT()->is_module_registered( 'manage' ) ) {
			return $settings;
		}

		$settings_to_add = array(
			array(
				'name' => __( 'Add to Subscription', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'desc' => WCS_ATT_Integrations::is_block_based_cart() ? __( 'Allow customers to add products to their existing subscriptions.', 'woocommerce-subscriptions' ) : __( 'Allow customers to add individual products and/or entire carts to their existing subscriptions.', 'woocommerce-subscriptions' ),
				'id'   => 'wcsatt_add_to_subscription_options',
			),
			array(
				'name'     => __( 'Products', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Allow customers to add individual products to existing subscriptions.', 'woocommerce-subscriptions' ),
				'id'       => 'wcsatt_add_product_to_subscription',
				'type'     => 'select',
				'options'  => array(
					'off'              => _x( 'Disabled', 'adding a product to an existing subscription', 'woocommerce-subscriptions' ),
					'matching_schemes' => _x( 'Enabled for products with Subscription Plans', 'adding a product to an existing subscription', 'woocommerce-subscriptions' ),
					'on'               => _x( 'Enabled', 'adding a product to an existing subscription', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),
		);

		if ( ! WCS_ATT_Integrations::is_block_based_cart() ) {
			$settings_to_add[] = array(
				'name'     => __( 'Cart Contents', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Allow customers to add their cart contents to an existing subscription.', 'woocommerce-subscriptions' ),
				'id'       => 'wcsatt_add_cart_to_subscription',
				'type'     => 'select',
				'options'  => array(
					'off'        => _x( 'Disabled', 'adding a cart to an existing subscription', 'woocommerce-subscriptions' ),
					'plans_only' => _x( 'Enabled when cart contents have Subscription Plans', 'adding a cart to an existing subscription', 'woocommerce-subscriptions' ),
					'on'         => _x( 'Enabled', 'adding a cart to an existing subscription', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			);
		}

		$settings_to_add[] = array(
			'type' => 'sectionend',
			'id'   => 'wcsatt_add_to_subscription_options',
		);

		return array_merge( $settings_to_add, $settings );
	}

	/**
	 * Load scripts and styles.
	 *
	 * APFS functionality is included in the main admin.js bundle loaded by WCS_Admin_Assets.
	 * This method only handles APFS-specific styles and localization parameters.
	 *
	 * @return void
	 */
	public static function admin_scripts() {

		global $post;

		// Get admin screen id.
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		// Enqueue APFS admin styles on product edit screens and settings page.
		if ( in_array( $screen_id, array( 'edit-product', 'product', WCS_ATT_Core_Compatibility::get_formatted_screen_id( 'woocommerce_page_wc-settings' ) ), true ) ) {

			// Load webpack-generated asset file for version.
			$asset_file_path = WCS_ATT()->plugin_path() . '/build/admin.asset.php';
			$asset_file      = file_exists( $asset_file_path ) ? include $asset_file_path : array(
				'version' => WC_Subscriptions::$version,
			);

			// Register and enqueue APFS admin stylesheet (already includes APFS styles from webpack).
			// Version by the stylesheet's own modification time: the asset.php
			// version is a JS-bundle hash, so CSS-only changes would otherwise
			// reuse the same `?ver` and serve a stale cached stylesheet.
			$admin_css_path = WCS_ATT()->plugin_path() . '/build/admin.css';
			wp_register_style(
				'wcsatt-admin-css',
				WCS_ATT()->plugin_url() . '/build/admin.css',
				array( 'woocommerce_admin_styles' ),
				file_exists( $admin_css_path ) ? filemtime( $admin_css_path ) : $asset_file['version']
			);

			// Add RTL support for admin styles.
			wp_style_add_data( 'wcsatt-admin-css', 'rtl', 'replace' );
			wp_enqueue_style( 'wcsatt-admin-css' );
		}

		// Localize the main admin script with APFS parameters on product and settings pages.
		// Note: The 'wcs-admin' script is registered and enqueued by WCS_Admin_Assets.
		if ( in_array( $screen_id, array( 'product', WCS_ATT_Core_Compatibility::get_formatted_screen_id( 'woocommerce_page_wc-settings' ) ) ) ) {

			// Get storewide plans for both product and settings pages.
			$storewide_plans = array();
			// Fetch storewide plans from WordPress option.
			$plans_data = get_option( 'wcsatt_subscribe_to_cart_schemes', array() );
			if ( is_array( $plans_data ) && ! empty( $plans_data ) ) {
				foreach ( $plans_data as $plan_data ) {
					// Initialize scheme object to ensure all properties are set with defaults.
					$scheme = new WCS_ATT_Scheme( array( 'data' => $plan_data ) );

					$sync_date_raw = $scheme->get_sync_date();

					$storewide_plans[] = array(
						'id'                             => $scheme->get_key(),
						'subscription_period_interval'   => $scheme->get_interval(),
						'subscription_period'            => $scheme->get_period(),
						'subscription_length'            => $scheme->get_length(),
						'subscription_pricing_method'    => $scheme->get_pricing_mode(),
						'subscription_discount'          => $scheme->get_discount(),
						'subscription_signup_fee'        => $scheme->get_signup_fee(),
						'subscription_trial_length'      => $scheme->get_trial_length(),
						'subscription_trial_period'      => $scheme->get_trial_period(),
						'subscription_payment_sync_date' => is_array( $sync_date_raw ) ? $sync_date_raw : array( 'day' => absint( $sync_date_raw ) ),
					);
				}
			}

			$params = array(
				'subscription_lengths'               => wcs_get_subscription_ranges(),
				'storewide_plans'                    => $storewide_plans,
				'i18n_do_no_sync'                    => __( 'Disabled', 'woocommerce-subscriptions' ),
				'i18n_inherit_option'                => __( 'Inherit from product', 'woocommerce-subscriptions' ),
				'i18n_inherit_option_variable'       => __( 'Inherit from chosen variation', 'woocommerce-subscriptions' ),
				'i18n_override_option'               => __( 'Override product', 'woocommerce-subscriptions' ),
				'i18n_override_option_variable'      => __( 'Override all variations', 'woocommerce-subscriptions' ),
				'i18n_discount_description'          => __( 'Discount to apply to the product when this plan is selected.', 'woocommerce-subscriptions' ),
				'i18n_discount_description_variable' => __( 'Discount to apply to the chosen variation when this plan is selected.', 'woocommerce-subscriptions' ),
				'wc_ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'post_id'                            => is_object( $post ) ? $post->ID : '',
				'sync_options'                       => class_exists( 'WC_Subscriptions_Synchroniser' )
					? array(
						'week'  => self::format_sync_options( WC_Subscriptions_Synchroniser::get_billing_period_ranges( 'week' ) ),
						'month' => self::format_sync_options( WC_Subscriptions_Synchroniser::get_billing_period_ranges( 'month' ) ),
						'year'  => self::format_sync_options( self::get_year_sync_options_with_labels() ),
					)
					: array(),
			);

			// Localize the main admin script (loaded by WCS_Admin_Assets).
			wp_localize_script( 'wcs-admin', 'wcsatt_admin_params', $params );

			// Localize product plans for product edit page.
			if ( 'product' === $screen_id && is_object( $post ) && $post->ID > 0 ) {
				$product = wc_get_product( $post->ID );
				if ( $product ) {
					// Get custom product plans. Data is preserved in place across mode switches --
					// the product's current mode (from _wcsatt_schemes_status) is the authoritative source.
					$product_plans_data = $product->get_meta( '_wcsatt_schemes', true );
					$product_plans      = array();

					if ( is_array( $product_plans_data ) && ! empty( $product_plans_data ) ) {
						foreach ( $product_plans_data as $plan_data ) {
							// Initialize scheme object to ensure all properties are set with defaults.
							$scheme = new WCS_ATT_Scheme( array( 'data' => $plan_data ) );

							$sync_date_raw = $scheme->get_sync_date();

							$product_plans[] = array(
								'id'                      => $scheme->get_key(),
								'subscription_period_interval' => $scheme->get_interval(),
								'subscription_period'     => $scheme->get_period(),
								'subscription_length'     => $scheme->get_length(),
								'subscription_discount'   => $scheme->get_discount(),
								'subscription_signup_fee' => $scheme->get_signup_fee(),
								'subscription_trial_length' => $scheme->get_trial_length(),
								'subscription_trial_period' => $scheme->get_trial_period(),
								'subscription_pricing_method' => $scheme->get_pricing_mode(),
								'subscription_regular_price' => $scheme->get_regular_price(),
								'subscription_sale_price' => $scheme->get_sale_price(),
								'subscription_payment_sync_date' => is_array( $sync_date_raw ) ? $sync_date_raw : array( 'day' => absint( $sync_date_raw ) ),
							);
						}
					}

					// Localize product plans data.
					wp_localize_script(
						'wcs-admin',
						'wcsatt_product_plans',
						array(
							'plans' => $product_plans,
						)
					);
				}
			}
		}
	}

	/**
	 * Support scanning for template overrides in extension.
	 *
	 * @since APFS 3.1.8
	 *
	 * @param  array $paths
	 * @return array
	 */
	public static function template_scan_path( $paths ) {

		$paths['All Products for WooCommerce Subscriptions'] = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/';

		return $paths;
	}

	/**
	 * Convert a PHP associative options map (value => label) to the
	 * [{value, label}] format expected by the React SelectControl component.
	 *
	 * @param array $options_map Associative array of value => label pairs.
	 * @return array Array of { value, label } objects sorted by numeric value.
	 */
	/**
	 * Get year sync options with "each year" labels per Figma design.
	 *
	 * Wraps WC_Subscriptions_Synchroniser::get_year_sync_options() and appends
	 * "each year" to each month name (e.g., "January" → "January each year").
	 *
	 * @since 9.0.0
	 *
	 * @return array Associative array of value => label.
	 */
	private static function get_year_sync_options_with_labels() {
		$options = WC_Subscriptions_Synchroniser::get_year_sync_options();

		foreach ( $options as $value => &$label ) {
			if ( 0 !== $value ) {
				/* translators: %s: month name (e.g., "January") */
				$label = sprintf( __( '%s each year', 'woocommerce-subscriptions' ), $label );
			}
		}

		return $options;
	}

	private static function format_sync_options( $options_map ) {
		$formatted = array();
		foreach ( $options_map as $value => $label ) {
			$formatted[] = array(
				'value' => (string) $value,
				'label' => $label,
			);
		}
		usort(
			$formatted,
			function ( $a, $b ) {
				return (int) $a['value'] - (int) $b['value'];
			}
		);
		return $formatted;
	}

	/**
	 * Add APFS debug data in the system status.
	 *
	 * @since APFS 3.1.8
	 */
	public static function render_system_status_items() {

		$debug_data = array(
			'overrides' => self::get_template_overrides(),
		);

		include WCS_ATT_ABSPATH . 'admin/views/html-admin-page-status-report.php';
	}

	/**
	 * Determine which of our files have been overridden by the theme.
	 *
	 * @since  APFS 3.1.8
	 *
	 * @return array
	 */
	private static function get_template_overrides() {

		$template_path    = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/';
		$templates        = WC_Admin_Status::scan_template_files( $template_path );
		$wc_template_path = trailingslashit( WC()->template_path() );
		$theme_root       = trailingslashit( get_theme_root() );

		$overridden = array();

		foreach ( $templates as $file ) {

			$found_location  = false;
			$check_locations = array(
				get_stylesheet_directory() . "/{$file}",
				get_stylesheet_directory() . "/{$wc_template_path}{$file}",
				get_template_directory() . "/{$file}",
				get_template_directory() . "/{$wc_template_path}{$file}",
			);

			foreach ( $check_locations as $location ) {
				if ( is_readable( $location ) ) {
					$found_location = $location;
					break;
				}
			}

			if ( ! empty( $found_location ) ) {

				$core_version  = WC_Admin_Status::get_file_version( $template_path . $file );
				$found_version = WC_Admin_Status::get_file_version( $found_location );
				$is_outdated   = $core_version && ( empty( $found_version ) || version_compare( $found_version, $core_version, '<' ) );

				if ( false !== strpos( $found_location, '.php' ) ) {
					$overridden[] = array(
						'file'         => str_replace( $theme_root, '', $found_location ),
						'version'      => $found_version,
						'core_version' => $core_version,
						'is_outdated'  => $is_outdated,
					);
				}
			}
		}

		return $overridden;
	}
}
