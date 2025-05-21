<?php
/**
 * WooCommerce Subscriptions Admin Post Types.
 *
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WCS_Admin_Post_Types' ) ) {
	return;
}

/**
 * WC_Admin_Post_Types Class
 *
 * Handles the edit posts views and some functionality on the edit post screen for WC post types.
 */
class WCS_Admin_Post_Types {

	/**
	 * The value to use for the 'post__in' query param when no results should be returned.
	 *
	 * We can't use an empty array, because WP returns all posts when post__in is an empty
	 * array. Source: https://core.trac.wordpress.org/ticket/28099
	 *
	 * This would ideally be a private CONST but visibility modifiers are only allowed for
	 * class constants in PHP >= 7.1.
	 *
	 * @var array
	 */
	private static $post__in_none = array( 0 );

	/**
	 * Constructor
	 */
	public function __construct() {
		// Subscription list table columns and their content
		add_filter( 'manage_edit-shop_subscription_columns', array( $this, 'shop_subscription_columns' ) );
		add_filter( 'manage_edit-shop_subscription_sortable_columns', array( $this, 'shop_subscription_sortable_columns' ) );
		add_action( 'manage_shop_subscription_posts_custom_column', array( $this, 'render_shop_subscription_columns' ), 2, 2 );

		add_filter( 'woocommerce_shop_subscription_list_table_columns', array( $this, 'shop_subscription_columns' ) );
		add_filter( 'woocommerce_shop_subscription_list_table_sortable_columns', array( $this, 'shop_subscription_sortable_columns' ) );
		add_action( 'woocommerce_shop_subscription_list_table_custom_column', array( $this, 'render_shop_subscription_columns' ), 2, 2 );

		// Bulk actions
		// CPT based screens
		add_filter( 'bulk_actions-edit-shop_subscription', array( $this, 'filter_bulk_actions' ) );
		add_action( 'load-edit.php', array( $this, 'parse_bulk_actions' ) );
		// HPOS based screens
		add_filter( 'bulk_actions-woocommerce_page_wc-orders--shop_subscription', array( $this, 'filter_bulk_actions' ) );

		add_action( 'admin_notices', array( $this, 'bulk_admin_notices' ) );

		// Subscription order/filter
		add_filter( 'request', array( $this, 'request_query' ) );
		add_filter( 'woocommerce_shop_subscription_list_table_request', array( $this, 'add_subscription_list_table_query_default_args' ) );
		add_filter( 'woocommerce_shop_subscription_list_table_prepare_items_query_args', array( $this, 'filter_subscription_list_table_request_query' ) );

		// Subscription Search
		add_filter( 'get_search_query', array( $this, 'shop_subscription_search_label' ) );
		add_filter( 'query_vars', array( $this, 'add_custom_query_var' ) );
		add_action( 'parse_query', array( $this, 'shop_subscription_search_custom_fields' ) );

		add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );
		add_filter( 'woocommerce_order_updated_messages', array( $this, 'post_updated_messages' ) );

		// Add ListTable filters when CPT is enabled
		add_action( 'restrict_manage_posts', array( $this, 'restrict_by_product' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_by_payment_method' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_by_customer' ) );

		// Add ListTable filters when HPOS is enabled
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'restrict_by_product' ) );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'restrict_by_payment_method' ) );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'restrict_by_customer' ) );

		// Add Subscription list table status views when HPOS is enabled.
		add_filter( 'views_woocommerce_page_wc-orders--shop_subscription', array( $this, 'filter_subscription_list_table_views' ) );

		add_action( 'list_table_primary_column', array( $this, 'list_table_primary_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'shop_subscription_row_actions' ), 10, 2 );

		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders--shop_subscription', [ $this, 'handle_subscription_bulk_actions' ], 10, 3 );
	}

	/**
	 * Modifies the actual SQL that is needed to order by last payment date on subscriptions. Data is pulled from related
	 * but independent posts, so subqueries are needed. That's something we can't get by filtering the request. This is hooked
	 * in @see WCS_Admin_Post_Types::request_query function.
	 *
	 * @param  array    $pieces all the pieces of the resulting SQL once WordPress has finished parsing it
	 * @param  WP_Query $query  the query object that forms the basis of the SQL
	 * @return array modified pieces of the SQL query
	 */
	public function posts_clauses( $pieces, $query ) {
		global $wpdb;

		if ( ! is_admin() || ! isset( $query->query['post_type'] ) || 'shop_subscription' !== $query->query['post_type'] ) {
			return $pieces;
		}

		// Let's check whether we even have the privileges to do the things we want to do
		if ( $this->is_db_user_privileged() ) {
			$pieces = self::posts_clauses_high_performance( $pieces );
		} else {
			$pieces = self::posts_clauses_low_performance( $pieces );
		}

		$order = strtoupper( $query->query['order'] );

		// fields and order are identical in both cases
		$pieces['fields'] .= ', COALESCE(lp.last_payment, o.post_date_gmt, 0) as lp';
		$pieces['orderby'] = "CAST(lp AS DATETIME) {$order}";

		return $pieces;
	}

	/**
	 * Check is database user is capable of doing high performance things, such as creating temporary tables,
	 * indexing them, and then dropping them after.
	 *
	 * @return bool
	 */
	public function is_db_user_privileged() {
		$permissions = $this->get_special_database_privileges();

		return ( in_array( 'CREATE TEMPORARY TABLES', $permissions, true ) && in_array( 'INDEX', $permissions, true ) && in_array( 'DROP', $permissions, true ) );
	}

	/**
	 * Return the privileges a database user has out of CREATE TEMPORARY TABLES, INDEX and DROP. This is so we can use
	 * these discrete values on a debug page.
	 *
	 * @return array
	 */
	public function get_special_database_privileges() {
		global $wpdb;

		$permissions = $wpdb->get_col( "SELECT PRIVILEGE_TYPE FROM information_schema.user_privileges WHERE GRANTEE = CONCAT( '''', REPLACE( CURRENT_USER(), '@', '''@''' ), '''' ) AND PRIVILEGE_TYPE IN ('CREATE TEMPORARY TABLES', 'INDEX', 'DROP')" );

		return $permissions;
	}

	/**
	 * Modifies the query for a slightly faster, yet still pretty slow query in case the user does not have
	 * the necessary privileges to run
	 *
	 * @param $pieces
	 *
	 * @return mixed
	 */
	private function posts_clauses_low_performance( $pieces ) {
		global $wpdb;

		$pieces['join'] .= "LEFT JOIN
				(SELECT
					MAX( p.post_date_gmt ) as last_payment,
					pm.meta_value
				FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_subscription_renewal'
				GROUP BY pm.meta_value) lp
			ON {$wpdb->posts}.ID = lp.meta_value
			LEFT JOIN {$wpdb->posts} o on {$wpdb->posts}.post_parent = o.ID";

		return $pieces;
	}

	/**
	 * Modifies the query in such a way that makes use of the CREATE TEMPORARY TABLE, DROP and INDEX
	 * MySQL privileges.
	 *
	 * @param array $pieces
	 *
	 * @return array $pieces
	 */
	private function posts_clauses_high_performance( $pieces ) {
		global $wpdb;

		// in case multiple users sort at the same time
		$session = wp_get_session_token();

		$table_name = substr( "{$wpdb->prefix}tmp_{$session}_lastpayment", 0, 64 );

		// Let's create a temporary table, drop the previous one, because otherwise this query is hella slow
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"CREATE TEMPORARY TABLE {$table_name} (id INT PRIMARY KEY, last_payment DATETIME) AS
			 SELECT pm.meta_value as id, MAX( p.post_date_gmt ) as last_payment FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_subscription_renewal'
			 GROUP BY pm.meta_value"
		);
		// Magic ends here

		$pieces['join'] .= "LEFT JOIN {$table_name} lp
			ON {$wpdb->posts}.ID = lp.id
			LEFT JOIN {$wpdb->posts} o on {$wpdb->posts}.post_parent = o.ID";

		return $pieces;
	}

	/**
	 * Displays the dropdown for the product filter
	 *
	 * @param string $order_type The type of order. This will be 'shop_subscription' for Subscriptions.
	 *
	 * @return string the html dropdown element
	 */
	public function restrict_by_product( $order_type = '' ) {
		if ( '' === $order_type ) {
			$order_type = isset( $GLOBALS['typenow'] ) ? $GLOBALS['typenow'] : '';
		}

		if ( 'shop_subscription' !== $order_type ) {
			return;
		}

		$product_id     = '';
		$product_string = '';

		if ( ! empty( $_GET['_wcs_product'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product_id     = absint( $_GET['_wcs_product'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product_string = wc_get_product( $product_id )->get_formatted_name();
		}

		WCS_Select2::render(
			array(
				'class'       => 'wc-product-search',
				'name'        => '_wcs_product',
				'placeholder' => esc_attr__( 'Search for a product&hellip;', 'woocommerce-subscriptions' ),
				'action'      => 'woocommerce_json_search_products_and_variations',
				'selected'    => wp_strip_all_tags( $product_string ),
				'value'       => $product_id,
				'allow_clear' => 'true',
			)
		);
	}

	/**
	 * Remove "edit" from the bulk actions.
	 *
	 * @param array $actions
	 * @return array
	 */
	public function remove_bulk_actions( $actions ) {

		if ( isset( $actions['edit'] ) ) {
			unset( $actions['edit'] );
		}

		return $actions;
	}

	/**
	 * Alters the default bulk actions for the subscription object type.
	 *
	 * Removes the default "edit", "mark_processing", "mark_on-hold", "mark_completed", "mark_cancelled" options from the bulk actions.
	 * Adds subscription-related actions for activating, suspending and cancelling.
	 *
	 * @param array $actions An array of bulk actions admin users can take on subscriptions. In the format ( 'name' => 'i18n_text' ).
	 * @return array The bulk actions.
	 */
	public function filter_bulk_actions( $actions ) {
		/**
		 * Get the status that the list table is being filtered by.
		 * The 'post_status' key is used for CPT datastores, 'status' is used for HPOS datastores.
		 *
		 * Note: The nonce check is ignored below as there is no nonce provided on status filter requests and it's not necessary
		 * because we're filtering an admin screen, not processing or acting on the data.
		 */
		$status_filter = sanitize_key( wp_unslash( $_GET['post_status'] ?? $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// List of actions to remove that are irrelevant to subscriptions.
		$actions_to_remove = [
			'edit',
			'mark_processing',
			'mark_on-hold',
			'mark_completed',
			'mark_cancelled',
			'remove_personal_data',
			'trash',
			'delete',
			'untrash',
		];

		// Remove actions that are not relevant to subscriptions.
		$actions = array_diff_key( $actions, array_flip( $actions_to_remove ) );

		// If we are currently in expired or cancelled listing. We only need to add specific subscriptions actions.
		if ( in_array( $status_filter, [ 'wc-cancelled', 'wc-expired' ], true ) ) {
			$actions = array_merge(
				$actions,
				[
					'trash_subscriptions' => _x( 'Move to Trash', 'an action on a subscription', 'woocommerce-subscriptions' ),
				]
			);

			return $actions;
		} elseif ( 'trash' === $status_filter ) {
			$actions = array_merge(
				$actions,
				[
					'untrash_subscriptions' => _x( 'Restore', 'an action on a subscription', 'woocommerce-subscriptions' ),
					'delete_subscriptions'  => _x( 'Delete Permanently', 'an action on a subscription', 'woocommerce-subscriptions' ),
				]
			);

			return $actions;
		}

		/**
		 * Subscriptions bulk actions filter.
		 *
		 * This is a filterable array where the key is the action (will become query arg), and the value is a translatable
		 * string.
		 *
		 * @since 1.0.0 - Moved over from WooCommerce Subscriptions prior to 4.0.0
		 */
		$subscriptions_actions = apply_filters(
			'woocommerce_subscription_bulk_actions',
			[
				'active'              => _x( 'Activate', 'an action on a subscription', 'woocommerce-subscriptions' ),
				'on-hold'             => _x( 'Put on-hold', 'an action on a subscription', 'woocommerce-subscriptions' ),
				'cancelled'           => _x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ),
				'trash_subscriptions' => _x( 'Move to Trash', 'an action on a subscription', 'woocommerce-subscriptions' ),
			]
		);

		$actions = array_merge( $actions, $subscriptions_actions );

		// No need to display certain bulk actions if we know all the subscriptions on the page have that status already.
		switch ( $status_filter ) {
			case 'wc-active':
				unset( $actions['active'] );
				break;
			case 'wc-on-hold':
				unset( $actions['on-hold'] );
				break;
		}

		return $actions;
	}

	/**
	 * Deals with bulk actions. The style is similar to what WooCommerce is doing. Extensions will have to define their
	 * own logic by copying the concept behind this method.
	 */
	public function parse_bulk_actions() {

		// We only want to deal with shop_subscriptions. In case any other CPTs have an 'active' action
		if ( ! isset( $_REQUEST['post_type'] ) || 'shop_subscription' !== $_REQUEST['post_type'] || ! isset( $_REQUEST['post'] ) ) {
			return;
		}

		// Verify the nonce before proceeding, using the bulk actions nonce name as defined in WP core.
		check_admin_referer( 'bulk-posts' );

		$action = '';

		if ( isset( $_REQUEST['action'] ) && -1 != $_REQUEST['action'] ) { // phpcs:ignore
			$action = wc_clean( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && -1 != $_REQUEST['action2'] ) { // phpcs:ignore
			$action = wc_clean( wp_unslash( $_REQUEST['action2'] ) );
		}

		if ( ! in_array( $action, [ 'active', 'on-hold', 'cancelled', 'trash_subscriptions', 'untrash_subscriptions', 'delete_subscriptions' ], true ) ) {
			return;
		}

		$subscription_ids  = array_map( 'absint', (array) $_REQUEST['post'] );
		$base_redirect_url = wp_get_referer() ? wp_get_referer() : '';
		$redirect_url      = $this->handle_subscription_bulk_actions( $base_redirect_url, $action, $subscription_ids );

		wp_safe_redirect( $redirect_url );
		exit();
	}

	/**
	 * Shows confirmation message that subscription statuses were changed via bulk action.
	 */
	public function bulk_admin_notices() {
		$is_subscription_list_table = false;

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$current_screen             = get_current_screen();
			$is_subscription_list_table = $current_screen && wcs_get_page_screen_id( 'shop_subscription' ) === $current_screen->id;
		} else {
			global $post_type, $pagenow;
			$is_subscription_list_table = 'edit.php' === $pagenow && 'shop_subscription' === $post_type;
		}

		// Bail out if not on shop subscription list page.
		if ( ! $is_subscription_list_table ) {
			return;
		}

		/**
		 * If the action isn't set, return early.
		 *
		 * Note: Nonce verification is not required here because we're just displaying an admin notice after a verified request was made.
		 */
		if ( ! isset( $_REQUEST['bulk_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$number = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$admin_notice = new WCS_Admin_Notice( 'updated' );
		$admin_notice->set_simple_content(
			sprintf(
				// translators: placeholder is the number of subscriptions updated
				_n( '%s subscription status changed.', '%s subscription statuses changed.', $number, 'woocommerce-subscriptions' ),
				number_format_i18n( $number )
			)
		);
		$admin_notice->display();

		/**
		 * Display an admin notice for any errors that occurred processing the bulk action
		 *
		 * Note: Nonce verification is ignored as we're not acting on any data from the request. We're simply displaying a message.
		 */
		if ( ! empty( $_REQUEST['error_count'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_message = isset( $_REQUEST['error'] ) ? wc_clean( wp_unslash( $_REQUEST['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_count   = isset( $_REQUEST['error_count'] ) ? absint( $_REQUEST['error_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$admin_notice = new WCS_Admin_Notice( 'error' );
			$admin_notice->set_simple_content(
				sprintf(
					// translators: 1$: is the number of subscriptions not updated, 2$: is the error message
					_n( '%1$s subscription could not be updated: %2$s', '%1$s subscriptions could not be updated: %2$s', $error_count, 'woocommerce-subscriptions' ),
					number_format_i18n( $error_count ),
					$error_message
				)
			);
			$admin_notice->display();
		}

		// Remove the query args which flags this bulk action request so WC doesn't duplicate the notice and so links generated on this page don't contain these flags.
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = remove_query_arg( [ 'error_count', 'error', 'bulk_action', 'changed', 'ids' ], esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}
		unset( $_REQUEST['ids'], $_REQUEST['bulk_action'], $_REQUEST['changed'], $_REQUEST['error_count'], $_REQUEST['error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Define custom columns for subscription
	 *
	 * Column names that have a corresponding `WC_Order` column use the `order_` prefix here
	 * to take advantage of core WooCommerce assets, like JS/CSS.
	 *
	 * @param  array $existing_columns
	 * @return array
	 */
	public function shop_subscription_columns( $existing_columns ) {

		$columns = array(
			'cb'                => '<input type="checkbox" />',
			'status'            => __( 'Status', 'woocommerce-subscriptions' ),
			'order_title'       => __( 'Subscription', 'woocommerce-subscriptions' ),
			'order_items'       => __( 'Items', 'woocommerce-subscriptions' ),
			'recurring_total'   => __( 'Total', 'woocommerce-subscriptions' ),
			'start_date'        => __( 'Start Date', 'woocommerce-subscriptions' ),
			'trial_end_date'    => __( 'Trial End', 'woocommerce-subscriptions' ),
			'next_payment_date' => __( 'Next Payment', 'woocommerce-subscriptions' ),
			'last_payment_date' => __( 'Last Order Date', 'woocommerce-subscriptions' ), // Keep deprecated 'last_payment_date' key for backward compatibility
			'end_date'          => __( 'End Date', 'woocommerce-subscriptions' ),
			'orders'            => _x( 'Orders', 'number of orders linked to a subscription', 'woocommerce-subscriptions' ),
		);

		return $columns;
	}

	/**
	 * Outputs column content for the admin subscriptions list table.
	 *
	 * @param string       $column       The column name.
	 * @param WC_Order|int $subscription Optional. The subscription being displayed. Defaults to the global $post object.
	 */
	public function render_shop_subscription_columns( $column, $subscription = null ) {
		global $post, $the_subscription;

		// Attempt to get the subscription ID for the current row from the passed variable or the global $post object.
		if ( ! empty( $subscription ) ) {
			$subscription_id = is_int( $subscription ) ? $subscription : $subscription->get_id();
		} else {
			$subscription_id = $post->ID;
		}

		// If we have a subscription ID, set the global $the_subscription object.
		if ( empty( $the_subscription ) || $the_subscription->get_id() !== $subscription_id ) {
			$the_subscription = wcs_get_subscription( $subscription_id );
		}

		// If the subscription failed to load, only display the ID.
		if ( empty( $the_subscription ) ) {
			if ( 'order_title' !== $column ) {
				echo '&mdash;';
				return;
			}

			// translators: placeholder is a subscription ID.
			echo '<strong>' . sprintf( esc_html_x( '#%s', 'hash before subscription number', 'woocommerce-subscriptions' ), esc_html( $subscription_id ) ) . '</strong>';

			/**
			 * Display a help tip to explain why the subscription couldn't be loaded.
			 *
			 * Note: The wcs_help_tip() call below is not escaped here because the contents of the tip is escaped in the function via wc_help_tip() which uses esc_attr().
			 */
			echo sprintf(
				'<div class="%1$s"><a href="%2$s">%3$s</a></div>',
				'wcs-unknown-order-info-wrapper',
				esc_url( 'https://woocommerce.com/document/subscriptions/store-manager-guide/#section-19' ),
				// translators: Placeholder is a <br> HTML tag.
				wcs_help_tip( sprintf( __( "This subscription couldn't be loaded from the database. %s Click to learn more.", 'woocommerce-subscriptions' ), '</br>' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			return;
		}

		$column_content = '';

		switch ( $column ) {
			case 'status':
				// The status label.
				$column_content = sprintf(
					'<mark class="subscription-status order-status status-%1$s %1$s tips" data-tip="%2$s"><span>%2$s</span></mark>',
					sanitize_title( $the_subscription->get_status() ),
					wcs_get_subscription_status_name( $the_subscription->get_status() )
				);

				$actions = self::get_subscription_list_table_actions( $the_subscription );

				// Display the subscription quick actions links.
				$action_links = [];
				foreach ( $actions as $action_name => $action_url ) {
					$action_links[] = sprintf(
						'<span class="%1$s">%2$s</span>',
						esc_attr( $action_name ),
						$action_url
					);
				}

				$column_content .= sprintf( '<div class="row-actions">%s</div>', implode( ' | ', $action_links ) );

				$column_content = apply_filters( 'woocommerce_subscription_list_table_column_status_content', $column_content, $the_subscription, $actions );
				break;

			case 'order_title':
				$customer_tip = '';

				$address = $the_subscription->get_formatted_billing_address();
				if ( $address ) {
					$customer_tip .= _x( 'Billing:', 'meaning billing address', 'woocommerce-subscriptions' ) . ' ' . esc_html( $address );
				}

				if ( $the_subscription->get_billing_email() ) {
					// translators: placeholder is customer's billing email
					$customer_tip .= '<br/><br/>' . sprintf( __( 'Email: %s', 'woocommerce-subscriptions' ), esc_attr( $the_subscription->get_billing_email() ) );
				}

				if ( $the_subscription->get_billing_phone() ) {
					// translators: placeholder is customer's billing phone number
					$customer_tip .= '<br/><br/>' . sprintf( __( 'Tel: %s', 'woocommerce-subscriptions' ), esc_html( $the_subscription->get_billing_phone() ) );
				}

				if ( ! empty( $customer_tip ) ) {
					echo '<div class="tips" data-tip="' . wc_sanitize_tooltip( $customer_tip ) . '">'; // phpcs:ignore Standard.Category.SniffName.ErrorCode
				}

				// This is to stop PHP from complaining
				$username = '';

				$user_info = get_userdata( $the_subscription->get_user_id() );
				if ( $the_subscription->get_user_id() && ( false !== $user_info ) ) {

					$username = '<a href="user-edit.php?user_id=' . absint( $user_info->ID ) . '">';

					if ( $the_subscription->get_billing_first_name() || $the_subscription->get_billing_last_name() ) {
						$username .= esc_html( ucfirst( $the_subscription->get_billing_first_name() ) . ' ' . ucfirst( $the_subscription->get_billing_last_name() ) );
					} elseif ( $user_info->first_name || $user_info->last_name ) {
						$username .= esc_html( ucfirst( $user_info->first_name ) . ' ' . ucfirst( $user_info->last_name ) );
					} else {
						$username .= esc_html( ucfirst( $user_info->display_name ) );
					}

					$username .= '</a>';

				} elseif ( $the_subscription->get_billing_first_name() || $the_subscription->get_billing_last_name() ) {
					$username = trim( $the_subscription->get_billing_first_name() . ' ' . $the_subscription->get_billing_last_name() );
				}

				$column_content = sprintf(
					// translators: $1: is opening link, $2: is subscription order number, $3: is closing link tag, $4: is user's name
					_x( '%1$s#%2$s%3$s for %4$s', 'Subscription title on admin table. (e.g.: #211 for John Doe)', 'woocommerce-subscriptions' ),
					'<a href="' . esc_url( $the_subscription->get_edit_order_url() ) . '">',
					'<strong>' . esc_attr( $the_subscription->get_order_number() ) . '</strong>',
					'</a>',
					$username
				);

				$column_content .= '</div>';

				$column_content .= '<button type="button" class="toggle-row"><span class="screen-reader-text">' . __( 'Show more details', 'woocommerce-subscriptions' ) . '</span></button>';

				break;
			case 'order_items':
				// Display either the item name or item count with a collapsed list of items
				$subscription_items = $the_subscription->get_items();
				switch ( count( $subscription_items ) ) {
					case 0:
						$column_content .= '&ndash;';
						break;
					case 1:
						foreach ( $subscription_items as $item ) {
							$item_name      = wp_kses( self::get_item_name_html( $item, $item->get_product() ), array( 'a' => array( 'href' => array() ) ) );
							$item_meta_html = self::get_item_meta_html( $item );
							$meta_help_tip  = $item_meta_html ? wcs_help_tip( $item_meta_html, true ) : '';

							$column_content .= sprintf( '<div class="order-item">%s%s</div>', $item_name, $meta_help_tip );
						}
						break;
					default:
						// translators: %d: item count.
						$column_content .= '<a href="#" class="show_order_items">' . esc_html( apply_filters( 'woocommerce_admin_order_item_count', sprintf( _n( '%d item', '%d items', $the_subscription->get_item_count(), 'woocommerce-subscriptions' ), $the_subscription->get_item_count() ), $the_subscription ) ) . '</a>';
						$column_content .= '<table class="order_items" cellspacing="0">';

						foreach ( $subscription_items as $item ) {
							$item_name      = self::get_item_name_html( $item, $item->get_product(), 'do_not_include_quantity' );
							$item_meta_html = self::get_item_meta_html( $item );

							$column_content .= self::get_item_display_row( $item, $item_name, $item_meta_html );
						}

						$column_content .= '</table>';
						break;
				}
				break;

			case 'recurring_total':
				$column_content .= esc_html( wp_strip_all_tags( $the_subscription->get_formatted_order_total() ) );
				$column_content .= '<small class="meta">';
				// translators: placeholder is the display name of a payment gateway a subscription was paid by
				$column_content .= esc_html( sprintf( __( 'Via %s', 'woocommerce-subscriptions' ), $the_subscription->get_payment_method_to_display() ) );

				if ( WCS_Staging::is_duplicate_site() && $the_subscription->has_payment_gateway() && ! $the_subscription->get_requires_manual_renewal() ) {
					$column_content .= WCS_Staging::get_payment_method_tooltip( $the_subscription );
				}

				$column_content .= '</small>';
				break;

			case 'start_date':
			case 'trial_end_date':
			case 'next_payment_date':
			case 'last_payment_date':
			case 'end_date':
				$column_content = self::get_date_column_content( $the_subscription, $column );
				break;

			case 'orders':
				$column_content .= $this->get_related_orders_link( $the_subscription );
				break;
		}

		echo wp_kses( apply_filters( 'woocommerce_subscription_list_table_column_content', $column_content, $the_subscription, $column ), array( 'a' => array( 'class' => array(), 'href' => array(), 'data-tip' => array(), 'title' => array() ), 'time' => array( 'class' => array(), 'title' => array() ), 'mark' => array( 'class' => array(), 'data-tip' => array() ), 'small' => array( 'class' => array() ), 'table' => array( 'class' => array(), 'cellspacing' => array(), 'cellpadding' => array() ), 'tr' => array( 'class' => array() ), 'td' => array( 'class' => array() ), 'div' => array( 'class' => array(), 'data-tip' => array() ), 'br' => array(), 'strong' => array(), 'span' => array( 'class' => array(), 'data-tip' => array() ), 'p' => array( 'class' => array() ), 'button' => array( 'type' => array(), 'class' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

	}

	/**
	 * Return the content for a date column on the Edit Subscription screen
	 *
	 * @param WC_Subscription $subscription
	 * @param string $column
	 * @return string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public static function get_date_column_content( $subscription, $column ) {
		$date_type_map = array( 'last_payment_date' => 'last_order_date_created' );
		$date_type     = array_key_exists( $column, $date_type_map ) ? $date_type_map[ $column ] : $column;

		if ( 'last_payment_date' === $column ) {
			$date_timestamp = self::get_last_payment_date( $subscription );
		} else {
			$date_timestamp = $subscription->get_time( $date_type );
		}

		if ( 0 === $date_timestamp ) {
			return '-';
		}

		$datetime         = wcs_get_datetime_from( $date_timestamp );
		$accurate_date    = $datetime->date_i18n( __( 'Y/m/d g:i:s A', 'woocommerce-subscriptions' ) );
		$fuzzy_human_date = $subscription->format_date_to_display( $date_timestamp, $date_type );
		$column_content   = sprintf(
			'<time class="%s" title="%s">%s</time>',
			esc_attr( $column ),
			esc_attr( $accurate_date ),
			esc_html( $fuzzy_human_date )
		);

		// Custom handling for `Next payment` date column.
		if ( 'next_payment_date' === $column && $subscription->has_status( 'active' ) ) {
			$tooltip_message = '';
			$tooltip_classes = 'woocommerce-help-tip';

			if ( $datetime->getTimestamp() < time() ) {
				$tooltip_message .= __( '<b>Subscription payment overdue.</b></br>', 'woocommerce-subscriptions' );
				$tooltip_classes .= ' wcs-payment-overdue';
			}

			if ( $subscription->payment_method_supports( 'gateway_scheduled_payments' ) && ! $subscription->is_manual() ) {
				$tooltip_message .= __( 'This date should be treated as an estimate only. The payment gateway for this subscription controls when payments are processed.</br>', 'woocommerce-subscriptions' );
				$tooltip_classes .= ' wcs-offsite-renewal';
			}

			if ( $tooltip_message ) {
				$column_content .= '<div class="' . esc_attr( $tooltip_classes ) . '" data-tip="' . esc_attr( $tooltip_message ) . '"></div>';
			}
		}

		return $column_content;
	}

	/**
	 * Make columns sortable
	 *
	 * @param array $columns
	 * @return array
	 */
	public function shop_subscription_sortable_columns( $columns ) {

		$sortable_columns = array(
			'order_title'       => 'ID',
			'recurring_total'   => 'order_total',
			'start_date'        => 'start_date',
			'trial_end_date'    => 'trial_end_date',
			'next_payment_date' => 'next_payment_date',
			'last_payment_date' => 'last_payment_date',
			'end_date'          => 'end_date',
		);

		return wp_parse_args( $sortable_columns, $columns );
	}

	/**
	 * Search custom fields as well as content.
	 *
	 * @param WP_Query $wp
	 * @return void
	 */
	public function shop_subscription_search_custom_fields( $wp ) {
		global $pagenow, $wpdb;

		if ( 'edit.php' !== $pagenow || empty( $wp->query_vars['s'] ) || 'shop_subscription' !== $wp->query_vars['post_type'] ) {
			return;
		}

		$post_ids = isset( $_GET['s'] ) ? wcs_subscription_search( wc_clean( wp_unslash( $_GET['s'] ) ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! empty( $post_ids ) ) {

			// Remove s - we don't want to search order name
			unset( $wp->query_vars['s'] );

			// so we know we're doing this
			$wp->query_vars['shop_subscription_search'] = true;

			// Search by found posts
			$wp->query_vars['post__in'] = $post_ids;
		}
	}

	/**
	 * Change the label when searching orders.
	 *
	 * @param mixed $query
	 * @return string
	 */
	public function shop_subscription_search_label( $query ) {
		global $pagenow, $typenow;

		if ( 'edit.php' !== $pagenow ) {
			return $query;
		}

		if ( 'shop_subscription' !== $typenow ) {
			return $query;
		}

		if ( ! get_query_var( 'shop_subscription_search' ) ) {
			return $query;
		}

		return isset( $_GET['s'] ) ? wc_clean( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Query vars for custom searches.
	 *
	 * @param mixed $public_query_vars
	 * @return array
	 */
	public function add_custom_query_var( $public_query_vars ) {
		$public_query_vars[] = 'sku';
		$public_query_vars[] = 'shop_subscription_search';

		return $public_query_vars;
	}

	/**
	 * Filters and sorts the request for subscriptions stored in WP Post tables.
	 *
	 * @param array $vars
	 *
	 * @return array
	 */
	public function request_query( $vars ) {
		global $typenow;

		if ( 'shop_subscription' === $typenow ) {

			// Filter the orders by the customer, product or payment method
			$vars = $this->set_filter_by_customer_query( $vars );
			$vars = $this->set_filter_by_product_query( $vars );
			$vars = $this->set_filter_by_payment_method_query( $vars );

			// Sorting
			if ( isset( $vars['orderby'] ) ) {
				switch ( $vars['orderby'] ) {
					case 'order_total':
						$vars = array_merge(
							$vars,
							[
								'meta_key' => '_order_total', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
								'orderby'  => 'meta_value_num',
							]
						);
						break;
					case 'last_payment_date':
						add_filter( 'posts_clauses', [ $this, 'posts_clauses' ], 10, 2 );
						break;
					case 'start_date':
					case 'trial_end_date':
					case 'next_payment_date':
					case 'end_date':
						$vars = array_merge(
							$vars,
							[
								'meta_key'  => sprintf( '_schedule_%s', str_replace( '_date', '', $vars['orderby'] ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
								'meta_type' => 'DATETIME',
								'orderby'   => 'meta_value',
							]
						);
						break;
				}
			}

			// Status
			if ( empty( $vars['post_status'] ) ) {
				$vars['post_status'] = array_keys( wcs_get_subscription_statuses() );
			}
		}

		return $vars;
	}

	/**
	 * Filters the List Table request for Subscriptions stored in HPOS.
	 *
	 * @since 5.2.0
	 *
	 * @param array $request_query The query args sent to wc_get_orders().
	 *
	 * @return array $request_query
	 */
	public function filter_subscription_list_table_request_query( $request_query ) {
		$request_query = $this->set_filter_by_customer_query( $request_query );
		$request_query = $this->set_filter_by_product_query( $request_query );
		$request_query = $this->set_filter_by_payment_method_query( $request_query );
		$request_query = $this->set_order_by_query_args( $request_query );

		return $request_query;
	}

	/**
	 * Adds default query arguments for displaying subscriptions in the admin list table.
	 *
	 * By default, WC will fetch items to display in the list table by query the DB using
	 * order params (eg order statuses). This function is responsible for making sure the
	 * default request includes required values to return subscriptions.
	 *
	 * @param array $query_args The admin subscription's list table query args.
	 * @return array $query_args
	 */
	public function add_subscription_list_table_query_default_args( $query_args ) {
		/**
		 * Note this request isn't nonced as we're only filtering a list table by status and not modifying data.
		 */
		if ( empty( $query_args['status'] ) || ( isset( $_GET['status'] ) && 'all' === $_GET['status'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query_args['status'] = array_keys( wcs_get_subscription_statuses() );
		}

		return $query_args;
	}

	/**
	 * Checks if the current request is filtering query by customer user and then fetches the subscriptions
	 * that belong to that customer and sets the post__in query var to filter the request.
	 *
	 * @since 5.2.0
	 *
	 * @param array $request_query The query args sent to wc_get_orders().
	 *
	 * @return array $request_query
	 */
	private function set_filter_by_customer_query( $request_query ) {
		/**
		 * Note this request isn't nonced as we're only filtering a list table and not modifying data.
		 */
		if ( ! isset( $_GET['_customer_user'] ) || ! $_GET['_customer_user'] > 0 ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $request_query;
		}

		$customer_id      = absint( $_GET['_customer_user'] ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subscription_ids = apply_filters(
			'wcs_admin_request_query_subscriptions_for_customer',
			WCS_Customer_Store::instance()->get_users_subscription_ids( $customer_id ),
			$customer_id
		);

		return self::set_post__in_query_var( $request_query, $subscription_ids );
	}

	/**
	 * Checks if the current request is filtering query by product and then fetches all subscription IDs for that product
	 * and sets the post__in query var to filter the request for the given array of subscription IDs.
	 *
	 * @since 5.2.0
	 *
	 * @param array $request_query The query args sent to wc_get_orders().
	 *
	 * @return array $request_query
	 */
	private function set_filter_by_product_query( $request_query ) {
		/**
		 * Note this request isn't nonced as we're only filtering a list table by product ID and not modifying data.
		 */
		if ( ! isset( $_GET['_wcs_product'] ) || ! $_GET['_wcs_product'] > 0 ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $request_query;
		}

		$product_id       = absint( $_GET['_wcs_product'] ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subscription_ids = wcs_get_subscriptions_for_product( $product_id );
		$subscription_ids = apply_filters(
			'wcs_admin_request_query_subscriptions_for_product',
			array_keys( $subscription_ids ),
			$product_id
		);

		return self::set_post__in_query_var( $request_query, $subscription_ids );
	}

	/**
	 * Checks if the current request is filtering query by payment method and then fetches all subscription IDs
	 * for that payment method and sets the post__in query var to filter the request.
	 *
	 * @since 5.2.0
	 *
	 * @param array $request_query The query args sent to wc_get_orders().
	 *
	 * @return array $request_query
	 */
	private function set_filter_by_payment_method_query( $request_query ) {
		/**
		 * If we've using the 'none' flag for the post__in query var, there's no need to apply other query filters, as we're going to return no subscriptions anyway.
		 *
		 * Note this request isn't nonced as we're only filtering a list table by payment method ID and not modifying data.
		 */
		if ( empty( $_GET['_payment_method'] ) || ( isset( $request_query['post__in'] ) && self::$post__in_none === $request_query['post__in'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $request_query;
		}

		$payment_method = wc_clean( wp_unslash( $_GET['_payment_method'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! wcs_is_custom_order_tables_usage_enabled() ) {
			$query_vars = [
				'type'   => 'shop_subscription',
				'limit'  => -1,
				'status' => 'any',
				'return' => 'ids',
			];

			if ( '_manual_renewal' === $payment_method ) {
				$query_vars['meta_query'][] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'key'   => '_requires_manual_renewal',
					'value' => 'true',
				];
			} else {
				$query_vars['payment_method'] = $payment_method;
			}

			// If there are already set post restrictions (post__in) apply them to this query
			if ( isset( $request_query['post__in'] ) ) {
				$query_vars['post__in'] = $request_query['post__in'];
			}

			$subscription_ids = wcs_get_orders_with_meta_query( $query_vars );
			$request_query    = self::set_post__in_query_var( $request_query, $subscription_ids );

		} elseif ( '_manual_renewal' === $payment_method ) {
			$request_query['meta_query'][] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'key'   => '_requires_manual_renewal',
				'value' => 'true',
			];
		} else {
			$request_query['payment_method'] = $payment_method;
		}

		return $request_query;
	}

	/**
	 * Sets the order by query args for the subscriptions list table request on HPOS enabled sites.
	 *
	 * This function is similar to the posts table equivalent function (self::request_query()) except it only sets the order by.
	 *
	 * @param array $request_query The query args sent to wc_get_orders() to populate the list table.
	 * @return array $request_query
	 */
	private function set_order_by_query_args( $request_query ) {

		if ( ! isset( $request_query['orderby'] ) ) {
			return $request_query;
		}

		switch ( $request_query['orderby'] ) {
			case 'last_payment_date':
				add_filter( 'woocommerce_orders_table_query_clauses', [ $this, 'orders_table_query_clauses' ], 10, 3 );
				break;
			case 'start_date':
			case 'trial_end_date':
			case 'next_payment_date':
			case 'end_date':
				$request_query['meta_key'] = sprintf( '_schedule_%s', str_replace( '_date', '', $request_query['orderby'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$request_query['orderby']  = 'meta_value';
				break;
		}

		return $request_query;
	}

	/**
	 * Set the 'post__in' query var with a given set of post ids.
	 *
	 * There are a few special conditions for handling the post__in value. Namely:
	 * - if there are no matching post_ids, the value should be array( 0 ), not an empty array()
	 * - if there are existing IDs in post__in, we only want to return posts with an ID in both
	 *   the existing set and the new set
	 *
	 * While this method is public, it should not be used as it will eventually be deprecated and
	 * it's only made publicly available for other Subscriptions methods until Subscriptions
	 * requires WC 3.0, and can rely on using methods in the data store rather than a hack like
	 * pulling this for use outside of the admin context.
	 *
	 * @param array $query_vars
	 * @param array $post_ids
	 * @return array
	 */
	public static function set_post__in_query_var( $query_vars, $post_ids ) {

		if ( empty( $post_ids ) ) {
			// No posts for this user
			$query_vars['post__in'] = self::$post__in_none;
		} elseif ( ! isset( $query_vars['post__in'] ) ) {
			// No other posts limitations, include all of these posts
			$query_vars['post__in'] = $post_ids;
		} elseif ( self::$post__in_none !== $query_vars['post__in'] ) {
			// Existing post limitation, we only want to include existing IDs that are also in this new set of IDs
			$intersecting_post_ids  = array_intersect( $query_vars['post__in'], $post_ids );
			$query_vars['post__in'] = empty( $intersecting_post_ids ) ? self::$post__in_none : $intersecting_post_ids;
		}

		return $query_vars;
	}

	/**
	 * Change messages when a post type is updated.
	 *
	 * @param  array $messages
	 * @return array
	 */
	public function post_updated_messages( $messages ) {
		global $post, $theorder;

		if ( ! isset( $theorder ) || ! $theorder instanceof WC_Subscription ) {
			if ( ! isset( $post ) || 'shop_subscription' !== $post->post_type ) {
				return $messages;
			} elseif ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
				\Automattic\WooCommerce\Utilities\OrderUtil::init_theorder_object( $post );
			} else {
				return $messages;
			}
		}

		$messages['shop_subscription'] = array(
			0  => '', // Unused. Messages start at index 1.
			1  => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			2  => __( 'Custom field updated.', 'woocommerce-subscriptions' ),
			3  => __( 'Custom field deleted.', 'woocommerce-subscriptions' ),
			4  => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			// translators: placeholder is previous post title
			5  => isset( $_GET['revision'] ) ? sprintf( _x( 'Subscription restored to revision from %s', 'used in post updated messages', 'woocommerce-subscriptions' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false, // // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			6  => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			7  => __( 'Subscription saved.', 'woocommerce-subscriptions' ),
			8  => __( 'Subscription submitted.', 'woocommerce-subscriptions' ),
			// translators: php date string
			9  => sprintf( __( 'Subscription scheduled for: %1$s.', 'woocommerce-subscriptions' ), '<strong>' . date_i18n( _x( 'M j, Y @ G:i', 'used in "Subscription scheduled for <date>"', 'woocommerce-subscriptions' ), strtotime( $theorder->get_date_created() ?? $post->post_date ) ) . '</strong>' ),
			10 => __( 'Subscription draft updated.', 'woocommerce-subscriptions' ),
		);

		return $messages;
	}

	/**
	 * Returns a clickable link that takes you to a collection of orders relating to the subscription.
	 *
	 * @uses  self::get_related_orders()
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @return string the link string
	 */
	public function get_related_orders_link( $the_subscription ) {
		$orders_table_url = wcs_is_custom_order_tables_usage_enabled() ? 'admin.php?page=wc-orders&status=all' : 'edit.php?post_type=shop_order&post_status=all';

		return sprintf(
			'<a href="%s">%s</a>',
			admin_url( $orders_table_url . '&_subscription_related_orders=' . absint( $the_subscription->get_id() ) ),
			count( $the_subscription->get_related_orders() )
		);
	}

	/**
	 * Displays the dropdown for the payment method filter.
	 *
	 * @param string $order_type The type of order. This will be 'shop_subscription' for Subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function restrict_by_payment_method( $order_type = '' ) {
		if ( '' === $order_type ) {
			$order_type = isset( $GLOBALS['typenow'] ) ? $GLOBALS['typenow'] : '';
		}

		if ( 'shop_subscription' !== $order_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_gateway_id = ( ! empty( $_GET['_payment_method'] ) ) ? wc_clean( wp_unslash( $_GET['_payment_method'] ) ) : ''; ?>

		<select class="wcs_payment_method_selector" name="_payment_method" id="_payment_method" class="first">
			<option value=""><?php esc_html_e( 'Any Payment Method', 'woocommerce-subscriptions' ); ?></option>
			<option value="none" <?php echo esc_attr( 'none' === $selected_gateway_id ? 'selected' : '' ) . '>' . esc_html__( 'None', 'woocommerce-subscriptions' ); ?></option>
		<?php

		foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway_id => $gateway ) {
			echo '<option value="' . esc_attr( $gateway_id ) . '"' . ( $selected_gateway_id === $gateway_id ? 'selected' : '' ) . '>' . esc_html( $gateway->title ) . '</option>';
		}
		echo '<option value="_manual_renewal">' . esc_html__( 'Manual Renewal', 'woocommerce-subscriptions' ) . '</option>';
		?>
		</select>
		<?php
	}

	/**
	 * Sets post table primary column subscriptions.
	 *
	 * @param string $default
	 * @param string $screen_id
	 * @return string
	 */
	public function list_table_primary_column( $default, $screen_id ) {

		if ( is_admin() && in_array( $screen_id, [ wcs_get_page_screen_id( 'shop_subscription' ), 'edit-shop_subscription' ], true ) ) {
			$default = 'order_title';
		}

		return $default;
	}

	/**
	 * Don't display default Post actions on Subscription post types (we display our own set of
	 * actions when rendering the column content).
	 *
	 * @param array $actions
	 * @param object $post
	 * @return array
	 */
	public function shop_subscription_row_actions( $actions, $post ) {

		if ( 'shop_subscription' === $post->post_type ) {
			$actions = array();
		}

		return $actions;
	}

	/**
	 * Gets the HTML for a line item's meta to display on the Subscription list table.
	 *
	 * @param WC_Order_Item $item The line item object.
	 * @param mixed         $deprecated
	 *
	 * @return string The line item meta html string generated by @see wc_display_item_meta().
	 */
	protected static function get_item_meta_html( $item, $deprecated = '' ) {
		if ( $deprecated ) {
			wcs_deprecated_argument( __METHOD__, '3.0.7', 'The second parameter (product) is no longer used.' );
		}

		$item_meta_html = wc_display_item_meta(
			$item,
			array(
				'before'    => '',
				'after'     => '',
				'separator' => '',
				'echo'      => false,
			)
		);

		return $item_meta_html;
	}

	/**
	 * Get the HTML for order item meta to display on the Subscription list table.
	 *
	 * @param WC_Order_Item $item
	 * @param WC_Product $product
	 * @return string
	 */
	protected static function get_item_name_html( $item, $_product, $include_quantity = 'include_quantity' ) {

		$item_quantity = absint( $item['qty'] );

		$item_name = '';

		if ( wc_product_sku_enabled() && $_product && $_product->get_sku() ) {
			$item_name .= $_product->get_sku() . ' - ';
		}

		$item_name .= apply_filters( 'woocommerce_order_item_name', $item['name'], $item, false );
		$item_name  = wp_kses_post( $item_name );

		if ( 'include_quantity' === $include_quantity && $item_quantity > 1 ) {
			$item_name = sprintf( '%s &times; %s', absint( $item_quantity ), $item_name );
		}

		if ( $_product ) {
			$item_name = sprintf( '<a href="%s">%s</a>', get_edit_post_link( ( $_product->is_type( 'variation' ) ) ? wcs_get_objects_property( $_product, 'parent_id' ) : $_product->get_id() ), $item_name );
		}

		return $item_name;
	}

	/**
	 * Gets the table row HTML content for a subscription line item.
	 *
	 * On the Subscriptions list table, subscriptions with multiple items display those line items in a table.
	 * This function generates an individual row for a specific line item.
	 *
	 * @param WC_Line_Item_Product $item      The line item product object.
	 * @param string               $item_name The line item's name.
	 * @param string               $item_meta_html The line item's meta HTML generated by @see wc_display_item_meta().
	 *
	 * @return string The table row HTML content for a line item.
	 */
	protected static function get_item_display_row( $item, $item_name, $item_meta_html ) {
		ob_start();
		?>
		<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_admin_order_item_class', '', $item ) ); ?>">
			<td class="qty"><?php echo absint( $item['qty'] ); ?></td>
			<td class="name">
				<?php

				echo wp_kses( $item_name, array( 'a' => array( 'href' => array() ) ) );

				if ( $item_meta_html ) {
					echo esc_html( wcs_help_tip( $item_meta_html, true ) );
				}
				?>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Renders the dropdown for the customer filter.
	 *
	 * @param string $order_type The type of order. This will be 'shop_subscription' for Subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.17
	 */
	public static function restrict_by_customer( $order_type = '' ) {
		if ( '' === $order_type ) {
			$order_type = isset( $GLOBALS['typenow'] ) ? $GLOBALS['typenow'] : '';
		}

		if ( 'shop_subscription' !== $order_type ) {
			return;
		}

		// When HPOS is enabled, WC displays the customer filter so this doesn't need to be duplicated.
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			return;
		}

		$user_string = '';
		$user_id     = '';

		/**
		 * If the user is being filtered, get the user object and set the user string.
		 *
		 * Note: The nonce verification is not required here because we're populating a filter field, not processing a form.
		 */
		if ( ! empty( $_GET['_customer_user'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$user_id = absint( $_GET['_customer_user'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$user    = get_user_by( 'id', $user_id );

			$user_string = sprintf(
				/* translators: 1: user display name 2: user ID 3: user email */
				esc_html__( '%1$s (#%2$s &ndash; %3$s)', 'woocommerce-subscriptions' ),
				$user->display_name,
				absint( $user->ID ),
				$user->user_email
			);
		}
		?>
		<select class="wc-customer-search" name="_customer_user" data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'woocommerce-subscriptions' ); ?>" data-allow_clear="true">
			<option value="<?php echo esc_attr( $user_id ); ?>" selected="selected"><?php echo wp_kses_post( $user_string ); ?></option>
		</select>
		<?php
	}

	/**
	 * Generates the list of actions available on the Subscriptions list table.
	 *
	 * @param WC_Subscription $subscription The subscription to generate the actions for.
	 * @return array $actions The actions. Array keys are the action names, values are the action link (<a>) tags.
	 */
	private function get_subscription_list_table_actions( $subscription ) {
		$actions = [];

		// We need an instance of the post object type to be able to check user capabilities for status transition actions.
		$post_type_object = get_post_type_object( $subscription->get_type() );

		// Some actions URLS change depending on the environment.
		$is_hpos_enabled = wcs_is_custom_order_tables_usage_enabled();

		// On HPOS environments, WC expects a slightly different format for the bulk actions.
		if ( $is_hpos_enabled ) {
			$id_key          = wcs_is_woocommerce_pre( '8.1' ) ? 'order' : 'id';
			$action_url_args = [
				$id_key    => [ $subscription->get_id() ],
				'_wpnonce' => wp_create_nonce( 'bulk-orders' ),
			];
		} else {
			$action_url_args = [
				'post'     => $subscription->get_id(),
				'_wpnonce' => wp_create_nonce( 'bulk-posts' ),
			];
		}

		$action_url   = add_query_arg( $action_url_args );
		$action_url   = remove_query_arg( [ 'changed', 'ids', 'filter_action' ], $action_url );
		$all_statuses = array(
			'active'    => __( 'Reactivate', 'woocommerce-subscriptions' ),
			'on-hold'   => __( 'Suspend', 'woocommerce-subscriptions' ),
			'cancelled' => _x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ),
			'trash'     => __( 'Trash', 'woocommerce-subscriptions' ),
			'deleted'   => __( 'Delete Permanently', 'woocommerce-subscriptions' ),
		);

		foreach ( $all_statuses as $status => $label ) {
			if ( ! $subscription->can_be_updated_to( $status ) ) {
				continue;
			}

			// Trashing and deleting requires specific user capabilities.
			if ( in_array( $status, array( 'trash', 'deleted' ), true ) && ! current_user_can( $post_type_object->cap->delete_post, $subscription->get_id() ) ) {
				continue;
			}

			if ( 'trash' === $status ) {
				// If the subscription is already trashed, add an untrash action instead.
				if ( 'trash' === $subscription->get_status() ) {
					$untrash_url        = $is_hpos_enabled ? esc_url( add_query_arg( 'action', 'untrash_subscriptions', $action_url ) ) : wp_nonce_url( admin_url( sprintf( $post_type_object->_edit_link . '&amp;action=untrash', $subscription->get_id() ) ), 'untrash-post_' . $subscription->get_id() );
					$actions['untrash'] = sprintf(
						'<a title="%s" href="%s">%s</a>',
						esc_attr( __( 'Restore this item from the Trash', 'woocommerce-subscriptions' ) ),
						$untrash_url,
						__( 'Restore', 'woocommerce-subscriptions' )
					);
				} elseif ( EMPTY_TRASH_DAYS ) {
					$actions['trash'] = sprintf(
						'<a class="submitdelete" title="%s" href="%s">%s</a>',
						esc_attr( __( 'Move this item to the Trash', 'woocommerce-subscriptions' ) ),
						esc_url( $this->get_trash_or_delete_subscription_link( $subscription->get_id(), $action_url, 'trash' ) ),
						$label
					);
				}

				// The trash action has been handled so continue to the next one.
				continue;
			}

			// The delete action is only shown on already trashed subscriptions, or where there is no trash period.
			if ( 'deleted' === $status && ( 'trash' === $subscription->get_status() || ! EMPTY_TRASH_DAYS ) ) {
				$actions['delete'] = sprintf(
					'<a class="submitdelete" title="%s" href="%s">%s</a>',
					esc_attr( __( 'Delete this item permanently', 'woocommerce-subscriptions' ) ),
					esc_url( $this->get_trash_or_delete_subscription_link( $subscription->get_id(), $action_url, 'delete' ) ),
					$label
				);

				// The delete action has been handled so continue to the next one.
				continue;
			}

			// Modify the label for canceling if the subscription is pending cancel.
			if ( 'cancelled' === $status && 'pending-cancel' === $subscription->get_status() ) {
				$label = __( 'Cancel Now', 'woocommerce-subscriptions' );
			}

			$actions[ $status ] = sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'action', $status, $action_url ) ), $label );
		}

		if ( 'pending' === $subscription->get_status() ) {
			unset( $actions['active'] );
			unset( $actions['trash'] );
		} elseif ( ! in_array( $subscription->get_status(), array( 'cancelled', 'pending-cancel', 'expired', 'switched', 'suspended' ), true ) ) {
			unset( $actions['trash'] );
		}

		return apply_filters( 'woocommerce_subscription_list_table_actions', $actions, $subscription );
	}

	/**
	 * Handles bulk action requests for Subscriptions.
	 *
	 * @param string $redirect_to      The default URL to redirect to after handling the bulk action request.
	 * @param string $action           The action to take against the list of subscriptions.
	 * @param array  $subscription_ids The list of subscription to run the action against.
	 *
	 * @return string The URL to redirect to after handling the bulk action request.
	 */
	public function handle_subscription_bulk_actions( $redirect_to, $action, $subscription_ids ) {

		if ( ! in_array( $action, [ 'active', 'on-hold', 'cancelled', 'trash_subscriptions', 'untrash_subscriptions', 'delete_subscriptions' ], true ) ) {
			return $redirect_to;
		}

		switch ( $action ) {
			case 'trash_subscriptions':
				$sendback_args = $this->do_bulk_action_delete_subscriptions( $subscription_ids );
				break;
			case 'untrash_subscriptions':
				$sendback_args = $this->do_bulk_action_untrash_subscriptions( $subscription_ids );
				break;
			case 'delete_subscriptions':
				$sendback_args = $this->do_bulk_action_delete_subscriptions( $subscription_ids, true );
				break;
			default:
				$sendback_args = $this->do_bulk_action_update_status( $subscription_ids, $action );
		}

		return esc_url_raw( add_query_arg( $sendback_args, $redirect_to ) ); // nosemgrep: audit.php.wp.security.xss.query-arg   -- The output of add_query_arg is being escaped.
	}

	/**
	 * Handles bulk updating the status subscriptions.
	 *
	 * @param array  $ids        Subscription IDs to be trashed or deleted.
	 * @param string $new_status The new status to update the subscriptions to.
	 *
	 * @return array Array of query args to redirect to after handling the bulk action request.
	 */
	private function do_bulk_action_update_status( $subscription_ids, $new_status ) {
		$sendback_args = [
			'ids'         => join( ',', $subscription_ids ),
			'bulk_action' => 'marked_' . $new_status,
			'changed'     => 0,
			'error_count' => 0,
		];

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			$note         = _x( 'Subscription status changed by bulk edit:', 'Used in order note. Reason why status changed.', 'woocommerce-subscriptions' );

			try {
				if ( 'cancelled' === $new_status ) {
					$subscription->cancel_order( $note );
				} else {
					$subscription->update_status( $new_status, $note, true );
				}

				// Fire the action hooks.
				do_action( 'woocommerce_admin_changed_subscription_to_' . $new_status, $subscription_id );

				$sendback_args['changed']++;
			} catch ( Exception $e ) {
				$sendback_args['error'] = rawurlencode( $e->getMessage() );
				$sendback_args['error_count']++;
			}
		}

		return $sendback_args;
	}

	/**
	 * Handles bulk trashing and deleting of subscriptions.
	 *
	 * @param array $ids          Subscription IDs to be trashed or deleted.
	 * @param bool  $force_delete When set, the subscription will be completed deleted. Otherwise, it will be trashed.
	 *
	 * @return array Array of query args to redirect to after handling the bulk action request.
	 */
	private function do_bulk_action_delete_subscriptions( $subscription_ids, $force_delete = false ) {
		$sendback_args = [
			'ids'         => join( ',', $subscription_ids ),
			'bulk_action' => $force_delete ? 'deleted' : 'trashed',
			'changed'     => 0,
		];

		foreach ( $subscription_ids as $id ) {
			$subscription = wcs_get_subscription( $id );
			$subscription->delete( $force_delete );
			$updated_subscription = wcs_get_subscription( $id );

			if ( ( $force_delete && false === $updated_subscription ) || ( ! $force_delete && $updated_subscription->get_status() === 'trash' ) ) {
				$sendback_args['changed']++;
			}
		}

		return $sendback_args;
	}

	/**
	 * Handles bulk untrashing of subscriptions.
	 *
	 * @param array $ids Subscription IDs to be restored.
	 *
	 * @return array Array of query args to redirect to after handling the bulk action request.
	 */
	private function do_bulk_action_untrash_subscriptions( $subscription_ids ) {
		$data_store      = WC_Data_Store::load( 'subscription' );
		$use_crud_method = method_exists( $data_store, 'has_callable' ) && $data_store->has_callable( 'untrash_order' );
		$sendback_args   = [
			'ids'         => join( ',', $subscription_ids ),
			'bulk_action' => 'untrashed',
			'changed'     => 0,
		];

		foreach ( $subscription_ids as $id ) {
			if ( $use_crud_method ) {
				$data_store->untrash_order( wcs_get_subscription( $id ) );
			} else {
				wp_untrash_post( $id );
			}

			$sendback_args['changed']++;
		}

		return $sendback_args;
	}

	/**
	 * Filters the list of available list table views for Subscriptions when HPOS enabled.
	 *
	 * This function adds links to the top of the Subscriptions List Table to filter the table by status while also showing status count.
	 *
	 * In HPOS, WooCommerce extends the WP_List_Table class and generates these views for Orders, but we need to override this and
	 * manually add the views for Subscriptions which is done by this function.
	 *
	 * @since 5.2.0
	 *
	 * @param array $views
	 *
	 * @return array
	 */
	public function filter_subscription_list_table_views( $views ) {
		$view_counts     = [];
		$views           = [];
		$all_count       = 0;
		$statuses        = $this->get_list_table_view_statuses();
		$count_by_status = WC_Data_Store::load( 'subscription' )->get_subscriptions_count_by_status();

		/**
		 * The nonce check is ignored below as there is no nonce provided on status filter requests and it's not necessary
		 * because we're filtering an admin screen, not processing or acting on the data.
		 */
		$current_status = ! empty( $_GET['status'] ) ? wc_clean( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		foreach ( array_keys( $statuses ) as $slug ) {
			$total_in_status = isset( $count_by_status[ $slug ] ) ? $count_by_status[ $slug ] : 0;

			if ( $total_in_status > 0 ) {
				$view_counts[ $slug ] = $total_in_status;
			}

			if ( ( get_post_status_object( $slug ) )->show_in_admin_all_list ) {
				$all_count += $total_in_status;
			}
		}

		$views['all'] = $this->get_list_table_view_status_link( 'all', __( 'All', 'woocommerce-subscriptions' ), $all_count, '' === $current_status || 'all' === $current_status );

		foreach ( $view_counts as $slug => $count ) {
			$views[ $slug ] = $this->get_list_table_view_status_link( $slug, $statuses[ $slug ], $count, $slug === $current_status );
		}

		return $views;
	}

	/**
	 * Returns a HTML link to filter the subscriptions list table view by status.
	 *
	 * @param string $status_slug  Status slug used to identify the view.
	 * @param string $status_name  Human-readable name of the view.
	 * @param int    $status_count Number of statuses in this view.
	 * @param bool   $current      If this is the current view.
	 *
	 * @return string
	 */
	private function get_list_table_view_status_link( $status_slug, $status_name, $status_count, $current ) {
		$base_url = get_admin_url( null, 'admin.php?page=wc-orders--shop_subscription' );

		return sprintf(
			'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( 'status', $status_slug, $base_url ) ),
			$current ? 'class="current"' : '',
			esc_html( $status_name ),
			absint( $status_count )
		);
	}

	/**
	 * Returns a list of subscription status slugs and labels that should be visible in the status list.
	 *
	 * @return array slug => label array of order statuses.
	 */
	private function get_list_table_view_statuses() {
		return array_intersect_key(
			array_merge(
				wcs_get_subscription_statuses(),
				array(
					'trash' => ( get_post_status_object( 'trash' ) )->label,
					'draft' => ( get_post_status_object( 'draft' ) )->label,
				)
			),
			array_flip( get_post_stati( array( 'show_in_admin_status_list' => true ) ) )
		);
	}

	/**
	 * Generates an admin trash or delete subscription URL in a HPOS environment compatible way.
	 *
	 * @param int    $subscription_id The subscription to generate a trash or delete URL for.
	 * @param string $base_action_url The base URL to add the query args to.
	 * @param string $status          The status to generate the URL for. Should be 'trash' or 'delete'.
	 *
	 * @return string The admin trash or delete subscription URL.
	 */
	private function get_trash_or_delete_subscription_link( $subscription_id, $base_action_url, $status ) {

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			return add_query_arg( 'action', $status . '_subscriptions', $base_action_url ); // nosemgrep: audit.php.wp.security.xss.query-arg -- False positive. The URL returned from this function is escaped at the point of output.
		}

		return get_delete_post_link( $subscription_id, '', 'delete' === $status );
	}

	/** Deprecated Functions */

	/**
	 * Get the HTML for an order item to display on the Subscription list table.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.7
	 *
	 * @param WC_Line_Item_Product $item         The subscription line item object.
	 * @param WC_Subscription      $subscription The subscription object. This variable is no longer used.
	 * @param string               $element      The type of element to generate. Can be 'div' or 'row'. Default is 'div'.
	 *
	 * @return string The line item column HTML content for a line item.
	 */
	protected static function get_item_display( $item, $subscription = '', $element = 'div' ) {
		wcs_deprecated_function( __METHOD__, '3.0.7' );
		$_product       = $item->get_product();
		$item_meta_html = self::get_item_meta_html( $item );

		if ( 'div' === $element ) {
			$item_html = self::get_item_display_div( $item, self::get_item_name_html( $item, $_product ), $item_meta_html );
		} else {
			$item_html = self::get_item_display_row( $item, self::get_item_name_html( $item, $_product, 'do_not_include_quantity' ), $item_meta_html );

		}

		return $item_html;
	}

	/**
	 * Gets the HTML for order item to display on the Subscription list table using a div element
	 * as the wrapper, which is done for subscriptions with a single line item.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.7
	 *
	 * @param WC_Line_Item_Product $item           The line item object.
	 * @param string               $item_name      The line item's name.
	 * @param string               $item_meta_html The line item's meta HTML.
	 *
	 * @return string The subscription line item column HTML content.
	 */
	protected static function get_item_display_div( $item, $item_name, $item_meta_html ) {
		wcs_deprecated_function( '__METHOD__', '3.0.7' );
		$item_html  = '<div class="order-item">';
		$item_html .= wp_kses( $item_name, array( 'a' => array( 'href' => array() ) ) );

		if ( $item_meta_html ) {
			$item_html .= wcs_help_tip( $item_meta_html, true );
		}

		$item_html .= '</div>';

		return $item_html;
	}

	/**
	 * Add extra options to the bulk actions dropdown
	 *
	 * It's only on the All Shop Subscriptions screen.
	 * Introducing new filter: woocommerce_subscription_bulk_actions. This has to be done through jQuery as the
	 * 'bulk_actions' filter that WordPress has can only be used to remove bulk actions, not to add them.
	 *
	 * This is a filterable array where the key is the action (will become query arg), and the value is a translatable
	 * string. The same array is used to
	 *
	 * @deprecated 5.3.0
	 */
	public function print_bulk_actions_script() {
		wcs_deprecated_function( __METHOD__, 'subscription-core 5.3.0' );
		$post_status = ( isset( $_GET['post_status'] ) ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$subscription_id = ( ! empty( $GLOBALS['post']->ID ) ) ? $GLOBALS['post']->ID : '';
		if ( ! $subscription_id ) {
			return;
		}

		if ( 'shop_subscription' !== WC_Data_Store::load( 'subscription' )->get_order_type( $subscription_id ) || in_array( $post_status, array( 'cancelled', 'trash', 'wc-expired' ), true ) ) {
			return;
		}

		// Make it filterable in case extensions want to change this
		$bulk_actions = apply_filters(
			'woocommerce_subscription_bulk_actions',
			array(
				'active'    => _x( 'Activate', 'an action on a subscription', 'woocommerce-subscriptions' ),
				'on-hold'   => _x( 'Put on-hold', 'an action on a subscription', 'woocommerce-subscriptions' ),
				'cancelled' => _x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ),
			)
		);

		// No need to display certain bulk actions if we know all the subscriptions on the page have that status already
		switch ( $post_status ) {
			case 'wc-active':
				unset( $bulk_actions['active'] );
				break;
			case 'wc-on-hold':
				unset( $bulk_actions['on-hold'] );
				break;
		}

		?>
		<script type="text/javascript">
			jQuery( function( $ ) {
				<?php
				foreach ( $bulk_actions as $action => $title ) {
					?>
					$( '<option>' )
						.val( '<?php echo esc_attr( $action ); ?>' )
						.text( '<?php echo esc_html( $title ); ?>' )
						.appendTo( "select[name='action'], select[name='action2']" );
					<?php
				}
				?>
			} );
		</script>
		<?php
	}

	/**
	 * Adds Order table query clauses to order the subscriptions list table by last payment date.
	 *
	 * There are 2 methods we use to order the subscriptions list table by last payment date:
	 *  - High performance: This method uses a temporary table to store the last payment date for each subscription.
	 *  - Low performance: This method uses a subquery to get the last payment date for each subscription.
	 *
	 * @param string[]         $pieces Associative array of the clauses for the query.
	 * @param OrdersTableQuery $query  The query object.
	 * @param array            $args   Query args.
	 *
	 * @return string[] $pieces Associative array of the clauses for the query.
	 */
	public function orders_table_query_clauses( $pieces, $query, $args ) {

		if ( ! is_admin() || ! isset( $args['type'] ) || 'shop_subscription' !== $args['type'] ) {
			return $pieces;
		}

		// Let's check whether we even have the privileges to do the things we want to do
		if ( $this->is_db_user_privileged() ) {
			$pieces = self::orders_table_clauses_high_performance( $pieces );
		} else {
			$pieces = self::orders_table_clauses_low_performance( $pieces );
		}

		$query_order = strtoupper( $args['order'] );

		$pieces['orderby'] = "COALESCE(lp.last_payment, parent_order.date_created_gmt, 0) {$query_order}";

		return $pieces;
	}

	/**
	 * Get the last payment date for a subscription.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 * @return int The last payment date timestamp.
	 */
	private static function get_last_payment_date( $subscription ) {
		$last_order_date_created = $subscription->get_last_order_date_created();

		if ( ! empty( $last_order_date_created ) ) {
			return $last_order_date_created;
		}

		$date_timestamp = $subscription->get_time( 'last_order_date_created' );
		$subscription->set_last_order_date_created( $date_timestamp );
		$subscription->save();

		return $date_timestamp;
	}

	/**
	 * Adds order table query clauses to sort the subscriptions list table by last payment date.
	 *
	 * This function provides a lower performance method using a subquery to sort by last payment date.
	 * It is a HPOS version of @see self::posts_clauses_low_performance().
	 *
	 * @param string[] $pieces Associative array of the clauses for the query.
	 * @return string[] $pieces Updated associative array of clauses for the query.
	 */
	private function orders_table_clauses_low_performance( $pieces ) {
		$order_datastore = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
		$order_table     = $order_datastore::get_orders_table_name();
		$meta_table      = $order_datastore::get_meta_table_name();

		$pieces['join'] .= "LEFT JOIN
				(SELECT
					MAX( orders.date_created_gmt ) as last_payment,
					order_meta.meta_value
				FROM {$meta_table} as order_meta
				LEFT JOIN {$order_table} orders ON orders.id = order_meta.order_id
				WHERE order_meta.meta_key = '_subscription_renewal'
				GROUP BY order_meta.meta_value) lp
			ON {$order_table}.id = lp.meta_value
			LEFT JOIN {$order_table} as parent_order on {$order_table}.parent_order_id = parent_order.ID";

		return $pieces;
	}

	/**
	 * Adds order table query clauses to sort the subscriptions list table by last payment date.
	 *
	 * This function provides a higher performance method using a temporary table to sort by last payment date.
	 * It is a HPOS version of @see self::posts_clauses_high_performance().
	 *
	 * @param string[] $pieces Associative array of the clauses for the query.
	 * @return string[] $pieces Updated associative array of clauses for the query.
	 */
	private function orders_table_clauses_high_performance( $pieces ) {
		global $wpdb;

		$order_datastore = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
		$order_table     = $order_datastore::get_orders_table_name();
		$meta_table      = $order_datastore::get_meta_table_name();
		$session         = wp_get_session_token();

		$table_name = substr( "{$wpdb->prefix}tmp_{$session}_lastpayment", 0, 64 );

		// Create a temporary table, drop the previous one.
		//phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );

		$wpdb->query(
			"CREATE TEMPORARY TABLE {$table_name} (id INT PRIMARY KEY, last_payment DATETIME) AS
			SELECT order_meta.meta_value as id, MAX( orders.date_created_gmt ) as last_payment
			FROM {$meta_table} as order_meta
			LEFT JOIN {$order_table} as orders ON orders.id = order_meta.order_id
			WHERE order_meta.meta_key = '_subscription_renewal'
			GROUP BY order_meta.meta_value"
		);
		//phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pieces['join'] .= "LEFT JOIN {$table_name} as lp
			ON {$order_table}.id = lp.id
			LEFT JOIN {$order_table} as parent_order on {$order_table}.parent_order_id = parent_order.id";

		return $pieces;
	}
}
