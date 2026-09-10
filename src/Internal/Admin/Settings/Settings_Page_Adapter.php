<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings;

use Automattic\WooCommerce\Admin\Settings\LegacySettingsPageAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts the Subscriptions settings tab for WooCommerce's modern ("settings-ui") settings renderer.
 *
 * WooCommerce core's modern settings experience consumes a canonical schema produced from a legacy
 * settings array. The parent {@see LegacySettingsPageAdapter} performs that translation wholesale, so this
 * subclass only layers on Subscriptions-specific presentation (currently just the shell title). As the WCS
 * settings grow richer modern-UI affordances — custom field components, per-field option lists, script
 * handles — they belong here.
 *
 * Lives under Internal\ deliberately: the modern settings UI in WooCommerce core is still in active
 * development (gated behind the `settings-ui` feature flag), so this class is expected to be refined as
 * that API settles, and we make no back-compat commitment for it.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Settings_Page_Adapter extends LegacySettingsPageAdapter {

	/**
	 * Build the canonical settings schema for a section.
	 *
	 * @param string $section Section id. Empty string means the default section.
	 * @return array
	 */
	public function get_schema( string $section ): array {
		$schema = parent::get_schema( $section );

		$schema['shell']['title'] = __( 'Subscriptions settings', 'woocommerce-subscriptions' );

		return $schema;
	}

	/**
	 * Script handles the modern settings app must load before it mounts.
	 *
	 * Returns the Subscriptions settings-ui extension bundle, which registers our field-component overrides with
	 * WooCommerce's renderer (e.g. the value-dependent help on the "Billing date alignment" select). WooCommerce
	 * core enqueues these handles only while it renders this page through the modern renderer, so the extension is
	 * never loaded on the classic tab. The handle is registered in {@see \WCS_Admin_Assets::enqueue_scripts()};
	 * returning it here is a no-op if the build asset is absent (the handle simply will not be registered).
	 *
	 * @param string $section Section id. Empty string means the default section.
	 * @return string[]
	 */
	public function get_script_handles( string $section ): array {
		return array( 'wcs-settings-ui' );
	}
}
