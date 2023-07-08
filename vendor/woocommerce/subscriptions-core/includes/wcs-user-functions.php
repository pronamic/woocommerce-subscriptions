<?php
/**
 * WooCommerce Subscriptions User Functions
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Give a user the Subscription's default subscriber role
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_make_user_active( $user_id ) {
	wcs_update_users_role( $user_id, 'default_subscriber_role' );
}

/**
 * Give a user the Subscription's default subscriber's inactive role
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_make_user_inactive( $user_id ) {
	wcs_update_users_role( $user_id, 'default_inactive_role' );
}

/**
 * Give a user the Subscription's default subscriber's inactive role if they do not have an active subscription
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_maybe_make_user_inactive( $user_id ) {
	if ( ! wcs_user_has_subscription( $user_id, '', 'active' ) ) {
		wcs_update_users_role( $user_id, 'default_inactive_role' );
	}
}

/**
 * Wrapper for wcs_maybe_make_user_inactive() that accepts a subscription instead of a user ID.
 * Handy for hooks that pass a subscription object.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.9
 * @param WC_Subscription|WC_Order
 */
function wcs_maybe_make_user_inactive_for( $subscription ) {
	wcs_maybe_make_user_inactive( $subscription->get_user_id() );
}
add_action( 'woocommerce_subscription_status_failed', 'wcs_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_on-hold', 'wcs_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_cancelled', 'wcs_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_switched', 'wcs_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_expired', 'wcs_maybe_make_user_inactive_for', 10, 1 );

/**
 * Update a user's role to a special subscription's role
 *
 * @param int $user_id The ID of a user
 * @param string $role_new The special name assigned to the role by Subscriptions, one of 'default_subscriber_role', 'default_inactive_role' or 'default_cancelled_role'
 * @return WP_User The user with the new role.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_update_users_role( $user_id, $role_new ) {

	$user = new WP_User( $user_id );

	// Never change an admin's role to avoid locking out admins testing the plugin
	if ( ! empty( $user->roles ) && in_array( 'administrator', $user->roles ) ) {
		return;
	}

	// Allow plugins to prevent Subscriptions from handling roles
	if ( ! apply_filters( 'woocommerce_subscriptions_update_users_role', true, $user, $role_new ) ) {
		return;
	}

	$roles = wcs_get_new_user_role_names( $role_new );

	$role_new = $roles['new'];
	$role_old = $roles['old'];

	if ( ! empty( $role_old ) ) {
		$user->remove_role( $role_old );
	}

	$user->add_role( $role_new );

	do_action( 'woocommerce_subscriptions_updated_users_role', $role_new, $user, $role_old );
	return $user;
}

/**
 * Gets default new and old role names if the new role is 'default_subscriber_role'. Otherwise returns role_new and an
 * empty string.
 *
 * @param $role_new string the new role of the user
 * @return array with keys 'old' and 'new'.
 */
function wcs_get_new_user_role_names( $role_new ) {
	$default_subscriber_role = wcs_get_subscriber_role();
	$default_cancelled_role  = wcs_get_inactive_subscriber_role();
	$role_old = '';

	if ( 'default_subscriber_role' == $role_new ) {
		$role_old = $default_cancelled_role;
		$role_new = $default_subscriber_role;
	} elseif ( in_array( $role_new, array( 'default_inactive_role', 'default_cancelled_role' ) ) ) {
		$role_old = $default_subscriber_role;
		$role_new = $default_cancelled_role;
	}

	return array(
		'new' => $role_new,
		'old' => $role_old,
	);
}

/**
 * Check if a user has a subscription, optionally to a specific product and/or with a certain status.
 *
 * @param int $user_id (optional) The ID of a user in the store. If left empty, the current user's ID will be used.
 * @param int $product_id (optional) The ID of a product in the store. If left empty, the function will see if the user has any subscription.
 * @param mixed $status (optional) A valid subscription status string or array. If left empty, the function will see if the user has a subscription of any status.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 *
 * @return bool
 */
function wcs_user_has_subscription( $user_id = 0, $product_id = '', $status = 'any' ) {

	$subscriptions = wcs_get_users_subscriptions( $user_id );

	$has_subscription = false;

	if ( empty( $product_id ) ) { // Any subscription

		if ( ! empty( $status ) && 'any' != $status ) { // We need to check for a specific status
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->has_status( $status ) ) {
					$has_subscription = true;
					break;
				}
			}
		} elseif ( ! empty( $subscriptions ) ) {
			$has_subscription = true;
		}
	} else {

		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->has_product( $product_id ) && ( empty( $status ) || 'any' == $status || $subscription->has_status( $status ) ) ) {
				$has_subscription = true;
				break;
			}
		}
	}

	return apply_filters( 'wcs_user_has_subscription', $has_subscription, $user_id, $product_id, $status );
}

/**
 * Gets all the active and inactive subscriptions for a user, as specified by $user_id
 *
 * @param int $user_id (optional) The id of the user whose subscriptions you want. Defaults to the currently logged in user.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 *
 * @return WC_Subscription[]
 */
function wcs_get_users_subscriptions( $user_id = 0 ) {
	if ( 0 === $user_id || empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$subscriptions = array();

	if ( has_filter( 'wcs_pre_get_users_subscriptions' ) ) {
		wcs_deprecated_function( 'The "wcs_pre_get_users_subscriptions" hook should no longer be used. A persistent caching layer is now in place. Because of this, "wcs_pre_get_users_subscriptions"', '2.3.0' );
		$filtered_subscriptions = apply_filters( 'wcs_pre_get_users_subscriptions', $subscriptions, $user_id );

		if ( is_array( $filtered_subscriptions ) ) {
			$subscriptions = $filtered_subscriptions;
		}
	}

	if ( empty( $subscriptions ) && 0 !== $user_id && ! empty( $user_id ) ) {
		$subscription_ids = WCS_Customer_Store::instance()->get_users_subscription_ids( $user_id );

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( $subscription ) {
				$subscriptions[ $subscription_id ] = $subscription;
			}
		}
	}

	return apply_filters( 'wcs_get_users_subscriptions', $subscriptions, $user_id );
}

/**
 * Get subscription IDs for the given user.
 *
 * @author Jeremy Pry
 *
 * @param int $user_id The ID of the user whose subscriptions you want.
 *
 * @return array Array of Subscription IDs.
 */
function wcs_get_users_subscription_ids( $user_id ) {
	wcs_deprecated_function( __FUNCTION__, '2.3.0', 'WCS_Customer_Store::instance()->get_users_subscription_ids()' );
	return WCS_Customer_Store::instance()->get_users_subscription_ids( $user_id );
}

/**
 * Get subscription IDs for a user using caching.
 *
 * @author Jeremy Pry
 *
 * @param int $user_id The ID of the user whose subscriptions you want.
 *
 * @return array Array of subscription IDs.
 */
function wcs_get_cached_user_subscription_ids( $user_id = 0 ) {
	wcs_deprecated_function( __FUNCTION__, '2.3.0', 'WCS_Customer_Store::instance()->get_users_subscription_ids()' );

	$user_id = absint( $user_id );

	if ( 0 === $user_id ) {
		$user_id = get_current_user_id();
	}

	return WCS_Customer_Store::instance()->get_users_subscription_ids( $user_id );
}

/**
 * Return a link for subscribers to change the status of their subscription, as specified with $status parameter
 *
 * @param int $subscription_id A subscription's post ID
 * @param string $status A subscription's post ID
 * @param string $current_status A subscription's current status
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
 */
function wcs_get_users_change_status_link( $subscription_id, $status, $current_status = '' ) {

	if ( '' === $current_status ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( $subscription instanceof WC_Subscription ) {
			$current_status = $subscription->get_status();
		}
	}

	$action_link = add_query_arg(
		array(
			'subscription_id'        => $subscription_id,
			'change_subscription_to' => $status,
		)
	);
	$action_link = wp_nonce_url( $action_link, $subscription_id . $current_status );

	return apply_filters( 'wcs_users_change_status_link', $action_link, $subscription_id, $status );
}

/**
 * Check if a given user (or the currently logged in user) has permission to put a subscription on hold.
 *
 * By default, a store manager can put all subscriptions on hold, while other users can only suspend their own subscriptions.
 *
 * @param int|WC_Subscription $subscription An instance of a WC_Snbscription object or ID representing a 'shop_subscription' post
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_can_user_put_subscription_on_hold( $subscription, $user = '' ) {
	$user_can_suspend = false;

	if ( empty( $user ) ) {
		$user = wp_get_current_user();
	} elseif ( is_int( $user ) ) {
		$user = get_user_by( 'id', $user );
	}

	if ( user_can( $user, 'manage_woocommerce' ) ) { // Admin, so can always suspend a subscription
		$user_can_suspend = true;
	}

	return apply_filters( 'wcs_can_user_put_subscription_on_hold', $user_can_suspend, $subscription, $user );
}

/**
 * Retrieve available actions that a user can perform on the subscription
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 *
 * @param WC_Subscription $subscription The subscription.
 * @param int             $user_id      The user.
 *
 * @return array
 */
function wcs_get_all_user_actions_for_subscription( $subscription, $user_id ) {

	$actions = array();

	if ( user_can( $user_id, 'edit_shop_subscription_status', $subscription->get_id() ) ) {
		$current_status = $subscription->get_status();

		if ( $subscription->can_be_updated_to( 'active' ) && ! $subscription->needs_payment() ) {
			$actions['reactivate'] = array(
				'url'  => wcs_get_users_change_status_link( $subscription->get_id(), 'active', $current_status ),
				'name' => __( 'Reactivate', 'woocommerce-subscriptions' ),
			);
		}

		if ( wcs_can_user_resubscribe_to( $subscription, $user_id ) && false == $subscription->can_be_updated_to( 'active' ) ) {
			$actions['resubscribe'] = array(
				'url'  => wcs_get_users_resubscribe_link( $subscription ),
				'name' => __( 'Resubscribe', 'woocommerce-subscriptions' ),
			);
		}

		// Show button for subscriptions which can be cancelled and which may actually require cancellation (i.e. has a future payment)
		$next_payment = $subscription->get_time( 'next_payment' );
		if ( $subscription->can_be_updated_to( 'cancelled' ) && ( ! $subscription->is_one_payment() && ( $subscription->has_status( 'on-hold' ) && empty( $next_payment ) ) || $next_payment > 0 ) ) {
			$actions['cancel'] = array(
				'url'  => wcs_get_users_change_status_link( $subscription->get_id(), 'cancelled', $current_status ),
				'name' => _x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ),
			);
		}
	}

	return apply_filters( 'wcs_view_subscription_actions', $actions, $subscription, $user_id );
}

/**
 * Checks if a user has a certain capability
 *
 * @access public
 * @param array $allcaps
 * @param array $caps
 * @param array $args
 * @return array
 */
function wcs_user_has_capability( $allcaps, $caps, $args ) {
	if ( isset( $caps[0] ) ) {
		switch ( $caps[0] ) {
			case 'edit_shop_subscription_payment_method':
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $subscription && $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_payment_method'] = true;
				}
			break;
			case 'edit_shop_subscription_status':
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_status'] = true;
				}
			break;
			case 'edit_shop_subscription_line_items':
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_line_items'] = true;
				}
			break;
			case 'switch_shop_subscription':
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['switch_shop_subscription'] = true;
				}
			break;
			case 'subscribe_again':
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['subscribe_again'] = true;
				}
			break;
			case 'pay_for_order':
				$user_id = $args[1];
				$order   = wc_get_order( $args[2] );

				if ( $order && wcs_order_contains_subscription( $order, 'any' ) ) {

					if ( $user_id === $order->get_user_id() ) {
						$allcaps['pay_for_order'] = true;
					} else {
						unset( $allcaps['pay_for_order'] );
					}
				}
			break;
			case 'toggle_shop_subscription_auto_renewal':
				$user_id      = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $subscription && $user_id === $subscription->get_user_id() ) {
					$allcaps['toggle_shop_subscription_auto_renewal'] = true;
				} else {
					unset( $allcaps['toggle_shop_subscription_auto_renewal'] );
				}
			break;
		}
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'wcs_user_has_capability', 15, 3 );

/**
 * Grants shop managers the capability to edit subscribers.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.4
 * @param array $roles The user roles shop managers can edit.
 * @return array The list of roles editable by shop managers.
 */
function wcs_grant_shop_manager_editable_roles( $roles ) {
	$roles[] = wcs_get_subscriber_role();
	return $roles;
}

add_filter( 'woocommerce_shop_manager_editable_roles', 'wcs_grant_shop_manager_editable_roles' );

/**
 * Gets the subscriber role.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 *
 * @return string The role to apply to subscribers.
 */
function wcs_get_subscriber_role() {
	if ( class_exists( 'WCS_Subscriber_Role_Manager' ) ) {
		return WCS_Subscriber_Role_Manager::get_subscriber_role();
	}

	return 'subscriber';
}

/**
 * Gets the inactive subscriber role.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 *
 * @return string The role to apply to inactive subscribers.
 */
function wcs_get_inactive_subscriber_role() {
	if ( class_exists( 'WCS_Subscriber_Role_Manager' ) ) {
		return WCS_Subscriber_Role_Manager::get_inactive_subscriber_role();
	}

	return 'customer';
}
