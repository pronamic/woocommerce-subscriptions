<?php
/**
 * Helpers for subscription plan keys.
 *
 * @package WooCommerce Subscriptions
 * @since   9.2.0
 */

namespace Automattic\WooCommerce_Subscriptions\Internal\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for the keys that identify subscription plans, usable whether or not the bundled plans code is loaded.
 *
 * A plan's key is persisted on order items as '_wcsatt_scheme' and read back long after the plan may have changed,
 * so deciding what a key means must not depend on anything an extension can filter at runtime.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Plan_Utils {

	/**
	 * Billing periods a legacy plan key can name.
	 *
	 * Deliberately not wcs_get_subscription_period_strings(), which is filtered through 'woocommerce_subscription_periods'.
	 * That helper describes how periods are presented, and a store that trims it would silently stop legacy keys
	 * canonicalizing, or make the same key canonicalize differently between requests.
	 *
	 * @since 9.2.0
	 *
	 * @var string[]
	 */
	const PERIODS = array( 'day', 'week', 'month', 'year' );

	/**
	 * Canonicalizes a subscription plan key so that the two legacy spellings of the same key compare equal.
	 *
	 * The standalone All Products for Subscriptions plugin spelled a plan's key two different ways
	 * depending on where the plan was saved:
	 *
	 * - Storewide plans were saved with an explicit id built as "{interval}_{period}_{length}", so a
	 *   plan with no end date was stored as e.g. "4_week_0".
	 * - Product-level plans were saved with no id at all, so the key fell back to
	 *   implode( '_', array_filter( array( $interval, $period, $length ) ) ), which drops a zero
	 *   length and yields e.g. "4_week".
	 *
	 * The standalone plugin recomputed the second form on every read and never honoured a stored id, so
	 * the difference was invisible. Since 9.0.0 a stored id is authoritative, which means an order item
	 * stamped with one spelling no longer matches a plan keyed with the other. Reducing both to the
	 * shorter form makes them compare equal again, in either direction.
	 *
	 * Keys that are not in the legacy "{interval}_{period}[_{length}]" shape - such as the UUIDs assigned
	 * to plans created since 9.0.0, or ids set by third parties - are returned unchanged, so they can only
	 * ever match themselves.
	 *
	 * This inspects the key string and nothing else. A plan's key is an identifier, not a description of
	 * its billing schedule: a merchant may edit a plan's schedule while its key stays the same, and such a
	 * plan must keep matching the items purchased under it.
	 *
	 * @since 9.2.0
	 *
	 * @param  mixed $key Plan key.
	 * @return string The canonicalized key, the key unchanged if it is not a legacy schedule key, or '' for a non-scalar.
	 */
	public static function canonicalize_key( $key ) {

		if ( ! is_scalar( $key ) ) {
			return '';
		}

		$key   = strval( $key );
		$parts = explode( '_', $key );

		if ( 2 !== count( $parts ) && 3 !== count( $parts ) ) {
			return $key;
		}

		$interval = $parts[0];
		$period   = $parts[1];
		$length   = isset( $parts[2] ) ? $parts[2] : '0';

		if ( ! ctype_digit( $interval ) || ! ctype_digit( $length ) || ! absint( $interval ) ) {
			return $key;
		}

		if ( ! in_array( $period, self::PERIODS, true ) ) {
			return $key;
		}

		// Mirrors the legacy key format: a zero length is dropped, since array_filter treats it as empty.
		return implode( '_', array_filter( array( absint( $interval ), $period, absint( $length ) ) ) );
	}

	/**
	 * Whether two plan keys name the same plan: both must canonicalize to the same non-empty string, so a
	 * missing plan matches nothing, not even another missing plan.
	 *
	 * @since 9.2.0
	 *
	 * @param  mixed $a Plan key.
	 * @param  mixed $b Plan key.
	 * @return bool
	 */
	public static function keys_match( $a, $b ) {
		$canonical_a = self::canonicalize_key( $a );

		return '' !== $canonical_a && self::canonicalize_key( $b ) === $canonical_a;
	}

	/**
	 * Resolves a stored plan key against the keys a product currently offers: the exact key when the product has it,
	 * else the one candidate spelling the same plan the other legacy way, and never a choice between two plans.
	 *
	 * @since 9.2.0
	 *
	 * @param  mixed $key            Plan key as stored.
	 * @param  array $candidate_keys Keys of the plans the product offers.
	 * @return string The candidate's spelling of the key, or '' when the key is empty or not a scalar, matches no
	 *                candidate, or matches more than one.
	 */
	public static function resolve_key( $key, $candidate_keys ) {

		if ( ! is_scalar( $key ) || ! is_array( $candidate_keys ) ) {
			return '';
		}

		$key = strval( $key );

		if ( '' === $key ) {
			return '';
		}

		$candidate_keys = array_unique( array_map( 'strval', array_filter( $candidate_keys, 'is_scalar' ) ) );

		// A key is an identifier first: an exact match wins over any legacy equivalence.
		if ( in_array( $key, $candidate_keys, true ) ) {
			return $key;
		}

		$matches = array();

		foreach ( $candidate_keys as $candidate_key ) {
			if ( self::keys_match( $key, $candidate_key ) ) {
				$matches[] = $candidate_key;
			}
		}

		// Never choose between plans.
		return 1 === count( $matches ) ? $matches[0] : '';
	}
}
