<?php
/**
 * Privacy/GDPR related functionality which ties into WordPress functionality.
 *
 * @package  WooCommerce Subscriptions Gifting\Privacy
 * @version  2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Hooks into WooCommerce's privacy-related functionality.
 */
class WCSG_Privacy extends WC_Abstract_Privacy {

	/**
	 * WCSG_Privacy constructor.
	 */
	public function __construct() {
		parent::__construct( __( 'WooCommerce Subscriptions Gifting', 'woocommerce-subscriptions' ) );

		// include our exporters and erasers.
		include_once 'class-wcsg-privacy-exporters.php';
		include_once 'class-wcsg-privacy-erasers.php';

		$this->add_exporter( 'woocommerce-gifted-subscriptions-data', __( 'Recipient Subscription Data', 'woocommerce-subscriptions' ), array( 'WCSG_Privacy_Exporters', 'subscription_data_exporter' ) );
		$this->add_exporter( 'woocommerce-gifted-order-data', __( 'Recipient Order Data', 'woocommerce-subscriptions' ), array( 'WCSG_Privacy_Exporters', 'order_data_exporter' ) );

		$this->add_eraser( 'woocommerce-gifted-subscriptions-data', __( 'Recipient Subscription Data', 'woocommerce-subscriptions' ), array( 'WCSG_Privacy_Erasers', 'subscription_data_eraser' ) );
		$this->add_eraser( 'woocommerce-gifted-order-data', __( 'Recipient Order Data', 'woocommerce-subscriptions' ), array( 'WCSG_Privacy_Erasers', 'order_data_eraser' ) );
	}

	/**
	 * Attach callbacks.
	 */
	protected function init() {
		parent::init();

		// When an order or subscription is anonymised, remove recipient line item meta.
		add_action( 'woocommerce_privacy_before_remove_order_personal_data', array( 'WCSG_Privacy_Erasers', 'remove_personal_recipient_line_item_data' ) );
		add_action( 'woocommerce_privacy_before_remove_subscription_personal_data', array( 'WCSG_Privacy_Erasers', 'remove_personal_recipient_line_item_data' ) );

		// Remove recipient meta from a subscription when it is anonymised.
		add_action( 'woocommerce_privacy_before_remove_subscription_personal_data', array( 'WCSG_Privacy_Erasers', 'remove_recipient_meta' ) );
	}
}
