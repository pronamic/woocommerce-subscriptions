<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Subscription Downloads Products.
 *
 * @package  WC_Subscription_Downloads_Products
 */
class WC_Subscription_Downloads_Products {
	public const EDITOR_UPDATE                    = 'wcsubs_subscription_download_relationships';
	public const RELATIONSHIP_DOWNLOAD_TO_SUB     = 'download-to-sub';
	public const RELATIONSHIP_VAR_DOWNLOAD_TO_SUB = 'var-download-to-sub';
	public const RELATIONSHIP_SUB_TO_DOWNLOAD     = 'sub-to-download';
	public const RELATIONSHIP_VAR_SUB_TO_DOWNLOAD = 'var-sub-to-download';

	/**
	 * Products actions.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_options_downloads', array( $this, 'simple_write_panel_options' ) );
		add_action( 'woocommerce_variation_options_download', array( $this, 'variable_write_panel_options' ), 10, 3 );
		add_action( 'woocommerce_product_options_pricing', array( $this, 'subscription_product_editor_ui' ) );
		add_action( 'woocommerce_variable_subscription_pricing', array( $this, 'variable_subscription_product_editor_ui' ), 10, 3 );

		add_action( 'save_post_product', array( $this, 'handle_product_save' ) );
		add_action( 'save_post_product_variation', array( $this, 'handle_product_variation_save' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'handle_product_variation_save' ) );
		add_action( 'woocommerce_update_product', array( $this, 'handle_product_save' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'handle_product_variation_save' ) );

		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		add_action( 'woocommerce_product_duplicate', array( $this, 'save_subscriptions_when_duplicating_product' ), 10, 2 );
	}

	/**
	 * Handle product save - generic handler for all product updates.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function handle_product_save( $post_id ) {
		// Bail if this is an autosave or revision.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		$product_is_downloadable = $product->is_downloadable();
		$product_is_subscription = $product->is_type( array( 'subscription', 'variable-subscription' ) );

		// We do not allow downloadable subscription products to be linked with other subscription products; this is
		// principally to avoid confusion (though it would be technically feasible).
		if ( $product_is_subscription && ! $product_is_downloadable ) {
			$this->handle_subscription_product_save( $post_id );
		} elseif ( ! $product_is_subscription && $product_is_downloadable ) {
			$this->handle_downloadable_product_save( $post_id );
		}
	}

	/**
	 * Handle product variation save - generic handler for all variation updates.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function handle_product_variation_save( $post_id ) {
		// Bail if this is an autosave or revision.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$variation = wc_get_product( $post_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return;
		}

		// Handle downloadable variations (they link TO subscriptions).
		if ( $variation->is_downloadable() ) {
			$this->handle_downloadable_product_save( $post_id );
		}

		// Handle subscription variations (they link TO downloadable products).
		$parent = wc_get_product( $variation->get_parent_id() );

		if ( $parent && $parent->is_type( 'variable-subscription' ) ) {
			$this->handle_subscription_product_save( $post_id );
		}
	}

	/**
	 * Handle save for downloadable products (simple or variation).
	 * These products link TO subscription products.
	 *
	 * @param int $product_id Product or variation ID.
	 *
	 * @return void
	 */
	private function handle_downloadable_product_save( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if (
			isset( $_POST[ self::RELATIONSHIP_VAR_DOWNLOAD_TO_SUB . $product_id ] )
			&& wp_verify_nonce( $_POST[ self::RELATIONSHIP_VAR_DOWNLOAD_TO_SUB . $product_id ], self::EDITOR_UPDATE )
		) {
			$subscription_ids = wc_clean( wp_unslash( $_POST['_variable_subscription_downloads_ids'][ $product_id ] ?? array() ) );
			$subscription_ids = array_filter( (array) $subscription_ids );
			$this->update_subscription_downloads( $product_id, $subscription_ids );
		}

		if (
			isset( $_POST[ self::RELATIONSHIP_DOWNLOAD_TO_SUB ] )
			&& wp_verify_nonce( $_POST[ self::RELATIONSHIP_DOWNLOAD_TO_SUB ], self::EDITOR_UPDATE )
		) {
			$subscription_ids = wc_clean( wp_unslash( $_POST['_subscription_downloads_ids'] ?? array() ) );
			$subscription_ids = array_filter( (array) $subscription_ids );
			$this->update_subscription_downloads( $product_id, $subscription_ids );
		}

		// Observe and act on product status changes (regardless of whether they were made from within the product
		// editor, therefore we don't care about nonce checks here).
		$this->assess_downloadable_product_status( $product_id );
		// phpcs:enable
	}

	/**
	 * Handle save for subscription products (simple subscription or variation).
	 * These products link TO downloadable products.
	 *
	 * @param int $product_id Subscription product or variation ID.
	 *
	 * @return void
	 */
	private function handle_subscription_product_save( $product_id ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if (
			isset( $_POST[ self::RELATIONSHIP_VAR_SUB_TO_DOWNLOAD . $product_id ] )
			&& wp_verify_nonce( $_POST[ self::RELATIONSHIP_VAR_SUB_TO_DOWNLOAD . $product_id ], self::EDITOR_UPDATE )
		) {
			$product_ids = wc_clean( wp_unslash( $_POST[ '_subscription_linked_downloadable_products_' . $product_id ] ?? array() ) );
			$product_ids = array_filter( (array) $product_ids );
			$this->update_subscription_products( $product_id, $product_ids );
			return;
		}

		if (
			isset( $_POST[ self::RELATIONSHIP_SUB_TO_DOWNLOAD ] )
			&& wp_verify_nonce( $_POST[ self::RELATIONSHIP_SUB_TO_DOWNLOAD ], self::EDITOR_UPDATE )
		) {
			$product_ids = wc_clean( wp_unslash( (array) $_POST['_subscription_linked_downloadable_products'] ?? array() ) );
			$product_ids = array_filter( (array) $product_ids );
			$this->update_subscription_products( $product_id, $product_ids );
		}
		// phpcs:enable
	}

	/**
	 * Assess downloadable product status and adjust permissions accordingly.
	 * Called when no form data is available (e.g., status change, REST API update, file changes).
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return void
	 */
	private function assess_downloadable_product_status( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_downloadable() ) {
			return;
		}

		$status_object = get_post_status_object( $product->get_status() );
		$is_public     = $status_object && $status_object->public;

		// Always revoke existing permissions first to ensure clean state.
		// This handles file changes and status transitions.
		$this->revoke_permissions_for_product( $product_id );

		// Grant fresh permissions only if product is public.
		if ( $is_public ) {
			$this->grant_permissions_for_product( $product_id );
		}
	}

	/**
	 * Simple product write panel options.
	 */
	public function simple_write_panel_options() {
		global $post;
		?>
			<p class="form-field _subscription_downloads_field hide_if_subscription">
				<label for="subscription-downloads-ids"><?php esc_html_e( 'Linked subscription products', 'woocommerce-subscriptions' ); ?></label>

				<select id="subscription-downloads-ids" multiple="multiple" data-action="wc_subscription_downloads_search" data-placeholder="<?php esc_attr_e( 'Select subscriptions', 'woocommerce-subscriptions' ); ?>" class="subscription-downloads-ids wc-product-search" name="_subscription_downloads_ids[]" style="width: 50%;">
					<?php
					$subscriptions_ids = WC_Subscription_Downloads::get_subscriptions( $post->ID );

					if ( $subscriptions_ids ) {
						foreach ( $subscriptions_ids as $subscription_id ) {
							$subscription = wc_get_product( $subscription_id );

							if ( $subscription ) {
								echo '<option value="' . esc_attr( $subscription_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $subscription->get_formatted_name() ) ) . '</option>';
							}
						}
					}
					?>
				</select>

				<span class="description"><?php esc_html_e( 'Select subscription products that will include this downloadable product.', 'woocommerce-subscriptions' ); ?></span>
				<?php wp_nonce_field( self::EDITOR_UPDATE, self::RELATIONSHIP_DOWNLOAD_TO_SUB, false ); ?>
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
					<p class="form-field _subscription_downloads_field form-row form-row-full hide_if_variable-subscription">
						<label><?php esc_html_e( 'Linked subscription products', 'woocommerce-subscriptions' ); ?>:</label>
						<?php echo wc_help_tip( wc_sanitize_tooltip( __( 'Select subscription products that will include this downloadable product.', 'woocommerce-subscriptions' ) ) ); ?>

						<select multiple="multiple" data-placeholder="<?php esc_html_e( 'Select subscriptions', 'woocommerce-subscriptions' ); ?>" class="subscription-downloads-ids wc-product-search" name="_variable_subscription_downloads_ids[<?php echo esc_attr( $variation->ID ); ?>][]" style="width: 100%">
							<?php
							$subscriptions_ids = WC_Subscription_Downloads::get_subscriptions( $variation->ID );
							if ( $subscriptions_ids ) {
								foreach ( $subscriptions_ids as $subscription_id ) {
									$subscription = wc_get_product( $subscription_id );

									if ( $subscription ) {
										echo '<option value="' . esc_attr( $subscription_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $subscription->get_formatted_name() ) ) . '</option>';
									}
								}
							}
							?>
						</select>
						<?php wp_nonce_field( self::EDITOR_UPDATE, self::RELATIONSHIP_VAR_DOWNLOAD_TO_SUB . $variation->ID, false ); ?>
					</p>
				</td>
			</tr>
		<?php
	}

	/**
	 * Adds a field with which to link the subscription product (the product being edited) with zero-or-many
	 * downloadable products.
	 *
	 * @return void
	 */
	public function subscription_product_editor_ui(): void {
		global $post;

		if ( ! $post instanceof WP_Post ) {
			wc_get_logger()->warning(
				'Unable to add the downloadable products selector to the product editor (global post object is unavailable).',
				array( 'backtrace' => true )
			);

			return;
		}

		$description     = esc_html__( 'Select simple and variable downloadable products that will be included with this subscription product.', 'woocommerce-subscriptions' );
		$label           = esc_html__( 'Linked downloadable products', 'woocommerce-subscriptions' );
		$linked_products = '';
		$nonce_field     = wp_nonce_field( self::EDITOR_UPDATE, self::RELATIONSHIP_SUB_TO_DOWNLOAD, false, false );
		$placeholder     = esc_attr__( 'Select products', 'woocommerce-subscriptions' );

		foreach ( WC_Subscription_Downloads::get_downloadable_products( $post->ID ) as $product_id ) {
			$product_id = absint( $product_id );
			$product    = wc_get_product( $product_id );

			if ( $product ) {
				$product_name     = esc_html( wp_strip_all_tags( $product->get_formatted_name() ) );
				$linked_products .= "<option value='$product_id' selected='selected'>$product_name</option>";
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- variables are escaped above.
		echo "
			<div class='options_group subscription_linked_downloadable_products_section'>
				<p class='form-field subscription_linked_downloadable_products'>
					<label for='subscription-linked-downloadable-products'>$label</label>
					<select
						class='wc-product-search subscription-downloads-ids'
						data-action='wc_subscription_linked_downloadable_products_search'
						data-placeholder='$placeholder'
						id='subscription-linked-downloadable-products'
						multiple='multiple'
						name='_subscription_linked_downloadable_products[]'
						style='width: 50%;'
					>
						$linked_products
					</select>
					<span class='description'>$description</span>
					$nonce_field
				</p>
			</div>
		";
	}

	/**
	 * @param int     $loop
	 * @param array   $variation_data
	 * @param WP_Post $variation
	 *
	 * @return void
	 */
	public function variable_subscription_product_editor_ui( $loop, $variation_data, $variation ): void {
		if ( ! $variation instanceof WP_Post ) {
			wc_get_logger()->warning(
				'Unable to add the downloadable products selector to the variation section of the product editor (we do not have a valid post object).',
				array( 'backtrace' => true )
			);

			return;
		}

		$variation_id    = (int) $variation->ID;
		$label           = esc_html__( 'Linked downloadable products', 'woocommerce-subscriptions' );
		$linked_products = '';
		$nonce_field     = wp_nonce_field( self::EDITOR_UPDATE, self::RELATIONSHIP_VAR_SUB_TO_DOWNLOAD . $variation_id, false, false );
		$placeholder     = esc_attr__( 'Select products', 'woocommerce-subscriptions' );
		$tooltip         = wc_help_tip( wc_sanitize_tooltip( __( 'Select simple and variable downloadable products that will be included with this subscription variation.', 'woocommerce-subscriptions' ) ) );

		foreach ( WC_Subscription_Downloads::get_downloadable_products( $variation->ID ) as $product_id ) {
			$product_id = absint( $product_id );
			$product    = wc_get_product( $product_id );

			if ( $product ) {
				$product_name     = esc_html( wp_strip_all_tags( $product->get_formatted_name() ) );
				$linked_products .= "<option value='$product_id' selected='selected'>$product_name</option>";
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- variables are escaped above.
		echo "
			<div class='variable_subscription_linked_downloadable_products show_if_variable-subscription' style='display: none'>
				<p class='form-row form-field subscription_linked_downloadable_products'>
					<label for='subscription-linked-downloadable-products'>$label</label>
					$tooltip
					<select
						class='wc-product-search subscription-downloads-ids'
						data-action='wc_subscription_linked_downloadable_products_search'
						data-placeholder='$placeholder'
						id='subscription-linked-downloadable-products'
						multiple='multiple'
						name='_subscription_linked_downloadable_products_{$variation_id}[]'
						style='width: 100%;'
					>
						$linked_products
					</select>
					$nonce_field
				</p>
			</div>
		";
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

		return apply_filters( 'woocommerce_subscription_downloads_get_orders', $orders, $subscription_product_id );
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

		$wpdb->query(
			$wpdb->prepare(
				"
					DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions
					WHERE order_id = %d AND product_id = %d AND download_id = %s;
				",
				$order_id,
				$product_id,
				$download_id
			)
		);

		do_action( 'woocommerce_ajax_revoke_access_to_product_download', $download_id, $product_id, $order_id );
	}

	/**
	 * Update subscription downloads table and orders according in respect to the described relationship between a
	 * regular product and zero-to-many regular subscription products.
	 *
	 * @param  int   $product_id    The downloadable product ID.
	 * @param  array $subscriptions Subscription product IDs.
	 *
	 * @return void
	 */
	protected function update_subscription_downloads( $product_id, $subscriptions ) {
		$current       = array_map( 'intval', WC_Subscription_Downloads::get_subscriptions( $product_id ) );
		$subscriptions = array_map( 'intval', (array) $subscriptions );

		sort( $current );
		sort( $subscriptions );

		$to_delete = array_diff( $current, $subscriptions );
		$to_create = array_diff( $subscriptions, $current );

		$this->delete_relationships( $to_delete, array( $product_id ) );
		$this->create_relationships( $to_create, array( $product_id ) );
	}

	/**
	 * Update subscription downloads table and orders according in respect to the described relationship between a
	 * subscription product and zero-to-many regular products.
	 *
	 * @param int   $subscription_product_id Subscription product ID.
	 * @param int[] $new_ids                 IDs for downloadable products that should be associated with the subscription product.
	 *
	 * @return void
	 */
	private function update_subscription_products( int $subscription_product_id, array $new_ids ): void {
		$existing_ids = array_map( 'intval', WC_Subscription_Downloads::get_downloadable_products( $subscription_product_id ) );
		$new_ids      = array_map( 'intval', $new_ids );

		sort( $existing_ids );
		sort( $new_ids );

		$to_delete = array_diff( $existing_ids, $new_ids );
		$to_create = array_diff( $new_ids, $existing_ids );

		$this->delete_relationships( array( $subscription_product_id ), $to_delete );
		$this->create_relationships( array( $subscription_product_id ), $to_create );
	}

	/**
	 * Deletes relationships that exist between any of the supplied subscription IDs and any of the supplied product
	 * IDs.
	 *
	 * The most common use case will be to supply a single subscription ID and one-or-more product IDs, or else the
	 * inverse.
	 *
	 * @param int[] $subscription_ids
	 * @param int[] $product_ids
	 *
	 * @return void
	 */
	private function delete_relationships( array $subscription_ids, array $product_ids ): void {
		global $wpdb;

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;

			foreach ( $subscription_ids as $subscription_id ) {
				$subscription_id = (int) $subscription_id;

				$wpdb->delete(
					$wpdb->prefix . 'woocommerce_subscription_downloads',
					array(
						'product_id'      => $product_id,
						'subscription_id' => $subscription_id,
					),
					array(
						'%d',
						'%d',
					)
				);

				$orders = $this->get_orders( $subscription_id );
				foreach ( $orders as $order_id ) {
					$product   = wc_get_product( $product_id );
					$downloads = $product->get_downloads();

					// Adds the downloadable files to the order/subscription.
					foreach ( array_keys( $downloads ) as $download_id ) {
						$this->revoke_access_to_download( $download_id, $product_id, $order_id );
					}
				}
			}
		}
	}

	/**
	 * Revoke download permissions for a product across all related subscriptions.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return void
	 */
	private function revoke_permissions_for_product( $product_id ) {
		global $wpdb;

		$subscription_ids = WC_Subscription_Downloads::get_subscriptions( $product_id );

		if ( empty( $subscription_ids ) ) {
			return;
		}

		foreach ( $subscription_ids as $subscription_id ) {
			$orders = $this->get_orders( $subscription_id );

			foreach ( $orders as $order_id ) {
				// Delete ALL permissions for this product+order combination.
				// This ensures that when files change, old permissions with different download_ids are removed.
				$wpdb->delete(
					$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
					array(
						'order_id'   => $order_id,
						'product_id' => $product_id,
					),
					array(
						'%d',
						'%d',
					)
				);

				do_action( 'woocommerce_revoke_access_to_product_download', $product_id, $order_id );
			}
		}
	}

	/**
	 * Grant download permissions for a product across all related subscriptions.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return void
	 */
	private function grant_permissions_for_product( $product_id ) {
		$subscription_product_ids = WC_Subscription_Downloads::get_subscriptions( $product_id );
		$product                  = wc_get_product( $product_id );

		if ( empty( $subscription_product_ids ) || ! $product ) {
			return;
		}

		$downloads = $product->get_downloads();

		foreach ( $subscription_product_ids as $subscription_id ) {
			$orders = $this->get_orders( $subscription_id );

			foreach ( $orders as $order_id ) {
				$order = wc_get_order( $order_id );

				if ( ! is_a( $order, 'WC_Subscription' ) ) {
					continue;
				}

				foreach ( array_keys( $downloads ) as $download_id ) {
					wc_downloadable_file_permission( $download_id, $product_id, $order );
				}
			}
		}
	}

	/**
	 * Adds relationships between the specified subscription and product IDs.
	 *
	 * The most common use case will be to supply a single subscription ID and one-or-more product IDs, or else the
	 * inverse.
	 *
	 * @param int[] $subscription_ids
	 * @param int[] $product_ids
	 *
	 * @return void
	 */
	private function create_relationships( array $subscription_ids, array $product_ids ): void {
		global $wpdb;

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$product    = wc_get_product( $product_id );

			// Check if product has public status.
			$has_public_status = false;
			if ( $product ) {
				$status_object     = get_post_status_object( $product->get_status() );
				$has_public_status = $status_object && $status_object->public;
			}

			foreach ( $subscription_ids as $subscription_id ) {
				$subscription_id = (int) $subscription_id;

				$wpdb->insert(
					$wpdb->prefix . 'woocommerce_subscription_downloads',
					array(
						'product_id'      => $product_id,
						'subscription_id' => $subscription_id,
					),
					array(
						'%d',
						'%d',
					)
				);

				// Only grant download permissions if product has public status.
				if ( $has_public_status ) {
					$orders = $this->get_orders( $subscription_id );
					foreach ( $orders as $order_id ) {
						$order = wc_get_order( $order_id );

						if ( ! is_a( $order, 'WC_Subscription' ) ) {
							// avoid adding permissions to orders and it's
							// subscription for the same user, causing duplicates
							// to show up
							continue;
						}

						$product   = wc_get_product( $product_id );
						$downloads = $product->get_downloads();

						// Adds the downloadable files to the order/subscription.
						foreach ( array_keys( $downloads ) as $download_id ) {
							wc_downloadable_file_permission( $download_id, $product_id, $order );
						}
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

	/**
	 * Deprecated, do not use. Previously took care of saving product data for variations.
	 *
	 * @deprecated 8.3.0
	 *
	 * @param int $variation_id
	 * @param int $index
	 *
	 * @return void
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function save_variation_product_data( $variation_id, $index ) {
		wc_deprecated_function( __METHOD__, '8.3.0', __CLASS__ . '::handle_product_variation_save' );
	}

	/**
	 * Deprecated, do not use. Previously took care of saving product data.
	 *
	 * @deprecated 8.3.0
	 *
	 * @param int      $subscription_product_id
	 * @param int|null $index
	 *
	 * @return void
	*/
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function save_subscription_product_data( int $subscription_product_id, ?int $index = null ) {
		wc_deprecated_function( __METHOD__, '8.3.0', __CLASS__ . '::handle_product_save' );
	}

	/**
	 * Deprecated, do not use. Previously set up assets for the Subscription Downloads extension.
	 *
	 * @deprecated 8.3.0
	 *
	 * @return void
	 */
	public function scripts() {
		wc_deprecated_function( __METHOD__, '8.3.0' );
	}
}
