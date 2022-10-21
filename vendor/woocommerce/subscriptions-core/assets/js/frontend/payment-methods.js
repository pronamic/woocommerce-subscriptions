jQuery( function ( $ ) {
	/**
	 * Displays an appropriate error message when the delete token button is clicked for a token used by subscriptions.
	 */
	$( '.wcs_deletion_error' ).on( 'click', function ( e ) {
		e.preventDefault();

		// Use the href to determine which notice needs to be displayed.
		if ( '#choose_default' === $( this ).attr( 'href' ) ) {
			$( '#wcs_delete_token_warning' )
				.find( 'li' )
				.html( wcs_payment_methods.choose_default_error );
		} else {
			$( '#wcs_delete_token_warning' )
				.find( 'li' )
				.html( wcs_payment_methods.add_method_error );
		}

		// Display the notice.
		$( '#wcs_delete_token_warning' ).slideDown();
	} );
} );
