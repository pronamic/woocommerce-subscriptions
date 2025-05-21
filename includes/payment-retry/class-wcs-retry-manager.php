<?php
/**
 * Manage the process of retrying a failed renewal payment that previously failed.
 *
 * @package        WooCommerce Subscriptions
 * @subpackage     WCS_Retry_Manager
 * @category       Class
 * @author         Prospress
 * @since          2.1
 */

class WCS_Retry_Manager {

	/* the rules that control the retry schedule and behaviour of each retry */
	protected static $retry_rules = array();

	/* an instance of the class responsible for storing retry data */
	protected static $store;

	/* the setting ID for enabling/disabling the automatic retry system */
	protected static $setting_id;

	/* property to store the instance of WCS_Retry_Admin */
	protected static $admin;

	/**
	 * Background updater to process retries from old store.
	 *
	 * @var WCS_Retry_Background_Migrator
	 */
	protected static $background_migrator;

	/**
	 * Our table maker instance.
	 *
	 * @var WCS_Table_Maker
	 */
	protected static $table_maker;

	/**
	 * Attach callbacks and set the retry rules
	 *
	 * @codeCoverageIgnore
	 * @since 2.1
	 */
	public static function init() {
		self::$setting_id = WC_Subscriptions_Admin::$option_prefix . '_enable_retry';
		self::$admin      = new WCS_Retry_Admin( self::$setting_id );

		if ( self::is_retry_enabled() ) {
			WCS_Retry_Email::init();

			add_action( 'init', array( __CLASS__, 'init_store' ) );

			add_filter( 'woocommerce_valid_order_statuses_for_payment', __CLASS__ . '::check_order_statuses_for_payment', 10, 2 );

			add_filter( 'woocommerce_subscription_dates', __CLASS__ . '::add_retry_date_type' );

			add_action( 'woocommerce_subscription_status_updated', __CLASS__ . '::maybe_cancel_retry', 0, 3 );

			add_action( 'woocommerce_subscriptions_retry_status_updated', __CLASS__ . '::maybe_delete_payment_retry_date', 0, 2 );

			add_action( 'woocommerce_subscription_renewal_payment_failed', array( __CLASS__, 'maybe_apply_retry_rule' ), 10, 2 );
			add_action( 'woocommerce_subscription_renewal_payment_failed', array( __CLASS__, 'maybe_reapply_last_retry_rule' ), 15, 2 );

			add_action( 'woocommerce_scheduled_subscription_payment_retry', __CLASS__ . '::maybe_retry_payment' );

			add_filter( 'woocommerce_subscriptions_is_failed_renewal_order', __CLASS__ . '::compare_order_and_retry_statuses', 10, 3 );

			add_action( 'plugins_loaded', __CLASS__ . '::load_dependant_classes' );

			add_action( 'woocommerce_subscriptions_before_upgrade', __CLASS__ . '::upgrade', 11, 2 );

			// Attach hooks that depend on WooCommerce being loaded.
			add_action( 'woocommerce_loaded', array( __CLASS__, 'attach_wc_dependant_hooks' ) );

			if ( ! self::$table_maker ) {
				self::$table_maker = new WCS_Retry_Table_Maker();
				add_action( 'init', array( self::$table_maker, 'register_tables' ), 0 );
			}
		}
	}

	/**
	 * Attaches hooks that depend on WooCommerce being loaded.
	 *
	 * We need to use different hooks on stores that have HPOS enabled but to check if this feature
	 * is enabled, we must wait for WooCommerce to be loaded first.
	 *
	 * @since 4.8.0
	 */
	public static function attach_wc_dependant_hooks() {
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			// Ensure scheduled retries are deleted when a renewal order is deleted or trashed.
			add_action( 'woocommerce_before_trash_order', __CLASS__ . '::maybe_cancel_retry_for_order' );
			add_action( 'woocommerce_before_delete_order', __CLASS__ . '::maybe_cancel_retry_for_order' );
		} else {
			// Ensure scheduled retries are deleted when a renewal order is deleted or trashed.
			add_action( 'delete_post', __CLASS__ . '::maybe_cancel_retry_for_order' );
			add_action( 'wp_trash_post', __CLASS__ . '::maybe_cancel_retry_for_order' );
		}
	}

	/**
	 * Adds any extra status that may be needed for a given order to check if it may
	 * need payment
	 *
	 * @param array    $statuses
	 * @param WC_Order $order
	 * @return array
	 * @since 2.2.1
	 */
	public static function check_order_statuses_for_payment( $statuses, $order = null ) {

		$last_retry = self::store()->get_last_retry_for_order( wcs_get_objects_property( $order, 'id' ) );
		if ( $last_retry ) {
			$statuses[] = $last_retry->get_rule()->get_status_to_apply( 'order' );
			$statuses   = array_unique( $statuses );
		}

		return $statuses;
	}

	/**
	 * A helper function to check if the retry system has been enabled or not
	 *
	 * @since 2.1
	 */
	public static function is_retry_enabled() {
		return (bool) apply_filters( 'wcs_is_retry_enabled', 'yes' == get_option( self::$setting_id, 'no' ) );
	}

	/**
	 * Add a renewal retry date type to Subscriptions date types
	 *
	 * @since 2.1
	 */
	public static function add_retry_date_type( $subscription_date_types ) {

		$subscription_date_types = wcs_array_insert_after( 'next_payment', $subscription_date_types, 'payment_retry', _x( 'Renewal Payment Retry', 'table heading', 'woocommerce-subscriptions' ) );

		return $subscription_date_types;
	}

	/**
	 * When a subscription's status is updated, if the new status isn't the expected retry subscription status, cancel the retry.
	 *
	 * @param object $subscription An instance of a WC_Subscription object
	 * @param string $new_status A valid subscription status
	 * @param string $old_status A valid subscription status
	 */
	public static function maybe_cancel_retry( $subscription, $new_status, $old_status ) {

		if ( $subscription->get_date( 'payment_retry' ) > 0 ) {

			$last_order = $subscription->get_last_order( 'all' );
			$last_retry = ( $last_order ) ? self::store()->get_last_retry_for_order( wcs_get_objects_property( $last_order, 'id' ) ) : null;

			if ( null !== $last_retry && 'cancelled' !== $last_retry->get_status() && null !== ( $last_retry_rule = $last_retry->get_rule() ) ) {

				$retry_subscription_status = $last_retry_rule->get_status_to_apply( 'subscription' );
				$applying_retry_rule       = did_action( 'woocommerce_subscriptions_before_apply_retry_rule' ) !== did_action( 'woocommerce_subscriptions_after_apply_retry_rule' );
				$retrying_payment          = did_action( 'woocommerce_subscriptions_before_payment_retry' ) !== did_action( 'woocommerce_subscriptions_after_payment_retry' );

				// If the new status isn't the expected retry subscription status and we aren't in the process of applying a retry rule or retrying payment, cancel the retry
				if ( $new_status != $retry_subscription_status && ! $applying_retry_rule && ! $retrying_payment ) {
					$last_retry->update_status( 'cancelled' );
					$subscription->delete_date( 'payment_retry' );
				}
			}
		}
	}

	/**
	 * When a (renewal) order is trashed or deleted, make sure its retries are also trashed/deleted.
	 *
	 * @param int $order_id
	 */
	public static function maybe_cancel_retry_for_order( $order_id ) {

		if ( 'shop_order' === WC_Data_Store::load( 'order' )->get_order_type( $order_id ) ) {

			$last_retry = self::store()->get_last_retry_for_order( $order_id );

			// Make sure the last retry is cancelled first so that it is unscheduled via self::maybe_delete_payment_retry_date()
			if ( null !== $last_retry && 'cancelled' !== $last_retry->get_status() ) {
				$last_retry->update_status( 'cancelled' );
			}

			foreach ( self::store()->get_retry_ids_for_order( $order_id ) as $retry_id ) {
				self::store()->delete_retry( $retry_id );
			}
		}
	}

	/**
	 * When a retry's status is updated, if it's no longer pending or processing and it's the most recent retry,
	 * delete the retry date on the subscriptions related to the order
	 *
	 * @param object $retry An instance of a WCS_Retry object
	 * @param string $new_status A valid retry status
	 */
	public static function maybe_delete_payment_retry_date( $retry, $new_status ) {
		if ( ! in_array( $new_status, array( 'pending', 'processing' ) ) ) {

			$last_retry = self::store()->get_last_retry_for_order( $retry->get_order_id() );

			if ( $retry->get_id() === $last_retry->get_id() ) {
				foreach ( wcs_get_subscriptions_for_renewal_order( $retry->get_order_id() ) as $subscription ) {
					$subscription->delete_date( 'payment_retry' );
				}
			}
		}

	}

	/**
	 * When a payment fails, apply a retry rule, if one exists that applies to this failure.
	 *
	 * @param WC_Subscription $subscription The subscription on which the payment failed.
	 * @param WC_Order        $last_order   The order on which the payment failed (will be the most recent order on the subscription specified with the subscription param).
	 *
	 * @since 2.1
	 */
	public static function maybe_apply_retry_rule( $subscription, $last_order ) {
		if ( $subscription->is_manual() || ! $subscription->payment_method_supports( 'subscription_date_changes' ) || ! self::is_scheduled_payment_attempt() ) {
			return;
		}

		$retry_count = self::store()->get_retry_count_for_order( wcs_get_objects_property( $last_order, 'id' ) );

		if ( self::rules()->has_rule( $retry_count, wcs_get_objects_property( $last_order, 'id' ) ) ) {

			$retry_rule = self::rules()->get_rule( $retry_count, wcs_get_objects_property( $last_order, 'id' ) );

			do_action( 'woocommerce_subscriptions_before_apply_retry_rule', $retry_rule, $last_order, $subscription );

			$retry_id = self::store()->save( new WCS_Retry( array(
				'status'   => 'pending',
				'order_id' => wcs_get_objects_property( $last_order, 'id' ),
				'date_gmt' => gmdate( 'Y-m-d H:i:s', gmdate( 'U' ) + $retry_rule->get_retry_interval() ),
				'rule_raw' => $retry_rule->get_raw_data(),
			) ) );

			foreach ( array( 'order' => $last_order, 'subscription' => $subscription ) as $object_key => $object ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

				$new_status = $retry_rule->get_status_to_apply( $object_key );

				if ( '' !== $new_status && ! $object->has_status( $new_status ) ) {
					$object->update_status( $new_status, _x( 'Retry rule applied:', 'used in order note as reason for why status changed', 'woocommerce-subscriptions' ) );
				}
			}

			if ( $retry_rule->get_retry_interval() > 0 ) {
				// by calling this after changing the status, this will also schedule the 'woocommerce_scheduled_subscription_payment_retry' action.
				$subscription->update_dates( array( 'payment_retry' => gmdate( 'Y-m-d H:i:s', gmdate( 'U' ) + $retry_rule->get_retry_interval( $retry_count ) ) ) );
			}

			do_action( 'woocommerce_subscriptions_after_apply_retry_rule', $retry_rule, $last_order, $subscription );
		}
	}

	/**
	 * (Maybe) reapply last retry rule if:
	 * - Payment is no-scheduled
	 * - $last_order contains a Retry
	 * - Retry contains a rule
	 *
	 * @param WC_Subscription $subscription The subscription on which the payment failed.
	 * @param WC_Order        $last_order   The order on which the payment failed (will be the most recent order on the subscription specified with the subscription param).
	 *
	 * @since 2.5.0
	 */
	public static function maybe_reapply_last_retry_rule( $subscription, $last_order ) {
		// We're only interested in non-automatic payment attempts.
		if ( self::is_scheduled_payment_attempt() ) {
			return;
		}

		$last_retry = self::store()->get_last_retry_for_order( $last_order->get_id() );
		if ( ! $last_retry || 'pending' !== $last_retry->get_status() || null === ( $last_retry_rule = $last_retry->get_rule() ) ) {
			return;
		}

		foreach ( array( 'order' => $last_order, 'subscription' => $subscription ) as $object_type => $object ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$new_status = $last_retry_rule->get_status_to_apply( $object_type );
			if ( '' !== $new_status && ! $object->has_status( $new_status ) ) {
				$object->update_status( $new_status, _x( 'Retry rule reapplied:', 'used in order note as reason for why status changed', 'woocommerce-subscriptions' ) );
			}
		}
	}

	/**
	 * When a retry hook is triggered, check if the rules for that retry are still valid
	 * and if so, retry the payment.
	 *
	 * @since 2.1.0
	 * @param WC_Order|int $order_id The order on which the payment failed.
	 */
	public static function maybe_retry_payment( $order_id ) {
		$last_order = ! is_object( $order_id ) ? wc_get_order( $order_id ) : $order_id;

		if ( false === $last_order ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $last_order );
		$last_retry    = self::store()->get_last_retry_for_order( wcs_get_objects_property( $last_order, 'id' ) );

		// we only need to retry the payment if we have applied a retry rule for the order and it still needs payment
		if ( null !== $last_retry && is_a( $last_retry, WCS_Retry::class ) && 'pending' === $last_retry->get_status() ) {

			do_action( 'woocommerce_subscriptions_before_payment_retry', $last_retry, $last_order );

			if ( $last_order->needs_payment() ) {

				$last_retry->update_status( 'processing' );

				$expected_order_status = $last_retry->get_rule()->get_status_to_apply( 'order' );
				$valid_order_status    = ( '' == $expected_order_status || $last_order->has_status( $expected_order_status ) );

				$expected_subscription_status = $last_retry->get_rule()->get_status_to_apply( 'subscription' );

				if ( '' == $expected_subscription_status ) {

					$valid_subscription_status = true;

				} else {

					$valid_subscription_status = true;

					foreach ( $subscriptions as $subscription ) {
						if ( ! $subscription->has_status( $expected_subscription_status ) ) {
							$valid_subscription_status = false;
							break;
						}
					}
				}

				// if both statuses are still the same or there no special status was applied and the order still needs payment (i.e. there has been no manual intervention), trigger the payment hook
				if ( $valid_order_status && $valid_subscription_status ) {
					$unique_payment_methods = array();
					$subscription = null;

					$last_order->update_status( 'pending', _x( 'Subscription renewal payment retry:', 'used in order note as reason for why order status changed', 'woocommerce-subscriptions' ), true );

					foreach ( $subscriptions as $subscription ) {
						// Make sure the subscription is on hold in case something goes wrong while trying to process renewal and in case gateways expect the subscription to be on-hold, which is normally the case with a renewal payment
						$subscription->update_status( 'on-hold', _x( 'Subscription renewal payment retry:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' ) );

						// Store a hash of the payment method and payment meta to determine if there's a single payment method being used.
						$payment_meta_hash = md5( $subscription->get_payment_method() . json_encode( $subscription->get_payment_method_meta() ) );
						$unique_payment_methods[ $payment_meta_hash ] = 1;
					}

					// Delete the payment method from the renewal order if the subscription has changed to manual renewal.
					if ( wcs_order_contains_manual_subscription( $last_order, 'renewal' ) ) {
						$last_order->set_payment_method( '' );
						$last_order->add_order_note( 'Renewal payment retry skipped - related subscription has changed to manual renewal.' );

						$last_order->save();
					} elseif ( 1 < count( $unique_payment_methods ) ) {
						// Throw an exception if there is more than 1 unique payment method.
						// This could only occur under circumstances where batch processing renewals has grouped unlike subscriptions.
						throw new Exception( __( 'Payment retry attempted on renewal order with multiple related subscriptions with no payment method in common.', 'woocommerce-subscriptions' ) );
					} else {
						// Before attempting to process payment, update the renewal order's payment method and meta to match the subscription's - in case it has changed.
						wcs_copy_payment_method_to_order( $subscription, $last_order );
						$last_order->save();

						WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $last_order );

						// Now that we've attempted to process the payment, refresh the order
						$last_order = wc_get_order( wcs_get_objects_property( $last_order, 'id' ) );
					}

					// if the order still needs payment, payment failed
					if ( $last_order->needs_payment() ) {
						$last_retry->update_status( 'failed' );
					} else {
						$last_retry->update_status( 'complete' );
					}
				} else {
					// order or subscription statuses have been manually updated, so we'll cancel the retry
					$last_retry->update_status( 'cancelled' );
				}
			} else {
				// last order must have been paid for some other way, so we'll cancel the retry
				$last_retry->update_status( 'cancelled' );
			}

			do_action( 'woocommerce_subscriptions_after_payment_retry', $last_retry, $last_order );
		}
	}

	/**
	* Determines if a renewal order and the last retry statuses are the same (used to determine if a payment method
	* change is needed)
	*
	* @since 2.2.8
	*/
	public static function compare_order_and_retry_statuses( $is_failed_order, $order_id, $order_status ) {

		$last_retry = self::store()->get_last_retry_for_order( $order_id );

		if ( null !== $last_retry && $order_status === $last_retry->get_rule()->get_status_to_apply( 'order' ) ) {
			$is_failed_order = true;
		}

		return $is_failed_order;
	}

	/**
	 * Loads/init our depended classes.
	 *
	 * @since 2.4
	 */
	public static function load_dependant_classes() {
		if ( ! self::$background_migrator ) {
			self::$background_migrator = new WCS_Retry_Background_Migrator( wc_get_logger() );
			add_action( 'init', array( self::$background_migrator, 'init' ), 15 );
		}
	}

	/**
	 * Runs our upgrade background scripts.
	 *
	 * @param string $new_version Version we're upgrading to.
	 * @param string $old_version Version we're upgrading from.
	 *
	 * @since 2.4
	 */
	public static function upgrade( $new_version, $old_version ) {
		if ( '0' !== $old_version && version_compare( $old_version, '2.4', '<' ) ) {
			self::$background_migrator->schedule_repair();
		}

		if ( version_compare( $new_version, '2.4.0', '>' ) ) {
			WCS_Retry_Migrator::set_needs_migration();
		}
	}

	/**
	 * Is `woocommerce_scheduled_subscription_payment` or `woocommerce_scheduled_subscription_payment_retry` current action?
	 *
	 * @return boolean
	 *
	 * @since 2.5.0
	 */
	protected static function is_scheduled_payment_attempt() {
		$doing_action = doing_action( 'woocommerce_scheduled_subscription_payment' ) || doing_action( 'woocommerce_scheduled_subscription_payment_retry' );

		/**
		 * Filter 'Is scheduled payment attempt?'
		 *
		 * @param boolean $doing_action
		 * @since 2.5.0
		 */
		return (bool) apply_filters( 'wcs_is_scheduled_payment_attempt', $doing_action );
	}

	/**
	 * Access the object used to interface with the store.
	 *
	 * @return WCS_Retry_Store
	 * @since 2.4
	 */
	public static function store() {
		if ( empty( self::$store ) ) {
			if ( ! did_action( 'plugins_loaded' ) ) {
				wcs_doing_it_wrong( __METHOD__, 'This method was called before the "plugins_loaded" hook. It applies a filter to the retry data store instantiated. For that to work, it should first be called after all plugins are loaded.', '2.4.1' );
			}

			$class       = self::get_store_class();
			self::$store = new $class();
		}

		return self::$store;
	}

	/**
	 * Get the class used for instantiating retry storage via self::store()
	 *
	 * @since 2.4
	 */
	protected static function get_store_class() {
		$default_store_class = 'WCS_Retry_Database_Store';
		if ( WCS_Retry_Migrator::needs_migration() ) {
			$default_store_class = 'WCS_Retry_Hybrid_Store';
		}

		return apply_filters( 'wcs_retry_store_class', $default_store_class );
	}

	/**
	 * Setup and access the object used to interface with retry rules
	 *
	 * @since 2.1
	 */
	public static function rules() {
		if ( empty( self::$retry_rules ) ) {
			$class = self::get_rules_class();
			self::$retry_rules = new $class();
		}
		return self::$retry_rules;
	}

	/**
	 * Get the class used for instantiating retry rules via self::rules()
	 *
	 * @since 2.1
	 */
	protected static function get_rules_class() {
		return apply_filters( 'wcs_retry_rules_class', 'WCS_Retry_Rules' );
	}

	/**
	 * Initialise the store object used to interface with retry data.
	 *
	 * Hooked onto 'init' to allow third-parties to use their own data store
	 * and to ensure WordPress is fully loaded.
	 *
	 * @since 2.4.1
	 */
	public static function init_store() {
		self::store()->init();
	}
}
