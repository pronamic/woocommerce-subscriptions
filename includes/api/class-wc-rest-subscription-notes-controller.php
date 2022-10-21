<?php
/**
 * REST API Subscription notes controller.
 *
 * Handles requests to the /subscriptions/<id>/notes endpoint.
 *
 * @package WooCommerce Subscriptions\Rest Api
 * @since   3.1.0
 */

defined( 'ABSPATH' ) || exit;

class WC_REST_Subscription_notes_Controller extends WC_REST_Order_Notes_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'subscriptions/(?P<order_id>[\d]+)/notes';

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type = 'shop_subscription';

	/**
	 * Prepare links for the request.
	 *
	 * @since 3.1.0
	 *
	 * @param WP_Comment $note
	 * @return array Links for the given order note.
	 */
	protected function prepare_links( $note ) {
		$links       = parent::prepare_links( $note );
		$links['up'] = array( 'href' => rest_url( sprintf( '/%s/subscriptions/%d', $this->namespace, (int) $note->comment_post_ID ) ) );

		return $links;
	}
}
