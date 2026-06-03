<?php
/**
 * Class Abilities_Registrar
 *
 * @package WooCommerce Subscriptions
 */

// @phan-file-suppress PhanUndeclaredFunction, PhanUndeclaredClassMethod @phan-suppress-current-line UnusedSuppression -- Abilities API added in WP 6.9; suppression covers the WP 6.8 compat run. @todo Remove when WCS drops WP <6.9.

namespace Automattic\WooCommerce_Subscriptions\Internal\Abilities;

/**
 * Registers WooCommerce Subscriptions abilities with the WordPress Abilities API.
 *
 * Hosts the WCS read-only abilities (subscription reads + the gifted-list
 * read) under the shared `woocommerce` category, and the `can_read_subscriptions()`
 * capability helper that mirrors the load-bearing read gate resolved by
 * the REST controllers (`read_private_shop_orders`, the primitive cap that
 * map_meta_cap routes
 * `wc_rest_check_post_permissions('shop_subscription', 'read')` to).
 *
 * Plugin ownership is carried by the ability namespace
 * (`woocommerce-subscriptions/*`); the `woocommerce` category itself is
 * owned and registered by WooCommerce Core 10.9+, so this class does not
 * re-register it (the Abilities API fires `_doing_it_wrong` on duplicate
 * slug registration).
 *
 * Registration is gated by the `woocommerce_subscriptions_abilities_enabled`
 * filter (default false). Concrete write abilities will land in a follow-up
 * pass once per-state-transition design is settled.
 *
 * Registration pattern: WCS abilities are registered exclusively via Woo
 * Core's `woocommerce_ability_definition_classes` loader filter (introduced
 * in WooCommerce 10.9). On stores running WC < 10.9 the feature silently
 * no-ops — see `woo_abilities_loader_available()`.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Abilities_Registrar {

	/**
	 * Category slug used for every WooCommerce Subscriptions ability.
	 *
	 * The `woocommerce` category is owned and registered by WooCommerce
	 * Core (10.9+); plugin ownership lives in the ability namespace, not
	 * the category. Mirrored on `Abstract_WCS_Ability::CATEGORY_SLUG` so
	 * Domain classes can reference `self::CATEGORY_SLUG` without a
	 * cross-namespace static call.
	 *
	 * @var string
	 */
	const CATEGORY_SLUG = 'woocommerce';

	/**
	 * Ability definition classes registered through the WC 10.9 loader.
	 *
	 * Registered exclusively via Woo Core's `woocommerce_ability_definition_classes`
	 * filter (introduced in WooCommerce 10.9).
	 *
	 * @var array<int, class-string>
	 */
	private const ABILITY_CLASSES = [
		Domain\Get_Subscription_Statuses::class,
		Domain\Get_Subscriptions::class,
		Domain\Get_Subscription::class,
		Domain\Get_Subscription_Related_Orders::class,
		Domain\Get_Order_Subscriptions::class,
		Domain\Get_Subscription_Notes::class,
		Domain\Get_Gifted_Subscriptions::class,
	];

	/**
	 * Whether init() has already wired its action callbacks.
	 *
	 * Without this guard, repeated calls to init() while the feature filter
	 * is true would each append a fresh `add_action()` for the registrar
	 * callbacks, and WP_Abilities_Registry::register() would emit
	 * `_doing_it_wrong` notices for every already-registered slug when the
	 * action fires.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize the abilities registration.
	 *
	 * Gated behind the `woocommerce_subscriptions_abilities_enabled` filter
	 * (default false during rollout). Flip via `add_filter()` on a per-site
	 * basis to enable; the default flips to true once the surface is stable.
	 *
	 * If the relevant Abilities API action has already fired we call the
	 * registrar directly; otherwise we hook it for when the API boots.
	 *
	 * Idempotent: only the first invocation that passes the feature gate
	 * wires the hooks; subsequent calls short-circuit.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		/**
		 * Filter whether WooCommerce Subscriptions's Abilities API registrations are active.
		 *
		 * @since 8.8.0
		 *
		 * @param bool $enabled Whether to register subscriptions abilities. Default false.
		 */
		if ( ! apply_filters( 'woocommerce_subscriptions_abilities_enabled', false ) ) {
			return;
		}

		if ( ! self::woo_abilities_loader_available() ) {
			// Abilities feature requires WC 10.9. Silently no-op on older
			// versions; the feature flag is the rollout safety net.
			return;
		}

		self::$initialized = true;

		add_filter( 'woocommerce_ability_definition_classes', [ __CLASS__, 'append_classes' ] );
	}

	/**
	 * Reset the idempotency guard set by init().
	 *
	 * Tests need to reset the static between cases because PHPUnit runs
	 * methods in the same PHP process; without a reset, a passing first
	 * test would force subsequent init() calls to short-circuit and break
	 * the isolated arrange/act/assert each case relies on.
	 *
	 * @internal Test-isolation helper. Not part of the public API.
	 *
	 * @return void
	 */
	public static function reset_initialized_for_testing(): void {
		self::$initialized = false;
	}

	/**
	 * Whether WooCommerce 10.9's AbilitiesLoader is available.
	 *
	 * Used as a hard gate: on WC < 10.9 the abilities feature silently
	 * no-ops. WC 10.9 also depends on WP 6.9, so wp_register_ability()
	 * is implicitly available wherever the loader exists.
	 *
	 * @return bool
	 */
	private static function woo_abilities_loader_available(): bool {
		return class_exists( '\\Automattic\\WooCommerce\\Internal\\Abilities\\AbilitiesLoader' );
	}

	/**
	 * Append WCS ability definition classes to Woo Core's loader.
	 *
	 * Filter callback for `woocommerce_ability_definition_classes`.
	 *
	 * @param array $classes Class names accumulated by the loader.
	 * @return array
	 */
	public static function append_classes( array $classes ): array {
		return array_merge( $classes, self::ABILITY_CLASSES );
	}

	/**
	 * Permission callback for read abilities.
	 *
	 * Mirrors the REST controllers' resolved read gate: `shop_subscription`
	 * post-type capability machinery (`capability_type='shop_order'`,
	 * `map_meta_cap=true`) routes `wc_rest_check_post_permissions('shop_subscription', 'read')`
	 * to the primitive `read_private_shop_orders` capability. Shop managers
	 * and administrators have it; subscribers do not.
	 *
	 * @return bool
	 */
	public static function can_read_subscriptions(): bool {
		return current_user_can( 'read_private_shop_orders' );
	}
}
