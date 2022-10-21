<?php
/**
 * An abstract class to make it possible to offload the handling of deprecated function calls to a separate class.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   WooCommerce
 * @since    4.0.0
 */

defined( 'ABSPATH' ) || exit;

abstract class WCS_Deprecated_Functions_Handler {

	/**
	 * The class this handler is responsible for.
	 *
	 * @var string
	 */
	protected $class = '';

	/**
	 * An array of functions which have been deprecated with their replacement (optional) and version they were deprecated.
	 *
	 * '{deprecated_function}' => array(
	 *     'replacement' => string|array The replacement function to call.
	 *     'version'     => string       The version the function was deprecated.
	 * )...
	 *
	 * @var array[]
	 */
	protected $deprecated_functions = array();

	/**
	 * Determines if a function is deprecated and handled by this class.
	 *
	 * @since 4.0.0
	 *
	 * @param string $function The function to check.
	 * @return bool
	 */
	public function is_deprecated( $function ) {
		return isset( $this->deprecated_functions[ $function ] );
	}

	/**
	 * Determines if there's a replacement function to call.
	 *
	 * @since 4.0.0
	 *
	 * @param  string $function The deprecated function to check if there's a replacement for.
	 * @return bool
	 */
	public function has_replacement( $function ) {
		return isset( $this->deprecated_functions[ $function ]['replacement'] );
	}

	/**
	 * Calls the replacement function if one exists.
	 *
	 * @since 4.0.0
	 *
	 * @param string $function  The deprecated function.
	 * @param array  $arguments The deprecated function arguments.
	 *
	 * @return mixed Returns what ever the replacement function returns.
	 */
	public function call_replacement( $function, $arguments = array() ) {
		if ( $this->is_deprecated( $function ) && $this->has_replacement( $function ) ) {
			$replacement = $this->deprecated_functions[ $function ]['replacement'];

			// Handle replacements which are handled internally.
			if ( is_array( $replacement ) ) {
				if ( is_array( $replacement[0] ) ) {
					$instance = call_user_func( $replacement[0] );

					return call_user_func_array( array( $instance, $replacement[1] ), $arguments );
				} elseif ( get_class( $this ) === $replacement[0] ) {
					return $this->{$replacement[1]}( ...$arguments );
				}
			} else {
				return call_user_func_array( $replacement, $arguments );
			}
		}
	}

	/**
	 * Triggers the deprecated notice.
	 *
	 * @since 4.0.0
	 * @param string $function The deprecated function.
	 */
	public function trigger_notice( $function ) {

		if ( $this->is_deprecated( $function ) ) {
			$version_deprecated   = $this->deprecated_functions[ $function ]['version'];
			$deprecated_function  = empty( $this->class ) ? $function : "{$this->class}::{$function}";
			$replacement_function = null;

			// Format the replacement function to be outputted.
			if ( $this->has_replacement( $function ) ) {
				$replacement_function = $this->deprecated_functions[ $function ]['replacement'];

				if ( is_array( $replacement_function ) ) {

					if ( is_array( $replacement_function[0] ) ) {
						$replacement_function[0] = implode( '::', $replacement_function[0] ) . '()';
						$replacement_function    = implode( '->', $replacement_function ) . '()';
					} elseif ( get_class( $this ) === $replacement_function[0] ) {
						// Replacement functions which point back to the handler class, aren't legitimate replacements so treat them as having no replacements.
						$replacement_function = null;
					} else {
						$replacement_function = implode( '::', $replacement_function ) . '()';
					}
				}
			}

			wcs_deprecated_function( $deprecated_function, $version_deprecated, $replacement_function );
		}
	}
}
