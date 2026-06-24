<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Utilities;

use WC_Subscription;

/**
 * Utilities for working with subscription objects.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Subscriptions {

	/**
	 * Get a subscription's raw stored status, bypassing the in-memory draft masking.
	 *
	 * WC_Subscription::set_status() masks the 'draft' and 'auto-draft' statuses to 'pending' while a
	 * subscription object is read, so a loaded object can no longer report that it was a
	 * never-activated admin draft. This reads the unmasked status straight from the datastore that
	 * backs the subscription, which lets callers tell an abandoned draft apart from a genuine pending
	 * subscription (for example, to delete it silently instead of cancelling it and emailing the
	 * merchant).
	 *
	 * @since 9.0.0
	 *
	 * @param WC_Subscription $subscription The subscription to inspect.
	 *
	 * @return string The raw stored status (e.g. 'auto-draft', 'draft', 'wc-active'), or an empty
	 *                string if it could not be determined.
	 */
	public static function get_raw_status( $subscription ): string {
		if ( ! $subscription instanceof WC_Subscription ) {
			return '';
		}

		$data_store = $subscription->get_data_store();

		// A subscription datastore is not guaranteed to implement get_subscription_raw_status(): the
		// method is specific to our own stores, and a third-party store registered through the
		// 'woocommerce_subscription_data_store' filter may not provide it. Detection must run against
		// the concrete store class, because WC_Data_Store proxies unknown calls through __call() —
		// which makes is_callable() on the wrapper always true and silently returns null for a method
		// the underlying store does not actually have.
		if ( ! method_exists( $data_store->get_current_class_name(), 'get_subscription_raw_status' ) ) {
			return '';
		}

		return (string) $data_store->get_subscription_raw_status( $subscription->get_id() );
	}
}
