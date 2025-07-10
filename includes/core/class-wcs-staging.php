<?php
/**
 * WooCommerce Subscriptions staging mode handler.
 *
 * @package WooCommerce Subscriptions
 * @author  Prospress
 * @since   1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
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
		add_action( 'admin_notices', array( __CLASS__, 'handle_site_change_notice' ) );
	}

	/**
	 * Add an order note to a renewal order to record when it was created under staging site conditions.
	 *
	 * @param int $renewal_order_id The renewal order ID.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public static function maybe_record_staging_site_renewal( $renewal_order_id ) {

		if ( ! WCS_Staging::is_duplicate_site() ) {
			return;
		}

		$renewal_order = wc_get_order( $renewal_order_id );

		if ( $renewal_order ) {
			$wp_site_url  = self::get_site_url_from_source( 'current_wp_site' );
			$wcs_site_url = self::get_site_url_from_source( 'subscriptions_install' );

			// translators: 1-2: opening/closing <a> tags - linked to staging site, 3: link to live site.
			$message = sprintf( __( 'Payment processing skipped - renewal order created on %1$sstaging site%2$s under staging site lock. Live site is at %3$s', 'woocommerce-subscriptions' ), '<a href="' . $wp_site_url . '">', '</a>', '<a href="' . $wcs_site_url . '">' . $wcs_site_url . '</a>' );

			$renewal_order->add_order_note( $message );
		}
	}

	/**
	 * Add a badge to the Subscriptions submenu when a site is operating under a staging site lock.
	 *
	 * @param array $subscription_order_type_data The WC_Subscription register order type data.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public static function maybe_add_menu_badge( $subscription_order_type_data ) {

		if ( isset( $subscription_order_type_data['labels']['menu_name'] ) && WCS_Staging::is_duplicate_site() ) {
			$subscription_order_type_data['labels']['menu_name'] .= '<span class="update-plugins">' . esc_html__( 'staging', 'woocommerce-subscriptions' ) . '</span>';
		}

		return $subscription_order_type_data;
	}

	/**
	 * Handles admin requests to redisplay the staging site admin notice.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.5
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function maybe_add_payment_method_note( $subscription ) {
		if ( wcs_is_subscription( $subscription ) && WCS_Staging::is_duplicate_site() && $subscription->has_payment_gateway() && ! $subscription->get_requires_manual_renewal() ) {
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function get_payment_method_tooltip( $subscription ) {
		// translators: placeholder is a payment method title.
		return '<div class="woocommerce-help-tip" data-tip="' . sprintf( esc_attr__( 'Subscription locked to Manual Renewal while the store is in staging mode. Live payment method: %s', 'woocommerce-subscriptions' ), $subscription->get_payment_method_title() ) . '"></div>';
	}

	/**
	 * Displays a notice when Subscriptions is being run on a different site, like a staging or testing site.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function handle_site_change_notice() {

		if ( self::is_duplicate_site() && current_user_can( 'manage_options' ) ) {

			if ( ! empty( $_REQUEST['_wcsnonce'] ) && wp_verify_nonce( wc_clean( wp_unslash( $_REQUEST['_wcsnonce'] ) ), 'wcs_duplicate_site' ) && isset( $_GET['wc_subscription_duplicate_site'] ) ) {

				if ( 'update' === $_GET['wc_subscription_duplicate_site'] ) {

					self::set_duplicate_site_url_lock();

				} elseif ( 'ignore' === $_GET['wc_subscription_duplicate_site'] ) {

					update_option( 'wcs_ignore_duplicate_siteurl_notice', self::get_duplicate_site_lock_key() );

				}

				wp_safe_redirect( remove_query_arg( array( 'wc_subscription_duplicate_site', '_wcsnonce' ) ) );

			} elseif ( self::get_duplicate_site_lock_key() !== get_option( 'wcs_ignore_duplicate_siteurl_notice' ) ) {
				$notice = new WCS_Admin_Notice( 'error' );
				$notice->set_simple_content(
					sprintf(
						// translators: 1$-2$: opening and closing <strong> tags. 3$-4$: opening and closing link tags for learn more. Leads to duplicate site article on docs. 5$-6$: Opening and closing link to production URL. 7$: Production URL .
						esc_html__( 'It looks like this site has moved or is a duplicate site. %1$sWooCommerce Subscriptions%2$s has disabled automatic payments and subscription related emails on this site to prevent duplicate payments from a staging or test environment. %1$sWooCommerce Subscriptions%2$s considers %5$s%7$s%6$s to be the site\'s URL. %3$sLearn more &raquo;%4$s.', 'woocommerce-subscriptions' ),
						'<strong>',
						'</strong>',
						'<a href="https://docs.woocommerce.com/document/subscriptions-handles-staging-sites/" target="_blank">',
						'</a>',
						'<a href="' . esc_url( self::get_site_url_from_source( 'subscriptions_install' ) ) . '" target="_blank">',
						'</a>',
						esc_url( self::get_site_url_from_source( 'subscriptions_install' ) )
					)
				);
				$notice->set_actions(
					array(
						array(
							'name'  => __( 'Quit nagging me (but don\'t enable automatic payments)', 'woocommerce-subscriptions' ),
							'url'   => wp_nonce_url( add_query_arg( 'wc_subscription_duplicate_site', 'ignore' ), 'wcs_duplicate_site', '_wcsnonce' ),
							'class' => 'button button-primary',
						),
						array(
							'name'  => __( 'Enable automatic payments', 'woocommerce-subscriptions' ),
							'url'   => wp_nonce_url( add_query_arg( 'wc_subscription_duplicate_site', 'update' ), 'wcs_duplicate_site', '_wcsnonce' ),
							'class' => 'button',
						),
					)
				);

				$notice->display();
			}
		}
	}

	/**
	 * Generates a unique key based on the sites URL used to determine duplicate/staging sites.
	 *
	 * The key can not simply be the site URL, e.g. http://example.com, because some hosts (WP Engine) replaces all
	 * instances of the site URL in the database when creating a staging site. As a result, we obfuscate
	 * the URL by inserting '_[wc_subscriptions_siteurl]_' into the middle of it.
	 *
	 * We don't use a hash because keeping the URL in the value allows for viewing and editing the URL
	 * directly in the database.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @return string The duplicate lock key.
	 */
	public static function get_duplicate_site_lock_key() {
		$site_url = self::get_site_url_from_source( 'current_wp_site' );
		$scheme   = parse_url( $site_url, PHP_URL_SCHEME ) . '://';
		$site_url = str_replace( $scheme, '', $site_url );

		return $scheme . substr_replace(
			$site_url,
			'_[wc_subscriptions_siteurl]_',
			intval( strlen( $site_url ) / 2 ),
			0
		);
	}

	/**
	 * Sets the duplicate site lock key to record the site's "live" url.
	 *
	 * This key is checked to determine if this database has moved to a different URL.
	 *
	 * @see self::get_duplicate_site_lock_key() which generates the key.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function set_duplicate_site_url_lock() {
		update_option( 'wc_subscriptions_siteurl', self::get_duplicate_site_lock_key() );
	}

	/**
	 * Determines if this is a duplicate/staging site.
	 *
	 * Checks if the WordPress site URL is the same as the URL subscriptions considers
	 * the live URL (@see self::set_duplicate_site_url_lock()).
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @return bool Whether the site is a duplicate URL or not.
	 */
	public static function is_duplicate_site() {
		$wp_site_url_parts  = wp_parse_url( self::get_site_url_from_source( 'current_wp_site' ) );
		$wcs_site_url_parts = wp_parse_url( self::get_site_url_from_source( 'subscriptions_install' ) );

		if ( ! isset( $wp_site_url_parts['path'] ) && ! isset( $wcs_site_url_parts['path'] ) ) {
			$paths_match = true;
		} elseif ( isset( $wp_site_url_parts['path'] ) && isset( $wcs_site_url_parts['path'] ) && $wp_site_url_parts['path'] == $wcs_site_url_parts['path'] ) {
			$paths_match = true;
		} else {
			$paths_match = false;
		}

		if ( isset( $wp_site_url_parts['host'] ) && isset( $wcs_site_url_parts['host'] ) && $wp_site_url_parts['host'] == $wcs_site_url_parts['host'] ) {
			$hosts_match = true;
		} else {
			$hosts_match = false;
		}

		// Check the host and path, do not check the protocol/scheme to avoid issues with WP Engine and other occasions where the WP_SITEURL constant may be set, but being overridden (e.g. by FORCE_SSL_ADMIN)
		if ( $paths_match && $hosts_match ) {
			$is_duplicate = false;
		} else {
			$is_duplicate = true;
		}

		return apply_filters( 'woocommerce_subscriptions_is_duplicate_site', $is_duplicate );
	}

	/**
	 * Gets the URL Subscriptions considers as the live site URL.
	 *
	 * This URL is set by @see WCS_Staging::set_duplicate_site_url_lock(). This function removes the obfuscation to get a raw URL.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param int|null    $blog_id The blog to get the URL for. Optional. Default is null. Used for multisites only.
	 * @param string      $path    The URL path to append. Optional. Default is ''.
	 * @param string|null $scheme  The URL scheme passed to @see set_url_scheme(). Optional. Default is null which automatically returns the URL as https or http depending on @see is_ssl().
	 */
	public static function get_live_site_url( $blog_id = null, $path = '', $scheme = null ) {
		if ( empty( $blog_id ) || ! is_multisite() ) {
			$url = get_option( 'wc_subscriptions_siteurl' );
		} else {
			switch_to_blog( $blog_id );
			$url = get_option( 'wc_subscriptions_siteurl' );
			restore_current_blog();
		}

		// Remove the prefix used to prevent the site URL being updated on WP Engine
		$url = str_replace( '_[wc_subscriptions_siteurl]_', '', $url );

		$url = set_url_scheme( $url, $scheme );

		if ( ! empty( $path ) && is_string( $path ) && strpos( $path, '..' ) === false ) {
			$url .= '/' . ltrim( $path, '/' );
		}

		return apply_filters( 'wc_subscriptions_site_url', $url, $path, $scheme, $blog_id );
	}

	/**
	 * Gets the sites WordPress or Subscriptions URL.
	 *
	 * WordPress - This is typically the URL the current site is accessible via.
	 * Subscriptions is the URL Subscriptions considers to be the URL to process live payments on. It may differ to the WP URL if the site has moved.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param string $source The URL source to get. Optional. Takes values 'current_wp_site' or 'subscriptions_install'. Default is 'current_wp_site' - the URL WP considers to be the site's.
	 * @return string The URL.
	 */
	public static function get_site_url_from_source( $source = 'current_wp_site' ) {
		// Let the default source be WP
		if ( 'subscriptions_install' === $source ) {
			$site_url = self::get_live_site_url();
		} elseif ( ! is_multisite() && defined( 'WP_SITEURL' ) ) {
			$site_url = WP_SITEURL;
		} else {
			$site_url = get_site_url();
		}

		return $site_url;
	}
}
