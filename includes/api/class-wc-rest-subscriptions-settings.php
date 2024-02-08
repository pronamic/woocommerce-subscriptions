<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC REST API Subscriptions Settings class.
 *
 * Adds subscription settings to the wc/<version>/settings and wc/<version>/settings/{group_id} endpoint.
 */
class WC_REST_Subscriptions_Settings {

	/**
	 * Init class and attach callbacks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_settings_groups', [ $this, 'add_settings_group' ] );
		add_filter( 'woocommerce_settings-subscriptions', [ $this, 'add_settings' ] );
	}

	/**
	 * Register the subscriptions settings group for use in the WC REST API /settings endpoint
	 *
	 * @param array $groups Array of setting groups.
	 *
	 * @return array
	 */
	public function add_settings_group( $groups ) {
		$groups[] = [
			'id'    => 'subscriptions',
			'label' => __( 'Subscriptions', 'woocommerce-subscriptions' ),
		];
		return $groups;
	}

	/**
	 * Add subscriptions specific settings to the WC REST API /settings/subscriptions endpoint.
	 *
	 * @param array $settings Array of settings.
	 *
	 * @return array
	 */
	public function add_settings( $settings ) {
		$subscription_settings = WC_Subscriptions_Admin::get_settings();

		foreach( $subscription_settings as $setting ) {
			// Skip settings that don't have a id, type or are an invalid setting type i.e. skip over title, sectionend, etc.
			if ( empty( $setting['id'] ) || empty( $setting['type'] ) || ! $this->is_setting_type_valid( $setting['type'] ) ) {
				continue;
			}

			$settings[] = $this->format_setting( $setting );
		}

		return $settings;
	}

	/**
	 * Checks if a setting type is a valid supported setting type.
	 *
	 * @param string $type Type.
	 *
	 * @return bool
	 */
	private function is_setting_type_valid( $type ) {
		return in_array( $type, [ 'text', 'email', 'number', 'color', 'password', 'textarea', 'select', 'multiselect', 'radio', 'checkbox', 'image_width', 'thumbnail_cropping' ], true );
	}

	/**
	 * Returns the subscriptions setting in the format expected by the WC /settings REST API.
	 *
	 * @param array $setting Subscription setting.
	 *
	 * @return array|bool
	 */
	private function format_setting( $setting ) {
		$description = '';

		if ( ! empty( $setting['desc'] ) ) {
			$description = $setting['desc'];
		} elseif ( ! empty( $setting['description'] ) ) {
			$description = $setting['description'];
		}

		$new_setting = [
			'id'          => $setting['id'],
			'label'       => ! empty( $setting['title'] ) ? $setting['title'] : '',
			'description' => $description,
			'type'        => $setting['type'],
			'option_key'  => $setting['id'],
			'default'     => ! empty( $setting['default'] ) ? $setting['default'] : '',
		];

		if ( isset( $setting['options'] ) ) {
			$new_setting['options'] = $setting['options'];
		}

		if ( isset( $setting['desc_tip'] ) ) {
			if ( true === $setting['desc_tip'] ) {
				$new_setting['tip'] = $description;
			} elseif ( ! empty( $setting['desc_tip'] ) ) {
				$new_setting['tip'] = $setting['desc_tip'];
			}
		}

		return $new_setting;
	}
}
