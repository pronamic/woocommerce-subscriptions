<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\CLI\Test_Data;

/**
 * Failure reason vocabulary for the test subscription generator.
 *
 * Closed set of slugs that the generator stamps onto a failed renewal order's note. The slugs
 * carry no behavioural meaning beyond what individual cases require — `expired_card` is
 * special because the RemediationAdvisor uses the gateway error code to decide between
 * TC-F2 and TC-D3. Everything else is flavour text.
 *
 * @since   x.x.x
 * @internal This class may be modified, moved or removed in future releases.
 */
class Failure_Reasons {

	/**
	 * The closed vocabulary: slug => human-readable note. Intentionally small —
	 * one declined-by-issuer case, one balance case, and the card-expired token
	 * the D3 detector keys on.
	 *
	 * @var array<string,string>
	 */
	private static $vocabulary = array(
		'card_declined'      => 'Payment declined by issuer.',
		'insufficient_funds' => 'Payment declined: insufficient funds.',
		'expired_card'       => 'Payment declined: card expired.',
	);

	/**
	 * Default reason picked when a case asks for a failed renewal but the
	 * caller didn't override `--failure-reason`. Realistic, retryable, and
	 * doesn't accidentally trigger the TC-D3 card-expired upgrade path.
	 */
	private const DEFAULT_REASON = 'card_declined';

	/**
	 * Return every known failure-reason slug.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::$vocabulary );
	}

	/**
	 * Whether the given slug is part of the vocabulary.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function is_valid( $slug ) {
		return isset( self::$vocabulary[ $slug ] );
	}

	/**
	 * Human-readable note template for the given slug. Empty string if the slug is unknown.
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function get_note( $slug ) {
		return self::$vocabulary[ $slug ] ?? '';
	}

	/**
	 * The default failure reason — used when a case calls for a failed renewal
	 * and no explicit reason was supplied.
	 *
	 * @return string
	 */
	public static function default_slug() {
		return self::DEFAULT_REASON;
	}
}
