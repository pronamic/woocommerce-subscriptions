<?php
/**
 * Downloadable files access policy for gifted subscriptions.
 *
 * @package WooCommerce Subscriptions Gifting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Properly handles permissions and access for downloadable files associated to gifted subscriptions.
 */
class WCSG_Download_Handler {

	/**
	 * Cache of subscription download permissions.
	 *
	 * @var array
	 */
	private static $subscription_download_permissions = array();

	/**
	 * Temporary cache of recipient download permissions stored before a subscription is saved.
	 *
	 * @var array
	 */
	private static $recipient_download_permissions = array();

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', __CLASS__ . '::register_download_settings', 11, 1 );
		add_filter( 'woocommerce_downloadable_file_permission_data', __CLASS__ . '::grant_recipient_download_permissions', 11 );
		add_filter( 'woocommerce_get_item_downloads', __CLASS__ . '::get_item_download_links', 15, 3 );

		// Download Permission Meta Box Functions.
		add_action( 'woocommerce_process_shop_order_meta', __CLASS__ . '::download_permissions_meta_box_save', 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_order_details', __CLASS__ . '::get_download_permissions_before_meta_box', 10, 1 );
		add_filter( 'woocommerce_admin_download_permissions_title', __CLASS__ . '::add_user_to_download_permission_title', 10, 3 );

		// Grant access via download meta box - hooked on prior to WC_AJAX::grant_access_to_download().
		add_action( 'wp_ajax_woocommerce_grant_access_to_download', __CLASS__ . '::ajax_grant_download_permission', 9 );

		// Revoke access via download meta box - hooked to a custom Ajax handler in place of WC_AJAX::revoke_access_to_download().
		add_action( 'wp_ajax_wcsg_revoke_access_to_download', __CLASS__ . '::ajax_revoke_download_permission' );

		// Handle subscriptions created on the admin. Needs to be hooked on prior WCS_Download_Handler::grant_permissions_on_admin_created_subscription().
		add_action( 'woocommerce_admin_created_subscription', array( __CLASS__, 'grant_permissions_on_admin_created_subscription' ), 1 );

		// Handle recipient download permissions when the subscription's customer or recipient is changed.
		add_action( 'woocommerce_before_subscription_object_save', array( __CLASS__, 'maybe_store_recipient_permissions_before_save' ) );
		add_action( 'woocommerce_order_object_updated_props', array( __CLASS__, 'maybe_restore_recipient_permissions_after_save' ), 10, 2 );
		add_action( 'woocommerce_subscriptions_gifting_recipient_changed', array( __CLASS__, 'maybe_grant_permissions_to_new_recipient' ), 10, 3 );
	}

	/**
	 * Gets the correct user's download links for a downloadable order item.
	 * If the request is from within an email, the links belonging to the email recipient are returned otherwise
	 * if the request is from the view subscription page use the current user id,
	 * otherwise the links for order's customer user are returned.
	 *
	 * @param array  $files Downloadable files for the order item.
	 * @param array  $item  Order line item.
	 * @param object $order Order object.
	 * @return array $files Files.
	 */
	public static function get_item_download_links( $files, $item, $order ) {
		$recipient_user_id = WCS_Gifting::get_recipient_user( $order );

		if ( $recipient_user_id ) {
			$user_id = ( wcs_is_subscription( $order ) && wcs_is_view_subscription_page() ) ? get_current_user_id() : $order->get_user_id();
			$mailer  = WC()->mailer();

			foreach ( $mailer->emails as $email ) {
				if ( isset( $email->wcsg_sending_recipient_email ) ) {
					$user_id = $recipient_user_id;
					break;
				}
			}

			$files = self::get_user_downloads_for_order_item( $order, $user_id, $item );
		}
		return $files;
	}

	/**
	 * Grants download permissions to the recipient rather than the purchaser by default. However if the
	 * purchaser can download setting is selected, permissions are granted to both recipient and purchaser.
	 *
	 * @param array $data download permission data inserted into the wp_woocommerce_downloadable_product_permissions table.
	 * @return array $data
	 */
	public static function grant_recipient_download_permissions( $data ) {

		$subscription = wcs_get_subscription( $data['order_id'] );

		if ( $subscription && WCS_Gifting::is_gifted_subscription( $subscription ) ) {

			$can_purchaser_download = ( 'yes' === get_option( WCSG_Admin::$option_prefix . '_downloadable_products', 'no' ) ) ? true : false;

			if ( $can_purchaser_download ) {
				remove_filter( 'woocommerce_downloadable_file_permission_data', __CLASS__ . '::grant_recipient_download_permissions', 11 );

				wc_downloadable_file_permission( $data['download_id'], $data['product_id'], $subscription );

				add_filter( 'woocommerce_downloadable_file_permission_data', __CLASS__ . '::grant_recipient_download_permissions', 11 );
			}

			$recipient_id       = WCS_Gifting::get_recipient_user( $subscription );
			$recipient          = get_user_by( 'id', $recipient_id );
			$data['user_id']    = $recipient_id;
			$data['user_email'] = $recipient->user_email;
		}
		return $data;
	}

	/**
	 * Insert Gifting download specific settings into Subscriptions settings
	 *
	 * @param array $settings Subscription's current set of settings.
	 * @return array $settings new settings with appended wcsg specific settings.
	 */
	public static function register_download_settings( $settings ) {

		$insert_index = array_search( // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
			array(
				'type' => 'sectionend',
				'id'   => WCSG_Admin::$option_prefix,
			),
			$settings
		);

		array_splice(
			$settings,
			$insert_index,
			0,
			array(
				array(
					'name'      => __( 'Downloadable Products', 'woocommerce-subscriptions' ),
					'desc'      => __( 'Allow both purchaser and recipient to download subscription products.', 'woocommerce-subscriptions' ),
					'id'        => WCSG_Admin::$option_prefix . '_downloadable_products',
					'default'   => 'no',
					'type'      => 'checkbox',
					'row_class' => 'gifting-downloadable-products',
					'desc_tip'  => __( 'If you want both the recipient and purchaser of a subscription to have access to downloadable products.', 'woocommerce-subscriptions' ),
				),
			)
		);

		return $settings;
	}

	/**
	 * Before displaying the meta box, save an unmodified set of the download permissions so they can be used later
	 * when displaying user information and outputting download permission hidden fields (which needs to be done just
	 * once per permission).
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 */
	public static function get_download_permissions_before_meta_box( $subscription ) {
		global $wpdb;

		if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {

			self::$subscription_download_permissions = self::get_subscription_download_permissions( wcsg_get_objects_id( $subscription ) );
		}
	}

	/**
	 * Formats the download permission title to also include information about the user the permission belongs to.
	 * This is to make it clear to store managers which user's permissions are being edited.
	 *
	 * We also sneak in hidden fields for the user and permission ID to make sure that we can revoke or modify
	 * permissions for a specific user, because WC doesn't use permission IDs and instead uses download IDs, which
	 * are a hash that do not take into account user ID and duplicate permissions for the same product on the same
	 * order for different users.
	 *
	 * @param string $download_title The download permission title displayed in order download permission meta boxes.
	 * @param int    $product_id     Product ID.
	 * @param int    $order_id       Order ID.
	 */
	public static function add_user_to_download_permission_title( $download_title, $product_id, $order_id ) {

		$subscription = wcs_get_subscription( $order_id );

		if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {

			foreach ( self::$subscription_download_permissions as $index => $download ) {
				if ( ! isset( $download->displayed ) ) {
					?>
					<input type="hidden" class="wcsg_download_permission_id" name="wcsg_download_permission_ids[<?php echo esc_attr( $index ); ?>]" value="<?php echo absint( $download->permission_id ); ?>" />
					<input type="hidden" class="wcsg_download_permission_id" name="wcsg_download_user_ids[<?php echo esc_attr( $index ); ?>]" value="<?php echo absint( $download->user_id ); ?>" />
					<?php

					$user_role = ( WCS_Gifting::get_recipient_user( $subscription ) == $download->user_id ) ? __( 'Recipient', 'woocommerce-subscriptions' ) : __( 'Purchaser', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
					$user      = get_userdata( $download->user_id );
					$user_name = ucfirst( $user->first_name ) . ( ( ! empty( $user->last_name ) ) ? ' ' . ucfirst( $user->last_name ) : '' );

					$download_title      = $user_role . ' (' . ( empty( $user_name ) ? ucfirst( $user->display_name ) : $user_name ) . ') &mdash; ' . $download_title;
					$download->displayed = true;
					break;
				}
			}
		}

		return $download_title;
	}

	/**
	 * Save download permission meta box data.
	 *
	 * We need to unhook WC_Meta_Box_Order_Downloads::save() to prevent the WC save function from being called because
	 * it does not differentiate between duplicate permissions for the same product on the same order even when the
	 * permissions are for different users (and with different permission IDs). This means it would modify all
	 * permissions on that order for that product and set them all to be for the same user, instead of keeping
	 * them for the different users.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function download_permissions_meta_box_save( $subscription_id ) {
		global $wpdb;

		// Post WC 3.0 WC_Meta_Box_Order_Downloads::save() no longer overrides the user ID associated with the download permissions so the contents of this function aren't necessary.
		if ( ! wcsg_is_woocommerce_pre( '3.0' ) ) {
			return;
		}

		if ( isset( $_POST['wcsg_download_permission_ids'] ) && isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			remove_action( 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Downloads::save', 30 );

			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$permission_ids      = $_POST['wcsg_download_permission_ids'];
			$user_ids            = $_POST['wcsg_download_user_ids'];
			$download_ids        = $_POST['download_id'];
			$product_ids         = $_POST['product_id'];
			$downloads_remaining = $_POST['downloads_remaining'];
			$access_expires      = $_POST['access_expires'];
			// phpcs:enable

			$subscription = wcs_get_subscription( $subscription_id );

			foreach ( $download_ids as $index => $download_id ) {

				$expiry = ( array_key_exists( $index, $access_expires ) && '' != $access_expires[ $index ] ) ? date_i18n( 'Y-m-d', strtotime( $access_expires[ $index ] ) ) : null; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison

				$data = array(
					'downloads_remaining' => wc_clean( $downloads_remaining[ $index ] ),
					'access_expires'      => $expiry,
				);

				$format = array( '%s', '%s' );

				// if we're updating the purchaser's permissions, update the download user id and email, in case it has changed.
				if ( WCS_Gifting::get_recipient_user( $subscription ) != $user_ids[ $index ] ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
					$data['user_id'] = absint( $_POST['customer_user'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
					$format[]        = '%d';

					$data['user_email'] = wc_clean( wp_unslash( $_POST['_billing_email'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
					$format[]           = '%s';
				}

				$wpdb->update(
					$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
					$data,
					array(
						'order_id'      => $subscription_id,
						'product_id'    => absint( $product_ids[ $index ] ),
						'download_id'   => wc_clean( $download_ids[ $index ] ),
						'permission_id' => $permission_ids[ $index ],
					),
					$format,
					array( '%d', '%d', '%s', '%d' )
				);
			}
		}
	}

	/**
	 * Get all download permissions for a subscription
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $order_by        Column to use inside the ORDER BY clause.
	 */
	private static function get_subscription_download_permissions( $subscription_id, $order_by = 'product_id' ) {
		global $wpdb;

		// Only allow ordering by permissions_id and product_id (because we can't sanitise $order_by with $wpdb->prepare(), we need it as a column not a string).
		if ( 'permission_id' !== $order_by ) {
			$order_by = 'product_id';
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d ORDER BY %s",
				$subscription_id,
				$order_by
			)
		);
	}

	/**
	 * Grants download permissions from the edit subscription meta box grant access button.
	 * Outputs meta box table rows for each permission granted.
	 */
	public static function ajax_grant_download_permission() {

		check_ajax_referer( 'grant-access', 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			die( -1 );
		}

		global $wpdb;

		$wpdb->hide_errors();

		$order_id     = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
		$product_ids  = isset( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$loop         = isset( $_POST['loop'] ) ? intval( $_POST['loop'] ) : 0;
		$file_counter = 0;

		if ( WCS_Gifting::is_gifted_subscription( $order_id ) ) {

			/** @var WC_Subscription $subscription */
			$subscription         = wcs_get_subscription( $order_id );
			$download_permissions = self::get_subscription_download_permissions( $order_id, 'permission_id' );
			$file_names           = array();
			$billing_email        = is_callable( array( $subscription, 'get_billing_email' ) ) ? $subscription->get_billing_email() : $subscription->billing_email;

			if ( ! $billing_email ) {
				die();
			}

			if ( ! is_array( $product_ids ) ) {
				$product_ids = array( $product_ids );
			}

			foreach ( $product_ids as $product_id ) {
				/** @var WC_Product $product */
				$product = wc_get_product( $product_id );
				$files   = is_callable( array( $product, 'get_downloads' ) ) ? $product->get_downloads() : $product->get_files();

				if ( $files ) {
					foreach ( $files as $download_id => $file ) {

						++$file_counter;

						if ( isset( $file['name'] ) ) {
							$file_names[ $download_id ] = $file['name'];
						} else {
							// Translators: placeholder is the number of files.
							$file_names[ $download_id ] = sprintf( __( 'File %d', 'woocommerce-subscriptions' ), $file_counter );
						}

						wc_downloadable_file_permission( $download_id, $product_id, $subscription );
					}
				}
			}

			if ( 0 < count( $file_names ) ) {
				$updated_download_permissions = self::get_subscription_download_permissions( $order_id, 'permission_id' );
				$new_download_permissions     = array_diff( array_keys( $updated_download_permissions ), array_keys( $download_permissions ) );

				foreach ( $new_download_permissions as $new_download_permission_index ) {

					++$loop;

					$download   = $updated_download_permissions[ $new_download_permission_index ];
					$file_count = $file_names[ $download->download_id ];

					self::$subscription_download_permissions[ $loop ] = $download;

					if ( class_exists( 'WC_Customer_Download' ) ) {
						// Post WC 3.0 the template expects a WC_Customer_Download object rather than stdClass objects.
						$download = new WC_Customer_Download( $download );
					}
					include plugin_dir_path( WC_PLUGIN_FILE ) . 'includes/admin/meta-boxes/views/html-order-download-permission.php';
				}
			}

			die();
		}
	}

	/**
	 * WooCommerce revokes download permissions based only on the product an order ID, that means when
	 * revoking downloads on a gift subscription with permissions for both the purchaser and recipient,
	 * it will revoke both sets of permissions instead of only the permission against which the store
	 * manager clicked the "Revoke Access" button.
	 *
	 * To workaround this, we add the permission ID as a hidden fields against each download permission
	 * with @see self::add_user_to_download_permission_title(). We then trigger a custom Ajax request
	 * that passes the permission ID to the server to make sure we only revoke only that permission.
	 *
	 * We also need to remove WC's handler, which is the WC_Ajax:;revoke_access_to_download() method attached
	 * to the 'woocommerce_revoke_access_to_download' Ajax action. To do this, we have out wcsg-admin.js file
	 * enqueued after WooCommerce's 'wc-admin-order-meta-boxes' script and then in our JavaScript call
	 * $( '.order_download_permissions' ).off() to remove WooCommerce's Ajax method.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.
	 */
	public static function ajax_revoke_download_permission() {
		global $wpdb;

		check_admin_referer( 'revoke_download_permission', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			die( -1 );
		}

		$subscription_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

		if ( WCS_Gifting::is_gifted_subscription( $subscription_id ) ) {

			$permission_id = isset( $_POST['download_permission_id'] ) ? intval( $_POST['download_permission_id'] ) : 0;

			if ( ! empty( $permission_id ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d AND permission_id = %d", $subscription_id, $permission_id ) );
			}
		}

		die();
	}

	/**
	 * Retrieves a user's download permissions for an order.
	 *
	 * @param WC_Order $order   Order object.
	 * @param int      $user_id User ID.
	 * @param array    $item    Order item.
	 *
	 * @return array
	 */
	public static function get_user_downloads_for_order_item( $order, $user_id, $item ) {
		global $wpdb;

		$product_id = wcs_get_canonical_product_id( $item );

		$downloads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions
				WHERE user_id = %d
				AND order_id = %d
				AND product_id = %d",
				$user_id,
				wcsg_get_objects_id( $order ),
				$product_id
			)
		);

		$files = array();
		/** @var WC_Product $product */
		$product = wc_get_product( $product_id );

		foreach ( $downloads as $download ) {

			if ( $product->has_file( $download->download_id ) ) {
				if ( wcsg_is_woocommerce_pre( '3.0' ) ) {
					$files[ $download->download_id ] = $product->get_file( $download->download_id );
				} else {
					$customer_download = new WC_Customer_Download( $download );
					/** @var WC_Product_Download $file */
					$file = $product->get_file( $download->download_id );

					$files[ $download->download_id ]                        = $file->get_data();
					$files[ $download->download_id ]['downloads_remaining'] = $customer_download->get_downloads_remaining();
					$files[ $download->download_id ]['access_expires']      = $customer_download->get_access_expires();
				}

				$files[ $download->download_id ]['download_url'] = add_query_arg(
					array(
						'download_file' => $product_id,
						'order'         => $download->order_key,
						'email'         => $download->user_email,
						'key'           => $download->download_id,
					),
					home_url( '/' )
				);
			}
		}
		return $files;
	}

	/**
	 * Retrieves all the user's download permissions for an order by checking
	 * for downloads stored on the subscriptions in the order.
	 *
	 * @param WC_Order $order   Order object.
	 * @param int      $user_id User ID.
	 *
	 * @return array
	 */
	public static function get_user_downloads_for_order( $order, $user_id ) {

		$subscriptions   = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
		$order_downloads = array();

		foreach ( $subscriptions as $subscription ) {
			foreach ( $subscription->get_items() as $subscription_item ) {
				$order_downloads = array_merge( $order_downloads, self::get_user_downloads_for_order_item( $subscription, $user_id, $subscription_item ) );
			}
		}

		return $order_downloads;
	}

	/**
	 * Makes sure download permissions on newly created subscriptions (admin-side) are granted after the recipient has
	 * been set.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.2.
	 */
	public static function grant_permissions_on_admin_created_subscription( $subscription ) {
		// Prevent WC Subscriptions from granting permissions to the subscription before Gifting has had a chance
		// to save the recipient information.
		remove_action( current_action(), array( 'WCS_Download_Handler', 'grant_download_permissions' ) );

		// Grant permissions after recipient information has been saved (which happens with priority 50).
		add_action( 'woocommerce_process_shop_order_meta', 'wc_downloadable_product_permissions', 60 );
	}

	/**
	 * Stores recipient download permissions before a subscription is saved, just in case they are needed later.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.2.
	 */
	public static function maybe_store_recipient_permissions_before_save( $subscription ) {
		global $wpdb;

		$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );
		if ( ! $recipient_user_id ) {
			return;
		}

		if ( isset( self::$recipient_download_permissions[ $subscription->get_id() ] ) ) {
			return;
		}

		$data_store = WC_Data_Store::load( 'customer-download' );
		self::$recipient_download_permissions[ $subscription->get_id() ] = $data_store->get_downloads(
			array(
				'order_id' => $subscription->get_id(),
				'user_id'  => $recipient_user_id,
				'return'   => 'ids',
			)
		);
	}

	/**
	 * Restores recipient download permissions from cached values.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.2.
	 */
	private static function restore_recipient_permissions( $subscription ) {
		$subscription = wcs_get_subscription( $subscription );
		if ( ! $subscription || empty( self::$recipient_download_permissions[ $subscription->get_id() ] ) ) {
			return;
		}

		$recipient_user_id = WCS_Gifting::get_recipient_user( $subscription );
		$recipient         = $recipient_user_id ? get_user_by( 'id', $recipient_user_id ) : false;

		if ( ! $recipient ) {
			return;
		}

		foreach ( self::$recipient_download_permissions[ $subscription->get_id() ] as $permission_id ) {
			$download = new WC_Customer_Download( $permission_id );
			$download->set_user_id( $recipient_user_id );
			$download->set_user_email( $recipient->user_email );
			$download->save();
		}
	}

	/**
	 * Restores previous recipient permissions when the subscription's customer changes.
	 * This is required because WC resets all download permissions to the new customer (effectively disabling recipient access) when such a change is made.
	 *
	 * @param WC_Order $order         Order object.
	 * @param array    $updated_props Properties that changed.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.2.
	 */
	public static function maybe_restore_recipient_permissions_after_save( $order, $updated_props ) {
		global $wpdb;

		if ( ! wcs_is_subscription( $order ) || ! array_intersect( array( 'customer_id', 'billing_email' ), $updated_props ) ) {
			return;
		}

		self::restore_recipient_permissions( $order );
	}

	/**
	 * Grants new recipients the downloads permissions the previous recipient had.
	 *
	 * @param WC_Subscription $subscription     Subscription object.
	 * @param int             $new_recipient_id New recipient user ID.
	 * @param int             $old_recipient_id Old recipient user ID.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.2.
	 */
	public static function maybe_grant_permissions_to_new_recipient( $subscription, $new_recipient_id, $old_recipient_id ) {
		global $wpdb;

		if ( ! $old_recipient_id || ! $new_recipient_id ) {
			return;
		}

		self::restore_recipient_permissions( $subscription );
	}
}
