/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { registerCheckoutFilters } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';

/**
 * Internal dependencies
 */
import {
	getSwitchString,
	isOneOffSubscription,
	getBillingFrequencyString,
} from '../utils';

/**
 * This is the filter integration API, it uses registerCheckoutFilters
 * to register its filters, each filter is a key: function pair.
 * The key the filter name, and the function is the filter.
 *
 * Each filter function is passed the previous (or default) value in that filter
 * as the first parameter, the second parameter is a object of 3PD registered data.
 * For WCS, we register out data with key `subscriptions`.
 * Filters must return the previous value or a new value with the same type.
 * If an error is thrown, it would be visible for store managers only.
 */
export const registerFilters = () => {
	registerCheckoutFilters( 'woocommerce-subscriptions', {
		// subscriptions data here comes from register_endpoint_data /cart registration.
		totalLabel: ( label, { subscriptions } ) => {
			if ( 0 < subscriptions?.length ) {
				return __( 'Total due today', 'woocommerce-subscriptions' );
			}
			return label;
		},
		// subscriptions data here comes from register_endpoint_data /cart/items registration.
		subtotalPriceFormat: ( label, { subscriptions } ) => {
			if (
				subscriptions?.billing_period &&
				subscriptions?.billing_interval
			) {
				const {
					billing_interval: billingInterval,
					subscription_length: subscriptionLength,
				} = subscriptions;
				// We check if we have a length and its equal or less to the billing interval.
				// When this is true, it means we don't have a next payment date.
				if (
					isOneOffSubscription( {
						subscriptionLength,
						billingInterval,
					} )
				) {
					// An edge case when length is 1 so it doesn't have a length prefix
					if ( 1 === subscriptionLength ) {
						return getBillingFrequencyString(
							subscriptions,
							// translators: the word used to describe billing frequency, e.g. "for" 1 day or "for" 1 month.
							__( 'for 1', 'woocommerce-subscriptions' ),
							label
						);
					}
					return getBillingFrequencyString(
						subscriptions,
						// translators: the word used to describe billing frequency, e.g. "for" 6 days or "for" 2 weeks.
						__( 'for', 'woocommerce-subscriptions' ),
						label
					);
				}
				return getBillingFrequencyString(
					subscriptions,
					// translators: the word used to describe billing frequency, e.g. "every" 6 days or "every" 2 weeks.
					__( 'every', 'woocommerce-subscriptions' ),
					label
				);
			}
			return label;
		},
		saleBadgePriceFormat: ( label, { subscriptions } ) => {
			if (
				subscriptions?.billing_period &&
				subscriptions?.billing_interval
			) {
				return getBillingFrequencyString( subscriptions, '/', label );
			}
			return label;
		},
		itemName: ( name, { subscriptions } ) => {
			if ( subscriptions?.is_resubscribe ) {
				return sprintf(
					// translators: %s Product name.
					__( '%s (resubscription)', 'woocommerce-subscriptions' ),
					name
				);
			}
			if ( subscriptions?.switch_type ) {
				return sprintf(
					// translators: %1$s Product name, %2$s Switch type (upgraded, downgraded, or crossgraded).
					__( '%1$s (%2$s)', 'woocommerce-subscriptions' ),
					name,
					getSwitchString( subscriptions.switch_type )
				);
			}
			return name;
		},
		cartItemPrice: ( pricePlaceholder, { subscriptions }, { context } ) => {
			if ( subscriptions?.sign_up_fees ) {
				return 'cart' === context
					? sprintf(
							/* translators: %s is the subscription price to pay immediately (ie: $10). */
							__( 'Due today %s', 'woocommerce-subscriptions' ),
							pricePlaceholder
					  )
					: sprintf(
							/* translators: %s is the subscription price to pay immediately (ie: $10). */
							__( '%s due today', 'woocommerce-subscriptions' ),
							pricePlaceholder
					  );
			}

			return pricePlaceholder;
		},
		placeOrderButtonLabel: ( label ) => {
			const subscriptionsData = getSetting( 'subscriptions_data' );

			if ( subscriptionsData?.place_order_override ) {
				return subscriptionsData?.place_order_override;
			}

			return label;
		},
	} );
};
