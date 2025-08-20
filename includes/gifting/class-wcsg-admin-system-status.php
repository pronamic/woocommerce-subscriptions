<?php
/**
 * Handles System report functionality
 *
 * @package WooCommerce Subscriptions Gifting
 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting   2.1.0.
 */

defined( 'ABSPATH' ) || exit;

/**
 * System Status Class
 */
class WCSG_Admin_System_Status {

	/**
	 * Array of Gifting information for display on the System Status page.
	 *
	 * @var array
	 */
	private static $gifting_data = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_system_status_report', array( __CLASS__, 'render_system_status_items' ) );
	}

	/**
	 * Renders Gifting system status report.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	public static function render_system_status_items() {
		self::set_gifting_information();
		self::set_theme_overrides();

		$system_status_sections = array(
			array(
				'title'   => __( 'Subscriptions Gifting', 'woocommerce-subscriptions' ),
				'tooltip' => __( 'This section shows any information about Subscriptions Gifting.', 'woocommerce-subscriptions' ),
				'data'    => apply_filters( 'wcsg_system_status', self::$gifting_data ),
			),
		);

		foreach ( $system_status_sections as $section ) {
			$section_title   = $section['title'];
			$section_tooltip = $section['tooltip'];
			$debug_data      = $section['data'];

			include plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/gifting/admin/status.php';
		}
	}

	/**
	 * Sets the theme overrides area for Subscriptions Gifting.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	private static function set_theme_overrides() {
		$theme_overrides = self::get_theme_overrides();

		if ( ! empty( $theme_overrides['overrides'] ) ) {
			self::$gifting_data['wcsg_theme_overrides'] = array(
				'name'  => _x( 'Subscriptions Gifting Template Theme Overrides', 'name for the system status page', 'woocommerce-subscriptions' ),
				'label' => _x( 'Subscriptions Gifting Template Theme Overrides', 'label for the system status page', 'woocommerce-subscriptions' ),
				'data'  => $theme_overrides['overrides'],
			);

			// Include a note on how to update if the templates are out of date.
			if ( ! empty( $theme_overrides['has_outdated_templates'] ) && true === $theme_overrides['has_outdated_templates'] ) {
				self::$gifting_data['wcsg_theme_overrides'] += array(
					'mark_icon' => 'warning',
					/* Translators: 1) an <a> tag pointing to a doc on how to fix outdated templates, 2) closing </a> tag. */
					'note'      => sprintf( __( '%1$sLearn how to update%2$s', 'woocommerce-subscriptions' ), '<a href="https://docs.woocommerce.com/document/fix-outdated-templates-woocommerce/" target="_blank">', '</a>' ),
				);
			}
		}
	}

	/**
	 * Determine which of our files have been overridden by the theme and if the theme files are outdated.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 * @return array
	 */
	private static function get_theme_overrides() {
		$wcsg_template_dir = dirname( WC_Subscriptions::$plugin_file ) . '/templates/gifting/';
		$wc_template_path  = trailingslashit( wc()->template_path() );
		$theme_root        = trailingslashit( get_theme_root() );
		$overridden        = array();
		$outdated          = false;
		$templates         = WC_Admin_Status::scan_template_files( $wcsg_template_dir );

		foreach ( $templates as $template ) {
			$theme_file = false;
			$locations  = array(
				get_stylesheet_directory() . "/{$template}",
				get_stylesheet_directory() . "/{$wc_template_path}{$template}",
				get_template_directory() . "/{$template}",
				get_template_directory() . "/{$wc_template_path}{$template}",
			);

			foreach ( $locations as $location ) {
				if ( is_readable( $location ) ) {
					$theme_file = $location;
					break;
				}
			}
			if ( ! empty( $theme_file ) ) {
				$core_version               = WC_Admin_Status::get_file_version( $wcsg_template_dir . $template );
				$theme_version              = WC_Admin_Status::get_file_version( $theme_file );
				$overridden_template_output = sprintf( '<code>%s</code>', esc_html( str_replace( $theme_root, '', $theme_file ) ) );
				if ( $core_version && ( empty( $theme_version ) || version_compare( $theme_version, $core_version, '<' ) ) ) {
					$outdated                    = true;
					$overridden_template_output .= sprintf(
						/* translators: %1$s is the file version, %2$s is the core version */
						esc_html__( 'version %1$s is out of date. The core version is %2$s', 'woocommerce-subscriptions' ),
						'<strong style="color:red">' . esc_html( $theme_version ) . '</strong>',
						'<strong>' . esc_html( $core_version ) . '</strong>'
					);
				}
				$overridden['overrides'][] = $overridden_template_output;
			}
		}

		$overridden['has_outdated_templates'] = $outdated;

		return $overridden;
	}

	/**
	 * Gets the number of Gifted Subscriptions and adds it to the system status.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.1.0.
	 */
	private static function set_gifting_information() {
		$gifted_subscriptions_count = WCS_Gifting::get_gifted_subscriptions_count();

		self::$gifting_data['wcsg_gifted_subscriptions_count'] = array(
			'name'  => _x( 'Gifted Subscriptions Count', 'name for the system status page', 'woocommerce-subscriptions' ),
			'label' => _x( 'Gifted Subscriptions Count', 'label for the system status page', 'woocommerce-subscriptions' ),
			'data'  => array( $gifted_subscriptions_count ),
		);
	}
}
