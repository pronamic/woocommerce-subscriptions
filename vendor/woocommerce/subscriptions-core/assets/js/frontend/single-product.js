( function ( document, $ ) {
	var $cache = {};

	/**
	 * Cache our DOM selectors.
	 */
	function generate_cache() {
		$cache.document = $( document );
		$cache.first_payment_date = $( '.first-payment-date' );
		$cache.is_variable_subscription =
			0 < $( 'div.product-type-variable-subscription' ).length;
	}

	/**
	 * Attach DOM events.
	 */
	function attach_events() {
		if ( $cache.is_variable_subscription ) {
			$cache.document.on(
				'found_variation',
				update_first_payment_element
			);
			$cache.document.on( 'reset_data', clear_first_payment_element );
		}
	}

	/**
	 * Update the variation's first payment element.
	 *
	 * @param {jQuery.Event} event
	 * @param {object} variation_data
	 */
	function update_first_payment_element( event, variation_data ) {
		$cache.first_payment_date.html( variation_data.first_payment_html );
	}

	/**
	 * Clear the variation's first payment element.
	 */
	function clear_first_payment_element() {
		$cache.first_payment_date.html( '' );
	}

	/**
	 * Initialise.
	 */
	function init() {
		generate_cache();
		attach_events();
	}

	$( init );
} )( document, jQuery );
