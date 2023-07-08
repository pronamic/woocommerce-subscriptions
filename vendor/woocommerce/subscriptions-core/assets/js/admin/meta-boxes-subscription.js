jQuery( function ( $ ) {
	var timezone = jstz.determine();

	// Display the timezone for date changes
	$( '#wcs-timezone' ).text( timezone.name() );

	// Display times in client's timezone (based on UTC)
	$( '.woocommerce-subscriptions.date-picker' ).each( function () {
		var $date_input = $( this ),
			date_type = $date_input.attr( 'id' ),
			$hour_input = $( '#' + date_type + '_hour' ),
			$minute_input = $( '#' + date_type + '_minute' ),
			time = $( '#' + date_type + '_timestamp_utc' ).val(),
			date = moment.unix( time );

		if ( time > 0 ) {
			date.local();
			$date_input.val(
				date.year() +
					'-' +
					zeroise( date.months() + 1 ) +
					'-' +
					date.format( 'DD' )
			);
			$hour_input.val( date.format( 'HH' ) );
			$minute_input.val( date.format( 'mm' ) );
		}
	} );

	// Make sure start date picker is in the past
	$( '.woocommerce-subscriptions.date-picker#start' ).datepicker(
		'option',
		'maxDate',
		moment().toDate()
	);

	// Make sure other date pickers are in the future
	$( '.woocommerce-subscriptions.date-picker:not(#start)' ).datepicker(
		'option',
		'minDate',
		moment().add( 1, 'hours' ).toDate()
	);

	// Validate date when hour/minute inputs change
	$( '[name$="_hour"], [name$="_minute"]' ).on( 'change', function () {
		$(
			'#' +
				$( this )
					.attr( 'name' )
					.replace( '_hour', '' )
					.replace( '_minute', '' )
		).trigger( 'change' );
	} );

	// Validate entire date
	$( '.woocommerce-subscriptions.date-picker' ).on( 'change', function () {
		// The date was deleted, clear hour/minute inputs values and set the UTC timestamp to 0
		if ( '' == $( this ).val() ) {
			$( '#' + $( this ).attr( 'id' ) + '_hour' ).val( '' );
			$( '#' + $( this ).attr( 'id' ) + '_minute' ).val( '' );
			$( '#' + $( this ).attr( 'id' ) + '_timestamp_utc' ).val( 0 );
			return;
		}

		var time_now = moment(),
			one_hour_from_now = moment().add( 1, 'hours' ),
			minimum_date = wcs_admin_meta_boxes.is_duplicate_site
				? moment().add( 2, 'minutes' )
				: one_hour_from_now,
			$date_input = $( this ),
			date_type = $date_input.attr( 'id' ),
			date_pieces = $date_input.val().split( '-' ),
			$hour_input = $( '#' + date_type + '_hour' ),
			$minute_input = $( '#' + date_type + '_minute' ),
			chosen_hour =
				0 == $hour_input.val().length
					? one_hour_from_now.format( 'HH' )
					: $hour_input.val(),
			chosen_minute =
				0 == $minute_input.val().length
					? one_hour_from_now.format( 'mm' )
					: $minute_input.val(),
			chosen_date = moment( {
				years: date_pieces[ 0 ],
				months: date_pieces[ 1 ] - 1,
				date: date_pieces[ 2 ],
				hours: chosen_hour,
				minutes: chosen_minute,
				seconds: one_hour_from_now.format( 'ss' ),
			} );

		// Make sure start date is before now.
		if (
			'start' == date_type &&
			false === chosen_date.isBefore( time_now )
		) {
			alert( wcs_admin_meta_boxes.i18n_start_date_notice );
			$date_input.val(
				time_now.year() +
					'-' +
					zeroise( time_now.months() + 1 ) +
					'-' +
					time_now.format( 'DD' )
			);
			$hour_input.val( time_now.format( 'HH' ) );
			$minute_input.val( time_now.format( 'mm' ) );
		}

		// Make sure trial end and next payment are after start date
		if (
			( 'trial_end' == date_type || 'next_payment' == date_type ) &&
			'' != $( '#start_timestamp_utc' ).val()
		) {
			var change_date = false,
				start = moment.unix( $( '#start_timestamp_utc' ).val() );

			// Make sure trial end is after start date
			if (
				'trial_end' == date_type &&
				chosen_date.isBefore( start, 'minute' )
			) {
				if ( 'trial_end' == date_type ) {
					alert( wcs_admin_meta_boxes.i18n_trial_end_start_notice );
				} else if ( 'next_payment' == date_type ) {
					alert(
						wcs_admin_meta_boxes.i18n_next_payment_start_notice
					);
				}

				// Change the date
				$date_input.val(
					start.year() +
						'-' +
						zeroise( start.months() + 1 ) +
						'-' +
						start.format( 'DD' )
				);
				$hour_input.val( start.format( 'HH' ) );
				$minute_input.val( start.format( 'mm' ) );
			}
		}

		// Make sure next payment is after trial end
		if (
			'next_payment' == date_type &&
			'' != $( '#trial_end_timestamp_utc' ).val()
		) {
			var trial_end = moment.unix(
				$( '#trial_end_timestamp_utc' ).val()
			);

			if ( chosen_date.isBefore( trial_end, 'minute' ) ) {
				alert( wcs_admin_meta_boxes.i18n_next_payment_trial_notice );
				$date_input.val(
					trial_end.year() +
						'-' +
						zeroise( trial_end.months() + 1 ) +
						'-' +
						trial_end.format( 'DD' )
				);
				$hour_input.val( trial_end.format( 'HH' ) );
				$minute_input.val( trial_end.format( 'mm' ) );
			}
		}

		// Make sure trial end is before next payment and expiration is after next payment date
		else if (
			( 'trial_end' == date_type || 'end' == date_type ) &&
			'' != $( '#next_payment' ).val()
		) {
			var change_date = false,
				next_payment = moment.unix(
					$( '#next_payment_timestamp_utc' ).val()
				);

			// Make sure trial end is before or equal to next payment
			if (
				'trial_end' == date_type &&
				next_payment.isBefore( chosen_date, 'minute' )
			) {
				alert( wcs_admin_meta_boxes.i18n_trial_end_next_notice );
				change_date = true;
			}
			// Make sure end date is after next payment date
			else if (
				'end' == date_type &&
				chosen_date.isBefore( next_payment, 'minute' )
			) {
				alert( wcs_admin_meta_boxes.i18n_end_date_notice );
				change_date = true;
			}

			if ( true === change_date ) {
				$date_input.val(
					next_payment.year() +
						'-' +
						zeroise( next_payment.months() + 1 ) +
						'-' +
						next_payment.format( 'DD' )
				);
				$hour_input.val( next_payment.format( 'HH' ) );
				$minute_input.val( next_payment.format( 'mm' ) );
			}
		}

		// Make sure the date is more than an hour in the future
		if (
			'trial_end' != date_type &&
			'start' != date_type &&
			chosen_date.unix() < minimum_date.unix()
		) {
			alert( wcs_admin_meta_boxes.i18n_past_date_notice );

			// Set date to current day
			$date_input.val(
				one_hour_from_now.year() +
					'-' +
					zeroise( one_hour_from_now.months() + 1 ) +
					'-' +
					one_hour_from_now.format( 'DD' )
			);

			// Set time if current time is in the past
			if (
				chosen_date.hours() < one_hour_from_now.hours() ||
				( chosen_date.hours() == one_hour_from_now.hours() &&
					chosen_date.minutes() < one_hour_from_now.minutes() )
			) {
				$hour_input.val( one_hour_from_now.format( 'HH' ) );
				$minute_input.val( one_hour_from_now.format( 'mm' ) );
			}
		}

		if ( 0 == $hour_input.val().length ) {
			$hour_input.val( one_hour_from_now.format( 'HH' ) );
		}

		if ( 0 == $minute_input.val().length ) {
			$minute_input.val( one_hour_from_now.format( 'mm' ) );
		}

		// Update the UTC timestamp sent to the server
		date_pieces = $date_input.val().split( '-' );

		$( '#' + date_type + '_timestamp_utc' ).val(
			moment( {
				years: date_pieces[ 0 ],
				months: date_pieces[ 1 ] - 1,
				date: date_pieces[ 2 ],
				hours: $hour_input.val(),
				minutes: $minute_input.val(),
				seconds: one_hour_from_now.format( 'ss' ),
			} )
				.utc()
				.unix()
		);

		$( 'body' ).trigger( 'wcs-updated-date', date_type );
	} );

	function zeroise( val ) {
		return val > 9 ? val : '0' + val;
	}

	if ( $( '#parent-order-id' ).is( 'select' ) ) {
		wcs_update_parent_order_options();

		$( '#customer_user' ).on( 'change', wcs_update_parent_order_options );
	}

	function wcs_update_parent_order_options() {
		// Get user ID to load orders for
		var user_id = $( '#customer_user' ).val();

		if ( ! user_id ) {
			return false;
		}

		var data = {
			user_id: user_id,
			action: 'wcs_get_customer_orders',
			security: wcs_admin_meta_boxes.get_customer_orders_nonce,
		};

		$( '#parent-order-id' )
			.siblings( '.select2-container' )
			.block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6,
				},
			} );

		$.ajax( {
			url: WCSubscriptions.ajaxUrl,
			data: data,
			type: 'POST',
			success: function ( response ) {
				if ( response ) {
					var $orderlist = $( '#parent-order-id' );

					$( '#parent-order-id' ).select2( 'val', '' );

					$orderlist.empty(); // remove old options

					$orderlist.append(
						$( '<option></option>' )
							.attr( 'value', '' )
							.text( 'Select an order' )
					);

					$.each( response, function ( order_id, order_number ) {
						$orderlist.append(
							$( '<option></option>' )
								.attr( 'value', order_id )
								.text( order_number )
						);
					} );

					$( '#parent-order-id' )
						.siblings( '.select2-container' )
						.unblock();
				}
			},
		} );
		return false;
	}

	$( 'body.post-type-shop_subscription #post, body.woocommerce_page_wc-orders--shop_subscription #order' ).on( 'submit', function () {
		if (
			'wcs_process_renewal' ==
			$(
				'body.post-type-shop_subscription select[name="wc_order_action"], body.woocommerce_page_wc-orders--shop_subscription select[name="wc_order_action"]'
			).val()
		) {
			return confirm(
				wcs_admin_meta_boxes.process_renewal_action_warning
			);
		}
	} );

	$( 'body.post-type-shop_subscription #post, body.woocommerce_page_wc-orders--shop_subscription #order' ).on( 'submit', function () {
		if (
			typeof wcs_admin_meta_boxes.change_payment_method_warning !=
				'undefined' &&
			wcs_admin_meta_boxes.payment_method != $( '#_payment_method' ).val()
		) {
			return confirm(
				wcs_admin_meta_boxes.change_payment_method_warning
			);
		}
	} );
} );
