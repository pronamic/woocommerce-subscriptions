<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Subscription Downloads Order.
 *
 * @package  WC_Subscription_Downloads_Order
 * @category Order
 * @author   WooThemes
 */
class WC_Subscription_Downloads_Order {

	/**
	 * Order actions.
	 */
	public function __construct() {
		add_action( 'woocommerce_subscription_status_changed', array( $this, 'download_permissions' ), 10, 4 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_list_downloads' ), 10, 3 );
		add_action( 'woocommerce_subscriptions_switched_item', array( $this, 'handle_download_switch' ), 10, 3 );
		add_filter( 'woocommerce_order_get_downloadable_items', array( $this, 'remove_subscription_download_duplicates' ), 1, 2 );
		add_filter( 'woocommerce_customer_available_downloads', array( $this, 'remove_customer_download_duplicates' ), 10, 2 );
	}

	/**
	 * Save the download permissions in the subscription depending on the status.
	 *
	 * @param  int $subscription_id Subscription ID.
	 * @param  string $old_status Old status.
	 * @param  string $new_status New status.
	 * @param  WC_Subscription $subscription Subscription object.
	 *
	 * @return void
	 */
	public function download_permissions( $subscription_id, $old_status, $new_status, $subscription ) {
		if ( ! in_array( $new_status, array( 'active', 'expired', 'cancelled' ) ) ) {
			return;
		}

		$product_item_ids = array_map( function( $item ) {
			return $item['product_id'];
		}, $subscription->get_items() );

		foreach ( $subscription->get_items() as $item ) {

			// Gets the downloadable products.
			$downloadable_products = WC_Subscription_Downloads::get_downloadable_products( $item['product_id'], $item['variation_id'] );

			if ( $downloadable_products ) {

				foreach ( $downloadable_products as $product_id ) {
					$_product = wc_get_product( $product_id );

					if ( ! $_product ) {
						continue;
					}

					// @phpstan-ignore property.notFound
					$product_status = version_compare( WC_VERSION, '3.0', '<' ) ? $_product->post->post_status : $_product->get_status();

					if ( 'expired' === $new_status || 'cancelled' === $new_status ) {
						WCS_Download_Handler::revoke_downloadable_file_permission( $product_id, $subscription_id, $subscription->get_user_id() );
					}
					// Adds the downloadable files to the subscription.
					else if ( $_product && $_product->exists() && $_product->is_downloadable() && 'publish' === $product_status ) {
						WCS_Download_Handler::revoke_downloadable_file_permission( $product_id, $subscription_id, $subscription->get_user_id() );
						$downloads = version_compare( WC_VERSION, '3.0', '<' ) ? $_product->get_files() : $_product->get_downloads();

						foreach ( array_keys( $downloads ) as $download_id ) {
							wc_downloadable_file_permission( $download_id, $product_id, $subscription );
						}

						if ( ! in_array( $_product->get_id(), $product_item_ids ) ) {
							// Skip wrong recalculation of totals by adding a 0 amount Subscriptions.
							$totals = array(
								'subtotal'     => wc_format_decimal( 0 ),
								'total'        => wc_format_decimal( 0 ),
								'subtotal_tax' => wc_format_decimal( 0 ),
								'tax'          => wc_format_decimal( 0 ),
							);

							$subscription->add_product( $_product, 1, array( 'totals' => $totals ) );
						}
					}
				}
			}
		}
	}

	/**
	 * Remove downloads duplicates on subscriptions.
	 *
	 * @since 1.1.29
	 *
	 * @param  array    $downloads List of downloads.
	 * @param  WC_Order $order     The order.
	 *
	 * @return array Array of downloads.
	 */
	public static function remove_subscription_download_duplicates( $downloads, $order ) {
		if ( is_a( $order, 'WC_Subscription' ) ) {
			$downloads = array_unique( $downloads, SORT_REGULAR );
		}
		return $downloads;
	}

	/**
	 * Remove customer download duplicates that were added to the same order.
	 *
	 * @since 1.1.30
	 *
	 * @param  array $downloads   List of downloads.
	 * @param  int   $customer_id The customer id.
	 *
	 * @return array Array of downloads.
	 */
	public static function remove_customer_download_duplicates( $downloads, $customer_id ) {
		// As downloads have an order_id, the following only removes download duplicates from the same order.
		$downloads = array_unique( $downloads, SORT_REGULAR );

		return $downloads;
	}

	/**
	 * List the downloads in order emails.
	 *
	 * @param  WC_Order $order         Order data
	 * @param  bool     $sent_to_admin Sent or not to admin.
	 * @param  bool     $plain_text    Plain or HTML email.
	 */
	public function email_list_downloads( $order, $sent_to_admin = false, $plain_text = false ) {
		// @phpstan-ignore property.notFound
		$order_status = version_compare( WC_VERSION, '3.0', '<' ) ? $order->status : $order->get_status();

		if ( $sent_to_admin && ! in_array( $order_status, array( 'processing', 'completed' ) ) ) {
			return;
		}

		$downloads = WC_Subscription_Downloads::get_order_downloads( $order );

		if ( $downloads && $plain_text ) {
			$html  = apply_filters( 'woocommerce_subscription_downloads_my_downloads_title', __( 'Available downloads', 'woocommerce-subscriptions' ) );
			$html .= PHP_EOL . PHP_EOL;

			foreach ( $downloads as $download ) {
				$html .= $download['name'] . ': ' . $download['download_url'] . PHP_EOL;
			}

			$html .= PHP_EOL;
			$html .= '****************************************************';
			$html .= PHP_EOL . PHP_EOL;

			echo esc_html( wp_strip_all_tags( $html ) );

		} elseif ( $downloads && ! $plain_text ) {
			$html = '<h2>' . esc_html( apply_filters( 'woocommerce_subscription_downloads_my_downloads_title', __( 'Available downloads', 'woocommerce-subscriptions' ) ) ) . '</h2>';

			$html .= '<table cellspacing="0" cellpadding="0" style="width: 100%; vertical-align: top;" border="0">';
				$html .= '<tr>';
					$html .= '<td valign="top">';
						$html .= '<ul class="digital-downloads">';
			foreach ( $downloads as $download ) {
				$html .= sprintf( '<li><a href="%1$s" title="%2$s" target="_blank">%2$s</a></li>', esc_url( $download['download_url'] ), esc_html( $download['name'] ) );
			}
						$html .= '</ul>';
					$html .= '</td>';
				$html .= '</tr>';
			$html .= '</table>';

			/**
			 * The following HTML output consists of both static content and variable elements.
			 * The variable elements, such as user-generated content or database values, are properly escaped to prevent security vulnerabilities.
			 *
			 * If this HTML is ever exposed externally via a filter or if more variable elements are added, additional security measures should be taken into account.
			 * Consider using the necessary escaping functions/methods to ensure the continued safety of the output.
			 *
			 * Note: The warning 'WordPress.Security.EscapeOutput.OutputNotEscaped' has been suppressed intentionally, but ensure that the code adheres to the required security standards.
			 */
			echo $html; // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	* Revoke download permissions granted on the old switch item.
	*
	* @param WC_Subscription $subscription
	* @param array $new_item
	* @param array $old_item
	*/
	public function handle_download_switch( $subscription, $new_item, $old_item ) {
		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		$subscription_id       = $subscription->get_id();
		$downloadable_products = WC_Subscription_Downloads::get_downloadable_products( $old_item['product_id'], $old_item['variation_id'] );
		$subscription_items    = $subscription->get_items();

		// Remove old item attached to the subscription.
		foreach ( $subscription_items as $item ) {
			if ( in_array( $item['product_id'], $downloadable_products ) || in_array( $item['variation_id'], $downloadable_products ) ) {
				$item = $subscription->get_item( $item );
				if ( $item ) {
					$item->delete();
				}
			}
		}

		// Further, remove all attached downloadable products to the subscription.
		foreach ( $downloadable_products as $product_id ) {
			WCS_Download_Handler::revoke_downloadable_file_permission( $product_id, $subscription_id, $subscription->get_user_id() );
		}

		// Re-trigger download permissions. It will automatically add permissions to the new items.
		$this->download_permissions( $subscription_id, '', 'active', $subscription );
	}
}
