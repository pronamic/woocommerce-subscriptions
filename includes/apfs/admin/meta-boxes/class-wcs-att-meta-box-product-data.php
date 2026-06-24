<?php
/**
 * WCS_ATT_Meta_Box_Product_Data class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product meta-box data for SATT-enabled product types.
 *
 * @class    WCS_ATT_Meta_Box_Product_Data
 * @version  6.0.5
 */
class WCS_ATT_Meta_Box_Product_Data {

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

		// Create the SATT Subscriptions tab.
		add_action( 'woocommerce_product_data_tabs', array( __CLASS__, 'satt_product_data_tab' ) );

		// Create the SATT Subscriptions tab panel.
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_data_panel' ) );

		// Process and save the necessary meta.
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_subscription_data' ), 10, 1 );
	}

	/**
	 * Add Subscriptions tab.
	 *
	 * @param  array $tabs
	 * @return void
	 */
	public static function satt_product_data_tab( $tabs ) {

		$tabs['satt'] = array(
			'label'    => __( 'Subscriptions', 'woocommerce-subscriptions' ),
			'target'   => 'wcsatt_data',
			'priority' => 100,
			'class'    => array( 'cart_subscription_options', 'cart_subscriptions_tab', 'show_if_simple', 'show_if_variable', 'show_if_bundle', 'hide_if_subscription', 'hide_if_variable-subscription' ),
		);

		return $tabs;
	}

	/**
	 * Product writepanel for Subscriptions.
	 *
	 * @return void
	 */
	public static function product_data_panel() {

		global $post, $product_object;

		// Determine the current mode from persisted _wcsatt_schemes_status (with legacy fallback).
		$global_schemes_status    = WCS_ATT_Product::get_subscription_scheme_mode( $product_object );
		$has_subscription_schemes = WCS_ATT_Scheme::MODE_OVERRIDE === $global_schemes_status && WCS_ATT_Product_Schemes::has_subscription_schemes( $product_object, 'local' );

		$classes = 'status_' . $global_schemes_status;

		if ( ! $has_subscription_schemes ) {
			$classes .= ' planless onboarding';
		}

		// Get current schemes for React app (custom plans mode).
		$scheme_meta = $product_object->get_meta( '_wcsatt_schemes', true );
		$scheme_meta = is_array( $scheme_meta ) ? $scheme_meta : array();

		// Add UUID if scheme doesn't have an id (schemas created before APFS consolidation).
		if ( ! empty( $scheme_meta ) ) {
			foreach ( $scheme_meta as $key => $scheme ) {
				if ( empty( $scheme['id'] ) ) {
					$scheme_meta[ $key ]['id'] = wp_generate_uuid4();
				}
			}
		}

		// Get storewide plan selection data (storewide mode).
		$storewide_selection_mode = $product_object->get_meta( '_wcsatt_storewide_selection_mode', true );
		$storewide_selection_mode = ! empty( $storewide_selection_mode ) ? $storewide_selection_mode : 'all';

		$selected_storewide_plans = $product_object->get_meta( '_wcsatt_selected_storewide_plans', true );
		$selected_plans_json      = is_array( $selected_storewide_plans ) && ! empty( $selected_storewide_plans ) ? wp_json_encode( array_values( $selected_storewide_plans ) ) : '[]';

		// Get product type for price override context awareness.
		$product_type = $product_object->get_type();

		// One-time purchase setting (inverted from _wcsatt_force_subscription).
		$allow_one_off = 'yes' === $product_object->get_meta( '_wcsatt_force_subscription', true ) ? 'no' : 'yes';

		// Gifting data for React.
		// Always pass gifting data if the global feature is enabled — React handles
		// product-type visibility dynamically (the user can switch product types in the editor).
		$gifting_globally_enabled = method_exists( WCSG_Admin::class, 'is_gifting_enabled' ) && WCSG_Admin::is_gifting_enabled();
		$product_gifting          = '';
		$gifting_option_text      = '';

		if ( $gifting_globally_enabled ) {
			$product_gifting     = $product_object->get_meta( '_subscription_gifting', true );
			$gifting_option_text = WCSG_Admin::get_gifting_option_text();
		}

		?><div id="wcsatt_data" class="panel wc-metaboxes-wrapper <?php echo esc_attr( $classes ); ?>" style="display:none;">

			<?php // React root — renders the entire Subscriptions panel UI. ?>
			<div
				id="wcsatt-product-plans-root"
				data-product-id="<?php echo esc_attr( $post->ID ); ?>"
				data-product-type="<?php echo esc_attr( $product_type ); ?>"
				data-initial-mode="<?php echo esc_attr( $global_schemes_status ); ?>"
				data-allow-one-off="<?php echo esc_attr( $allow_one_off ); ?>"
				data-storewide-selection-mode="<?php echo esc_attr( $storewide_selection_mode ); ?>"
				data-selected-storewide-plans="<?php echo esc_attr( $selected_plans_json ); ?>"
				data-settings-url="<?php echo esc_url( WCS_ATT()->get_resource_url( 'global-plan-settings' ) ); ?>"
				data-gifting-enabled="<?php echo $gifting_globally_enabled ? 'yes' : 'no'; ?>"
				data-gifting-value="<?php echo esc_attr( $product_gifting ); ?>"
				data-gifting-global-text="<?php echo esc_attr( $gifting_option_text ); ?>"
			></div>

			<?php // Hidden inputs — React manages these values, PHP reads them on save. ?>
			<input type="hidden" id="wcsatt-schemes-status" name="_wcsatt_schemes_status" value="<?php echo esc_attr( $global_schemes_status ); ?>" />
			<input type="hidden" id="wcsatt-allow-one-off" name="_wcsatt_allow_one_off" value="<?php echo esc_attr( $allow_one_off ); ?>" />
<input type="hidden" id="wcsatt-storewide-selection-mode" name="_wcsatt_storewide_selection_mode" value="<?php echo esc_attr( $storewide_selection_mode ); ?>" />
			<input type="hidden" id="wcsatt-selected-storewide-plans" name="_wcsatt_selected_storewide_plans" value="<?php echo esc_attr( $selected_plans_json ); ?>" />
			<?php if ( $gifting_globally_enabled ) : ?>
			<input type="hidden" id="wcsatt-subscription-gifting" name="_wcsatt_subscription_plan_gifting" value="<?php echo esc_attr( $product_gifting ); ?>" />
			<?php endif; ?>

		</div>
		<?php
	}


	/**
	 * Save subscription options.
	 *
	 * @param  WC_Product $product
	 * @return void
	 */
	public static function save_subscription_data( $product ) {

		if ( WCS_ATT_Product::supports_feature( $product, 'subscription_schemes' ) ) {

			// Default to 'disable' (Sell one-time only) for products without existing mode.
			// This matches the admin dropdown default and ensures products default to standard WooCommerce behavior.
			$global_schemes_status = isset( $_POST['_wcsatt_schemes_status'] ) ? wc_clean( wp_unslash( $_POST['_wcsatt_schemes_status'] ) ) : WCS_ATT_Product::get_default_subscription_scheme_mode(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( ! WCS_ATT_Scheme::is_valid_mode( $global_schemes_status ) ) {
				$global_schemes_status = WCS_ATT_Product::get_default_subscription_scheme_mode();
			}
			$schemes = $product->get_meta( '_wcsatt_schemes', true );

			// Process scheme options based on mode.
			if ( WCS_ATT_Scheme::MODE_OVERRIDE === $global_schemes_status ) {
				// Custom plans mode - process custom schemes from React.

				if ( empty( $schemes ) ) {
					$global_schemes_status = WCS_ATT_Scheme::MODE_DISABLE;
					WC_Admin_Meta_Boxes::add_error( __( 'To make this product available on subscription, you must add at least one custom subscription plan when overriding the global plan settings. You did not add any plans, or a server error prevented them from being saved. This product is now available for one-time purchase only.', 'woocommerce-subscriptions' ) );
				}
			} elseif ( WCS_ATT_Scheme::MODE_INHERIT === $global_schemes_status ) {
				// Storewide plans mode - process storewide plan selection.

				// Process storewide selection mode.
				$storewide_selection_mode = isset( $_POST['_wcsatt_storewide_selection_mode'] ) ? wc_clean( wp_unslash( $_POST['_wcsatt_storewide_selection_mode'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

				// Validate selection mode.
				if ( ! in_array( $storewide_selection_mode, array( 'all', 'specific' ), true ) ) {
					$storewide_selection_mode = 'all';
				}

				// Save selection mode.
				$product->update_meta_data( '_wcsatt_storewide_selection_mode', $storewide_selection_mode );

				// Process selected plan IDs (only for specific mode).
				if ( 'specific' === $storewide_selection_mode ) {
					if ( isset( $_POST['_wcsatt_selected_storewide_plans'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
						$selected_plans_json = wc_clean( wp_unslash( $_POST['_wcsatt_selected_storewide_plans'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

						try {
							$selected_plan_ids = json_decode( $selected_plans_json, true );

							if ( json_last_error() !== JSON_ERROR_NONE ) {
								throw new Exception( 'Invalid JSON in selected storewide plans data' );
							}

							if ( ! is_array( $selected_plan_ids ) ) {
								$selected_plan_ids = array();
							}

							// Validate that selected plans still exist.
							$storewide_plans = get_option( 'wcsatt_subscribe_to_cart_schemes', array() );
							$valid_plan_ids  = array();

							foreach ( $selected_plan_ids as $plan_id ) {
								// Check if plan still exists.
								$plan_exists = false;
								foreach ( $storewide_plans as $plan ) {
									if ( isset( $plan['id'] ) && $plan['id'] === $plan_id ) {
										$plan_exists = true;
										break;
									}
								}

								if ( $plan_exists ) {
									$valid_plan_ids[] = $plan_id;
								}
							}

							// Show warning if some plans were removed.
							if ( count( $valid_plan_ids ) < count( $selected_plan_ids ) ) {
								WCS_ATT_Admin_Notices::add_notice( __( 'Some selected storewide plans no longer exist and were removed from the selection.', 'woocommerce-subscriptions' ), 'warning', true );
							}

							// Save valid plan IDs.
							if ( ! empty( $valid_plan_ids ) ) {
								$product->update_meta_data( '_wcsatt_selected_storewide_plans', $valid_plan_ids );
							} else {
								WCS_ATT_Admin_Notices::add_notice(
									__( 'No storewide plans were selected. Switched to "Use all storewide subscription plans" mode.', 'woocommerce-subscriptions' ),
									array(
										'type'          => 'info',
										'dismiss_class' => 'no_plans_selected',
									),
									true
								);
								$product->update_meta_data( '_wcsatt_storewide_selection_mode', 'all' );
								$product->delete_meta_data( '_wcsatt_selected_storewide_plans' );
							}
						} catch ( Exception $e ) {
							// translators: %s is the error message.
							WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Error parsing selected storewide plans: %s', 'woocommerce-subscriptions' ), $e->getMessage() ) );
							$product->delete_meta_data( '_wcsatt_selected_storewide_plans' );
						}
					} else {
						// No plan data submitted in specific mode — fall back to all.
						WCS_ATT_Admin_Notices::add_notice(
							__( 'No storewide plans were selected. Switched to "Use all storewide subscription plans" mode.', 'woocommerce-subscriptions' ),
							array(
								'type'          => 'info',
								'dismiss_class' => 'no_plans_selected',
							),
							true
						);
						$product->update_meta_data( '_wcsatt_storewide_selection_mode', 'all' );
						$product->delete_meta_data( '_wcsatt_selected_storewide_plans' );
					}
				} else {
					// In "all" mode, no plan IDs need to be stored.
					$product->delete_meta_data( '_wcsatt_selected_storewide_plans' );
				}
			}

			// Persist the mode as the single source of truth. Also handles _wcsatt_disabled
			// for backward compatibility.
			WCS_ATT_Product::set_subscription_scheme_mode( $product, $global_schemes_status );

			// Process Advanced Settings for both custom plans and global plans modes.
			// Advanced Settings should work when product has custom schemes OR is using global plans.
			$has_plans = ! empty( $schemes ) || WCS_ATT_Scheme::MODE_INHERIT === $global_schemes_status;

			// Process one-time shipping option.
			$one_time_shipping = isset( $_POST['_subscription_one_time_shipping'] ) ? 'yes' : 'no';

			// Process force-sub status.
			// Hidden input sends 'yes' when one-off is allowed, '' when not (React manages this value).
			$allow_one_off      = isset( $_POST['_wcsatt_allow_one_off'] ) ? wc_clean( wp_unslash( $_POST['_wcsatt_allow_one_off'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$force_subscription = $has_plans && 'yes' !== $allow_one_off ? 'yes' : 'no';

			// Process prompt text.
			$prompt = $has_plans && ! empty( $_POST['_wcsatt_subscription_prompt'] ) ? wp_kses_post( wp_unslash( $_POST['_wcsatt_subscription_prompt'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			/*
			 * Add/update meta.
			 */

			// Save scheme options (custom mode only).
			if ( ! empty( $schemes ) && WCS_ATT_Scheme::MODE_OVERRIDE === $global_schemes_status ) {

				$product->update_meta_data( '_wcsatt_schemes', array_values( $schemes ) );

				// Set regular price to zero should the shop owner forget.
				if ( 'yes' === $force_subscription && empty( $_POST['_regular_price'] ) ) {
					$product->set_regular_price( 0 );
					$product->set_price( 0 );
				}
			}

			// Save one-time shipping option.
			$product->update_meta_data( '_subscription_one_time_shipping', $one_time_shipping );

			// Save force-sub status.
			$product->update_meta_data( '_wcsatt_force_subscription', $force_subscription );

			// Save prompt.
			if ( false === $prompt ) {
				$product->delete_meta_data( '_wcsatt_subscription_prompt' );
			} else {
				$product->update_meta_data( '_wcsatt_subscription_prompt', $prompt );
			}

			// Save gifting option from APFS subscription plans panel.
			if ( isset( $_POST['_wcsatt_subscription_plan_gifting'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$gifting_value = wc_clean( wp_unslash( $_POST['_wcsatt_subscription_plan_gifting'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( '' === $gifting_value ) {
					$product->delete_meta_data( '_subscription_gifting' );
				} else {
					$product->update_meta_data( '_subscription_gifting', $gifting_value );
				}
			}
		} else {

			$product->delete_meta_data( '_wcsatt_schemes' );
			$product->delete_meta_data( '_wcsatt_schemes_status' );
			$product->delete_meta_data( '_wcsatt_disabled' );
			$product->delete_meta_data( '_wcsatt_force_subscription' );
			$product->delete_meta_data( '_wcsatt_default_status' );
			$product->delete_meta_data( '_wcsatt_subscription_prompt' );
			$product->delete_meta_data( '_wcsatt_storewide_selection_mode' );
			$product->delete_meta_data( '_wcsatt_selected_storewide_plans' );
		}
	}
}
