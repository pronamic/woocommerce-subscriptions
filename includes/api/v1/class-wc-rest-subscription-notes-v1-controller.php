<?php
/**
 * REST API subscription notes controller
 *
 * Handles requests to the /subscription/<id>/notes endpoint.
 *
 * @author   Prospress
 * @since    2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * REST API Subscription Notes controller class.
 *
 * @package WooCommerce_Subscriptions/API
 */
class WC_REST_Subscription_Notes_V1_Controller extends WC_REST_Order_Notes_V1_Controller {

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

}
