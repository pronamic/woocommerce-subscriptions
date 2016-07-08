<?php
/**
 * WooCommerce Subscriptions Admin Meta Boxes
 *
 * Sets up the write panels used by the subscription custom order/post type
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WC_Admin_Meta_Boxes
 */
class WCS_Admin_Meta_Boxes {

	/**
	 * Constructor
	 */
	public function __construct() {

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 25 );

		add_action( 'add_meta_boxes', array( $this, 'remove_meta_boxes' ), 35 );

		// We need to remove core WC save methods for meta boxes we don't use
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'remove_meta_box_save' ), -1, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles_scripts' ), 20 );

		// We need to hook to the 'shop_order' rather than 'shop_subscription' because we declared that the 'shop_susbcription' order type supports 'order-meta-boxes'
		add_action( 'woocommerce_process_shop_order_meta', 'WCS_Meta_Box_Schedule::save', 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', 'WCS_Meta_Box_Subscription_Data::save', 10, 2 );

		add_filter( 'woocommerce_order_actions', __CLASS__ . '::add_subscription_actions', 10, 1 );

		add_action( 'woocommerce_order_action_wcs_process_renewal', __CLASS__ .  '::process_renewal_action_request', 10, 1 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', __CLASS__ .  '::create_pending_renewal_action_request', 10, 1 );

		add_filter( 'woocommerce_resend_order_emails_available', __CLASS__ . '::remove_order_email_actions', 0, 1 );
	}

	/**
	 * Add WC Meta boxes
	 */
	public function add_meta_boxes() {
		global $current_screen, $post_ID;

		add_meta_box( 'woocommerce-subscription-data', _x( 'Subscription Data', 'meta box title', 'woocommerce-subscriptions' ), 'WCS_Meta_Box_Subscription_Data::output', 'shop_subscription', 'normal', 'high' );

		add_meta_box( 'woocommerce-subscription-schedule', _x( 'Billing Schedule', 'meta box title', 'woocommerce-subscriptions' ), 'WCS_Meta_Box_Schedule::output', 'shop_subscription', 'side', 'default' );

		remove_meta_box( 'woocommerce-order-data', 'shop_subscription', 'normal' );

		add_meta_box( 'subscription_renewal_orders', __( 'Related Orders', 'woocommerce-subscriptions' ), 'WCS_Meta_Box_Related_Orders::output', 'shop_subscription', 'normal', 'low' );

		// Only display the meta box if an order relates to a subscription
		if ( 'shop_order' === get_post_type( $post_ID ) && wcs_order_contains_subscription( $post_ID, 'any' ) ) {
			add_meta_box( 'subscription_renewal_orders', __( 'Related Orders', 'woocommerce-subscriptions' ), 'WCS_Meta_Box_Related_Orders::output', 'shop_order', 'normal', 'low' );
		}
	}

	/**
	 * Removes the core Order Data meta box as we add our own Subscription Data meta box
	 */
	public function remove_meta_boxes() {
		remove_meta_box( 'woocommerce-order-data', 'shop_subscription', 'normal' );
	}

	/**
	 * Don't save save some order related meta boxes
	 */
	public function remove_meta_box_save( $post_id, $post ) {

		if ( 'shop_subscription' == $post->post_type ) {
			remove_action( 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Data::save', 40, 2 );
		}
	}

	/**
	 * Print admin styles/scripts
	 */
	public function enqueue_styles_scripts() {
		global $post;

		// Get admin screen id
		$screen = get_current_screen();

		if ( 'shop_subscription' == $screen->id ) {

			wp_register_script( 'jstz', plugin_dir_url( WC_Subscriptions::$plugin_file ) . '/assets/js/admin/jstz.min.js' );

			wp_register_script( 'momentjs', plugin_dir_url( WC_Subscriptions::$plugin_file ) . '/assets/js/admin/moment.min.js' );

			wp_enqueue_script( 'wcs-admin-meta-boxes-subscription', plugin_dir_url( WC_Subscriptions::$plugin_file ) . '/assets/js/admin/meta-boxes-subscription.js', array( 'wc-admin-meta-boxes', 'jstz', 'momentjs' ), WC_VERSION );

			wp_localize_script( 'wcs-admin-meta-boxes-subscription', 'wcs_admin_meta_boxes', apply_filters( 'woocommerce_subscriptions_admin_meta_boxes_script_parameters', array(
				'i18n_start_date_notice'         => __( 'Please enter a start date in the past.', 'woocommerce-subscriptions' ),
				'i18n_past_date_notice'          => __( 'Please enter a date at least one hour into the future.', 'woocommerce-subscriptions' ),
				'i18n_next_payment_start_notice' => __( 'Please enter a date after the trial end.', 'woocommerce-subscriptions' ),
				'i18n_next_payment_trial_notice' => __( 'Please enter a date after the start date.', 'woocommerce-subscriptions' ),
				'i18n_trial_end_start_notice'    => __( 'Please enter a date after the start date.', 'woocommerce-subscriptions' ),
				'i18n_trial_end_next_notice'     => __( 'Please enter a date before the next payment.', 'woocommerce-subscriptions' ),
				'i18n_end_date_notice'           => __( 'Please enter a date after the next payment.', 'woocommerce-subscriptions' ),
				'process_renewal_action_warning' => __( "Are you sure you want to process a renewal?\n\nThis will charge the customer and email them the renewal order (if emails are enabled).", 'woocommerce-subscriptions' ),
				'payment_method'                 => wcs_get_subscription( $post )->payment_method,
				'search_customers_nonce'         => wp_create_nonce( 'search-customers' ),
			) ) );
		}
	}

	/**
	 * Adds actions to the admin edit subscriptions page, if the subscription hasn't ended and the payment method supports them.
	 *
	 * @param array $actions An array of available actions
	 * @return array An array of updated actions
	 * @since 2.0
	 */
	public static function add_subscription_actions( $actions ) {
		global $theorder;

		if ( wcs_is_subscription( $theorder ) && ! $theorder->has_status( wcs_get_subscription_ended_statuses() ) ) {

			if ( $theorder->payment_method_supports( 'subscription_date_changes' ) && $theorder->has_status( 'active' ) ) {
				$actions['wcs_process_renewal'] = esc_html__( 'Process renewal', 'woocommerce-subscriptions' );
			}

			$actions['wcs_create_pending_renewal'] = esc_html__( 'Create pending renewal order', 'woocommerce-subscriptions' );
		}

		return $actions;
	}

	/**
	 * Handles the action request to process a renewal order.
	 *
	 * @param array $subscription
	 * @since 2.0
	 */
	public static function process_renewal_action_request( $subscription ) {
		do_action( 'woocommerce_scheduled_subscription_payment', $subscription->id );
		$subscription->add_order_note( __( 'Process renewal order action requested by admin.', 'woocommerce-subscriptions' ), false, true );
	}

	/**
	 * Handles the action request to create a pending renewal order.
	 *
	 * @param array $subscription
	 * @since 2.0
	 */
	public static function create_pending_renewal_action_request( $subscription ) {

		$subscription->update_status( 'on-hold' );

		$renewal_order = wcs_create_renewal_order( $subscription );

		if ( ! $subscription->is_manual() ) {
			$renewal_order->set_payment_method( $subscription->payment_gateway );
		}

		$subscription->add_order_note( __( 'Create pending renewal order requested by admin action.', 'woocommerce-subscriptions' ), false, true );
	}

	/**
	 * Removes order related emails from the available actions.
	 *
	 * @param array $available_emails
	 * @since 2.0
	 */
	public static function remove_order_email_actions( $email_actions ) {
		global $theorder;

		if ( wcs_is_subscription( $theorder ) ) {
			$email_actions = array();
		}

		return $email_actions;
	}
}

new WCS_Admin_Meta_Boxes();
