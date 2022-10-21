<?php
/**
 * Simple class to generate the HTML for a Select2 element in a WC version compatible way.
 *
 * @since    2.2
 * @category Class
 * @author   Prospress
 * @package  WooCommerce Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Select2 {

	protected $default_attributes = array(
		'type'        => 'hidden',
		'placeholder' => '',
		'class'       => '',
	);

	protected $attributes = array();

	/**
	 * Constructor.
	 *
	 * @param array $attributes The attributes that make up the Select2 element
	 * @since 2.2
	 */
	public function __construct( array $attributes ) {
		$this->attributes = array_merge( $this->default_attributes, $attributes );
	}

	/**
	 * Render a select2 element given an array of attributes.
	 *
	 * @param array $attributes Select2 attributes
	 * @since 2.2
	 */
	public static function render( array $attributes ) {
		$select2 = new self( $attributes );
		$select2->print_html();
	}

	/**
	 * Get a property name.
	 *
	 * @param string $property
	 * @return string class, name, id or data-$property;
	 * @since 2.2
	 */
	protected function get_property_name( $property ) {
		$data_properties = wcs_is_woocommerce_pre( '3.0' ) ? array( 'placeholder', 'selected', 'allow_clear' ) : array( 'placeholder', 'allow_clear' );
		return in_array( $property, $data_properties ) ? 'data-' . $property : $property;
	}

	/**
	 * Returns a list of properties/values (HTML) from an array. All the values
	 * are escaped.
	 *
	 * @param $attributes List of HTML attributes with values
	 * @return string
	 * @since 2.2
	 */
	protected function attributes_to_html( array $attributes ) {

		$html = array();

		foreach ( $attributes as $property => $value ) {
			if ( ! is_scalar( $value ) ) {
				$value = wcs_json_encode( $value );
			}

			$html[] = $this->get_property_name( $property ) . '="' . esc_attr( $value, 'woocommerce-subscriptions' ) . '"';
		}

		return implode( ' ', $html );
	}

	/**
	 * Prints the HTML to show the Select2 field.
	 *
	 * @since 2.2
	 */
	public function print_html() {
		$allowed_attributes = array_map( array( $this, 'get_property_name' ), array_keys( $this->attributes ) );
		$allowed_attributes = array_fill_keys( $allowed_attributes, array() );

		echo wp_kses_allow_underscores(
			$this->get_html(),
			array(
				'input'  => $allowed_attributes,
				'select' => $allowed_attributes,
				'option' => $allowed_attributes,
			)
		);
	}

	/**
	 * Returns the HTML needed to show the Select2 field
	 *
	 * @return string
	 * @since 2.2
	 */
	public function get_html() {
		$html = "\n<!--select2 -->\n";

		if ( wcs_is_woocommerce_pre( '3.0' ) ) {
			if ( isset( $this->attributes['class'] ) && $this->attributes['class'] === 'wc-enhanced-select' ) {
				$html .= '<select ';
				$html .= $this->attributes_to_html( $this->attributes );
				$html .= '>';
				$html .= '<option value=""></option>';
				$html .= '</select>';
			} else {
				$html .= '<input ';
				$html .= $this->attributes_to_html( $this->attributes );
				$html .= '/>';
			}
		} else {
			$attributes             = $this->attributes;
			$selected_value         = isset( $attributes['selected'] ) ? $attributes['selected'] : '';
			$attributes['selected'] = 'selected';

			$option_attributes = array_intersect_key( $attributes, array_flip( array( 'value', 'selected' ) ) );
			$select_attributes = array_diff_key( $attributes, $option_attributes );

			$html .= '<select ' . $this->attributes_to_html( $select_attributes ) . '>';
			$html .= '<option ' . $this->attributes_to_html( $option_attributes ) . '>' . $selected_value . '</option>';
			$html .= '</select>';
		}

		$html .= "\n<!--/select2 -->\n";

		return $html;
	}
}
