function hide_non_applicable_coupons() {
	var coupon_elements = document.getElementsByClassName( 'cart-discount' );

	for ( var i = 0; i < coupon_elements.length; i++ ) {
		if ( 0 !== coupon_elements[i].getElementsByClassName( 'wcs-hidden-coupon' ).length ) {
			coupon_elements[i].style.display = 'none';
		}
	}
}

hide_non_applicable_coupons();

jQuery( document ).ready( function( $ ){
	$( document.body ).on( 'updated_cart_totals updated_checkout', function() {
		hide_non_applicable_coupons();
	} );
} );
