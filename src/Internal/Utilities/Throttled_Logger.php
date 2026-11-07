<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Utilities;

use WC_Log_Levels;
use WC_Rate_Limiter;

/**
 * Writes a log entry at most once per interval for each throttle key.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Throttled_Logger {
	/**
	 * Log an entry, unless an entry with the same key was logged within the last $interval seconds.
	 *
	 * Use this where something other than the store controls how often an entry is triggered, such as a log line
	 * written for an unauthenticated request. The entry that is logged says that similar entries will not be, so one
	 * line is not read as one event.
	 *
	 * The key must be a fixed string. WC_Rate_Limiter stores one database row per key, so a key built from request
	 * data (an IP address, a token, a user agent) lets the sender create rows without limit.
	 *
	 * Two concurrent requests can both pass the check, so this limits volume over time, not an exact count.
	 *
	 * @param string $key      Fixed identifier shared by the entries that are throttled together. Stored with the
	 *                         'woocommerce_subscriptions_' prefix.
	 * @param string $message  Log message.
	 * @param array  $context  Logger context. Without a source, the entry goes to the shared 'woocommerce-subscriptions'
	 *                         log.
	 * @param string $level    One of the WC_Log_Levels constants.
	 * @param int    $interval Minimum number of seconds between logged entries.
	 *
	 * @return bool True if the entry was passed to the logger, false if it was suppressed.
	 */
	public static function log(
		string $key,
		string $message,
		array $context = array(),
		string $level = WC_Log_Levels::DEBUG,
		int $interval = 300
	): bool {
		$key      = 'woocommerce_subscriptions_' . $key;
		$context += array( 'source' => 'woocommerce-subscriptions' );

		if ( WC_Rate_Limiter::retried_too_soon( $key ) ) {
			return false;
		}

		WC_Rate_Limiter::set_rate_limit( $key, $interval );

		wc_get_logger()->log(
			$level,
			sprintf(
				'%1$s Similar entries will not be logged for the next %2$d seconds.',
				$message,
				$interval
			),
			$context
		);

		return true;
	}
}
