<?php
/**
 * Class WC_REST_Subscriptions_Settings_Option_Controller
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for settings options.
 */
class WC_REST_Subscriptions_Settings_Option_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v3';

	/**
	 * List of allowed option names that can be updated via the REST API.
	 *
	 * @var array
	 */
	private const ALLOWED_OPTIONS = [
		'woocommerce_subscriptions_gifting_is_welcome_announcement_dismissed',
	];

	/**
	 * Endpoint path.
	 *
	 * @var string
	 */
	protected $rest_base = 'subscriptions/settings';

	/**
	 * Configure REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<option_name>[a-zA-Z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_option' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'option_name' => [
						'required'          => true,
						'validate_callback' => [ $this, 'validate_option_name' ],
					],
					'value'       => [
						'required'          => true,
						'validate_callback' => [ $this, 'validate_value' ],
					],
				],
			]
		);
	}

	/**
	 * Validate the option name.
	 *
	 * @param string $option_name The option name to validate.
	 * @return bool
	 */
	public function validate_option_name( string $option_name ): bool {
		return in_array( $option_name, self::ALLOWED_OPTIONS, true );
	}

	/**
	 * Validate the value parameter.
	 *
	 * @param mixed $value The value to validate.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_value( $value ) {
		if ( is_bool( $value ) || is_array( $value ) ) {
			return true;
		}
		return new WP_Error(
			'rest_invalid_param',
			__( 'Invalid value type; must be either boolean or array', 'woocommerce-subscriptions' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Update the option value.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_option( WP_REST_Request $request ) {
		$option_name = $request->get_param( 'option_name' );
		$value       = $request->get_param( 'value' );

		update_option( $option_name, $value );

		return rest_ensure_response(
			[
				'success' => true,
			]
		);
	}

	/**
	 * Verify access.
	 *
	 * Override this method if custom permissions required.
	 */
	public function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}
}
