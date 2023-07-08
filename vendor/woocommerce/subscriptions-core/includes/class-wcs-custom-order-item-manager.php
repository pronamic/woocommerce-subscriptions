<?php
/**
 * Subscriptions Custom Order Item Manager
 *
 * @author   Prospress
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */
class WCS_Custom_Order_Item_Manager {

	/**
	 * The custom line item types managed by this class.
	 *
	 * @var array Each item type should have:
	 *   - A 'group' arg which is registered with WC_Abstract_Order::get_items() APIs via the woocommerce_order_type_to_group hook.
	 *   - A 'class' arg which WooCommerce's WC_Abstract_Order::get_item() APIs will use to instantiate the line item object.
	 *   - Optional. A 'data_store' arg. If provided, the line item will use this data store to load the line item data. Default is WC_Order_Item_Product_Data_Store.
	 */
	protected static $line_item_type_args = array(
		'line_item_removed'     => array(
			'group' => 'removed_line_items',
			'class' => 'WC_Subscription_Line_Item_Removed',
		),
		'line_item_switched'    => array(
			'group' => 'switched_line_items',
			'class' => 'WC_Subscription_Line_Item_Switched',
		),
		'coupon_pending_switch' => array(
			'group'      => 'pending_switch_coupons',
			'class'      => 'WC_Subscription_Item_Coupon_Pending_Switch',
			'data_store' => 'WC_Order_Item_Coupon_Data_Store',
		),
		'fee_pending_switch'    => array(
			'group'      => 'pending_switch_fees',
			'class'      => 'WC_Subscription_Item_Fee_Pending_Switch',
			'data_store' => 'WC_Order_Item_Fee_Data_Store',
		),
	);

	/**
	 * Initialise class hooks & filters when the file is loaded
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function init() {

		add_filter( 'woocommerce_order_type_to_group', array( __CLASS__, 'add_extra_groups' ) );
		add_filter( 'woocommerce_get_order_item_classname', array( __CLASS__, 'map_classname_for_extra_items' ), 10, 2 );
		add_filter( 'woocommerce_data_stores', array( __CLASS__, 'register_data_stores' ) );
	}

	/**
	 * Adds extra groups.
	 *
	 * @param array $type_to_group_list Existing list of types and their groups
	 * @return array $type_to_group_list
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function add_extra_groups( $type_to_group_list ) {

		foreach ( self::$line_item_type_args as $line_item_type => $args ) {
			$type_to_group_list[ $line_item_type ] = $args['group'];
		}

		return $type_to_group_list;
	}

	/**
	 * Maps the classname for extra items.
	 *
	 * @param string $classname
	 * @param string $item_type
	 * @return string $classname
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function map_classname_for_extra_items( $classname, $item_type ) {

		if ( isset( self::$line_item_type_args[ $item_type ] ) ) {
			$classname = self::$line_item_type_args[ $item_type ]['class'];
		}

		return $classname;
	}

	/**
	 * Register the data stores to be used for our custom line item types.
	 *
	 * @param  array $data_stores The registered data stores.
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function register_data_stores( $data_stores ) {

		foreach ( self::$line_item_type_args as $line_item_type => $args ) {
			// By default use the WC_Order_Item_Product_Data_Store unless specified otherwise.
			$data_store = isset( $args['data_store'] ) ? $args['data_store'] : 'WC_Order_Item_Product_Data_Store';
			$data_stores[ "order-item-{$line_item_type}" ] = $data_store;
		}

		return $data_stores;
	}
}
