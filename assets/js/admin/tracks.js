jQuery( function( $ ) {

	if ( ! window.hasOwnProperty( 'wcTracks' ) ) {
		return;
	}

	function record_event( eventName, properties = {} ) {
		window.wcTracks.recordEvent( eventName, properties );
	}

	// Add event listeners to Subscription Events by Date report clickable filters.
	if ( $( "#report_subscription_events_by_date_new" ).length ) {

		var filters = {
			'new':           'subscriptions_report_events_by_date_new_filter_click',
			'signups':       'subscriptions_report_events_by_date_signups_filter_click',
			'resubscribes':  'subscriptions_report_events_by_date_resubscribes_filter_click',
			'renewals':      'subscriptions_report_events_by_date_renewals_filter_click',
			'switches':      'subscriptions_report_events_by_date_switches_filter_click',
			'cancellations': 'subscriptions_report_events_by_date_cancellations_filter_click',
			'ended':         'subscriptions_report_events_by_date_ended_filter_click',
			'current':       'subscriptions_report_events_by_date_current_filter_click',
		}

		$.each( filters, function( key, value ) {
			$( "#report_subscription_events_by_date_" + key ).on( 'click', function() {
				// if range is not a URL param, we are looking at the default 7 day range.
				var properties = {
					range: location.search.includes( 'range' ) ? location.search.match( /range=([^&#]*)/ )[1] : '7day'
				};

				if ( 'custom' === properties.range ) {
					// Start or end dates may be ommitted.
					properties.start_date = location.search.includes( 'start_date=' )
						? location.search.match( /start_date=([^&#]*)/ )[1]
						: null;

					properties.end_date = location.search.includes( 'end_date=' )
						? location.search.match( /end_date=([^&#]*)/ )[1]
						: new Date().toISOString().split( 'T' )[0];

					properties.span = properties.start_date
						? Math.floor( ( new Date( properties.end_date ) - new Date( properties.start_date ) ) / 86400000 ) + 1 + 'day'
						: null;
				}

				record_event( value, properties );
			} );
		} );
	}

	// Add event listeners to Subscription by Product report links.
	if ( $( "tbody[ data-wp-lists='list:product' ]" ).length ) {

		$( "td.product_name a" ).on( 'click', function() {
			record_event( 'subscriptions_report_by_product_name_click' );
		} );

		$( "td.subscription_count a" ).on( 'click', function() {
			record_event( 'subscriptions_report_by_product_count_click' );
		} );
	}

	// Add event listeners to Subscription by Customer report links.
	if ( $( "tbody[ data-wp-lists='list:customer' ]" ).length ) {

		$( "td.customer_name a" ).on( 'click', function() {
			record_event( 'subscriptions_report_by_customer_name_click' );
		} );

		$( "td.total_subscription_count a" ).on( 'click', function() {
			record_event( 'subscriptions_report_by_customer_total_count_click' );
		} );

		$( "td.total_subscription_order_count a" ).on( 'click', function() {
			record_event( 'subscriptions_report_by_customer_total_order_count_click' );
		} );
	}
});
