<?php

use Automattic\WooCommerce_Subscriptions\Internal\Utilities\Request;

/**
 * A class to make it possible to switch between different subscriptions (i.e. upgrade/downgrade a subscription)
 *
 * @package WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Switcher
 * @category Class
 * @author Brent Shepherd
 * @since 1.4
 */
class WC_Subscriptions_Switcher {

	/**
	 * The last known switch total calculator instance which was calculated.
	 *
	 * @since 2.6.1
	 * @var WCS_Switch_Totals_Calculator
	 */
	protected static $switch_totals_calculator;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.4
	 */
	public static function init() {

		// Attach hooks which depend on WooCommerce constants
		add_action( 'woocommerce_loaded', array( __CLASS__, 'attach_dependant_hooks' ) );

		// Check if the current request is for switching a subscription and if so, start he switching process
		add_action( 'template_redirect', array( __CLASS__, 'subscription_switch_handler' ), 100 );

		// Pass in the filter switch to the group items
		add_filter( 'woocommerce_grouped_product_list_link', array( __CLASS__, 'add_switch_query_arg_grouped' ), 12 );
		add_filter( 'post_type_link', array( __CLASS__, 'add_switch_query_arg_post_link' ), 12, 2 );

		// Add the settings to control whether Switching is enabled and how it will behave
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ), 15 );

		// Render "wcs_switching_options" field
		add_action( 'woocommerce_admin_field_wcs_switching_options', __CLASS__ . '::switching_options_field_html' );

		// Add the "Switch" button to the View Subscription table
		add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'print_switch_link' ), 10, 3 );

		// Add hidden form inputs for AJAX add-to-cart compatibility during switches
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'add_switch_hidden_inputs' ) );

		// We need to create subscriptions on checkout and want to do it after almost all other extensions have added their products/items/fees
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_checkout' ), 50, 2 );

		// Same as above for WooCommerce Blocks.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'process_checkout' ), 50, 1 );

		// When creating an order, add meta if it's for switching a subscription
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'add_order_meta' ), 10, 2 );

		// Same as above for WooCommerce Blocks.
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'add_order_meta' ), 10, 1 );

		// Don't allow switching to the same product
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_switch_request' ), 10, 4 );

		// Record subscription switching in the cart
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'set_switch_details_in_cart' ), 10, 3 );

		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'trigger_switch_added_to_cart_hook' ), 15, 6 );

		// Retain coupons if required
		add_action( 'woocommerce_subscriptions_switch_added_to_cart', array( __CLASS__, 'retain_coupons' ), 15, 1 );

		// Make sure the 'switch_subscription' cart item data persists
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_from_session' ), 10, 3 );

		// Set totals for subscription switch orders (needs to be hooked just before WC_Subscriptions_Cart::calculate_subscription_totals())
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'calculate_prorated_totals' ), 99, 1 );

		// Don't display free trials when switching a subscription, because no free trials are provided
		add_filter( 'woocommerce_subscriptions_product_price_string_inclusions', array( __CLASS__, 'customise_product_string_inclusions' ), 12, 2 );

		// Don't carry switch meta data to renewal orders
		add_filter( 'wc_subscriptions_renewal_order_data', array( __CLASS__, 'remove_renewal_order_meta' ), 10 );

		// Don't carry switch meta data to renewal orders
		add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( __CLASS__, 'get_recurring_cart_key' ), 10, 2 );

		// Make sure the first renewal date takes into account any prorated length of time for upgrades/downgrades
		add_filter( 'wcs_recurring_cart_next_payment_date', array( __CLASS__, 'recurring_cart_next_payment_date' ), 100, 2 );

		// Make sure the new end date starts from the end of the time that has already paid for
		add_filter( 'wcs_recurring_cart_end_date', array( __CLASS__, 'recurring_cart_end_date' ), 100, 3 );

		// Make sure the switch process persists when having to choose product addons
		add_action( 'addons_add_to_cart_url', array( __CLASS__, 'addons_add_to_cart_url' ), 10 );

		// Make sure the switch process persists when having to choose product addons
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_order_itemmeta' ), 10 );

		// Add/remove the print switch link filters when printing HTML/plain subscription emails
		add_action( 'woocommerce_email_before_subscription_table', array( __CLASS__, 'remove_print_switch_link' ) );
		add_filter( 'woocommerce_email_order_items_table', array( __CLASS__, 'add_print_switch_link' ) );

		// Make sure sign-up fees paid on switch orders are accounted for in an items sign-up fee
		add_filter( 'woocommerce_subscription_items_sign_up_fee', array( __CLASS__, 'subscription_items_sign_up_fee' ), 10, 4 );

		// Display/indicate whether a cart switch item is a upgrade/downgrade/crossgrade
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'add_cart_item_switch_direction' ), 10, 3 );

		// Check if the order was to record a switch request and maybe call a "switch completed" action.
		add_action( 'woocommerce_subscriptions_switch_completed', array( __CLASS__, 'maybe_add_switched_callback' ), 10, 1 );

		// Revoke download permissions from old switch item
		add_action( 'woocommerce_subscriptions_switched_item', array( __CLASS__, 'remove_download_permissions_after_switch' ), 10, 3 );

		// Process subscription switch changes on completed switch orders status
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'process_subscription_switches' ), 10, 3 );

		// Check if we need to force payment on this switch, just after calculating the prorated totals in @see self::calculate_prorated_totals()
		add_filter( 'woocommerce_subscriptions_calculated_total', array( __CLASS__, 'set_force_payment_flag_in_cart' ), 10, 1 );

		// Require payment when switching from a $0 / period subscription to a non-zero subscription to process automatic payments
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 50, 2 );

		// Require payment when switching from a $0 / period subscription to a non-zero subscription to process automatic payments
		add_action( 'woocommerce_subscriptions_switch_completed', array( __CLASS__, 'maybe_set_payment_method_after_switch' ), 10, 1 );

		// Do not reduce product stock when the order item is simply to record a switch
		add_filter( 'woocommerce_order_item_quantity', array( __CLASS__, 'maybe_do_not_reduce_stock' ), 10, 3 );

		// Mock a free trial on the cart item to make sure the switch total doesn't include any recurring amount
		add_filter( 'woocommerce_before_calculate_totals', array( __CLASS__, 'maybe_set_free_trial' ), 100, 1 );
		add_action( 'woocommerce_subscription_cart_before_grouping', array( __CLASS__, 'maybe_unset_free_trial' ) );
		add_action( 'woocommerce_subscription_cart_after_grouping', array( __CLASS__, 'maybe_set_free_trial' ) );
		add_action( 'wcs_recurring_cart_start_date', array( __CLASS__, 'maybe_unset_free_trial' ), 0, 1 );
		add_action( 'wcs_recurring_cart_end_date', array( __CLASS__, 'maybe_set_free_trial' ), 100, 1 );
		add_filter( 'woocommerce_subscriptions_calculated_total', array( __CLASS__, 'maybe_unset_free_trial' ), 10000, 1 );
		add_action( 'woocommerce_cart_totals_before_shipping', array( __CLASS__, 'maybe_set_free_trial' ) );
		add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'maybe_unset_free_trial' ) );
		add_action( 'woocommerce_review_order_before_shipping', array( __CLASS__, 'maybe_set_free_trial' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'maybe_unset_free_trial' ) );

		// Grant download permissions after the switch is complete.
		add_action( 'woocommerce_grant_product_download_permissions', array( __CLASS__, 'delay_granting_download_permissions' ), 9, 1 );
		add_action( 'woocommerce_subscriptions_switch_completed', array( __CLASS__, 'grant_download_permissions' ), 9, 1 );
		add_action( 'woocommerce_subscription_checkout_switch_order_processed', array( __CLASS__, 'log_switches' ) );
		add_filter( 'wcs_admin_subscription_related_orders_to_display', array( __CLASS__, 'display_switches_in_related_order_metabox' ), 10, 3 );

		// Override the add to cart text when switch args are present.
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'display_switch_add_to_cart_text' ), 10, 1 );

		add_filter( 'woocommerce_subscriptions_calculated_total', [ __CLASS__, 'remove_handled_switch_recurring_carts' ], 100, 1 );
	}

	/**
	 * Attach WooCommerce version dependent hooks
	 *
	 * @since 2.2.0
	 */
	public static function attach_dependant_hooks() {

		if ( wcs_is_woocommerce_pre( '3.0' ) ) {

			// For order items created as part of a switch, keep a record of the prorated amounts
			add_action( 'woocommerce_add_order_item_meta', array( __CLASS__, 'add_order_item_meta' ), 10, 3 );

			// For subscription items created as part of a switch, keep a record of the relationship between the items
			add_action( 'woocommerce_add_subscription_item_meta', array( __CLASS__, 'set_subscription_item_meta' ), 50, 3 );

		} else {

			// For order items created as part of a switch, keep a record of the prorated amounts
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_line_item_meta' ), 10, 4 );
		}
	}

	/**
	 * Handles the subscription upgrade/downgrade process.
	 *
	 * @since 1.4
	 */
	public static function subscription_switch_handler() {
		global $post;

		$switch_subscription_id = Request::get_var( 'switch-subscription' );
		$item_id                = Request::get_var( 'item' );

		// If the current user doesn't own the subscription, remove the query arg from the URL
		if ( $switch_subscription_id && $item_id ) {

			$subscription = wcs_get_subscription( absint( $switch_subscription_id ) );
			$line_item    = $subscription ? wcs_get_order_item( absint( $item_id ), $subscription ) : false;
			$nonce        = Request::get_var( '_wcsnonce' );
			$nonce        = $nonce ? sanitize_text_field( wp_unslash( $nonce ) ) : false;

			// Visiting a switch link for someone elses subscription or if the switch link doesn't contain a valid nonce
			if ( ! is_object( $subscription ) || empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wcs_switch_request' ) || empty( $line_item ) || ! self::can_item_be_switched_by_user( $line_item, $subscription ) ) {

				Request::redirect( remove_query_arg( array( 'switch-subscription', 'auto-switch', 'item', '_wcsnonce' ) ) );
				return;

			} else {

				if ( Request::get_var( 'auto-switch' ) ) {
					$switch_message = __( 'You have a subscription to this product. Choosing a new subscription will replace your existing subscription.', 'woocommerce-subscriptions' );
				} else {
					$switch_message = __( 'Choose a new subscription.', 'woocommerce-subscriptions' );
				}

				wc_add_notice( $switch_message, 'notice' );

			}
		} elseif ( ( is_cart() || is_checkout() ) && ! is_order_received_page() && false !== ( $switch_items = self::cart_contains_switches( 'any' ) ) ) {

			$removed_item_count = 0;

			foreach ( $switch_items as $cart_item_key => $switch_item ) {

				$subscription  = wcs_get_subscription( $switch_item['subscription_id'] );
				$is_valid_item = is_object( $subscription );

				if ( $is_valid_item ) {
					if ( empty( $switch_item['item_id'] ) ) {

						$item = isset( WC()->cart->cart_contents[ $cart_item_key ] ) ? WC()->cart->cart_contents[ $cart_item_key ] : false;

						if ( empty( $item ) || ! self::can_item_be_added_by_user( $item, $subscription ) ) {
							$is_valid_item  = false;
						}
					} else {

						$item = wcs_get_order_item( $switch_item['item_id'], $subscription );

						if ( empty( $item ) || ! self::can_item_be_switched_by_user( $item, $subscription ) ) {
							$is_valid_item  = false;
						}
					}
				}

				if ( ! $is_valid_item ) {
					WC()->cart->remove_cart_item( $cart_item_key );
					$removed_item_count++;
				}
			}

			if ( $removed_item_count > 0 ) {
				wc_add_notice( _n( 'Your cart contained an invalid subscription switch request. It has been removed.', 'Your cart contained invalid subscription switch requests. They have been removed.', $removed_item_count, 'woocommerce-subscriptions' ), 'error' );

				Request::redirect( wc_get_cart_url() );
				return;
			}
		} elseif ( is_product() && $product = wc_get_product( $post ) ) { // Automatically initiate the switch process for limited variable subscriptions

			$limited_switchable_products = array();

			if ( $product->is_type( 'grouped' ) ) { // If we're on a grouped product's page, we need to check if this grouped product has children which are limited and may need to be switched

				$child_ids = $product->get_children();

				foreach ( $child_ids as $child_id ) {
					$product = wc_get_product( $child_id );

					if ( 'no' != wcs_get_product_limitation( $product ) && wcs_is_product_switchable_type( $product ) ) {
						$limited_switchable_products[] = $product;
					}
				}
			} elseif ( 'no' != wcs_get_product_limitation( $product ) && wcs_is_product_switchable_type( $product ) ) {
				// If we're on a limited variation or single product within a group which is switchable
				// we only need to look for if the customer is subscribed to this product
				$limited_switchable_products[] = $product;
			}

			// If we have limited switchable products, check if the customer is already subscribed and needs to be switched
			if ( ! empty( $limited_switchable_products ) ) {

				$subscriptions = wcs_get_users_subscriptions();

				foreach ( $subscriptions as $subscription ) {
					foreach ( $limited_switchable_products as $product ) {

						if ( ! $subscription->has_product( $product->get_id() ) ) {
							continue;
						}

						$limitation = wcs_get_product_limitation( $product );

						if ( 'any' == $limitation || $subscription->has_status( $limitation ) ) {

							$subscribed_notice = __( 'You have already subscribed to this product and it is limited to one per customer. You can not purchase the product again.', 'woocommerce-subscriptions' );

							// Don't initiate auto-switching when the subscription requires payment
							if ( $subscription->needs_payment() ) {

								$last_order = $subscription->get_last_order( 'all' );

								if ( $last_order->needs_payment() ) {
									// translators: 1$: is the "You have already subscribed to this product" notice, 2$-4$: opening/closing link tags, 3$: an order number
									$subscribed_notice = sprintf( __( '%1$s Complete payment on %2$sOrder %3$s%4$s to be able to change your subscription.', 'woocommerce-subscriptions' ), $subscribed_notice, sprintf( '<a href="%s">', esc_url( $last_order->get_checkout_payment_url() ) ), $last_order->get_order_number(), '</a>' );
								}

								wc_add_notice( $subscribed_notice, 'notice' );
								break;

							} else {
								$item_id = null;
								$item = null;

								// Get the matching item
								foreach ( $subscription->get_items() as $line_item_id => $line_item ) {
									if ( $line_item['product_id'] == $product->get_id() || $line_item['variation_id'] == $product->get_id() ) {
										$item_id = $line_item_id;
										$item    = $line_item;
										break;
									}
								}

								if ( apply_filters( 'wcs_initiate_auto_switch', self::can_item_be_switched_by_user( $item, $subscription ), $item, $subscription ) ) {
									Request::redirect( add_query_arg( 'auto-switch', 'true', self::get_switch_url( $item_id, $item, $subscription ) ) );
									return;
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * When switching between grouped products, the Switch Subscription will take people to the grouped product's page. From there if they
	 * click through to the individual products, they lose the switch.
	 *
	 * WooCommerce added a filter so we're able to modify the permalinks, passing through the switch parameter to the individual products'
	 * pages.
	 *
	 * @param string $permalink The permalink of the product belonging to that group
	 */
	public static function add_switch_query_arg_grouped( $permalink ) {

		if ( isset( $_GET['switch-subscription'] ) ) {
			$permalink = self::add_switch_query_args( absint( $_GET['switch-subscription'] ), absint( $_GET['item'] ), $permalink );
		}

		return $permalink;
	}

	/**
	 * Slightly more awkward implementation for WooCommerce versions that do not have the woocommerce_grouped_product_list_link filter.
	 *
	 * @param string  $permalink The permalink of the product belonging to the group
	 * @param WP_Post $post      The WP_Post object
	 *
	 * @return string modified string with the query arg present
	 */
	public static function add_switch_query_arg_post_link( $permalink, $post ) {
		if ( ! isset( $_GET['switch-subscription'] ) || ! is_main_query() || ! is_product() || 'product' !== $post->post_type ) {
			return $permalink;
		}

		$product = wc_get_product( $post );
		$type    = wcs_get_objects_property( $product, 'type' );

		switch ( $type ) {
			case 'variable-subscription':
			case 'subscription':
				return self::add_switch_query_args( absint( $_GET['switch-subscription'] ), absint( $_GET['item'] ), $permalink );

			case 'grouped':
				// Check to see if the group contains a subscription.
				$children = $product->get_children();
				foreach ( $children as $child ) {
					$child_product = wc_get_product( $child );
					if ( 'subscription' === wcs_get_objects_property( $child_product, 'type' ) ) {
						return self::add_switch_query_args( absint( $_GET['switch-subscription'] ), absint( $_GET['item'] ), $permalink );
					}
				}

				// break omitted intentionally to fall through to default.

			default:
				return $permalink;
		}
	}

	/**
	 * Add Switch settings to the Subscription's settings page.
	 *
	 * @since 1.4
	 */
	public static function add_settings( $settings ) {

		$switching_settings = array(
			array(
				'name' => __( 'Switching', 'woocommerce-subscriptions' ),
				'type' => 'title',
				// translators: placeholders are opening and closing link tags
				'desc' => sprintf( __( 'Allow subscribers to switch (upgrade or downgrade) between different subscriptions. %1$sLearn more%2$s.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( 'https://woocommerce.com/document/subscriptions/switching-guide/' ) . '">', '</a>' ),
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_switch_settings',
			),
			array(
				'type' => 'wcs_switching_options',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_allow_switching',
			),
			array(
				'name'     => __( 'Prorate Recurring Payment', 'woocommerce-subscriptions' ),
				'desc'     => __( 'When switching to a subscription with a different recurring payment or billing period, should the price paid for the existing billing period be prorated when switching to the new subscription?', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_apportion_recurring_price',
				'css'      => 'min-width:150px;',
				'default'  => 'no',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'no'              => _x( 'Never', 'when to allow a setting', 'woocommerce-subscriptions' ),
					'virtual-upgrade' => _x( 'For Upgrades of Virtual Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'yes-upgrade'     => _x( 'For Upgrades of All Subscription Products', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'virtual'         => _x( 'For Upgrades & Downgrades of Virtual Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'yes'             => _x( 'For Upgrades & Downgrades of All Subscription Products', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),
			array(
				'name'     => __( 'Prorate Sign up Fee', 'woocommerce-subscriptions' ),
				'desc'     => __( 'When switching to a subscription with a sign up fee, you can require the customer pay only the gap between the existing subscription\'s sign up fee and the new subscription\'s sign up fee (if any).', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee',
				'css'      => 'min-width:150px;',
				'default'  => 'no',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'no'   => _x( 'Never (do not charge a sign up fee)', 'when to prorate signup fee when switching', 'woocommerce-subscriptions' ),
					'full' => _x( 'Never (charge the full sign up fee)', 'when to prorate signup fee when switching', 'woocommerce-subscriptions' ),
					'yes'  => _x( 'Always', 'when to prorate signup fee when switching', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),
			array(
				'name'     => __( 'Prorate Subscription Length', 'woocommerce-subscriptions' ),
				'desc'     => __( 'When switching to a subscription with a length, you can take into account the payments already completed by the customer when determining how many payments the subscriber needs to make for the new subscription.', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_apportion_length',
				'css'      => 'min-width:150px;',
				'default'  => 'no',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'no'      => _x( 'Never', 'when to allow a setting', 'woocommerce-subscriptions' ),
					'virtual' => _x( 'For Virtual Subscription Products Only', 'when to prorate first payment / subscription length', 'woocommerce-subscriptions' ),
					'yes'     => _x( 'For All Subscription Products', 'when to prorate first payment / subscription length', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),
			array(
				'name'     => __( 'Switch Button Text', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Customise the text displayed on the button next to the subscription on the subscriber\'s account page. The default is "Switch Subscription", but you may wish to change this to "Upgrade" or "Change Subscription".', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_switch_button_text',
				'css'      => 'min-width:150px;',
				'default'  => __( 'Upgrade or Downgrade', 'woocommerce-subscriptions' ),
				'type'     => 'text',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => WC_Subscriptions_Admin::$option_prefix . '_switch_settings',
			),
		);

		// Insert the switch settings in after the synchronisation section otherwise add them to the end.
		if ( ! WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Synchroniser::$setting_id . '_title', $switching_settings, 'multiple-settings', 'sectionend' ) ) {
			$settings = array_merge( $settings, $switching_settings );
		}

		return $settings;
	}

	/**
	 * Render the wcs_switching_options setting field.
	 *
	 * @since 2.6.0
	 * @param array $data
	 */
	public static function switching_options_field_html( $data ) {

		// Calculate current checked options
		$allow_switching = get_option( $data['id'], 'no' );
		// Sanity check
		if ( ! in_array( $allow_switching, array( 'no', 'variable', 'grouped', 'variable_grouped' ) ) ) {
			$allow_switching = 'no';
		}

		$allow_switching_variable_checked = strpos( $allow_switching, 'variable' ) !== false;
		$allow_switching_grouped_checked  = strpos( $allow_switching, 'grouped' ) !== false;
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="wcs_switching_options">
					<?php esc_html_e( 'Allow Switching', 'woocommerce-subscriptions' ) ?>
				</label>
			</th>
			<td class="forminp forminp-wcs_switching_options">
				<div class="wcs_setting_switching_options" id="woocommerce_subscriptions_allow_switching">
					<label>
						<input <?php checked( $allow_switching_variable_checked ); ?> type="checkbox" name="<?php echo esc_attr( WC_Subscriptions_Admin::$option_prefix . '_allow_switching_variable' ) ?>"/>
						<?php echo esc_html_x( 'Between Subscription Variations', 'when to allow switching', 'woocommerce-subscriptions' ); ?>
					</label>
					<label>
						<input <?php checked( $allow_switching_grouped_checked ); ?> type="checkbox" name="<?php echo esc_attr( WC_Subscriptions_Admin::$option_prefix . '_allow_switching_grouped' ) ?>"/>
						<?php echo esc_html_x( 'Between Grouped Subscriptions', 'when to allow switching', 'woocommerce-subscriptions' ); ?>
					</label>
					<?php

					/**
					 * 'woocommerce_subscription_switching_options' filter.
					 *
					 * Used to add extra switching options.
					 *
					 * @since  2.6.0
					 * @param  array  $switching_options
					 * @return array
					 */
					$extra_switching_options = (array) apply_filters( 'woocommerce_subscriptions_allow_switching_options', array() );

					foreach ( $extra_switching_options as $option ) {

						if ( empty( $option['id'] ) || empty( $option['label'] ) ) {
							continue;
						}

						$label = $option['label'];
						$name  = WC_Subscriptions_Admin::$option_prefix . '_allow_switching_' . $option['id'];
						$value = get_option( $name, 'no' );

						echo '<label>';
						echo sprintf( '<input%s type="checkbox" name="%s" value="1"/> %s', checked( $value, 'yes', false ), esc_attr( $name ), esc_html( $label ) );
						echo isset( $option['desc_tip'] ) ? wc_help_tip( $option['desc_tip'], true ) : '';
						echo '</label>';
					}
					?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Adds an Upgrade/Downgrade link on the View Subscription page for each item that can be switched.
	 *
	 * @param int $item_id The order item ID of a subscription line item
	 * @param array $item An order line item
	 * @param object $subscription A WC_Subscription object
	 * @since 1.4
	 */
	public static function print_switch_link( $item_id, $item, $subscription ) {

		if ( wcs_is_order( $subscription ) || 'shop_subscription' !== $subscription->get_type() || ! self::can_item_be_switched_by_user( $item, $subscription ) ) {
			return;
		}

		$switch_url     = esc_url( self::get_switch_url( $item_id, $item, $subscription ) );
		$switch_text    = apply_filters( 'woocommerce_subscriptions_switch_link_text', get_option( WC_Subscriptions_Admin::$option_prefix . '_switch_button_text', __( 'Upgrade or Downgrade', 'woocommerce-subscriptions' ) ), $item_id, $item, $subscription );
		$switch_classes = apply_filters( 'woocommerce_subscriptions_switch_link_classes', array( 'wcs-switch-link', 'button', wc_wp_theme_get_element_class_name( 'button' ) ), $item_id, $item, $subscription );

		$switch_link    = sprintf( '<a href="%s" class="%s">%s</a>', $switch_url, implode( ' ', (array) $switch_classes ), $switch_text );

		echo wp_kses( apply_filters( 'woocommerce_subscriptions_switch_link', $switch_link, $item_id, $item, $subscription ), array( 'a' => array( 'href' => array(), 'title' => array(), 'class' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Add hidden form inputs for subscription switch parameters.
	 *
	 * When a customer is switching subscriptions, the switch parameters are passed via URL query arguments.
	 * This method outputs them as hidden form inputs so they're included when AJAX add-to-cart plugins
	 * serialize and submit the form via POST.
	 *
	 * @since 8.3.0
	 */
	public static function add_switch_hidden_inputs() {
		$switch_subscription_id = Request::get_var( 'switch-subscription' );
		$item_id                = Request::get_var( 'item' );
		$nonce                  = Request::get_var( '_wcsnonce' );

		// Only output if we're in a switch context with a valid nonce
		if ( ! $switch_subscription_id || ! $item_id || ! $nonce ) {
			return;
		}

		echo '<input type="hidden" name="switch-subscription" value="' . esc_attr( $switch_subscription_id ) . '" />';
		echo '<input type="hidden" name="item" value="' . esc_attr( $item_id ) . '" />';
		echo '<input type="hidden" name="_wcsnonce" value="' . esc_attr( $nonce ) . '" />';
	}

	/**
	 * The link for switching a subscription - the product page for variable subscriptions, or grouped product page for grouped subscriptions.
	 *
	 * @param WC_Subscription $subscription An instance of WC_Subscription
	 * @param array $item An order item on the subscription
	 * @since 2.0
	 */
	public static function get_switch_url( $item_id, $item, $subscription ) {

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		/** @var WC_Product_Variable */
		$product               = wc_get_product( $item['product_id'] );
		$parent_products       = WC_Subscriptions_Product::get_visible_grouped_parent_product_ids( $product );
		$additional_query_args = array();

		// Grouped product
		if ( ! empty( $parent_products ) ) {
			$switch_url = get_permalink( reset( $parent_products ) );
		} else {
			$switch_url = get_permalink( $product->get_id() );

			if ( ! empty( $_GET ) && is_product() ) {
				$product_variations = array();

				// Attributes in GET args are prefixed with attribute_ so to make sure we compare them correctly, apply the same prefix.
				foreach ( $product->get_variation_attributes() as $attribute => $value ) {
					$product_variations[ wcs_maybe_prefix_key( strtolower( $attribute ), 'attribute_' ) ] = $value;
				}

				$additional_query_args = array_intersect_key( $_GET, $product_variations );
			}
		}

		$switch_url = self::add_switch_query_args( $subscription->get_id(), $item_id, $switch_url, $additional_query_args );

		return apply_filters( 'woocommerce_subscriptions_switch_url', $switch_url, $item_id, $item, $subscription );
	}

	/**
	 * Add the switch parameters to a URL for a given subscription and item.
	 *
	 * @param int $subscription_id A subscription's post ID
	 * @param int $item_id The order item ID of a subscription line item
	 * @param string $permalink The permalink of the product
	 * @param array $additional_query_args (optional) Additional query args to add to the switch URL
	 * @since 2.0
	 */
	protected static function add_switch_query_args( $subscription_id, $item_id, $permalink, $additional_query_args = array() ) {

		// manually add a nonce because we can't use wp_nonce_url() (it would escape the URL)
		$query_args = array_merge(
			$additional_query_args,
			array(
				'switch-subscription' => absint( $subscription_id ),
				'item'                => absint( $item_id ),
				'_wcsnonce'           => wp_create_nonce( 'wcs_switch_request' ),
			)
		);

		$permalink = add_query_arg( $query_args, $permalink );

		return apply_filters( 'woocommerce_subscriptions_add_switch_query_args', $permalink, $subscription_id, $item_id ); // nosemgrep: audit.php.wp.security.xss.query-arg -- False positive. $permalink is escaped in the template and escaping URLs should be done at the point of output or usage.
	}

	/**
	 * Check if a given cart item can be added to a subscription, or if a given subscription line item can be switched.
	 *
	 * For an item to be switchable, switching must be enabled, and the item must be for a variable subscription or
	 * part of a grouped product (at the time the check is made, not at the time the subscription was purchased).
	 *
	 * The subscription must also be active and use manual renewals or use a payment method which supports cancellation.
	 *
	 * @since 2.6.0
	 *
	 * @param string $action The action to perform ("add" or "switch").
	 * @param array|WC_Order_Item_Product $item An order item on the subscription to switch, or cart item to add.
	 * @param WC_Subscription $subscription An instance of WC_Subscription
	 */
	protected static function is_action_allowed( $action, $item, $subscription = null ) {

		if ( ! in_array( $action, array( 'add', 'switch' ) ) ) {
			return false;
		}

		if ( 'switch' === $action && false === ( $item instanceof WC_Order_Item_Product ) ) {
			return false;
		}

		$product_id = wcs_get_canonical_product_id( $item );

		if ( 'switch' === $action ) {
			if ( 'line_item' == $item['type'] && wcs_is_product_switchable_type( $product_id ) ) {
				$is_product_switchable = true;
			} else {
				$is_product_switchable = false;
			}
		} else {
			$is_product_switchable = true;
		}

		if ( $subscription->has_status( 'active' ) && 0 !== $subscription->get_date( 'last_order_date_created' ) ) {
			$is_subscription_switchable = true;
		} else {
			$is_subscription_switchable = false;
		}

		if ( $subscription->payment_method_supports( 'subscription_amount_changes' ) && $subscription->payment_method_supports( 'subscription_date_changes' ) ) {
			$can_subscription_be_updated = true;
		} else {
			$can_subscription_be_updated = false;
		}

		if ( $is_product_switchable && $is_subscription_switchable && $can_subscription_be_updated ) {
			$is_action_allowed = true;
		} else {
			$is_action_allowed = false;
		}

		return $is_action_allowed;
	}

	/**
	 * Check if a cart item can be added to a subscription.
	 *
	 * The subscription must be active and use manual renewals or use a payment method which supports cancellation.
	 *
	 * @since 2.6.0
	 *
	 * @param array $item A cart to add to a subscription.
	 * @param WC_Subscription $subscription An instance of WC_Subscription
	 */
	public static function can_item_be_added( $item, $subscription = null ) {
		return apply_filters( 'woocommerce_subscriptions_can_item_be_added', self::is_action_allowed( 'add', $item, $subscription ), $item, $subscription );
	}

	/**
	 * Check if a given item on a subscription can be switched.
	 *
	 * For an item to be switchable, switching must be enabled, and the item must be for a variable subscription or
	 * part of a grouped product (at the time the check is made, not at the time the subscription was purchased)
	 *
	 * The subscription must also be active and use manual renewals or use a payment method which supports cancellation.
	 *
	 * @param WC_Order_Item_Product $item An order item on the subscription to switch, or cart item to add.
	 * @param WC_Subscription $subscription An instance of WC_Subscription
	 * @since 2.0
	 */
	public static function can_item_be_switched( $item, $subscription = null ) {
		return apply_filters( 'woocommerce_subscriptions_can_item_be_switched', self::is_action_allowed( 'switch', $item, $subscription ), $item, $subscription );
	}

	/**
	 * Checks if a user can perform a cart item "add" or order item "switch" action, given a subscription.
	 *
	 * @since 2.6.0
	 *
	 * @param string $action An action to perform with the item ('add' or 'switch').
	 * @param WC_Order_Item_Product $item An order item to switch, or cart item to add.
	 * @param WC_Subscription $subscription An instance of WC_Subscription.
	 * @param int $user_id (optional) The ID of a user. Defaults to currently logged in user.
	 */
	protected static function can_user_perform_action( $action, $item, $subscription, $user_id = 0 ) {

		if ( ! in_array( $action, array( 'add', 'switch' ) ) ) {
			return false;
		}

		if ( 'switch' === $action && false === ( $item instanceof WC_Order_Item_Product ) ) {
			return false;
		}

		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		$can_user_perform_action = false;

		if ( user_can( $user_id, 'switch_shop_subscription', $subscription->get_id() ) ) {
			if ( 'switch' === $action ) {
				$can_user_perform_action = self::can_item_be_switched( $item, $subscription );
			} elseif ( 'add' === $action ) {
				$can_user_perform_action = self::can_item_be_added( $item, $subscription );
			}
		}

		return $can_user_perform_action;
	}

	/**
	 * Check if a given item can be added to a subscription by a given user.
	 *
	 * @since 2.6.0
	 *
	 * @param array $item A cart item to add to a subscription.
	 * @param WC_Subscription $subscription An instance of WC_Subscription.
	 * @param int $user_id (optional) The ID of a user. Defaults to currently logged in user.
	 */
	public static function can_item_be_added_by_user( $item, $subscription, $user_id = 0 ) {
		return apply_filters( 'woocommerce_subscriptions_can_item_be_added_by_user', self::can_user_perform_action( 'add', $item, $subscription, $user_id ), $item, $subscription );
	}

	/**
	 * Check if a given item on a subscription can be switched by a given user.
	 *
	 * @param WC_Order_Item_Product $item An order item to switch.
	 * @param WC_Subscription $subscription An instance of WC_Subscription.
	 * @param int $user_id (optional) The ID of a user. Defaults to currently logged in user.
	 * @since 2.0
	 */
	public static function can_item_be_switched_by_user( $item, $subscription, $user_id = 0 ) {
		return apply_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user', self::can_user_perform_action( 'switch', $item, $subscription, $user_id ), $item, $subscription );
	}

	/**
	 * If the order being generated is for switching a subscription, keep a record of some of the switch
	 * routines meta against the order.
	 *
	 * @param int|\WC_Order $order_id The ID of a WC_Order object
	 * @param array         $posted The data posted on checkout
	 * @since 1.4
	 */
	public static function add_order_meta( $order_id, $posted = array() ) {

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// delete all the existing subscription switch links before adding new ones
		WCS_Related_Order_Store::instance()->delete_relations( $order, 'switch' );

		$switches = self::cart_contains_switches( 'any' );

		if ( false !== $switches ) {

			foreach ( $switches as $switch_details ) {
				WCS_Related_Order_Store::instance()->add_relation( $order, wcs_get_subscription( $switch_details['subscription_id'] ), 'switch' );
			}
		}
	}

	/**
	 * To prorate sign-up fee and recurring amounts correctly when the customer switches a subscription multiple times, keep a record of the
	 * amount for each on the order item.
	 *
	 * @param int $order_item_id The ID of a WC_Order_Item object.
	 * @param array $cart_item The cart item's data.
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @since 2.0
	 */
	public static function add_order_item_meta( $order_item_id, $cart_item, $cart_item_key ) {

		if ( false === wcs_is_woocommerce_pre( '3.0' ) ) {
			_deprecated_function( __METHOD__, '2.2.0 and WooCommerce 3.0.0', __CLASS__ . '::add_order_line_item_meta( $order_item, $cart_item_key, $cart_item )' );
		}

		if ( isset( $cart_item['subscription_switch'] ) ) {
			if ( $switches = self::cart_contains_switches() ) {
				foreach ( $switches as $switch_item_key => $switch_details ) {
					if ( $cart_item_key == $switch_item_key ) {
						wc_add_order_item_meta( $order_item_id, '_switched_subscription_sign_up_fee_prorated', wcs_get_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee_prorated', 'single', 0 ), true );
						wc_add_order_item_meta( $order_item_id, '_switched_subscription_price_prorated', wcs_get_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_price_prorated', 'single', 0 ), true );
					}
				}
			}

			// Store the order line item id so it can be retrieved when we're processing the switch on checkout
			foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

				// If this cart item belongs to this recurring cart
				if ( in_array( $cart_item_key, array_keys( $recurring_cart->cart_contents ) ) ) {
					WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ]['subscription_switch']['order_line_item_id'] = $order_item_id;
				}
			}
		}
	}

	/**
	 * Store switch related data on the line item on the subscription and switch order.
	 *
	 * For subscriptions: items on a new billing schedule are left to be added as new subscriptions, but we also want
	 * to keep a record of them being a switch, so we do that here.
	 *
	 * For orders: to prorate sign-up fee and recurring amounts correctly when the customer switches a subscription
	 * multiple times, keep a record of the amount for each on the order item.
	 *
	 * Attached to WC 3.0+ hooks and uses WC 3.0 methods.
	 *
	 * @param WC_Order_Item_Product $order_item
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @param array $cart_item The cart item's data.
	 * @param WC_Order $order The order or subscription object to which the line item relates
	 * @since 2.2.0
	 */
	public static function add_line_item_meta( $order_item, $cart_item_key, $cart_item, $order ) {
		if ( isset( $cart_item['subscription_switch'] ) ) {
			$switches = self::cart_contains_switches( 'any' );

			if ( isset( $switches[ $cart_item_key ] ) ) {
				$switch_details = $switches[ $cart_item_key ];

				if ( wcs_is_subscription( $order ) ) {
					if ( ! empty( $switch_details['item_id'] ) ) {
						$order_item->update_meta_data( '_switched_subscription_item_id', $switch_details['item_id'] );
					}
				} else {
					$sign_up_fee_prorated = WC()->cart->cart_contents[ $cart_item_key ]['data']->get_meta( '_subscription_sign_up_fee_prorated', true );
					$price_prorated       = WC()->cart->cart_contents[ $cart_item_key ]['data']->get_meta( '_subscription_price_prorated', true );
					$order_item->add_meta_data( '_switched_subscription_sign_up_fee_prorated', empty( $sign_up_fee_prorated ) ? 0 : $sign_up_fee_prorated );
					$order_item->add_meta_data( '_switched_subscription_price_prorated', empty( $price_prorated ) ? 0 : $price_prorated );
				}
			}
		}
	}

	/**
	 * Subscription items on a new billing schedule are left to be added as new subscriptions, but we also
	 * want to keep a record of them being a switch, so we do that here.
	 *
	 * @param int $item_id The ID of a WC_Order_Item object.
	 * @param array $cart_item The cart item's data.
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @since 2.0
	 */
	public static function set_subscription_item_meta( $item_id, $cart_item, $cart_item_key ) {

		if ( ! wcs_is_woocommerce_pre( '3.0' ) ) {
			_deprecated_function( __METHOD__, '2.2.0', __CLASS__ . '::add_subscription_line_item_meta( $order_item, $cart_item_key, $cart_item )' );
		}

		if ( isset( $cart_item['subscription_switch'] ) ) {
			if ( $switches = self::cart_contains_switches() ) {
				foreach ( $switches as $switch_item_key => $switch_details ) {
					if ( $cart_item_key == $switch_item_key ) {
						wc_add_order_item_meta( $item_id, '_switched_subscription_item_id', $switch_details['item_id'], true );
						wc_add_order_item_meta( $switch_details['item_id'], '_switched_subscription_new_item_id', $item_id, true );
					}
				}
			}
		}
	}

	/**
	 * Handle any subscription switch items on checkout (and before WC_Subscriptions_Checkout::process_checkout())
	 *
	 * If the item is on the same billing schedule as the old subscription (and the next payment date is the same) or the
	 * item is the only item on the subscription, the subscription item will be updated (and a note left on the order).
	 * If the item is on a new billing schedule and there are other items on the existing subscription, the old item will
	 * be removed and the new item will be added to a new subscription by @see WC_Subscriptions_Checkout::process_checkout()
	 *
	 * @param int|\WC_Order $order_id The post_id of a shop_order post/WC_Order
	 *  object
	 * @param array         $posted_data The data posted on checkout
	 * @since 2.0
	 */
	public static function process_checkout( $order_id, $posted_data = array() ) {
		if ( ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return;
		}

		$order             = wc_get_order( $order_id );
		$switch_order_data = array();

		try {
			foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
				$subscription = false;

				// Find the subscription for this recurring cart switch.
				foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {
					// A switch recurring cart shouldn't contain any cart items that are not switched.
					if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
						continue 2;
					}

					// All cart items in a switch recurring cart should have the same subscription ID.
					$subscription = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );
					break;
				}

				if ( ! $subscription ) {
					continue;
				}

				/**
				 * One-time calculations
				 *
				 * Coupons, fees and shipping are calculated a single time for each recurring cart.
				 */

				// If there are coupons in the cart, mark them for pending addition
				$new_coupons = array();

				foreach ( $recurring_cart->get_coupons() as $coupon_code => $coupon ) {
					$coupon_item = new WC_Subscription_Item_Coupon_Pending_Switch( $coupon_code );
					$coupon_item->set_props(
						array(
							'code'         => $coupon_code,
							'discount'     => $recurring_cart->get_coupon_discount_amount( $coupon_code ),
							'discount_tax' => $recurring_cart->get_coupon_discount_tax_amount( $coupon_code ),
						)
					);

					$coupon_data = $coupon->get_data();

					// Avoid storing used_by - it's not needed and can get large.
					unset( $coupon_data['used_by'] );

					$coupon_item->add_meta_data( 'coupon_data', $coupon_data );
					$coupon_item->save();

					do_action( 'woocommerce_checkout_create_order_coupon_item', $coupon_item, $coupon_code, $coupon, $subscription );

					$subscription->add_item( $coupon_item );
					$new_coupons[] = $coupon_item->get_id();
				}

				$subscription->save();
				$switch_order_data[ $subscription->get_id() ]['coupons'] = $new_coupons;

				// If there are fees in the cart, mark them for pending addition
				$new_fee_items = array();
				foreach ( $recurring_cart->get_fees() as $fee_key => $fee ) {
					$fee_item = new WC_Subscription_Item_Fee_Pending_Switch();
					$fee_item->set_props(
						array(
							'name'       => $fee->name,
							'tax_status' => $fee->taxable,
							'amount'     => $fee->amount,
							'total'      => $fee->total,
							'tax'        => $fee->tax,
							'tax_class'  => $fee->tax_class,
							'tax_data'   => $fee->tax_data,
						)
					);

					$fee_item->save();

					do_action( 'woocommerce_checkout_create_order_fee_item', $fee_item, $fee_key, $fee, $subscription );

					$subscription->add_item( $fee_item );
					$new_fee_items[] = $fee_item->get_id();
				}

				$subscription->save();
				$switch_order_data[ $subscription->get_id() ]['fee_items'] = $new_fee_items;

				if ( ! isset( $switch_order_data[ $subscription->get_id() ]['shipping_line_items'] ) ) {
					// Add the shipping
					// Keep a record of the current shipping line items so we can flip any new shipping items to a _pending_switch shipping item.
					$current_shipping_line_items = array_keys( $subscription->get_shipping_methods() );
					$new_shipping_line_items     = array();

					// Keep a record of the subscription shipping total. Adding shipping methods will cause a new shipping total to be set, we'll need to set it back after.
					$subscription_shipping_total = $subscription->get_total_shipping();

					WC_Subscriptions_Checkout::add_shipping( $subscription, $recurring_cart );

					// We must save the subscription, we need the Shipping method saved
					// otherwise the ID is bogus (new:1) and we need it.
					$subscription->save();

					// Set all new shipping methods to shipping_pending_switch line items
					foreach ( $subscription->get_shipping_methods() as $shipping_line_item_id => $shipping_meta ) {
						if ( ! in_array( $shipping_line_item_id, $current_shipping_line_items ) ) {
							wcs_update_order_item_type( $shipping_line_item_id, 'shipping_pending_switch', $subscription->get_id() );
							$new_shipping_line_items[] = $shipping_line_item_id;
						}
					}

					$subscription->set_shipping_total( $subscription_shipping_total );
					$switch_order_data[ $subscription->get_id() ]['shipping_line_items'] = $new_shipping_line_items;
				}

				// Loop through cart items to add them to the switched subscription.
				foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {
					// If we haven't calculated a first payment date, fall back to the recurring cart's next payment date.
					if ( 0 == $cart_item['subscription_switch']['first_payment_timestamp'] ) {
						$cart_item['subscription_switch']['first_payment_timestamp'] = wcs_date_to_time( $recurring_cart->next_payment_date );
					}

					$is_different_billing_schedule = self::has_different_billing_schedule( $cart_item, $subscription );
					$is_different_payment_date     = self::has_different_payment_date( $cart_item, $subscription );
					$is_different_length           = self::has_different_length( $recurring_cart, $subscription );
					$is_single_item_subscription   = self::is_single_item_subscription( $subscription );

					$switched_item_data = array();

					if ( ! empty( $cart_item['subscription_switch']['item_id'] ) ) {
						$existing_item                          = wcs_get_order_item( $cart_item['subscription_switch']['item_id'], $subscription );
						$switch_item                            = new WCS_Switch_Cart_Item( $cart_item, $subscription, $existing_item );
						$is_switch_with_matching_trials         = $switch_item->is_switch_during_trial() && $switch_item->trial_periods_match();
						$switched_item_data['remove_line_item'] = $cart_item['subscription_switch' ]['item_id'];
						$switched_item_data['switch_direction'] = $switch_item->get_switch_type();
					}

					// An existing subscription can be updated if it's a single item subscription or switches already calculated have left it with just one item.
					$can_update_existing_subscription = $is_single_item_subscription || ! empty( $existing_item ) &&  self::is_last_remaining_item_after_previous_switches( $subscription, $existing_item, $switch_order_data );

					// If the item is on the same schedule, we can just add it to the new subscription and remove the old item.
					if ( $can_update_existing_subscription || ( false === $is_different_billing_schedule && false === $is_different_payment_date && false === $is_different_length ) ) {
						// Add the new item
						$item                       = new WC_Order_Item_Pending_Switch;
						$item->legacy_values        = $cart_item; // @deprecated For legacy actions.
						$item->legacy_cart_item_key = $cart_item_key; // @deprecated For legacy actions.
						$item->set_props( array(
							'quantity'     => $cart_item['quantity'],
							'variation'    => $cart_item['variation'],
							'subtotal'     => $cart_item['line_subtotal'],
							'total'        => $cart_item['line_total'],
							'subtotal_tax' => $cart_item['line_subtotal_tax'],
							'total_tax'    => $cart_item['line_tax'],
							'taxes'        => $cart_item['line_tax_data'],
						) );

						if ( ! empty( $cart_item[ 'data' ] ) ) {
							$product = $cart_item[ 'data' ];
							$item->set_props( array(
								'name'         => $product->get_name(),
								'tax_class'    => $product->get_tax_class(),
								'product_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
								'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
							) );
						}

						if ( WC_Subscriptions_Product::get_trial_length( wcs_get_canonical_product_id( $cart_item ) ) > 0 ) {
							$item->add_meta_data( '_has_trial', 'true' );
						}

						do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $cart_item, $subscription );

						$subscription->add_item( $item );

						// The subscription is not saved automatically, we need to call 'save' because we added an item
						$subscription->save();
						$item_id = $item->get_id();

						$switched_item_data['add_line_item'] = $item_id;

						// Remove the item from the cart so that WC_Subscriptions_Checkout doesn't add it to a subscription
						if ( 1 == count( WC()->cart->recurring_carts[ $recurring_cart_key ]->get_cart() ) ) {
							// If this is the only item in the cart, clear out recurring carts so WC_Subscriptions_Checkout doesn't try to create an empty subscription
							unset( WC()->cart->recurring_carts[ $recurring_cart_key ] );
						} else {
							unset( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ] );
						}
					}

					// Obtain the new order item id from the cart item switch data.
					if ( isset( $cart_item['subscription_switch']['order_line_item_id'] ) ) {
						$new_order_item_id = $cart_item['subscription_switch']['order_line_item_id'];
					} else {
						$new_order_item_id = wc_get_order_item_meta( $cart_item['subscription_switch']['item_id'], '_switched_subscription_new_item_id', true );
					}

					// Store the switching data for this item.
					$switch_order_data[ $subscription->get_id() ]['switches'][ $new_order_item_id ] = $switched_item_data;

					// If the old subscription has just one item, we can safely update its billing schedule
					if ( $can_update_existing_subscription ) {

						if ( $is_different_billing_schedule ) {
							$switch_order_data[ $subscription->get_id() ]['billing_schedule']['_billing_period']   = WC_Subscriptions_Product::get_period( $cart_item['data'] );
							$switch_order_data[ $subscription->get_id() ]['billing_schedule']['_billing_interval'] = absint( WC_Subscriptions_Product::get_interval( $cart_item['data'] ) );
						}

						$updated_dates = array();

						if ( '1' == WC_Subscriptions_Product::get_length( $cart_item['data'] ) || ( 0 != $recurring_cart->end_date && gmdate( 'Y-m-d H:i:s', $cart_item['subscription_switch']['first_payment_timestamp'] ) >= $recurring_cart->end_date ) ) {
							// Delete the next payment date.
							$updated_dates['next_payment'] = 0;
						} else if ( $is_different_payment_date ) {
							$updated_dates['next_payment'] = gmdate( 'Y-m-d H:i:s', $cart_item['subscription_switch']['first_payment_timestamp'] );
						}

						if ( $is_different_length ) {
							$updated_dates['end'] = $recurring_cart->end_date;
						}

						// If the switch should maintain the current trial or delete it.
						if ( isset( $is_switch_with_matching_trials ) && $is_switch_with_matching_trials ) {
							$updated_dates['trial_end'] = $subscription->get_date( 'trial_end' );
						} else {
							$updated_dates['trial_end'] = 0;
						}

						$subscription->validate_date_updates( $updated_dates );
						$switch_order_data[ $subscription->get_id() ]['dates']['update'] = $updated_dates;
					}
				}
			}

			foreach ( $switch_order_data as $subscription_id => $switch_data ) {
				// Cancel all the switch orders linked to the switched subscription(s) which haven't been completed yet - excluding this one.
				$switch_orders = wcs_get_switch_orders_for_subscription( $subscription_id );

				foreach ( $switch_orders as $switch_order_id => $switch_order ) {
					if ( $order->get_id() !== $switch_order_id && in_array( $switch_order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed', 'on-hold' ), $switch_order ) ) ) {
						// translators: %s: order number.
						$switch_order->update_status( 'cancelled', sprintf( __( 'Switch order cancelled due to a new switch order being created #%s.', 'woocommerce-subscriptions' ), $order->get_order_number() ) );
					}
				}
			}

			if ( ! empty( $switch_order_data ) ) {
				wcs_set_objects_property( $order, 'subscription_switch_data', $switch_order_data );
				do_action( 'woocommerce_subscription_checkout_switch_order_processed', $order, $switch_order_data );
			}
		} catch ( Exception $e ) {
			// There was an error updating the subscription, delete pending switch order.
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
			throw $e;
		}
	}

	/**
	 * Updates address on the subscription if one of them is changed.
	 *
	 * @param WC_Order        $order The new order
	 * @param WC_Subscription $subscription The original subscription
	 */
	public static function maybe_update_subscription_address( $order, $subscription ) {
		$billing_address_changes  = array_diff_assoc( $order->get_address( 'billing' ), $subscription->get_address( 'billing' ) );
		$shipping_address_changes = array_diff_assoc( $order->get_address( 'shipping' ), $subscription->get_address( 'shipping' ) );

		if ( wcs_is_woocommerce_pre( '7.1' ) ) {
			$subscription->set_address( $billing_address_changes, 'billing' );
			$subscription->set_address( $shipping_address_changes, 'shipping' );
		} else {
			$subscription->set_billing_address( $billing_address_changes );
			$subscription->set_shipping_address( $shipping_address_changes );

			$subscription->save();
		}
	}

	/**
	 * Check if the cart includes any items which are to switch an existing subscription's contents.
	 *
	 * @since 2.0
	 * @param string $item_action Types of items to include ("any", "switch", or "add").
	 * @return bool|array Returns cart items that modify subscription contents, or false if no such items exist.
	 */
	public static function cart_contains_switches( $item_action = 'switch' ) {
		$subscription_switches = [];

		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( ! isset( WC()->cart ) ) {
			return false;
		}

		// We use WC()->cart->cart_contents instead of WC()->cart->get_cart() to prevent recursion caused when get_cart_from_session() is called too early ref: https://github.com/woocommerce/woocommerce/commit/1f3365f2066b1e9d7e84aca7b1d7e89a6989c213
		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
			// Use WC()->cart->cart_contents instead of '$cart_item' as the item may have been removed by a parent item that manages it inside this loop.
			if ( ! isset( WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch'] ) ) {
				continue;
			}

			if ( ! wcs_is_subscription( $cart_item['subscription_switch']['subscription_id'] ) ) {
				WC()->cart->remove_cart_item( $cart_item_key );
				wc_add_notice( __( 'Your cart contained an invalid subscription switch request. It has been removed.', 'woocommerce-subscriptions' ), 'error' );
				continue;
			}

			$is_switch    = ! empty( $cart_item['subscription_switch']['item_id'] );
			$include_item = false;

			if ( 'any' === $item_action ) {
				$include_item = true;
			} elseif ( 'switch' === $item_action && $is_switch ) {
				$include_item = true;
			} elseif ( 'add' === $item_action && ! $is_switch ) {
				$include_item = true;
			}

			if ( $include_item ) {
				$subscription_switches[ $cart_item_key ] = $cart_item['subscription_switch'];
			}
		}

		return ! empty( $subscription_switches ) ? $subscription_switches : false;
	}

	/**
	 * Check if the cart includes any items which are to switch an existing subscription's item.
	 *
	 * @param int|object $product Either a product ID (not variation ID) or product object
	 * @return bool True if the cart contains a switch for a given product, or false if it does not.
	 * @since 2.0
	 */
	public static function cart_contains_switch_for_product( $product ) {
		if ( ! is_object( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product ) {
			return false;
		}

		$product_id         = $product->get_id();
		$switch_items       = self::cart_contains_switches();
		$switch_product_ids = array();

		if ( false !== $switch_items ) {

			// Check if there is a switch for this variation product
			foreach ( $switch_items as $switch_item_details ) {

				$switch_product  = wc_get_product( wcs_get_order_items_product_id( $switch_item_details['item_id'] ) );
				$parent_products = WC_Subscriptions_Product::get_parent_ids( $product );

				// If the switch is for a grouped product, we need to check the other products grouped with this one
				if ( $parent_products ) {
					foreach ( $parent_products as $parent_id ) {
						$parent_product = wc_get_product( $parent_id );

						if ( ! $parent_product ) {
							wc_get_logger()->error(
								'Parent product {parent_id} for switch product {product_id} not found',
								array(
									'parent_id'  => $parent_id,
									'product_id' => $product_id,
								)
							);
							continue;
						}

						$parent_product_children = $parent_product->get_children();

						if ( ! is_array( $parent_product_children ) ) {
							wc_get_logger()->error(
								'Children of parent product {parent_id} for switch product {product_id} is not an array',
								array(
									'parent_id'  => $parent_id,
									'product_id' => $product_id,
								)
							);
							continue;
						}

						$switch_product_ids = array_unique( array_merge( $switch_product_ids, $parent_product_children ) );
					}
				} elseif ( $switch_product->is_type( 'subscription_variation' ) ) {
					$switch_product_ids[] = $switch_product->get_parent_id();
				} else {
					$switch_product_ids[] = $switch_product->get_id();
				}
			}
		}

		return in_array( $product_id, $switch_product_ids );
	}

	/**
	 * Triggers the woocommerce_subscriptions_switch_added_to_cart action hook when a subscription switch is added to the cart.
	 *
	 * @since 2.6.0
	 *
	 * @param string $cart_item_key The new cart item's key.
	 * @param int    $product_id The product added to the cart.
	 * @param int    $quantity The cart item's quantity.
	 * @param int    $variation_id ID of the variation being added to the cart or 0.
	 * @param array  $variation_attributes The variation's attributes, if any.
	 * @param array  $cart_item_data The cart item's custom data.
	 */
	public static function trigger_switch_added_to_cart_hook( $cart_item_key, $product_id, $quantity, $variation_id, $variation_attributes, $cart_item_data ) {
		if ( ! isset( $cart_item_data['subscription_switch'] ) || empty( $cart_item_data['subscription_switch']['item_id'] ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $cart_item_data['subscription_switch']['subscription_id'] );

		if ( ! $subscription ) {
			return;
		}

		$existing_item = wcs_get_order_item( $cart_item_data['subscription_switch']['item_id'], $subscription );
		$cart_item     = WC()->cart->get_cart_item( $cart_item_key );

		do_action( 'woocommerce_subscriptions_switch_added_to_cart', $subscription, $existing_item, $cart_item_key, $cart_item );
	}

	/**
	 * When a switch is added to the cart, add coupons which should be retained during switch.
	 *
	 * By default subscription coupons are not retained. Use woocommerce_subscriptions_retain_coupon_on_switch
	 * and return true to copy coupons from the subscription into the cart.
	 *
	 * @since 2.6.0
	 * @param WC_Subscription $subscription
	 */
	public static function retain_coupons( $subscription ) {
		foreach ( wcs_get_used_coupon_codes( $subscription ) as $coupon_code ) {
			$coupon = new WC_Coupon( $coupon_code );
			if ( ! WC()->cart->has_discount( $coupon_code ) && true === apply_filters( 'woocommerce_subscriptions_retain_coupon_on_switch', false, $coupon_code, $coupon, $subscription ) ) {
				WC()->cart->add_discount( $coupon_code );
			}
		}
	}

	/**
	 * When a product is added to the cart, check if it is being added to switch a subscription and if so,
	 * make sure it's valid (i.e. not the same subscription).
	 *
	 * @since 1.4
	 */
	public static function validate_switch_request( $is_valid, $product_id, $quantity, $variation_id = '' ) {

		$error_message = '';
		$subscription  = null;
		$item          = null;

		try {

			$switch_subscription_id = Request::get_var( 'switch-subscription' );

			if ( ! $switch_subscription_id ) {
				return $is_valid;
			}

			$nonce = Request::get_var( '_wcsnonce' );

			if ( empty( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'wcs_switch_request' ) ) {
				return false;
			}

			$subscription = wcs_get_subscription( absint( $switch_subscription_id ) );

			if ( ! $subscription ) {
				throw new Exception( __( 'The subscription may have been deleted.', 'woocommerce-subscriptions' ) );
			}

			$item_id = absint( Request::get_var( 'item' ) );
			$item    = wcs_get_order_item( $item_id, $subscription );

			// Prevent switching to non-subscription product
			if ( ! WC_Subscriptions_Product::is_subscription( $product_id ) ) {
				throw new Exception( __( 'You can only switch to a subscription product.', 'woocommerce-subscriptions' ) );
			}

			// Check if the chosen variation's attributes are different to the existing subscription's attributes (to support switching between a "catch all" variation)
			if ( empty( $item ) ) {

				throw new Exception( __( 'We can not find your old subscription item.', 'woocommerce-subscriptions' ) );

			} else {

				$identical_attributes = true;

				foreach ( $_POST as $key => $value ) {
					if ( false !== strpos( $key, 'attribute_' ) && ! empty( $item[ str_replace( 'attribute_', '', $key ) ] ) && $item[ str_replace( 'attribute_', '', $key ) ] != $value ) {
						$identical_attributes = false;
						break;
					}
				}

				if ( $product_id == $item['product_id'] && ( empty( $variation_id ) || ( $variation_id == $item['variation_id'] && true == $identical_attributes ) ) && $quantity == $item['qty'] ) {
					$is_identical_product = true;
				} else {
					$is_identical_product = false;
				}

				$is_identical_product = apply_filters( 'woocommerce_subscriptions_switch_is_identical_product', $is_identical_product, $product_id, $quantity, $variation_id, $subscription, $item );

				if ( $is_identical_product ) {
					throw new Exception( __( 'You can not switch to the same subscription.', 'woocommerce-subscriptions' ) );
				}

				// Also remove any existing items in the cart for switching this item (but don't make the switch invalid)
				if ( $is_valid ) {

					$existing_switch_items = self::cart_contains_switches();

					if ( false !== $existing_switch_items ) {
						foreach ( $existing_switch_items as $cart_item_key => $switch_item ) {
							if ( $switch_item['item_id'] == $item_id ) {
								WC()->cart->remove_cart_item( $cart_item_key );
							}
						}
					}
				}
			}
		} catch ( Exception $e ) {
			$error_message = $e->getMessage();
		}

		$error_message = apply_filters( 'woocommerce_subscriptions_switch_error_message', $error_message, $product_id, $quantity, $variation_id, $subscription, $item );

		if ( ! empty( $error_message ) ) {
			wc_add_notice( $error_message, 'error' );
			$is_valid = false;
		}

		return apply_filters( 'woocommerce_subscriptions_is_switch_valid', $is_valid, $product_id, $quantity, $variation_id, $subscription, $item );
	}

	/**
	 * When a subscription switch is added to the cart, store a record of pertinent meta about the switch.
	 *
	 * @since 1.4
	 */
	public static function set_switch_details_in_cart( $cart_item_data, $product_id, $variation_id ) {

		try {
			$switch_subscription_id = Request::get_var( 'switch-subscription' );

			if ( ! $switch_subscription_id ) {
				return $cart_item_data;
			}

			$subscription = wcs_get_subscription( absint( $switch_subscription_id ) );

			if ( ! $subscription ) {
				throw new Exception( __( 'The subscription may have been deleted.', 'woocommerce-subscriptions' ) );
			}

			// Requesting a switch for someone elses subscription
			if ( ! current_user_can( 'switch_shop_subscription', $subscription->get_id() ) ) {
				wc_add_notice( __( 'You can not switch this subscription. It appears you do not own the subscription.', 'woocommerce-subscriptions' ), 'error' );
				WC()->cart->empty_cart( true );
				Request::redirect( get_permalink( $product_id ) );
				return;
			}

			$item = wcs_get_order_item( absint( Request::get_var( 'item' ) ), $subscription );

			// Else it's a valid switch
			$product         = wc_get_product( $item['product_id'] );
			$parent_products = WC_Subscriptions_Product::get_parent_ids( $product );
			$child_products  = array();

			if ( ! empty( $parent_products ) ) {
				foreach ( $parent_products as $parent_id ) {
					$parent_product = wc_get_product( $parent_id );

					if ( ! $parent_product ) {
						wc_get_logger()->error(
							'Parent product {parent_id} for switch product {product_id} not found',
							array(
								'parent_id'  => $parent_id,
								'product_id' => $item['product_id'],
							)
						);
						continue;
					}

					$parent_product_children = $parent_product->get_children();

					if ( ! is_array( $parent_product_children ) ) {
						wc_get_logger()->error(
							'Children of parent product {parent_id} for switch product {product_id} is not an array',
							array(
								'parent_id'  => $parent_id,
								'product_id' => $item['product_id'],
							)
						);
						continue;
					}

					$child_products = array_unique( array_merge( $child_products, $parent_product_children ) );
				}
			}

			if ( $product_id != $item['product_id'] && ! in_array( $item['product_id'], $child_products ) ) {
				return $cart_item_data;
			}

			$next_payment_timestamp = $subscription->get_time( 'next_payment' );

			// If there are no more payments due on the subscription, because we're in the last billing period, we need to use the subscription's expiration date, not next payment date
			if ( false == $next_payment_timestamp ) {
				$next_payment_timestamp = $subscription->get_time( 'end' );
			}

			$cart_item_data['subscription_switch'] = array(
				'subscription_id'        => $subscription->get_id(),
				'item_id'                => absint( Request::get_var( 'item' ) ),
				'next_payment_timestamp' => $next_payment_timestamp,
				'upgraded_or_downgraded' => '',
			);

			return $cart_item_data;

		} catch ( Exception $e ) {

			wc_add_notice( __( 'There was an error locating the switch details.', 'woocommerce-subscriptions' ), 'error' );
			WC()->cart->empty_cart( true );
			Request::redirect( get_permalink( wc_get_page_id( 'cart' ) ) );
			return;
		}
	}

	/**
	 * Get the recurring amounts values from the session
	 *
	 * @since 1.4
	 */
	public static function get_cart_from_session( $cart_item_data, $cart_item, $key ) {
		if ( isset( $cart_item['subscription_switch'] ) ) {
			$cart_item_data['subscription_switch'] = $cart_item['subscription_switch'];
		}

		return $cart_item_data;
	}

	/**
	 * Make sure the sign-up fee on a subscription line item takes into account sign-up fees paid for switching.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $tax_inclusive_or_exclusive Defaults to the value tax setting stored on the subscription.
	 * @return array $cart_item Details of an item in WC_Cart for a switch
	 * @since 2.0
	 */
	public static function subscription_items_sign_up_fee( $sign_up_fee, $line_item, $subscription, $tax_inclusive_or_exclusive = '' ) {

		// This item has never been switched, no need to add anything
		if ( ! isset( $line_item['switched_subscription_item_id'] ) ) {
			return $sign_up_fee;
		}

		// First add any sign-up fees for previously switched items
		$switched_line_items = $subscription->get_items( 'line_item_switched' );

		// Default tax inclusive or exclusive to the value set on the subscription. This is for backwards compatibility
		if ( empty( $tax_inclusive_or_exclusive ) ) {
			$tax_inclusive_or_exclusive = ( $subscription->get_prices_include_tax() ) ? 'inclusive_of_tax' : 'exclusive_of_tax';
		}

		foreach ( $switched_line_items as $switched_line_item_id => $switched_line_item ) {
			if ( $line_item['switched_subscription_item_id'] == $switched_line_item_id ) {
				$sign_up_fee += $subscription->get_items_sign_up_fee( $switched_line_item, $tax_inclusive_or_exclusive ); // Recursion: get the sign up fee for this item's old item and the sign up fee for that item's old item and the sign up fee for that item's old item and the sign up fee for that item's old item ...
				break; // Each item can only be switched once
			}
		}

		// Now add any sign-up fees paid in switch orders
		foreach ( wcs_get_switch_orders_for_subscription( $subscription->get_id() ) as $order ) {
			foreach ( $order->get_items() as $order_item_id => $order_item ) {
				if ( wcs_get_canonical_product_id( $line_item ) == wcs_get_canonical_product_id( $order_item ) ) {

					// We only want to add the amount of the line total which was for a prorated sign-up fee, not the amount for a prorated recurring amount
					if ( isset( $order_item['switched_subscription_sign_up_fee_prorated'] ) ) {
						if ( $order_item['switched_subscription_sign_up_fee_prorated'] > 0 ) {
							$sign_up_proportion = $order_item['switched_subscription_sign_up_fee_prorated'] / ( $order_item['switched_subscription_price_prorated'] + $order_item['switched_subscription_sign_up_fee_prorated'] );
						} else {
							$sign_up_proportion = 0;
						}
					} else {
						$sign_up_proportion = 1;
					}

					$order_total = $order_item['line_total'];

					if ( 'inclusive_of_tax' == $tax_inclusive_or_exclusive && wcs_get_objects_property( $order, 'prices_include_tax' ) ) {
						$order_total += $order_item['line_tax'];
					}

					$sign_up_fee += round( $order_total * $sign_up_proportion, 2 );
				}
			}
		}

		return $sign_up_fee;
	}

	/**
	 * Set the subscription prices to be used in calculating totals by @see WC_Subscriptions_Cart::calculate_subscription_totals()
	 *
	 * @since 2.0
	 * @param WC_Cart $cart The cart object which totals are being calculated.
	 */
	public static function calculate_prorated_totals( $cart ) {
		if ( self::cart_contains_switches( 'any' ) ) {
			self::$switch_totals_calculator = new WCS_Switch_Totals_Calculator( $cart );
			self::$switch_totals_calculator->calculate_prorated_totals();
		}
	}

	/**
	 * Make sure when displaying the first payment date for a switched subscription, the date takes into
	 * account the switch (i.e. prepaid days and possibly a downgrade).
	 *
	 * @since 2.0
	 */
	public static function recurring_cart_next_payment_date( $first_renewal_date, $cart ) {

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) && 0 != $cart_item['subscription_switch']['first_payment_timestamp'] ) {
				$first_renewal_date = ( '1' != WC_Subscriptions_Product::get_length( $cart_item['data'] ) ) ? gmdate( 'Y-m-d H:i:s', $cart_item['subscription_switch']['first_payment_timestamp'] ) : 0;
			}
		}

		return $first_renewal_date;
	}

	/**
	 * Make sure the end date of the switched subscription starts after already paid term
	 *
	 * @since 2.0
	 */
	public static function recurring_cart_end_date( $end_date, $cart, $product ) {

		if ( 0 !== $end_date ) {
			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

				if ( isset( $cart_item['subscription_switch']['subscription_id'] ) && isset( $cart_item['data'] ) && $product == $cart_item['data'] ) {
					$next_payment_time = isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) ? $cart_item['subscription_switch']['first_payment_timestamp'] : 0;
					$end_timestamp     = WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['end_timestamp'];

					// if the subscription is length 1 and prorated, we want to use the prorated the next payment date as the end date
					if ( 1 == WC_Subscriptions_Product::get_length( $cart_item['data'] ) && 0 !== $next_payment_time && isset( $cart_item['subscription_switch']['recurring_payment_prorated'] ) ) {
						$end_date = gmdate( 'Y-m-d H:i:s', $next_payment_time );

					// if the subscription is more than 1 (and not 0) and we have a next payment date (prorated or not) we want to calculate the new end date from that
					} elseif ( 0 !== $next_payment_time && WC_Subscriptions_Product::get_length( $cart_item['data'] ) > 1 ) {
						// remove trial period on the switched subscription when calculating the new end date
						$trial_length = wcs_get_objects_property( $cart_item['data'], 'subscription_trial_length' );
						wcs_set_objects_property( $cart_item['data'], 'subscription_trial_length', 0, 'set_prop_only' );

						$end_date = WC_Subscriptions_Product::get_expiration_date( $cart_item['data'], gmdate( 'Y-m-d H:i:s', $next_payment_time ) );

						// add back the trial length if it has been spoofed
						wcs_set_objects_property( $cart_item['data'], 'subscription_trial_length', $trial_length, 'set_prop_only' );

					// elseif fallback to using the end date set on the cart item
					} elseif ( ! empty( $end_timestamp ) ) {
						$end_date = gmdate( 'Y-m-d H:i:s', $end_timestamp );
					}

					break;
				}
			}
		}
		return $end_date;
	}

	/**
	 * Make sure that a switch items cart key is based on it's first renewal date, not the date calculated for the product.
	 *
	 * @since 2.0
	 */
	public static function get_recurring_cart_key( $cart_key, $cart_item ) {

		if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) ) {
			remove_filter( 'woocommerce_subscriptions_recurring_cart_key', __METHOD__, 10 );
			$cart_key = WC_Subscriptions_Cart::get_recurring_cart_key( $cart_item, $cart_item['subscription_switch']['first_payment_timestamp'] );
			add_filter( 'woocommerce_subscriptions_recurring_cart_key', __METHOD__, 10, 2 );
		}

		// Append switch data to the recurring cart key so switch items are separated from other subscriptions in the cart. Switch items are processed through the checkout separately so should have separate recurring carts.
		if ( isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
			$cart_key .= '_switch_' . $cart_item['subscription_switch']['subscription_id'];
		}

		return $cart_key;
	}

	/**
	 * If the current request is to switch subscriptions, don't show a product's free trial period (because there is no
	 * free trial for subscription switches) and also if the length is being prorateed, don't display the length until
	 * checkout.
	 *
	 * @since 1.4
	 */
	public static function customise_product_string_inclusions( $inclusions, $product ) {

		if ( isset( $_GET['switch-subscription'] ) || self::cart_contains_switch_for_product( $product ) ) {

			$inclusions['trial_length'] = false;

			$apportion_length      = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'no' );
			$apportion_sign_up_fee = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee', 'no' );

			if ( 'yes' == $apportion_length || ( 'virtual' == $apportion_length && $product->is_virtual() ) ) {
				$inclusions['subscription_length'] = false;
			}

			if ( 'no' === $apportion_sign_up_fee ) {
				$inclusions['sign_up_fee'] = false;
			}
		}

		return $inclusions;
	}

	/**
	 * Do not carry over switch related meta data to renewal orders.
	 *
	 * @since 4.7.0
	 *
	 * @see wc_subscriptions_renewal_order_data
	 *
	 * @param array $order_meta An order's meta data.
	 *
	 * @return array Filtered order meta data to be copied.
	 */
	public static function remove_renewal_order_meta( $order_meta ) {
		unset( $order_meta['_subscription_switch'] );
		return $order_meta;
	}

	/**
	 * Do not carry over switch related meta data to renewal orders.
	 *
	 * @deprecated 4.7.0
	 *
	 * @since 1.5.4
	 */
	public static function remove_renewal_order_meta_query( $order_meta_query ) {
		_deprecated_function( __METHOD__, '4.7.0', 'WC_Subscriptions_Switcher::remove_renewal_order_meta' );

		$order_meta_query .= " AND `meta_key` NOT IN ('_subscription_switch')";

		return $order_meta_query;
	}

	/**
	 * Make the switch process persist even if the subscription product has Product Addons that need to be set.
	 *
	 * @since 1.5.6
	 */
	public static function addons_add_to_cart_url( $add_to_cart_url ) {

		if ( isset( $_GET['switch-subscription'] ) && false === strpos( $add_to_cart_url, 'switch-subscription' ) ) {
			$add_to_cart_url = self::add_switch_query_args( absint( $_GET['switch-subscription'] ), absint( $_GET['item'] ), $add_to_cart_url );
		}

		return $add_to_cart_url;
	}

	/**
	 * Completes subscription switches on completed order status changes.
	 *
	 * Commits all the changes calculated and saved by @see WC_Subscriptions_Switcher::process_checkout(), updating subscription
	 * line items, schedule, dates and totals to reflect the changes made in this switch order.
	 *
	 * @param int $order_id The post_id of a shop_order post/WC_Order object
	 * @param array $order_old_status The old order status
	 * @param array $order_new_status The new order status
	 * @since 2.1
	 */
	public static function process_subscription_switches( $order_id, $order_old_status, $order_new_status ) {
		$order            = wc_get_order( $order_id );
		$switch_processed = wcs_get_objects_property( $order, 'completed_subscription_switch' );

		if ( ! wcs_order_contains_switch( $order ) || 'true' == $switch_processed ) {
			return;
		}

		$order_completed = in_array( $order_new_status, array( apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order_id, $order ), 'processing', 'completed' ) );

		if ( $order_completed ) {
			$transaction = new WCS_SQL_Transaction();

			try {
				// Start transaction if available
				$transaction->start();

				self::complete_subscription_switches( $order );

				wcs_set_objects_property( $order, 'completed_subscription_switch', 'true' );

				$transaction->commit();

			} catch ( Exception $e ) {
				$transaction->rollback();
				throw $e;
			}

			do_action( 'woocommerce_subscriptions_switch_completed', $order );
		}
	}

	/**
	 * Check if a given subscription item was for upgrading/downgrading an existing item.
	 *
	 * @since 2.0
	 */
	protected static function is_item_switched( $item ) {
		return isset( $item['switched'] );
	}

	/**
	 * Do not display switch related order item meta keys unless Subscriptions is in debug mode.
	 *
	 * @since 2.0
	 */
	public static function hidden_order_itemmeta( $hidden_meta_keys ) {

		if ( apply_filters( 'woocommerce_subscriptions_hide_switch_itemmeta', ! defined( 'WCS_DEBUG' ) || true !== WCS_DEBUG ) ) {
			$hidden_meta_keys = array_merge(
				$hidden_meta_keys,
				array(
					'_switched_subscription_item_id',
					'_switched_subscription_new_item_id',
					'_switched_subscription_sign_up_fee_prorated',
					'_switched_subscription_price_prorated',
				)
			);
		}

		return $hidden_meta_keys;
	}

	/**
	 * Stop the switch link from printing on email templates
	 *
	 * @since 2.0
	 */
	public static function remove_print_switch_link() {
		remove_action( 'woocommerce_order_item_meta_end', __CLASS__ . '::print_switch_link', 10 );
	}

	/**
	 * Add the print switch link filter back after the subscription items table has been created in email template
	 *
	 * @since 2.0
	 */
	public static function add_print_switch_link( $table_content ) {
		add_action( 'woocommerce_order_item_meta_end', __CLASS__ . '::print_switch_link', 10, 3 );
		return $table_content;
	}

	/**
	 * Add the cart item upgrade/downgrade/crossgrade direction for display
	 *
	 * @since 2.0
	 */
	public static function add_cart_item_switch_direction( $product_subtotal, $cart_item, $cart_item_key ) {

		if ( ! empty( $cart_item['subscription_switch'] ) ) {

			switch ( $cart_item['subscription_switch']['upgraded_or_downgraded'] ) {
				case 'downgraded':
					$direction = _x( 'Downgrade', 'a switch type', 'woocommerce-subscriptions' );
					break;
				case 'upgraded':
					$direction = _x( 'Upgrade', 'a switch type', 'woocommerce-subscriptions' );
					break;
				default:
					$direction = _x( 'Crossgrade', 'a switch type', 'woocommerce-subscriptions' );
				break;
			}

			// translators: %1: product subtotal, %2: HTML span tag, %3: direction (upgrade, downgrade, crossgrade), %4: closing HTML span tag
			$product_subtotal = sprintf( _x( '%1$s %2$s(%3$s)%4$s', 'product subtotal string', 'woocommerce-subscriptions' ), $product_subtotal, '<span class="subscription-switch-direction">', $direction, '</span>' );

		}

		return $product_subtotal;
	}

	/**
	 * Gets the switch direction of a cart item.
	 *
	 * @param array $cart_item Cart item object.
	 * @return string|null Cart item subscription switch direction or null.
	 */
	public static function get_cart_item_switch_type( $cart_item ) {
		return isset( $cart_item['subscription_switch'], $cart_item['subscription_switch']['upgraded_or_downgraded'] ) ? $cart_item['subscription_switch']['upgraded_or_downgraded'] : null;
	}

	/**
	 * Creates a 2.0 updated version of the "subscriptions_switched" callback for developers to hook onto.
	 *
	 * The subscription passed to the new `woocommerce_subscriptions_switched_item` callback is strictly the subscription
	 * to which the `$new_order_item` belongs to; this may be a new or the original subscription.
	 *
	 * @since 2.0.5
	 * @param WC_Order $order
	 */
	public static function maybe_add_switched_callback( $order ) {
		if ( wcs_order_contains_switch( $order ) ) {

			$subscriptions = wcs_get_subscriptions_for_order( $order );

			foreach ( $subscriptions as $subscription ) {
				foreach ( $subscription->get_items() as $new_order_item ) {
					if ( isset( $new_order_item['switched_subscription_item_id'] ) ) {
						$product_id = wcs_get_canonical_product_id( $new_order_item );
						// we need to check if the switch order contains the line item that has just been switched so that we don't call the hook on items that were previously switched in another order
						foreach ( $order->get_items() as $order_item ) {
							if ( wcs_get_canonical_product_id( $order_item ) == $product_id ) {
								do_action( 'woocommerce_subscriptions_switched_item', $subscription, $new_order_item, WC_Subscriptions_Order::get_item_by_id( $new_order_item['switched_subscription_item_id'] ) );
								break;
							}
						}
					}
				}
			}
		}
	}

	/**
	* Revoke download permissions granted on the old switch item.
	*
	* @since 2.0.9
	* @param WC_Subscription $subscription
	* @param array $new_item
	* @param array $old_item
	*/
	public static function remove_download_permissions_after_switch( $subscription, $new_item, $old_item ) {

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		$product_id = wcs_get_canonical_product_id( $old_item );
		WCS_Download_Handler::revoke_downloadable_file_permission( $product_id, $subscription->get_id(), $subscription->get_user_id() );

	}

	/**
	 * Completes subscription switches for switch order.
	 *
	 * Performs all the changes calculated and saved by @see WC_Subscriptions_Switcher::process_checkout(), updating subscription
	 * line items, schedule, dates and totals to reflect the changes made in this switch order.
	 *
	 * @param WC_Order $order
	 * @since 2.1
	 */
	public static function complete_subscription_switches( $order ) {

		// Get the switch meta
		$switch_order_data = wcs_get_objects_property( $order, 'subscription_switch_data' );

		// if we don't have an switch data, there is nothing to do here. Switch orders created prior to v2.1 won't have any data to process.
		if ( empty( $switch_order_data ) || ! is_array( $switch_order_data ) ) {
			return;
		}

		foreach ( $switch_order_data as $subscription_id => $switch_data ) {

			$subscription = wcs_get_subscription( $subscription_id );

			if ( ! $subscription instanceof WC_Subscription ) {
				continue;
			}

			if ( ! empty( $switch_data['switches'] ) && is_array( $switch_data['switches'] ) ) {
				foreach ( $switch_data['switches'] as $order_item_id => $switched_item_data ) {
					$add_subscription_item    = isset( $switched_item_data['add_line_item'] );
					$remove_subscription_item = isset( $switched_item_data['remove_line_item'] );
					$switch_order_item        = wcs_get_order_item( $order_item_id, $order );

					// If we are adding a line item to an existing subscription...
					if ( $add_subscription_item ) {
						wcs_update_order_item_type( $switched_item_data['add_line_item'], 'line_item', $subscription->get_id() );

						// Trigger the action now if we're also removing an exising item from the original subscription.
						if ( $remove_subscription_item ) {
							do_action( 'woocommerce_subscription_item_switched', $order, $subscription, $switched_item_data['add_line_item'], $switched_item_data['remove_line_item'] );
						}
					}

					// Removing an existing subscription item?
					if ( $remove_subscription_item ) {
						$old_subscription_item = wcs_get_order_item( $switched_item_data['remove_line_item'], $subscription );
					}

					if ( $remove_subscription_item && empty( $old_subscription_item ) ) {
						throw new Exception( __( 'The original subscription item being switched cannot be found.', 'woocommerce-subscriptions' ) );
					} elseif ( empty( $switch_order_item ) ) {
						throw new Exception( __( 'The item on the switch order cannot be found.', 'woocommerce-subscriptions' ) );
					}

					// We don't want to include switch item meta in order item name
					add_filter( 'woocommerce_subscriptions_hide_switch_itemmeta', '__return_true' );
					$new_item_name = wcs_get_order_item_name( $switch_order_item, array( 'attributes' => true ) );

					if ( $remove_subscription_item ) {
						wcs_update_order_item_type( $switched_item_data['remove_line_item'], 'line_item_switched', $subscription->get_id() );

						// translators: 1$: old item, 2$: new item when switching
						$add_note = sprintf( _x( 'Customer switched from: %1$s to %2$s.', 'used in order notes', 'woocommerce-subscriptions' ), wcs_get_order_item_name( $old_subscription_item, array( 'attributes' => true ) ), $new_item_name );
					} else {
						// translators: %s: new item name.
						$add_note = sprintf( _x( 'Customer added %s.', 'used in order notes', 'woocommerce-subscriptions' ), $new_item_name );
					}

					remove_filter( 'woocommerce_subscriptions_hide_switch_itemmeta', '__return_true' );
				}
			}

			// Subscription objects hold an internal cache of line items so we need to get an updated subscription object after changing the line item types directly in the database.
			$subscription = wcs_get_subscription( $subscription_id );

			if ( ! $subscription ) {
				continue;
			}

			if ( ! empty( $add_note ) ) {
				$subscription->add_order_note( $add_note );
			}

			if ( ! empty( $switch_data['billing_schedule'] ) ) {

				// Update the billing schedule
				if ( ! empty( $switch_data['billing_schedule']['_billing_period'] ) ) {
					$subscription->set_billing_period( $switch_data['billing_schedule']['_billing_period'] );
				}

				if ( ! empty( $switch_data['billing_schedule']['_billing_interval'] ) ) {
					$subscription->set_billing_interval( $switch_data['billing_schedule']['_billing_interval'] );
				}
			}

			// Update subscription dates
			if ( ! empty( $switch_data['dates'] ) ) {

				if ( ! empty( $switch_data['dates']['delete'] ) ) {
					foreach ( $switch_data['dates']['delete'] as $date ) {
						$subscription->delete_date( $date );
					}
				}

				if ( ! empty( $switch_data['dates']['update'] ) ) {
					$subscription->update_dates( $switch_order_data[ $subscription->get_id() ]['dates']['update'] );
				}
			}

			// Archive the old coupons
			foreach ( $subscription->get_items( 'coupon' ) as $coupon_id => $coupon ) {
				wcs_update_order_item_type( $coupon_id, 'coupon_switched', $subscription->get_id() );
			}

			if ( ! empty( $switch_data['coupons'] ) && is_array( $switch_data['coupons'] ) ) {
				// Flip the switched coupons "on"
				foreach ( $switch_data['coupons'] as $coupon_code ) {
					wcs_update_order_item_type( $coupon_code, 'coupon', $subscription->get_id() );
				}
			}

			// Archive the old fees
			foreach ( $subscription->get_fees() as $fee_item_id => $fee ) {
				wcs_update_order_item_type( $fee_item_id, 'fee_switched', $subscription->get_id() );
			}

			if ( ! empty( $switch_data['fee_items'] ) && is_array( $switch_data['fee_items'] ) ) {
				// Flip the switched fee items "on"
				foreach ( $switch_data['fee_items'] as $fee_item_id ) {
					wcs_update_order_item_type( $fee_item_id, 'fee', $subscription->get_id() );
				}
			}

			if ( ! empty( $switch_data['shipping_line_items'] ) && is_array( $switch_data['shipping_line_items'] ) ) {
				// Archive the old subscription shipping methods
				foreach ( $subscription->get_shipping_methods() as $shipping_line_item_id => $item ) {
					wcs_update_order_item_type( $shipping_line_item_id, 'shipping_switched', $subscription->get_id() );
				}

				// Flip the switched shipping line items "on"
				foreach ( $switch_data['shipping_line_items'] as $shipping_line_item_id ) {
					wcs_update_order_item_type( $shipping_line_item_id, 'shipping', $subscription->get_id() );
				}
			}

			// Update the subscription address
			self::maybe_update_subscription_address( $order, $subscription );

			// Save every change
			$subscription->save();

			// We just changed above the type of some items related to this subscription, so we need to reload it to get the newest items
			$refreshed_subscription = wcs_get_subscription( $subscription->get_id() );

			if ( $refreshed_subscription ) {
				$refreshed_subscription->calculate_totals();
			}
		}
	}

	/**
	 * If we are switching a $0 / period subscription to a non-zero $ / period subscription, and the existing
	 * subscription is using manual renewals but manual renewals are not forced on the site, we need to set a
	 * flag to force WooCommerce to require payment so that we can switch the subscription to automatic renewals
	 * because it was probably only set to manual because it was $0.
	 *
	 * We need to determine this here instead of on the 'woocommerce_cart_needs_payment' because when payment is being
	 * processed, we will have changed the associated subscription data already, so we can't check that subscription's
	 * values anymore. We determine it here, then ue the 'force_payment' flag on 'woocommerce_cart_needs_payment' to
	 * require payment.
	 *
	 * @param int $total
	 * @since 2.0.16
	 */
	public static function set_force_payment_flag_in_cart( $total ) {

		if ( $total > 0 || wcs_is_manual_renewal_required() || false === self::cart_contains_switches( 'any' ) ) {
			return $total;
		}

		$old_recurring_total = 0;
		$new_recurring_total = 0;
		$has_future_payments = false;

		// Check that the new subscriptions are not for $0 recurring and there is a future payment required
		foreach ( WC()->cart->recurring_carts as $cart ) {

			$new_recurring_total += $cart->total;

			if ( $cart->next_payment_date > 0 ) {
				$has_future_payments = true;
			}
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
				continue;
			}

			$subscription = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );

			if ( ! $subscription ) {
				continue;
			}

			$is_manual_subscription = $subscription->is_manual();

			// Check for $0 / period to a non-zero $ / period and manual subscription
			$switch_from_zero_manual_subscription = $is_manual_subscription && 0 == $subscription->get_total();

			// Force payment gateway selection for new subscriptions if the old subscription was automatic or manual renewals aren't accepted
			$force_automatic_payments = ! $is_manual_subscription || 'no' === get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no' );

			if ( $new_recurring_total > 0 && true === $has_future_payments && ( $switch_from_zero_manual_subscription || ( $force_automatic_payments && self::cart_contains_subscription_creating_switch() ) ) ) {
				WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['force_payment'] = true;
			}
		}

		return $total;
	}

	/**
	 * Require payment when switching from a $0 / period subscription to a non-zero subscription to process
	 * automatic payments for future renewals, as indicated by the 'force_payment' flag on the switch, set in
	 * @see self::set_force_payment_flag_in_cart().
	 *
	 * @param bool $needs_payment
	 * @param object $cart
	 * @since 2.0.16
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {

		if ( false === $needs_payment && 0 == $cart->total && false !== ( $switch_items = self::cart_contains_switches( 'any' ) ) ) {

			foreach ( $switch_items as $switch_item ) {
				if ( isset( $switch_item['force_payment'] ) && true === $switch_item['force_payment'] ) {
					$needs_payment = true;
					break;
				}
			}
		}

		return $needs_payment;
	}

	/**
	 * Once payment is processed on a switch from a $0 / period subscription to a non-zero $ / period subscription, if
	 * payment was completed with a payment method which supports automatic payments, update the payment on the subscription
	 * and the manual renewals flag so that future renewals are processed automatically.
	 *
	 * @param WC_Order $order
	 * @since 2.1
	 */
	public static function maybe_set_payment_method_after_switch( $order ) {

		// Only set manual subscriptions to automatic if automatic payments are enabled
		if ( wcs_is_manual_renewal_required() ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_switch_order( $order ) as $subscription ) {

			if ( false === $subscription->is_manual() ) {
				continue;
			}

			if ( $subscription->get_payment_method() !== wcs_get_objects_property( $order, 'payment_method' ) ) {

				// Set the new payment method on the subscription
				// @phpstan-ignore property.notFound
				$available_gateways   = WC()->payment_gateways->get_available_payment_gateways();
				$order_payment_method = wcs_get_objects_property( $order, 'payment_method' );
				$payment_method       = '' != $order_payment_method && isset( $available_gateways[ $order_payment_method ] ) ? $available_gateways[ $order_payment_method ] : false;

				if ( $payment_method && $payment_method->supports( 'subscriptions' ) ) {
					$subscription->set_payment_method( $payment_method );
					$subscription->set_requires_manual_renewal( false );
					$subscription->save();
				}
			}
		}
	}

	/**
	 * Delay granting download permissions to the subscription until the switch is processed.
	 *
	 * @param int $order_id The order the download permissions are being granted for.
	 * @since 2.2.13
	 */
	public static function delay_granting_download_permissions( $order_id ) {
		if ( wcs_order_contains_switch( $order_id ) ) {
			remove_action( 'woocommerce_grant_product_download_permissions', 'WCS_Download_Handler::save_downloadable_product_permissions' );
		}
	}

	/**
	 * Grant the download permissions to the subscription after the switch is processed.
	 *
	 * @param WC_Order $order The switch order.
	 * @since 2.2.13
	 */
	public static function grant_download_permissions( $order ) {
		WCS_Download_Handler::save_downloadable_product_permissions( wcs_get_objects_property( $order, 'id' ) );

		// reattach the hook detached in @see self::delay_granting_download_permissions()
		add_action( 'woocommerce_grant_product_download_permissions', 'WCS_Download_Handler::save_downloadable_product_permissions' );
	}

	/**
	 * Calculates the total amount a customer has paid in early renewals and switches since the last non-early renewal or parent order (inclusive).
	 *
	 * This function will map the current item back through multiple switches to make sure it finds the item that was present at the time of last parent/scheduled renewal.
	 *
	 * @since 2.6.0
	 *
	 * @param WC_Subscription       $subscription         The Subscription.
	 * @param WC_Order_Item         $subscription_item    The current line item on the subscription to map back through the related orders.
	 * @param string                $include_sign_up_fees Optional. Whether to include the sign-up fees paid. Can be 'include_sign_up_fees' or 'exclude_sign_up_fees'. Default 'include_sign_up_fees'.
	 * @param WC_Order[]            $orders_to_include    Optional. The orders to include in the total.
	 *
	 * @return float The total amount paid for an existing subscription line item.
	 */
	public static function calculate_total_paid_since_last_order( $subscription, $subscription_item, $include_sign_up_fees = 'include_sign_up_fees', $orders_to_include = array() ) {
		$found_item      = false;
		$item_total_paid = 0;
		$orders          = empty( $orders_to_include ) ? $subscription->get_related_orders( 'all', array( 'parent', 'renewal', 'switch' ) ) : $orders_to_include;

		// We need the orders sorted by the date they were paid, with the newest first.
		wcs_sort_objects( $orders, 'date_paid', 'descending' );

		// We'll need to make sure we map switched items back through past orders so flag if the current item has been switched before.
		$has_been_switched           = $subscription_item->meta_exists( '_switched_subscription_item_id' );
		$switched_subscription_items = $subscription->get_items( 'line_item_switched' );

		foreach ( $orders as $order ) {
			$order_is_parent = $order->get_id() === $subscription->get_parent_id();

			/**
			 * Find the item on the order which matches the subscription item.
			 *
			 * @var WC_Order_Item_Product $order_item */
			$order_item = wcs_find_matching_line_item( $order, $subscription_item );

			if ( $order_item && ! is_bool( $order_item ) ) {
				$found_item = true;
				$item_total = (float) $order_item->get_total();

				if ( $order->get_prices_include_tax( 'edit' ) ) {
					$item_total += (float) $order_item->get_total_tax();
				}

				// Remove any signup fees if necessary.
				if ( 'include_sign_up_fees' !== $include_sign_up_fees ) {
					if ( $order_is_parent ) {
						if ( $order_item->meta_exists( '_synced_sign_up_fee' ) ) {
							$item_total -= $order_item->get_meta( '_synced_sign_up_fee' ) * $order_item->get_quantity();
						} elseif ( $subscription_item->meta_exists( '_has_trial' ) ) {
							// Where there's a free trial, the sign up fee is the entire item total so the non-sign-up fee portion is 0.
							$item_total = 0;
						} else {
							// For non-free trial subscriptions, the sign up fee portion is the order total minus the recurring total (subscription item total).
							// Use the subscription item's subtotal (without discounts) to avoid signup fee coupon discrepancies
							// @phpstan-ignore-next-line False positive when using WC_Order_Item_Product::get_subtotal()
							$item_total -= max( $order_item->get_total() - $subscription_item->get_subtotal(), 0 );
						}
					// Remove the prorated sign up fees.
					} elseif ( $order_item->meta_exists( '_switched_subscription_sign_up_fee_prorated' ) ) {
						$item_total -= $order_item->get_meta( '_switched_subscription_sign_up_fee_prorated' ) * $order_item->get_quantity();
					}
				}

				$item_total_paid += $item_total;
			}

			// If the current order in line contains a switch, we might need to start looking for the previous product in older related orders.
			if ( $has_been_switched && wcs_order_contains_switch( $order ) ) {
				// The new subscription item stores a reference to the old subscription item in meta.
				$switched_subscription_item_id = $subscription_item->get_meta( '_switched_subscription_item_id' );

				// Check that the switched subscription line item still exists.
				if ( isset( $switched_subscription_items[ $switched_subscription_item_id ] ) ) {
					$switched_subscription_item = $switched_subscription_items[ $switched_subscription_item_id ];

					// The switched subscription item stores a reference to the new item on the switch order .
					$switch_order_item_id = $switched_subscription_item->get_meta( '_switched_subscription_new_item_id' );

					// Check that this switch order contains the switch for the current subscription item.
					if ( $switch_order_item_id && (bool) wcs_get_order_item( $switch_order_item_id, $order ) ) {
						// The item we need to look for now in older related orders is the subscription item which switched.
						$subscription_item = $switched_subscription_item;

						// If the switched subscription item has been switched, make a note of it too as we might have a multi-switch.
						$has_been_switched = $subscription_item->meta_exists( '_switched_subscription_item_id' );
					}
				}
			}

			// If this is a parent order, or it's a renewal order but not an early renewal, we've gone back far enough -- exit out.
			if ( $order_is_parent || ( wcs_order_contains_renewal( $order ) && ! wcs_order_contains_early_renewal( $order ) ) ) {
				break;
			}
		}

		// If we never found any amount paid, fall back to the existing item's line item total.
		return $found_item ? $item_total_paid : $subscription_item['line_total'];
	}

	/**
	 * Logs information about all the switches in the cart to the wcs-switch-cart-items log.
	 *
	 * @since 2.6.0
	 */
	public static function log_switches() {
		if ( isset( self::$switch_totals_calculator ) ) {
			self::$switch_totals_calculator->log_switches();
		}
	}

	/**
	 * Determines if a subscription item being switched is the last remaining item on the subscription after previous switches.
	 *
	 * If the item being switched is the last remaining item on the subscription after previous switches, then the subscription
	 * can be updated even if the billing schedule is being changed.
	 *
	 * @param WC_Subscription       $subscription  The subscription being switched.
	 * @param WC_Order_Item_Product $switched_item The subscription line item being switched.
	 * @param array                 $switch_data   Data about the switches that will occur on the subscription.
	 *
	 * @return bool True if the item being switched is the last remaining item on the subscription after previous switches.
	 */
	private static function is_last_remaining_item_after_previous_switches( $subscription, $switched_item, $switch_data ) {
		$remaining_items = $subscription->get_items();

		// If there is no switch data for this subscription return false.
		if ( ! isset( $switch_data[ $subscription->get_id() ]['switches'] ) ) {
			return false;
		}

		foreach( $switch_data[ $subscription->get_id() ]['switches'] as $switch ) {
			// If items are actively being added to this subscription, then it is not the last remaining item.
			if ( isset( $switch['add_line_item'] ) ) {
				return false;
			}

			if ( isset( $switch['remove_line_item'] ) ) {
				unset( $remaining_items[ $switch['remove_line_item'] ] );
			}
		}

		// If there's only 1 item left and it's the item we're switching, then it's the last remaining item.
		return 1 === count( $remaining_items ) && isset( $remaining_items[ $switched_item->get_id() ] );
	}

	/**
	 * Adds switch orders or switched subscriptions to the related order meta box.
	 *
	 * @since 3.1.0
	 *
	 * @param WC_Abstract_Order[] $orders_to_display The list of related orders to display.
	 * @param WC_Subscription[]   $subscriptions     The list of related subscriptions.
	 * @param WC_Order            $order             The order or subscription post being viewed.
	 *
	 * @return array The orders/subscriptions to display in the meta box.
	 */
	public static function display_switches_in_related_order_metabox( $orders_to_display, $subscriptions, $order ) {
		if ( $order instanceof WP_Post ) {
			wcs_deprecated_argument( __METHOD__, '4.7.0', 'Passing a WP Post object is deprecated. This function now expects an Order or Subscription object.' );
			$order = wc_get_order( $order->ID );
		}

		$switched_subscriptions = array();

		// On the subscription page, just show related orders.
		if ( wcs_is_subscription( $order ) ) {

			foreach ( wcs_get_switch_orders_for_subscription( $order->get_id() ) as $switch_order ) {
				$switch_order->update_meta_data( '_relationship', __( 'Switch Order', 'woocommerce-subscriptions' ) );
				$orders_to_display[] = $switch_order;
			}

			// Display the subscriptions which had item/s switched to this subscription by its parent order.
			if ( ! empty( $order->post_parent ) ) {
				$switched_subscriptions = wcs_get_subscriptions_for_switch_order( $order->get_parent_id() );
			}

			// On the Edit Order screen, show any subscriptions with items switched by this order.
		} else {
			$switched_subscriptions = wcs_get_subscriptions_for_switch_order( $order );
		}

		foreach ( $switched_subscriptions as $subscription ) {
			$subscription->update_meta_data( '_relationship', __( 'Switched Subscription', 'woocommerce-subscriptions' ) );
			$orders_to_display[] = $subscription;
		}

		return $orders_to_display;
	}

	/**
	 * Override the order item quantity used to reduce stock levels when the order item is to record a switch and where no
	 * prorated amount is being charged.
	 *
	 * @param int $quantity the original order item quantity used to reduce stock
	 * @param WC_Order $order
	 * @param array $order_item
	 *
	 * @return int
	 */
	public static function maybe_do_not_reduce_stock( $quantity, $order, $order_item ) {

		if ( isset( $order_item['switched_subscription_price_prorated'] ) && 0 == $order_item['line_total'] ) {
			$quantity = 0;
		}

		return $quantity;
	}

	/**
	 * Make sure switch cart item price doesn't include any recurring amount by setting a free trial.
	 *
	 * @since 2.0.18
	 */
	public static function maybe_set_free_trial( $total = '' ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) && 0 != $cart_item['subscription_switch']['first_payment_timestamp'] ) {
				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_trial_length', 1, 'set_prop_only' );
			}
		}

		return $total;
	}

	/**
	 * Remove mock free trials from switch cart items.
	 *
	 * @since 2.0.18
	 */
	public static function maybe_unset_free_trial( $total = '' ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) && 0 != $cart_item['subscription_switch']['first_payment_timestamp'] ) {
				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_trial_length', 0, 'set_prop_only' );
			}
		}
		return $total;
	}

	/**
	 * Check if a cart item has a different billing schedule (period and interval) to the subscription being switched.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param array $cart_item
	 * @param WC_Subscription $subscription
	 * @since 2.2.19
	 */
	protected static function has_different_billing_schedule( $cart_item, $subscription ) {
		return WC_Subscriptions_Product::get_period( $cart_item['data'] ) != $subscription->get_billing_period() || WC_Subscriptions_Product::get_interval( $cart_item['data'] ) != $subscription->get_billing_interval();
	}

	/**
	 * Check if a cart item contains a different payment timestamp to the subscription being switched.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param array $cart_item
	 * @param WC_Subscription $subscription
	 * @since 2.2.19
	 */
	protected static function has_different_payment_date( $cart_item, $subscription ) {

		// If there are no more payments due on the subscription, because we're in the last billing period, we need to use the subscription's expiration date, not next payment date
		if ( 0 === ( $next_payment_timestamp = $subscription->get_time( 'next_payment' ) ) ) {
			$next_payment_timestamp = $subscription->get_time( 'end' );
		}

		if ( 0 !== $cart_item['subscription_switch']['first_payment_timestamp'] && $next_payment_timestamp !== $cart_item['subscription_switch']['first_payment_timestamp'] ) {
			$is_different_payment_date = true;
		} elseif ( 0 !== $cart_item['subscription_switch']['first_payment_timestamp'] && 0 === $subscription->get_time( 'next_payment' ) ) { // if the subscription doesn't have a next payment but the switched item does
			$is_different_payment_date = true;
		} else {
			$is_different_payment_date = false;
		}

		return $is_different_payment_date;
	}

	/**
	 * Determine if a recurring cart has a different length (end date) to a subscription.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param WC_Cart $recurring_cart
	 * @param WC_Subscription $subscription
	 * @return bool
	 * @since 2.2.19
	 */
	protected static function has_different_length( $recurring_cart, $subscription ) {
		// @phpstan-ignore property.notFound
		$recurring_cart_end_date = gmdate( 'Y-m-d', wcs_date_to_time( $recurring_cart->end_date ) );
		$subscription_end_date   = gmdate( 'Y-m-d', $subscription->get_time( 'end' ) );

		return $recurring_cart_end_date !== $subscription_end_date;
	}

	/**
	 * Checks if a subscription has a single line item.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param WC_Subscription $subscription
	 * @return bool
	 * @since 2.2.19
	 */
	protected static function is_single_item_subscription( $subscription ) {
		// WC_Abstract_Order::get_item_count() uses quantities, not just line item rows
		return 1 === count( $subscription->get_items() );
	}

	/**
	 * Check if the cart contains a subscription switch which will result in a new subscription being created.
	 *
	 * New subscriptions will be created when:
	 *  - The current subscription has more than 1 line item @see self::is_single_item_subscription() and
	 *  - the recurring cart has a different length @see self::has_different_length() or
	 *  - the switched cart item has a different payment date @see self::has_different_payment_date() or
	 *  - the switched cart item has a different billing schedule @see self::has_different_billing_schedule()
	 *
	 * @return bool
	 * @since 2.2.19
	 */
	public static function cart_contains_subscription_creating_switch() {
		$cart_contains_subscription_creating_switch = false;

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

			foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {

				if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
					continue;
				}

				$subscription = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );

				if ( ! $subscription ) {
					continue;
				}

				if (
					! self::is_single_item_subscription( $subscription ) && (
					self::has_different_length( $recurring_cart, $subscription ) ||
					self::has_different_payment_date( $cart_item, $subscription ) ||
					self::has_different_billing_schedule( $cart_item, $subscription ) )
				) {
					$cart_contains_subscription_creating_switch = true;
					break 2;
				}
			}
		}

		return $cart_contains_subscription_creating_switch;
	}

	/**
	 * Filters the add to cart text for products during a switch request.
	 *
	 * @since 3.1.0
	 *
	 * @param  string $add_to_cart_text The product's default add to cart text.
	 * @return string 'Switch subscription' during a switch, or the default add to cart text if switch args aren't present.
	 */
	public static function display_switch_add_to_cart_text( $add_to_cart_text ) {
		if ( isset( $_GET['switch-subscription'], $_GET['item'] ) ) {
			$add_to_cart_text = _x( 'Switch subscription', 'add to cart button text while switching a subscription', 'woocommerce-subscriptions' );
		}

		return $add_to_cart_text;
	}

	/**
	 * Removes subscription items from recurring carts which have been handled.
	 *
	 * It's possible that after we've processed the subscription switches and removed any recurring carts that shouldn't lead to new subscriptions,
	 * that someone could call WC()->cart->calculate_totals() and that would lead us to recreate all the recurring carts after we've already processed them.
	 *
	 * This method runs after subscription recurring carts have been created and removes any recurring carts which have been handled.
	 *
	 * @param float $total The total amount of the cart.
	 * @return float $total. The total amount of the cart. This is a pass-through method and doesn't modify the total.
	 */
	public static function remove_handled_switch_recurring_carts( $total ) {
		if ( ! isset( WC()->cart->recurring_carts ) ) {
			return $total;
		}

		// We only want to remove the recurring cart if the switch order has been processed.
		if ( ! did_action( 'woocommerce_subscription_checkout_switch_order_processed' ) ) {
			return $total;
		}

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

			// Remove any items from the recurring cart which have been handled.
			foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! isset( $cart_item['subscription_switch'] ) ) {
					continue;
				}

				$subscription = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );

				if ( ! $subscription ) {
					continue;
				}

				$switch_order = $subscription->get_last_order( 'all', 'switch' );

				if ( empty( $switch_order ) ) {
					continue;
				}

				$switch_order_data = wcs_get_objects_property( $switch_order, 'subscription_switch_data' );

				// Skip if the switch order data is not set.
				if ( ! isset( $switch_order_data[ $subscription->get_id() ]['switches'] ) ) {
					continue;
				}

				$subscription_switch_data      = $switch_order_data[ $subscription->get_id() ]['switches'];
				$switched_subscription_item_id = $cart_item['subscription_switch']['item_id'];

				foreach ( $subscription_switch_data as $switch_data ) {

					// We're only interested in cases where there's a straight swap of items. ie there's a remove and an add.
					if ( ! isset( $switch_data['remove_line_item'], $switch_data['add_line_item'] ) ) {
						continue;
					}

					if ( $switch_data['remove_line_item'] === $switched_subscription_item_id ) {
						unset( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ] );
					}
				}
			}

			// If the recurring cart is now empty, remove it.
			if ( empty( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents ) ) {
				unset( WC()->cart->recurring_carts[ $recurring_cart_key ] );
			}
		}

		return $total;
	}
}
