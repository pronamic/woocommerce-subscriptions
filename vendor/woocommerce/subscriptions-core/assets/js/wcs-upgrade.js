/**
 * @deprecated subscriptions-core 7.7.0 This file is no longer in use and can be removed in future.
 */
jQuery( function ( $ ) {
	var upgrade_start_time = null,
		total_subscriptions = wcs_update_script_data.subscription_count;

	$( '#update-messages' ).slideUp();
	$( '#upgrade-step-3' ).slideUp();

	$( 'form#subscriptions-upgrade' ).on( 'submit', function ( e ) {
		$( '#update-welcome' ).slideUp( 600 );
		$( '#update-messages' ).slideDown( 600 );
		if ( 'true' == wcs_update_script_data.really_old_version ) {
			wcs_ajax_update_really_old_version();
		} else if ( 'true' == wcs_update_script_data.upgrade_to_1_5 ) {
			wcs_ajax_update_products();
			wcs_ajax_update_hooks();
		} else if ( 'true' == wcs_update_script_data.upgrade_to_2_0 ) {
			wcs_ajax_update_subscriptions();
		} else if ( 'true' == wcs_update_script_data.repair_2_0 ) {
			wcs_ajax_repair_subscriptions();
		} else {
			wcs_ajax_update_complete();
		}
		e.preventDefault();
	} );
	function wcs_ajax_update_really_old_version() {
		$.ajax( {
			url: wcs_update_script_data.ajax_url,
			type: 'POST',
			data: {
				action: 'wcs_upgrade',
				upgrade_step: 'really_old_version',
				nonce: wcs_update_script_data.upgrade_nonce,
			},
			success: function ( results ) {
				$( '#update-messages ol' ).append(
					$( '<li />' ).text( results.message )
				);
				wcs_ajax_update_products();
				wcs_ajax_update_hooks();
			},
			error: function ( results, status, errorThrown ) {
				wcs_ajax_update_error();
			},
		} );
	}
	function wcs_ajax_update_products() {
		$.ajax( {
			url: wcs_update_script_data.ajax_url,
			type: 'POST',
			data: {
				action: 'wcs_upgrade',
				upgrade_step: 'products',
				nonce: wcs_update_script_data.upgrade_nonce,
			},
			success: function ( results ) {
				$( '#update-messages ol' ).append(
					$( '<li />' ).text( results.message )
				);
			},
			error: function ( results, status, errorThrown ) {
				wcs_ajax_update_error();
			},
		} );
	}
	function wcs_ajax_update_hooks() {
		var start_time = new Date();
		$.ajax( {
			url: wcs_update_script_data.ajax_url,
			type: 'POST',
			data: {
				action: 'wcs_upgrade',
				upgrade_step: 'hooks',
				nonce: wcs_update_script_data.upgrade_nonce,
			},
			success: function ( results ) {
				if ( results.message ) {
					var end_time = new Date(),
						execution_time = Math.ceil(
							( end_time.getTime() - start_time.getTime() ) / 1000
						);
					$( '#update-messages ol' ).append(
						$( '<li />' ).text(
							results.message.replace(
								'{execution_time}',
								execution_time
							)
						)
					);
				}
				if (
					undefined == typeof results.upgraded_count ||
					parseInt( results.upgraded_count ) <=
						wcs_update_script_data.hooks_per_request - 1
				) {
					wcs_ajax_update_subscriptions();
				} else {
					wcs_ajax_update_hooks();
				}
			},
			error: function ( results, status, errorThrown ) {
				wcs_ajax_update_error();
			},
		} );
	}
	function wcs_ajax_update_subscriptions() {
		var start_time = new Date();

		if ( null === upgrade_start_time ) {
			upgrade_start_time = start_time;
		}

		$.ajax( {
			url: wcs_update_script_data.ajax_url,
			type: 'POST',
			data: {
				action: 'wcs_upgrade',
				upgrade_step: 'subscriptions',
				nonce: wcs_update_script_data.upgrade_nonce,
			},
			success: function ( results ) {
				if ( 'success' == results.status ) {
					var end_time = new Date(),
						execution_time = Math.ceil(
							( end_time.getTime() - start_time.getTime() ) / 1000
						);

					$( '#update-messages ol' ).append(
						$( '<li />' ).text(
							results.message.replace(
								'{execution_time}',
								execution_time
							)
						)
					);

					wcs_update_script_data.subscription_count -=
						results.upgraded_count;

					if (
						'undefined' === typeof results.upgraded_count ||
						parseInt( wcs_update_script_data.subscription_count ) <=
							0
					) {
						wcs_ajax_update_complete();
					} else {
						wcs_ajax_update_estimated_time( results.time_message );
						wcs_ajax_update_subscriptions();
					}
				} else {
					wcs_ajax_update_error( results.message );
				}
			},
			error: function ( results, status, errorThrown ) {
				$(
					'<br/><span>Error: ' +
						results.status +
						' ' +
						errorThrown +
						'</span>'
				).appendTo( '#update-error p' );
				wcs_ajax_update_error( $( '#update-error p' ).html() );
			},
		} );
	}
	function wcs_ajax_repair_subscriptions() {
		var start_time = new Date();

		if ( null === upgrade_start_time ) {
			upgrade_start_time = start_time;
		}

		$.ajax( {
			url: wcs_update_script_data.ajax_url,
			type: 'POST',
			data: {
				action: 'wcs_upgrade',
				upgrade_step: 'subscription_dates_repair',
				nonce: wcs_update_script_data.upgrade_nonce,
			},
			success: function ( results ) {
				if ( 'success' == results.status ) {
					var end_time = new Date(),
						execution_time = Math.ceil(
							( end_time.getTime() - start_time.getTime() ) / 1000
						);

					$( '#update-messages ol' ).append(
						$( '<li />' ).text(
							results.message.replace(
								'{execution_time}',
								execution_time
							)
						)
					);

					wcs_update_script_data.subscription_count -=
						results.repaired_count;
					wcs_update_script_data.subscription_count -=
						results.unrepaired_count;

					if (
						parseInt( wcs_update_script_data.subscription_count ) <=
						0
					) {
						wcs_ajax_update_complete();
					} else {
						wcs_ajax_update_estimated_time( results.time_message );
						wcs_ajax_repair_subscriptions();
					}
				} else {
					wcs_ajax_update_error( results.message );
				}
			},
			error: function ( results, status, errorThrown ) {
				$(
					'<br/><span>Error: ' +
						results.status +
						' ' +
						errorThrown +
						'</span>'
				).appendTo( '#update-error p' );
				wcs_ajax_update_error( $( '#update-error p' ).html() );
			},
		} );
	}
	function wcs_ajax_update_complete() {
		$( '#update-ajax-loader, #estimated_time' ).slideUp( function () {
			$( '#update-complete' ).slideDown();
		} );
	}
	function wcs_ajax_update_error( message ) {
		message = message || '';
		if ( message.length > 0 ) {
			$( '#update-error p' ).html( message );
		}
		$( '#update-ajax-loader, #estimated_time' ).slideUp( function () {
			$( '#update-error' ).slideDown();
		} );
	}
	function wcs_ajax_update_estimated_time( message ) {
		var total_updated =
				total_subscriptions - wcs_update_script_data.subscription_count,
			now = new Date(),
			execution_time,
			time_per_update,
			time_left,
			time_left_minutes,
			time_left_seconds;

		execution_time = Math.ceil(
			( now.getTime() - upgrade_start_time.getTime() ) / 1000
		);
		time_per_update = execution_time / total_updated;

		time_left = Math.floor(
			wcs_update_script_data.subscription_count * time_per_update
		);
		time_left_minutes = Math.floor( time_left / 60 );
		time_left_seconds = time_left % 60;

		$( '#estimated_time' ).html(
			message.replace(
				'{time_left}',
				time_left_minutes + ':' + zeropad( time_left_seconds )
			)
		);
	}
	function zeropad( number ) {
		var pad_char = 0,
			pad = new Array( 3 ).join( pad_char );
		return ( pad + number ).slice( -pad.length );
	}
} );
