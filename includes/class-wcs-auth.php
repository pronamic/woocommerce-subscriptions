<?php
/**
 * WooCommerce Auth
 *
 * Handles wc-auth endpoint requests
 *
 * @author   Prospress
 * @category API
 * @since    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_Auth {

	/**
	 * Setup class
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_filter( 'woocommerce_api_permissions_in_scope', array( $this, 'get_permissions_in_scope' ), 10, 2 );
	}

	/**
	 * Return a list of permissions a scope allows
	 *
	 * @param array $permissions
	 * @param string $scope
	 * @since 2.0.0
	 * @return array
	 */
	public function get_permissions_in_scope( $permissions, $scope ) {

		switch ( $scope ) {
			case 'read':
				$permissions[] = __( 'View subscriptions', 'woocommerce-subscriptions' );
			break;
			case 'write':
				$permissions[] = __( 'Create subscriptions', 'woocommerce-subscriptions' );
			break;
			case 'read_write':
				$permissions[] = __( 'View and manage subscriptions', 'woocommerce-subscriptions' );
			break;
		}

		return $permissions;
	}
}
