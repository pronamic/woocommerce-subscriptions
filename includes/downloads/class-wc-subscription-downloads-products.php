<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Subscription Downloads Products.
 *
 * @package  WC_Subscription_Downloads_Products
 * @category Products
 * @author   WooThemes
 */
class WC_Subscription_Downloads_Products {

	/**
	 * Products actions.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_options_downloads', array( $this, 'simple_write_panel_options' ), 10 );
		add_action( 'woocommerce_variation_options_download', array( $this, 'variable_write_panel_options' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'woocommerce_process_product_meta_simple', array( $this, 'save_simple_product_data' ), 10 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_product_data' ), 10, 2 );
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			add_action( 'woocommerce_duplicate_product', array( $this, 'save_subscriptions_when_duplicating_product' ), 10, 2 );
		} else {
			add_action( 'woocommerce_product_duplicate', array( $this, 'save_subscriptions_when_duplicating_product' ), 10, 2 );
		}
	}

	/**
	 * Product screen scripts.
	 *
	 * @return void
	 */
	public function scripts() {
		$screen = get_current_screen();

		if ( 'product' == $screen->id && version_compare( WC_VERSION, '2.3.0', '<' ) ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_enqueue_script( 'wc_subscription_downloads_writepanel', plugins_url( 'assets/js/admin/writepanel' . $suffix . '.js', plugin_dir_path( __FILE__ ) ), array( 'ajax-chosen', 'chosen' ), WC_Subscriptions::$version, true );

			wp_localize_script(
				'wc_subscription_downloads_writepanel',
				'wc_subscription_downloads_product',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'security' => wp_create_nonce( 'search-products' ),
				)
			);
		}
	}

	/**
	 * Simple product write panel options.
	 */
	public function simple_write_panel_options() {
		global $post;
		?>
			<p class="form-field _subscription_downloads_field">
				<label for="subscription-downloads-ids"><?php esc_html_e( 'Included subscription products', 'woocommerce-subscriptions' ); ?></label>

				<select id="subscription-downloads-ids" multiple="multiple" data-action="wc_subscription_downloads_search" data-placeholder="<?php esc_attr_e( 'Select subscriptions', 'woocommerce-subscriptions' ); ?>" class="subscription-downloads-ids wc-product-search" name="_subscription_downloads_ids[]" style="width: 50%;">
					<?php
					$subscriptions_ids = WC_Subscription_Downloads::get_subscriptions( $post->ID );

					if ( $subscriptions_ids ) {
						foreach ( $subscriptions_ids as $subscription_id ) {
							$_subscription = wc_get_product( $subscription_id );

							if ( $_subscription ) {
								echo '<option value="' . esc_attr( $subscription_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $_subscription->get_formatted_name() ) ) . '</option>';
							}
						}
					}
					?>
				</select>

				<?php echo wc_help_tip( wc_sanitize_tooltip( __( 'Select subscription products that will include this downloadable product.', 'woocommerce-subscriptions' ) ) ); ?>
			</p>

		<?php
	}

	/**
	 * Variable product write panel options.
	 */
	public function variable_write_panel_options( $loop, $variation_data, $variation ) {
		?>
			<tr class="show_if_variation_downloadable">
				<td colspan="2">
					<p class="form-field form-row form-row-full">
						<label><?php esc_html_e( 'Included subscription products', 'woocommerce-subscriptions' ); ?>:</label>
						<?php echo wc_help_tip( wc_sanitize_tooltip( __( 'Select subscription products that will include this downloadable product.', 'woocommerce-subscriptions' ) ) ); ?>

						<select multiple="multiple" data-placeholder="<?php esc_html_e( 'Select subscriptions', 'woocommerce-subscriptions' ); ?>" class="subscription-downloads-ids wc-product-search" name="_variable_subscription_downloads_ids[<?php echo esc_attr( $loop ); ?>][]" style="width: 100%">
							<?php
							$subscriptions_ids = WC_Subscription_Downloads::get_subscriptions( $variation->ID );
							if ( $subscriptions_ids ) {
								foreach ( $subscriptions_ids as $subscription_id ) {
									$_subscription = wc_get_product( $subscription_id );

									if ( $_subscription ) {
										echo '<option value="' . esc_attr( $subscription_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $_subscription->get_formatted_name() ) ) . '</option>';
									}
								}
							}
							?>
						</select>
					</p>
				</td>
			</tr>
		<?php
	}

	/**
	 * Search orders from subscription product ID.
	 *
	 * @param  int   $subscription_product_id
	 *
	 * @return array
	 */
	protected function get_orders( $subscription_product_id ) {
		global $wpdb;

		$orders   = array();
		$meta_key = '_product_id';

		// Check if subscription product has parent (i.e. is a variable subscription product).
		$parent_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_parent AS parent_id
				FROM {$wpdb->prefix}posts
				WHERE ID = %d;
				",
				$subscription_product_id
			)
		);

		// If the subscription product is a variation, use variation meta key to find related orders.
		if ( ! empty( $parent_id ) ) {
			$meta_key = '_variation_id';
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_items.order_id AS id
				FROM {$wpdb->prefix}woocommerce_order_items as order_items
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta ON order_items.order_item_id = itemmeta.order_item_id
				WHERE itemmeta.meta_key = %s
				AND itemmeta.meta_value = %d;
				",
				$meta_key,
				$subscription_product_id
			)
		);

		foreach ( $results as $order ) {
			$orders[] = $order->id;
		}

		$orders = apply_filters( 'woocommerce_subscription_downloads_get_orders', $orders, $subscription_product_id );

		return $orders;
	}

	/**
	 * Revoke access to download.
	 *
	 * @param  bool $download_id
	 * @param  bool $product_id
	 * @param  bool $order_id
	 *
	 * @return void
	 */
	protected function revoke_access_to_download( $download_id, $product_id, $order_id ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "
			DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions
			WHERE order_id = %d AND product_id = %d AND download_id = %s;
		", $order_id, $product_id, $download_id  ) );

		do_action( 'woocommerce_ajax_revoke_access_to_product_download', $download_id, $product_id, $order_id );
	}

	/**
	 * Update subscription downloads table and orders.
	 *
	 * @param  int $product_id
	 * @param  array $subscriptions
	 *
	 * @return void
	 */
	protected function update_subscription_downloads( $product_id, $subscriptions ) {
		global $wpdb;

		$subscriptions = (array) $subscriptions;
		$current       = WC_Subscription_Downloads::get_subscriptions( $product_id );

		// Delete items.
		$delete_ids = array_diff( $current, $subscriptions );
		if ( $delete_ids ) {
			foreach ( $delete_ids as $delete ) {
				$wpdb->delete(
					$wpdb->prefix . 'woocommerce_subscription_downloads',
					array(
						'product_id'      => $product_id,
						'subscription_id' => $delete,
					),
					array(
						'%d',
						'%d',
					)
				);

				$_orders = $this->get_orders( $delete );
				foreach ( $_orders as $order_id ) {
					$_product  = wc_get_product( $product_id );
					$downloads = $_product->get_downloads();

					// Adds the downloadable files to the order/subscription.
					foreach ( array_keys( $downloads ) as $download_id ) {
						$this->revoke_access_to_download( $download_id, $product_id, $order_id );
					}
				}
			}
		}

		// Add items.
		$add_ids = array_diff( $subscriptions, $current );
		if ( $add_ids ) {
			foreach ( $add_ids as $add ) {
				$wpdb->insert(
					$wpdb->prefix . 'woocommerce_subscription_downloads',
					array(
						'product_id'      => $product_id,
						'subscription_id' => $add,
					),
					array(
						'%d',
						'%d',
					)
				);

				$_orders = $this->get_orders( $add );
				foreach ( $_orders as $order_id ) {
					$order     = wc_get_order( $order_id );

					if ( ! is_a( $order, 'WC_Subscription' ) ) {
						// avoid adding permissions to orders and it's
						// subscription for the same user, causing duplicates
						// to show up
						continue;
					}

					$_product  = wc_get_product( $product_id );
					$downloads = $_product->get_downloads();

					// Adds the downloadable files to the order/subscription.
					foreach ( array_keys( $downloads ) as $download_id ) {
						wc_downloadable_file_permission( $download_id, $product_id, $order );
					}
				}
			}
		}
	}

	/**
	 * Save simple product data.
	 *
	 * @param  int $product_id
	 *
	 * @return void
	 */
	public function save_simple_product_data( $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$subscription_downloads_ids = ! empty( $_POST['_subscription_downloads_ids'] ) ? wc_clean( wp_unslash( $_POST['_subscription_downloads_ids'] ) ) : '';

		if ( empty( $subscription_downloads_ids ) ) {
			$subscription_downloads_ids = array();
		}

		$this->update_subscription_downloads( $product_id, $subscription_downloads_ids );
	}

	/**
	 * Save variable product data.
	 *
	 * @param  int $variation_id
	 * @param  int $index
	 *
	 * @return void
	 */
	public function save_variation_product_data( $variation_id, $index ) {
		if ( ! isset( $_POST['variable_is_downloadable'][ $index ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$subscription_download_ids = isset( $_POST['_variable_subscription_downloads_ids'][ $index ] ) ? wc_clean( wp_unslash( $_POST['_variable_subscription_downloads_ids'][ $index ] ) ) : array();
		$subscription_download_ids = array_filter( $subscription_download_ids ); // nosemgrep: audit.php.lang.misc.array-filter-no-callback -- $subscription_download_ids are already passed through wc_clean() and wp_unslash().

		$this->update_subscription_downloads( $variation_id, $subscription_download_ids );
	}

	/**
	 * Save subscriptions information when duplicating a product.
	 *
	 * @param int|WC_Product     $id_or_product Duplicated product ID
	 * @param WP_Post|WC_Product $post   Product being duplicated
	 */
	public function save_subscriptions_when_duplicating_product( $id_or_product, $post ) {
		$post_id = is_a( $post, 'WC_Product' ) ? $post->get_parent_id() : $post->ID;
		$new_id  = is_a( $id_or_product, 'WC_Product' ) ? $id_or_product->get_id() : $id_or_product;

		$subscriptions = WC_Subscription_Downloads::get_subscriptions( $post_id );
		if ( ! empty( $subscriptions ) ) {
			$this->update_subscription_downloads( $new_id, $subscriptions );
		}

		$children_products = get_children( 'post_parent=' . $post_id . '&post_type=product_variation' );
		if ( empty( $children_products ) ) {
			return;
		}

		// Create assoc array where keys are flatten variation attributes and values
		// are original product variations.
		$children_ids_by_variation_attributes = array();
		foreach ( $children_products as $child ) {
			$str_attributes = $this->get_str_variation_attributes( $child );
			if ( ! empty( $str_attributes ) ) {
				$children_ids_by_variation_attributes[ $str_attributes ] = $child;
			}
		}

		// Copy variations' subscriptions.
		$exclude               = apply_filters( 'woocommerce_duplicate_product_exclude_children', false );
		$new_children_products = get_children( 'post_parent=' . $new_id . '&post_type=product_variation' );
		if ( ! $exclude && ! empty( $new_children_products ) ) {
			foreach ( $new_children_products as $child ) {
				$str_attributes = $this->get_str_variation_attributes( $child );
				if ( ! empty( $children_ids_by_variation_attributes[ $str_attributes ] ) ) {
					$this->save_subscriptions_when_duplicating_product(
						$child->ID,
						$children_ids_by_variation_attributes[ $str_attributes ]
					);
				}
			}
		}
	}

	/**
	 * Get string representation of variation attributes from a given product variation.
	 *
	 * @param mixed $product_variation Product variation
	 *
	 * @return string Variation attributes
	 */
	protected function get_str_variation_attributes( $product_variation ) {
		$product_variation = wc_get_product( $product_variation );
		if ( ! is_callable( array( $product_variation, 'get_formatted_variation_attributes' ) ) ) {
			return false;
		}

		return (string) wc_get_formatted_variation( $product_variation, true );
	}
}
