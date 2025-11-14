<?php
/**
 * Recipient management.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Recipient management class.
 */
class WCSG_Recipient_Management {

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'wcs_get_users_subscriptions', __CLASS__ . '::get_users_subscriptions', 1, 2 );

		add_action( 'woocommerce_subscription_details_after_subscription_table', array( __CLASS__, 'gifting_information_after_customer_details' ), 1 );

		add_filter( 'wcs_view_subscription_actions', __CLASS__ . '::add_recipient_actions', 11, 2 );

		// We want to handle the changing of subscription status before Subscriptions core.
		add_action( 'init', __CLASS__ . '::change_user_recipient_subscription', 99 );

		add_filter( 'wcs_can_user_put_subscription_on_hold', __CLASS__ . '::recipient_can_suspend', 1, 2 );

		if ( ! is_admin() ) {
			add_filter( 'woocommerce_subscription_related_orders', array( __CLASS__, 'maybe_remove_parent_order' ), 11, 2 );
		}

		add_filter( 'user_has_cap', __CLASS__ . '::grant_recipient_capabilities', 20, 3 );

		add_action( 'delete_user_form', __CLASS__ . '::maybe_display_delete_recipient_warning', 10 );

		add_action( 'delete_user', __CLASS__ . '::maybe_remove_recipient', 10, 1 );

		add_filter( 'woocommerce_attribute_label', __CLASS__ . '::format_recipient_meta_label', 10, 2 );

		add_filter( 'woocommerce_order_item_display_meta_value', __CLASS__ . '::format_recipient_meta_value', 10 );

		add_filter( 'woocommerce_hidden_order_itemmeta', __CLASS__ . '::hide_recipient_order_item_meta', 10, 1 );

		add_action( 'woocommerce_before_order_itemmeta', __CLASS__ . '::display_recipient_meta_admin', 10, 1 );

		add_action( 'woocommerce_subscription_status_updated', __CLASS__ . '::maybe_update_recipient_role', 10, 2 );

		// Hooked onto priority 8 for compatibility with WooCommerce Memberships.
		add_action( 'woocommerce_order_status_completed', __CLASS__ . '::maybe_create_recipient', 8, 2 );
		add_action( 'woocommerce_order_status_processing', __CLASS__ . '::maybe_create_recipient', 8, 2 );

		if ( wcsg_is_woocommerce_pre( '3.0' ) ) {
			add_action( 'woocommerce_add_order_item_meta', __CLASS__ . '::maybe_add_recipient_order_item_meta', 10, 2 );
		}

		add_action( 'woocommerce_customer_reset_password', array( __CLASS__, 'maybe_add_recipient_reset_password_flag' ) );

		// Disable early renewal via modal for recipients.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_disable_early_renewal_modal_for_recipient' ), 100 );
	}

	/**
	 * Grant capabilities for subscriptions and related orders to recipients
	 *
	 * @param array $allcaps An array of user capabilities.
	 * @param array $caps    The capability being questioned.
	 * @param array $args    Additional arguments related to the capability.
	 * @return array
	 */
	public static function grant_recipient_capabilities( $allcaps, $caps, $args ) {
		if ( isset( $caps[0] ) ) {
			switch ( $caps[0] ) {
				case 'view_order':
					$user_id = $args[1];
					$order   = wc_get_order( $args[2] );

					if ( $order ) {
						if ( 'shop_subscription' === WC_Data_Store::load( 'subscription' )->get_order_type( $args[2] ) && WCS_Gifting::get_recipient_user( $order ) == $user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
							$allcaps['view_order'] = true;
						} elseif ( wcs_order_contains_renewal( $order ) ) {
							$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
							foreach ( $subscriptions as $subscription ) {
								if ( WCS_Gifting::get_recipient_user( $subscription ) == $user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
									$allcaps['view_order'] = true;
									break;
								}
							}
						}
					}
					break;
				case 'pay_for_order':
					$user_id = $args[1];
					$order   = wc_get_order( $args[2] );

					if ( $order && wcs_order_contains_subscription( $order, 'any' ) ) {
						$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

						foreach ( $subscriptions as $subscription ) {
							if ( WCS_Gifting::get_recipient_user( $subscription ) == $user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
								$allcaps['pay_for_order'] = true;
								break;
							}
						}
					}
					break;
				case 'subscribe_again':
					// subscribe_again is the capability used to enable resubscription. Recipients cannot resubscribe (@see https://docs.woocommerce.com/document/subscriptions-gifting/#section-13) and so we only want to enable this function for early renewals.
					if ( ! isset( $_GET['subscription_renewal_early'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						break;
					}

					$user_id      = $args[1];
					$subscription = wcs_get_subscription( $args[2] );

					if ( WCS_Gifting::is_gifted_subscription( $subscription ) && (int) WCS_Gifting::get_recipient_user( $subscription ) === $user_id ) {
						$allcaps['subscribe_again'] = true;
					}
					break;

			}
		}
		return $allcaps;
	}

	/**
	 * Adds available user actions to the subscription recipient
	 *
	 * @param array  $actions      An array of actions the user can peform.
	 * @param object $subscription Subscription object.
	 * @return array An updated array of actions the user can perform on a gifted subscription.
	 */
	public static function add_recipient_actions( $actions, $subscription ) {

		if ( WCS_Gifting::is_gifted_subscription( $subscription ) && get_current_user_id() == WCS_Gifting::get_recipient_user( $subscription ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			$recipient_actions = array();
			$current_status    = $subscription->get_status();
			$recipient_user    = wp_get_current_user();
			$subscription_id   = wcsg_get_objects_id( $subscription );

			$admin_with_suspension_disallowed = ( current_user_can( 'manage_woocommerce' ) && '0' === get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', '0' ) ) ? true : false;

			if ( $subscription->can_be_updated_to( 'on-hold' ) && wcs_can_user_put_subscription_on_hold( $subscription, $recipient_user ) && ! $admin_with_suspension_disallowed ) {
				$recipient_actions['suspend'] = array(
					'url'  => self::get_recipient_change_status_link( $subscription_id, 'on-hold', $recipient_user->ID, $current_status ),
					'name' => __( 'Suspend', 'woocommerce-subscriptions' ),
				);
			} elseif ( $subscription->can_be_updated_to( 'active' ) && ! $subscription->needs_payment() ) {
				$recipient_actions['reactivate'] = array(
					'url'  => self::get_recipient_change_status_link( $subscription_id, 'active', $recipient_user->ID, $current_status ),
					'name' => __( 'Reactivate', 'woocommerce-subscriptions' ),
				);
			}

			if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
				$recipient_actions['cancel'] = array(
					'url'  => self::get_recipient_change_status_link( $subscription_id, 'cancelled', $recipient_user->ID, $current_status ),
					'name' => __( 'Cancel', 'woocommerce-subscriptions' ),
				);
			}

			$actions = array_merge( $recipient_actions, $actions );

			// Remove the ability for recipients to change the payment method.
			unset( $actions['change_payment_method'] );
		}
		return $actions;
	}

	/**
	 * Disables the early renewal modal for recipients. This is to prevent recipients from renewing using the purchaser's
	 * payment information.
	 * This is only required when running on WCS < 3.0.5, where being able to renew early implies access to the early renewal modal.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.1.
	 */
	public static function maybe_disable_early_renewal_modal_for_recipient() {
		if ( ! wcsg_is_wc_subscriptions_pre( '3.0.5' ) ) {
			return;
		}

		if ( ! wcs_is_view_subscription_page() || ! isset( $GLOBALS['wp']->query_vars['view-subscription'] ) ) {
			return;
		}

		$subscription = wcs_get_subscription( absint( $GLOBALS['wp']->query_vars['view-subscription'] ) );
		if ( ! $subscription || ! WCS_Gifting::is_gifted_subscription( $subscription ) || get_current_user_id() === $subscription->get_user_id() ) {
			return;
		}

		$callback = array( 'WCS_Early_Renewal_Modal_Handler', 'maybe_print_early_renewal_modal' );
		remove_action( 'woocommerce_subscription_details_table', $callback, has_action( 'woocommerce_subscription_details_table', $callback ) );
	}

	/**
	 * Generates a link for the user to change the status of a subscription
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $status          The status the recipient has requested to change the subscription to.
	 * @param int    $recipient_id    Recipient ID.
	 * @param string $current_status  Current status.
	 */
	private static function get_recipient_change_status_link( $subscription_id, $status, $recipient_id, $current_status ) {

		$action_link = add_query_arg(
			array(
				'subscription_id'              => $subscription_id,
				'change_subscription_to'       => $status,
				'wcsg_requesting_recipient_id' => $recipient_id,
			)
		);
		$action_link = wp_nonce_url( $action_link, $subscription_id . $current_status );

		return $action_link;
	}

	/**
	 * Checks if a status change request is by the recipient, and if it is,
	 * validate the request and proceed to change to the subscription.
	 */
	public static function change_user_recipient_subscription() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// Check if the request is being made from the recipient (wcsg_requesting_recipient_id is set).
		if ( isset( $_GET['wcsg_requesting_recipient_id'] ) && isset( $_GET['change_subscription_to'] ) && isset( $_GET['subscription_id'] ) && ! empty( $_GET['_wpnonce'] ) ) {

			remove_action( 'init', 'WCS_User_Change_Status_Handler::maybe_change_users_subscription', 100 );

			$subscription = wcs_get_subscription( absint( $_GET['subscription_id'] ) );
			$user_id      = $subscription->get_user_id();
			$new_status   = $_GET['change_subscription_to']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			if ( WCS_User_Change_Status_Handler::validate_request( $user_id, $subscription, $new_status, $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				WCS_User_Change_Status_Handler::change_users_subscription( $subscription, $new_status );
				wp_safe_redirect( $subscription->get_view_order_url() );
				exit;
			}
		}
		// phpcs:enable
	}

	/**
	 * Allows the recipient to suspend a subscription, provided the suspension count hasnt been reached
	 *
	 * @param bool            $user_can_suspend Whether the user can suspend a subscription.
	 * @param WC_Subscription $subscription     Subscription object.
	 */
	public static function recipient_can_suspend( $user_can_suspend, $subscription ) {

		if ( WCS_Gifting::is_gifted_subscription( $subscription ) && get_current_user_id() == WCS_Gifting::get_recipient_user( $subscription ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison

			// Make sure subscription suspension count hasn't been reached.
			$suspension_count    = wcsg_get_objects_property( $subscription, 'suspension_count' );
			$allowed_suspensions = get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', 0 );

			if ( 'unlimited' === $allowed_suspensions || $allowed_suspensions > $suspension_count ) { // 0 not > anything so prevents a customer ever being able to suspend
				$user_can_suspend = true;
			}
		}

		return $user_can_suspend;

	}

	/**
	 * Adds all the subscriptions that have been gifted to a user to their subscriptions
	 *
	 * @param array $subscriptions An array of subscriptions assigned to the user.
	 * @param int   $user_id       Recipient's user ID.
	 * @return array An updated array of subscriptions with any subscriptions gifted to the user added.
	 */
	public static function get_users_subscriptions( $subscriptions, $user_id ) {

		// Get the subscription posts that have been gifted to this user.
		$recipient_subscriptions = self::get_recipient_subscriptions( $user_id );

		foreach ( $recipient_subscriptions as $subscription_id ) {
			$subscriptions[ $subscription_id ] = wcs_get_subscription( $subscription_id );
		}

		if ( 0 < count( $recipient_subscriptions ) ) {
			krsort( $subscriptions );
		}

		return $subscriptions;
	}

	/**
	 * Adds recipient/purchaser information to the view subscription page.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 */
	public static function gifting_information_after_customer_details( $subscription ) {
		// check if the subscription is gifted.
		if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {
			$customer_user_id  = $subscription->get_user_id();
			$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );
			$current_user_id   = get_current_user_id();

			if ( $current_user_id == $customer_user_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				wc_get_template(
					'html-view-subscription-gifting-information.php',
					array(
						'user_title' => 'Recipient',
						'name'       => WCS_Gifting::get_user_display_name( $recipient_user_id ),
					),
					'',
					plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/'
				);
			} else {
				wc_get_template(
					'html-view-subscription-gifting-information.php',
					array(
						'user_title' => 'Purchaser',
						'name'       => WCS_Gifting::get_user_display_name( $customer_user_id ),
					),
					'',
					plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/'
				);
			}
		}
	}

	/**
	 * Gets an array of subscription ids which have been gifted to a user
	 *
	 * @param int   $user_id  The user id of the recipient.
	 * @param int   $order_id The Order ID which contains the subscription.
	 * @param array $args     Array of arguments.
	 *
	 * @return int[] An array of subscription IDs gifted to the user
	 */
	public static function get_recipient_subscriptions( $user_id, $order_id = 0, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'subscriptions_per_page' => -1,
				'subscription_status'    => 'any',
				'orderby'                => 'start_date',
				'order'                  => 'DESC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_recipient_user',
						'value'   => $user_id,
						'compare' => '=',
					),
				),
			)
		);

		if ( 0 != $order_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			$args['order_id'] = $order_id;
		}

		return array_keys( wcs_get_subscriptions( $args ) );
	}

	/**
	 * Filter the WC_Subscription::get_related_orders() method removing parent orders for recipients.
	 *
	 * @param array           $related_orders An array of order ids related to the $subscription.
	 * @param WC_Subscription $subscription   Subscription object.
	 * @return array an array of order ids related to the $subscription.
	 */
	public static function maybe_remove_parent_order( $related_orders, $subscription ) {
		if ( WCS_Gifting::is_gifted_subscription( $subscription ) && get_current_user_id() == WCS_Gifting::get_recipient_user( $subscription ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			$parent_order_id = $subscription->get_parent_id();

			if ( isset( $related_orders[ $parent_order_id ] ) ) {
				unset( $related_orders[ $parent_order_id ] );
			}
		}
		return $related_orders;
	}

	/**
	 * Maybe add recipient information to order item meta for displaying in order item tables.
	 *
	 * @param int   $item_id   The item ID.
	 * @param array $cart_item The cart's item.
	 */
	public static function maybe_add_recipient_order_item_meta( $item_id, $cart_item ) {
		$recipient_user_id = WCSG_Cart::get_recipient_from_cart_item( $cart_item );

		if ( $recipient_user_id ) {
			wc_update_order_item_meta( $item_id, 'wcsg_recipient', 'wcsg_recipient_id_' . $recipient_user_id );

			// Clear the order item meta cache so all meta is included in emails sent on checkout.
			$cache_key = WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'item_meta_array_' . $item_id;
			wp_cache_delete( $cache_key, 'orders' );
		}
	}

	/**
	 * Format the order item meta label to be displayed.
	 *
	 * @param string $label The item meta label displayed.
	 * @param string $name  The name of the order item meta (key).
	 */
	public static function format_recipient_meta_label( $label, $name ) {
		if ( 'wcsg_recipient' === $name || 'wcsg_deleted_recipient_data' === $name ) {
			$label = __( 'Recipient', 'woocommerce-subscriptions' );
		}
		return $label;
	}

	/**
	 * Format recipient order item meta value by extracting the recipient user id.
	 *
	 * @param mixed $value Order item meta value.
	 */
	public static function format_recipient_meta_value( $value ) {
		if ( false !== strpos( $value, 'wcsg_recipient_id' ) ) {

			$recipient_id = substr( $value, strlen( 'wcsg_recipient_id_' ) );
			$strip_tags   = is_checkout() && ! is_wc_endpoint_url( 'order-received' );

			return WCS_Gifting::get_user_display_name( $recipient_id, $strip_tags );

		} elseif ( false !== strpos( $value, 'wcsg_deleted_recipient_data' ) ) {

			$recipient_data = json_decode( substr( $value, strlen( 'wcsg_deleted_recipient_data_' ) ), true );
			return $recipient_data['display_name'];
		}
		return $value;
	}

	/**
	 * Prevents default display of recipient meta in admin panel.
	 *
	 * @param array $ignored_meta_keys An array of order item meta keys which are skipped when displaying meta.
	 */
	public static function hide_recipient_order_item_meta( $ignored_meta_keys ) {
		array_push( $ignored_meta_keys, 'wcsg_recipient', 'wcsg_deleted_recipient_data' );
		return $ignored_meta_keys;
	}

	/**
	 * Displays recipient order item meta for admin panel.
	 *
	 * @param int $item_id The id of the order item.
	 */
	public static function display_recipient_meta_admin( $item_id ) {

		$recipient_meta             = wc_get_order_item_meta( $item_id, 'wcsg_recipient' );
		$deleted_recipient_meta     = wc_get_order_item_meta( $item_id, 'wcsg_deleted_recipient_data' );
		$recipient_shipping_address = '';
		$recipient_display_name     = '';

		if ( ! empty( $recipient_meta ) ) {

			$recipient_id               = substr( $recipient_meta, strlen( 'wcsg_recipient_id_' ) );
			$recipient_shipping_address = WC()->countries->get_formatted_address( WCS_Gifting::get_users_shipping_address( $recipient_id ) );
			$recipient_display_name     = WCS_Gifting::get_user_display_name( $recipient_id );

		} elseif ( ! empty( $deleted_recipient_meta ) ) {

			$recipient_data         = json_decode( substr( $deleted_recipient_meta, strlen( 'wcsg_deleted_recipient_data_' ) ), true );
			$recipient_display_name = $recipient_data['display_name'];

			unset( $recipient_data['display_name'] );

			$recipient_shipping_address = WC()->countries->get_formatted_address( $recipient_data );
		}

		if ( ! empty( $recipient_meta ) || ! empty( $deleted_recipient_meta ) ) {

			if ( empty( $recipient_shipping_address ) ) {
				$recipient_shipping_address = 'N/A';
			}

			$tooltip_content  = '';
			$tooltip_content .= __( 'Shipping:', 'woocommerce-subscriptions' );
			$tooltip_content .= ' ';
			$tooltip_content .= $recipient_shipping_address;

			echo '<br />';
			echo '<b>' . esc_html__( 'Recipient:', 'woocommerce-subscriptions' ) . '</b> ' . wp_kses( $recipient_display_name, wp_kses_allowed_html( 'user_description' ) );
			echo '<img class="help_tip" data-tip="' . wc_sanitize_tooltip( $tooltip_content ) . '" src="' . esc_url( WC()->plugin_url() ) . '/assets/images/help.png" height="16" width="16" />'; // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Removes recipient subscription meta from gifted subscriptions if the recipient is deleted.
	 *
	 * @param int $user_id The id of the user being deleted.
	 */
	public static function maybe_remove_recipient( $user_id ) {

		$gifted_items = WCS_Gifting::get_recipient_order_items( $user_id );

		if ( ! empty( $gifted_items ) ) {
			$recipient_data = wp_json_encode(
				array_merge(
					array( 'display_name' => addslashes( WCS_Gifting::get_user_display_name( $user_id ) ) ),
					WCS_Gifting::get_users_shipping_address( $user_id )
				)
			);

			foreach ( $gifted_items as $gifted_item ) {
				if ( ! wcs_is_subscription( $gifted_item['order_id'] ) ) {
					wc_update_order_item_meta( $gifted_item['order_item_id'], 'wcsg_deleted_recipient_data', 'wcsg_deleted_recipient_data_' . $recipient_data );
				}
				wc_delete_order_item_meta( $gifted_item['order_item_id'], 'wcsg_recipient', 'wcsg_recipient_id_' . $user_id );
			}
		}
	}

	/**
	 * Displays a warning message if a recipient is in the process of being deleted.
	 */
	public static function maybe_display_delete_recipient_warning() {

		$recipient_users = array();

		$user_ids = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( empty( $_REQUEST['users'] ) && ! empty( $_REQUEST['user'] ) ) {
			$user_ids = array( $_REQUEST['user'] );
		} else {
			$user_ids = $_REQUEST['users'];
		}
		// phpcs:enable

		if ( ! empty( $user_ids ) ) {

			foreach ( $user_ids as $user_id ) {

				$gifted_subscriptions = self::get_recipient_subscriptions( $user_id );

				if ( 0 !== count( $gifted_subscriptions ) ) {
					$recipient_users[ $user_id ] = $gifted_subscriptions;
				}
			}

			$recipients_count = count( $recipient_users );

			if ( 0 !== $recipients_count ) {

				echo '<p><strong>' . esc_html__( 'WARNING:', 'woocommerce-subscriptions' ) . ' </strong>';
				echo esc_html( _n( 'The following recipient will be removed from their subscriptions:', 'The following recipients will be removed from their subscriptions:', $recipients_count, 'woocommerce-subscriptions' ) );

				echo '<p><dl>';

				foreach ( $recipient_users as $recipient_id => $subscriptions ) {

					$recipient = get_userdata( $recipient_id );

					echo '<dt>ID #' . esc_attr( $recipient_id ) . ': ' . esc_attr( $recipient->user_login ) . '</dt>';

					foreach ( $subscriptions as $subscription_id ) {

						$subscription = wcs_get_subscription( $subscription_id );
						echo '<dd>' . esc_html__( 'Subscription', 'woocommerce-subscriptions' ) . ' <a href="' . esc_url( wcs_get_edit_post_link( wcsg_get_objects_id( $subscription ) ) ) . '">#' . esc_html( $subscription->get_order_number() ) . '</a></dd>';

					}
				}

				echo '</dl>';

			}
		}
	}

	/**
	 * On password reset, if the user needs to update account, sets a (temporary) flag
	 *
	 * @param WP_User $user User object.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	public static function maybe_add_recipient_reset_password_flag( $user ) {
		if ( 'true' === get_user_meta( $user->ID, 'wcsg_update_account', true ) ) {
			update_user_meta( $user->ID, 'wcsg_recipient_just_reset_password', 'true' );
		}
	}

	/**
	 * Does the user require a new password after password reset?
	 *
	 * @param Int $user_id User ID we want to validate.
	 *
	 * @return bool
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	public static function user_requires_new_password( $user_id ) {
		return ! metadata_exists( 'user', $user_id, 'wcsg_recipient_just_reset_password' );
	}

	/**
	 * On subscription status changes, maybe update the role of the subscription recipient (if set) depending on the new subscription status.
	 * Sets the recipient user to the inactive subscriber role on on-hold, cancelled, expired statuses and an active subscriber role on active statuses.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param string          $new_status   The subscription's new status.
	 */
	public static function maybe_update_recipient_role( $subscription, $new_status ) {

		$inactive_statuses = array(
			'on-hold',
			'cancelled',
			'expired',
		);

		if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {
			$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );

			if ( in_array( $new_status, $inactive_statuses, true ) ) {
				wcs_maybe_make_user_inactive( $recipient_user_id );
			} elseif ( 'active' === $new_status ) {
				wcs_make_user_active( $recipient_user_id );
			}
		}
	}

	/**
	 * When orders are processed/completed, create new recipients and attach shipping information to gifted subscriptions.
	 *
	 * @param int $order_id Order ID.
	 * @param WC_Order $order Order object.
	 */
	public static function maybe_create_recipient( $order_id, ?WC_Order $order = null ) {
		$subscriptions = wcs_get_subscriptions_for_order( $order_id );

		foreach( $subscriptions as $subscription ) {
			self::maybe_create_recipient_and_attach_shipping_information( $subscription, $order );
		}
	}

	/**
	 * Maybe create a recipient user and attach shipping information to a subscription.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 * @param WC_Order $order Order object.
	 */
	public static function maybe_create_recipient_and_attach_shipping_information( $subscription, ?WC_Order $order = null ) {
		$recipient_user_email_address = $subscription->get_meta( '_recipient_user_email_address' );

		if ( ! $recipient_user_email_address ) {
			return;
		}

		$recipient_user_id = email_exists( $recipient_user_email_address );

		// Create a new user if the recipient's email doesn't already exist.
		if ( ! $recipient_user_id ) {
			WCSG_Email::use_gifting_new_account_email();
			$recipient_user_id = WCS_Gifting::create_recipient_user( $recipient_user_email_address );
		}

		if ( ! is_numeric( $recipient_user_id ) ) {
			return;
		}

		WCS_Gifting::set_recipient_user( $subscription, $recipient_user_id, 'save', 0, $order );

		$subscription->set_shipping_first_name( get_user_meta( $recipient_user_id, 'shipping_first_name', true ) );
		$subscription->set_shipping_last_name( get_user_meta( $recipient_user_id, 'shipping_last_name', true ) );
		$subscription->set_shipping_company( get_user_meta( $recipient_user_id, 'shipping_company', true ) );
		$subscription->set_shipping_address_1( get_user_meta( $recipient_user_id, 'shipping_address_1', true ) );
		$subscription->set_shipping_address_2( get_user_meta( $recipient_user_id, 'shipping_address_2', true ) );
		$subscription->set_shipping_city( get_user_meta( $recipient_user_id, 'shipping_city', true ) );
		$subscription->set_shipping_state( get_user_meta( $recipient_user_id, 'shipping_state', true ) );
		$subscription->set_shipping_postcode( get_user_meta( $recipient_user_id, 'shipping_postcode', true ) );
		$subscription->set_shipping_country( get_user_meta( $recipient_user_id, 'shipping_country', true ) );

		$subscription->save();
	}
}
