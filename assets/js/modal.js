jQuery( function ( $ ) {
	const modals = $( '.wcs-modal' );

	// Resize all open modals on window resize.
	$( window ).on( 'resize', resizeModals );

	// Initialize modals
	$( modals ).each( function () {
		trigger = $( this ).data( 'modal-trigger' );
		$( trigger ).on( 'click', { modal: this }, show_modal );
	} );

	/**
	 * Displays the modal linked to a click event.
	 *
	 * Attaches all close callbacks and resizes to fit.
	 *
	 * @param {JQuery event} event
	 */
	function show_modal( event ) {
		const modal = $( event.data.modal );

		if ( ! should_show_modal( modal ) ) {
			return;
		}

		// Prevent the trigger element event being triggered.
		event.preventDefault();

		const contentWrapper = modal.find( '.content-wrapper' );
		const close = modal.find( '.close' );

		modal.trigger( 'focus' );
		modal.addClass( 'open' );

		resizeModal( modal );

		$( document.body ).toggleClass( 'wcs-modal-open', true );

		// Attach callbacks to handle closing the modal.
		close.on( 'click', () => close_modal( modal ) );
		modal.on( 'click', () => close_modal( modal ) );
		contentWrapper.on( 'click', ( e ) => e.stopPropagation() );

		// Close the modal if the escape key is pressed.
		modal.on( 'keyup', function ( e ) {
			if ( 27 === e.keyCode ) {
				close_modal( modal );
			}
		} );
	}

	/**
	 * Closes a modal and resets any forced height styles.
	 *
	 * @param {JQuery Object} modal
	 */
	function close_modal( modal ) {
		modal.removeClass( 'open' );
		$( modal ).find( '.content-wrapper' ).css( 'height', '' );

		if ( 0 === modals.filter( '.open' ).length ) {
			$( document.body ).removeClass( 'wcs-modal-open' );
		}
	}

	/**
	 * Determines if a modal should be displayed.
	 *
	 * A custom trigger is called to allow third-parties to filter whether the modal should be displayed or not.
	 *
	 * @param {JQuery Object} modal
	 */
	function should_show_modal( modal ) {
		// Allow third-parties to filter whether the modal should be displayed.
		var event = jQuery.Event( 'wcs_show_modal' );
		event.modal = modal;

		$( document ).trigger( event );

		// Fallback to true (show modal) if the result is undefined.
		return undefined === event.result ? true : event.result;
	}

	/**
	 * Resize all open modals to fit the display.
	 */
	function resizeModals() {
		$( modals ).each( function () {
			if ( ! $( this ).hasClass( 'open' ) ) {
				return;
			}

			resizeModal( this );
		} );
	}

	/**
	 * Resize a modal to fit the display.
	 *
	 * @param {JQuery Object} modal
	 */
	function resizeModal( modal ) {
		var modal_container = $( modal ).find( '.content-wrapper' );

		// On smaller displays the height is already forced to be 100% in CSS. We just clear any height we might set previously.
		if ( $( window ).width() <= 414 ) {
			modal_container.css( 'height', '' );
		} else if ( modal_container.height() > $( window ).height() ) {
			// Force the container height to trigger scroll etc if it doesn't fit on the screen.
			modal_container.css( 'height', '90%' );
		}
	}
} );
