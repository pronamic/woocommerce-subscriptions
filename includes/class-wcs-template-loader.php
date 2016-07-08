<?php
/**
 * WC Subscriptions Template Loader
 *
 * @version		2.0
 * @author 		Prospress
 */
class WCS_Template_Loader {

	public static function init() {
		add_filter( 'wc_get_template', __CLASS__ . '::add_view_subscription_template', 10, 5 );

		add_action( 'woocommerce_account_view-subscription_endpoint', __CLASS__ . '::get_view_subscription_template' );
	}

	/**
	 * Show the subscription template when view a subscription instead of loading the default order template.
	 *
	 * @param $located
	 * @param $template_name
	 * @param $args
	 * @param $template_path
	 * @param $default_path
	 * @since 2.0
	 */
	public static function add_view_subscription_template( $located, $template_name, $args, $template_path, $default_path ) {
		global $wp;

		if ( 'myaccount/my-account.php' == $template_name && ! empty( $wp->query_vars['view-subscription'] ) && WC_Subscriptions::is_woocommerce_pre( '2.6' ) ) {
			$located = wc_locate_template( 'myaccount/view-subscription.php', $template_path, plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/' );
		}

		return $located;
	}

	/**
	 * Get the view subscription template. A post WC v2.6 compatible version of @see WCS_Template_Loader::add_view_subscription_template()
	 *
	 * @since 2.0.17
	 */
	public static function get_view_subscription_template() {
		wc_get_template( 'myaccount/view-subscription.php', array(), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/' );
	}
}
WCS_Template_Loader::init();
