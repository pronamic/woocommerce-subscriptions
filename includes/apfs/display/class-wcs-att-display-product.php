<?php
/**
 * WCS_ATT_Display_Product class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single-product template modifications.
 *
 * @class    WCS_ATT_Display_Product
 * @version  4.1.0
 */
class WCS_ATT_Display_Product {

	/**
	 * Initialization.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Single-product display hooks.
	 */
	private static function add_hooks() {

		// Display subscription options in the single-product template.
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'show_subscription_options' ), 100 );

		// When there's a single forced plan the options selector is hidden, so render the trial/sign-up fee detail lines
		// below the price instead — matching where legacy subscription products show them.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'show_single_plan_price_details' ), 11 );

		// Changes the single-product add-to-cart button text when a product with the force subscription is set.
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'single_add_to_cart_text' ), 10, 2 );

		// Changes the shop button text when a product has subscription options.
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'add_to_cart_text' ), 10, 2 );

		// Changes the shop button action when a product has subscription options.
		add_filter( 'woocommerce_product_add_to_cart_url', array( __CLASS__, 'add_to_cart_url' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( __CLASS__, 'supports_ajax_add_to_cart' ), 10, 3 );

		// Replace plain variation price html with subscription options template.
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'add_subscription_options_to_variation_data' ), 1, 3 );

		// Add product page class if a product has subscription plans.
		add_filter( 'post_class', array( __CLASS__, 'add_product_class' ), 10, 3 );
	}

	/**
	 * Options for purchasing a product once or creating a subscription from it.
	 *
	 * @param  WC_Product      $product
	 * @param  WC_Product|null $parent_product
	 * @return void
	 */
	public static function get_subscription_options_content( $product, $parent_product = null ) {

		if ( ! WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_options_product_single' ) ) {
			return '';
		}

		/*
		 * Subscription options for variable products are embedded inside the variation data 'price_html' field and updated by the core variations script.
		 */
		if ( $product->is_type( 'variable' ) ) {
			if ( self::modify_variation_data_price_html( $product ) ) {
				return '';
			} else {
				return '<div class="wcsatt-options-wrapper wcsatt-options-wrapper--variation"></div>';
			}
		}

		$product_id                      = WCS_ATT_Core_Compatibility::get_product_id( $product );
		$subscription_schemes            = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
		$base_scheme                     = WCS_ATT_Product_Schemes::get_base_subscription_scheme( $product );
		$force_subscription              = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product );
		$default_subscription_scheme_key = WCS_ATT_Product_Schemes::get_default_subscription_scheme( $product, 'key' );
		$posted_subscription_scheme_key  = WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $product_id );
		$options                         = array();
		$layout                          = self::get_subscription_options_layout( $parent_product ? $parent_product : $product );
		$display_dropdown                = 'grouped' === $layout;

		// Filter default key.
		$default_subscription_scheme_key = apply_filters( 'wcsatt_get_default_subscription_scheme_id', $default_subscription_scheme_key, $subscription_schemes, false === $force_subscription, $product ); // Why 'false === $force_subscription'? The answer is back-compat.

		// Option selected by default.
		if ( null !== $posted_subscription_scheme_key ) {
			$default_subscription_scheme_key = $posted_subscription_scheme_key;
		}

		$default_subscription_scheme_option_value = WCS_ATT_Product_Schemes::stringify_subscription_scheme_key( $default_subscription_scheme_key );

		// Non-recurring (one-time) option.
		if ( false === $force_subscription ) {

			$none_option_description = _x( 'one time', 'product subscription selection - negative response', 'woocommerce-subscriptions' );
			$none_option_has_price   = apply_filters( 'wcsatt_single_product_one_time_option_has_price', false, $product, $parent_product );

			if ( $none_option_has_price ) {
				// translators: %s is the price html.
				$none_option_description = sprintf( _x( '%s one time', 'product subscription selection - negative response', 'woocommerce-subscriptions' ), WCS_ATT_Product_Prices::get_price_html( $product, false, array() ) );
			}

			$none_option_price_class = $none_option_has_price ? 'price' : 'no-price';

			$options[] = array(
				'class'       => 'one-time-option',
				'description' => apply_filters( 'wcsatt_single_product_one_time_option_description', '<span class="' . $none_option_price_class . ' one-time-price">' . $none_option_description . '</span>', $product ),
				'value'       => '0',
				'selected'    => '0' === $default_subscription_scheme_option_value,
				'data'        => apply_filters( 'wcsatt_single_product_one_time_option_data', array(), $product, $parent_product ),
			);
		}

		// Subscription options.
		foreach ( $subscription_schemes as $subscription_scheme ) {

			$option_price_html_args = array(
				'context'         => 'radio',
				'append_discount' => true,
			);

			$scheme_key       = $subscription_scheme->get_key();
			$is_base_scheme   = $base_scheme->get_key() === $scheme_key;
			$has_price_filter = $subscription_scheme->has_price_filter();

			/**
			 * 'wcsatt_single_product_subscription_option_price_html_args' filter
			 *
			 * Use this filter to override subscription plan price strings.
			 *
			 * For example, add [ 'append_discount' => true ] to append discounts to plan prices.
			 *
			 * @param  array            $option_price_html_args
			 * @param  WCS_ATT_Scheme   $subscription_scheme
			 * @param  WC_Product       $product
			 * @param  WC_Product|null  $parent_product
			 */
			$option_price_html_args = apply_filters( 'wcsatt_single_product_subscription_option_price_html_args', $option_price_html_args, $subscription_scheme, $product, $parent_product );

			$is_nyp = class_exists( 'WCS_ATT_Integration_NYP' ) && class_exists( 'WC_Name_Your_Price_Helpers' ) && WC_Name_Your_Price_Helpers::is_nyp( $product );

			if ( $is_nyp ) {
				WCS_ATT_Integration_NYP::before_subscription_option_get_price_html();
			}

			// Get price.
			$sub_price_html = WCS_ATT_Product_Prices::get_price_html( $product, $scheme_key, $option_price_html_args );

			if ( $is_nyp ) {
				WCS_ATT_Integration_NYP::after_subscription_option_get_price_html();
			}

			$trial_length = $subscription_scheme->get_trial_length();
			$trial_period = $subscription_scheme->get_trial_period();
			$signup_fee   = $subscription_scheme->get_signup_fee();

			$option_data = array(
				'discount_from_regular' => apply_filters( 'wcsatt_discount_from_regular', false ),
				'option_has_price'      => false,
				'subscription_scheme'   => array_merge(
					$subscription_scheme->get_data(),
					array(
						'is_prorated'          => WCS_ATT_Sync::is_first_payment_prorated( $product, $scheme_key ),
						'is_base'              => $is_base_scheme,
						'has_price_filter'     => $has_price_filter,
						'trial_label'          => self::get_trial_length_label( $trial_length, $trial_period ),
						'signup_fee_formatted' => $signup_fee > 0 ? wp_strip_all_tags( wc_price( self::get_display_signup_fee( $signup_fee, $product ) ) ) : '',
					)
				),
			);

			// Dropdown price strings need special handling, as html is not allowed.
			if ( $display_dropdown ) {

				$dropdown_option_price_html_args = apply_filters(
					'wcsatt_single_product_subscription_dropdown_option_price_html_args',
					array(
						'context'      => 'dropdown',
						'price'        => '%p',
						'append_price' => false === $force_subscription,
						'hide_price'   => $subscription_scheme->get_length() > 0 && false === $force_subscription, // "Deliver every month for 6 months for $8.00 (10% off)" is just too confusing, isn't it?
						'sign_up_fee'  => false, // Trial and sign-up fee details are shown in dedicated detail lines below the dropdown.
						'trial_length' => false,
					),
					$subscription_scheme,
					$product,
					$parent_product
				);

				$option_data['dropdown_details_html'] = WCS_ATT_Product_Prices::get_price_html( $product, $subscription_scheme->get_key(), $dropdown_option_price_html_args );
			}

			$option_data = apply_filters( 'wcsatt_single_product_subscription_option_data', $option_data, $subscription_scheme, $product, $parent_product );

			$option_has_price   = $option_data['option_has_price'] || false !== strpos( $sub_price_html, 'amount' );
			$option_price_class = $option_has_price ? 'price' : 'no-price';

			$option_description = apply_filters( 'wcsatt_single_product_subscription_option_description', '<span class="' . $option_price_class . ' subscription-price">' . $sub_price_html . '</span>', $sub_price_html, $has_price_filter, false === $force_subscription, $product, $subscription_scheme );

			$option = array(
				'class'       => 'subscription-option',
				'value'       => $scheme_key,
				'selected'    => $default_subscription_scheme_option_value === $scheme_key,
				'description' => $option_description,
				'data'        => $option_data,
			);

			// Now that all data has been filtered, create dropdown descriptions.
			if ( $display_dropdown ) {
				$option['dropdown'] = self::format_subscription_options_dropdown_description( $option['data']['dropdown_details_html'], $product, $subscription_scheme, $dropdown_option_price_html_args );
			}

			$options[] = $option;
		}

		/**
		 * 'wcsatt_single_product_options' filter.
		 *
		 * @param  array       $options
		 * @param  array       $subscription_schemes
		 * @param  WC_Product  $product
		 */
		$options      = apply_filters( 'wcsatt_single_product_options', $options, $subscription_schemes, $product );
		$options_html = '';

		// Anything to display?
		if ( count( $options ) > 0 ) {

			/*
			 * When the "grouped" layout is active and one-time purchases are allowed, a "purchase one-time or subscribe" prompt needs to be displayed above the subscription plans.
			 * By default, this prompt is a pair of radio inputs, but it's also possible to have a checkbox there.
			 */
			$prompt_type     = 'grouped' === $layout && false === $force_subscription ? 'radio' : 'text';
			$prompt          = '';
			$text            = self::get_subscription_options_prompt_text( $parent_product ? $parent_product : $product );
			$wrapper_classes = array();
			$prompt_classes  = array();

			/**
			 * 'wcsatt_grouped_layout_prompt_type' filter.
			 *
			 * Accepted values: 'radio', 'checkbox'.
			 *
			 * @since  APFS 3.0.0
			 *
			 * @param  string      $type
			 * @param  WC_Product  $product
			 */
			$prompt_type = apply_filters( 'wcsatt_grouped_layout_prompt_type', $prompt_type, $product );

			if ( $text ) {

				ob_start();

				wc_get_template(
					'single-product/product-subscription-options-prompt-text.php',
					array(
						'text' => $text,
					),
					false,
					plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
				);

				$prompt = ob_get_clean();
			}

			$hide_options = false;

			if ( 'grouped' === $layout ) {
				$hide_options = count( $subscription_schemes ) === 1 || '0' === $default_subscription_scheme_option_value;
			} else {
				$hide_options = count( $options ) === 1;
			}

			$wrapper_classes[] = 'wcsatt-options-wrapper-' . $layout;
			$wrapper_classes[] = 'wcsatt-options-wrapper-' . $prompt_type;
			$wrapper_classes[] = $hide_options ? 'closed' : 'open';
			$wrapper_classes[] = $product->is_type( 'variation' ) && ! self::modify_variation_data_price_html( $parent_product ) ? 'wcsatt-options-wrapper--variation' : '';

			if ( in_array( $prompt_type, array( 'radio', 'checkbox' ) ) ) {

				$prompt_html_args = array(
					'context' => 'prompt',
				);

				if ( 'checkbox' === $prompt_type ) {
					$prompt_html_args['subscribe_options_html'] = _x( 'Choose a subscription plan', 'Subscribe call-to-action - checkbox', 'woocommerce-subscriptions' );
				}

				/**
				 * 'wcsatt_single_product_subscription_prompt_html_args' filter
				 *
				 * Use this filter to modify the format of the subscription prompt price string.
				 *
				 * For example, add [ 'allow_discount' => false ] to always display the base plan price instead of the max discount.
				 *
				 * @since  APFS 3.0.0
				 *
				 * @param  array            $option_price_html_args
				 * @param  array            $options
				 * @param  WC_Product       $product
				 * @param  WC_Product|null  $parent_product
				 */
				$prompt_html_args = apply_filters( 'wcsatt_single_product_subscription_prompt_html_args', $prompt_html_args, $options, $product, $parent_product );

				if ( 'radio' === $prompt_type ) {

					$subscription_cta        = WCS_ATT_Product_Prices::get_price_html( $product, null, $prompt_html_args );
					$one_time_cta            = __( 'One-time purchase', 'woocommerce-subscriptions' );
					$once_time_cta_has_price = apply_filters( 'wcsatt_single_product_one_time_cta_has_price', false, $product, $parent_product );

					if ( $once_time_cta_has_price ) {
						// translators: %s is the price html.
						$one_time_cta = sprintf( __( 'One-time for %s', 'woocommerce-subscriptions' ), '<span class="price one-time-price">' . WCS_ATT_Product_Prices::get_price_html( $product, false, array() ) . '</span>' );
					}

					ob_start();

					wc_get_template(
						'single-product/product-subscription-options-prompt-radio.php',
						array(
							'one_time_cta'     => $one_time_cta,
							'subscription_cta' => $subscription_cta,
						),
						false,
						plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
					);

					$prompt .= ob_get_clean();

				} elseif ( 'checkbox' === $prompt_type ) {

					$cta = WCS_ATT_Product_Prices::get_price_html( $product, null, $prompt_html_args );

					ob_start();

					wc_get_template(
						'single-product/product-subscription-options-prompt-checkbox.php',
						array(
							'cta' => $cta,
						),
						false,
						plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
					);

					$prompt .= ob_get_clean();
				}
			}

			$prompt_classes[] = 'wcsatt-options-product-prompt-' . $layout;
			$prompt_classes[] = 'wcsatt-options-product-prompt-' . $prompt_type;
			$prompt_classes[] = 'wcsatt-options-product-prompt--' . ( $prompt ? 'visible' : 'hidden' );

			// Hide options wrapper?
			$hide_wrapper = false;

			if ( count( $options ) === 1 ) {
				$hide_wrapper = true;
			} elseif ( $product->is_type( 'bundle' ) ) {
				if ( method_exists( $product, 'get_bundle_form_data' ) ) {
					$form_data    = $product->get_bundle_form_data();
					$hide_wrapper = 'yes' === $form_data['hide_total_on_validation_fail'];
				} else {
					$hide_wrapper = $product->requires_input();
				}
			} elseif ( $product->is_type( 'composite' ) ) {
				$form_data    = $product->add_to_cart_form_settings();
				$hide_wrapper = ! isset( $form_data['hide_total_on_validation_fail'] ) || 'yes' === $form_data['hide_total_on_validation_fail'];
			}

			// Determine initial detail-line values from the selected scheme.
			$initial_trial_length = 0;
			$initial_trial_period = 'day';
			$initial_signup_fee   = 0;
			$selected_scheme      = $default_subscription_scheme_key && isset( $subscription_schemes[ $default_subscription_scheme_key ] ) ? $subscription_schemes[ $default_subscription_scheme_key ] : $base_scheme;

			if ( $selected_scheme ) {
				$initial_trial_length = $selected_scheme->get_trial_length();
				$initial_trial_period = $selected_scheme->get_trial_period();
				$initial_signup_fee   = self::get_display_signup_fee( $selected_scheme->get_signup_fee(), $product );
			}

			ob_start();

			wc_get_template(
				'single-product/product-subscription-options.php',
				array(
					'layout'           => $layout,
					'product'          => $product,
					'product_id'       => $product_id,
					'options'          => $options,
					'prompt'           => $prompt,
					'prompt_type'      => $prompt_type,
					'prompt_classes'   => $prompt_classes,
					'wrapper_classes'  => $wrapper_classes,
					'display_dropdown' => $display_dropdown,
					'allow_one_time'   => false === $force_subscription,
					'sign_up_text'     => self::get_subscription_options_button_text( $parent_product ? $parent_product : $product ),
					'dropdown_label'   => $force_subscription ? '' : self::get_subscription_options_dropdown_label( $product ),
					'hide_wrapper'     => $hide_wrapper,
					'trial_length'     => $initial_trial_length,
					'trial_period'     => $initial_trial_period,
					'trial_label'      => self::get_trial_length_label( $initial_trial_length, $initial_trial_period ),
					'signup_fee'       => $initial_signup_fee,
				),
				false,
				plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
			);

			$options_html = ob_get_clean();
		}

		return $options_html;
	}

	/**
	 * Returns the signup fee adjusted for the store's tax display setting.
	 *
	 * @since  9.0.0
	 *
	 * @param  float      $signup_fee Raw signup fee.
	 * @param  WC_Product $product    Product (needed for tax class context).
	 * @return float
	 */
	private static function get_display_signup_fee( $signup_fee, $product ) {

		if ( $signup_fee <= 0 ) {
			return $signup_fee;
		}

		$args = array(
			'qty'   => 1,
			'price' => $signup_fee,
		);

		if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
			return wc_get_price_including_tax( $product, $args );
		}

		return wc_get_price_excluding_tax( $product, $args );
	}

	/**
	 * Returns the human-readable trial length label for the "Free trial:" detail line, e.g. "1 week" or "7 days".
	 *
	 * wcs_get_subscription_period_strings() returns only the singular period name (e.g. "week") for a length of 1,
	 * so the count is prepended in that case to avoid a label that reads "Free trial: week".
	 *
	 * @since  9.0.0
	 *
	 * @param  int    $trial_length Trial length.
	 * @param  string $trial_period Trial period (day, week, month, year).
	 * @return string Empty string when there is no trial.
	 */
	public static function get_trial_length_label( $trial_length, $trial_period ) {
		return wcs_get_subscription_trial_length_label( $trial_length, $trial_period );
	}

	/**
	 * Formats a dropdown description by cleaning up an html price string and replacing the price in a placeholder.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  string         $dropdown_details_html
	 * @param  WC_Product     $product
	 * @param  WCS_ATT_Scheme $subscription_scheme
	 * @param  array          $args
	 * @return string
	 */
	public static function format_subscription_options_dropdown_description( $dropdown_details_html, $product, $subscription_scheme, $args = array() ) {

		$formatted_discount = isset( $args['allow_discount'] ) && false === $args['allow_discount'] ? '' : WCS_ATT_Product_Prices::get_formatted_discount( $product, $subscription_scheme );
		$dropdown_price     = WCS_ATT_Product_Prices::get_formatted_price(
			wc_get_price_to_display(
				$product,
				array(
					'price' => WCS_ATT_Product_Prices::get_price( $product, $subscription_scheme->get_key() ),
				)
			)
		);
		$dropdown_details   = ucfirst( trim( wp_kses( $dropdown_details_html, array() ) ) );
		// translators: %1$s is the price html, %2$s is the formatted discount.
		$dropdown_description_string = $formatted_discount ? _x( '%1$s (%2$s off)', 'discounted dropdown option price', 'woocommerce-subscriptions' ) : '%s';

		return sprintf( $dropdown_description_string, str_replace( '%p', $dropdown_price, $dropdown_details ), $formatted_discount );
	}

	/**
	 * Controls where variation subscription scheme options will be rendered: In the variation data array's 'price_html' key, or before the add to cart button.
	 *
	 * @since  APFS 2.3.1
	 *
	 * @param  WC_Product_Variable $variable_product
	 * @return bool
	 */
	protected static function modify_variation_data_price_html( $variable_product ) {
		return apply_filters( 'wcsatt_modify_variation_data_price_html', true, $variable_product );
	}

	/**
	 * Label for subscription plans dropdown.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  WC_Product $product
	 * @return string
	 */
	protected static function get_subscription_options_dropdown_label( $product ) {

		$label = __( 'Deliver:', 'woocommerce-subscriptions' );

		if ( ! $product->needs_shipping() ) {

			$label = __( 'Renew:', 'woocommerce-subscriptions' );

			if ( $product->is_type( array( 'bundle', 'composite' ) ) ) {
				$label = __( 'Choose a plan:', 'woocommerce-subscriptions' );
			}
		}

		return apply_filters( 'wcsatt_single_product_subscription_options_label', $label, $product );
	}

	/**
	 * Returns the subscription options text prompt.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function get_subscription_options_prompt_text( $product ) {

		// Check product-level prompt text FIRST, regardless of scheme source.
		// Fall back to global setting only if product prompt is empty.
		$text = $product->get_meta( '_wcsatt_subscription_prompt', true );
		if ( empty( $text ) ) {
			$text = get_option( 'wcsatt_subscribe_to_cart_prompt', false );
		}

		$allow_one_time = false === WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product );

		/*
		 * Display default text when:
		 *
		 * - the "flat" layout is selected, OR
		 * - the "grouped" layout is selected and one-time purchases are not allowed.
		 */
		if ( empty( $text ) && ( 'flat' === self::get_subscription_options_layout( $product ) || false === $allow_one_time ) ) {

			$text = $allow_one_time ? __( 'Choose a purchase plan:', 'woocommerce-subscriptions' ) : __( 'Choose a subscription plan:', 'woocommerce-subscriptions' );
			$text = apply_filters( 'wcsatt_default_prompt_text', '<span class="wcsatt-options-prompt-text-label">' . $text . '</span>', $product );

		} elseif ( ! empty( $text ) ) {

			$clean_text    = do_shortcode( wp_kses_post( $text ) );
			$stripped_text = wp_strip_all_tags( $clean_text );

			if ( $clean_text === $stripped_text ) {
				$text = '<span class="wcsatt-options-prompt-text-label">' . $clean_text . '</span>';
			} else {
				$text = wpautop( $clean_text );
			}
		}

		return wptexturize( $text );
	}

	/**
	 * Returns the subscription options layout.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function get_subscription_options_layout( $product ) {

		$layout = 'grouped';

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product, 'local' ) ) {
			$product_layout = $product->get_meta( '_wcsatt_layout', true );
			$layout         = in_array( $product_layout, array( 'flat', 'grouped' ), true ) ? $product_layout : 'grouped';
		}

		/**
		 * Filter plan options template.
		 *
		 * @since  APFS 4.0.0
		 *
		 * @param  string      $layout
		 * @param  WC_Product  $product
		 */
		return apply_filters( 'wcsatt_subscription_options_layout', $layout, $product );
	}

	/**
	 * Return add-to-cart button replacement text when choosing a subscription plan.
	 * Returns null if the text should not be modified.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  WC_Product $product
	 * @return string|null
	 */
	public static function get_subscription_options_button_text( $product ) {

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {

			$button_text = null;

			if ( $product->is_type( 'bundle' ) && isset( $_GET['update-bundle'] ) ) {
				$updating_cart_key = wc_clean( $_GET['update-bundle'] );
				if ( isset( WC()->cart->cart_contents[ $updating_cart_key ] ) ) {
					return $button_text;
				}
			} elseif ( $product->is_type( 'composite' ) && isset( $_GET['update-composite'] ) ) {
				$updating_cart_key = wc_clean( $_GET['update-composite'] );
				if ( isset( WC()->cart->cart_contents[ $updating_cart_key ] ) ) {
					return $button_text;
				}
			}

			$button_text = get_option( WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text', __( 'Sign up', 'woocommerce-subscriptions' ) );

			return apply_filters( 'wcsatt_single_add_to_cart_text', $button_text, $product );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Filters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Replace plain variation price html with subscription options template.
	 * Subscription options are updated by the core variations script when a variation is selected.
	 *
	 * @param  array                $variation_data
	 * @param  WC_Product_Variable  $variable_product
	 * @param  WC_Product_Variation $variation_product
	 * @return array
	 */
	public static function add_subscription_options_to_variation_data( $variation_data, $variable_product, $variation_product ) {

		global $product;

		$is_current_product = false;

		if ( is_object( $product ) && is_a( $product, 'WC_Product' ) && ! doing_action( 'wc_ajax_woocommerce_show_composited_product' ) ) {
			$is_current_product = $variable_product->get_id() === $product->get_id();
		} elseif ( doing_action( 'wc_ajax_get_variation' ) ) {
			$is_current_product = true;
		}

		if ( ! $is_current_product ) {
			return $variation_data;
		}

		if ( $subscription_options_content = self::get_subscription_options_content( $variation_product, $variable_product ) ) {

			$modify_variation_data_price_html     = self::modify_variation_data_price_html( $variable_product );
			$subscription_schemes                 = WCS_ATT_Product_Schemes::get_subscription_schemes( $variation_product );
			$force_subscription                   = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $variation_product );
			$price_filter_exists                  = WCS_ATT_Product_Schemes::price_filter_exists( $subscription_schemes );
			$is_single_scheme_forced_subscription = $force_subscription && count( $subscription_schemes ) === 1;
			$has_equal_variation_prices           = '' === $variation_data['price_html'];

			/*
			 * When should we keep the existing price string?
			 *
			 * - When dealing with a single-scheme, force-subscription case (non-empty price string with subscription details).
			 * - When no scheme overrides the original variation price and all variation prices are equal and hidden (empty price string).
			 */
			if ( $is_single_scheme_forced_subscription || ( false === $price_filter_exists && $has_equal_variation_prices ) ) {

				if ( $modify_variation_data_price_html ) {
					$variation_data['price_html'] = $variation_data['price_html'] . $subscription_options_content;
				} else {
					$variation_data['satt_price_html']   = $variation_data['price_html'];
					$variation_data['satt_options_html'] = $subscription_options_content;
				}
			} else {

				/*
				 * At this point, the variation price string will include subscription details because it has been filtered by 'WCS_ATT_Product_Prices::get_price_html'.
				 * We need to generate the original, subscription-less price string by temporarily removing APFS price filters.
				 */

				if ( $force_subscription ) {
					WCS_ATT_Product_Schemes::set_forced_subscription_scheme( $variation_product, false );
				}

				// Remove APFS price and price_html filters to get the original price string.
				WCS_ATT_Product_Price_Filters::remove();
				$price_html = $variation_product->get_price_html();
				WCS_ATT_Product_Price_Filters::add();

				$variation_data['price_html'] = '<span class="price">' . $price_html . '</span>';

				if ( $modify_variation_data_price_html ) {
					$variation_data['price_html'] .= $subscription_options_content;
				} else {
					$variation_data['satt_price_html']   = $variation_data['price_html'];
					$variation_data['satt_options_html'] = $subscription_options_content;
				}

				if ( $force_subscription ) {
					WCS_ATT_Product_Schemes::set_forced_subscription_scheme( $variation_product, true );
				}
			}
		}

		return $variation_data;
	}

	/**
	 * Displays single-product options for purchasing a product once or creating a subscription from it.
	 *
	 * @return void
	 */
	public static function show_subscription_options() {

		global $product;

		// Include the SATT script in footer.
		wp_enqueue_script( 'wcsatt-single-product' );

		// At this point, we're echoing template parts that can be overriden by themes.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::get_subscription_options_content( $product );
	}

	/**
	 * Renders the trial and sign-up fee detail lines below the price for products with a single forced plan.
	 *
	 * With a single forced plan there is nothing to choose, so the options selector (and its detail lines) is hidden.
	 * This outputs the detail lines just below the price instead — the same placement legacy subscription products use
	 * (@see WC_Subscriptions_Product::output_subscription_price_details()) — so both look identical.
	 *
	 * @since 9.0.0
	 *
	 * @return void
	 */
	public static function show_single_plan_price_details() {

		global $product;

		if ( ! is_a( $product, 'WC_Product' ) || $product->is_type( 'variable' ) || ! WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			return;
		}

		$schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );

		// Only when the selector is hidden because there's a single forced plan; otherwise the selector (with its own
		// detail lines) is shown.
		if ( count( $schemes ) !== 1 || ! WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product ) ) {
			return;
		}

		$scheme       = reset( $schemes );
		$trial_label  = self::get_trial_length_label( $scheme->get_trial_length(), $scheme->get_trial_period() );
		$signup_fee   = $scheme->get_signup_fee();
		$details_html = '';

		if ( '' !== $trial_label ) {
			/* translators: %s: trial length string, e.g. "30 days" or "1 week" */
			$details_html .= '<p class="woocommerce-subscriptions-product-details__trial">' . esc_html( sprintf( __( 'Free trial: %s', 'woocommerce-subscriptions' ), $trial_label ) ) . '</p>';
		}

		if ( $signup_fee > 0 ) {
			/* translators: %s: formatted sign-up fee amount */
			$details_html .= '<p class="woocommerce-subscriptions-product-details__signup-fee">' . wp_kses_post( sprintf( __( 'Sign-up fee: %s', 'woocommerce-subscriptions' ), wc_price( self::get_display_signup_fee( $signup_fee, $product ) ) ) ) . '</p>';
		}

		if ( '' === $details_html ) {
			return;
		}

		// $details_html is escaped per-line above.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="woocommerce-subscriptions-product-details" aria-live="polite" aria-atomic="true">' . $details_html . '</div>';
	}

	/**
	 * Overrides the single-product add-to-cart button text with "Sign up".
	 *
	 * @since  APFS 1.1.1
	 *
	 * @param  string     $button_text
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function single_add_to_cart_text( $button_text, $product ) {

		if ( WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product ) ) {

			$text        = self::get_subscription_options_button_text( $product );
			$button_text = $text ? $text : $button_text;
		}

		return $button_text;
	}

	/**
	 * Whether to prompt users to choose a plan in the catalog.
	 *
	 * @since  APFS 4.0.0
	 *
	 * @param  WC_Product $product
	 */
	public static function prompt_plan_selection_in_catalog( $product ) {

		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return false;
		}

		$prompt = false;

		if ( ! $product->has_options() && WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			// Modify button/text if one-time purchases are not allowed and the product has multiple plans.
			if ( WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product ) && count( WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) ) > 1 ) {
				$prompt = true;
			}
		}

		return apply_filters( 'wcsatt_prompt_plan_selection_in_catalog', $prompt, $product );
	}

	/**
	 * Changes the shop add-to-cart button text when a product has subscription options.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  string     $button_text
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function add_to_cart_text( $button_text, $product ) {

		if ( self::prompt_plan_selection_in_catalog( $product ) ) {

			$button_text = apply_filters( 'wcsatt_add_to_cart_text', __( 'Select plan', 'woocommerce-subscriptions' ), $product );

		} elseif ( ! $product->has_options() && WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) && WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product ) ) {
			$button_text = apply_filters( 'wcsatt_add_to_cart_text', get_option( WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text', __( 'Sign up', 'woocommerce-subscriptions' ) ), $product );
		}

		return $button_text;
	}

	/**
	 * Changes the shop add-to-cart button action when a product has subscription options.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  string     $url
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function add_to_cart_url( $url, $product ) {

		if ( self::prompt_plan_selection_in_catalog( $product ) ) {
			$url = apply_filters( 'wcsatt_add_to_cart_url', $product->get_permalink(), $product );
		}

		return $url;
	}

	/**
	 * Changes the shop add-to-cart button URL when a product has subscription options.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  array      $supports
	 * @param  string     $feature
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function supports_ajax_add_to_cart( $supports, $feature, $product ) {

		if ( 'ajax_add_to_cart' === $feature && self::prompt_plan_selection_in_catalog( $product ) ) {
			$supports = apply_filters( 'wcsatt_product_supports_ajax_add_to_cart', false, $product );
		}

		return $supports;
	}

	/**
	 * Add product page class if a product has subscription plans.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  array      $classes
	 * @param  WC_Product $product
	 */
	public static function add_product_class( $classes, $class, $product_id ) {

		global $product;

		if ( $product && ! is_a( $product, 'WC_Product' ) ) {
			return $classes;
		}

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			$classes[] = 'has-subscription-plans';
		}

		return $classes;
	}
}
