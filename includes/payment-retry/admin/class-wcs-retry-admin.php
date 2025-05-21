<?php
/**
 * Create settings and add meta boxes relating to retries
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Retry_Admin {

	/**
	 * @var string The ID of the setting to enable/disable the retry system.
	 */
	public $setting_id;

	/**
	 * Constructor
	 */
	public function __construct( $setting_id ) {

		$this->setting_id = $setting_id;

		add_filter( 'woocommerce_subscription_settings', array( $this, 'add_settings' ) );

		if ( WCS_Retry_Manager::is_retry_enabled() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 50, 2 );

			add_filter( 'wcs_display_date_type', array( $this, 'maybe_hide_date_type' ), 10, 3 );

			// Display the number of retries in the Orders list table
			add_action( 'manage_shop_order_posts_custom_column', __CLASS__ . '::add_column_content', 20, 2 );

			// HPOS - Display the number of retries in the Orders list table
			add_action( 'woocommerce_shop_order_list_table_custom_column', __CLASS__ . '::add_column_content_list_table', 10, 2 );

			add_filter( 'wcs_system_status', array( $this, 'add_system_status_content' ) );
		}
	}

	/**
	 * Add a meta box to the Edit Order screen to display the retries relating to that order
	 *
	 * @param string           $post_type Optional. Post type. Default empty.
	 * @param WC_Order|WP_Post $order     Optional. The Order object. Default null. If null, the global $post is used.
	 */
	public function add_meta_boxes( $post_type = '', $order = null ) {
		/**
		 * Get the order parameter into a consistent type.
		 *
		 * For backwards compatibility, if the order parameter isn't provided, use the global $post_id.
		 * On CPT stores, the order param will be a WP Post object.
		 */
		if ( is_null( $order ) || is_a( $order, 'WP_Post' ) ) {
			global $post_ID;
			$order_id = $order ? $order->ID : $post_ID;
			$order    = wc_get_order( $order_id );
		}

		// Only display the meta box if an order relates to a subscription and there are retries for that order.
		if ( wcs_is_order( $order ) && wcs_order_contains_renewal( $order ) && WCS_Retry_Manager::store()->get_retry_count_for_order( $order->get_id() ) > 0 ) {
			add_meta_box( 'renewal_payment_retries', __( 'Automatic Failed Payment Retries', 'woocommerce-subscriptions' ), 'WCS_Meta_Box_Payment_Retries::output', wcs_get_page_screen_id( 'shop_order' ), 'normal', 'low' );
		}
	}

	/**
	 * Only display the retry payment date on the Edit Subscription screen if the subscription has a pending retry
	 * and when that is the case, do not display the next payment date (because it will still be set to the original
	 * payment date, in the past).
	 *
	 * @param bool            $show_date_type
	 * @param string          $date_key
	 * @param WC_Subscription $the_subscription
	 *
	 * @return bool
	 */
	public function maybe_hide_date_type( $show_date_type, $date_key, $the_subscription ) {

		if ( 'payment_retry' === $date_key && 0 == $the_subscription->get_time( 'payment_retry' ) ) {
			$show_date_type = false;
		} elseif ( 'next_payment' === $date_key && $the_subscription->get_time( 'payment_retry' ) > 0 ) {
			$show_date_type = false;
		}

		return $show_date_type;
	}

	/**
	 * Dispay the number of retries on a renewal order in the Orders list table.
	 *
	 * @param string $column  The string of the current column
	 * @param int    $post_id The ID of the order
	 *
	 * @since 2.1
	 */
	public static function add_column_content( $column, $post_id ) {

		if ( 'subscription_relationship' == $column && wcs_order_contains_renewal( $post_id ) ) {

			$retries = WCS_Retry_Manager::store()->get_retries_for_order( $post_id );

			if ( ! empty( $retries ) ) {

				$retry_counts = array();
				$tool_tip     = '';

				foreach ( $retries as $retry ) {
					$retry_counts[ $retry->get_status() ] = isset( $retry_counts[ $retry->get_status() ] ) ? ++$retry_counts[ $retry->get_status() ] : 1;
				}

				foreach ( $retry_counts as $retry_status => $retry_count ) {

					switch ( $retry_status ) {
						case 'pending':
							// translators: %d: retry count.
							$tool_tip .= sprintf( _n( '%d Pending Payment Retry', '%d Pending Payment Retries', $retry_count, 'woocommerce-subscriptions' ), $retry_count );
							break;
						case 'processing':
							// translators: %d: retry count.
							$tool_tip .= sprintf( _n( '%d Processing Payment Retry', '%d Processing Payment Retries', $retry_count, 'woocommerce-subscriptions' ), $retry_count );
							break;
						case 'failed':
							// translators: %d: retry count.
							$tool_tip .= sprintf( _n( '%d Failed Payment Retry', '%d Failed Payment Retries', $retry_count, 'woocommerce-subscriptions' ), $retry_count );
							break;
						case 'complete':
							// translators: %d: retry count.
							$tool_tip .= sprintf( _n( '%d Successful Payment Retry', '%d Successful Payment Retries', $retry_count, 'woocommerce-subscriptions' ), $retry_count );
							break;
						case 'cancelled':
							// translators: %d: retry count.
							$tool_tip .= sprintf( _n( '%d Cancelled Payment Retry', '%d Cancelled Payment Retries', $retry_count, 'woocommerce-subscriptions' ), $retry_count );
							break;
					}

					$tool_tip .= '<br />';
				}

				echo '<br /><span class="payment_retry tips" data-tip="' . esc_attr( $tool_tip ) . '"></span>';
			}
		}
	}

	/**
	 * Display the number of retries on a renewal order in the Orders list table,
	 * for HPOS-enabled stores.
	 *
	 * @param string   $column The column name
	 * @param WC_Order $order  The order object
	 *
	 * @return void
	 */
	public static function add_column_content_list_table( string $column, WC_Order $order ) {
		if ( 'subscription_relationship' === $column ) {
			self::add_column_content( $column, $order->get_id() );
		}
	}

	/**
	 * Add a setting to enable/disable the retry system
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function add_settings( $settings ) {

		$misc_section_end = wp_list_filter( $settings, array(
			'id'   => 'woocommerce_subscriptions_miscellaneous',
			'type' => 'sectionend',
		) );

		$spliced_array = array_splice( $settings, key( $misc_section_end ), 0, array(
			array(
				'name'     => __( 'Retry Failed Payments', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Enable automatic retry of failed recurring payments', 'woocommerce-subscriptions' ),
				'id'       => $this->setting_id,
				'default'  => 'no',
				'type'     => 'checkbox',
				// translators: 1,2: opening/closing link tags (to documentation).
				'desc_tip' => sprintf( __( 'Attempt to recover recurring revenue that would otherwise be lost due to payment methods being declined only temporarily. %1$sLearn more%2$s.', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/document/subscriptions/failed-payment-retry/">', '</a>' ),
			),
		) );

		return $settings;
	}

	/**
	 * Add system status information about custom retry rules.
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public static function add_system_status_content( $data ) {
		$has_custom_retry_rules      = has_action( 'wcs_default_retry_rules' );
		$has_custom_retry_rule_class = has_action( 'wcs_retry_rule_class' );
		$has_custom_raw_retry_rule   = has_action( 'wcs_get_retry_rule_raw' );
		$has_custom_retry_rule       = has_action( 'wcs_get_retry_rule' );
		$has_retry_on_post_store     = WCS_Retry_Migrator::needs_migration();

		$data['wcs_retry_rules_overridden'] = array(
			'name'      => _x( 'Custom Retry Rules', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'     => 'Custom Retry Rules',
			'mark_icon' => $has_custom_retry_rules ? 'warning' : 'yes',
			'note'      => $has_custom_retry_rules ? 'Yes' : 'No',
			'success'   => ! $has_custom_retry_rules,
		);

		$data['wcs_retry_rule_class_overridden'] = array(
			'name'      => _x( 'Custom Retry Rule Class', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'     => 'Custom Retry Rule Class',
			'mark_icon' => $has_custom_retry_rule_class ? 'warning' : 'yes',
			'note'      => $has_custom_retry_rule_class ? 'Yes' : 'No',
			'success'   => ! $has_custom_retry_rule_class,
		);

		$data['wcs_raw_retry_rule_overridden'] = array(
			'name'      => _x( 'Custom Raw Retry Rule', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'     => 'Custom Raw Retry Rule',
			'mark_icon' => $has_custom_raw_retry_rule ? 'warning' : 'yes',
			'note'      => $has_custom_raw_retry_rule ? 'Yes' : 'No',
			'success'   => ! $has_custom_raw_retry_rule,
		);

		$data['wcs_retry_rule_overridden'] = array(
			'name'      => _x( 'Custom Retry Rule', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'     => 'Custom Retry Rule',
			'mark_icon' => $has_custom_retry_rule ? 'warning' : 'yes',
			'note'      => $has_custom_retry_rule ? 'Yes' : 'No',
			'success'   => ! $has_custom_retry_rule,
		);

		$data['wcs_retry_data_migration_status'] = array(
			'name'      => _x( 'Retries Migration Status', 'label for the system status page', 'woocommerce-subscriptions' ),
			'label'     => 'Retries Migration Status',
			'mark_icon' => $has_retry_on_post_store ? '' : 'yes',
			'note'      => $has_retry_on_post_store ? 'In-Progress' : 'Completed',
			'mark'      => ( $has_retry_on_post_store ) ? '' : 'yes',
		);

		return $data;
	}
}
