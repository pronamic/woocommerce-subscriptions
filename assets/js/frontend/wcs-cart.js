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

	/**
	 * Update all subscriptions shipping methods which inherit the chosen method from the initial
	 * cart when the customer changes the shipping method.
	 */
	$( document ).on(
		'change',
		'select.shipping_method, :input[name^=shipping_method]',
		function ( event ) {
			var shipping_method_option = $( event.target );
			var shipping_method_id = shipping_method_option.val();
			var package_index = shipping_method_option.data( 'index' );

			// We're only interested in the initial cart shipping method options which have int package indexes.
			if ( ! Number.isInteger( package_index ) ) {
				return;
			}

			// Find all recurring cart info elements with the same package index as the changed shipping method.
			$(
				'.recurring-cart-shipping-mapping-info[data-index=' +
					package_index +
					']'
			).each( function () {
				// Update the corresponding subscription's hidden chosen shipping method.
				$(
					'input[name="shipping_method[' +
						$( this ).data( 'recurring_index' ) +
						']"]'
				).val( shipping_method_id );
			} );
		}
	);

	$( '.payment_methods [name="payment_method"]' ).on( 'click', function () {
		if ( $( this ).hasClass( 'supports-payment-method-changes' ) ) {
			$( '.update-all-subscriptions-payment-method-wrap' ).show();
		} else {
			$( '.update-all-subscriptions-payment-method-wrap' ).hide();
		}
	} );
} );
