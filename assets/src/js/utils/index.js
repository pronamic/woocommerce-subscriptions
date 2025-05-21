/**
 * External dependencies
 */
import { sprintf, __, _nx } from '@wordpress/i18n';

export function getAvailablePeriods( number ) {
	return {
		day: _nx(
			'day',
			'days',
			number,
			'Used in recurring totals section in Cart. 2+ will need plural, 1 will need singular.',
			'woocommerce-subscriptions'
		),
		week: _nx(
			'week',
			'weeks',
			number,
			'Used in recurring totals section in Cart. 2+ will need plural, 1 will need singular.',
			'woocommerce-subscriptions'
		),
		month: _nx(
			'month',
			'months',
			number,
			'Used in recurring totals section in Cart. 2+ will need plural, 1 will need singular.',
			'woocommerce-subscriptions'
		),
		year: _nx(
			'year',
			'years',
			number,
			'Used in recurring totals section in Cart. 2+ will need plural, 1 will need singular.',
			'woocommerce-subscriptions'
		),
	};
}

/**
 * Creates a recurring string from a subscription
 *
 * Examples
 * period recurring total
 * Daily recurring total
 * Weekly recurring total
 * Monthly recurring total
 * etc
 * If subscription bills at non standard intervals, then the order is transposed, and the line reads:
 * Recurring total every X day | week | month | quarter | year
 * Recurring total every 3rd day
 * Recurring total every 2nd week
 * Recurring total every 4th month
 * etc
 *
 * @param {Object} subscription                     Subscription object.
 * @param {string} subscription.billingPeriod      Period (month, day, week, year).
 * @param {number} subscription.billingInterval    Internal (1 month, 5 day, 4 week, 6 year).
 */
export function getRecurringPeriodString( { billingInterval, billingPeriod } ) {
	switch ( billingInterval ) {
		case 1:
			if ( 'day' === billingPeriod ) {
				return __(
					'Daily recurring total',
					'woocommerce-subscriptions'
				);
			} else if ( 'week' === billingPeriod ) {
				return __(
					'Weekly recurring total',
					'woocommerce-subscriptions'
				);
			} else if ( 'month' === billingPeriod ) {
				return __(
					'Monthly recurring total',
					'woocommerce-subscriptions'
				);
			} else if ( 'year' === billingPeriod ) {
				return __(
					'Yearly recurring total',
					'woocommerce-subscriptions'
				);
			}
			break;
		case 2:
			return sprintf(
				/* translators: %1$s is week, month, year */
				__(
					'Recurring total every 2nd %1$s',
					'woocommerce-subscriptions'
				),
				billingPeriod
			);

		case 3:
			return sprintf(
				/* Translators: %1$s is week, month, year */
				__(
					'Recurring total every 3rd %1$s',
					'woocommerce-subscriptions'
				),
				billingPeriod
			);
		default:
			return sprintf(
				/* Translators: %1$d is number of weeks, months, days, years. %2$s is week, month, year */
				__(
					'Recurring total every %1$dth %2$s',
					'woocommerce-subscriptions'
				),
				billingInterval,
				billingPeriod
			);
	}
}

export function getSubscriptionLengthString( {
	subscriptionLength,
	billingPeriod,
} ) {
	const periodsStings = getAvailablePeriods( subscriptionLength );
	return sprintf(
		'For %1$d %2$s',
		subscriptionLength,
		periodsStings[ billingPeriod ],
		'woocommerce-subscriptions'
	);
}
/**
 * Creates a billing frequency string from a subscription
 *
 * Examples
 * Every 6th week
 * Every day
 * Every month
 * / day
 * Each Week
 * etc
 *
 * @param {Object} subscription                  Subscription object.
 * @param {string} subscription.billing_period   Period (month, day, week, year).
 * @param {number} subscription.billing_interval Internal (1 month, 5 day, 4 week, 6 year).
 * @param {string} separator                     A string to be prepended to frequency. followed by a space. Eg: (every, each, /)
 * @param {string} price                         This is the string representation of the price of the product.
 */
export function getBillingFrequencyString(
	{ billing_interval: billingInterval, billing_period: billingPeriod },
	separator,
	price
) {
	const periodsStings = getAvailablePeriods( billingInterval );
	const translatedPeriod = periodsStings[ billingPeriod ];
	separator = separator.trim();
	switch ( billingInterval ) {
		case 1:
			return `${ price } ${ separator } ${ translatedPeriod }`;
		default:
			return sprintf(
				/*
				 * translators: %1$s is the price of the product. %2$s is the separator used e.g "every" or "/",
				 * %3$d is the length, %4$s is week, month, year
				 */
				__( `%1$s %2$s %3$d %4$s`, 'woocommerce-subscriptions' ),
				price,
				separator,
				billingInterval,
				translatedPeriod
			);
	}
}

/**
 * Returns a switch string
 *
 * @param {string} switchType The switch type (upgraded, downgraded, crossgraded).
 *
 * @return {string} Translation ready switch name.
 */
export function getSwitchString( switchType ) {
	switch ( switchType ) {
		case 'upgraded':
			return __( 'Upgrade', 'woocommerce-subscriptions' );

		case 'downgraded':
			return __( 'Downgrade', 'woocommerce-subscriptions' );

		case 'crossgraded':
			return __( 'Crossgrade', 'woocommerce-subscriptions' );

		default:
			return '';
	}
}

/**
 * Checks weather a subscription is a one off or not.
 *
 * @param {Object} subscription                    Subscription object data.
 * @param {number} subscription.subscriptionLength Subscription length.
 * @param {number} subscription.billingInterval    Billing interval
 * @return {boolean} whether this is a one off subscription or not.
 */
export function isOneOffSubscription( {
	subscriptionLength,
	billingInterval,
} ) {
	return subscriptionLength === billingInterval;
}
