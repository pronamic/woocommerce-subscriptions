<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings;

use Automattic\WooCommerce_Subscriptions\Settings;
use WC_Subscriptions_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The experimental React (settings-ui) renderer's adapter: shapes the modern settings payload.
 *
 * Division of responsibilities mirrors the classic side. The layout itself - which sections exist,
 * their order, grouping and field moves - lives solely in {@see Settings_Layout}, shared by both
 * renderers. {@see Classic_Renderer} is the CLASSIC renderer's adapter (card wrapper markup and
 * per-field normalization for WooCommerce's table renderer); this class is the MODERN renderer's
 * counterpart: it normalizes section headings and reshapes the laid-out array into the schema the
 * settings-ui SDK consumes (section-body rebuilds, non-serializing flags, data attributes feeding
 * the custom React components).
 *
 * It exists as a class of its own, rather than inside {@see Settings_Page}, for two reasons:
 *
 *   - WooCommerce core's API pins get_settings() to the {@see \WC_Settings_Page} object (the modern
 *     LegacySettingsPageAdapter sources its schema from that method), but nothing requires the
 *     payload construction to live there. Keeping {@see Settings_Page} a thin shared tab shell and
 *     the modern-only shaping here separates the code every store runs from the code only the
 *     experimental opt-in exercises.
 *   - The React renderer is parked in-tree behind the `WCS_EXPERIMENTAL_MODERN_SETTINGS_UI` opt-in
 *     (see {@see \Automattic\WooCommerce_Subscriptions\Internal\Settings\Settings_Ui_Feature_Flag})
 *     until WooCommerce's settings-ui SDK stabilizes. This class is that parked surface's entire
 *     PHP payload, so it can be deleted - or revived - as a unit without touching the shell that
 *     registers the tab.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
final class Modern_Field_Adaptations {

	/**
	 * Shape the modern settings payload: normalize the section-heading keys, then apply the
	 * modern-only settings-ui transforms to the (already laid-out) settings.
	 *
	 * The layout itself - card order, the Miscellaneous split, cross-section field moves - is NOT applied
	 * here: it comes from {@see Settings_Layout::reshape()} (see {@see Settings_Page::get_settings()}), the
	 * definition shared with the classic renderer, so both screens agree by construction. Title normalization
	 * runs first, and after the layout, so the section headings the layout itself creates (the cards split out
	 * of Miscellaneous) also gain the 'title' key the modern schema requires. What remains is only what the
	 * classic table genuinely cannot share: replacements and per-field reshaping where the React schema
	 * cannot render the classic structure (the Switching composite and behavior selects, the Billing date
	 * alignment proration block), non-serializing flags and data attributes feeding custom React components,
	 * and the product-creation sub-heading (the classic renderer has no `info` renderer).
	 *
	 * These transforms run on the copy consumed by {@see Settings_Page_Adapter} (WooCommerce's
	 * LegacySettingsPageAdapter::get_schema() sources its data from {@see Settings_Page::get_settings()}),
	 * never on the array the classic tab renders, which it obtains directly from
	 * {@see WC_Subscriptions_Admin::get_settings()}.
	 *
	 * This is applied unconditionally (it is not gated by {@see Settings_Page::is_settings_ui_active()}): the
	 * classic tab renders and saves through the legacy paths and never sees this copy, but the output IS read
	 * by a generic consumer - WooCommerce's REST settings registrar copies {@see Settings_Page::get_settings()}
	 * into the `woocommerce_settings-{id}` filter on `rest_api_init`, making this reshaped array the source of
	 * the `/wc/v3/settings/subscriptions` group. {@see Settings_Page::filter_rest_registered_settings()}
	 * reconciles that surface (stripping the non-serializing fields these transforms flag, restoring the
	 * legacy option ids the decompositions replaced), so a transform added here that flags or replaces a
	 * persisting field changes the REST surface and must be weighed against that filter's rules.
	 *
	 * Each transform is keyed off setting ids and is a no-op when the relevant section/field is absent (for
	 * example when All Products for Subscriptions is inactive), so the page degrades gracefully.
	 *
	 * @param array $settings Laid-out legacy settings array.
	 * @return array
	 */
	public static function apply( array $settings ): array {
		$settings = array_map( array( __CLASS__, 'normalize_title_key' ), $settings );

		$settings = self::promote_storewide_plans_heading( $settings );
		$settings = self::build_switching_section( $settings );
		// Add to Subscription, Renewals, Checkout options, Payment recovery, Suspensions, Downloadable
		// content, Gifting, Purchase text and Subscriber roles are fully shared: copy and controls live at
		// source and the layout module orders and groups them, so no modern-only reshaping is needed.
		$settings = self::build_billing_date_alignment_section( $settings );
		$settings = self::build_subscription_notifications_section( $settings );
		$settings = self::build_subscription_product_creation_section( $settings );
		$settings = self::build_processing_reliability_section( $settings );

		return $settings;
	}

	/**
	 * Copy a section heading's legacy 'name' key to 'title' when the latter is absent.
	 *
	 * Subscriptions section headings (and those added by third parties via the `woocommerce_subscription_settings`
	 * filter) conventionally use the 'name' key. The modern schema builder (SettingsUISchema::from_legacy_settings())
	 * reads a group heading's text from 'title' only — with no 'name' fallback — so a heading carrying only 'name'
	 * renders as an untitled card. We mirror the classic renderer's normalization for headings to keep both in
	 * agreement. Ordinary fields need no such treatment: the schema's field-label resolver already falls back to
	 * 'name' when 'title' is absent, so we scope this to heading (`type => 'title'`) definitions.
	 *
	 * @param array|mixed $setting A single legacy setting definition.
	 * @return array|mixed
	 */
	private static function normalize_title_key( $setting ) {
		if ( is_array( $setting ) && isset( $setting['type'], $setting['name'] ) && 'title' === $setting['type'] && ! isset( $setting['title'] ) ) {
			$setting['title'] = $setting['name'];
		}

		return $setting;
	}

	/**
	 * Promote the "Storewide subscription plans" heading onto its section title.
	 *
	 * For the classic tab the All Products for Subscriptions section deliberately keeps an empty `title` field
	 * and renders its heading + description inside the plans row's own cell (a CSS-layout requirement — see
	 * WCS_ATT_Admin::add_settings()). The modern renderer instead derives each card's heading and description
	 * from the section `title` field, so here we lift the existing (already translated) heading/description off
	 * the plans field and onto the title, matching how the neighbouring "Add to Subscription" section is built.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function promote_storewide_plans_heading( array $settings ): array {
		$title_index   = Settings_Layout::find_field_index( $settings, 'wcsatt_subscribe_to_cart_options', 'title' );
		$schemes_index = Settings_Layout::find_field_index( $settings, 'wcsatt_subscribe_to_cart_schemes' );

		if ( null === $title_index || null === $schemes_index ) {
			return $settings;
		}

		$heading     = isset( $settings[ $schemes_index ]['name'] ) ? $settings[ $schemes_index ]['name'] : '';
		$description = isset( $settings[ $schemes_index ]['desc'] ) ? $settings[ $schemes_index ]['desc'] : '';

		$settings[ $title_index ]['title'] = $heading;
		$settings[ $title_index ]['name']  = $heading;
		$settings[ $title_index ]['desc']  = $description;

		unset(
			$settings[ $schemes_index ]['name'],
			$settings[ $schemes_index ]['title'],
			$settings[ $schemes_index ]['desc']
		);

		// The storewide plans control is a self-contained React app (rendered by a settings-ui field-component
		// override) that reads its data from a localized global and persists via its own REST endpoints — it never
		// travels through the settings form. Flagging it non-persisting makes the modern schema emit `save.adapter:
		// none`, so the renderer does not post a hidden input for it; otherwise it would submit an empty value under
		// this option id alongside the form, which the classic save could then write over the REST-saved plans with.
		$settings[ $schemes_index ]['is_option'] = false;

		return $settings;
	}

	/**
	 * Reshape the "Switching" card.
	 *
	 * The section is source-driven: {@see \WC_Subscriptions_Switcher::add_settings()} carries the design copy
	 * shared with the classic tab (section description, behavior selects with their labels and options, the
	 * Virtual/Physical product-type pairs with their group helper copy, the button text help) and derives the
	 * decomposed controls' current values from the stored legacy options. Only what the classic table cannot
	 * share is reshaped here:
	 *
	 *  - The composite allow-switching control (`wcs_switching_options`) has a classic renderer but no React
	 *    one, so it is replaced with the redesign's three checkboxes ({@see self::modern_checkbox()}) plus a
	 *    checkbox per filter-registered extra option ({@see self::extra_switching_checkboxes()}), matching
	 *    the classic composite's contents.
	 *  - The behavior selects keep the per-value `data-descriptions` map they carry at source (shown by the
	 *    SelectWithDescriptions component here and swapped in by the classic description handler in
	 *    assets/js/admin/admin.js); only their persistence and classic-only presentation are adapted -
	 *    {@see self::adapt_switching_select()}.
	 *  - The product-type pairs swap their classic presentation (visible fieldset legend, `data-show-if-value`
	 *    disclosure) for the modern one (an `info` sub-heading plus a validation notice, shown/hidden by the
	 *    client extension) - {@see self::adapt_switching_product_types()}.
	 *  - The button text gains the design's modern presentation - {@see self::adapt_switch_button_text()}.
	 *
	 * Conditional visibility (show the behavior controls only once a switching type is enabled; show the product
	 * types only for a prorating behavior) and the per-option help on the behavior dropdowns are delivered by the
	 * settings-ui client extension (`client/entrypoints/settings-ui.js`), keyed off these same shared field ids -
	 * keep them in sync. See {@see Settings_Page_Adapter::get_script_handles()}.
	 *
	 * Persistence is harmonized: every reshaped field stays `is_option => false` (so WooCommerce's generic save
	 * never writes a junk option row for it) but renders under its real option/POST key with `save.adapter =
	 * form_post`, so a modern save posts the same keys the classic tab does and the classic save handlers
	 * ({@see WC_Subscriptions_Admin::update_subscription_settings()} and
	 * {@see \WC_Subscriptions_Switcher::save_switch_proration_settings()}) persist them.
	 *
	 * Each transform is a no-op when its field is absent, so the page degrades gracefully.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function build_switching_section( array $settings ): array {
		$prefix = WC_Subscriptions_Admin::$option_prefix;

		// The composite classic control becomes the redesign's three checkboxes. Their labels live here (the
		// classic renderer draws its own copies inside the composite's markup), and they post under the same
		// keys the classic control does: the plans checkbox is a real option, while variations/grouped are the
		// transient POST keys that update_subscription_settings() folds into the `_allow_switching` composite.
		$composite_index = Settings_Layout::find_field_index( $settings, $prefix . '_allow_switching' );
		if ( null !== $composite_index ) {
			$allow_switching_checkboxes = array(
				self::modern_checkbox( 'switch_allow_plans', __( 'Subscription plans', 'woocommerce-subscriptions' ), '', $prefix . '_allow_switching_product_plans' ),
				self::modern_checkbox( 'switch_allow_variations', __( 'Subscription variations', 'woocommerce-subscriptions' ), '', $prefix . '_allow_switching_variable' ),
				self::modern_checkbox( 'switch_allow_grouped', __( 'Grouped subscriptions', 'woocommerce-subscriptions' ), '', $prefix . '_allow_switching_grouped' ),
			);

			foreach ( self::extra_switching_checkboxes( $prefix ) as $extra_checkbox ) {
				$allow_switching_checkboxes[] = $extra_checkbox;
			}

			array_splice( $settings, $composite_index, 1, $allow_switching_checkboxes );
		}

		$settings = self::adapt_switching_select( $settings, $prefix . '_switch_first_billing_behavior' );
		$settings = self::adapt_switching_select( $settings, $prefix . '_apportion_sign_up_fee' );
		$settings = self::adapt_switching_select( $settings, $prefix . '_switch_fixed_term_behavior' );

		$settings = self::adapt_switching_product_types( $settings, $prefix . '_switch_first_billing' );
		$settings = self::adapt_switching_product_types( $settings, $prefix . '_switch_fixed_term' );

		return self::adapt_switch_button_text( $settings );
	}

	/**
	 * Build checkboxes for the filter-registered allow-switching options (e.g. Product Bundles / Composite
	 * Products content switching), mirroring the classic composite control, which renders them after the core
	 * options ({@see \WC_Subscriptions_Switcher::switching_options_field_html()}).
	 *
	 * Each checkbox renders under the classic control's real POST key
	 * (`{$prefix}_allow_switching_{$option_id}`, also its option row) with the same form POST persistence as
	 * the core three, so {@see WC_Subscriptions_Admin::update_subscription_settings()} saves it natively. The
	 * plans option is skipped: the redesign renders it first, under its own label, as one of the core three.
	 *
	 * @param string $prefix The shared option-id prefix ({@see WC_Subscriptions_Admin::$option_prefix}).
	 * @return array[] Checkbox field definitions (possibly empty).
	 */
	private static function extra_switching_checkboxes( string $prefix ): array {
		/**
		 * This filter is documented in includes/switching/class-wc-subscriptions-switcher.php
		 *
		 * @since 9.2.0 Also consulted here so the modern card mirrors the classic composite's options.
		 */
		$extra_switching_options = (array) apply_filters( 'woocommerce_subscriptions_allow_switching_options', array() );
		// A non-array entry cannot carry an option and an object would fatal on the offset reads below -
		// even inside isset()/empty(), which throw on object offsets. This path is REST-reachable on every
		// store, so a malformed third-party return must degrade, not fatal.
		$extra_switching_options = array_filter( $extra_switching_options, 'is_array' );
		$checkboxes              = array();

		foreach ( $extra_switching_options as $option ) {
			if ( empty( $option['id'] ) || empty( $option['label'] ) || 'product_plans' === $option['id'] ) {
				continue;
			}

			$id = $prefix . '_allow_switching_' . $option['id'];

			$checkbox = array(
				'id'        => $id,
				'type'      => 'checkbox',
				'desc'      => $option['label'],
				'default'   => get_option( $id, 'no' ),
				'is_option' => false,
				'save'      => array( 'adapter' => 'form_post' ),
			);

			if ( ! empty( $option['desc_tip'] ) && is_string( $option['desc_tip'] ) ) {
				$checkbox['desc_tip'] = $option['desc_tip'];
			}

			$checkboxes[] = $checkbox;
		}

		return $checkboxes;
	}

	/**
	 * Adapt a source-defined Switching select for the modern renderer.
	 *
	 * The field itself (label, options, derived current value, and the per-value `data-descriptions` map read
	 * by the SelectWithDescriptions component) is inherited from the source definition - every behavior select
	 * carries its map at source, shared with the classic tab's description-swapping handler. This opts the
	 * field into the modern form POST so the classic save handlers persist the posted value, and drops the
	 * classic-only presentation: the static no-JS fallback help line (superseded by the per-value map) and the
	 * enhanced-select class/css.
	 *
	 * @param array  $settings Assembled legacy settings array.
	 * @param string $id       Field id (a real option/POST key).
	 * @return array
	 */
	private static function adapt_switching_select( array $settings, string $id ): array {
		$index = Settings_Layout::find_field_index( $settings, $id );

		if ( null === $index ) {
			return $settings;
		}

		$field = $settings[ $index ];

		$field['is_option'] = false;
		$field['save']      = array( 'adapter' => 'form_post' );

		unset( $field['desc'], $field['css'], $field['class'] );

		$settings[ $index ] = $field;

		return $settings;
	}

	/**
	 * Reshape a source-defined Virtual/Physical product-type pair for the modern renderer.
	 *
	 * The checkboxes themselves (labels, the group helper on the physical checkbox's `desc_tip`, derived
	 * current values) are inherited from the source definition. The classic renderer shows the group heading
	 * as a visible fieldset legend (the `name` on the pair's first checkbox, see
	 * {@see Classic_Renderer::CLASS_VISIBLE_LEGEND}); the modern renderer has no legend concept, so that
	 * heading is lifted onto a standalone `info` sub-heading (rendered bold by the SettingsSubheading
	 * component), and the classic-only keys - the legend name/class, `checkboxgroup`, and the
	 * `data-show-if-value` disclosure attribute (modern visibility comes from the client extension) - are
	 * dropped. A "select at least one" notice (rendered by SettingsFieldError, which supplies the warning
	 * glyph; display-only, its id exists for the client extension's predicate) is appended after the pair,
	 * the modern counterpart to the classic save-time validation (migration §1g). Both checkboxes opt into
	 * the modern form POST so the classic packer persists them.
	 *
	 * @param array  $settings  Assembled legacy settings array.
	 * @param string $id_prefix Shared id prefix of the pair (e.g. `woocommerce_subscriptions_switch_first_billing`).
	 * @return array
	 */
	private static function adapt_switching_product_types( array $settings, string $id_prefix ): array {
		$virtual_index  = Settings_Layout::find_field_index( $settings, $id_prefix . '_virtual' );
		$physical_index = Settings_Layout::find_field_index( $settings, $id_prefix . '_physical' );

		if ( null === $virtual_index || null === $physical_index || $physical_index <= $virtual_index ) {
			return $settings;
		}

		$heading = isset( $settings[ $virtual_index ]['name'] ) ? $settings[ $virtual_index ]['name'] : '';

		foreach ( array( $virtual_index, $physical_index ) as $index ) {
			$field         = $settings[ $index ];
			$field['save'] = array( 'adapter' => 'form_post' );
			unset( $field['name'], $field['class'], $field['checkboxgroup'], $field['custom_attributes'] );
			$settings[ $index ] = $field;
		}

		// Insert the validation notice after the pair first (the higher index), then the sub-heading above
		// it, so neither splice disturbs the other's target position.
		array_splice(
			$settings,
			$physical_index + 1,
			0,
			array(
				array(
					'id'        => $id_prefix . '_product_types_error',
					'type'      => 'info',
					'name'      => __( 'Select at least one subscription product type.', 'woocommerce-subscriptions' ),
					'is_option' => false,
				),
			)
		);

		if ( '' !== $heading ) {
			array_splice(
				$settings,
				$virtual_index,
				0,
				array(
					array(
						'id'        => $id_prefix . '_product_types_title',
						'type'      => 'info',
						'name'      => $heading,
						'is_option' => false,
					),
				)
			);
		}

		return $settings;
	}

	/**
	 * Adapt the source-defined switch button text field for the modern renderer.
	 *
	 * The field (its help copy and current value) is inherited from the source definition; the design's modern
	 * presentation is applied on top: the sentence-case label ("Switch button text" - the classic tab
	 * deliberately keeps "Switch Button Text" because E2E selectors key off it), the "Switch" placeholder, and
	 * the classic hover tooltip (`desc` + `desc_tip => true`) converted into persistently rendered help. The
	 * field renders under its real `_switch_button_text` option id and opts into the modern form POST so
	 * {@see WC_Subscriptions_Admin::update_subscription_settings()} persists it.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function adapt_switch_button_text( array $settings ): array {
		$index = Settings_Layout::find_field_index( $settings, WC_Subscriptions_Admin::$option_prefix . '_switch_button_text' );

		if ( null === $index ) {
			return $settings;
		}

		$field = $settings[ $index ];

		$field['name']        = __( 'Switch button text', 'woocommerce-subscriptions' );
		$field['placeholder'] = __( 'Switch', 'woocommerce-subscriptions' );
		$field['desc_tip']    = isset( $field['desc'] ) ? $field['desc'] : '';
		$field['is_option']   = false;
		$field['save']        = array( 'adapter' => 'form_post' );

		unset( $field['desc'], $field['css'], $field['tip'] );

		$settings[ $index ] = $field;

		return $settings;
	}

	/**
	 * Reshape the "Processing reliability" card.
	 *
	 * The card already carries its title/description and the two checkboxes (dedicated processing, web cron), with
	 * the design copy. The only reshaping is the Web Cron URL field: its legacy custom type has no native renderer,
	 * so we supply the current URL and the regenerate link as data attributes for the WebCronUrlField component
	 * (readonly URL + copy + "Generate a new URL"), and mark it non-serializing (it is a display, not an option).
	 * The client extension reveals it only when web cron is enabled. No-op when the section is absent
	 * (queue-management feature inactive).
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function build_processing_reliability_section( array $settings ): array {
		$section_id = 'woocommerce_subscriptions_queue_processing_options';

		if ( null === Settings_Layout::find_field_index( $settings, $section_id, 'title' ) ) {
			return $settings;
		}

		// The Web Cron URL field is contributed by External_Trigger_Settings, so it carries that class's section
		// id as its prefix ('..._external_trigger_options_url_display') rather than this card's $section_id.
		$url_field_id = 'woocommerce_subscriptions_external_trigger_options_url_display';
		$url_index    = Settings_Layout::find_field_index( $settings, $url_field_id );
		if ( null !== $url_index ) {
			$token = (string) get_option( 'woocommerce_subscriptions_external_trigger_token', '' );
			$url   = '' !== $token
				? add_query_arg( 'wcs_token', $token, rest_url( 'wc/v3/subscriptions/job-queue' ) )
				: '';

			// Build a raw URL (not wp_nonce_url(), which esc_html()s the result to `&#038;`): this travels as a
			// JSON data attribute and is assigned to href by JS, which does not decode entities — so an encoded
			// ampersand would detach `_wpnonce` and the nonce check would fail.
			$regenerate_url = esc_url_raw(
				add_query_arg(
					array(
						'action'   => 'wcs_regenerate_external_trigger_token',
						'_wpnonce' => wp_create_nonce( 'wcs_regenerate_external_trigger_token' ),
					),
					admin_url( 'admin-post.php' )
				)
			);

			$settings[ $url_index ]['name']              = __( 'Web Cron URL', 'woocommerce-subscriptions' );
			$settings[ $url_index ]['title']             = __( 'Web Cron URL', 'woocommerce-subscriptions' );
			$settings[ $url_index ]['is_option']         = false;
			$settings[ $url_index ]['custom_attributes'] = array(
				'data-url'            => $url,
				'data-regenerate-url' => $regenerate_url,
			);
		}

		return $settings;
	}

	/**
	 * Reshape the "Subscription product creation" card.
	 *
	 * The card already exists (correct title/description and the two product-type checkboxes). Here we add a
	 * sub-heading above the checkboxes: the React SettingsSubheading renders the `info` type, while the classic
	 * renderer shows the group heading as the fieldset legend it derives from the first checkbox's `name` (which
	 * the modern label mapping ignores in favour of `desc`). The checkboxes are real, persisting options. No-op
	 * when the section is absent.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function build_subscription_product_creation_section( array $settings ): array {
		$prefix     = WC_Subscriptions_Admin::$option_prefix;
		$section_id = $prefix . '_product_creation';

		if ( null === Settings_Layout::find_field_index( $settings, $section_id, 'title' ) ) {
			return $settings;
		}

		// Add a sub-heading directly above the product-type checkboxes.
		$simple_index = Settings_Layout::find_field_index( $settings, $prefix . '_enable_simple_subscription' );
		if ( null !== $simple_index ) {
			array_splice(
				$settings,
				$simple_index,
				0,
				array(
					array(
						'id'        => 'woocommerce_subscriptions_product_types_heading',
						'type'      => 'info',
						'name'      => __( 'Enable subscription product types:', 'woocommerce-subscriptions' ),
						'is_option' => false,
					),
				)
			);
		}

		return $settings;
	}

	/**
	 * Reshape the "Subscription notifications" (customer notifications) card.
	 *
	 * The card copy (title, description, checkbox label and help, offset label and help) lives at source,
	 * shared with the classic tab. The card keeps its real, persisting options. The only reshaping left here
	 * is structural: the reminder-timing offset is marked non-serializing (`is_option => false`) because its
	 * value is the structured `{ number, unit }` shape, which the SDK's scalar hidden-input serialization
	 * can't represent, so the RelativeDateSelector component renders the combo (number + unit) and posts the
	 * array itself; its source `desc_tip => true` tooltip flag is cleared so the modern renderer shows the
	 * `desc` copy persistently. No-op when the section is absent.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function build_subscription_notifications_section( array $settings ): array {
		$prefix     = WC_Subscriptions_Admin::$option_prefix;
		$section_id = $prefix . '_customer_notifications';

		if ( null === Settings_Layout::find_field_index( $settings, $section_id, 'title' ) ) {
			return $settings;
		}

		// Structural, not copy: keep the offset off the scalar save path and convert its classic hover
		// tooltip into the persistently rendered help.
		$offset_index = Settings_Layout::find_field_index( $settings, $prefix . '_customer_notifications_offset' );
		if ( null !== $offset_index ) {
			$settings[ $offset_index ]['desc_tip']  = false;
			$settings[ $offset_index ]['is_option'] = false;
		}

		return $settings;
	}

	/**
	 * Refine the "Billing date alignment" (payment synchronisation) card for the modern renderer.
	 *
	 * Unlike the other cards, this section is not part of the parked redesign namespace — its dropdown
	 * (`_first_billing_behavior`), Virtual/Physical proration checkboxes and sign-up-cutoff number are **real,
	 * persisting** options. The dropdown is already rendered with per-option help (SelectWithDescriptions) and the
	 * cutoff number natively; here we replace only the legacy custom proration-options field with native
	 * Virtual/Physical checkboxes (their real option ids), a heading, and a validation notice - kept modern-only
	 * because the source `woocommerce_subscriptions_proration_options` custom field type has a classic renderer
	 * ({@see \WC_Subscriptions_Synchroniser::proration_options_field_html()}) but no React one. The conditional
	 * visibility — cutoff shown for "Charge full amount at sign-up", the proration product types for "Prorate…" —
	 * is applied by the client extension. No-op parts degrade gracefully when the legacy field is absent.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function build_billing_date_alignment_section( array $settings ): array {
		$index = Settings_Layout::find_field_index( $settings, 'woocommerce_subscriptions_prorate_options' );
		if ( null !== $index ) {
			array_splice( $settings, $index, 1, self::billing_date_alignment_prorate_fields() );
		}

		// The cutoff number's classic disclosure attribute does not apply here — modern visibility comes from the
		// client extension, as it does for the switching pairs ({@see self::adapt_switching_product_types()}).
		// Only that one attribute is dropped: the client forwards the rest to the field (see
		// client/settings-ui/), so an extension's attributes must survive. `is_array()` guards the index because
		// the array arrives through the `woocommerce_subscription_settings` filter and a scalar would fatal.
		$cutoff_index = Settings_Layout::find_field_index( $settings, 'woocommerce_subscriptions_days_no_fee' );
		if ( null !== $cutoff_index && isset( $settings[ $cutoff_index ]['custom_attributes'] ) && is_array( $settings[ $cutoff_index ]['custom_attributes'] ) ) {
			unset( $settings[ $cutoff_index ]['custom_attributes']['data-show-if-value'] );
		}

		return $settings;
	}

	/**
	 * The Virtual/Physical proration block for "Billing date alignment" (modern-only).
	 *
	 * The two checkboxes carry their **real** option ids (`_prorate_virtual` default yes, `_prorate_physical`
	 * default no), so they render current state and persist through the form like the surrounding sync options.
	 * The heading and validation notice are presentational (`is_option => false`); the client extension shows the
	 * notice only while "Prorate…" is chosen but neither product type is checked.
	 *
	 * @return array
	 */
	private static function billing_date_alignment_prorate_fields(): array {
		return array(
			array(
				'id'        => 'woocommerce_subscriptions_prorate_types_heading',
				'type'      => 'info',
				'name'      => __( 'Apply proration to:', 'woocommerce-subscriptions' ),
				'is_option' => false,
			),
			array(
				'id'      => 'woocommerce_subscriptions_prorate_virtual',
				'type'    => 'checkbox',
				'desc'    => __( 'Virtual subscription products', 'woocommerce-subscriptions' ),
				'default' => 'yes',
			),
			array(
				'id'      => 'woocommerce_subscriptions_prorate_physical',
				'type'    => 'checkbox',
				'desc'    => __( 'Physical subscription products', 'woocommerce-subscriptions' ),
				'default' => 'no',
			),
			// A standalone caption for the pair above (rendered by SettingsHelpText), not help attached to a single
			// checkbox.
			array(
				'id'        => 'woocommerce_subscriptions_prorate_types_help',
				'type'      => 'info',
				'name'      => __( 'Product types not selected will be charged on the next billing date.', 'woocommerce-subscriptions' ),
				'is_option' => false,
			),
			array(
				'id'        => 'woocommerce_subscriptions_prorate_types_error',
				'type'      => 'info',
				'name'      => __( 'Select at least one subscription product type to apply proration.', 'woocommerce-subscriptions' ),
				'is_option' => false,
			),
		);
	}

	/**
	 * Build a modern checkbox field whose current state derives from the registry.
	 *
	 * The modern renderer takes a checkbox's visible label from `desc` and its help text from `desc_tip`. The
	 * current value derives-on-read via {@see Settings::get()}. The field renders under its real option/POST
	 * key (`$id`) with `save.adapter = form_post`, so a modern save posts it and the classic save handlers
	 * persist it, while `is_option => false` keeps WooCommerce's generic save from writing a junk option row.
	 *
	 * @param string $key         Registry key (e.g. `switch_allow_plans`).
	 * @param string $label       Visible checkbox label.
	 * @param string $description Optional help text shown beneath the checkbox ('' for none).
	 * @param string $id          Field id (a real option/POST key).
	 * @return array
	 */
	private static function modern_checkbox( string $key, string $label, string $description, string $id ): array {
		$field = array(
			'id'        => $id,
			'type'      => 'checkbox',
			'desc'      => $label,
			'default'   => Settings::get( $key ),
			'is_option' => false,
			'save'      => array( 'adapter' => 'form_post' ),
		);

		if ( '' !== $description ) {
			$field['desc_tip'] = $description;
		}

		return $field;
	}
}
