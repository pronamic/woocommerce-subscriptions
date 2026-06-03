<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Per-subscription remediation lock backed by `add_option()`.
 *
 * Atomic via the options table's UNIQUE index on `option_name` — an
 * existing row makes the INSERT fail and `add_option()` returns false.
 * Transients aren't a fit here: without an object cache they're stored
 * via `update_option`, which is not atomic against concurrent inserts.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class RemediationLock {

	/**
	 * Lock TTL in seconds. Should comfortably exceed the longest expected
	 * remediation runtime (gateway round-trip plus DB writes).
	 */
	private const TTL_SECONDS = 60;

	/**
	 * Try to acquire a per-subscription remediation lock.
	 *
	 * The TTL is short enough that a stale lock from a crashed handler
	 * unblocks itself within a minute. Stale-lock cleanup runs only when
	 * a fresh acquire collides with an expired lock, so the happy path
	 * is a single INSERT.
	 *
	 * @param int $subscription_id Subscription id to lock.
	 *
	 * @return bool True if the lock was acquired, false if another
	 *              remediation is already in flight.
	 */
	public function acquire( int $subscription_id ): bool {
		$lock_key = $this->lock_key( $subscription_id );
		$now      = time();

		if ( add_option( $lock_key, (string) ( $now + self::TTL_SECONDS ), '', 'no' ) ) {
			return true;
		}

		// Existing lock — clean up if stale (caller crashed before
		// releasing). Two requests racing here both call delete_option
		// then race on add_option; the options table's UNIQUE index
		// resolves the race so exactly one wins.
		$expires = (int) get_option( $lock_key, 0 );
		if ( $expires > 0 && $expires < $now ) {
			// Stale-lock recovery is invisible without this entry —
			// recurring lock-leak patterns from PHP fatals or timeouts
			// would otherwise produce silent "every other request wins"
			// behaviour. Debug level keeps it out of warning streams
			// for the happy single-recovery case.
			wc_get_logger()->debug(
				sprintf(
					'Health Check: clearing stale remediation lock — subscription=%d expired=%d age=%ds',
					$subscription_id,
					$expires,
					$now - $expires
				),
				array(
					'source'          => 'wcs-health-check',
					'subscription_id' => $subscription_id,
				)
			);
			delete_option( $lock_key );
			return (bool) add_option( $lock_key, (string) ( $now + self::TTL_SECONDS ), '', 'no' );
		}

		return false;
	}

	/**
	 * Release a per-subscription remediation lock.
	 *
	 * @param int $subscription_id Subscription id to unlock.
	 *
	 * @return void
	 */
	public function release( int $subscription_id ): void {
		delete_option( $this->lock_key( $subscription_id ) );
	}

	/**
	 * Build the wp_options key for a subscription lock.
	 *
	 * @param int $subscription_id Subscription id.
	 *
	 * @return string
	 */
	private function lock_key( int $subscription_id ): string {
		return 'wcs_health_check_lock_' . $subscription_id;
	}
}
