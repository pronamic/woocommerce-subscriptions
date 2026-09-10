<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Settings;

/**
 * Registry definitions for the redesigned Switching section.
 *
 * Translates the legacy Switching options into the redesigned (decomposed) keys via the
 * derivations used by derive-on-read. The behavioural mapping follows the redesign's migration
 * plan; the decomposed value vocabulary is defined here (it is what the experimental settings-ui
 * renderer reads; saves are packed back into the legacy options by the classic save-packers).
 *
 * Legacy → decomposed at a glance:
 *  - `_allow_switching` (composite no|variable|grouped|variable_grouped) → three checkboxes.
 *  - `_allow_switching_product_plans` (yes/no, default yes) → the plans checkbox.
 *  - `_apportion_recurring_price` (7 values, incl. the physical-only pair) → first-billing behaviour + Virtual/Physical pair.
 *  - `_apportion_sign_up_fee` (3 values) → sign-up fee behaviour.
 *  - `_apportion_length` (4 values, incl. physical-only) → fixed-term behaviour + Virtual/Physical pair.
 *  - `_switch_button_text` → button text (label preserved; see note below).
 *
 * @internal
 */
class Switching_Definitions {
	private const LEGACY_ALLOW_SWITCHING       = 'woocommerce_subscriptions_allow_switching';
	private const LEGACY_ALLOW_SWITCHING_PLANS = 'woocommerce_subscriptions_allow_switching_product_plans';
	private const LEGACY_APPORTION_RECURRING   = 'woocommerce_subscriptions_apportion_recurring_price';
	private const LEGACY_APPORTION_SIGN_UP_FEE = 'woocommerce_subscriptions_apportion_sign_up_fee';
	private const LEGACY_APPORTION_LENGTH      = 'woocommerce_subscriptions_apportion_length';
	private const LEGACY_SWITCH_BUTTON_TEXT    = 'woocommerce_subscriptions_switch_button_text';

	/**
	 * Register the Switching section's settings with the registry.
	 *
	 * @param Registry $registry Registry to populate.
	 */
	public static function register( Registry $registry ): void {
		// Allow switching — the composite splits into two checkboxes; plans is a separate option.
		$registry->register(
			'switch_allow_variations',
			static function () {
				return false !== strpos( (string) get_option( self::LEGACY_ALLOW_SWITCHING, 'no' ), 'variable' ) ? 'yes' : 'no';
			}
		);
		$registry->register(
			'switch_allow_grouped',
			static function () {
				return false !== strpos( (string) get_option( self::LEGACY_ALLOW_SWITCHING, 'no' ), 'grouped' ) ? 'yes' : 'no';
			}
		);
		$registry->register(
			'switch_allow_plans',
			static function () {
				return 'yes' === get_option( self::LEGACY_ALLOW_SWITCHING_PLANS, 'yes' ) ? 'yes' : 'no';
			}
		);

		// First billing behaviour (was "Prorate Recurring Payment") → behaviour + product-type pair.
		$registry->register(
			'switch_first_billing_behavior',
			static function () {
				// Cast before the loose `switch`: a non-string stored value (e.g. int 0 from a filter) would
				// otherwise match the first string case under PHP 7.x comparison rules.
				switch ( (string) get_option( self::LEGACY_APPORTION_RECURRING, 'no' ) ) {
					case 'virtual-upgrade':
					case 'yes-upgrade':
					case 'physical-upgrade':
						return 'upgrades';
					case 'virtual':
					case 'yes':
					case 'physical':
						return 'upgrades_and_downgrades';
					default:
						return 'full';
				}
			}
		);
		$registry->register(
			'switch_first_billing_virtual',
			static function () {
				// Virtual applies to the Virtual-only and All values; the physical-only and "Never" values do not.
				return in_array( get_option( self::LEGACY_APPORTION_RECURRING, 'no' ), array( 'virtual-upgrade', 'virtual', 'yes-upgrade', 'yes' ), true ) ? 'yes' : 'no';
			}
		);
		$registry->register(
			'switch_first_billing_physical',
			static function () {
				// Physical applies to the Physical-only and All values.
				return in_array( get_option( self::LEGACY_APPORTION_RECURRING, 'no' ), array( 'physical-upgrade', 'physical', 'yes-upgrade', 'yes' ), true ) ? 'yes' : 'no';
			}
		);

		// Sign-up fee behaviour (was "Prorate Sign up Fee").
		$registry->register(
			'switch_signup_fee_behavior',
			static function () {
				// Cast for the same reason as the first-billing switch above.
				switch ( (string) get_option( self::LEGACY_APPORTION_SIGN_UP_FEE, 'no' ) ) {
					case 'full':
						return 'full';
					case 'yes':
						return 'prorate';
					default:
						return 'none';
				}
			}
		);

		// Fixed-term behaviour (was "Prorate Subscription Length") → behaviour + product-type pair.
		$registry->register(
			'switch_fixed_term_behavior',
			static function () {
				// Only the known prorating values map to 'prorate'; unrecognized/empty stored values behave as
				// no-proration at runtime and must derive the same way (mirrors the first-billing default branch).
				return in_array( get_option( self::LEGACY_APPORTION_LENGTH, 'no' ), array( 'virtual', 'yes', 'physical' ), true ) ? 'prorate' : 'none';
			}
		);
		$registry->register(
			'switch_fixed_term_virtual',
			static function () {
				// Virtual applies to the Virtual-only and All values; the physical-only value does not.
				return in_array( get_option( self::LEGACY_APPORTION_LENGTH, 'no' ), array( 'virtual', 'yes' ), true ) ? 'yes' : 'no';
			}
		);
		$registry->register(
			'switch_fixed_term_physical',
			static function () {
				// Physical applies to the Physical-only and All values.
				return in_array( get_option( self::LEGACY_APPORTION_LENGTH, 'no' ), array( 'physical', 'yes' ), true ) ? 'yes' : 'no';
			}
		);

		// Switch button text. The unset default is wrapped in __() to mirror the legacy read
		// (class-wc-subscriptions-switcher.php), so a localized store with no stored value derives its translated
		// label rather than the English source string. A new store (option never saved) defaults to "Switch"; a
		// store with a persisted value keeps it. This mirrors the classic runtime default in
		// WC_Subscriptions_Switcher so both experiences resolve to the same label.
		$registry->register(
			'switch_button_text',
			static function () {
				return get_option( self::LEGACY_SWITCH_BUTTON_TEXT, __( 'Switch', 'woocommerce-subscriptions' ) );
			}
		);
	}
}
