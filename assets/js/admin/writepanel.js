/* global wc_subscription_downloads_product */
jQuery( document ).ready( function ( $ ) {
	function subscriptionDownloadsChosen() {
		$( 'select.subscription-downloads-ids' ).ajaxChosen({
			method:         'GET',
			url:            wc_subscription_downloads_product.ajax_url,
			dataType:       'json',
			afterTypeDelay: 100,
			minTermLength:  1,
			data: {
				action:   'wc_subscription_downloads_search',
				security: wc_subscription_downloads_product.security
			}
		}, function ( data ) {

			var orders = {};

			$.each( data, function ( i, val ) {
				orders[i] = val;
			});

			return orders;
		});
	}

	subscriptionDownloadsChosen();

	$( 'body' ).on( 'woocommerce_variations_added', function () {
		subscriptionDownloadsChosen();
	});
});
