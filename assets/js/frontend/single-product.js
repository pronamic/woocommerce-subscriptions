( function ( document, $ ) {
	var $cache = {};

	/**
	 * Cache our DOM selectors.
	 */
	function generate_cache() {
		$cache.document = $( document );
		$cache.first_payment_date = $( '.first-payment-date' );
		$cache.subscription_details = $(
			'.woocommerce-subscriptions-product-details'
		);
		// Match the product-type class wherever it lands: on the product wrapper in classic themes, or on the <body>
		// in block themes (where the wrapper is a generic wp-block-group).
		$cache.is_variable_subscription =
			0 < $( '.product-type-variable-subscription' ).length;
	}

	/**
	 * Attach DOM events.
	 */
	function attach_events() {
		if ( $cache.is_variable_subscription ) {
			$cache.document.on( 'found_variation', update_variation_elements );
			$cache.document.on( 'reset_data', clear_variation_elements );
		}
	}

	/**
	 * Update the variation's first payment date and subscription detail lines.
	 *
	 * Shown only once a variation is found (i.e. the add to cart button is enabled).
	 *
	 * @param {jQuery.Event} event
	 * @param {object} variation_data
	 */
	function update_variation_elements( event, variation_data ) {
		var details = variation_data.subscription_details_html || '';
		var has_details = '' !== details;

		$cache.first_payment_date.html( variation_data.first_payment_html );
		$cache.subscription_details.html( details ).toggle( has_details );
		tighten_variation_price_spacing( has_details );
	}

	/**
	 * Clear and hide the variation's first payment date and subscription detail lines.
	 */
	function clear_variation_elements() {
		$cache.first_payment_date.html( '' );
		$cache.subscription_details.empty().hide();
		tighten_variation_price_spacing( false );
	}

	/**
	 * Toggle the variation price's bottom margin.
	 *
	 * When the detail lines are shown, the price's bottom margin is removed (via CSS keyed off this class) so they sit
	 * directly beneath it — matching the spacing between the detail lines themselves. When there are no detail lines,
	 * the margin is kept so the price retains its normal spacing above the add to cart button.
	 *
	 * The class is toggled on the (stable) variation wrapper rather than setting the margin on the price element
	 * directly, because WooCommerce re-renders the price element on every variation change — often after this handler
	 * runs — which would wipe an inline style.
	 *
	 * @param {boolean} tighten Whether to remove the price's bottom margin.
	 */
	function tighten_variation_price_spacing( tighten ) {
		$cache.subscription_details
			.closest( '.single_variation_wrap' )
			.toggleClass( 'wcsatt-variation-has-details', tighten );
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
