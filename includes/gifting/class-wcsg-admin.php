<?php
/**
 * Admin integration.
 *
 * @package WooCommerce Subscriptions Gifting/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class for admin-side integration.
 */
class WCSG_Admin {

	/**
	 * Prefix used in all Gifting settings names.
	 *
	 * @var string
	 */
	public static $option_prefix = 'woocommerce_subscriptions_gifting';

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		add_filter( 'woocommerce_subscription_list_table_column_content', __CLASS__ . '::display_recipient_name_in_subscription_title', 1, 3 );

		add_filter( 'woocommerce_order_items_meta_get_formatted', __CLASS__ . '::remove_recipient_order_item_meta', 1, 1 );

		add_filter( 'woocommerce_subscription_settings', __CLASS__ . '::add_settings', 10, 1 );

		if ( wcsg_is_wc_subscriptions_pre( '2.3.5' ) ) {
			add_filter( 'request', __CLASS__ . '::request_query', 11, 1 );
		} else {
			add_filter( 'wcs_admin_request_query_subscriptions_for_customer', array( __CLASS__, 'request_query_customer_filter' ), 10, 2 );
		}

		add_action( 'woocommerce_admin_order_data_after_order_details', __CLASS__ . '::display_edit_subscription_recipient_field', 10, 1 );

		// Save recipient user after WC have saved all subscription order items (40).
		add_action( 'woocommerce_process_shop_order_meta', __CLASS__ . '::save_subscription_recipient_meta', 50, 2 );

		add_action( 'admin_notices', __CLASS__ . '::admin_installed_notice' );

		// Filter for gifted subscriptions.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_gifted_subscriptions_filter' ), 50 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'add_gifted_subscriptions_filter' ), 50 );

		add_action( 'pre_get_posts', array( __CLASS__, 'maybe_filter_by_gifted_subscriptions' ) );
		add_action( 'woocommerce_shop_subscription_list_table_prepare_items_query_args', array( __CLASS__, 'filter_subscription_list_table_by_gifted_subscriptions' ) );

		// Add "Resend new recipient account email".
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_resend_new_recipient_account_email_action' ), 10, 1 );
		add_action( 'woocommerce_order_action_wcsg_resend_new_recipient_account_email', array( __CLASS__, 'resend_new_recipient_account_email' ), 10, 1 );

		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_wcsg_recipient_meta' ) );
	}

	/**
	 * Hides the wcsg_recipient meta from the order item meta in the edit order page.
	 *
	 * @param array $hidden_order_itemmeta The hidden order item meta.
	 * @return array The hidden order item meta.
	 */
	public static function hide_wcsg_recipient_meta( $hidden_order_itemmeta ) {
		$hidden_order_itemmeta[] = '_wcsg_cart_key';
		return $hidden_order_itemmeta;
	}

	/**
	 * Register/queue admin scripts.
	 */
	public static function enqueue_scripts() {
		global $post;

		$screen = get_current_screen();

		if ( 'shop_subscription' === $screen->id && WCS_Gifting::is_gifted_subscription( $post->ID ) ) {

			wp_register_script( 'wcs_gifting_admin', plugins_url( '/assets/js/gifting/wcsg-admin.js', WC_Subscriptions::$plugin_file ), array( 'jquery', 'wc-admin-order-meta-boxes' ), WC_Subscriptions::$version, true );

			wp_localize_script(
				'wcs_gifting_admin',
				'wcs_gifting',
				array(
					'revoke_download_permission_nonce' => wp_create_nonce( 'revoke_download_permission' ),
					'ajax_url'                         => admin_url( 'admin-ajax.php' ),
				)
			);

			wp_enqueue_script( 'wcs_gifting_admin' );
		}

		if ( true == get_transient( 'wcsg_show_activation_notice' ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			wp_enqueue_style( 'woocommerce-activation', plugins_url( '/assets/css/activation.css', WC_PLUGIN_FILE ), array(), WC_VERSION );
		}
	}

	/**
	 * Formats the subscription title in the admin subscriptions table to include the recipient's name.
	 *
	 * @param string          $column_content The column content HTML elements.
	 * @param WC_Subscription $subscription   Subscription object.
	 * @param string          $column         The column name being rendered.
	 */
	public static function display_recipient_name_in_subscription_title( $column_content, $subscription, $column ) {

		if ( 'order_title' === $column && WCS_Gifting::is_gifted_subscription( $subscription ) ) {

			$recipient_id   = WCS_Gifting::get_recipient_user( $subscription );
			$recipient_user = get_userdata( $recipient_id );
			$recipient_name = '<a href="' . esc_url( get_edit_user_link( $recipient_id ) ) . '">';

			if ( ! empty( $recipient_user->first_name ) || ! empty( $recipient_user->last_name ) ) {
				$recipient_name .= ucfirst( $recipient_user->first_name ) . ( ( ! empty( $recipient_user->last_name ) ) ? ' ' . ucfirst( $recipient_user->last_name ) : '' );
			} else {
				$recipient_name .= ucfirst( $recipient_user->display_name );
			}
			$recipient_name .= '</a>';

			$purchaser_id   = $subscription->get_user_id();
			$purchaser_user = get_userdata( $purchaser_id );
			$purchaser_name = '<a href="' . esc_url( get_edit_user_link( $purchaser_id ) ) . '">';

			if ( ! empty( $purchaser_user->first_name ) || ! empty( $purchaser_user->last_name ) ) {
				$purchaser_name .= ucfirst( $purchaser_user->first_name ) . ( ( ! empty( $purchaser_user->last_name ) ) ? ' ' . ucfirst( $purchaser_user->last_name ) : '' );
			} else {
				$purchaser_name .= ucfirst( $purchaser_user->display_name );
			}
			$purchaser_name .= '</a>';

			// translators: $1: is subscription order number,$2: is recipient user's name, $3: is the purchaser user's name.
			$column_content = sprintf( _x( '%1$s for %2$s purchased by %3$s', 'Subscription title on admin table. (e.g.: #211 for John Doe Purchased by: Jane Doe)', 'woocommerce-subscriptions' ), '<a href="' . esc_url( $subscription->get_edit_order_url() ) . '">#<strong>' . esc_attr( $subscription->get_order_number() ) . '</strong></a>', $recipient_name, $purchaser_name );

			$column_content .= '</div>';
		}

		return $column_content;
	}

	/**
	 * Removes the recipient order item meta from the admin subscriptions table.
	 *
	 * @param array $formatted_meta formatted order item meta key, label and value.
	 */
	public static function remove_recipient_order_item_meta( $formatted_meta ) {

		if ( is_admin() ) {
			$screen = get_current_screen();

			if ( isset( $screen->id ) && 'edit-shop_subscription' === $screen->id ) {
				foreach ( $formatted_meta as $meta_id => $meta ) {
					if ( 'wcsg_recipient' === $meta['key'] ) {
						unset( $formatted_meta[ $meta_id ] );
					}
				}
			}
		}

		return $formatted_meta;
	}

	/**
	 * Add Gifting specific settings to standard Subscriptions settings
	 *
	 * @param array $settings  Current set of settings.
	 * @return array $settings New set of settings.
	 */
	public static function add_settings( $settings ) {

		return array_merge(
			$settings,
			array(
				array(
					'name' => __( 'Gifting', 'woocommerce-subscriptions' ),
					'type' => 'title',
					'id'   => self::$option_prefix,
				),
				array(
					'name'      => __( 'Enable gifting', 'woocommerce-subscriptions' ),
					'desc'      => __( 'Allow shoppers to gift a subscription', 'woocommerce-subscriptions' ),
					'id'        => self::$option_prefix . '_enable_gifting',
					'default'   => 'no',
					'type'      => 'checkbox',
					'row_class' => 'enable-gifting',
				),
				array(
					'name'        => '',
					'desc'        => __( 'You can override this global setting on each product.', 'woocommerce-subscriptions' ),
					'id'          => self::$option_prefix . '_default_option',
					'default'     => 'disabled',
					'type'        => 'radio',
					'desc_at_end' => true,
					'row_class'   => 'gifting-radios',
					'options'     => array(
						'enabled'  => __( 'Enabled for all products', 'woocommerce-subscriptions' ),
						'disabled' => __( 'Disabled for all products', 'woocommerce-subscriptions' ),
					),
				),
				array(
					'name'      => __( 'Gifting Checkbox Text', 'woocommerce-subscriptions' ),
					'desc'      => __( 'This is what shoppers will see in the product page and cart.', 'woocommerce-subscriptions' ),
					'id'        => self::$option_prefix . '_gifting_checkbox_text',
					'default'   => __( 'This is a gift', 'woocommerce-subscriptions' ),
					'type'      => 'text',
					'row_class' => 'gifting-checkbox-text',
				),
				array(
					'type' => 'sectionend',
					'id'   => self::$option_prefix,
				),
			)
		);
	}

	/**
	 * Adds meta query to also include subscriptions the user is the recipient of when filtering subscriptions by customer.
	 * Compatibility method for Subscriptions < 2.3.5.
	 *
	 * @param  array $vars Request vars.
	 * @return array
	 */
	public static function request_query( $vars ) {
		global $typenow;

		if ( 'shop_subscription' === $typenow ) {

			// Add _recipient_user meta check when filtering by customer.
			$user_id = isset( $_GET['_customer_user'] ) ? intval( $_GET['_customer_user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $user_id ) {
				$vars['meta_query'][] = array(
					'key'     => '_recipient_user',
					'value'   => $user_id,
					'compare' => '=',
				);

				$vars['meta_query']['relation'] = 'OR';
			}
		}

		return $vars;
	}

	/**
	 * Adds subscriptions the user is the recipient of when filtering subscriptions by customer on the backend.
	 *
	 * @param array $subscription_ids Current set of subscription IDs.
	 * @param int   $customer_user_id User ID.
	 * @return array New set of subscription IDs.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting  2.0.2.
	 */
	public static function request_query_customer_filter( $subscription_ids, $customer_user_id ) {
		$recipient_subscriptions = WCSG_Recipient_Management::get_recipient_subscriptions( $customer_user_id );
		return array_merge( $subscription_ids, $recipient_subscriptions );
	}

	/**
	 * Output a recipient user select field in the edit subscription data metabox.
	 *
	 * @param WP_Post $subscription Subscription's post object.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.1.
	 */
	public static function display_edit_subscription_recipient_field( $subscription ) {

		if ( ! wcs_is_subscription( $subscription ) ) {
			return;
		} ?>

		<p class="form-field form-field-wide wc-customer-user">
			<label for="recipient_user"><?php esc_html_e( 'Recipient:', 'woocommerce-subscriptions' ); ?></label>
			<?php
			$user_string = '';
			$user_id     = '';
			if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {
				$user_id     = WCS_Gifting::get_recipient_user( $subscription );
				$user        = get_user_by( 'id', $user_id );
				$user_string = esc_html( $user->display_name ) . ' (#' . absint( $user->ID ) . ' &ndash; ' . esc_html( $user->user_email );
			}

			if ( is_callable( array( 'WCS_Select2', 'render' ) ) ) {
				WCS_Select2::render(
					array(
						'class'       => 'wc-customer-search',
						'name'        => 'recipient_user',
						'id'          => 'recipient_user',
						'placeholder' => esc_attr__( 'Search for a recipient&hellip;', 'woocommerce-subscriptions' ),
						'selected'    => $user_string,
						'value'       => $user_id,
						'allow_clear' => 'true',
					)
				);
			} else {
				?>
				<input type="hidden" class="wc-customer-search" id="recipient_user" name="recipient_user" data-placeholder="<?php esc_attr_e( 'Search for a recipient&hellip;', 'woocommerce-subscriptions' ); ?>" data-selected="<?php echo esc_attr( $user_string ); ?>" value="<?php echo esc_attr( $user_id ); ?>" data-allow_clear="true"/>
				<?php
			}
			?>
		</p>
		<?php
	}

	/**
	 * Save subscription recipient user meta by updating or deleting _recipient_user post meta.
	 * Also updates the recipient id stored in subscription line item meta.
	 *
	 * @param int     $post_id  Post ID.
	 * @param WP_Post $post     Post object.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 1.0.1.
	 */
	public static function save_subscription_recipient_meta( $post_id, $post ) {

		if ( 'shop_subscription' !== WC_Data_Store::load( 'subscription' )->get_order_type( $post_id ) || empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			return;
		}

		$recipient_user         = empty( $_POST['recipient_user'] ) ? '' : absint( $_POST['recipient_user'] );
		$subscription           = wcs_get_subscription( $post_id );
		$customer_user          = $subscription->get_user_id();
		$is_gifted_subscription = WCS_Gifting::is_gifted_subscription( $subscription );

		if ( $recipient_user == $customer_user ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			// Remove the recipient.
			$recipient_user = '';
			wcs_add_admin_notice( __( 'Error saving subscription recipient: customer and recipient cannot be the same. The recipient user has been removed.', 'woocommerce-subscriptions' ), 'error' );
		}

		if ( ( $is_gifted_subscription && WCS_Gifting::get_recipient_user( $subscription ) == $recipient_user ) || ( ! $is_gifted_subscription && empty( $recipient_user ) ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			// Recipient user remains unchanged - do nothing.
			return;
		} elseif ( empty( $recipient_user ) ) {
			WCS_Gifting::delete_recipient_user( $subscription );

			// Delete recipient meta from subscription order items.
			foreach ( $subscription->get_items() as $order_item_id => $order_item ) {
				wc_delete_order_item_meta( $order_item_id, 'wcsg_recipient' );
			}
		} else {
			WCS_Gifting::set_recipient_user( $subscription, $recipient_user );

			// Update all subscription order items.
			foreach ( $subscription->get_items() as $order_item_id => $order_item ) {
				wc_update_order_item_meta( $order_item_id, 'wcsg_recipient', 'wcsg_recipient_id_' . $recipient_user );
			}
		}
	}

	/**
	 * Outputs a welcome message. Called when the Subscriptions extension is activated.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 */
	public static function admin_installed_notice() {

		if ( true == get_transient( 'wcsg_show_activation_notice' ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			wc_get_template( 'activation-notice.php', array( 'settings_tab_url' => self::settings_tab_url() ), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/' );
			delete_transient( 'wcsg_show_activation_notice' );
		}
	}

	/**
	 * A WooCommerce version aware function for getting the Subscriptions/Gifting admin settings tab URL.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.0.
	 * @return string
	 */
	public static function settings_tab_url() {
		return apply_filters( 'woocommerce_subscriptions_settings_tab_url', admin_url( 'admin.php?page=wc-settings&tab=subscriptions' ) );
	}

	/**
	 * Adds a dropdown to the Subscriptions admin screen to allow filtering by gifted subscriptions.
	 *
	 * @param string $post_type Current post type.
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.3.
	 */
	public static function add_gifted_subscriptions_filter( $post_type = '' ) {
		$post_type = ! empty( $post_type ) ? $post_type : $GLOBALS['typenow'];

		if ( 'shop_subscription' !== $post_type ) {
			return;
		}

		$gifted_subscriptions = WCS_Gifting::get_gifted_subscriptions(
			array(
				'limit'  => 1,
				'return' => 'ids',
			)
		);
		if ( empty( $gifted_subscriptions ) ) {
			return;
		}

		$options        = array(
			''      => __( 'All Subscriptions', 'woocommerce-subscriptions' ),
			'true'  => __( 'Gifted Subscriptions', 'woocommerce-subscriptions' ),
			'false' => __( 'Non-gifted subscriptions', 'woocommerce-subscriptions' ),
		);
		$wcsg_is_gifted = isset( $_GET['wcsg_is_gifted'] ) ? $_GET['wcsg_is_gifted'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		echo '<select class="wcsg_is_gifted_selector last" name="wcsg_is_gifted" id="wcsg_is_gifted">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $value, $wcsg_is_gifted, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Filters the main admin query to include only gifted or non-gifted subscriptions.
	 *
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.3.
	 */
	public static function maybe_filter_by_gifted_subscriptions( $query ) {
		global $typenow;

		if ( ! is_admin() || ! $query->is_main_query() || 'shop_subscription' !== $typenow || ! isset( $_GET['wcsg_is_gifted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$wcsg_is_gifted = trim( $_GET['wcsg_is_gifted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		$compare = '';
		if ( 'true' === $wcsg_is_gifted ) {
			$compare = 'EXISTS';
		} elseif ( 'false' === $wcsg_is_gifted ) {
			$compare = 'NOT EXISTS';
		}

		if ( ! $compare ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! $meta_query ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'key'     => '_recipient_user',
			'compare' => $compare,
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Filter the Subscriptions Admin Table to filter only gifted or non-gifted subscriptions.
	 *
	 * @param array $request_query The query args sent to wc_get_orders().
	 */
	public static function filter_subscription_list_table_by_gifted_subscriptions( $request_query ) {
		/**
		 * Note this request isn't nonced as we're only filtering a list table and not modifying data.
		 */
		if ( ! isset( $_GET['wcsg_is_gifted'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $request_query;
		}

		$wcsg_is_gifted = trim( $_GET['wcsg_is_gifted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( ! in_array( $wcsg_is_gifted, array( 'true', 'false' ), true ) ) {
			return $request_query;
		}

		$request_query['meta_query'][] = array(
			'key'     => '_recipient_user',
			'compare' => 'true' === $wcsg_is_gifted ? 'EXISTS' : 'NOT EXISTS',
		);

		return $request_query;
	}

	/**
	 * Adds actions to the admin edit subscriptions page, if the subscription is a gifted one.
	 *
	 * @param array $actions Current admin actions.
	 * @return array $actions The subscription actions with the "Renew Now" action added if it's permitted.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	public static function add_resend_new_recipient_account_email_action( $actions ) {
		global $theorder;

		if ( WCS_Gifting::is_gifted_subscription( $theorder ) ) {
			$actions['wcsg_resend_new_recipient_account_email'] = __( 'Resend "new recipient account" email', 'woocommerce-subscriptions' );
		}

		return $actions;
	}

	/**
	 * Resends the "new recipient" e-mail.
	 *
	 * @param WC_Order $subscription Subscription object.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	public static function resend_new_recipient_account_email( $subscription ) {
		if ( WCS_Gifting::is_gifted_subscription( $subscription->get_id() ) ) {
			WCSG_Email::resend_new_recipient_user_email( $subscription );
		}
	}

	/**
	 * Get enable gifting setting.
	 *
	 * @return bool
	 */
	public static function is_gifting_enabled() {
		$global_enable_gifting = get_option( self::$option_prefix . '_enable_gifting', 'no' );

		return apply_filters( 'wcsg_enable_gifting', 'yes' === $global_enable_gifting );
	}

	/**
	 * Get if gifting is enabled by default for all products.
	 *
	 * @return bool
	 */
	public static function is_gifting_enabled_for_all_products() {
		$global_default_option = get_option( self::$option_prefix . '_default_option', 'disabled' );

		return apply_filters( 'wcsg_is_enabled_for_all_products', 'enabled' === $global_default_option );
	}

	/**
	 * Get the text for the gifting option.
	 *
	 * @return string
	 */
	public static function get_gifting_option_text() {
		return self::is_gifting_enabled_for_all_products()
			? __( 'Follow global setting (enabled)', 'woocommerce-subscriptions' )
			: __( 'Follow global setting (disabled)', 'woocommerce-subscriptions' );
	}

	/**
	 * Get the text for the gifting option.
	 */
	public static function get_gifting_global_override_text() {
		?>
		<p class="_subscription_gifting_field_description form-field">
			<span class="description">
				<?php
				/* translators: %1$s opening anchor tag with url, %2$s closing anchor tag */
				$gifting_description = __( 'Overriding your %1$sstore\'s settings%2$s', 'woocommerce-subscriptions' );

				echo wp_kses_post(
					sprintf(
						$gifting_description,
						'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=subscriptions#woocommerce_subscriptions_gifting_enable_gifting' ) . '">',
						'</a>'
					)
				);
				?>
			</span>
		</p>
		<?php
	}
}
