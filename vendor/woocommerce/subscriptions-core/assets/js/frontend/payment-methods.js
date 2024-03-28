jQuery( function ( $ ) {
	/**
	 * Displays an appropriate error message when the delete token button is clicked for a token used by subscriptions.
	 */
	$( '.wcs_deletion_error' ).on( 'click', function ( e ) {
		e.preventDefault();

		var notice_content_container = $( '#wcs_delete_token_warning' ).find( 'li' );

		// For block based WC notices we need to find the notice content container.
		if ( $( '#wcs_delete_token_warning' ).find( '.wc-block-components-notice-banner' ).length > 0 ) {
			notice_content_container = $( '#wcs_delete_token_warning' ).find( '.wc-block-components-notice-banner__content' );
		}

		// Use the href to determine which notice needs to be displayed.
		if ( '#choose_default' === $( this ).attr( 'href' ) ) {
			notice_content_container.html( wcs_payment_methods.choose_default_error );
		} else {
			notice_content_container.html( wcs_payment_methods.add_method_error );
		}

		// Display the notice.
		$( '#wcs_delete_token_warning' ).slideDown();
	} );
} );
