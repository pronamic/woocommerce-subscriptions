<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Subscription Downloads Ajax.
 *
 * @package  WC_Subscription_Downloads_Ajax
 * @category Ajax
 * @author   WooThemes
 */
class WC_Subscription_Downloads_Ajax {

	/**
	 * Ajax actions.
	 */
	public function __construct() {
		add_action( 'wp_ajax_wc_subscription_downloads_search', array( $this, 'search_subscriptions' ) );
	}

	/**
	 * Search subscription products.
	 */
	public function search_subscriptions() {
		ob_start();

		global $wpdb;

		check_ajax_referer( 'search-products', 'security' );

		$term = wc_clean( stripslashes( $_GET['term'] ) );

		if ( empty( $term ) ) {
			die();
		}

		$found_subscriptions = array();

		$term = apply_filters( 'woocommerce_subscription_downloads_json_search_order_number', $term );

		// Find subscription products by title.
		$query_subscriptions = $wpdb->get_results( $wpdb->prepare( "
			SELECT ID
			FROM $wpdb->posts AS posts
				LEFT JOIN $wpdb->term_relationships AS t_relationships ON(posts.ID = t_relationships.object_id)
				LEFT JOIN $wpdb->term_taxonomy AS t_taxonomy ON(t_relationships.term_taxonomy_id = t_taxonomy.term_taxonomy_id)
				LEFT JOIN $wpdb->terms AS terms ON(t_taxonomy.term_id = terms.term_id)
			WHERE posts.post_type = 'product'
			AND posts.post_status = 'publish'
			AND posts.post_title LIKE %s
			AND t_taxonomy.taxonomy = 'product_type'
			AND (terms.slug = 'subscription' OR terms.slug = 'variable-subscription')
			ORDER BY posts.post_date DESC
		", '%' . $term . '%' ) );

		if ( $query_subscriptions ) {
			foreach ( $query_subscriptions as $item ) {
				$_product = wc_get_product( $item->ID );
				$found_subscriptions[ $item->ID ] = sanitize_text_field( $_product->get_formatted_name() );

				if ( 'variable-subscription' == $_product->get_type() ) {
					$chindren = get_children( array( 'post_parent' => $_product->get_id(), 'post_type' => 'product_variation' ) );

					foreach ( $chindren as $child ) {
						$_child_product = wc_get_product( $child );
						$found_subscriptions[ $child->ID ] = sanitize_text_field( $_child_product->get_formatted_name() );
					}
				}
			}
		}

		wp_send_json( $found_subscriptions );
	}
}
