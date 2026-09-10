<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Settings;

use InvalidArgumentException;

/**
 * Registry of redesigned settings: the decomposed keys the redesigned settings vocabulary exposes
 * and how each derives its value from the stored (real) option(s).
 *
 * Only sections whose redesigned shape decomposes stored options register here - Switching and
 * Gifting, whose experimental React cards expose per-concern controls that have no stored option
 * of their own. Fully harmonized sections read and write their real options directly and need no
 * derivation. A key that is not registered is, by definition, not a redesigned setting; callers
 * fall through to the option of the same name transparently (handled in
 * {@see \Automattic\WooCommerce_Subscriptions\Settings}).
 *
 * @internal
 */
class Registry {
	/**
	 * Registered settings, keyed by the bare setting key (suffix without a leading underscore).
	 *
	 * Each value is a derivation callable mapping the current stored option(s) to this setting's
	 * value, used by derive-on-read.
	 *
	 * @var array<string, callable>
	 */
	private array $definitions = array();

	/**
	 * Build the default registry, populated with every redesigned section's definitions.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$registry = new self();

		Switching_Definitions::register( $registry );
		Gifting_Definitions::register( $registry );
		// Only sections whose redesigned controls decompose stored options register here. The
		// Renewals, Suspensions and Add to Subscription definitions were retired when those
		// sections were fully harmonized at source (their controls read and write real options).

		return $registry;
	}

	/**
	 * Register a redesigned setting and how to derive its value from the legacy option(s).
	 *
	 * @param string   $key    Setting key (the suffix after the prefix; leading underscore optional).
	 * @param callable $derive Derivation mapping the current legacy option(s) to this setting's value.
	 */
	public function register( string $key, callable $derive ): void {
		$this->definitions[ $this->normalize( $key ) ] = $derive;
	}

	/**
	 * Whether a key is a registered (redesigned) setting.
	 *
	 * @param string $key Setting key (leading underscore optional).
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->definitions[ $this->normalize( $key ) ] );
	}

	/**
	 * Derive a registered setting's value from the current legacy options.
	 *
	 * @param string $key Setting key. Must be registered ({@see self::has()}).
	 * @return mixed
	 * @throws InvalidArgumentException If the key is not registered.
	 */
	public function derive( string $key ) {
		$normalized = $this->normalize( $key );

		if ( ! isset( $this->definitions[ $normalized ] ) ) {
			throw new InvalidArgumentException( 'No derivation registered for setting: ' . esc_html( $key ) );
		}

		return ( $this->definitions[ $normalized ] )();
	}

	/**
	 * Reduce a key to its bare suffix (no leading underscore) for consistent lookup.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function normalize( string $key ): string {
		return ltrim( $key, '_' );
	}
}
