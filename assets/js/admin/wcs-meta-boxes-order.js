jQuery( function ( $ ) {
	$( 'body.post-type-shop_order #post' ).on( 'submit', function () {
		if (
			'wcs_retry_renewal_payment' ==
			$(
				"body.post-type-shop_order select[name='wc_order_action']"
			).val()
		) {
			return confirm(
				wcs_admin_order_meta_boxes.retry_renewal_payment_action_warning
			);
		}
	} );

	$( document ).on( 'change', '#wcs-order-price-lock', function () {
		// Block the checkbox element while we update the order.
		$( '#wcs_order_price_lock' ).block( {
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6,
			},
		} );

		var data = {
			wcs_order_price_lock: $( '#wcs-order-price-lock' ).is( ':checked' )
				? 'yes'
				: 'no',
			order_id: $( '#post_ID' ).val(),
			action: 'wcs_order_price_lock',
			woocommerce_meta_nonce: $( '#woocommerce_meta_nonce' ).val(),
		};

		$.ajax( {
			type: 'post',
			url: woocommerce_admin_meta_boxes.ajax_url,
			data: data,
			complete: function () {
				$( '#wcs_order_price_lock' ).unblock();
			},
		} );
	} );
} );
