<?php
/**
 * Store retry details in the WordPress posts table as a custom post type
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WCS_Retry_Store
 * @category	Class
 * @author		Prospress
 * @since		2.1
 */

class WCS_Retry_Post_Store extends WCS_Retry_Store {

	protected static $post_type = 'payment_retry';

	/**
	 * Setup the class, if required
	 *
	 * @return null
	 */
	public function init() {
		register_post_type( self::$post_type, array(
			'description'  => __( 'Payment retry posts store details about the automatic retry of failed renewal payments.', 'woocommerce-subscriptions' ),
			'public'       => false,
			'map_meta_cap' => true,
			'hierarchical' => false,
			'supports'     => array( 'title', 'editor','comments' ),
			'rewrite'      => false,
			'query_var'    => false,
			'can_export'   => true,
			'ep_mask'      => EP_NONE,
			'labels'       => array(
					'name'               => _x( 'Renewal Payment Retries', 'Post type name', 'woocommerce-subscriptions' ),
					'singular_name'      => __( 'Renewal Payment Retry', 'woocommerce-subscriptions' ),
					'menu_name'          => _x( 'Renewal Payment Retries', 'Admin menu name', 'woocommerce-subscriptions' ),
					'add_new'            => __( 'Add', 'woocommerce-subscriptions' ),
					'add_new_item'       => __( 'Add New Retry', 'woocommerce-subscriptions' ),
					'edit'               => __( 'Edit', 'woocommerce-subscriptions' ),
					'edit_item'          => __( 'Edit Retry', 'woocommerce-subscriptions' ),
					'new_item'           => __( 'New Retry', 'woocommerce-subscriptions' ),
					'view'               => __( 'View Retry', 'woocommerce-subscriptions' ),
					'view_item'          => __( 'View Retry', 'woocommerce-subscriptions' ),
					'search_items'       => __( 'Search Renewal Payment Retries', 'woocommerce-subscriptions' ),
					'not_found'          => __( 'No retries found', 'woocommerce-subscriptions' ),
					'not_found_in_trash' => __( 'No retries found in trash', 'woocommerce-subscriptions' ),
				),
			)
	 	);
	}

	/**
	 * Save the details of a retry to the database
	 *
	 * @param WCS_Retry $retry
	 * @return int the retry's ID
	 */
	public function save( WCS_Retry $retry ) {

		$post_id = wp_insert_post( array(
			'ID'            => $retry->get_id(),
			'post_type'     => self::$post_type,
			'post_status'   => $retry->get_status(),
			'post_parent'   => $retry->get_order_id(),
			'post_date'     => $retry->get_date(),
			'post_date_gmt' => $retry->get_date_gmt(),
		) );

		// keep a record of the rule in post meta
		foreach ( $retry->get_rule()->get_raw_data() as $rule_key => $rule_value ) {
			update_post_meta( $post_id, '_rule_' . $rule_key, $rule_value );
		}

		return $post_id;
	}

	/**
	 * Get the details of a retry from the database
	 *
	 * @param int $retry_id
	 * @return WCS_Retry
	 */
	public function get_retry( $retry_id ) {

		$retry_post = get_post( $retry_id );

		if ( null !== $retry_post ) {

			$rule_data = array();
			$post_meta = get_post_meta( $retry_id );

			foreach ( $post_meta as $meta_key => $meta_value ) {
				if ( 0 === strpos( $meta_key, '_rule_' ) ) {
					$rule_data[ substr( $meta_key, 6 ) ] = $meta_value[0];
				}
			}

			$retry = new WCS_Retry( array(
				'id'       => $retry_post->ID,
				'status'   => $retry_post->post_status,
				'order_id' => $retry_post->post_parent,
				'date_gmt' => $retry_post->post_date_gmt,
				'rule_raw' => $rule_data,
			) );
		} else {
			$retry = null;
		}

		return $retry;
	}

	/**
	 *
	 */
	public function get_retries( $args ) {

		$args = wp_parse_args( $args, array(
			'status'     => 'any',
			'date_query' => array(),
		) );

		$retry_post_ids = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => self::$post_type,
			'post_status'    => $args['status'],
			'date_query'     => $args['date_query'],
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$retries = array();

		foreach ( $retry_post_ids as $retry_post_id ) {
			$retries[ $retry_post_id ] = $this->get_retry( $retry_post_id );
		}

		return $retries;
	}

	/**
	 * Get the IDs of all retries from the database for a given order
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function get_retry_ids_for_order( $order_id ) {

		$retry_post_ids = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => self::$post_type,
			'post_status'    => 'any',
			'post_parent'    => $order_id,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		return $retry_post_ids;
	}
}
