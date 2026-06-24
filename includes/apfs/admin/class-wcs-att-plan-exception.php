<?php
/**
 * Plan Exception
 *
 * Exception thrown by WCS_ATT_Plans_Manager for expected error conditions
 * such as validation failures and missing plans.
 *
 * @since    9.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception for expected plan operation failures.
 *
 * Carries a machine-readable error code, an HTTP status, and optional
 * field-level details — so the REST controller can convert it into a
 * properly-shaped WP_Error response.
 */
class WCS_ATT_Plan_Exception extends RuntimeException {

	/**
	 * Machine-readable error code (e.g. 'plan_not_found', 'validation_error').
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status code to use in the REST response.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Optional field-level details (e.g. validation error messages keyed by field name).
	 *
	 * @var array
	 */
	private $details;

	/**
	 * Constructor.
	 *
	 * @since 9.0.0
	 *
	 * @param string $error_code Machine-readable error code.
	 * @param string $message    Human-readable message.
	 * @param int    $status     HTTP status code. Default 400.
	 * @param array  $details    Optional field-level details.
	 */
	public function __construct( $error_code, $message, $status = 400, $details = array() ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->status     = $status;
		$this->details    = $details;
	}

	/**
	 * Return the machine-readable error code.
	 *
	 * @since 9.0.0
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Return the HTTP status code.
	 *
	 * @since 9.0.0
	 *
	 * @return int
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Return the field-level details array.
	 *
	 * @since 9.0.0
	 *
	 * @return array
	 */
	public function get_details() {
		return $this->details;
	}
}
