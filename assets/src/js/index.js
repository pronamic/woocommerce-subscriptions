/**
 * External dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import {
	ExperimentalOrderMeta,
	ExperimentalOrderShippingPackages,
} from '@woocommerce/blocks-checkout';

/**
 * Internal dependencies
 */
import { SubscriptionsRecurringTotals } from './recurring-totals';
import { SubscriptionsRecurringPackages } from './recurring-packages';
import { registerFilters } from './filters';
import './index.scss';

/**
 * This is the first integration point between WooCommerce Subscriptions
 * and Cart and Checkout blocks, it happens on two folds:
 * - First, we register our code via `registerPlugin`, this React code
 * is then going to be rendered hidden inside Cart and Checkout blocks
 * (via <PluginArea /> component).
 * - Second, we're using SlotFills[1] to move that code to where we want it
 * inside the tree.
 */
const render = () => {
	return (
		<>
			<ExperimentalOrderShippingPackages>
				<SubscriptionsRecurringPackages />
			</ExperimentalOrderShippingPackages>
			<ExperimentalOrderMeta>
				<SubscriptionsRecurringTotals />
			</ExperimentalOrderMeta>
		</>
	);
};

registerPlugin( 'woocommerce-subscriptions', {
	render,
	scope: 'woocommerce-checkout',
} );

/**
 * RegisterFilters is the second part of the integration, and it handles filters
 * like price, totals, and so on.
 */
registerFilters();
