<?php
/**
 * Subscription Legacy Object
 *
 * Extends WC_Subscription to provide WC 3.0 methods when running WooCommerce < 3.0.
 *
 * @class    WC_Subscription_Legacy
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.1
 * @package  WooCommerce Subscriptions/Classes
 * @category Class
 * @author   Brent Shepherd
 */

class WC_Subscription_Legacy extends WC_Subscription {

	protected $schedule;

	protected $status_transition = false;

	/**
	 * Whether the object has been read. Pre WC 3.0 subscription objects are always read by default.
	 * Provides an accessible variable equivalent to WC_Data::$object_read pre WC 3.0.
	 *
	 * @protected boolean
	 */
	protected $object_read = true;

	/**
	 * Initialize the subscription object.
	 *
	 * @param int|WC_Subscription $subscription
	 */
	public function __construct( $subscription ) {

		parent::__construct( $subscription );

		$this->order_type = 'shop_subscription';

		$this->schedule = new stdClass();
	}

	/**
	 * Populates a subscription from the loaded post data.
	 *
	 * @param mixed $result
	 */
	public function populate( $result ) {
		parent::populate( $result );

		if ( $this->post->post_parent > 0 ) {
			$this->order = wc_get_order( $this->post->post_parent );
		}
	}

	/**
	 * Returns the unique ID for this object.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get parent order ID.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @return int
	 */
	public function get_parent_id() {
		return $this->post->post_parent;
	}

	/**
	 * Gets order currency.
	 *
	 * @return string
	 */
	public function get_currency() {
		return $this->get_order_currency();
	}

	/**
	 * Get customer_note.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_customer_note( $context = 'view' ) {
		return $this->customer_note;
	}

	/**
	 * Get prices_include_tax.
	 *
	 * @param  string $context
	 * @return bool
	 */
	public function get_prices_include_tax( $context = 'view' ) {
		return $this->prices_include_tax;
	}

	/**
	 * Get the payment method.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_payment_method( $context = 'view' ) {
		return $this->payment_method;
	}

	/**
	 * Get the payment method's title.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_payment_method_title( $context = 'view' ) {
		return $this->payment_method_title;
	}

	/** Address Getters **/

	/**
	 * Get billing_first_name.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_first_name( $context = 'view' ) {
		return $this->billing_first_name;
	}

	/**
	 * Get billing_last_name.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_last_name( $context = 'view' ) {
		return $this->billing_last_name;
	}

	/**
	 * Get billing_company.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_company( $context = 'view' ) {
		return $this->billing_company;
	}

	/**
	 * Get billing_address_1.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_address_1( $context = 'view' ) {
		return $this->billing_address_1;
	}

	/**
	 * Get billing_address_2.
	 *
	 * @param  string $context
	 * @return string $value
	 */
	public function get_billing_address_2( $context = 'view' ) {
		return $this->billing_address_2;
	}

	/**
	 * Get billing_city.
	 *
	 * @param  string $context
	 * @return string $value
	 */
	public function get_billing_city( $context = 'view' ) {
		return $this->billing_city;
	}

	/**
	 * Get billing_state.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_state( $context = 'view' ) {
		return $this->billing_state;
	}

	/**
	 * Get billing_postcode.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_postcode( $context = 'view' ) {
		return $this->billing_postcode;
	}

	/**
	 * Get billing_country.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_country( $context = 'view' ) {
		return $this->billing_country;
	}

	/**
	 * Get billing_email.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_email( $context = 'view' ) {
		return $this->billing_email;
	}

	/**
	 * Get billing_phone.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_billing_phone( $context = 'view' ) {
		return $this->billing_phone;
	}

	/**
	 * Get shipping_first_name.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_first_name( $context = 'view' ) {
		return $this->shipping_first_name;
	}

	/**
	 * Get shipping_last_name.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_last_name( $context = 'view' ) {
		return $this->shipping_last_name;
	}

	/**
	 * Get shipping_company.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_company( $context = 'view' ) {
		return $this->shipping_company;
	}

	/**
	 * Get shipping_address_1.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_address_1( $context = 'view' ) {
		return $this->shipping_address_1;
	}

	/**
	 * Get shipping_address_2.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_address_2( $context = 'view' ) {
		return $this->shipping_address_2;
	}

	/**
	 * Get shipping_city.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_city( $context = 'view' ) {
		return $this->shipping_city;
	}

	/**
	 * Get shipping_state.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_state( $context = 'view' ) {
		return $this->shipping_state;
	}

	/**
	 * Get shipping_postcode.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_postcode( $context = 'view' ) {
		return $this->shipping_postcode;
	}

	/**
	 * Get shipping_country.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_shipping_country( $context = 'view' ) {
		return $this->shipping_country;
	}

	/**
	 * Get order key.
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @param  string $context
	 * @return string
	 */
	public function get_order_key( $context = 'view' ) {
		return $this->order_key;
	}

	/**
	 * Get date_created.
	 *
	 * Used by parent::get_date()
	 *
	 * @throws WC_Data_Exception
	 * @return DateTime|NULL object if the date is set or null if there is no date.
	 */
	public function get_date_created( $context = 'view' ) {

		if ( '0000-00-00 00:00:00' != $this->post->post_date_gmt ) {
			$datetime = new WC_DateTime( $this->post->post_date_gmt, new DateTimeZone( 'UTC' ) );
			$datetime->setTimezone( new DateTimeZone( wc_timezone_string() ) );
		} else {
			$datetime = new WC_DateTime( $this->post->post_date, new DateTimeZone( wc_timezone_string() ) );
		}

		// Cache it in $this->schedule for backward compatibility
		if ( ! isset( $this->schedule->start ) ) {
			$this->schedule->start = wcs_get_datetime_utc_string( $datetime );
		}

		return $datetime;
	}

	/**
	 * Get date_modified.
	 *
	 * Used by parent::get_date()
	 *
	 * @throws WC_Data_Exception
	 * @return DateTime|NULL object if the date is set or null if there is no date.
	 */
	public function get_date_modified( $context = 'view' ) {

		if ( '0000-00-00 00:00:00' != $this->post->post_modified_gmt ) {
			$datetime = new WC_DateTime( $this->post->post_modified_gmt, new DateTimeZone( 'UTC' ) );
			$datetime->setTimezone( new DateTimeZone( wc_timezone_string() ) );
		} else {
			$datetime = new WC_DateTime( $this->post->post_modified, new DateTimeZone( wc_timezone_string() ) );
		}

		return $datetime;
	}

	/**
	 * Check if a given line item on the subscription had a sign-up fee, and if so, return the value of the sign-up fee.
	 *
	 * The single quantity sign-up fee will be returned instead of the total sign-up fee paid. For example, if 3 x a product
	 * with a 10 BTC sign-up fee was purchased, a total 30 BTC was paid as the sign-up fee but this function will return 10 BTC.
	 *
	 * @param array|int Either an order item (in the array format returned by self::get_items()) or the ID of an order item.
	 * @param  string $tax_inclusive_or_exclusive Whether or not to adjust sign up fee if prices inc tax - ensures that the sign up fee paid amount includes the paid tax if inc
	 * @return bool
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_items_sign_up_fee( $line_item, $tax_inclusive_or_exclusive = 'exclusive_of_tax' ) {

		if ( ! is_array( $line_item ) ) {
			$line_item = wcs_get_order_item( $line_item, $this );
		}

		$parent_order = $this->get_parent();

		// If there was no original order, nothing was paid up-front which means no sign-up fee
		if ( false == $parent_order ) {

			$sign_up_fee = 0;

		} else {

			$original_order_item = '';

			// Find the matching item on the order
			foreach ( $parent_order->get_items() as $order_item ) {
				if ( wcs_get_canonical_product_id( $line_item ) == wcs_get_canonical_product_id( $order_item ) ) {
					$original_order_item = $order_item;
					break;
				}
			}

			// No matching order item, so this item wasn't purchased in the original order
			if ( empty( $original_order_item ) ) {

				$sign_up_fee = 0;

			} elseif ( isset( $line_item['item_meta']['_has_trial'] ) ) {

				// Sign up is total amount paid for this item on original order when item has a free trial
				$sign_up_fee = $original_order_item['line_total'] / $original_order_item['qty'];
			} elseif ( isset( $original_order_item['item_meta']['_synced_sign_up_fee'] ) ) {
				$sign_up_fee = $original_order_item['item_meta']['_synced_sign_up_fee'] / $original_order_item['qty'];

				// The synced sign up fee meta contains the raw product sign up fee, if the subscription totals are inclusive of tax, we need to adjust the synced sign up fee to match tax inclusivity.
				if ( $this->get_prices_include_tax() ) {
					$line_item_total    = $original_order_item['line_total'] + $original_order_item['line_tax'];
					$signup_fee_portion = $sign_up_fee / $line_item_total;
					$sign_up_fee        = $original_order_item['line_total'] * $signup_fee_portion;
				}
			} else {

				// Sign-up fee is any amount on top of recurring amount
				$sign_up_fee = max( $original_order_item['line_total'] / $original_order_item['qty'] - $line_item['line_total'] / $line_item['qty'], 0 );
			}

			// If prices don't inc tax, ensure that the sign up fee amount includes the tax.
			if ( 'inclusive_of_tax' === $tax_inclusive_or_exclusive && ! empty( $original_order_item ) && ! empty( $sign_up_fee ) ) {
				$sign_up_fee_proportion = $sign_up_fee / ( $original_order_item['line_total'] / $original_order_item['qty'] );
				$sign_up_fee_tax        = $original_order_item['line_tax'] * $sign_up_fee_proportion;

				$sign_up_fee += $sign_up_fee_tax;
				$sign_up_fee  = wc_format_decimal( $sign_up_fee, wc_get_price_decimals() );
			}
		}

		return apply_filters( 'woocommerce_subscription_items_sign_up_fee', $sign_up_fee, $line_item, $this, $tax_inclusive_or_exclusive );
	}

	/**
	 * Helper function to make sure when WC_Subscription calls get_prop() from
	 * it's new getters that the property is both retrieved from the legacy class
	 * property and done so from post meta.
	 *
	 * For inherited dates props, like date_created, date_modified, date_paid,
	 * date_completed, we want to use our own get_date() function rather simply
	 * getting the stored value. Otherwise, we either get the prop set in memory
	 * or post meta if it's not set yet, because __get() in WC < 3.0 would fallback
	 * to post meta.
	 *
	 * @param string
	 * @param string
	 * @return mixed
	 */
	protected function get_prop( $prop, $context = 'view' ) {

		if ( 'switch_data' == $prop ) {
			$prop = 'subscription_switch_data';
		}

		// The requires manual renewal prop uses boolean values but is stored as a string so needs special handling, it also needs to be handled before the checks on $this->$prop to avoid triggering __isset() & __get() magic methods for $this->requires_manual_renewal
		if ( 'requires_manual_renewal' === $prop ) {
			$value = $this->get_meta( '_' . $prop, true );

			if ( 'false' === $value || '' === $value ) {
				$value = false;
			} else {
				$value = true;
			}
		} elseif ( ! isset( $this->$prop ) || empty( $this->$prop ) ) {
			$value = $this->get_meta( '_' . $prop, true );
		} else {
			$value = $this->$prop;
		}

		return $value;
	}

	/**
	 * Get the stored date for a specific schedule.
	 *
	 * @param string $date_type 'date_created', 'trial_end', 'next_payment', 'last_order_date_created' or 'end'
	 */
	protected function get_date_prop( $date_type ) {

		$datetime = parent::get_date_prop( $date_type );

		// Cache the string equalivent of it in $this->schedule for backward compatibility
		if ( ! isset( $this->schedule->{$date_type} ) ) {
			if ( ! is_object( $datetime ) ) {
				$this->schedule->{$date_type} = 0;
			} else {
				$this->schedule->{$date_type} = wcs_get_datetime_utc_string( $datetime );
			}
		}

		return wcs_get_datetime_from( wcs_date_to_time( $datetime ) );
	}

	/*** Setters *****************************************************/

	/**
	 * Set the unique ID for this object.
	 *
	 * @param int
	 */
	public function set_id( $id ) {
		$this->id = absint( $id );
	}

	/**
	 * Set parent order ID. We don't use WC_Abstract_Order::set_parent_id() because we want to allow false
	 * parent IDs, like 0.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @param int $value
	 */
	public function set_parent_id( $value ) {
		// Update the parent in the database
		wp_update_post(  array(
			'ID'          => $this->id,
			'post_parent' => $value,
		) );

		// And update the parent in memory
		$this->post->post_parent = $value;
		$this->order = null;
	}

	/**
	 * Set subscription status.
	 *
	 * @param string $new_status Status to change the order to. No internal wc- prefix is required.
	 * @return array details of change
	 */
	public function set_status( $new_status, $note = '', $manual_update = false ) {

		$old_status = $this->get_status();
		$prefix     = substr( $new_status, 0, 3 );
		$new_status = 'wc-' === $prefix ? substr( $new_status, 3 ) : $new_status;

		wp_update_post(
			array(
				'ID'          => $this->get_id(),
				'post_status' => wcs_maybe_prefix_key( $new_status, 'wc-' ),
			)
		);
		$this->post_status = $this->post->post_status = wcs_maybe_prefix_key( $new_status, 'wc-' );

		if ( $old_status !== $new_status ) {
			$this->status_transition = array(
				'from'   => ! empty( $this->status_transition['from'] ) ? $this->status_transition['from'] : $old_status,
				'to'     => $new_status,
				'note'   => $note,
				'manual' => (bool) $manual_update,
			);
		}

		return array(
			'from' => $old_status,
			'to'   => $new_status,
		);
	}

	/**
	 * Helper function to make sure when WC_Subscription calls set_prop() that property is
	 * both set in the legacy class property and saved in post meta immediately.
	 *
	 * @param string $prop
	 * @param mixed $value
	 */
	protected function set_prop( $prop, $value ) {

		if ( 'switch_data' == $prop ) {
			$prop = 'subscription_switch_data';
		}

		$this->$prop = $value;

		// The requires manual renewal prop uses boolean values but it stored as a string
		if ( 'requires_manual_renewal' === $prop ) {
			if ( false === $value || '' === $value ) {
				$value = 'false';
			} else {
				$value = 'true';
			}
		}

		update_post_meta( $this->get_id(), '_' . $prop, $value );
	}

	/**
	 * Set the stored date for a specific schedule.
	 *
	 * @param string $date_type 'trial_end', 'next_payment', 'cancelled', 'payment_retry' or 'end'
	 * @param int $value UTC timestamp
	 */
	protected function set_date_prop( $date_type, $value ) {
		$datetime = wcs_get_datetime_from( $value );
		$date     = ! is_null( $datetime ) ? wcs_get_datetime_utc_string( $datetime ) : 0;

		$this->set_prop( $this->get_date_prop_key( $date_type ), $date );
		$this->schedule->{$date_type} = $date;
	}

	/**
	 * Set a certain date type for the last order on the subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @param string $date_type
	 * @param string|integer|object
	 * @return WC_DateTime|NULL object if the date is set or null if there is no date.
	 */
	protected function set_last_order_date( $date_type, $date = null ) {

		$last_order = $this->get_last_order( 'all' );

		if ( $last_order ) {

			$datetime = wcs_get_datetime_from( $date );

			switch ( $date_type ) {
				case 'date_paid':
					update_post_meta( $last_order->id, '_paid_date', ! is_null( $date ) ? $datetime->date( 'Y-m-d H:i:s' ) : '' );
					// Preemptively set the UTC timestamp for WC 3.0+ also to avoid incorrect values when the site's timezone is changed between now and upgrading to WC 3.0
					update_post_meta( $last_order->id, '_date_paid', ! is_null( $date ) ? $datetime->getTimestamp() : '' );
				break;

				case 'date_completed':
					update_post_meta( $last_order->id, '_completed_date', ! is_null( $date ) ? $datetime->date( 'Y-m-d H:i:s' ) : '' );
					// Preemptively set the UTC timestamp for WC 3.0+ also to avoid incorrect values when the site's timezone is changed between now and upgrading to WC 3.0
					update_post_meta( $last_order->id, '_date_completed', ! is_null( $date ) ? $datetime->getTimestamp() : '' );
				break;

				case 'date_modified':
					wp_update_post( array(
						'ID'                => $last_order->id,
						'post_modified'     => $datetime->date( 'Y-m-d H:i:s' ),
						'post_modified_gmt' => wcs_get_datetime_utc_string( $datetime ),
					) );
				break;

				case 'date_created':
					wp_update_post( array(
						'ID'            => $last_order->id,
						'post_date'     => $datetime->date( 'Y-m-d H:i:s' ),
						'post_date_gmt' => wcs_get_datetime_utc_string( $datetime ),
					) );
				break;
			}
		}
	}

	/**
	 * Set date_created.
	 *
	 * Used by parent::update_dates()
	 *
	 * @param  string|integer|null $date UTC timestamp, or ISO 8601 DateTime. If the DateTime string has no timezone or offset, WordPress site timezone will be assumed. Null if their is no date.
	 * @throws WC_Data_Exception
	 */
	public function set_date_created( $date = null ) {
		global $wpdb;

		if ( ! is_null( $date ) ) {

			$datetime_string = wcs_get_datetime_utc_string( wcs_get_datetime_from( $date ) );

			// Don't use wp_update_post() to avoid infinite loops here
			$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_date = %s, post_date_gmt = %s WHERE ID = %d", get_date_from_gmt( $datetime_string ), $datetime_string, $this->get_id() ) );

			$this->post->post_date     = get_date_from_gmt( $datetime_string );
			$this->post->post_date_gmt = $datetime_string;
		}
	}

	/**
	 * Set discount_total.
	 *
	 * @param string $value
	 * @throws WC_Data_Exception
	 */
	public function set_discount_total( $value ) {
		$this->set_total( $value, 'cart_discount' );
	}

	/**
	 * Set discount_tax.
	 *
	 * @param string $value
	 * @throws WC_Data_Exception
	 */
	public function set_discount_tax( $value ) {
		$this->set_total( $value, 'cart_discount_tax' );
	}

	/**
	 * Set shipping_total.
	 *
	 * @param string $value
	 * @throws WC_Data_Exception
	 */
	public function set_shipping_total( $value ) {
		$this->set_total( $value, 'shipping' );
	}

	/**
	 * Set shipping_tax.
	 *
	 * @param string $value
	 * @throws WC_Data_Exception
	 */
	public function set_shipping_tax( $value ) {
		$this->set_total( $value, 'shipping_tax' );
	}

	/**
	 * Set cart tax.
	 *
	 * @param string $value
	 * @throws WC_Data_Exception
	 */
	public function set_cart_tax( $value ) {
		$this->set_total( $value, 'tax' );
	}

	/**
	 * Save data to the database. Nothing to do here as it's all done separately when calling @see this->set_prop().
	 *
	 * @return int order ID
	 */
	public function save() {
		$this->status_transition();
		return $this->get_id();
	}

	/**
	 * Update meta data by key or ID, if provided.
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @param  string $key
	 * @param  string $value
	 * @param  int $meta_id
	 */
	public function update_meta_data( $key, $value, $meta_id = '' ) {
		if ( ! empty( $meta_id ) ) {
			update_metadata_by_mid( 'post', $meta_id, $value, $key );
		} else {
			update_post_meta( $this->get_id(), $key, $value );
		}
	}

	/**
	 * Save subscription date changes to the database.
	 * Nothing to do here as all date properties are saved when calling @see $this->set_prop().
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.6
	 */
	public function save_dates() {
		// Nothing to do here.
	}
}
