<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings;

use Automattic\WooCommerce_Subscriptions\Internal\Settings\Settings_Ui_Feature_Flag;
use WC_Subscriptions;
use WC_Subscriptions_Admin;

/**
 * Renders the classic (PHP-rendered) Subscriptions settings tab as the redesigned one-column,
 * card-grouped screen. This is the shipped settings experience for every store.
 *
 * Division of responsibilities: the layout itself - which sections exist, their order, grouping
 * and field moves - lives solely in {@see Settings_Layout}, shared by both renderers. This class
 * is the CLASSIC renderer's adapter: it translates that shared layout into what WooCommerce's
 * table renderer can display (card wrapper markup + per-field normalization). The experimental
 * React renderer has its own thin adapter in {@see Modern_Field_Adaptations}.
 *
 * The technique is entirely additive, through documented extension points, so WooCommerce's field
 * renderer and the plugin's back-compat surface (option/section ids, the
 * `woocommerce_subscription_settings` filter, `woocommerce_settings_*` actions, `.form-table` and
 * existing field classnames) are untouched:
 *
 *   - {@see apply_card_layout()} delegates the layout (card order, Miscellaneous split, field moves) to
 *     {@see Settings_Layout} - the definition shared with the experimental React renderer - then
 *     injects zero-cost display fields at each section boundary. Those fields carry custom `type`s
 *     that WooCommerce hands to `woocommerce_admin_field_{type}` - the same seam this plugin
 *     already uses for its `informational` / `wcs_switching_options` / web-cron field types - and
 *     our handlers emit the card wrapper `<div>`s. `WC_Admin_Settings::output_fields()` is never
 *     touched. The same pass normalizes fields to the card presentation (checkbox `<th>` headings
 *     blanked, hover tooltips folded into persistent help).
 *   - {@see enqueue_styles()} loads the compiled card stylesheet
 *     (`build/style-settings-classic.css`), which collapses the `.form-table` two-column grid into
 *     the stacked, carded layout.
 *
 * Reshaping runs only while the render-phase latch is open ({@see begin_render()} /
 * {@see end_render()}, operated by {@see Settings_Page::output()} around the classic tab render).
 * WooCommerce's settings controller saves and re-renders in the same POST request, so the latch -
 * not the request method - is what separates the two phases: the save handler runs before output
 * (latch closed) and sees the pristine array, as does every other consumer of
 * {@see WC_Subscriptions_Admin::get_settings()} (tracker, REST, CLI - none of them open the
 * latch). The stylesheet is enqueued on any request for the settings tab screen
 * (`admin_enqueue_scripts` fires before output, while the latch is still closed, so it cannot
 * depend on it). When the experimental React renderer is active ({@see Settings_Ui_Feature_Flag})
 * this renderer is inert: that renderer lays itself out.
 *
 * @internal This class is used internally by WooCommerce Subscriptions. It is not intended for
 *           third party use, and may change at any time.
 */
class Classic_Renderer {

	/**
	 * Custom `type` for the display field that opens the outer layout wrapper.
	 */
	const FIELD_LAYOUT_START = 'woocommerce_subscriptions_settings_layout_start';

	/**
	 * Custom `type` for the display field that closes the outer layout wrapper.
	 */
	const FIELD_LAYOUT_END = 'woocommerce_subscriptions_settings_layout_end';

	/**
	 * Custom `type` for the display field that opens a section card.
	 */
	const FIELD_CARD_START = 'woocommerce_subscriptions_settings_card_start';

	/**
	 * Custom `type` for the display field that closes a section card.
	 */
	const FIELD_CARD_END = 'woocommerce_subscriptions_settings_card_end';

	/**
	 * Stylesheet handle.
	 */
	const STYLE_HANDLE = 'wcs-settings-classic';

	/**
	 * Opt-in marker for a visible checkbox-group heading.
	 *
	 * {@see normalize_field()} preserves a checkbox group's heading as the fieldset `<legend>`,
	 * which the card stylesheet hides visually (screen-reader-only) because the design shows most
	 * groups as flat lists. A group whose first checkbox carries this value in its `class` key
	 * renders the legend visibly instead, styled as the design's group sub-heading (used by the
	 * switcher's "Apply proration/renewals" product-type pairs).
	 */
	const CLASS_VISIBLE_LEGEND = 'wcs-visible-legend';

	/**
	 * Opt-in marker for suppressing a WCS-owned checkbox row heading.
	 *
	 * Unmarked checkbox fields retain their `name` and `title`, preserving extension-supplied copy.
	 */
	const CLASS_HIDE_CHECKBOX_TITLE = 'woocommerce-subscriptions-settings__hide-checkbox-title';

	/**
	 * Whether the classic settings tab is currently rendering (the render-phase latch).
	 *
	 * Opened by {@see begin_render()} and closed by {@see end_render()}; {@see apply_card_layout()}
	 * only applies while it is open.
	 *
	 * @var bool
	 */
	private static $render_phase = false;

	/**
	 * Open the render-phase latch.
	 *
	 * Called by {@see Settings_Page::output()} immediately before delegating to the classic
	 * settings renderer, so the `woocommerce_subscription_settings` filter run triggered by that
	 * render - and only that run - is reshaped into the card layout.
	 */
	public static function begin_render() {
		self::$render_phase = true;
	}

	/**
	 * Close the render-phase latch.
	 *
	 * Called by {@see Settings_Page::output()} in a `finally` block, so the latch cannot be left
	 * open even when the render throws.
	 */
	public static function end_render() {
		self::$render_phase = false;
	}

	/**
	 * Register the renderer's hooks.
	 *
	 * Ordering contract on `woocommerce_subscription_settings`: the reshape hooks at priority
	 * 10000 so it runs after every known section-adder (the APFS storewide-plans and
	 * Add-to-Subscription managers hook at 1000 and 998, product creation and the health check at
	 * 999, queue processing at 1000 with its external-trigger URL at 1001), giving it the
	 * fully-assembled array to reorder and wrap. Third-party callbacks below 10000 are reshaped
	 * with everything else; a callback hooked above 10000 receives, during a classic tab render
	 * only, the card-wrapped array ({@see apply_card_layout()}): display fields with the custom card/layout
	 * `type`s interleaved and fields normalized per {@see normalize_field()}. That is a
	 * display-only blast radius - the save handler and every other consumer of
	 * {@see WC_Subscriptions_Admin::get_settings()} run with the latch closed and always see the
	 * pristine array.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'apply_card_layout' ), 10000 );

		add_action( 'woocommerce_admin_field_' . self::FIELD_LAYOUT_START, array( __CLASS__, 'render_layout_start' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_LAYOUT_END, array( __CLASS__, 'render_layout_end' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_CARD_START, array( __CLASS__, 'render_card_start' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_CARD_END, array( __CLASS__, 'render_card_end' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 20 );
	}

	/**
	 * Reshape the assembled settings into the redesign's card layout for rendering.
	 *
	 * Delegates the layout itself (card order, Miscellaneous split, field moves/merges) to
	 * {@see Settings_Layout::reshape()}, then wraps each section block in card start/end display
	 * fields and normalizes each field for the card presentation ({@see normalize_field()}).
	 *
	 * Runs only while the render-phase latch is open on the classic Subscriptions settings tab:
	 * it bails on the experimental React renderer (that renderer lays itself out) and whenever the
	 * latch is closed (so the save handler - which runs earlier in the same POST request - and any
	 * other consumer of {@see WC_Subscriptions_Admin::get_settings()} see the pristine array).
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	public static function apply_card_layout( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return $settings;
		}

		if ( ! self::$render_phase ) {
			return $settings;
		}

		if ( ( new Settings_Ui_Feature_Flag() )->is_active() ) {
			return $settings;
		}

		if ( ! self::is_subscriptions_settings_screen() ) {
			return $settings;
		}

		$settings = Settings_Layout::reshape( $settings );

		list( $leading, $blocks, $trailing ) = Settings_Layout::split_into_sections( $settings );

		if ( empty( $blocks ) ) {
			return $settings;
		}

		$out   = $leading;
		$out[] = array( 'type' => self::FIELD_LAYOUT_START );

		foreach ( $blocks as $block ) {
			$out[] = array(
				'type'       => self::FIELD_CARD_START,
				'section_id' => $block['id'],
			);

			foreach ( $block['fields'] as $field ) {
				$out[] = self::normalize_field( $field );
			}

			$out[] = array( 'type' => self::FIELD_CARD_END );
		}

		$out[] = array( 'type' => self::FIELD_LAYOUT_END );

		// Loose fields sitting after the sections (an extension seam) render after the cards,
		// outside the layout wrapper, mirroring how leading fields render before it.
		foreach ( $trailing as $field ) {
			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Normalize a field for the card presentation, by type:
	 *
	 *   - Marked WCS-owned checkbox: blank `name`/`title` so it renders as a flat control (label +
	 *     help), instead of classic WooCommerce's extra bold `<th>` sub-heading. Unmarked checkbox
	 *     fields retain their headings so extension copy is preserved. On a marked group-start
	 *     checkbox the heading copy is preserved as an explicit `legend` first, so the fieldset
	 *     keeps its accessible group name (WCAG 1.3.1). The stylesheet keeps legends
	 *     screen-reader-only unless the group opts into a visible heading
	 *     ({@see CLASS_VISIBLE_LEGEND}).
	 *   - Select / text / other inputs: move any hover-tooltip copy (`desc_tip`) into the persistent
	 *     description (`desc`) and drop the tooltip, so help reads as text beneath the control like
	 *     the redesign, rather than a `?` tip. The field's own label (`<th>`) is left intact.
	 *
	 * @param array $field A settings field.
	 * @return array
	 */
	private static function normalize_field( $field ) {
		if ( ! is_array( $field ) || ! isset( $field['type'] ) ) {
			return $field;
		}

		if ( 'checkbox' === $field['type'] ) {
			$classes = isset( $field['class'] ) && is_string( $field['class'] )
				? preg_split( '/\s+/', trim( $field['class'] ) )
				: array();

			if ( ! is_array( $classes ) || ! in_array( self::CLASS_HIDE_CHECKBOX_TITLE, $classes, true ) ) {
				return $field;
			}

			if ( isset( $field['checkboxgroup'] ) && 'start' === $field['checkboxgroup'] && ! isset( $field['legend'] ) ) {
				$heading = '';

				foreach ( array( 'title', 'name' ) as $key ) {
					if ( isset( $field[ $key ] ) && is_string( $field[ $key ] ) && '' !== $field[ $key ] ) {
						$heading = $field[ $key ];
						break;
					}
				}

				if ( '' !== $heading ) {
					$field['legend'] = $heading;
				}
			}

			$field['name']  = '';
			$field['title'] = '';

			return $field;
		}

		// Convert a hover tooltip to persistent help text. `desc_tip` may be a string (the tip copy)
		// or boolean true (use `desc` as the tip). Either way, fold the copy into `desc` and clear
		// `desc_tip` so WooCommerce renders a persistent `<p class="description">` instead of a `?`
		// tip. When both keys carry copy (classic WC renders the description persistently PLUS a
		// tooltip - an extension-field shape), the tip copy is appended rather than dropped: both
		// sentences end up in the one rendered description.
		if ( ! empty( $field['desc_tip'] ) ) {
			if ( is_string( $field['desc_tip'] ) ) {
				$has_desc = isset( $field['desc'] ) && is_string( $field['desc'] ) && '' !== $field['desc'];

				$field['desc'] = $has_desc ? $field['desc'] . ' ' . $field['desc_tip'] : $field['desc_tip'];
			}
			$field['desc_tip'] = false;
		}

		return $field;
	}

	/**
	 * Whether the current request is for the classic Subscriptions settings tab screen.
	 *
	 * Screen detection only (admin context plus the `page`/`tab` query args, which WooCommerce's
	 * settings form preserves on its POST action URL, so save requests match too). The render/save
	 * separation within a matching request is the render-phase latch, not this check.
	 *
	 * @return bool
	 */
	private static function is_subscriptions_settings_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && WC_Subscriptions_Admin::$tab_name === $tab;
	}

	/**
	 * Enqueue the compiled card stylesheet on the classic Subscriptions settings tab.
	 *
	 * Deliberately independent of the render-phase latch: `admin_enqueue_scripts` fires before
	 * {@see Settings_Page::output()} opens it, and the stylesheet must also load on the POST
	 * save-and-re-render request.
	 */
	public static function enqueue_styles() {
		if ( ( new Settings_Ui_Feature_Flag() )->is_active() ) {
			return;
		}

		if ( ! self::is_subscriptions_settings_screen() ) {
			return;
		}

		$version    = WC_Subscriptions::$version;
		$asset_path = \WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'build/settings-classic.asset.php' );

		if ( file_exists( $asset_path ) ) {
			$asset = require $asset_path;

			if ( isset( $asset['version'] ) ) {
				$version = $asset['version'];
			}
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'build/style-settings-classic.css', WC_Subscriptions::$plugin_file ),
			array( 'woocommerce_subscriptions_admin' ),
			$version
		);
	}

	/**
	 * Open the outer single-column layout wrapper. Rendered via `woocommerce_admin_field_*`.
	 */
	public static function render_layout_start() {
		echo '<div class="woocommerce-subscriptions-settings">';
	}

	/**
	 * Close the outer single-column layout wrapper. Rendered via `woocommerce_admin_field_*`.
	 */
	public static function render_layout_end() {
		echo '</div>';
	}

	/**
	 * The card's element id for a section, or '' when the section id yields no valid identifier.
	 *
	 * Deep links (an admin notice pointing at the section it is about) resolve their fragment
	 * through here so the anchor and the rendered markup cannot drift apart.
	 *
	 * @since 9.2.0
	 *
	 * @param string $section_id The section's settings id.
	 * @return string
	 */
	public static function card_element_id( $section_id ) {
		if ( ! is_string( $section_id ) || '' === $section_id ) {
			return '';
		}

		$modifier = sanitize_html_class( $section_id );

		return '' === $modifier ? '' : 'woocommerce-subscriptions-settings-card-' . $modifier;
	}

	/**
	 * Open a section card. Rendered via `woocommerce_admin_field_*`.
	 *
	 * The card carries a `--{section_id}` BEM modifier (when the section id yields a valid class
	 * name) so individual cards can be targeted for additive tweaks without new markup, plus a
	 * matching element id so a link can scroll the section into view. `tabindex="-1"` keeps the
	 * card out of the tab order while letting a fragment link move focus to it, so the jump is
	 * announced rather than being a silent scroll.
	 *
	 * @param array $field The display field ({@see apply_card_layout()} sets `section_id`).
	 */
	public static function render_card_start( $field ) {
		$classes = 'woocommerce-subscriptions-settings__card';
		$id      = isset( $field['section_id'] ) ? self::card_element_id( $field['section_id'] ) : '';

		if ( '' !== $id ) {
			$classes .= ' woocommerce-subscriptions-settings__card--' . sanitize_html_class( $field['section_id'] );
		}

		echo '<div class="' . esc_attr( $classes ) . '"';

		if ( '' !== $id ) {
			echo ' id="' . esc_attr( $id ) . '" tabindex="-1"';
		}

		echo '>';
	}

	/**
	 * Close a section card. Rendered via `woocommerce_admin_field_*`.
	 */
	public static function render_card_end() {
		echo '</div>';
	}
}
