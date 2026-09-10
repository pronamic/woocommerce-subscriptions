<?php
/**
 * A class for managing the customer suspension feature.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Customer_Suspension_Manager {

	/**
	 * Initialise the class.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ), 5 );
		add_action( 'woocommerce_update_options_subscriptions', array( __CLASS__, 'save_max_customer_suspensions' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', array( __CLASS__, 'sanitize_max_customer_suspensions' ) );
		add_filter( 'wcs_can_user_put_subscription_on_hold', array( __CLASS__, 'can_customer_put_subscription_on_hold' ), 0, 3 );
		add_filter( 'wcs_view_subscription_actions', array( __CLASS__, 'add_customer_suspension_action' ), 0, 3 );
	}

	/**
	 * Adds the customer suspension settings.
	 *
	 * The single legacy dropdown (`_max_customer_suspensions`: 0-12 / unlimited) is presented as three
	 * progressive controls — an enable checkbox, a limit checkbox, and a per-billing-period number — matching the
	 * redesigned settings screen. The three are display-only (`is_option => false`); they are folded back into the
	 * single stored option by {@see self::save_max_customer_suspensions()} on save, and their initial state is
	 * derived from that same option, so the storage and every consumer stay unchanged.
	 *
	 * @since 4.0.0
	 *
	 * @param  array $settings Subscriptions settings.
	 * @return array Subscriptions settings.
	 */
	public static function add_settings( $settings ) {
		$prefix   = WC_Subscriptions_Admin::$option_prefix;
		$defaults = self::get_control_defaults();

		self::fire_deprecated_suspension_range_filter();

		$per_period_attributes = array(
			// Reveal only once the limit is enabled (reusable behaviour, see assets/js/admin/admin.js).
			'data-show-if-checked' => $prefix . '_max_customer_suspensions_limit',
			'min'                  => '1',
			'step'                 => '1',
		);

		// `is_option => false` keeps these display controls out of the classic save (no junk options); the explicit
		// `save` adapter still opts them into the modern form POST, so save_max_customer_suspensions() can fold them
		// back into the single stored option on a modern save just as it does on a classic one.
		$form_post = array( 'adapter' => 'form_post' );

		$suspension_settings = array(
			array(
				'name'      => __( 'Customer suspensions', 'woocommerce-subscriptions' ),
				'desc'      => __( 'Enable subscriber suspensions', 'woocommerce-subscriptions' ),
				'id'        => $prefix . '_max_customer_suspensions_enable',
				'default'   => $defaults['enable'],
				// The explicit `value` makes the derived state authoritative: without it the renderer falls back to
				// get_option( id, default ), and a stray option row stored under this display-only id would mask it.
				'value'     => $defaults['enable'],
				'type'      => 'checkbox',
				'class'     => \Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings\Classic_Renderer::CLASS_HIDE_CHECKBOX_TITLE,
				'is_option' => false,
				'save'      => $form_post,
			),
			array(
				'name'              => __( 'Limit Suspensions', 'woocommerce-subscriptions' ),
				'desc'              => __( 'Limit the number of times a subscriber can suspend their subscription', 'woocommerce-subscriptions' ),
				'id'                => $prefix . '_max_customer_suspensions_limit',
				'default'           => $defaults['limit'],
				'value'             => $defaults['limit'],
				'type'              => 'checkbox',
				'class'             => \Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings\Classic_Renderer::CLASS_HIDE_CHECKBOX_TITLE,
				'is_option'         => false,
				'save'              => $form_post,
				// Reveal only once suspensions are enabled (reusable behaviour, see assets/js/admin/admin.js).
				'custom_attributes' => array(
					'data-show-if-checked' => $prefix . '_max_customer_suspensions_enable',
				),
			),
			array(
				'name'              => __( 'Suspensions per Billing Period', 'woocommerce-subscriptions' ),
				'id'                => $prefix . '_max_customer_suspensions_per_period',
				'default'           => $defaults['per_period'],
				'value'             => $defaults['per_period'],
				'type'              => 'number',
				'is_option'         => false,
				'save'              => $form_post,
				'custom_attributes' => $per_period_attributes,
			),
		);

		WC_Subscriptions_Admin::insert_setting_after( $settings, $prefix . '_miscellaneous', $suspension_settings, 'multiple_settings' );
		return $settings;
	}

	/**
	 * Fires the deprecated suspension-range filter, discarding whatever it returns.
	 *
	 * The filter described the option list of the legacy 0-12 / Unlimited dropdown. The redesigned controls are
	 * a checkbox pair plus a free numeric stepper, so there is no option list to filter and no faithful way to
	 * honour the return value: a callback restricting the range to even numbers, or adding a custom non-numeric
	 * entry alongside 'unlimited', cannot be expressed as a bound on a stepper. Interpreting it as a cap would
	 * support only the `min <= value <= max` subset while silently ignoring the rest, which is a less honest
	 * break than announcing the removal.
	 *
	 * The filter's one documented purpose - letting a store allow more than 12 suspensions - is now the default
	 * behaviour, since the stepper is unbounded. A callback that needs to control the effective allowance at
	 * runtime should filter the option itself via `pre_option_woocommerce_subscriptions_max_customer_suspensions`.
	 *
	 * Kept as a deprecated no-op call so existing callbacks keep running (and hosts surface the notice) rather
	 * than disappearing silently.
	 *
	 * @since 9.2.0
	 */
	private static function fire_deprecated_suspension_range_filter() {
		apply_filters_deprecated(
			'woocommerce_subscriptions_max_customer_suspension_range',
			array( array_merge( range( 0, 12 ), array( 'unlimited' => 'Unlimited' ) ) ),
			'9.2.0',
			'pre_option_woocommerce_subscriptions_max_customer_suspensions',
			'The redesigned suspension settings have no option list to filter, so the returned range is ignored.'
		);
	}

	/**
	 * Derives the display state of the three suspension controls from the single stored option.
	 *
	 * @return array{enable:string, limit:string, per_period:int}
	 */
	private static function get_control_defaults() {
		$max            = get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', '0' );
		$is_numeric_cap = is_numeric( $max ) && (int) $max >= 1;

		return array(
			'enable'     => ( '0' === (string) $max || '' === (string) $max ) ? 'no' : 'yes',
			'limit'      => $is_numeric_cap ? 'yes' : 'no',
			'per_period' => $is_numeric_cap ? (int) $max : 1,
		);
	}

	/**
	 * Folds the three display controls into the single stored `_max_customer_suspensions` option.
	 *
	 * Enabled + limited → the number (min 1); enabled + unlimited → 'unlimited'; disabled → '0'. The number field
	 * always posts on a submit that includes these controls — on both the classic tab and (via its `form_post` save
	 * adapter) the modern renderer — so its absence marks a submission that did not include them, in which case the
	 * stored value is left untouched. The checkboxes are read through {@see wcs_is_setting_checked()} so both the
	 * classic (absent-is-off) and modern (explicit `false`/`'no'`) submission conventions resolve correctly.
	 *
	 * @return void
	 */
	public static function save_max_customer_suspensions() {
		$prefix         = WC_Subscriptions_Admin::$option_prefix;
		$per_period_key = $prefix . '_max_customer_suspensions_per_period';

		if ( ! wcs_is_verified_settings_form_submission( $per_period_key ) ) {
			return;
		}

		$enabled = wcs_is_setting_checked( $prefix . '_max_customer_suspensions_enable' );
		$limited = wcs_is_setting_checked( $prefix . '_max_customer_suspensions_limit' );
		$number  = absint( wp_unslash( $_POST[ $per_period_key ] ?? 0 ) );

		update_option( $prefix . '_max_customer_suspensions', self::pack_value( $enabled, $limited, $number ) );
	}

	/**
	 * Packs the three control values into the single stored option's value.
	 *
	 * Returns a string in every case: the option has always been stored as a string (the legacy dropdown saved
	 * `'0'`..`'12'` / `'unlimited'` through WooCommerce's native option save), and {@see self::add_customer_suspension_action()}
	 * strict-compares the disabled state against `'0'`. Returning an integer here would store `0` for that request's
	 * cache and defeat the strict comparison.
	 *
	 * @param bool $enabled Whether subscriber suspensions are enabled.
	 * @param bool $limited Whether a per-period limit applies.
	 * @param int  $number  The per-period limit (used only when enabled and limited).
	 * @return string `'0'`, `'unlimited'`, or the capped number (min 1) as a string.
	 */
	public static function pack_value( $enabled, $limited, $number ) {
		if ( ! $enabled ) {
			return '0';
		}

		if ( ! $limited ) {
			return 'unlimited';
		}

		return (string) max( 1, (int) $number );
	}

	/**
	 * Validate a generic-save write of the suspensions maximum.
	 *
	 * The settings form never saves this option through WC_Admin_Settings::save_fields() - its
	 * display controls are packed by {@see self::save_max_customer_suspensions()} - so this filter is
	 * effectively the REST settings API's validator. It keeps the writable domain identical to what
	 * the form can produce: a non-negative number of suspensions per billing period, or 'unlimited'.
	 * Invalid input keeps the stored value unchanged: WooCommerce's `number` settings entries have no
	 * rejection seam, and a silent no-change mirrors the form's own coerce-rather-than-reject
	 * behavior - the next read reports the truth.
	 *
	 * @since 9.2.0
	 *
	 * @param mixed $value The submitted value.
	 * @return string The value to store.
	 */
	public static function sanitize_max_customer_suspensions( $value ) {
		if ( 'unlimited' === $value ) {
			return 'unlimited';
		}

		if ( is_numeric( $value ) && (int) $value >= 0 ) {
			return (string) (int) $value;
		}

		$stored = get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', '0' );

		return is_scalar( $stored ) ? (string) $stored : '0';
	}

	/**
	 * Filters whether the current user can suspend the subscription.
	 *
	 * Allows the customer to suspend the subscription if the _max_customer_suspensions setting hasn't been reached.
	 *
	 * @since 4.0.0
	 *
	 * @param bool            $can_user_suspend Whether the current user can suspend the subscrption determined by @see wcs_can_user_put_subscription_on_hold().
	 * @param WC_Subscription $subscription     The subscription.
	 * @param WP_User         $user             The current user.
	 *
	 * @return bool Whether the subscription can be suspended by the user.
	 */
	public static function can_customer_put_subscription_on_hold( $can_user_suspend, $subscription, $user ) {

		// Exit early if the customer can already suspend the subscription.
		if ( $can_user_suspend ) {
			return $can_user_suspend;
		}

		// We're only interested in the customer who owns the subscription.
		if ( $subscription->get_user_id() !== $user->ID ) {
			return $can_user_suspend;
		}

		// Make sure subscription suspension count hasn't been reached
		$suspension_count    = intval( $subscription->get_suspension_count() );
		$allowed_suspensions = self::get_allowed_customer_suspensions();

		if ( 'unlimited' === $allowed_suspensions || $allowed_suspensions > $suspension_count ) { // 0 not > anything so prevents a customer ever being able to suspend
			$can_user_suspend = true;
		}

		return $can_user_suspend;
	}

	/**
	 * Adds the customer suspension action, if allowed.
	 *
	 * @since 4.0.0
	 *
	 * @param array           $actions      The actions a customer/user can make with a subscription.
	 * @param WC_Subscription $subscription The subscription.
	 * @param int             $user_id      The user viewing the subscription.
	 *
	 * @return array The customer's subscription actions.
	 */
	public static function add_customer_suspension_action( $actions, $subscription, $user_id ) {

		if ( ! $subscription->can_be_updated_to( 'on-hold' ) ) {
			return $actions;
		}

		if ( ! user_can( $user_id, 'edit_shop_subscription_status', $subscription->get_id() ) ) {
			return $actions;
		}

		if ( '0' === self::get_allowed_customer_suspensions() ) {
			return $actions;
		}

		if ( current_user_can( 'manage_woocommerce' ) || wcs_can_user_put_subscription_on_hold( $subscription, $user_id ) ) {
			$actions['suspend'] = array(
				'url'  => wcs_get_users_change_status_link( $subscription->get_id(), 'on-hold', $subscription->get_status() ),
				'name' => __( 'Suspend', 'woocommerce-subscriptions' ),
			);
		}

		return $actions;
	}

	/**
	 * Gets the number of suspensions a customer can make per billing period.
	 *
	 * @since 4.0.0
	 * @return string The number of suspensions a customer can make per billing period. Can 'unlimited' or the number of suspensions allowed.
	 */
	public static function get_allowed_customer_suspensions() {
		return get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', '0' );
	}
}
