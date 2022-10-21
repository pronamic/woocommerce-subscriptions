<?php
/**
 * Post Types Admin
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

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
		add_action( 'manage_shop_subscription_posts_custom_column', array( $this, 'render_shop_subscription_columns' ), 2 );

		// Bulk actions
		add_filter( 'bulk_actions-edit-shop_subscription', array( $this, 'remove_bulk_actions' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'print_bulk_actions_script' ) );
		add_action( 'load-edit.php', array( $this, 'parse_bulk_actions' ) );
		add_action( 'admin_notices', array( $this, 'bulk_admin_notices' ) );

		// Subscription order/filter
		add_filter( 'request', array( $this, 'request_query' ) );

		// Subscription Search
		add_filter( 'get_search_query', array( $this, 'shop_subscription_search_label' ) );
		add_filter( 'query_vars', array( $this, 'add_custom_query_var' ) );
		add_action( 'parse_query', array( $this, 'shop_subscription_search_custom_fields' ) );

		add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );

		add_action( 'restrict_manage_posts', array( $this, 'restrict_by_product' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_by_payment_method' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_by_customer' ) );

		add_action( 'list_table_primary_column', array( $this, 'list_table_primary_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'shop_subscription_row_actions' ), 10, 2 );
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

		return ( in_array( 'CREATE TEMPORARY TABLES', $permissions ) && in_array( 'INDEX', $permissions ) && in_array( 'DROP', $permissions ) );
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
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );

		$wpdb->query(
			"CREATE TEMPORARY TABLE {$table_name} (id INT PRIMARY KEY, last_payment DATETIME) AS
			 SELECT pm.meta_value as id, MAX( p.post_date_gmt ) as last_payment FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_subscription_renewal'
			 GROUP BY pm.meta_value" );
		// Magic ends here

		$pieces['join'] .= "LEFT JOIN {$table_name} lp
			ON {$wpdb->posts}.ID = lp.id
			LEFT JOIN {$wpdb->posts} o on {$wpdb->posts}.post_parent = o.ID";

		return $pieces;
	}


	/**
	 * Displays the dropdown for the product filter
	 * @return string the html dropdown element
	 */
	public function restrict_by_product() {
		global $typenow;

		if ( 'shop_subscription' !== $typenow ) {
			return;
		}

		$product_id = '';
		$product_string = '';

		if ( ! empty( $_GET['_wcs_product'] ) ) {
			$product_id     = absint( $_GET['_wcs_product'] );
			$product_string = wc_get_product( $product_id )->get_formatted_name();
		}

		WCS_Select2::render( array(
			'class'       => 'wc-product-search',
			'name'        => '_wcs_product',
			'placeholder' => esc_attr__( 'Search for a product&hellip;', 'woocommerce-subscriptions' ),
			'action'      => 'woocommerce_json_search_products_and_variations',
			'selected'    => strip_tags( $product_string ),
			'value'       => $product_id,
			'allow_clear' => 'true',
		) );
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
	 * Add extra options to the bulk actions dropdown
	 *
	 * It's only on the All Shop Subscriptions screen.
	 * Introducing new filter: woocommerce_subscription_bulk_actions. This has to be done through jQuery as the
	 * 'bulk_actions' filter that WordPress has can only be used to remove bulk actions, not to add them.
	 *
	 * This is a filterable array where the key is the action (will become query arg), and the value is a translatable
	 * string. The same array is used to
	 *
	 */
	public function print_bulk_actions_script() {

		$post_status = ( isset( $_GET['post_status'] ) ) ? $_GET['post_status'] : '';

		if ( 'shop_subscription' !== get_post_type() || in_array( $post_status, array( 'cancelled', 'trash', 'wc-expired' ) ) ) {
			return;
		}

		// Make it filterable in case extensions want to change this
		$bulk_actions = apply_filters( 'woocommerce_subscription_bulk_actions', array(
			'active'    => _x( 'Activate', 'an action on a subscription', 'woocommerce-subscriptions' ),
			'on-hold'   => _x( 'Put on-hold', 'an action on a subscription', 'woocommerce-subscriptions' ),
			'cancelled' => _x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ),
		) );

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

		if ( isset( $_REQUEST['action'] ) && -1 != $_REQUEST['action'] ) {
			$action = wc_clean( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && -1 != $_REQUEST['action2'] ) {
			$action = wc_clean( wp_unslash( $_REQUEST['action2'] ) );
		}

		switch ( $action ) {
			case 'active':
			case 'on-hold':
			case 'cancelled':
				$new_status = $action;
				break;
			default:
				return;
		}

		$report_action = 'marked_' . $new_status;

		$changed = 0;

		$subscription_ids = array_map( 'absint', (array) $_REQUEST['post'] );

		$sendback_args = array(
			'post_type'    => 'shop_subscription',
			$report_action => true,
			'ids'          => join( ',', $subscription_ids ),
			'error_count'  => 0,
		);

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			$order_note   = _x( 'Subscription status changed by bulk edit:', 'Used in order note. Reason why status changed.', 'woocommerce-subscriptions' );

			try {

				if ( 'cancelled' === $action ) {
					$subscription->cancel_order( $order_note );
				} else {
					$subscription->update_status( $new_status, $order_note, true );
				}

				// Fire the action hooks
				switch ( $action ) {
					case 'active':
					case 'on-hold':
					case 'cancelled':
					case 'trash':
						do_action( 'woocommerce_admin_changed_subscription_to_' . $action, $subscription_id );
						break;
				}

				$changed++;

			} catch ( Exception $e ) {
				$sendback_args['error'] = urlencode( $e->getMessage() );
				$sendback_args['error_count']++;
			}
		}

		$sendback_args['changed'] = $changed;
		$sendback                 = add_query_arg( $sendback_args, wp_get_referer() ? wp_get_referer() : '' );
		wp_safe_redirect( esc_url_raw( $sendback ) );

		exit();
	}

	/**
	 * Show confirmation message that subscription status was changed
	 */
	public function bulk_admin_notices() {
		global $post_type, $pagenow;

		// Bail out if not on shop order list page
		if ( 'edit.php' !== $pagenow || 'shop_subscription' !== $post_type ) {
			return;
		}

		$subscription_statuses = wcs_get_subscription_statuses();

		// Check if any status changes happened
		foreach ( $subscription_statuses as $slug => $name ) {

			if ( isset( $_REQUEST[ 'marked_' . str_replace( 'wc-', '', $slug ) ] ) ) {

				$number = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0;

				// translators: placeholder is the number of subscriptions updated
				$message = sprintf( _n( '%s subscription status changed.', '%s subscription statuses changed.', $number, 'woocommerce-subscriptions' ), number_format_i18n( $number ) );
				echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';

				if ( ! empty( $_REQUEST['error_count'] ) ) {
					$error_msg = isset( $_REQUEST['error'] ) ? stripslashes( $_REQUEST['error'] ) : '';
					$error_count = isset( $_REQUEST['error_count'] ) ? absint( $_REQUEST['error_count'] ) : 0;
					// translators: 1$: is the number of subscriptions not updated, 2$: is the error message
					$message = sprintf( _n( '%1$s subscription could not be updated: %2$s', '%1$s subscriptions could not be updated: %2$s', $error_count, 'woocommerce-subscriptions' ), number_format_i18n( $error_count ), $error_msg );
					echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
				}

				$_SERVER['REQUEST_URI'] = remove_query_arg( array( 'error_count', 'marked_active' ), $_SERVER['REQUEST_URI'] );

				break;
			}
		}
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
	 * Output custom columns for subscriptions
	 * @param string $column
	 */
	public function render_shop_subscription_columns( $column ) {
		global $post, $the_subscription, $wp_list_table;

		if ( empty( $the_subscription ) || $the_subscription->get_id() != $post->ID ) {
			$the_subscription = wcs_get_subscription( $post->ID );
		}

		// If the subscription failed to load, only display the ID.
		if ( empty( $the_subscription ) ) {
			if ( 'order_title' === $column ) {
				// translators: placeholder is a subscription ID.
				echo '<strong>' . sprintf( esc_html_x( '#%s', 'hash before subscription number', 'woocommerce-subscriptions' ), esc_html( $post->ID ) ) . '</strong>';
				?>
				<div class="wcs-unknown-order-info-wrapper">
					<a href="https://woocommerce.com/document/subscriptions/store-manager-guide/#section-18">
						<?php
						// Translators: Placeholder is a <br> HTML tag.
						echo wcs_help_tip( sprintf( __( "This subscription couldn't be loaded from the database. %s Click to learn more.", 'woocommerce-subscriptions' ), '</br>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</a>
				</div>
				<?php
			} else {
				echo '&mdash;';
			}

			return;
		}

		$column_content = '';

		switch ( $column ) {
			case 'status':
				// The status label
				$column_content = sprintf( '<mark class="subscription-status order-status status-%1$s %1$s tips" data-tip="%2$s"><span>%3$s</span></mark>', sanitize_title( $the_subscription->get_status() ), wcs_get_subscription_status_name( $the_subscription->get_status() ), wcs_get_subscription_status_name( $the_subscription->get_status() ) );

				$post_type_object = get_post_type_object( $post->post_type );

				$actions = array();

				$action_url = add_query_arg(
					array(
						'post'     => $the_subscription->get_id(),
						// Using the bulk actions nonce name as defined in WP core.
						'_wpnonce' => wp_create_nonce( 'bulk-posts' ),
					)
				);

				if ( isset( $_REQUEST['status'] ) ) {
					$action_url = add_query_arg( array( 'status' => $_REQUEST['status'] ), $action_url );
				}

				$all_statuses = array(
					'active'    => __( 'Reactivate', 'woocommerce-subscriptions' ),
					'on-hold'   => __( 'Suspend', 'woocommerce-subscriptions' ),
					'cancelled' => _x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ),
					'trash'     => __( 'Trash', 'woocommerce-subscriptions' ),
					'deleted'   => __( 'Delete Permanently', 'woocommerce-subscriptions' ),
				);

				foreach ( $all_statuses as $status => $label ) {

					if ( $the_subscription->can_be_updated_to( $status ) ) {

						if ( in_array( $status, array( 'trash', 'deleted' ) ) ) {

							if ( current_user_can( $post_type_object->cap->delete_post, $post->ID ) ) {

								if ( 'trash' == $post->post_status ) {
									$actions['untrash'] = '<a title="' . esc_attr( __( 'Restore this item from the Trash', 'woocommerce-subscriptions' ) ) . '" href="' . wp_nonce_url( admin_url( sprintf( $post_type_object->_edit_link . '&amp;action=untrash', $post->ID ) ), 'untrash-post_' . $post->ID ) . '">' . __( 'Restore', 'woocommerce-subscriptions' ) . '</a>';
								} elseif ( EMPTY_TRASH_DAYS ) {
									$actions['trash'] = '<a class="submitdelete" title="' . esc_attr( __( 'Move this item to the Trash', 'woocommerce-subscriptions' ) ) . '" href="' . get_delete_post_link( $post->ID ) . '">' . __( 'Trash', 'woocommerce-subscriptions' ) . '</a>';
								}

								if ( 'trash' == $post->post_status || ! EMPTY_TRASH_DAYS ) {
									$actions['delete'] = '<a class="submitdelete" title="' . esc_attr( __( 'Delete this item permanently', 'woocommerce-subscriptions' ) ) . '" href="' . get_delete_post_link( $post->ID, '', true ) . '">' . __( 'Delete Permanently', 'woocommerce-subscriptions' ) . '</a>';
								}
							}
						} else {

							if ( 'cancelled' === $status && 'pending-cancel' === $the_subscription->get_status() ) {
								$label = __( 'Cancel Now', 'woocommerce-subscriptions' );
							}

							$actions[ $status ] = sprintf( '<a href="%s">%s</a>', add_query_arg( 'action', $status, $action_url ), $label );

						}
					}
				}

				if ( 'pending' === $the_subscription->get_status() ) {
					unset( $actions['active'] );
					unset( $actions['trash'] );
				} elseif ( ! in_array( $the_subscription->get_status(), array( 'cancelled', 'pending-cancel', 'expired', 'switched', 'suspended' ) ) ) {
					unset( $actions['trash'] );
				}

				$actions = apply_filters( 'woocommerce_subscription_list_table_actions', $actions, $the_subscription );

				$column_content .= $wp_list_table->row_actions( $actions );

				$column_content = apply_filters( 'woocommerce_subscription_list_table_column_status_content', $column_content, $the_subscription, $actions );
				break;

			case 'order_title':

				$customer_tip = '';

				if ( $address = $the_subscription->get_formatted_billing_address() ) {
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
					echo '<div class="tips" data-tip="' . wc_sanitize_tooltip( $customer_tip ) . '">'; // XSS ok.
				}

				// This is to stop PHP from complaining
				$username = '';

				if ( $the_subscription->get_user_id() && ( false !== ( $user_info = get_userdata( $the_subscription->get_user_id() ) ) ) ) {

					$username  = '<a href="user-edit.php?user_id=' . absint( $user_info->ID ) . '">';

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
				// translators: $1: is opening link, $2: is subscription order number, $3: is closing link tag, $4: is user's name
				$column_content = sprintf( _x( '%1$s#%2$s%3$s for %4$s', 'Subscription title on admin table. (e.g.: #211 for John Doe)', 'woocommerce-subscriptions' ), '<a href="' . esc_url( admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ) ) . '">', '<strong>' . esc_attr( $the_subscription->get_order_number() ) . '</strong>', '</a>', $username );

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
				$column_content .= esc_html( strip_tags( $the_subscription->get_formatted_order_total() ) );
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
	 * @since 2.3.0
	 */
	public static function get_date_column_content( $subscription, $column ) {

		$date_type_map = array( 'last_payment_date' => 'last_order_date_created' );
		$date_type     = array_key_exists( $column, $date_type_map ) ? $date_type_map[ $column ] : $column;

		if ( 0 == $subscription->get_time( $date_type, 'gmt' ) ) {
			$column_content = '-';
		} else {
			$column_content = sprintf( '<time class="%s" title="%s">%s</time>', esc_attr( $column ), esc_attr( date( __( 'Y/m/d g:i:s A', 'woocommerce-subscriptions' ), $subscription->get_time( $date_type, 'site' ) ) ), esc_html( $subscription->get_date_to_display( $date_type ) ) );

			if ( 'next_payment_date' == $column && $subscription->payment_method_supports( 'gateway_scheduled_payments' ) && ! $subscription->is_manual() && $subscription->has_status( 'active' ) ) {
				$column_content .= '<div class="woocommerce-help-tip" data-tip="' . esc_attr__( 'This date should be treated as an estimate only. The payment gateway for this subscription controls when payments are processed.', 'woocommerce-subscriptions' ) . '"></div>';
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
	 * @access public
	 * @param WP_Query $wp
	 * @return void
	 */
	public function shop_subscription_search_custom_fields( $wp ) {
		global $pagenow, $wpdb;

		if ( 'edit.php' !== $pagenow || empty( $wp->query_vars['s'] ) || 'shop_subscription' !== $wp->query_vars['post_type'] ) {
			return;
		}

		$post_ids = wcs_subscription_search( $_GET['s'] );

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
	 * @access public
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

		return wp_unslash( $_GET['s'] );
	}

	/**
	 * Query vars for custom searches.
	 *
	 * @access public
	 * @param mixed $public_query_vars
	 * @return array
	 */
	public function add_custom_query_var( $public_query_vars ) {
		$public_query_vars[] = 'sku';
		$public_query_vars[] = 'shop_subscription_search';

		return $public_query_vars;
	}

	/**
	 * Filters and sorting handler
	 *
	 * @param  array $vars
	 * @return array
	 */
	public function request_query( $vars ) {
		global $typenow;

		if ( 'shop_subscription' === $typenow ) {

			// Filter the orders by the posted customer.
			if ( isset( $_GET['_customer_user'] ) && $_GET['_customer_user'] > 0 ) {
				$customer_id      = absint( $_GET['_customer_user'] );
				$subscription_ids = apply_filters(
					'wcs_admin_request_query_subscriptions_for_customer',
					WCS_Customer_Store::instance()->get_users_subscription_ids( $customer_id ),
					$customer_id
				);
				$vars = self::set_post__in_query_var( $vars, $subscription_ids );
			}

			if ( isset( $_GET['_wcs_product'] ) && $_GET['_wcs_product'] > 0 ) {
				$product_id       = absint( $_GET['_wcs_product'] );
				$subscription_ids = wcs_get_subscriptions_for_product( $product_id );
				$subscription_ids = apply_filters(
					'wcs_admin_request_query_subscriptions_for_product',
					array_keys( $subscription_ids ),
					$product_id
				);
				$vars = self::set_post__in_query_var( $vars, $subscription_ids );
			}

			// If we've using the 'none' flag for the post__in query var, there's no need to apply other query filters, as we're going to return no subscriptions anyway
			if ( isset( $vars['post__in'] ) && self::$post__in_none === $vars['post__in'] ) {
				return $vars;
			}

			if ( ! empty( $_GET['_payment_method'] ) ) {
				if ( '_manual_renewal' === trim( $_GET['_payment_method'] ) ) {
					$meta_query = array(
						array(
							'key'   => '_requires_manual_renewal',
							'value' => 'true',
						),
					);
				} else {
					$payment_gateway_filter = ( 'none' == $_GET['_payment_method'] ) ? '' : $_GET['_payment_method'];
					$meta_query             = array(
						array(
							'key'   => '_payment_method',
							'value' => $payment_gateway_filter,
						),
					);
				}

				$query_vars = array(
					'post_type'      => 'shop_subscription',
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'meta_query'     => $meta_query,
				);

				// If there are already set post restrictions (post__in) apply them to this query
				if ( isset( $vars['post__in'] ) ) {
					$query_vars['post__in'] = $vars['post__in'];
				}

				$subscription_ids = get_posts( $query_vars );

				if ( ! empty( $subscription_ids ) ) {
					$vars['post__in'] = $subscription_ids;
				} else {
					$vars['post__in'] = self::$post__in_none;
				}
			}

			// Sorting
			if ( isset( $vars['orderby'] ) ) {
				switch ( $vars['orderby'] ) {
					case 'order_total':
						$vars = array_merge( $vars, array(
							'meta_key' => '_order_total',
							'orderby'  => 'meta_value_num',
						) );
					break;
					case 'last_payment_date':
						add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );
						break;
					case 'start_date':
					case 'trial_end_date':
					case 'next_payment_date':
					case 'end_date':
						$vars = array_merge( $vars, array(
							'meta_key'  => sprintf( '_schedule_%s', str_replace( '_date', '', $vars['orderby'] ) ),
							'meta_type' => 'DATETIME',
							'orderby'   => 'meta_value',
						) );
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
	 * Set the 'post__in' query var with a given set of post ids.
	 *
	 * There are a few special conditions for handling the post__in value. Namely:
	 * - if there are no matching post_ids, the value should be array( 0 ), not an empty array()
	 * - if there are existing IDs in post__in, we only want to retun posts with an ID in both
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
		global $post, $post_ID;

		$messages['shop_subscription'] = array(
			0  => '', // Unused. Messages start at index 1.
			1  => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			2  => __( 'Custom field updated.', 'woocommerce-subscriptions' ),
			3  => __( 'Custom field deleted.', 'woocommerce-subscriptions' ),
			4  => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			// translators: placeholder is previous post title
			5  => isset( $_GET['revision'] ) ? sprintf( _x( 'Subscription restored to revision from %s', 'used in post updated messages', 'woocommerce-subscriptions' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
			6  => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			7  => __( 'Subscription saved.', 'woocommerce-subscriptions' ),
			8  => __( 'Subscription submitted.', 'woocommerce-subscriptions' ),
			// translators: php date string
			9  => sprintf( __( 'Subscription scheduled for: %1$s.', 'woocommerce-subscriptions' ), '<strong>' . date_i18n( _x( 'M j, Y @ G:i', 'used in "Subscription scheduled for <date>"', 'woocommerce-subscriptions' ), wcs_date_to_time( $post->post_date ) ) . '</strong>' ),
			10 => __( 'Subscription draft updated.', 'woocommerce-subscriptions' ),
		);

		return $messages;
	}

	/**
	 * Returns a clickable link that takes you to a collection of orders relating to the subscription.
	 *
	 * @uses  self::get_related_orders()
	 * @since  2.0
	 * @return string the link string
	 */
	public function get_related_orders_link( $the_subscription ) {
		return sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'edit.php?post_status=all&post_type=shop_order&_subscription_related_orders=' . absint( $the_subscription->get_id() ) ),
			count( $the_subscription->get_related_orders() )
		);
	}

	/**
	 * Displays the dropdown for the payment method filter.
	 *
	 * @since 2.0
	 */
	public static function restrict_by_payment_method() {
		global $typenow;

		if ( 'shop_subscription' !== $typenow ) {
			return;
		}

		$selected_gateway_id = ( ! empty( $_GET['_payment_method'] ) ) ? $_GET['_payment_method'] : ''; ?>

		<select class="wcs_payment_method_selector" name="_payment_method" id="_payment_method" class="first">
			<option value=""><?php esc_html_e( 'Any Payment Method', 'woocommerce-subscriptions' ) ?></option>
			<option value="none" <?php echo esc_attr( 'none' == $selected_gateway_id ? 'selected' : '' ) . '>' . esc_html__( 'None', 'woocommerce-subscriptions' ) ?></option>
		<?php

		foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway_id => $gateway ) {
			echo '<option value="' . esc_attr( $gateway_id ) . '"' . ( $selected_gateway_id == $gateway_id ? 'selected' : '' ) . '>' . esc_html( $gateway->title ) . '</option>';
		}
		echo '<option value="_manual_renewal">' . esc_html__( 'Manual Renewal', 'woocommerce-subscriptions' ) . '</option>';
		?>
		</select> <?php
	}

	/**
	 * Sets post table primary column subscriptions.
	 *
	 * @param string $default
	 * @param string $screen_id
	 * @return string
	 */
	public function list_table_primary_column( $default, $screen_id ) {

		if ( 'edit-shop_subscription' == $screen_id ) {
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

		if ( 'shop_subscription' == $post->post_type ) {
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

		$item_meta_html = wc_display_item_meta( $item, array(
				'before'    => '',
				'after'     => '',
				'separator' => '',
				'echo'      => false,
		) );

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

		$item_quantity  = absint( $item['qty'] );

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
					echo wcs_help_tip( $item_meta_html, true );
				} ?>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Renders the dropdown for the customer filter.
	 *
	 * @since 2.2.17
	 */
	public static function restrict_by_customer() {
		global $typenow;

		// Prior to WC 3.3 this was handled by WC core so exit early if an earlier version of WC is active.
		if ( 'shop_subscription' !== $typenow || wcs_is_woocommerce_pre( '3.3' ) ) {
			return;
		}

		$user_string = '';
		$user_id     = '';

		if ( ! empty( $_GET['_customer_user'] ) ) {
			$user_id = absint( $_GET['_customer_user'] );
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

	/** Deprecated Functions */

	/**
	 * Get the HTML for an order item to display on the Subscription list table.
	 *
	 * @deprecated 3.0.7
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
	 * @deprecated 3.0.7
	 *
	 * @param WC_Line_Item_Product $item           The line item object.
	 * @param string               $item_name      The line item's name.
	 * @param string               $item_meta_html The line item's meta HTML.
	 *
	 * @return string The subcription line item column HTML content.
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
}
