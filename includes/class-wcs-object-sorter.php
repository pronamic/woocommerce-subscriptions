<?php
/**
 * A class to sort objects by an object property.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions
 * @since    2.6.0
 */

class WCS_Object_Sorter {

	/**
	 * The object property to compare.
	 *
	 * Used to generate the getter by prepending the 'get_' prefix. For example id -> get_id()
	 *
	 * @var string A valid object property. Could be 'date_created', 'date_modified', 'date_paid', 'date_completed' or 'id' for WC_Order or WC_Subscription objects, for example.
	 */
	protected $sort_by_property = '';

	/**
	 * Constructor.
	 *
	 * @since 2.6.0
	 *
	 * @param string $property The object property to use in comparisons. This will be used to generate the object getter by prepending 'get_'.
	 */
	public function __construct( $property ) {
		$this->sort_by_property = $property;
	}

	/**
	 * Compares two objects using the @see $this->sort_by_property getter.
	 *
	 * Designed to be used by uasort(), usort() or uksort() functions.
	 *
	 * @since 2.6.0
	 *
	 * @param object $object_one
	 * @param object $object_two
	 * @return int 0. -1 or 1 Depending on the result of the comparison.
	 */
	public function ascending_compare( $object_one, $object_two ) {
		$function = "get_{$this->sort_by_property}";

		if ( ! is_callable( array( $object_one, $function ) ) || ! is_callable( array( $object_two, $function ) ) ) {
			return 0;
		}

		$value_one = $object_one->{$function}();
		$value_two = $object_two->{$function}();

		if ( $value_one === $value_two ) {
			return 0;
		}

		return ( $value_one < $value_two ) ? -1 : 1;
	}

	/**
	 * Compares two objects using the @see $this->sort_by_property getter in reverse order.
	 *
	 * Designed to be used by uasort(), or usort() style functions.
	 *
	 * @since 2.6.0
	 *
	 * @param object $object_one
	 * @param object $object_two
	 * @return int 0. -1 or 1 Depending on the result of the comparison.
	 */
	public function descending_compare( $object_one, $object_two ) {
		return -1 * $this->ascending_compare( $object_one, $object_two );
	}
}
