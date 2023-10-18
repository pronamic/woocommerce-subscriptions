<?php
/**
 * WC Subscriptions Template Loader
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 * @author  Prospress
 */
class WCS_Template_Loader {

	/**
	 * Relocated templates from WooCommerce Subscriptions.
	 *
	 * @var array[] Array of file names and their directory found in templates/
	 */
	private static $relocated_templates = [
		'order-shipping-html.php'                 => 'admin/deprecated/',
		'order-tax-html.php'                      => 'admin/deprecated/',
		'html-admin-notice.php'                   => 'admin/',
		'html-failed-scheduled-action-notice.php' => 'admin/',
		'html-variation-price.php'                => 'admin/',
		'html-variation-synchronisation.php'      => 'admin/',
		'status.php'                              => 'admin/',
		'cart-recurring-shipping.php'             => 'cart/',
		'form-change-payment-method.php'          => 'checkout/',
		'recurring-coupon-totals.php'             => 'checkout/',
		'recurring-fee-totals.php'                => 'checkout/',
		'recurring-itemized-tax-totals.php'       => 'checkout/',
		'recurring-subscription-totals.php'       => 'checkout/',
		'recurring-subtotals.php'                 => 'checkout/',
		'recurring-tax-totals.php'                => 'checkout/',
		'recurring-totals.php'                    => 'checkout/',
		'subscription-receipt.php'                => 'checkout/',
		'admin-new-renewal-order.php'             => 'emails/',
		'admin-new-switch-order.php'              => 'emails/',
		'admin-payment-retry.php'                 => 'emails/',
		'cancelled-subscription.php'              => 'emails/',
		'customer-completed-renewal-order.php'    => 'emails/',
		'customer-completed-switch-order.php'     => 'emails/',
		'customer-on-hold-renewal-order.php'      => 'emails/',
		'customer-payment-retry.php'              => 'emails/',
		'customer-processing-renewal-order.php'   => 'emails/',
		'customer-renewal-invoice.php'            => 'emails/',
		'email-order-details.php'                 => 'emails/',
		'expired-subscription.php'                => 'emails/',
		'on-hold-subscription.php'                => 'emails/',
		'admin-new-renewal-order.php'             => 'emails/plain/',
		'admin-new-switch-order.php'              => 'emails/plain/',
		'admin-payment-retry.php'                 => 'emails/plain/',
		'cancelled-subscription.php'              => 'emails/plain/',
		'customer-completed-renewal-order.php'    => 'emails/plain/',
		'customer-completed-switch-order.php'     => 'emails/plain/',
		'customer-on-hold-renewal-order.php'      => 'emails/plain/',
		'customer-payment-retry.php'              => 'emails/plain/',
		'customer-processing-renewal-order.php'   => 'emails/plain/',
		'customer-renewal-invoice.php'            => 'emails/plain/',
		'email-order-details.php'                 => 'emails/plain/',
		'expired-subscription.php'                => 'emails/plain/',
		'on-hold-subscription.php'                => 'emails/plain/',
		'subscription-info.php'                   => 'emails/plain/',
		'subscription-info.php'                   => 'emails/',
		'html-modal.php'                          => '',
		'my-subscriptions.php'                    => 'myaccount/',
		'related-orders.php'                      => 'myaccount/',
		'related-subscriptions.php'               => 'myaccount/',
		'subscription-details.php'                => 'myaccount/',
		'subscription-totals-table.php'           => 'myaccount/',
		'subscription-totals.php'                 => 'myaccount/',
		'subscriptions.php'                       => 'myaccount/',
		'view-subscription.php'                   => 'myaccount/',
		'subscription.php'                        => 'single-product/add-to-cart/',
		'variable-subscription.php'               => 'single-product/add-to-cart/',
	];

	public static function init() {
		add_action( 'woocommerce_account_view-subscription_endpoint', array( __CLASS__, 'get_view_subscription_template' ) );
		add_action( 'woocommerce_subscription_details_table', array( __CLASS__, 'get_subscription_details_template' ) );
		add_action( 'woocommerce_subscription_totals_table', array( __CLASS__, 'get_subscription_totals_template' ) );
		add_action( 'woocommerce_subscription_totals_table', array( __CLASS__, 'get_order_downloads_template' ), 20 );
		add_action( 'woocommerce_subscription_totals', array( __CLASS__, 'get_subscription_totals_table_template' ), 10, 4 );
		add_action( 'woocommerce_subscriptions_recurring_totals_subtotals', array( __CLASS__, 'get_recurring_cart_subtotals' ) );
		add_action( 'woocommerce_subscriptions_recurring_totals_coupons', array( __CLASS__, 'get_recurring_cart_coupons' ) );
		add_action( 'woocommerce_subscriptions_recurring_totals_shipping', array( __CLASS__, 'get_recurring_cart_shipping' ) );
		add_action( 'woocommerce_subscriptions_recurring_totals_fees', array( __CLASS__, 'get_recurring_cart_fees' ) );
		add_action( 'woocommerce_subscriptions_recurring_totals_taxes', array( __CLASS__, 'get_recurring_cart_taxes' ) );
		add_action( 'woocommerce_subscriptions_recurring_subscription_totals', array( __CLASS__, 'get_recurring_subscription_totals' ) );
		add_action( 'woocommerce_subscription_add_to_cart', array( __CLASS__, 'get_subscription_add_to_cart' ), 30 );
		add_action( 'woocommerce_variable-subscription_add_to_cart', array( __CLASS__, 'get_variable_subscription_add_to_cart' ), 30 );
		add_action( 'wcopc_subscription_add_to_cart', array( __CLASS__, 'get_opc_subscription_add_to_cart' ) ); // One Page Checkout compatibility

		add_filter( 'wc_get_template', array( __CLASS__, 'handle_relocated_templates' ), 10, 5 );
	}

	/**
	 * Get the view subscription template.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.17
	 */
	public static function get_view_subscription_template( $subscription_id ) {
		$subscription = wcs_get_subscription( absint( $subscription_id ) );

		if ( ! $subscription || ! current_user_can( 'view_order', $subscription->get_id() ) ) {
			echo '<div class="woocommerce-error">' . esc_html__( 'Invalid Subscription.', 'woocommerce-subscriptions' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" class="wc-forward">' . esc_html__( 'My Account', 'woocommerce-subscriptions' ) . '</a>' . '</div>';
			return;
		}

		wc_get_template( 'myaccount/view-subscription.php', compact( 'subscription' ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Get the subscription details template, which is part of the view subscription page.
	 *
	 * @param WC_Subscription $subscription Subscription object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	public static function get_subscription_details_template( $subscription ) {
		wc_get_template( 'myaccount/subscription-details.php', array( 'subscription' => $subscription ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Get the subscription totals template, which is part of the view subscription page.
	 *
	 * @param WC_Subscription $subscription Subscription object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	public static function get_subscription_totals_template( $subscription ) {
		wc_get_template( 'myaccount/subscription-totals.php', array( 'subscription' => $subscription ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Get the order downloads template, which is part of the view subscription page.
	 *
	 * @param WC_Subscription $subscription Subscription object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function get_order_downloads_template( $subscription ) {
		if ( $subscription->has_downloadable_item() && $subscription->is_download_permitted() ) {
			wc_get_template(
				'order/order-downloads.php',
				array(
					'downloads'  => $subscription->get_downloadable_items(),
					'show_title' => true,
				)
			);
		}
	}

	/**
	 * Gets the subscription totals table.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 *
	 * @param WC_Subscription $subscription     The subscription to print the totals table for.
	 * @param bool  $include_item_removal_links Whether the remove line item links should be included.
	 * @param array $totals                     The subscription totals rows to be displayed.
	 * @param bool  $include_switch_links       Whether the line item switch links should be included.
	 */
	public static function get_subscription_totals_table_template( $subscription, $include_item_removal_links, $totals, $include_switch_links = true ) {

		// If the switch links shouldn't be printed, remove the callback which prints them.
		if ( false === $include_switch_links ) {
			$callback_detached = remove_action( 'woocommerce_order_item_meta_end', 'WC_Subscriptions_Switcher::print_switch_link' );
		}

		wc_get_template(
			'myaccount/subscription-totals-table.php',
			array(
				'subscription'       => $subscription,
				'allow_item_removal' => $include_item_removal_links,
				'totals'             => $totals,
			),
			'',
			WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' )
		);

		// Reattach the callback if it was successfully removed.
		if ( false === $include_switch_links && $callback_detached ) {
			add_action( 'woocommerce_order_item_meta_end', 'WC_Subscriptions_Switcher::print_switch_link', 10, 3 );
		}
	}

	/**
	 * Gets the subscription receipt template content.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.0
	 *
	 * @param WC_Subscription $subscription The subscription to display the receipt for.
	 */
	public static function get_subscription_receipt_template( $subscription ) {
		wc_get_template( 'checkout/subscription-receipt.php', array( 'subscription' => $subscription ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Gets the recurring totals subtotal rows content.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param array $recurring_carts The recurring carts.
	 */
	public static function get_recurring_cart_subtotals( $recurring_carts ) {
		$recurring_carts = wcs_apply_array_filter( 'woocommerce_subscriptions_display_recurring_subtotals', $recurring_carts, 'next_payment_date' );
		wc_get_template( 'checkout/recurring-subtotals.php', array( 'recurring_carts' => $recurring_carts ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Gets the recurring totals coupon rows content.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param array $recurring_carts The recurring carts.
	 */
	public static function get_recurring_cart_coupons( $recurring_carts ) {
		// Filter out all recurring carts without a next payment date.
		$recurring_carts = wcs_apply_array_filter( 'woocommerce_subscriptions_display_recurring_coupon_totals', $recurring_carts, 'next_payment_date' );
		wc_get_template( 'checkout/recurring-coupon-totals.php', array( 'recurring_carts' => $recurring_carts ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Gets the recurring totals shipping rows content.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public static function get_recurring_cart_shipping() {
		if ( WC()->cart->show_shipping() && WC_Subscriptions_Cart::cart_contains_subscriptions_needing_shipping() ) {
			wcs_cart_totals_shipping_html();
		}
	}

	/**
	 * Gets the recurring totals fee rows content.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param array $recurring_carts The recurring carts.
	 */
	public static function get_recurring_cart_fees( $recurring_carts ) {
		// Filter out all recurring carts without a next payment date.
		$recurring_carts = wcs_apply_array_filter( 'woocommerce_subscriptions_display_recurring_fee_totals', $recurring_carts, 'next_payment_date' );
		wc_get_template( 'checkout/recurring-fee-totals.php', array( 'recurring_carts' => $recurring_carts ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Gets the recurring totals tax rows content.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param array $recurring_carts The recurring carts.
	 */
	public static function get_recurring_cart_taxes( $recurring_carts ) {
		$tax_display_mode = wcs_is_woocommerce_pre( '4.4' ) ? WC()->cart->tax_display_cart : WC()->cart->get_tax_price_display_mode();

		if ( ! wc_tax_enabled() || 'excl' !== $tax_display_mode ) {
			return;
		}

		// Filter out all recurring carts without a next payment date.
		$recurring_carts = wcs_apply_array_filter( 'woocommerce_subscriptions_display_recurring_tax_totals', $recurring_carts, 'next_payment_date' );

		if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
			wc_get_template( 'checkout/recurring-itemized-tax-totals.php', array( 'recurring_carts' => $recurring_carts ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
		} else {
			wc_get_template( 'checkout/recurring-tax-totals.php', array( 'recurring_carts' => $recurring_carts ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
		}
	}

	/**
	 * Gets the recurring subscription total rows content.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param array $recurring_carts The recurring carts.
	 */
	public static function get_recurring_subscription_totals( $recurring_carts ) {
		// Filter out all recurring carts without a next payment date.
		$recurring_carts = wcs_apply_array_filter( 'woocommerce_subscriptions_display_recurring_subscription_totals', $recurring_carts, 'next_payment_date' );
		wc_get_template( 'checkout/recurring-subscription-totals.php', array( 'recurring_carts' => $recurring_carts ), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Loads the my-subscriptions.php template on the My Account page.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @param int $current_page The My Account Subscriptions page.
	 */
	public static function get_my_subscriptions( $current_page = 1 ) {
		$all_subscriptions = wcs_get_users_subscriptions();
		$current_page      = empty( $current_page ) ? 1 : absint( $current_page );
		$posts_per_page    = get_option( 'posts_per_page' );
		$max_num_pages     = ceil( count( $all_subscriptions ) / $posts_per_page );
		$subscriptions     = array_slice( $all_subscriptions, ( $current_page - 1 ) * $posts_per_page, $posts_per_page );

		wc_get_template(
			'myaccount/my-subscriptions.php',
			array(
				'subscriptions' => $subscriptions,
				'current_page'  => $current_page,
				'max_num_pages' => $max_num_pages,
				'paginate'      => true,
			),
			'',
			WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' )
		);
	}

	/**
	 * Gets the subscription add_to_cart template.
	 *
	 * Use the same cart template for subscription as that which is used for simple products. Reduce code duplication
	 * and is made possible by the friendly actions & filters found through WC.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function get_subscription_add_to_cart() {
		wc_get_template( 'single-product/add-to-cart/subscription.php', array(), '', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' ) );
	}

	/**
	 * Gets the variable subscription add_to_cart template.
	 *
	 * Use a very similar cart template as that of a variable product with added functionality.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function get_variable_subscription_add_to_cart() {
		global $product;

		// Enqueue variation scripts
		wp_enqueue_script( 'wc-add-to-cart-variation' );

		// Get Available variations?
		$get_variations = count( $product->get_children() ) <= apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );

		// Load the template
		wc_get_template(
			'single-product/add-to-cart/variable-subscription.php',
			array(
				'available_variations' => $get_variations ? $product->get_available_variations() : false,
				'attributes'           => $product->get_variation_attributes(),
				'selected_attributes'  => $product->get_default_attributes(),
			),
			'',
			WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory( 'templates/' )
		);
	}

	/**
	 * Gets OPC's simple add to cart template for simple subscription products (to ensure data attributes required by OPC are added).
	 *
	 * Variable subscription products will be handled automatically because they identify as "variable" in response to is_type() method calls,
	 * which OPC uses.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function get_opc_subscription_add_to_cart() {
		global $product;
		wc_get_template( 'checkout/add-to-cart/simple.php', array( 'product' => $product ), '', PP_One_Page_Checkout::$template_path );
	}

	/**
	 * Handles relocated subscription templates.
	 *
	 * Hooked onto 'wc_get_template'.
	 *
	 * @since 1.4.0
	 *
	 * @param string $template
	 * @param string $tempalte_name
	 * @param array  $args
	 * @param string $template_path
	 * @param
	 */
	public static function handle_relocated_templates( $template, $template_name, $args, $template_path, $default_path ) {
		// We only want to relocate subscription template files that can't be found.
		if ( file_exists( $template ) ) {
			return $template;
		}

		$subscriptions_core_path = WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory();
		$template_file           = basename( $template_name );

		if ( ! $default_path || strpos( $default_path, $subscriptions_core_path ) !== false || ! self::is_deprecated_default_path( $template_file, $default_path ) ) {
			return $template;
		}

		return $subscriptions_core_path . '/templates/' . self::$relocated_templates[ $template_file ] . $template_file;
	}

	/**
	 * Determine if the given template file and default path is sourcing the template
	 * from a outdated location.
	 *
	 * @since 1.4.0
	 *
	 * @param string $template_file Template file name.
	 * @param string $default_path  Default path passed to `wc_get_template()`.
	 *
	 * @return bool
	 */
	public static function is_deprecated_default_path( $template_file, $default_path ) {
		$is_default_path_wcs = false;

		if ( isset( self::$relocated_templates[ $template_file ] ) ) {
			if ( class_exists( 'WC_Subscriptions_Plugin' ) ) {
				// WCS 4+ is active.
				$wcs_templates_path  = WC_Subscriptions_Plugin::instance()->get_plugin_directory();
				$is_default_path_wcs = strpos( $default_path, $wcs_templates_path ) !== false;
			} elseif ( file_exists( dirname( $default_path ) . '/woocommerce-subscriptions.php' ) ) {
				// WCS is installed but not active.
				$is_default_path_wcs = true;
			} elseif ( preg_match( '/woocommerce-subscriptions(|[A-Za-z0-9\-\_ ]+)\/templates/', $default_path ) ) {
				$maybe_a_plugin_name = basename( dirname( $default_path ) );
				// Avoid the case where $default_path is referring to some other plugin like woocommerce-subscriptions-extension.
				if ( ! file_exists( dirname( $default_path ) . "/$maybe_a_plugin_name.php" ) ) {
					$is_default_path_wcs = true;
				}
			}
		}

		return $is_default_path_wcs;
	}
}
