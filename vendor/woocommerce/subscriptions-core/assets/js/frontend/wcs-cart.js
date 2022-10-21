function hide_non_applicable_coupons() {
	var coupon_elements = document.getElementsByClassName( 'cart-discount' );

	for ( var i = 0; i < coupon_elements.length; i++ ) {
		if (
			0 !==
			coupon_elements[ i ].getElementsByClassName( 'wcs-hidden-coupon' )
				.length
		) {
			coupon_elements[ i ].style.display = 'none';
		}
	}
}

hide_non_applicable_coupons();

jQuery( function ( $ ) {
	$( document.body ).on( 'updated_cart_totals updated_checkout', function () {
		hide_non_applicable_coupons();
	} );

	$( '.payment_methods [name="payment_method"]' ).on( 'click', function () {
		if ( $( this ).hasClass( 'supports-payment-method-changes' ) ) {
			$( '.update-all-subscriptions-payment-method-wrap' ).show();
		} else {
			$( '.update-all-subscriptions-payment-method-wrap' ).hide();
		}
	} );
} );
