<?php

namespace Automattic\WooCommerce_Subscriptions;

use Automattic\WooCommerce_Subscriptions\Internal\Settings\Registry;

/**
 * Public accessor for WooCommerce Subscriptions settings.
 *
 * Subscriptions settings persist under a single namespace: the `woocommerce_subscriptions_*`
 * options. The redesigned settings screens (the classic renderer and the experimental React
 * renderer alike) write back to those same options, so the stored option state is always the
 * single source of truth.
 *
 * What this accessor adds is per-key read resolution for the redesigned settings vocabulary: a
 * registered key (e.g. `switch_allow_variations`) has no stored option of its own - it derives its
 * value from the real option(s) it decomposes ({@see Registry}) - while an unregistered key passes
 * straight through to the option of the same name.
 *
 * It lives outside `Internal\` deliberately. Third-party developers may need to read Subscriptions
 * settings without coupling to specific option keys, so the static facade - `Settings::get( 'key' )`
 * and `Settings::prefix()` - is intended as supported API. Those statics delegate to a canonical
 * instance; the instance carries its dependency (the settings registry) so the resolution logic
 * can be unit-tested in isolation. The constructor is internal wiring, not part of the consumer
 * API.
 */
class Settings {
	/**
	 * The option prefix Subscriptions settings persist under.
	 *
	 * A single namespace: the earlier dual-namespace ("modern prefix") exploration was retired when
	 * the redesigned screens were harmonized to write back to the real options.
	 */
	private const OPTION_PREFIX = 'woocommerce_subscriptions';

	/**
	 * Canonical instance backing the static facade and the plugin accessor.
	 *
	 * @var Settings|null
	 */
	private static ?Settings $instance = null;

	/**
	 * The redesigned-settings registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @internal Constructed via {@see self::instance()} (or the plugin's `settings()` accessor). The
	 * explicit dependency exists for injection in tests, not as consumer API.
	 *
	 * @param Registry $registry The redesigned-settings registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The canonical instance, lazily built with default dependencies.
	 *
	 * @return Settings
	 */
	public static function instance(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self( Registry::create_default() );
		}

		return self::$instance;
	}

	/**
	 * Replace (or reset) the canonical instance.
	 *
	 * @internal Test seam. Pass null to rebuild the default instance on next access.
	 *
	 * @param Settings|null $instance Instance to use as canonical.
	 */
	public static function set_instance( ?Settings $instance ): void {
		self::$instance = $instance;
	}

	/**
	 * Get a Subscriptions setting.
	 *
	 * Static facade over the canonical instance.
	 *
	 * @param string $key           Setting key: the suffix after the prefix, with or without a leading
	 *                              underscore (e.g. 'allow_switching' or '_allow_switching').
	 * @param mixed  $default_value Value returned when an *unregistered* key has no stored option. It
	 *                              does not apply to registered (redesigned) keys: those always derive
	 *                              from the stored options, so they have no unset state for a default
	 *                              to cover. See {@see self::get_value()}.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = false ) {
		return self::instance()->get_value( $key, $default_value );
	}

	/**
	 * Get the option prefix Subscriptions settings persist under.
	 *
	 * Static facade over the canonical instance.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		return self::instance()->get_prefix();
	}

	/**
	 * Instance implementation of {@see self::get()}.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Value returned only on the unregistered pass-through path; ignored
	 *                              for registered keys, which always derive their value.
	 * @return mixed
	 */
	public function get_value( string $key, $default_value = false ) {
		// Not a redesigned setting: pass straight through to the option of the same name.
		if ( ! $this->registry->has( $key ) ) {
			return get_option( $this->option_name( $key ), $default_value );
		}

		// Redesigned setting: derive the value from the stored options. The redesigned controls write
		// back to their real options (via the save-packers on `woocommerce_update_options_subscriptions`),
		// so the stored state is the single source of truth and a registered key never has a stored value
		// of its own.
		return $this->registry->derive( $key );
	}

	/**
	 * Instance implementation of {@see self::prefix()}.
	 *
	 * @return string
	 */
	public function get_prefix(): string {
		return self::OPTION_PREFIX;
	}

	/**
	 * Build the fully-qualified option name for a key.
	 *
	 * @param string $key Setting key (leading underscore optional).
	 * @return string
	 */
	private function option_name( string $key ): string {
		return self::OPTION_PREFIX . '_' . ltrim( $key, '_' );
	}
}
