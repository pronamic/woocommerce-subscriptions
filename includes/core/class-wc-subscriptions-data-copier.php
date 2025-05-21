<?php
/**
 * Woocommerce Subscriptions Data Copier
 */

defined( 'ABSPATH' ) || exit;

class WC_Subscriptions_Data_Copier {

	/**
	 * The default copy type.
	 */
	const DEFAULT_COPY_TYPE = 'subscription';

	/**
	 * The default data keys that are excluded from the copy.
	 *
	 * @var string[]
	 */
	const DEFAULT_EXCLUDED_META_KEYS = [
		'_paid_date',
		'_date_paid',
		'_completed_date',
		'_date_completed',
		'_edit_last',
		'_subscription_switch_data',
		'_order_key',
		'_edit_lock',
		'_wc_points_earned',
		'_transaction_id',
		'_billing_interval',
		'_billing_period',
		'_subscription_resubscribe',
		'_subscription_renewal',
		'_subscription_switch',
		'_payment_method',
		'_payment_method_title',
		'_suspension_count',
		'_requires_manual_renewal',
		'_cancelled_email_sent',
		'_last_order_date_created',
		'_trial_period',
		'_created_via',
		'_order_stock_reduced',
		'id',
	];

	/**
	 * The subscription or order being copied.
	 *
	 * @var WC_Order
	 */
	private $from_object = null;

	/**
	 * The subscription or order being copied to.
	 *
	 * @var WC_Order
	 */
	private $to_object = null;

	/**
	 * The type of copy. Can be 'subscription' or 'renewal'.
	 *
	 * Used in dynamic filters to allow third parties to target specific meta keys in different copying contexts.
	 *
	 * @var string
	 */
	private $copy_type = '';

	/**
	 * Copies data from one object to another.
	 *
	 * This function acts as a publicly accessible wrapper for obtaining an instance of the copier and completing the copy.
	 *
	 * @param WC_Order $from_object The object to copy data from.
	 * @param WC_Order $to_object   The object to copy data to.
	 * @param string   $copy_type   Optional. The type of copy. Can be 'subscription', 'parent', 'renewal_order' or 'resubscribe_order'. Default is 'subscription'.
	 */
	public static function copy( $from_object, $to_object, $copy_type = self::DEFAULT_COPY_TYPE ) {
		$instance = new self( $from_object, $to_object, $copy_type );
		$instance->copy_data();
	}

	/**
	 * Constructor.
	 *
	 * @param WC_Order $from_object The object to copy data from.
	 * @param WC_Order $to_object   The object to copy data to.
	 * @param string   $copy_type   Optional. The type of copy. Can be 'subscription', 'parent', 'renewal_order' or 'resubscribe_order'. Default is 'subscription'.
	 */
	public function __construct( $from_object, $to_object, $copy_type = self::DEFAULT_COPY_TYPE ) {
		$this->from_object = $from_object;
		$this->to_object   = $to_object;
		$this->copy_type   = $copy_type;
	}

	/**
	 * Copies the data from the "from" object to the "to" object.
	 */
	public function copy_data() {

		if ( ! wcs_is_custom_order_tables_usage_enabled() ) {
			$data_array = $GLOBALS['wpdb']->get_results( $this->get_deprecated_meta_query(), ARRAY_A );
			$data       = wp_list_pluck( $data_array, 'meta_value', 'meta_key' );
		} else {
			$data  = $this->get_meta_data();
			$data += $this->get_order_data();
			$data += $this->get_operational_data();
			$data += $this->get_address_data();

			// Payment token meta isn't accounted from in the above methods, so we need to add it separately.
			if ( ! isset( $data['_payment_tokens'] ) ) {
				$tokens = $this->from_object->get_payment_tokens();

				if ( ! empty( $tokens ) ) {
					$data['_payment_tokens'] = $tokens;
				}
			}

			// Remove any excluded meta keys.
			$data = $this->filter_excluded_meta_keys_via_query( $data );
		}

		$data = $this->apply_deprecated_filter( $data );

		/**
		 * Filters the data to be copied from one object to another.
		 *
		 * This filter name contains a dynamic part, $this->copy_type. The full set of hooks include:
		 *     - wc_subscriptions_subscription_data
		 *     - wc_subscriptions_parent_data
		 *     - wc_subscriptions_renewal_order_data
		 *     - wc_subscriptions_resubscribe_order_data
		 *
		 * @since subscriptions-core 2.5.0
		 *
		 * @param array    $data {
		 *     The data to be copied to the "to" object. Each value is keyed by the meta key. Example format [ '_meta_key' => 'meta_value' ].
		 *
		 *     @type mixed $meta_value The meta value to be copied.
		 * }
		 * @param WC_Order $from_object The object to copy data from.
		 * @param WC_Order $to_object   The object to copy data to.
		 */
		$data = apply_filters( "wc_subscriptions_{$this->copy_type}_data", $data, $this->to_object, $this->from_object );

		/**
		 * Filters the data to be copied from one object to another.
		 *
		 * @since subscriptions-core 2.5.0
		 *
		 * @param array    $data {
		 *     The data to be copied to the "to" object. Each value is keyed by the meta key. Example format [ '_meta_key' => 'meta_value' ].
		 *
		 *     @type mixed $meta_value The meta value to be copied.
		 * }
		 * @param WC_Order $from_object The object to copy data from.
		 * @param WC_Order $to_object   The object to copy data to.
		 */
		$data = apply_filters( 'wc_subscriptions_object_data', $data, $this->to_object, $this->from_object, $this->copy_type );

		foreach ( $data as $key => $value ) {
			$this->set_data( $key, maybe_unserialize( $value ) );
		}

		$this->to_object->save();
	}

	/**
	 * Sets a piece of data on the "to" object.
	 *
	 * This function uses a setter where appropriate, otherwise it sets the data directly.
	 * Values which are stored as a bool in memory are converted before being set. eg 'no' -> false, 'yes' -> true.
	 *
	 * @param string $key   The data key to set.
	 * @param mixed  $value The value to set.
	 */
	private function set_data( $key, $value ) {

		// WC will automatically set/update these keys when a shipping/billing address attribute changes so we can ignore these keys.
		if ( in_array( $key, [ '_shipping_address_index', '_billing_address_index' ], true ) ) {
			return;
		}

		// The WC_Order setter for these keys will expect an array of values, return early if the value is not an array.
		if (
			in_array( $key, [ '_shipping_address', '_shipping', '_billing_address', '_billing' ], true )
			&& ! is_array( $value )
		) {
			return;
		}

		// Special cases where properties with setters don't map nicely to their function names.
		$setter_map = [
			'_cart_discount'      => 'set_discount_total',
			'_cart_discount_tax'  => 'set_discount_tax',
			'_customer_user'      => 'set_customer_id',
			'_order_tax'          => 'set_cart_tax',
			'_order_shipping'     => 'set_shipping_total',
			'_order_currency'     => 'set_currency',
			'_order_shipping_tax' => 'set_shipping_tax',
			'_order_total'        => 'set_total',
			'_order_version'      => 'set_version',
		];

		$setter = isset( $setter_map[ $key ] ) ? $setter_map[ $key ] : 'set_' . ltrim( $key, '_' );

		if ( is_callable( [ $this->to_object, $setter ] ) ) {
			// Re-bool the value before setting it. Setters like `set_prices_include_tax()` expect a bool.
			if ( is_string( $value ) && in_array( $value, [ 'yes', 'no' ], true ) ) {
				$value = 'yes' === $value;
			}

			$this->to_object->{$setter}( $value );
		} elseif ( '_payment_tokens' === $key ) {
			// Payment tokens don't have a setter and cannot be set via metadata so we need to set them via the datastore.
			$this->to_object->get_data_store()->update_payment_token_ids( $this->to_object, $value );
		} else {
			$this->to_object->update_meta_data( $key, $value );
		}
	}

	/**
	 * Determines if there are callbacks attached to the deprecated "wcs_{$this->copy_type}_meta_query" filter.
	 *
	 * @return bool True if there are callbacks attached to the deprecated "wcs_{$this->copy_type}_meta_query" filter. False otherwise.
	 */
	private function has_filter_on_meta_query_hook() {
		return has_filter( "wcs_{$this->copy_type}_meta_query" );
	}

	/**
	 * Gets the "from" object's meta data.
	 *
	 * @return string[] The meta data.
	 */
	private function get_meta_data() {
		$meta_data = [];

		foreach ( $this->from_object->get_meta_data() as $meta ) {
			$meta_data[ $meta->key ] = $meta->value;
		}

		return $meta_data;
	}

	/**
	 * Gets the "from" object's operational data that was previously stored in wp post meta.
	 *
	 * @return string[] The operational data with the legacy meta key.
	 */
	private function get_operational_data() {
		return [
			'_created_via'                  => $this->from_object->get_created_via( 'edit' ),
			'_order_version'                => $this->from_object->get_version( 'edit' ),
			'_prices_include_tax'           => wc_bool_to_string( $this->from_object->get_prices_include_tax( 'edit' ) ),
			'_recorded_coupon_usage_counts' => wc_bool_to_string( $this->from_object->get_recorded_coupon_usage_counts( 'edit' ) ),
			'_download_permissions_granted' => wc_bool_to_string( $this->from_object->get_download_permissions_granted( 'edit' ) ),
			'_cart_hash'                    => $this->from_object->get_cart_hash( 'edit' ),
			'_new_order_email_sent'         => wc_bool_to_string( $this->from_object->get_new_order_email_sent( 'edit' ) ),
			'_order_key'                    => $this->from_object->get_order_key( 'edit' ),
			'_order_stock_reduced'          => $this->from_object->get_order_stock_reduced( 'edit' ),
			'_date_paid'                    => $this->from_object->get_date_paid( 'edit' ),
			'_date_completed'               => $this->from_object->get_date_completed( 'edit' ),
			'_order_shipping_tax'           => $this->from_object->get_shipping_tax( 'edit' ),
			'_order_shipping'               => $this->from_object->get_shipping_total( 'edit' ),
			'_cart_discount_tax'            => $this->from_object->get_discount_tax( 'edit' ),
			'_cart_discount'                => $this->from_object->get_discount_total( 'edit' ),
			'_recorded_sales'               => wc_bool_to_string( $this->from_object->get_recorded_sales( 'edit' ) ),
		];
	}

	/**
	 * Gets the "from" object's core data that was previously stored in wp post meta.
	 *
	 * @return string[] The core data with the legacy meta keys.
	 */
	private function get_order_data() {
		return [
			'_order_currency'       => $this->from_object->get_currency( 'edit' ),
			'_order_tax'            => $this->from_object->get_cart_tax( 'edit' ),
			'_order_total'          => $this->from_object->get_total( 'edit' ),
			'_customer_user'        => $this->from_object->get_customer_id( 'edit' ),
			'_billing_email'        => $this->from_object->get_billing_email( 'edit' ),
			'_payment_method'       => $this->from_object->get_payment_method( 'edit' ),
			'_payment_method_title' => $this->from_object->get_payment_method_title( 'edit' ),
			'_customer_ip_address'  => $this->from_object->get_customer_ip_address( 'edit' ),
			'_customer_user_agent'  => $this->from_object->get_customer_user_agent( 'edit' ),
			'_transaction_id'       => $this->from_object->get_transaction_id( 'edit' ),
		];
	}

	/**
	 * Gets the "from" object's address data that was previously stored in wp post meta.
	 *
	 * @return string[] The address data with the legacy meta keys.
	 */
	private function get_address_data() {
		return array_filter(
			[
				'_billing_first_name'  => $this->from_object->get_billing_first_name( 'edit' ),
				'_billing_last_name'   => $this->from_object->get_billing_last_name( 'edit' ),
				'_billing_company'     => $this->from_object->get_billing_company( 'edit' ),
				'_billing_address_1'   => $this->from_object->get_billing_address_1( 'edit' ),
				'_billing_address_2'   => $this->from_object->get_billing_address_2( 'edit' ),
				'_billing_city'        => $this->from_object->get_billing_city( 'edit' ),
				'_billing_state'       => $this->from_object->get_billing_state( 'edit' ),
				'_billing_postcode'    => $this->from_object->get_billing_postcode( 'edit' ),
				'_billing_country'     => $this->from_object->get_billing_country( 'edit' ),
				'_billing_email'       => $this->from_object->get_billing_email( 'edit' ),
				'_billing_phone'       => $this->from_object->get_billing_phone( 'edit' ),
				'_shipping_first_name' => $this->from_object->get_shipping_first_name( 'edit' ),
				'_shipping_last_name'  => $this->from_object->get_shipping_last_name( 'edit' ),
				'_shipping_company'    => $this->from_object->get_shipping_company( 'edit' ),
				'_shipping_address_1'  => $this->from_object->get_shipping_address_1( 'edit' ),
				'_shipping_address_2'  => $this->from_object->get_shipping_address_2( 'edit' ),
				'_shipping_city'       => $this->from_object->get_shipping_city( 'edit' ),
				'_shipping_state'      => $this->from_object->get_shipping_state( 'edit' ),
				'_shipping_postcode'   => $this->from_object->get_shipping_postcode( 'edit' ),
				'_shipping_country'    => $this->from_object->get_shipping_country( 'edit' ),
				'_shipping_phone'      => $this->from_object->get_shipping_phone( 'edit' ),
			]
		);
	}

	/**
	 * Removes the meta keys excluded via the deprecated from the set of data to be copied.
	 *
	 * @param array $data The data to be copied.
	 * @return array The data to be copied with the excluded keys removed.
	 */
	public function filter_excluded_meta_keys_via_query( $data ) {
		$excluded_keys = $this->get_excluded_data_keys();

		foreach ( $data as $meta_key => $meta_value ) {
			if ( isset( $excluded_keys['in'] ) && in_array( $meta_key, $excluded_keys['in'], true ) ) {
				unset( $data[ $meta_key ] );
			} elseif ( isset( $excluded_keys['regex'] ) ) {
				foreach ( $excluded_keys['regex'] as $regex ) {
					if ( preg_match( $regex, $meta_key ) ) {
						unset( $data[ $meta_key ] );
						break;
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Returns the deprecated meta database query that returns the "from" objects meta data.
	 *
	 * Triggers a deprecation notice if the deprecated "wcs_{$this->copy_type}_meta_query" filter is in use by at least 1 third-party.
	 *
	 * @return string SQL SELECT query.
	 */
	private function get_deprecated_meta_query() {
		global $wpdb;

		$meta_query = sprintf(
			"SELECT `meta_key`, `meta_value`
			 FROM %s
			 WHERE `post_id` = %d
			 AND `meta_key` NOT LIKE '%s'
			 AND `meta_key` NOT IN ('%s')",
			$wpdb->postmeta,
			$this->from_object->get_id(),
			'_schedule_%',
			implode( "', '", self::DEFAULT_EXCLUDED_META_KEYS )
		);

		if ( in_array( $this->copy_type, [ 'renewal_order', 'parent' ], true ) ) {
			$meta_query .= " AND `meta_key` NOT LIKE '_download_permissions_granted' ";
		}

		if ( $this->has_filter_on_meta_query_hook() ) {
			/**
			 * Filters the data to be copied from one object to another.
			 *
			 * This filter name contains a dynamic part, $this->copy_type. The full set of hooks include:
			 *     - wcs_subscription_meta_query
			 *     - wcs_parent_meta_query
			 *     - wcs_renewal_order_meta_query
			 *     - wcs_resubscribe_order_meta_query
			 *
			 * @deprecated subscriptions-core 2.5.0
			 *
			 * @param string   $meta_query        The SQL query to fetch the meta data to be copied.
			 * @param WC_Order $this->to_object   The object to copy data to.
			 * @param WC_Order $this->from_object The object to copy data from.
			 */
			$meta_query = apply_filters( "wcs_{$this->copy_type}_meta_query", $meta_query, $this->to_object, $this->from_object );
			wcs_deprecated_hook( "wcs_{$this->copy_type}_meta_query", 'subscriptions-core 2.5.0', "wc_subscriptions_{$this->copy_type}_data" );
		}

		return $meta_query;
	}

	/**
	 * Applies the deprecated "wcs_{$this->copy_type}_meta filter.
	 *
	 * Triggers a deprecation notice if the deprecated "wcs_{$this->copy_type}_meta" filter is in use by at least 1 third-party.
	 *
	 * @param array $data The data to copy.
	 * @return array The filtered set of data to copy.
	 */
	private function apply_deprecated_filter( $data ) {
		// Only continue if the filter is use.
		if ( ! has_filter( "wcs_{$this->copy_type}_meta" ) ) {
			return $data;
		}

		// Convert the data into the backwards compatible format ready for filtering - wpdb's ARRAY_A format.
		$data_array = [];

		foreach ( $data as $key => $value ) {
			$data_array[] = [
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- This is a meta key, not a query.
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- This is a meta value, not a query.
			];
		}

		wcs_deprecated_hook( "wcs_{$this->copy_type}_meta", 'wcs-core 2.5.0', "wc_subscriptions_{$this->copy_type}_data" );

		/**
		 * Filters the data to be copied from one object to another.
		 *
		 * This filter name contains a dynamic part, $this->copy_type. The full set of hooks include:
		 *     - wcs_subscription_meta
		 *     - wcs_parent_meta
		 *     - wcs_renewal_order_meta
		 *     - wcs_resubscribe_order_meta
		 *
		 * @deprecated subscriptions-core 2.5.0
		 *
		 * @param array[]    $data_array {
		 *     The metadata to be copied to the "to" object.
		 *
		 *     @type array $meta_data {
		 *          The metadata to be copied.
		 *
		 *          @type string $meta_key   The meta key to be copied.
		 *          @type mixed  $meta_value The meta value to be copied.
		 *     }
		 * }
		 * @param WC_Order $this->to_object   The object to copy data to.
		 * @param WC_Order $this->from_object The object to copy data from.
		 */
		$data_array = apply_filters( "wcs_{$this->copy_type}_meta", $data_array, $this->to_object, $this->from_object );

		// Return the data to a key => value format.
		return wp_list_pluck( $data_array, 'meta_value', 'meta_key' );
	}

	/**
	 * Gets a list of meta keys to exclude from the copy.
	 *
	 * If third-parties are hooked onto the "wcs_{$this->copy_type}_meta_query" filter, this function will attempt
	 * to pluck the excluded meta keys from the filtered SQL query. There is no guarantee that this will work for all
	 * queries, however it should work under most standard circumstances.
	 *
	 * If no third-parties are hooked onto the "wcs_{$this->copy_type}_meta_query" filter, this function will simply return
	 * the default list of excluded meta keys.
	 *
	 * @return string[][] An array of excluded meta keys. The array has two keys: 'in' and 'regex'. The 'in' key contains an array of meta keys to exclude. The 'regex' key contains an array of regular expressions to exclude.
	 */
	private function get_excluded_data_keys() {
		$excluded_keys = [];

		// If there are no third-parties hooked into the deprecated filter, there is no need to parse the query.
		if ( ! $this->has_filter_on_meta_query_hook() ) {
			$excluded_keys['in'] = self::DEFAULT_EXCLUDED_META_KEYS;

			if ( in_array( $this->copy_type, [ 'renewal_order', 'parent' ], true ) ) {
				$excluded_keys['regex'][] = $this->get_keys_from_like_clause( '_download_permissions_granted' );
			}

			return $excluded_keys;
		}

		// Get the deprecated meta query and attempt to pull the excluded keys from it.
		$meta_query = $this->get_deprecated_meta_query();

		// Normalize the query.
		$meta_query = str_replace( [ "\r", "\n", "\t" ], ' ', $meta_query ); // Remove line breaks, tabs, etc.
		$meta_query = preg_replace( '/\s+/', ' ', $meta_query ); // Remove duplicate whitespace.
		$meta_query = str_replace( '`', '', $meta_query ); // Remove backticks.
		$meta_query = str_replace( '"', "'", $meta_query ); // Replace double quotes with single quotes.

		// Handle all the NOT LIKE clauses.
		preg_match_all( "/meta_key NOT LIKE '(.*?)'/", $meta_query, $not_like_clauses );

		if ( ! empty( $not_like_clauses[1] ) ) {
			foreach ( $not_like_clauses[1] as $not_like_clause ) {
				$excluded_keys['regex'][] = $this->get_keys_from_like_clause( $not_like_clause );
			}
		}

		// Handle all the NOT IN clauses.
		preg_match_all( '/meta_key NOT IN \((.*?)\)/', $meta_query, $not_in_clauses );

		if ( ! empty( $not_in_clauses[1] ) ) {
			$excluded_keys['in'] = [];
			foreach ( $not_in_clauses[1] as $not_in_clause ) {
				$excluded_keys['in'] = array_merge( $excluded_keys['in'], $this->get_keys_from_in_clause( $not_in_clause ) );
			}
		}

		return $excluded_keys;
	}

	/**
	 * Gets a list of meta keys from a SQL IN clause.
	 *
	 * @param string $in_clause The concatenated string of meta keys from the IN clause. eg: '_paid_date', '_date_paid', '_completed_date' ...
	 * @return string[] The meta keys from the IN clause. eg: [ '_paid_date', '_date_paid', '_completed_date' ]
	 */
	private function get_keys_from_in_clause( $in_clause ) {
		// Remove single quotes.
		$in_keys = str_replace( "'", '', $in_clause );

		// Split into an array.
		$in_keys = explode( ',', $in_keys );

		// Trim whitespace from each key.
		$in_keys = array_map( 'trim', $in_keys );

		return $in_keys;
	}

	/**
	 * Formats a LIKE clause into a regex pattern.
	 *
	 * @param string $like_clause A SQL LIKE clause. eg: '_schedule_%%'
	 * @return string A regex pattern. eg: '/^_schedule_.*$/'
	 */
	private function get_keys_from_like_clause( $like_clause ) {
		// Remove the surrounding quotes.
		$like_clause = str_replace( "'", '', $like_clause );

		// Replace the wildcard with a regex wildcard.
		$like_clause = str_replace( '%', '.*?', $like_clause );

		// Add the regex wildcard to the beginning and end of the string.
		return '^' . trim( $like_clause ) . '$^';
	}
}
