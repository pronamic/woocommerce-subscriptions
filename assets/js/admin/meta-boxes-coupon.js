jQuery( function ( $ ) {
	'use strict';

	var renewals_field = document.querySelector( '.wcs_number_payments_field' ),
		$renewals_field = $( renewals_field );

	/**
	 * Subscription coupon actions.
	 * @type {{init: function, type_options: function, move_field: function}}
	 */
	var wcs_meta_boxes_coupon_actions = {
		/**
		 * Initialize variation actions.
		 */
		init: function () {
			if ( renewals_field ) {
				$( document.getElementById( 'discount_type' ) )
					.on( 'change', this.type_options )
					.trigger( 'change' );
				this.move_field();
			}
		},

		/**
		 * Show/hide fields by coupon type options.
		 */
		type_options: function () {
			var select_val = $( this ).val();

			switch ( select_val ) {
				case 'recurring_fee':
				case 'recurring_percent':
					$renewals_field.show();
					break;

				default:
					$renewals_field.hide();
					break;
			}
		},

		/**
		 * Move the renewal form field in the DOM to a better location.
		 */
		move_field: function () {
			var parent = document.getElementById( 'general_coupon_data' ),
				shipping = parent.querySelector( '.free_shipping_field' );

			parent.insertBefore( renewals_field, shipping );
		},
	};

	wcs_meta_boxes_coupon_actions.init();
} );
