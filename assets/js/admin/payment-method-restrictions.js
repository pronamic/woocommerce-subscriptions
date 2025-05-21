jQuery( function( $ ) {

	/**
	 * If the WC core validation passes (errors removed), check our own validation.
	 */
	$( document.body ).on( 'wc_remove_error_tip', function( e, element, removed_error_type ) {
		var product_type = $( '#product-type' ).val();

		if ( 'subscription' !== product_type && 'variable-subscription' !== product_type ) {
			return;
		}

		// We're only interested in the product's recurring price and sale price input.
		if ( 'subscription' === product_type && ! $( element ).is( '#_subscription_price' ) && ! $( element ).is( '#_sale_price' ) ) {
			return;
		}

		if ( 'variable-subscription' === product_type && ! $( element ).hasClass( 'wc_input_subscription_price' ) && ! $( element ).is( '.wc_input_price[name^=variable_sale_price]' ) ) {
			return;
		}

		// Reformat the product price - remove the decimal place separator and remove excess decimal places.
		var price = accounting.unformat( $( element ).val(), wcs_gateway_restrictions.decimal_point_separator );
		price     = accounting.formatNumber( price, wcs_gateway_restrictions.number_of_decimal_places, '' );

		// Error types to validate.
		var zero_error            = 'i18n_zero_subscription_error';
		var displaying_zero_error = element.parent().find( '.wc_error_tip, .' + zero_error ).length !== 0;

		// Check if the product price is 0 or less.
		if ( 0 >= price ) {
			$( document.body ).triggerHandler( 'wc_subscriptions_add_error_tip', [ element, zero_error ] );
			displaying_zero_error = true;
		} else if ( displaying_zero_error && removed_error_type !== zero_error ) {
			$( document.body ).triggerHandler( 'wc_remove_error_tip', [ element, zero_error ] );
			displaying_zero_error = false;
		}

		// Check if the product price is below the amount that can be processed by the payment gateway.
		if ( ! displaying_zero_error && 'undefined' !== typeof wcs_gateway_restrictions.minimum_subscription_amount ) {
			var below_minimum_error      = 'i18n_below_minimum_subscription_error';
			var displaying_minimum_error = element.parent().find( '.wc_error_tip, .' + below_minimum_error ).length !== 0;

			if ( parseFloat( wcs_gateway_restrictions.minimum_subscription_amount ) > parseFloat( price ) ) {
				$( document.body ).triggerHandler( 'wc_subscriptions_add_error_tip', [ element, below_minimum_error ] );
				displaying_minimum_error = true;
			} else if ( displaying_minimum_error && removed_error_type !== below_minimum_error ) {
				$( document.body ).triggerHandler( 'wc_remove_error_tip', [ element, below_minimum_error ] );
				displaying_minimum_error = false;
			}
		}
	} );

	/**
	 * Validate the recurring price or sale price field on element change event or when a validate event is triggered.
	 */
	$( document.body ).on( 'change wc_subscriptions_validate_zero_recurring_price', '#_subscription_price, #_sale_price, .wc_input_subscription_price, .wc_input_price[name^=variable_sale_price]', function() {
		var product_type = $( '#product-type' ).val();

		if ( 'subscription' !== product_type && 'variable-subscription' !== product_type ) {
			return;
		}

		// Reformat the product price - remove the decimal place separator and remove excess decimal places.
		var price = accounting.unformat( $( this ).val(), wcs_gateway_restrictions.decimal_point_separator );
		price     = accounting.formatNumber( price, wcs_gateway_restrictions.number_of_decimal_places );

		if ( 0 >= price ) {
			$( this ).val( '' );
		}
	} );

	/**
	 * When the product type is changed to a subscription product type, validate generic product sale price elements.
	 */
	$( document.body ).on( 'change', '#product-type', function() {
		var product_type = $( '#product-type' ).val();

		if ( 'subscription' !== product_type && 'variable-subscription' !== product_type ) {
			return;
		}

		$( '#_sale_price, .wc_input_price[name^=variable_sale_price]' ).each( function() {
			$( this ).trigger( 'wc_subscriptions_validate_zero_recurring_price' );
		});
	} );


	/**
	 * Displays a WC error tip against an element for a given error type.
	 *
	 * Based on the WC core `wc_add_error_tip` handler callback in woocommerce_admin.js.
	 */
	$( document.body ).on( 'wc_subscriptions_add_error_tip', function( e, element, error_type ) {
		var offset = element.position();

		// Remove any error that is already being shown before adding a new one.
		if ( element.parent().find( '.wc_error_tip' ).length !== 0 ) {
			element.parent().find( '.wc_error_tip' ).remove();
		}

		element.after( '<div class="wc_error_tip ' + error_type + '">' + wcs_gateway_restrictions[ error_type ] + '</div>' );
		element.parent().find( '.wc_error_tip' )
			.css( 'left', offset.left + element.width() - ( element.width() / 2 ) - ( $( '.wc_error_tip' ).width() / 2 ) )
			.css( 'top', offset.top + element.height() )
			.fadeIn( '100' );

	})
} );
