<?php
/**
 * WooCommerce Subscriptions API Customers Class
 *
 * Handles requests to the /customers/subscriptions endpoint
 *
 * @author      Prospress
 * @category    API
 * @since       2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class: WC_Subscription_API_Customers
 * extends @see WC_API_Customer to provide functionality to subscriptions
 *
 * @since 2.0
 */
class WC_API_Subscriptions_Customers extends WC_API_Customers {

	public function __construct( WC_API_Server $server ) {
		parent::__construct( $server );

		// remove the add customer data because WC_API_Customers already did that
		remove_filter( 'woocommerce_api_order_response', array( $this, 'add_customer_data' ), 10 );

		// remove the modify user query because WC_API_Customers already did that
		remove_action( 'pre_user_query', array( $this, 'modify_user_query' ) );
	}


	/**
	 * Register the routes for this class
	 *
	 * GET /customers/<id>/subscriptions
	 *
	 * @since 2.0
	 * @param array $routes
	 * @return array
	 */
	public function register_routes( $routes ) {
		$routes = parent::register_routes( $routes );

		# GET /customers/<id>/subscriptions
		$routes[ $this->base . '/(?P<id>\d+)/subscriptions' ] = array(
			array( array( $this, 'get_customer_subscriptions' ), WC_API_SERVER::READABLE ),
		);

		return $routes;
	}

	/**
	 * WCS API function to get all the subscriptions tied to a particular customer.
	 *
	 * @since 2.0
	 * @param $id int
	 * @param $fields array
	 */
	public function get_customer_subscriptions( $id, $fields = null ) {
		global $wpdb;

		// check the customer id given is a valid customer in the store. We're able to leech off WC-API for this.
		$id = $this->validate_request( $id, 'customer', 'read' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$subscription_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID, post_date_gmt
						FROM {$wpdb->posts} AS posts
						LEFT JOIN {$wpdb->postmeta} AS meta on posts.ID = meta.post_id
						WHERE meta.meta_key = '_customer_user'
						AND   meta.meta_value = '%d'
						AND   posts.post_type = 'shop_subscription'
						AND   posts.post_status IN ( '" . implode( "','", array_keys( wcs_get_subscription_statuses() ) ) . "' )
						GROUP BY posts.ID
						ORDER BY posts.post_date_gmt DESC
					", $id ) );

		$subscriptions = array();

		foreach ( $subscription_ids as $subscription_id ) {
			$subscriptions[] = WC()->api->WC_API_Subscriptions->get_subscription( $subscription_id, $fields );
		}

		return array( 'customer_subscriptions' => apply_filters( 'wc_subscriptions_api_customer_subscriptions', $subscriptions, $id, $fields, $subscription_ids, $this->server ) );
	}
}
