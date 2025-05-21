jQuery( function ( $ ) {
	let observer = null;

	if ( arePointersEnabled() ) {
		observer = new MutationObserver( showSubscriptionPointers );
	 
	 	observer.observe( document.getElementById( 'poststuff' ), {
			attributes: true,
			childList: true,
			characterData: false,
			subtree:true,
		} );
	}

	$( 'select#product-type' ).on( 'change', function () {
		if ( arePointersEnabled() ) {
			$( '#product-type' ).pointer( 'close' );
		}
	} );

	$(
		'#_subscription_price, #_subscription_period, #_subscription_length'
	).on( 'change', function () {
		if ( arePointersEnabled() ) {
			$( '.options_group.subscription_pricing' ).pointer( 'close' );
			$( '#product-type' ).pointer( 'close' );
		}
	} );

	function arePointersEnabled() {
		if ( $.getParameterByName( 'subscription_pointers' ) == 'true' ) {
			return true;
		} else {
			return false;
		}
	}

	function showSubscriptionPointers() {
		$( '#product-type' )
			.pointer( {
				content: WCSPointers.typePointerContent,
				position: {
					edge: 'left',
					align: 'center',
				},
				close: function () {
					if (
						$( 'select#product-type' ).val() ==
						WCSubscriptions.productType
					) {
						$(
							'.options_group.subscription_pricing:not(".subscription_sync")'
						)
							.pointer( {
								content: WCSPointers.pricePointerContent,
								position: 'bottom',
								close: function () {
									dismissSubscriptionPointer();
								},
							} )
							.pointer( 'open' );
					}
					dismissSubscriptionPointer();
				},
			} )
			.pointer( 'open' );
	}

	function dismissSubscriptionPointer() {
		$.post( ajaxurl, {
			pointer: 'wcs_pointer',
			action: 'dismiss-wp-pointer',
		} );

		if ( observer ) {
			observer.disconnect();
		}
	}
} );
