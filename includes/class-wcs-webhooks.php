<?php
/**
 * WooCommerce Subscriptions Webhook class
 *
 * This class introduces webhooks to, storing and retrieving webhook data from the associated
 * `shop_webhook` custom post type, as well as delivery logs from the `webhook_delivery`
 * comment type.
 *
 * Subscription Webhooks are enqueued to their associated actions, delivered, and logged.
 *
 * @author      Prospress
 * @category    Webhooks
 * @since       2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Webhooks {

	/**
	 * Setup webhook for subscriptions
	 *
	 * @since 2.0
	 */
	public static function init() {

		add_filter( 'woocommerce_webhook_topic_hooks', __CLASS__ . '::add_topics', 20, 2 );

		add_filter( 'woocommerce_webhook_payload', __CLASS__ . '::create_payload', 10, 4 );

		add_filter( 'woocommerce_valid_webhook_resources', __CLASS__ . '::add_resource', 10, 1 );

		add_filter( 'woocommerce_valid_webhook_events', __CLASS__ . '::add_event', 10, 1 );

		add_action( 'woocommerce_checkout_subscription_created', __CLASS__ . '::add_subscription_created_callback', 10, 1 );

		add_action( 'woocommerce_subscription_date_updated', __CLASS__ . '::add_subscription_updated_callback', 10, 1 );

		add_action( 'woocommerce_subscriptions_switch_completed', __CLASS__ . '::add_subscription_switched_callback', 10, 1 );

		add_filter( 'woocommerce_webhook_topics', __CLASS__ . '::add_topics_admin_menu', 10, 1 );

		add_filter( 'wcs_new_order_created', __CLASS__ . '::add_subscription_created_order_callback', 10, 1 );

	}

	/**
	 * Trigger `order.create` every time an order is created by Subscriptions.
	 *
	 * @param WC_Order $order WC_Order Object
	 */
	public static function add_subscription_created_order_callback( $order ) {

		do_action( 'wcs_webhook_order_created', wcs_get_objects_property( $order, 'id' ) );

		return $order;
	}

	/**
	 * Add Subscription webhook topics
	 *
	 * @param array $topic_hooks
	 * @since 2.0
	 */
	public static function add_topics( $topic_hooks, $webhook ) {

		switch ( $webhook->get_resource() ) {
			case 'order':
				$topic_hooks['order.created'][] = 'wcs_webhook_order_created';
				break;

			case 'subscription':
				$topic_hooks = apply_filters( 'woocommerce_subscriptions_webhook_topics', array(
					'subscription.created'  => array(
						'wcs_api_subscription_created',
						'wcs_webhook_subscription_created',
						'woocommerce_process_shop_subscription_meta',
					),
					'subscription.updated'  => array(
						'wcs_webhook_subscription_updated',
						'woocommerce_update_subscription',
					),
					'subscription.deleted'  => array(
						'woocommerce_subscription_trashed',
						'woocommerce_subscription_deleted',
						'woocommerce_api_delete_subscription',
					),
					'subscription.switched' => array(
						'wcs_webhook_subscription_switched',
					),
				), $webhook );
				break;
		}

		return $topic_hooks;
	}

	/**
	 * Add Subscription topics to the Webhooks dropdown menu in when creating a new webhook.
	 *
	 * @since 2.0
	 */
	public static function add_topics_admin_menu( $topics ) {

		$front_end_topics = array(
			'subscription.created'  => __( ' Subscription created', 'woocommerce-subscriptions' ),
			'subscription.updated'  => __( ' Subscription updated', 'woocommerce-subscriptions' ),
			'subscription.deleted'  => __( ' Subscription deleted', 'woocommerce-subscriptions' ),
			'subscription.switched' => __( ' Subscription switched', 'woocommerce-subscriptions' ),
		);

		return array_merge( $topics, $front_end_topics );
	}

	/**
	 * Setup payload for subscription webhook delivery.
	 *
	 * @since 2.0
	 */
	public static function create_payload( $payload, $resource, $resource_id, $id ) {

		if ( 'subscription' == $resource && empty( $payload ) && wcs_is_subscription( $resource_id ) ) {
			$webhook      = new WC_Webhook( $id );
			$current_user = get_current_user_id();

			// Build the payload with the same user context as the user who created
			// the webhook -- this avoids permission errors as background processing
			// runs with no user context.
			wp_set_current_user( $webhook->get_user_id() ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Discouraged -- user ID can only be provided by WC_Webhook::get_user_id() which is the webhook author's ID.

			switch ( $webhook->get_api_version() ) {
				case 'legacy_v3':

					// @phpstan-ignore-next-line Ignore legacy referencies.
					if ( is_null( wc()->api ) ) {
						throw new \Exception( 'The Legacy REST API plugin is not installed on this site. More information: https://developer.woocommerce.com/2023/10/03/the-legacy-rest-api-will-move-to-a-dedicated-extension-in-woocommerce-9-0/ ' );
					}

					// @phpstan-ignore-next-line
					WC()->api->WC_API_Subscriptions->register_routes( array() );
					// @phpstan-ignore-next-line
					$payload = WC()->api->WC_API_Subscriptions->get_subscription( $resource_id );
					break;
				case 'wp_api_v1':
				case 'wp_api_v2':
					// There is no v2 subscritpion endpoint support so they fall back to v1.
					$request    = new WP_REST_Request( 'GET' );
					// @phpstan-ignore class.nameCase
					$controller = new WC_REST_Subscriptions_v1_Controller();

					$request->set_param( 'id', $resource_id );
					$result  = $controller->get_item( $request );
					$payload = isset( $result->data ) ? $result->data : array();

					break;
				case 'wp_api_v3':
					$payload = WCS_API::get_wc_api_endpoint_data( "/wc/v3/subscriptions/{$resource_id}" );
					break;
			}

			// Restore the current user.
			wp_set_current_user( $current_user ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Discouraged -- this ID was provided by get_current_user_id() above.
		}

		return $payload;
	}

	/**
	 * Add webhook resource for subscription.
	 *
	 * @param array $resources
	 * @since 2.0
	 */
	public static function add_resource( $resources ) {

		$resources[] = 'subscription';

		return $resources;
	}

	/**
	 * Add webhook event for subscription switched.
	 *
	 * @param array $events
	 * @since 2.1
	 */
	public static function add_event( $events ) {

		$events[] = 'switched';

		return $events;
	}

	/**
	 * Call a "subscription created" action hook with the first parameter being a subscription id so that it can be used
	 * for webhooks.
	 *
	 * @since 2.0
	 */
	public static function add_subscription_created_callback( $subscription ) {
		do_action( 'wcs_webhook_subscription_created', $subscription->get_id() );
	}

	/**
	 * Call a "subscription updated" action hook with a subscription id as the first parameter to be used for webhooks payloads.
	 *
	 * @since 2.0
	 */
	public static function add_subscription_updated_callback( $subscription ) {
		do_action( 'wcs_webhook_subscription_updated', $subscription->get_id() );
	}

	/**
	 * For each switched subscription in an order, call a "subscription switched" action hook with a subscription id as the first parameter to be used for webhooks payloads.
	 *
	 * @since 2.1
	 */
	public static function add_subscription_switched_callback( $order ) {
		$switched_subscriptions = wcs_get_subscriptions_for_switch_order( $order );
		foreach ( array_keys( $switched_subscriptions ) as $subscription_id ) {
			do_action( 'wcs_webhook_subscription_switched', $subscription_id );
		}
	}

}
