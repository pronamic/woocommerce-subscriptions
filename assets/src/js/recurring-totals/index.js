/**
 * External dependencies
 */
import { sprintf, __ } from '@wordpress/i18n';
import {
	Panel,
	Subtotal,
	TotalsItem,
	TotalsTaxes,
	TotalsWrapper,
} from '@woocommerce/blocks-checkout';
import { getCurrencyFromPriceResponse } from '@woocommerce/price-format';
import { isWcVersion, getSetting } from '@woocommerce/settings';
/**
 * Internal dependencies
 */
import {
	getRecurringPeriodString,
	getSubscriptionLengthString,
	isOneOffSubscription,
} from '../utils';
import './index.scss';

/**
 * All data passed in get_script_data is available here, from all
 * plugins (e.g WooCommerce Admin, WooCommerce Blocks).
 */
const DISPLAY_CART_PRICES_INCLUDING_TAX = getSetting(
	'displayCartPricesIncludingTax',
	false
);

/**
 * Component responsible for rending the coupons discount totals item.
 *
 * @param {Object} props          Props passed to component.
 * @param {Object} props.currency Object containing currency data to format prices.
 * @param {Object} props.values   Recurring cart totals (shipping, taxes).
 */
const DiscountTotals = ( { currency, values } ) => {
	const {
		total_discount: totalDiscount,
		total_discount_tax: totalDiscountTax,
	} = values;
	const discountValue = parseInt( totalDiscount, 10 );

	if ( ! discountValue ) {
		return null;
	}

	const discountTaxValue = parseInt( totalDiscountTax, 10 );
	const discountTotalValue = DISPLAY_CART_PRICES_INCLUDING_TAX
		? discountValue + discountTaxValue
		: discountValue;

	return (
		<TotalsItem
			className="wc-block-components-totals-discount"
			currency={ currency }
			label={ __( 'Discount', 'woocommerce-subscriptions' ) }
			value={ discountTotalValue * -1 }
		/>
	);
};

/**
 * Component responsible for rending the shipping totals item.
 *
 * @param {Object} props                        Props passed to component.
 * @param {string|undefined} props.selectedRate Selected shipping method
 * name.
 * @param {boolean} props.needsShipping         Boolean to indicate if we
 * need shipping or not.
 * @param {boolean} props.calculatedShipping    Boolean to indicate if we
 * calculated shipping or not.
 * @param {Object} props.currency               Object containing
 * currency data to format prices.
 * @param {Object} props.values                 Recurring cart totals (shipping, taxes).
 */
const ShippingTotal = ( {
	values,
	currency,
	selectedRate,
	needsShipping,
	calculatedShipping,
} ) => {
	if ( ! needsShipping || ! calculatedShipping ) {
		return null;
	}
	const shippingTotals = DISPLAY_CART_PRICES_INCLUDING_TAX
		? parseInt( values.total_shipping, 10 ) +
		  parseInt( values.total_shipping_tax, 10 )
		: parseInt( values.total_shipping, 10 );

	const valueToShow =
		0 === shippingTotals && isWcVersion( '9.0', '>=' ) ? (
			<strong>{ __( 'Free', 'woocommerce-subscriptions' ) }</strong>
		) : (
			shippingTotals
		);
	return (
		<TotalsItem
			value={ valueToShow }
			label={ __( 'Shipping', 'woocommerce-subscriptions' ) }
			currency={ currency }
			description={
				!! selectedRate &&
				sprintf(
					// translators: %s selected shipping rate (ex: flat rate)
					__( 'via %s', 'woocommerce-subscriptions' ),
					selectedRate
				)
			}
		/>
	);
};
/**
 * Component responsible for rendering recurring cart description.
 *
 * @param {Object} props                    Props passed to component.
 * @param {string} props.nextPaymentDate    Formatted next payment date.
 * @param {number} props.subscriptionLength Subscription length.
 * @param {string} props.billingPeriod      Recurring cart period (day, week, month, year).
 * @param {number} props.billingInterval    Recurring cart interval (1 - 6).
 */
const SubscriptionDescription = ( {
	nextPaymentDate,
	subscriptionLength,
	billingPeriod,
	billingInterval,
} ) => {
	const subscriptionLengthString = getSubscriptionLengthString( {
		subscriptionLength,
		billingPeriod,
	} );
	const firstPaymentString = isOneOffSubscription( {
		subscriptionLength,
		billingInterval,
	} )
		? sprintf(
				/* Translators: %1$s is a date. */
				__( 'Due: %1$s', 'woocommerce-subscriptions' ),
				nextPaymentDate
		  )
		: sprintf(
				/* Translators: %1$s is a date. */
				__( 'Starting: %1$s', 'woocommerce-subscriptions' ),
				nextPaymentDate
		  );
	return (
		// Only render this section if we have a next payment date.
		<span>
			{ !! nextPaymentDate && firstPaymentString }{ ' ' }
			{ !! subscriptionLength &&
				subscriptionLength >= billingInterval && (
					<span className="wcs-recurring-totals__subscription-length">
						{ subscriptionLengthString }
					</span>
				) }
		</span>
	);
};

/**
 * Component responsible for rendering recurring cart heading.
 *
 * @param {Object} props                    Props passed to component.
 * @param {Object} props.currency           Object containing currency data to format prices.
 * @param {number} props.billingInterval    Recurring cart interval (1 - 6).
 * @param {string} props.billingPeriod      Recurring cart period (day, week, month, year).
 * @param {string} props.nextPaymentDate    Formatted next payment date.
 * @param {number} props.subscriptionLength Subscription length.
 * @param {Object} props.totals             Recurring cart totals (shipping, taxes).
 */
const TabHeading = ( {
	currency,
	billingInterval,
	billingPeriod,
	nextPaymentDate,
	subscriptionLength,
	totals,
} ) => {
	// For future one off subscriptions, we show "Total" instead of a recurring title.
	const title = isOneOffSubscription( {
		billingInterval,
		subscriptionLength,
	} )
		? __( 'Total', 'woocommerce-subscriptions' )
		: getRecurringPeriodString( {
				billingInterval,
				billingPeriod,
		  } );
	return (
		<TotalsItem
			className="wcs-recurring-totals-panel__title"
			currency={ currency }
			label={ title }
			value={ totals }
			description={
				<SubscriptionDescription
					nextPaymentDate={ nextPaymentDate }
					subscriptionLength={ subscriptionLength }
					billingInterval={ billingInterval }
					billingPeriod={ billingPeriod }
				/>
			}
		/>
	);
};

/**
 * Component responsible for rendering a single recurring total panel.
 * We render several ones depending on how many recurring carts we have.
 *
 * @param {Object} props                     Props passed to component.
 * @param {Object} props.subscription        Recurring cart data that we registered
 *                                           with ExtendRestApi.
 * @param {boolean} props.needsShipping      Boolean to indicate if we need
 *                                           shipping or not.
 * @param {boolean} props.calculatedShipping Boolean to indicate if we calculated
 *                                           shipping or not.
 */
const RecurringSubscription = ( {
	subscription,
	needsShipping,
	calculatedShipping,
} ) => {
	const {
		totals,
		billing_interval: billingInterval,
		billing_period: billingPeriod,
		next_payment_date: nextPaymentDate,
		subscription_length: subscriptionLength,
		shipping_rates: shippingRates,
	} = subscription;

	// We skip one off subscriptions
	if ( ! nextPaymentDate ) {
		return null;
	}

	const selectedRate = shippingRates?.[ 0 ]?.shipping_rates?.find(
		( { selected } ) => selected
	)?.name;

	const currency = getCurrencyFromPriceResponse( totals );

	return (
		<div className="wcs-recurring-totals-panel">
			<TabHeading
				billingInterval={ billingInterval }
				billingPeriod={ billingPeriod }
				nextPaymentDate={ nextPaymentDate }
				subscriptionLength={ subscriptionLength }
				totals={ parseInt( totals.total_price, 10 ) }
				currency={ currency }
			/>
			<Panel
				className="wcs-recurring-totals-panel__details"
				initialOpen={ false }
				title={ __( 'Details', 'woocommerce-subscriptions' ) }
			>
				<TotalsWrapper>
					<Subtotal currency={ currency } values={ totals } />
					<DiscountTotals currency={ currency } values={ totals } />
				</TotalsWrapper>
				<TotalsWrapper className="wc-block-components-totals-shipping">
					<ShippingTotal
						currency={ currency }
						needsShipping={ needsShipping }
						calculatedShipping={ calculatedShipping }
						values={ totals }
						selectedRate={ selectedRate }
					/>
				</TotalsWrapper>
				{ ! DISPLAY_CART_PRICES_INCLUDING_TAX && (
					<TotalsWrapper>
						<TotalsTaxes currency={ currency } values={ totals } />
					</TotalsWrapper>
				) }
				<TotalsWrapper>
					<TotalsItem
						className="wcs-recurring-totals-panel__details-total"
						currency={ currency }
						label={ __( 'Total', 'woocommerce-subscriptions' ) }
						value={ parseInt( totals.total_price, 10 ) }
					/>
				</TotalsWrapper>
			</Panel>
		</div>
	);
};

/**
 * This component is responsible for rending recurring totals.
 * It has to be the highest level item directly inside the SlotFill
 * to receive properties passed from Cart and Checkout.
 *
 * extensions is data registered into `/cart` endpoint.
 *
 * @param {Object} props            Passed props from SlotFill to this component.
 * @param {Object} props.extensions data registered into `/cart` endpoint.
 * @param {Object} props.cart       cart endpoint data in readonly mode.
 */
export const SubscriptionsRecurringTotals = ( { extensions, cart } ) => {
	const { subscriptions } = extensions;
	const { cartNeedsShipping, cartHasCalculatedShipping } = cart;
	if ( ! subscriptions || 0 === subscriptions.length ) {
		return null;
	}
	return subscriptions.map( ( { key, ...subscription } ) => (
		<RecurringSubscription
			subscription={ subscription }
			needsShipping={ cartNeedsShipping }
			calculatedShipping={ cartHasCalculatedShipping }
			key={ key }
		/>
	) );
};
