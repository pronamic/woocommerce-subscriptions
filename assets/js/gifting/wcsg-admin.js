jQuery( document ).ready( function ( $ ) {
	// Remove WC's revoke handler to make sure that only our handler is called (to make sure only the correct permissions are revoked not all permissions matching the product/order ID)
	$( '.order_download_permissions' ).off( 'click', 'button.revoke_access' );

	$( '.order_download_permissions' ).on(
		'click',
		'button.revoke_access',
		function () {
			if (
				window.confirm(
					woocommerce_admin_meta_boxes.i18n_permission_revoke
				)
			) {
				var el = $( this ).parent().parent();
				var permission_id = $( this )
					.siblings()
					.find( '.wcsg_download_permission_id' )
					.val();
				var post_id = $( '#post_ID' ).val();

				if ( 0 < permission_id ) {
					$( el ).block( {
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6,
						},
					} );

					var data = {
						action: 'wcsg_revoke_access_to_download',
						post_id: post_id,
						download_permission_id: permission_id,
						nonce: wcs_gifting.revoke_download_permission_nonce,
					};

					$.ajax( {
						url: wcs_gifting.ajax_url,
						data: data,
						type: 'POST',
						success: function () {
							// Success
							$( el ).fadeOut( '300', function () {
								$( el ).remove();
							} );
						},
					} );
				} else {
					$( el ).fadeOut( '300', function () {
						$( el ).remove();
					} );
				}
			}
		}
	);
} );
