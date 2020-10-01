<?php
/**
 * WooCommerce Subscriptions staging mode handler.
 *
 * @package WooCommerce Subscriptions
 * @author  Prospress
 * @since   2.3.0
 */
class WCS_Staging {

	/**
	 * Attach callbacks.
	 */
	public static function init() {
		add_action( 'woocommerce_generated_manual_renewal_order', array( __CLASS__, 'maybe_record_staging_site_renewal' ) );
		add_filter( 'woocommerce_register_post_type_subscription', array( __CLASS__, 'maybe_add_menu_badge' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_reset_admin_notice' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'maybe_add_payment_method_note' ) );
	}

	/**
	 * Add an order note to a renewal order to record when it was created under staging site conditions.
	 *
	 * @param int $renewal_order_id The renewal order ID.
	 * @since 2.3.0
	 */
	public static function maybe_record_staging_site_renewal( $renewal_order_id ) {

		if ( ! WC_Subscriptions::is_duplicate_site() ) {
			return;
		}

		$renewal_order = wc_get_order( $renewal_order_id );

		if ( $renewal_order ) {
			$wp_site_url  = WC_Subscriptions::get_site_url_from_source( 'current_wp_site' );
			$wcs_site_url = WC_Subscriptions::get_site_url_from_source( 'subscriptions_install' );

			// translators: 1-2: opening/closing <a> tags - linked to staging site, 3: link to live site.
			$message = sprintf( __( 'Payment processing skipped - renewal order created on %1$sstaging site%2$s under staging site lock. Live site is at %3$s', 'woocommerce-subscriptions' ), '<a href="' . $wp_site_url . '">', '</a>', '<a href="' . $wcs_site_url . '">' . $wcs_site_url . '</a>' );

			$renewal_order->add_order_note( $message );
		}
	}

	/**
	 * Add a badge to the Subscriptions submenu when a site is operating under a staging site lock.
	 *
	 * @param array $subscription_order_type_data The WC_Subscription register order type data.
	 * @since 2.3.0
	 */
	public static function maybe_add_menu_badge( $subscription_order_type_data ) {

		if ( isset( $subscription_order_type_data['labels']['menu_name'] ) && WC_Subscriptions::is_duplicate_site() ) {
			$subscription_order_type_data['labels']['menu_name'] .= '<span class="update-plugins">' . esc_html__( 'staging', 'woocommerce-subscriptions' ) . '</span>';
		}

		return $subscription_order_type_data;
	}

	/**
	 * Handles admin requests to redisplay the staging site admin notice.
	 *
	 * @since 2.5.5
	 */
	public static function maybe_reset_admin_notice() {
		if ( isset( $_REQUEST['wcs_display_staging_notice'] ) && is_admin() && current_user_can( 'manage_options' ) ) {
			delete_option( 'wcs_ignore_duplicate_siteurl_notice' );
			wp_safe_redirect( remove_query_arg( array( 'wcs_display_staging_notice' ) ) );
		}
	}

	/**
	 * Displays a note under the edit subscription payment method field to explain why the subscription is set to Manual Renewal.
	 *
	 * @param WC_Subscription $subscription
	 * @since 2.6.0
	 */
	public static function maybe_add_payment_method_note( $subscription ) {
		if ( wcs_is_subscription( $subscription ) && WC_Subscriptions::is_duplicate_site() && $subscription->has_payment_gateway() && ! $subscription->get_requires_manual_renewal() ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Subscription locked to Manual Renewal while the store is in staging mode. Payment method changes will take effect in live mode.', 'woocommerce-subscriptions' )
			);
		}
	}

	/**
	 * Returns the content for a tooltip explaining a subscription's payment method while in staging mode.
	 *
	 * @param WC_Subscription $subscription
	 * @return string HTML content for a tooltip.
	 * @since 2.6.0
	 */
	public static function get_payment_method_tooltip( $subscription ) {
		// translators: placeholder is a payment method title.
		return '<div class="woocommerce-help-tip" data-tip="' . sprintf( esc_attr__( 'Subscription locked to Manual Renewal while the store is in staging mode. Live payment method: %s', 'woocommerce-subscriptions' ), $subscription->get_payment_method_title() ) . '"></div>';
	}
}
