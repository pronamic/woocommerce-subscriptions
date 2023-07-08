<?php
/**
 * Updates the 'post_author' column for subscriptions on WC 3.5+.
 *
 * @author     Prospress
 * @category   Admin
 * @package    WooCommerce Subscriptions/Admin/Upgrades
 * @version    1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_Subscription_Post_Author extends WCS_Background_Upgrader {

	/**
	 * Constructor
	 *
	 * @param WC_Logger $logger The WC_Logger instance.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 */
	public function __construct( WC_Logger $logger ) {
		wcs_deprecated_function( __METHOD__, '2.5.0' );

		$this->scheduled_hook = 'wcs_upgrade_subscription_post_author';
		$this->log_handle     = 'wcs-upgrade-subscription-post-author';
		$this->logger         = $logger;
	}

	/**
	 * Update a subscription, setting its post_author to its customer ID.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 */
	protected function update_item( $subscription_id ) {
		global $wpdb;

		try {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					SET p.post_author = pm.meta_value WHERE p.ID = %d AND pm.meta_key = '_customer_user'",
					$subscription_id
				)
			);

			if ( 0 === $wpdb->rows_affected ) {
				if ( '1' === get_post_meta( $subscription_id, '_customer_user', true ) && is_a( WCS_Customer_Store::instance(), 'WCS_Customer_Store_Cached_CPT' ) ) {
					// Admin's subscription cache seems to be corrupt, force a refresh.
					WCS_Customer_Store::instance()->delete_cache_for_user( 1 );
				}

				throw new Exception( 'post_author wasn\'t updated, it was already set to 1' );
			}

			$this->log( sprintf( 'Subscription ID %d post_author updated.', $subscription_id ) );
		} catch ( Exception $e ) {
			$this->log( sprintf( '--- Exception caught repairing subscription %d - exception message: %s ---', $subscription_id, $e->getMessage() ) );

			// Ignore this subscription the next time around.
			$this->add_subscription_to_ignore_list( $subscription_id );
		}
	}


	/**
	 * Get a batch of subscriptions which need to be updated.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 * @return array A list of subscription ids which need to be updated.
	 */
	protected function get_items_to_update() {
		return get_posts( array(
			'post_type'      => 'shop_subscription',
			'posts_per_page' => 20,
			'author'         => '1',
			'post_status'    => 'any',
			'post__not_in'   => $this->get_subscriptions_to_ignore(),
			'fields'         => 'ids',
		) );
	}

	/**
	 * Schedule the instance's hook to run in $this->time_limit seconds, if it's not already scheduled.
	 */
	protected function schedule_background_update() {
		parent::schedule_background_update();

		update_option( 'wcs_subscription_post_author_upgrade_is_scheduled', true );
	}

	/**
	 * Unschedule the instance's hook in Action Scheduler
	 */
	protected function unschedule_background_updates() {
		parent::unschedule_background_updates();

		delete_option( 'wcs_subscription_post_author_upgrade_is_scheduled' );
		delete_option( 'wcs_post_author_upgrade_other_subscriptions_to_ignore' );
	}

	/**
	 * Returns the list of admin subscription IDs to ignore during this upgrade routine.
	 *
	 * @return array
	 */
	private function get_subscriptions_to_ignore() {
		$subscriptions_to_ignore       = WCS_Customer_Store::instance()->get_users_subscription_ids( 1 );
		$other_subscriptions_to_ignore = get_option( 'wcs_post_author_upgrade_other_subscriptions_to_ignore', array() );

		if ( is_array( $other_subscriptions_to_ignore ) && ! empty( $other_subscriptions_to_ignore ) ) {
			$subscriptions_to_ignore = array_unique( array_merge( $subscriptions_to_ignore, $other_subscriptions_to_ignore ) );
		}

		return $subscriptions_to_ignore;
	}

	/**
	 * Adds a subscription ID to the ignore list for this upgrade routine.
	 *
	 * @param int $subscription_id
	 */
	private function add_subscription_to_ignore_list( $subscription_id ) {
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id ) {
			return;
		}

		$subscriptions_to_ignore = get_option( 'wcs_post_author_upgrade_other_subscriptions_to_ignore', array() );
		$subscriptions_to_ignore = is_array( $subscriptions_to_ignore ) ? $subscriptions_to_ignore : array();

		if ( ! in_array( $subscription_id, $subscriptions_to_ignore ) ) {
			$subscriptions_to_ignore[] = $subscription_id;
		}

		update_option( 'wcs_post_author_upgrade_other_subscriptions_to_ignore', $subscriptions_to_ignore );
	}

	/**
	 * Hooks into WC's 3.5 update routine to add the subscription post type to the list of post types affected by this update.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 */
	public static function hook_into_wc_350_update() {
		add_filter( 'woocommerce_update_350_order_customer_id_post_types', array( __CLASS__, 'add_post_type_to_wc_350_update' ) );
	}

	/**
	 * Callback for the `woocommerce_update_350_order_customer_id_post_types` hook. Makes sure `shop_subscription` is
	 * included in the post types array.
	 *
	 * @param  array $post_types
	 * @return array
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 */
	public static function add_post_type_to_wc_350_update( $post_types = array() ) {
		if ( ! in_array( 'shop_subscription', $post_types ) ) {
			$post_types[] = 'shop_subscription';
		}

		return $post_types;
	}

}

