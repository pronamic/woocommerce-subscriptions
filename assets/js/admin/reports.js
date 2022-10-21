jQuery( function( $ ) {

	$.extend({
		wcs_format_money: function(value,decimal_precision) {
			return window.accounting.formatMoney(
				value,
				{
					symbol: wcs_reports.currency_format_symbol,
					format: wcs_reports.currency_format,
					decimal: wcs_reports.currency_format_decimal_sep,
					thousand: wcs_reports.currency_format_thousand_sep,
					precision: decimal_precision,
				}
			);
		},
	});

	// We're on the Subscriptions Upcoming Revenue Report page, change datepicker to future dates.
	if ( $( '#woocommerce_subscriptions_upcoming_recurring_revenue_chart' ).length > 0 ) {

		$( '.range_datepicker' ).datepicker( 'destroy' );

		var dates = $( '.range_datepicker' ).datepicker({
			changeMonth: true,
			changeYear: true,
			defaultDate: "",
			dateFormat: "yy-mm-dd",
			numberOfMonths: 1,
			minDate: "+0D",
			showButtonPanel: true,
			showOn: "focus",
			buttonImageOnly: true,
			onSelect: function( selectedDate ) {
				var option = $(this).is('.from') ? "minDate" : "maxDate",
					instance = $( this ).data( "datepicker" ),
					date = $.datepicker.parseDate( instance.settings.dateFormat || $.datepicker._defaults.dateFormat, selectedDate, instance.settings );
				dates.not( this ).datepicker( 'option', option, date );
			}
		});
	}

	// We're on the Payment Retry page, change datepicker to allow both future dates and historical dates
	if ( $( '#woocommerce_subscriptions_payment_retry_chart' ).length > 0 ) {

		$( '.range_datepicker' ).datepicker( 'destroy' );

		var dates = $( '.range_datepicker' ).datepicker({
			changeMonth: true,
			changeYear: true,
			defaultDate: "",
			dateFormat: "yy-mm-dd",
			numberOfMonths: 1,
			showButtonPanel: true,
			showOn: "focus",
			buttonImageOnly: true,
			onSelect: function( selectedDate ) {
				var option = $(this).is('.from') ? "minDate" : "maxDate",
					instance = $( this ).data( "datepicker" ),
					date = $.datepicker.parseDate( instance.settings.dateFormat || $.datepicker._defaults.dateFormat, selectedDate, instance.settings );
				dates.not( this ).datepicker( 'option', option, date );
			}
		});
	}

});