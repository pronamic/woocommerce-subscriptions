<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings;

use Automattic\WooCommerce\Admin\Features\Features;
use Automattic\WooCommerce\Admin\Settings\SettingsUIPageInterface;
use Automattic\WooCommerce_Subscriptions\Internal\Settings\Settings_Ui_Feature_Flag;
use WC_Settings_Page;
use WC_Subscriptions_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Subscriptions settings tab as a first-class {@see WC_Settings_Page}.
 *
 * Historically the Subscriptions tab was wired up through WooCommerce's older procedural settings API: the
 * `woocommerce_settings_tabs_array` filter added the tab, `woocommerce_settings_subscriptions` rendered it,
 * and `woocommerce_update_options_subscriptions` saved it (all in {@see WC_Subscriptions_Admin}). The modern
 * "settings-ui" renderer, however, only discovers tabs that are registered as WC_Settings_Page *objects*
 * (see WooCommerce's Settings::get_current_settings_ui_page(), which iterates WC_Admin_Settings::get_settings_pages()).
 *
 * This class is the thin tab shell shared by both renderers: it wraps the existing tab in such an object so it
 * can opt into the modern UI via {@see self::get_settings_ui_page()}, routes output between the classic and
 * modern renderers, and deliberately keeps the established behaviour intact - rendering and saving still
 * delegate to the long-standing WC_Subscriptions_Admin methods. The wrapper is registered through the
 * `woocommerce_get_settings_pages` filter; the legacy tab/render registrations are removed in favour of it
 * (the save hook is left in place - see {@see self::save()}).
 *
 * The shell carries no renderer schema shaping of its own: the layout shared by both renderers lives in
 * {@see Settings_Layout}, the classic renderer's adapter in {@see Classic_Renderer}, and the modern-only
 * payload shaping in {@see Modern_Field_Adaptations} (consumed via {@see self::get_settings()}). The one
 * shaping concern the shell does own is the REST settings surface: registering the tab as a
 * WC_Settings_Page also opts it into WooCommerce's REST settings registrar, and
 * {@see self::filter_rest_registered_settings()} reconciles what that exposes at
 * `/wc/v3/settings/subscriptions` with what actually persists.
 *
 * Lives under Internal\ deliberately: see the note on {@see Settings_Page_Adapter}.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Settings_Page extends WC_Settings_Page {

	/**
	 * Render before extension callbacks that use WooCommerce's conventional default priority.
	 */
	const OUTPUT_PRIORITY = 1;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = WC_Subscriptions_Admin::$tab_name;
		$this->label = __( 'Subscriptions', 'woocommerce-subscriptions' );

		parent::__construct();

		/*
		 * WC_Settings_Page registers this callback at the default priority 10. WordPress treats the same
		 * callback at different priorities as separate registrations, so remove the inherited callback
		 * before re-registering it at priority 1. Otherwise, the settings page would render twice.
		 */
		remove_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ), self::OUTPUT_PRIORITY );

		/*
		 * After WooCommerce's REST registrar (WC_Register_WP_Admin_Settings, priority 10 on this same
		 * filter, attached on rest_api_init): it drops the `is_option` flag when copying this page's
		 * fields, so display-only fields would become readable and writable options over REST. The
		 * group-index filter gets the same late slot: both filters are public surfaces other code can
		 * also register into (including the deprecated WC_REST_Subscriptions_Settings mirror, should
		 * something still construct it), so overlapping registrations are normalized here too.
		 */
		add_filter( 'woocommerce_settings-' . $this->id, array( $this, 'filter_rest_registered_settings' ), 20 );
		add_filter( 'woocommerce_settings_groups', array( $this, 'remove_duplicate_rest_group' ), 20 );
	}

	/**
	 * Get the settings for this page.
	 *
	 * The Subscriptions tab has no sub-sections, so the section id is ignored. The signature accepts an
	 * optional section id to remain compatible with WC_Settings_Page::get_settings() as consumed by
	 * WooCommerce core (and as documented on the base method for externally registered pages).
	 *
	 * The pipeline is: assemble the legacy array, apply the shared layout ({@see Settings_Layout::reshape()} -
	 * the same card order, Miscellaneous split and field moves the classic renderer applies), then hand the
	 * copy to {@see Modern_Field_Adaptations::apply()} for the section-heading normalization and the remaining
	 * modern-only transforms.
	 *
	 * @param string $section_id Section id. Empty string means the default section.
	 * @return array
	 */
	public function get_settings( $section_id = '' ) {
		// The adaptation pipeline lives in adapt_assembled_settings(), including the plugin-file-swap
		// degradation: this object can be in memory while its collaborators' files are gone.
		return $this->adapt_assembled_settings( WC_Subscriptions_Admin::get_settings() );
	}

	/**
	 * Reconcile the settings registered with the REST settings API into the intended surface: only fields
	 * that really persist under their own option id, each listed once, with the ids the REST group has
	 * carried since 6.0.0 preserved.
	 *
	 * Registering the tab as a WC_Settings_Page makes WooCommerce's WC_Register_WP_Admin_Settings (priority
	 * 10 on this same filter, attached on `rest_api_init`) copy every field {@see self::get_settings()}
	 * returns into the `/wc/v3/settings/subscriptions` group. The copy drops the `is_option => false` flag
	 * that WC_Admin_Settings::save_fields() honours on the classic form save, so a display-only field would
	 * become a writable option over REST: a PUT to its id returns 200 and writes a stray option row nothing
	 * reads (the real value lives in the legacy option the field derives from), and a GET reports that
	 * stray row instead of the derived value the settings page shows.
	 *
	 * Four rules produce the surface:
	 *
	 * - Drop every id that is display-only in either source array, EXCEPT ids the assembled (classic)
	 *   array persists directly. The modern payload marks some real options non-serializing for its own
	 *   save pipeline (e.g. the sign-up fee proration select); those still read and save correctly under
	 *   their own option id, and have been part of the REST group since 6.0.0, so they stay registered.
	 * - Drop entries whose type is outside WooCommerce's REST settings type allowlist - section `title` /
	 *   `sectionend` markers and this plugin's custom renderer types. The REST controllers already exclude
	 *   them from every response and 404 them individually, and the 6.0.0 surface never listed them, so
	 *   registering them only bloats the raw group.
	 * - Collapse duplicate registrations of the same field, keyed by id and type. This filter is a public
	 *   surface any plugin can write to - including the deprecated WC_REST_Subscriptions_Settings mirror,
	 *   should something still construct it - so overlapping registrations are normalized to one entry,
	 *   keeping the first. Malformed third-party entries (non-scalar ids) are skipped rather than fataled on.
	 * - Re-register the option ids whose form fields the redesign decomposed into display-only controls
	 *   ({@see self::get_legacy_rest_settings()}), so established REST/CLI integrations keep working
	 *   against the options the runtime still reads.
	 *
	 * Only the REST settings controllers apply this filter, so the classic form render and save are
	 * unaffected.
	 *
	 * @param array|mixed $settings Settings registered for the subscriptions group.
	 * @return array|mixed
	 */
	public function filter_rest_registered_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$assembled = $this->normalize_assembled_settings( WC_Subscriptions_Admin::get_settings() );

		$stripped_ids = $this->get_rest_stripped_ids( $assembled, $this->adapt_assembled_settings( $assembled ) );

		$type_gate = class_exists( 'WC_REST_Setting_Options_V2_Controller' ) ? new \WC_REST_Setting_Options_V2_Controller() : null;
		$seen      = array();
		$filtered  = array();

		foreach ( $settings as $setting ) {
			if ( is_array( $setting ) && isset( $setting['id'] ) ) {
				if ( ! is_scalar( $setting['id'] ) ) {
					continue;
				}

				if ( isset( $stripped_ids[ $setting['id'] ] ) ) {
					continue;
				}

				if ( null !== $type_gate && ( ! isset( $setting['type'] ) || ! $type_gate->is_setting_type_valid( $setting['type'] ) ) ) {
					continue;
				}

				$key = $this->get_dedupe_key( $setting );

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
			}

			$filtered[] = $setting;
		}

		foreach ( $this->get_legacy_rest_settings() as $legacy_setting ) {
			if ( ! isset( $seen[ $this->get_dedupe_key( $legacy_setting ) ] ) ) {
				$filtered[] = $legacy_setting;
			}
		}

		return $filtered;
	}

	/**
	 * Apply the modern adaptation pipeline to an already-assembled settings array, degrading to an empty
	 * array during the plugin-file-swap window - the single definition {@see self::get_settings()} and
	 * {@see self::filter_rest_registered_settings()} both consume.
	 *
	 * Accepts non-array input because the assembled array can be raw `woocommerce_subscription_settings`
	 * filter output ({@see self::normalize_assembled_settings()} for why that must degrade rather than
	 * TypeError).
	 *
	 * @param array|mixed $assembled The assembled classic settings array (raw filter output).
	 * @return array
	 */
	private function adapt_assembled_settings( $assembled ): array {
		$assembled = $this->normalize_assembled_settings( $assembled );

		if ( ! WC_Subscriptions_Admin::are_settings_classes_loadable() ) {
			return array();
		}

		return Modern_Field_Adaptations::apply( Settings_Layout::reshape( $assembled ) );
	}

	/**
	 * Normalize raw `woocommerce_subscription_settings` filter output into an array.
	 *
	 * A broken third-party callback returning null or a scalar must degrade to an empty settings list
	 * (the same defense {@see Classic_Renderer::apply_card_layout()} applies), not raise a TypeError -
	 * on this class's paths that would be a 500 on every REST/CLI settings request. The degrade is
	 * logged: it makes the settings surface come back empty with no error anywhere, so without a log
	 * line the misbehaving integration is undiagnosable from the response alone. No explicit log
	 * source: WooCommerce derives one from the calling plugin's directory. The culprit cannot be read
	 * off a backtrace - apply_filters() has already returned when the bad value is detected, so the
	 * callback frames are gone - which is why the entry carries the filter's registered callbacks
	 * instead: the list that narrows which integration to suspect.
	 *
	 * @param array|mixed $assembled Raw filter output.
	 * @return array
	 */
	private function normalize_assembled_settings( $assembled ): array {
		if ( is_array( $assembled ) ) {
			return $assembled;
		}

		wc_get_logger()->error(
			sprintf( 'A "woocommerce_subscription_settings" filter callback returned %s instead of an array; the Subscriptions settings degraded to an empty list.', gettype( $assembled ) ),
			array( 'registered_callbacks' => $this->get_settings_filter_callbacks() )
		);

		return array();
	}

	/**
	 * Describe the callbacks registered on 'woocommerce_subscription_settings', for the degrade log entry.
	 *
	 * @return string[] One "{priority}: {callback}" string per registered callback, closures named by
	 *                  file and line.
	 */
	private function get_settings_filter_callbacks(): array {
		global $wp_filter;

		if ( ! isset( $wp_filter['woocommerce_subscription_settings'] ) ) {
			return array();
		}

		$descriptions = array();

		foreach ( $wp_filter['woocommerce_subscription_settings']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_string( $function ) ) {
					$name = $function;
				} elseif ( is_array( $function ) && isset( $function[0], $function[1] ) ) {
					$name = ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) . '::' . $function[1];
				} elseif ( $function instanceof \Closure ) {
					$reflection = new \ReflectionFunction( $function );
					$name       = 'closure@' . $reflection->getFileName() . ':' . $reflection->getStartLine();
				} else {
					$name = 'unrecognized callback type ' . gettype( $function );
				}

				$descriptions[] = $priority . ': ' . $name;
			}
		}

		return $descriptions;
	}

	/**
	 * The composite key duplicate registrations are collapsed on: id and type together, because a
	 * section's `title` and `sectionend` markers legitimately share an id.
	 *
	 * @param array $setting A registered setting entry with an 'id'.
	 * @return string
	 */
	private function get_dedupe_key( array $setting ): string {
		return $setting['id'] . '|' . ( isset( $setting['type'] ) ? $setting['type'] : '' );
	}

	/**
	 * Compute the ids to remove from the REST-registered settings: display-only in either source array,
	 * unless the assembled (classic) array persists the id directly.
	 *
	 * @param array $assembled The assembled classic settings array.
	 * @param array $adapted   The modern-adapted settings array.
	 * @return array Map of id => true.
	 */
	private function get_rest_stripped_ids( array $assembled, array $adapted ): array {
		$persisting_ids = array();

		foreach ( $assembled as $field ) {
			if ( is_array( $field ) && isset( $field['id'] ) && ( ! isset( $field['is_option'] ) || false !== $field['is_option'] ) ) {
				$persisting_ids[ $field['id'] ] = true;
			}
		}

		$display_only_ids = $this->collect_display_only_ids( $assembled ) + $this->collect_display_only_ids( $adapted );

		return array_diff_key( $display_only_ids, $persisting_ids );
	}

	/**
	 * Collect the ids a settings array marks display-only (`is_option => false`).
	 *
	 * @param array $fields Settings fields.
	 * @return array Map of id => true.
	 */
	private function collect_display_only_ids( array $fields ): array {
		$ids = array();

		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['id'], $field['is_option'] ) && false === $field['is_option'] ) {
				$ids[ $field['id'] ] = true;
			}
		}

		return $ids;
	}

	/**
	 * The REST-writable option ids whose form fields the redesign decomposed away.
	 *
	 * The redesign replaced the "Prorate Recurring Payment", "Prorate Subscription Length", "Customer
	 * Suspensions" and APFS "Add to Subscription" selects with display-only controls that derive from -
	 * and pack back into - these same options, so the options themselves no longer appear as fields
	 * anywhere and would silently vanish from the REST group. The runtime still reads them directly
	 * (the switch totals calculator reads the proration pair, the suspension manager reads the maximum,
	 * the APFS product/cart modules read the channel selects), so REST reads and writes against these
	 * ids remain exactly as meaningful as they were in 9.1.0.
	 *
	 * The value sets are the full domains the runtime honors, not just what the pre-redesign selects
	 * offered: the proration pair includes the physical-only values the redesigned controls can pack
	 * (see Switching_Definitions and WCS_Switch_Totals_Calculator), and the suspensions maximum is a
	 * `number` entry matching the redesigned (unbounded) control's domain - any non-negative number, or
	 * 'unlimited' - validated by the suspension manager's sanitize filter rather than an options list.
	 * The deprecated `woocommerce_subscriptions_max_customer_suspension_range` filter is deliberately
	 * not applied (its return value is ignored since 9.2.0).
	 *
	 * The APFS entries mirror 9.1.0's own registration conditions: only while the `manage` module is
	 * registered, and the cart entry only on stores not using the block-based cart.
	 *
	 * @return array[] Settings entries in the REST registration format.
	 */
	private function get_legacy_rest_settings(): array {
		$option_prefix = WC_Subscriptions_Admin::$option_prefix;

		$settings = array(
			array(
				'id'          => $option_prefix . '_apportion_recurring_price',
				'label'       => __( 'Prorate Recurring Payment', 'woocommerce-subscriptions' ),
				'description' => __( 'When switching to a subscription with a different recurring payment or billing period, should the price paid for the existing billing period be prorated when switching to the new subscription?', 'woocommerce-subscriptions' ),
				'type'        => 'select',
				'option_key'  => $option_prefix . '_apportion_recurring_price',
				'default'     => 'no',
				'options'     => array(
					'no'               => _x( 'Never', 'when to allow a setting', 'woocommerce-subscriptions' ),
					'virtual-upgrade'  => _x( 'For Upgrades of Virtual Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'physical-upgrade' => _x( 'For Upgrades of Physical Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'yes-upgrade'      => _x( 'For Upgrades of All Subscription Products', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'virtual'          => _x( 'For Upgrades & Downgrades of Virtual Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'physical'         => _x( 'For Upgrades & Downgrades of Physical Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'yes'              => _x( 'For Upgrades & Downgrades of All Subscription Products', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
				),
			),
			array(
				'id'          => $option_prefix . '_apportion_length',
				'label'       => __( 'Prorate Subscription Length', 'woocommerce-subscriptions' ),
				'description' => __( 'When switching to a subscription with a length, you can take into account the payments already completed by the customer when determining how many payments the subscriber needs to make for the new subscription.', 'woocommerce-subscriptions' ),
				'type'        => 'select',
				'option_key'  => $option_prefix . '_apportion_length',
				'default'     => 'no',
				'options'     => array(
					'no'       => _x( 'Never', 'when to allow a setting', 'woocommerce-subscriptions' ),
					'virtual'  => _x( 'For Virtual Subscription Products Only', 'when to prorate first payment / subscription length', 'woocommerce-subscriptions' ),
					'physical' => _x( 'For Physical Subscription Products Only', 'when to prorate first payment / subscription length', 'woocommerce-subscriptions' ),
					'yes'      => _x( 'For All Subscription Products', 'when to prorate first payment / subscription length', 'woocommerce-subscriptions' ),
				),
			),
			/*
			 * A `number` entry rather than the pre-redesign select: the redesigned controls have no upper
			 * bound, and REST mirrors the form's domain - any non-negative number, or 'unlimited'. That
			 * domain is enforced by {@see \WCS_Customer_Suspension_Manager::sanitize_max_customer_suspensions()}
			 * on the generic-save path the REST controller uses (WooCommerce's own `number` validation is
			 * plain text cleaning).
			 */
			array(
				'id'          => $option_prefix . '_max_customer_suspensions',
				'label'       => __( 'Customer Suspensions', 'woocommerce-subscriptions' ),
				'description' => __( 'The maximum number of suspensions per billing period: a non-negative number, or "unlimited".', 'woocommerce-subscriptions' ),
				'type'        => 'number',
				'option_key'  => $option_prefix . '_max_customer_suspensions',
				'default'     => 0,
				'tip'         => __( 'Set a maximum number of times a customer can suspend their account for each billing period. For example, for a value of 3 and a subscription billed yearly, if the customer has suspended their account 3 times, they will not be presented with the option to suspend their account until the next year. Store managers will always be able to suspend an active subscription. Set this to 0 to turn off the customer suspension feature completely.', 'woocommerce-subscriptions' ),
			),
		);

		/*
		 * Mirror 9.1.0's own registration conditions for the APFS pair. is_callable() rather than a bare
		 * function/class existence check, because old STANDALONE All Products for Subscriptions builds
		 * predate these methods (is_module_registered arrived in standalone 3.2.0, is_block_based_cart in
		 * 3.3.0), the standalone plugin wins the load order over the bundled copy, and no minimum
		 * standalone version is enforced - degrade to omitting the entries there instead of fataling
		 * the REST request.
		 */
		if ( function_exists( 'WCS_ATT' ) && is_callable( array( \WCS_ATT(), 'is_module_registered' ) ) && \WCS_ATT()->is_module_registered( 'manage' ) ) {
			$settings[] = array(
				'id'          => 'wcsatt_add_product_to_subscription',
				'label'       => __( 'Products', 'woocommerce-subscriptions' ),
				'description' => __( 'Allow customers to add individual products to existing subscriptions.', 'woocommerce-subscriptions' ),
				'type'        => 'select',
				'option_key'  => 'wcsatt_add_product_to_subscription',
				'default'     => 'off',
				'options'     => array(
					'off'              => _x( 'Disabled', 'adding a product to an existing subscription', 'woocommerce-subscriptions' ),
					'matching_schemes' => _x( 'Enabled for products with Subscription Plans', 'adding a product to an existing subscription', 'woocommerce-subscriptions' ),
					'on'               => _x( 'Enabled', 'adding a product to an existing subscription', 'woocommerce-subscriptions' ),
				),
			);

			/*
			 * Relying on the loaded WCS_ATT_Integrations class is safe here, unlike the lazily-loaded
			 * integration class behind WOOSUBS-1732: both the bundled and the standalone loaders require
			 * this class eagerly and unconditionally, so the loaded class is always the active
			 * implementation. is_callable() additionally covers standalone builds older than 3.3.0,
			 * which predate is_block_based_cart().
			 */
			if ( is_callable( array( 'WCS_ATT_Integrations', 'is_block_based_cart' ) ) && ! \WCS_ATT_Integrations::is_block_based_cart() ) {
				$settings[] = array(
					'id'          => 'wcsatt_add_cart_to_subscription',
					'label'       => __( 'Cart Contents', 'woocommerce-subscriptions' ),
					'description' => __( 'Allow customers to add their cart contents to an existing subscription.', 'woocommerce-subscriptions' ),
					'type'        => 'select',
					'option_key'  => 'wcsatt_add_cart_to_subscription',
					'default'     => 'off',
					'options'     => array(
						'off'        => _x( 'Disabled', 'adding a cart to an existing subscription', 'woocommerce-subscriptions' ),
						'plans_only' => _x( 'Enabled when cart contents have Subscription Plans', 'adding a cart to an existing subscription', 'woocommerce-subscriptions' ),
						'on'         => _x( 'Enabled', 'adding a cart to an existing subscription', 'woocommerce-subscriptions' ),
					),
				);
			}
		}

		return $settings;
	}

	/**
	 * Collapse duplicate registrations of the subscriptions group in the REST settings index.
	 *
	 * Since 9.2.0 the group entry normally arrives once, from WooCommerce's WC_Register_WP_Admin_Settings
	 * for this page. The `woocommerce_settings_groups` filter is a public surface though, and the
	 * deprecated WC_REST_Subscriptions_Settings mirror registers the same group if something still
	 * constructs it - keep the first 'subscriptions' entry and drop the rest.
	 *
	 * @param array|mixed $groups Settings groups registered with the REST settings index.
	 * @return array|mixed
	 */
	public function remove_duplicate_rest_group( $groups ) {
		if ( ! is_array( $groups ) ) {
			return $groups;
		}

		$seen   = false;
		$result = array();

		foreach ( $groups as $group ) {
			if ( is_array( $group ) && isset( $group['id'] ) && $this->id === $group['id'] ) {
				if ( $seen ) {
					continue;
				}

				$seen = true;
			}

			$result[] = $group;
		}

		return $result;
	}

	/**
	 * Opt the Subscriptions tab into the modern settings UI.
	 *
	 * This is the single gate that decides classic vs. modern for the whole page. WooCommerce core routes the
	 * request to the modern renderer only when this returns a page adapter (its request context resolves to null
	 * otherwise — see SettingsUIRequestContext::get_current_settings_ui_page()), and {@see self::output()} keys
	 * its own branch off the same method. Gating here therefore keeps rendering and persistence in agreement.
	 *
	 * Returns null (i.e. stays on the classic renderer) when the modern experience is not active for this store
	 * (see {@see self::is_settings_ui_active()}), or when the running version of WooCommerce predates the
	 * settings-ui adapter classes, so this remains safe on stores that have not yet picked up that core work.
	 *
	 * @return SettingsUIPageInterface|null
	 */
	public function get_settings_ui_page(): ?SettingsUIPageInterface {
		if ( ! $this->is_settings_ui_active() ) {
			return null;
		}

		if ( ! class_exists( '\Automattic\WooCommerce\Admin\Settings\LegacySettingsPageAdapter' ) ) {
			return null;
		}

		return new Settings_Page_Adapter( $this );
	}

	/**
	 * Whether the modern (settings-ui) experience is active for Subscriptions on this request.
	 *
	 * Routes the classic/modern decision through the same {@see Settings_Ui_Feature_Flag} seam that governs
	 * settings persistence (see {@see \Automattic\WooCommerce_Subscriptions\Settings}), so the rendered UI and
	 * the read/write namespace can never disagree. Crucially this honours the experimental
	 * `WCS_EXPERIMENTAL_MODERN_SETTINGS_UI` opt-in: the React renderer is a code-only opt-in, off by default,
	 * even on a store that has enabled Core's `settings-ui` feature to exercise the surrounding UI work.
	 *
	 * @return bool
	 */
	private function is_settings_ui_active(): bool {
		return ( new Settings_Ui_Feature_Flag() )->is_active();
	}

	/**
	 * Output the settings page.
	 *
	 * In modern-UI mode we let the parent print the React mount point, then re-emit the Subscriptions nonce:
	 * the modern app saves by posting the surrounding settings <form>, and the classic save handler
	 * ({@see WC_Subscriptions_Admin::update_subscription_settings()}) still verifies that nonce. In classic
	 * mode we defer to the existing renderer, which outputs the informational fields and the nonce exactly as
	 * before, bracketed by the {@see Classic_Renderer} render-phase latch so the settings array assembled for
	 * this render - and only this render - is reshaped into the card layout. The latch (rather than the
	 * request method) is what separates rendering from saving: WooCommerce saves and re-renders in the same
	 * POST request, with the save handler running before this method, while the latch is still closed. The
	 * `finally` guarantees the latch closes even when the renderer throws.
	 */
	public function output() {
		// This object can be in memory while the renderer classes' files are gone mid plugin-file-swap;
		// render nothing for the remainder of the request rather than fatal on the references below.
		// See WC_Subscriptions_Admin::are_settings_classes_loadable().
		if ( ! WC_Subscriptions_Admin::are_settings_classes_loadable() ) {
			return;
		}

		if ( $this->should_render_settings_ui() ) {
			parent::output();
			wp_nonce_field( 'wcs_subscription_settings', '_wcsnonce', false );
			return;
		}

		Classic_Renderer::begin_render();

		try {
			WC_Subscriptions_Admin::subscription_settings_page();
		} finally {
			Classic_Renderer::end_render();
		}
	}

	/**
	 * Saving is intentionally a no-op here.
	 *
	 * {@see WC_Subscriptions_Admin::update_subscription_settings()} remains hooked to
	 * `woocommerce_update_options_subscriptions` and performs the Subscriptions-specific save (the composite
	 * "allow switching" handling, the manual/automatic renewal interplay, default fall-backs). Overriding the
	 * parent — which would otherwise run WooCommerce's generic save_fields() routine — prevents the submitted
	 * values from being processed twice.
	 */
	public function save() {}

	/**
	 * Determine whether the modern settings UI should render for the current request.
	 *
	 * Mirrors the gate WooCommerce core applies in WC_Settings_Page::output() so our classic/modern branch
	 * decision matches the parent's: the feature must be enabled, an adapter must be available, and schema
	 * generation for this page/section must not have already failed.
	 *
	 * The Subscriptions opt-in (the `WCS_EXPERIMENTAL_MODERN_SETTINGS_UI` gate) is enforced through the adapter check:
	 * {@see self::get_settings_ui_page()} returns null unless {@see self::is_settings_ui_active()} passes, so
	 * the bare `settings-ui` feature check below is only the Core half of the mirror, not the authoritative gate.
	 *
	 * @return bool
	 */
	private function should_render_settings_ui() {
		global $current_section;

		if ( ! class_exists( '\Automattic\WooCommerce\Admin\Features\Features' ) || ! Features::is_enabled( 'settings-ui' ) ) {
			return false;
		}

		if ( ! $this->get_settings_ui_page() instanceof SettingsUIPageInterface ) {
			return false;
		}

		$section_key = '' === $current_section ? 'default' : (string) $current_section;

		return empty( $GLOBALS['wc_settings_ui_schema_failed'][ $this->id ][ $section_key ] );
	}
}
