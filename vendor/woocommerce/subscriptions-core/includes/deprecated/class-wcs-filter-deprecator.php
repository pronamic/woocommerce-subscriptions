<?php
/**
 * Handle deprecated filters.
 *
 * When triggering a filter which has a deprecated equivalient from Subscriptions v1.n, check if the old
 * filter had any callbacks attached to it, and if so, log a notice and trigger the old filter with a set
 * of parameters in the deprecated format so that the current return value also has the old filters applied
 * (wherever possible that is).
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Hook_Deprecator
 * @category   Class
 * @author     Prospress
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

class WCS_Filter_Deprecator extends WCS_Hook_Deprecator {

	// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned,WordPress.Arrays.MultipleStatementAlignment.LongIndexSpaceBeforeDoubleArrow
	/* The filters that have been deprecated, 'new_hook' => 'old_hook' */
	protected $deprecated_hooks = array(

		// Subscription Meta Filters
		'woocommerce_subscription_payment_failed_count'              => 'woocommerce_subscription_failed_payment_count',
		'woocommerce_subscription_payment_completed_count'           => 'woocommerce_subscription_completed_payment_count',
		'woocommerce_subscription_get_end_date'                      => 'woocommerce_subscription_expiration_date',
		'woocommerce_subscription_get_trial_end_date'                => 'woocommerce_subscription_trial_expiration_date',
		'woocommerce_subscriptions_product_expiration_date'          => 'woocommerce_subscription_calculated_expiration_date',
		'woocommerce_subscription_get_last_payment_date'             => 'woocommerce_subscription_last_payment_date',
		'woocommerce_subscription_calculated_next_payment_date'      => 'woocommerce_subscriptions_calculated_next_payment_date',
		'woocommerce_subscription_date_updated'                      => 'woocommerce_subscriptions_set_trial_expiration_date',
		'wcs_subscription_statuses'                                  => array(
			'woocommerce_subscriptions_custom_status_string', //no replacement as Subscriptions now uses wcs_get_subscription_statuses() for everything (the deprecator could use 'wc_subscription_statuses' and loop over all statuses to set it in the returned value)
			'woocommerce_subscriptions_status_string',
		),

		// Renewal Filters
		'wcs_renewal_order_items'                                    => 'woocommerce_subscriptions_renewal_order_items',
		'wcs_renewal_order_meta_query'                               => 'woocommerce_subscriptions_renewal_order_meta_query',
		'wcs_renewal_order_meta'                                     => 'woocommerce_subscriptions_renewal_order_meta',
		'wcs_renewal_order_item_name'                                => 'woocommerce_subscriptions_renewal_order_item_name',
		'wcs_users_resubscribe_link'                                 => 'woocommerce_subscriptions_users_renewal_link',
		'wcs_can_user_resubscribe_to_subscription'                   => 'woocommerce_can_subscription_be_renewed',
		'wcs_renewal_order_created'                                  => array(
			'woocommerce_subscriptions_renewal_order_created', // Even though 'woocommerce_subscriptions_renewal_order_created' is an action, as it is attached to a filter, we need to handle it in here
			'woocommerce_subscriptions_renewal_order_id',
		),

		// List Table Filters
		'woocommerce_subscription_list_table_actions'                => 'woocommerce_subscriptions_list_table_actions',
		'woocommerce_subscription_list_table_column_status_content'  => 'woocommerce_subscriptions_list_table_column_status_content',
		'woocommerce_subscription_list_table_column_content'         => 'woocommerce_subscriptions_list_table_column_content',

		// User Filters
		'wcs_can_user_put_subscription_on_hold'                      => 'woocommerce_subscriptions_can_current_user_suspend',
		'wcs_view_subscription_actions'                              => 'woocommerce_my_account_my_subscriptions_actions',
		'wcs_get_users_subscriptions'                                => 'woocommerce_users_subscriptions',
		'wcs_users_change_status_link'                               => 'woocommerce_subscriptions_users_action_link',
		'wcs_user_has_subscription'                                  => 'woocommerce_user_has_subscription',

		// Misc Filters
		'woocommerce_subscription_max_failed_payments_exceeded'      => 'woocommerce_subscriptions_max_failed_payments_exceeded',
		'woocommerce_my_subscriptions_payment_method'                => 'woocommerce_my_subscriptions_recurring_payment_method',
		'woocommerce_subscriptions_update_payment_via_pay_shortcode' => 'woocommerce_subscriptions_update_recurring_payment_via_pay_shortcode',
		'woocommerce_can_subscription_be_updated_to'                 => 'woocommerce_can_subscription_be_changed_to',
	);
	// phpcs:enable

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Trigger the old filter with the original callback parameters and make sure the return value is passed on (when possible).
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function trigger_hook( $old_hook, $new_callback_args ) {

		// Return value is always the first param
		$return_value = $new_callback_args[0];

		switch ( $old_hook ) {

			// New arg spec: $subscription_statuses
			// Old arg spec: $status, $subscription_key, $user_id
			case 'woocommerce_subscriptions_custom_status_string':
				// Need to loop over the status and apply the old hook to each, we don't have a subscription or user for them anymore though
				foreach ( $return_value as $status_key => $status_string ) {
					$return_value[ $status_key ] = apply_filters( $old_hook, $status_string, '', 0 );
				}
				break;

			// New arg spec: $subscription_statuses
			// Old arg spec: $status_string, $status, $subscription_key, $user_id
			case 'woocommerce_subscriptions_status_string':
				// Need to loop over the status and apply the old hook to each, we don't have a subscription or user for them anymore though
				foreach ( $return_value as $status_key => $status_string ) {
					$return_value[ $status_key ] = apply_filters( $old_hook, $status_string, $status_key, '', 0 );
				}
				break;

			// New arg spec: $count, $subscription
			// Old arg spec: $count, $user_id, $subscription_key
			case 'woocommerce_subscription_failed_payment_count':
				$subscription = $new_callback_args[1];
				$return_value = apply_filters( $old_hook, $return_value, $subscription->get_user_id(), wcs_get_old_subscription_key( $subscription ) );
				break;

			// New arg spec: $date, $subscription, $timezone
			// Old arg spec: $date, $subscription_key, $user_id
			case 'woocommerce_subscription_completed_payment_count':
			case 'woocommerce_subscription_expiration_date':
			case 'woocommerce_subscription_trial_expiration_date':
			case 'woocommerce_subscription_last_payment_date':
				$subscription = $new_callback_args[1];
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_old_subscription_key( $subscription ), $subscription->get_user_id(), 'mysql' );
				break;

			// New arg spec: $expiration_date, $product_id, $from_date
			// Old arg spec: $expiration_date, $subscription_key, $user_id
			case 'woocommerce_subscription_calculated_expiration_date':
				$return_value = apply_filters( $old_hook, $return_value, '', 0 );
				break;

			// New arg spec: $next_payment_date, $subscription
			// Old arg spec: $next_payment_date, $order, $product_id, $type, $from_date, $from_date_arg
			case 'woocommerce_subscriptions_calculated_next_payment_date':
				$subscription = $new_callback_args[1];
				$last_payment = $subscription->get_date( 'last_order_date_created' );
				$return_value = apply_filters( $old_hook, $return_value, self::get_order( $subscription ), self::get_product_id( $subscription ), 'mysql', $last_payment, $last_payment );
				break;

			// New arg spec: $subscription, $date_type, $datetime
			// Old arg spec: $is_set, $expiration_date, $subscription_key, $user_id
			case 'woocommerce_subscription_set_next_payment_date':
			case 'woocommerce_subscriptions_set_trial_expiration_date':
			case 'woocommerce_subscriptions_set_expiration_date':

				$subscription = $new_callback_args[0];
				$date_type    = $new_callback_args[1];

				if ( ( 'next_payment' == $date_type && in_array( $old_hook, array( 'woocommerce_subscriptions_set_trial_expiration_date', 'woocommerce_subscription_set_next_payment_date' ) ) ) || ( 'end_date' == $date_type && 'woocommerce_subscriptions_set_expiration_date' == $old_hook ) ) {
					// Here the old return value was a boolean where as now there is no equivalent filter, so we apply the filter to the action (which is only triggered when the old filter's value would have been true) and ignore the return value
					apply_filters( $old_hook, true, wcs_get_old_subscription_key( $subscription ), $subscription->get_user_id() );
				}

				break;

			// New arg spec: $order_items, $renewal_order, $subscription
			// Old arg spec: $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role
			case 'woocommerce_subscriptions_renewal_order_meta':
			case 'woocommerce_subscriptions_renewal_order_meta_query':
			// Old arg spec: $order_items, $original_order_id, $renewal_order_id, $product_id, $new_order_role
			case 'woocommerce_subscriptions_renewal_order_items':
				$renewal_order = $new_callback_args[1];
				$subscription  = $new_callback_args[2];
				$original_id   = self::get_order_id( $subscription );

				// Now we need to find the new orders role, if the calling function is wcs_create_resubscribe_order(), the role is parent, otherwise it's child
				$backtrace  = debug_backtrace();
				$order_role = ( 'wcs_create_resubscribe_order' == $backtrace[1]['function'] ) ? 'parent' : 'child';

				// Old arg spec: $order_items, $original_order_id, $renewal_order_id, $product_id, $new_order_role
				if ( 'woocommerce_subscriptions_renewal_order_items' == $old_hook ) {
					$return_value = apply_filters( $old_hook, $return_value, $original_id, wcs_get_objects_property( $renewal_order, 'id' ), self::get_product_id( $subscription ), $order_role );
				} else {
					// Old arg spec: $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role
					$return_value = apply_filters( $old_hook, $return_value, $original_id, wcs_get_objects_property( $renewal_order, 'id' ), $order_role );
				}

				break;

			// New arg spec: $item_name, $order_item, $subscription
			// Old arg spec: $item_name, $order_item, $original_order
			case 'woocommerce_subscriptions_renewal_order_item_name':
				$return_value = apply_filters( $old_hook, $return_value, $new_callback_args[1], self::get_order( $new_callback_args[2] ) );
				break;

			// New arg spec: $renewal_order, $subscription
			// Old arg spec: $renewal_order, $original_order, $product_id, $new_order_role
			case 'woocommerce_subscriptions_renewal_order_created':
				do_action( $old_hook, $return_value, self::get_order( $new_callback_args[1] ), self::get_product_id( $new_callback_args[1] ), 'child' );
				break;

			// New arg spec: $renewal_order, $subscription
			// Old arg spec: $renewal_order_id, $original_order, $product_id, $new_order_role
			case 'woocommerce_subscriptions_renewal_order_id':

				$renewal_order = $new_callback_args[0];
				$subscription  = $new_callback_args[1];

				// Now we need to find the new orders role, if the calling function is wcs_create_resubscribe_order(), the role is parent, otherwise it's child
				$backtrace  = debug_backtrace();
				$order_role = ( 'wcs_create_resubscribe_order' == $backtrace[1]['function'] ) ? 'parent' : 'child';

				$renewal_order_id = apply_filters( $old_hook, $return_value->id, self::get_order( $subscription ), self::get_product_id( $subscription ), $order_role );

				// Only change the return value if a new filter was returned by the hook
				if ( wcs_get_objects_property( $renewal_order, 'id' ) !== $renewal_order_id ) {
					$return_value = wc_get_order( $renewal_order_id );
				}
				break;

			// New arg spec: $resubscribe_link, $subscription_id
			// Old arg spec: $renewal_url, $subscription_key
			case 'woocommerce_subscriptions_users_renewal_link':
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_old_subscription_key( wcs_get_subscription( $new_callback_args[1] ) ) );
				break;

			// New arg spec: $can_user_resubscribe, $subscription, $user_id
			// Old arg spec: $subscription_can_be_renewed, $subscription, $subscription_key, $user_id
			case 'woocommerce_can_subscription_be_renewed':
				$subscription = $new_callback_args[1];
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_subscription_in_deprecated_structure( $subscription ), wcs_get_old_subscription_key( $subscription ), $subscription->get_user_id() );
				break;

			// New arg spec: $actions, $subscription
			// Old arg spec: $actions, $subscription_array
			case 'woocommerce_subscriptions_list_table_actions':
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_subscription_in_deprecated_structure( $new_callback_args[1] ) );
				break;

			// New arg spec: $column_content, $subscription, $actions
			// Old arg spec: $column_content, $subscription_array, $actions, $list_table
			case 'woocommerce_subscriptions_list_table_column_status_content':
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_subscription_in_deprecated_structure( $new_callback_args[1] ) );
				break;

			// New arg spec: $column_content, $the_subscription, $column
			// Old arg spec: $column_content, $subscription_array, $column
			case 'woocommerce_subscriptions_list_table_column_content':
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_subscription_in_deprecated_structure( $new_callback_args[1] ), $new_callback_args[2] );
				break;

			// New arg spec: $user_can_suspend, $subscription
			// Old arg spec: $user_can_suspend, $subscription_key
			case 'woocommerce_subscriptions_can_current_user_suspend':
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_old_subscription_key( $new_callback_args[1] ) );
				break;

			// New arg spec: $actions, $subscription (individual subscription object)
			// Old arg spec: $all_actions, $subscriptions (array of subscription arrays)
			case 'woocommerce_my_account_my_subscriptions_actions':

				$subscription = $new_callback_args[1];
				$old_key      = wcs_get_old_subscription_key( $subscription );

				$subscription_in_deprecated_structure = array(
					$old_key => wcs_get_subscription_in_deprecated_structure( $subscription ),
				);

				$all_actions = apply_filters( $old_hook, $return_value, $subscription_in_deprecated_structure );

				// Only change the return value if a new value was returned by the filter
				if ( $all_actions !== $return_value ) {
					$return_value = $all_actions[ $old_key ];
				}
				break;

			// New arg spec: $action_link, $subscription_id, $status
			// Old arg spec: $action_link, $subscription_key, $status
			case 'woocommerce_subscriptions_users_action_link':
				$subscription = $new_callback_args[1];
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_old_subscription_key( wcs_get_subscription( $new_callback_args[1] ) ), $new_callback_args[2] );
				break;

			// New arg spec: failed_payments_exceeded, $subscription
			// Old arg spec: $failed_payments_exceeded, $user_id, $subscription_key
			case 'woocommerce_subscriptions_max_failed_payments_exceeded':
				$subscription = $new_callback_args[1];
				$return_value = apply_filters( $old_hook, $return_value, $subscription->get_user_id(), wcs_get_old_subscription_key( $subscription ) );
				break;

			// New arg spec: $payment_method_to_display, $subscription
			// Old arg spec: $payment_method_to_display, $subscription_details, $order
			case 'woocommerce_my_subscriptions_recurring_payment_method':
				$subscription = $new_callback_args[1];
				$return_value = apply_filters( $old_hook, $return_value, wcs_get_subscription_in_deprecated_structure( $subscription ), self::get_order( $subscription ) );
				break;

			// New arg spec: $allow_update, $new_payment_method, $subscription
			// Old arg spec: $allow_update, $new_payment_method
			case 'woocommerce_subscriptions_update_recurring_payment_via_pay_shortcode':
				$return_value = apply_filters( $old_hook, $return_value, $new_callback_args[1] );
				break;

			// New arg spec: $has_subscription, $user_id, $product_id, $status
			// Old arg spec: $has_subscription, $user_id, $product_id
			case 'woocommerce_user_has_subscription':
				$return_value = apply_filters( $old_hook, $return_value, $new_callback_args[0], $new_callback_args[1] );
				break;

			// New arg spec: $subscriptions (array of objects), $user_id
			// Old arg spec: $subscriptions (array of arrays), $user_id
			case 'woocommerce_users_subscriptions':

				// For this hook, the old return value is incompatible with the new return value, so we will trigger another, more urgent notice
				trigger_error( 'Callbacks on the "woocommerce_users_subscriptions" filter must be updated immediately. Attach callbacks to the new "wcs_get_users_subscriptions" filter instead. Since version 2.0 of WooCommerce Subscriptions, the "woocommerce_users_subscriptions" filter does not affect the list of a user\'s subscriptions as the subscription data structure has changed.' );

				// But still trigger the old hook, even if we can't map the old data to the new return value
				$subscriptions     = $new_callback_args[0];
				$old_subscriptions = array();

				foreach ( $subscriptions as $subscription ) {
					$old_subscriptions[ wcs_get_old_subscription_key( $subscription ) ] = wcs_get_subscription_in_deprecated_structure( $subscription );
				}

				apply_filters( $old_hook, $old_subscriptions, $new_callback_args[1] );
				break;

			// New arg spec: $can_be_updated, $new_status_or_meta, $subscription
			// Old arg spec: $can_be_changed, $new_status_or_meta, $args
			case 'woocommerce_can_subscription_be_changed_to':

				$subscription = $new_callback_args[2];

				// Build the old $arg object
				$args = new stdClass();
				$args->subscription_key           = wcs_get_old_subscription_key( $subscription );
				$args->subscription               = wcs_get_subscription_in_deprecated_structure( $subscription );
				$args->user_id                    = $subscription->get_user_id();
				$args->order                      = self::get_order( $subscription );
				$args->payment_gateway            = $subscription->get_payment_method();
				$args->order_uses_manual_payments = $subscription->is_manual();
				$return_value = apply_filters( $old_hook, $return_value, $args );
				break;
		}

		return $return_value;
	}
}
