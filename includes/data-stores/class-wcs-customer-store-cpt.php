<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer data store for subscriptions stored in Custom Post Types.
 *
 * Gets subscriptions for users via the '_customer_user' post meta value.
 *
 * @version  2.3.0
 * @category Class
 * @author   Prospress
 */
class WCS_Customer_Store_CPT extends WCS_Customer_Store {

	/**
	 * The meta key used to link a customer with a subscription.
	 *
	 * @var string
	 */
	private $meta_key = '_customer_user';

	/**
	 * Get the meta key used to link a customer with a subscription.
	 *
	 * @return string
	 */
	protected function get_meta_key() {
		return $this->meta_key;
	}

	/**
	 * Get the IDs for a given user's subscriptions by querying post meta.
	 *
	 * @param int $user_id The id of the user whose subscriptions you want.
	 * @return array
	 */
	public function get_users_subscription_ids( $user_id ) {

		if ( 0 === $user_id ) {
			return array();
		}

		$query = new WP_Query();

		return $query->query( array(
			'post_type'           => 'shop_subscription',
			'posts_per_page'      => -1,
			'post_status'         => 'any',
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'meta_query'          => array(
				array(
					'key'   => $this->get_meta_key(),
					'value' => $user_id,
				),
			),
		) );
	}
}
