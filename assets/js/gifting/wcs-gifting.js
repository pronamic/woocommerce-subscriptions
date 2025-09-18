jQuery( document ).ready( function ( $ ) {
	setShippingAddressNoticeVisibility( true );

	$( document ).on(
		'change',
		'.woocommerce_subscription_gifting_checkbox[type="checkbox"]',
		function ( e, eventContext ) {
			if ( $( this ).is( ':checked' ) ) {
				$( this )
					.closest( '.wcsg_add_recipient_fields_container' )
					.find( '.wcsg_add_recipient_fields' )
					.slideDown( 250, function () {
						if (
							typeof eventContext === 'undefined' ||
							eventContext !== 'pageload'
						) {
							$( this )
								.find( '.recipient_email' )
								.trigger( 'focus' );
						}
					} );

				const shipToDifferentAddressCheckbox = $( document ).find(
					'#ship-to-different-address-checkbox'
				);
				if ( ! shipToDifferentAddressCheckbox.is( ':checked' ) ) {
					shipToDifferentAddressCheckbox.click();
				}
				setShippingAddressNoticeVisibility( false );
			} else {
				$( this )
					.closest( '.wcsg_add_recipient_fields_container' )
					.find( '.wcsg_add_recipient_fields' )
					.slideUp( 250 );

				const recipientEmailElement = $( this )
					.closest( '.wcsg_add_recipient_fields_container' )
					.find( '.recipient_email' );
				recipientEmailElement.val( '' );
				setShippingAddressNoticeVisibility( true );

				if ( $( 'form.checkout' ).length !== 0 ) {
					// Trigger the event to update the checkout after the recipient field has been cleared.
					updateCheckout();
				}
			}
		}
	);

	/**
	 * Handles showing and hiding the gifting checkbox on variable subscription products.
	 */
	function hideGiftingCheckbox() {
		$( '.woocommerce_subscription_gifting_checkbox[type="checkbox"]' )
			.prop( 'checked', false )
			.trigger( 'change' );
		$( '.wcsg_add_recipient_fields_container' ).hide();
	}

	// When a variation is found, show the gifting checkbox if it's enabled for the variation, otherwise hide it.
	$( document ).on( 'found_variation', function ( event, variationData ) {
		if ( variationData.gifting ) {
			$( '.wcsg_add_recipient_fields_container' ).show();
			return;
		}

		hideGiftingCheckbox();
	} );

	// When the data is reset, hide the gifting checkbox.
	$( document ).on( 'reset_data', hideGiftingCheckbox );

	/**
	 * Handles recipient e-mail inputs on the cart page.
	 */
	const cart = {
		init: function () {
			$( document ).on(
				'submit',
				'div.woocommerce > form',
				this.set_update_cart_as_clicked
			);

			// We need to make sure our callback is hooked before WC's.
			const handlers = $._data( document, 'events' );
			if ( typeof handlers.submit !== 'undefined' ) {
				handlers.submit.unshift( handlers.submit.pop() );
			}
		},

		set_update_cart_as_clicked: function ( evt ) {
			const $form = $( evt.target );
			// eslint-disable-next-line no-restricted-globals
			const $submit = $( document.activeElement );

			// If we're not on the cart page exit.
			if ( $form.find( 'table.shop_table.cart' ).length === 0 ) {
				return;
			}

			// If the recipient email element is the active element, the clicked button is the update cart button.
			if ( $submit.is( 'input.recipient_email' ) ) {
				$( ':input[type="submit"][name="update_cart"]' ).attr(
					'clicked',
					'true'
				);
			}
		},
	};
	cart.init();

	/**
	 * Email validation function
	 *
	 * @param {string} email - The email to validate
	 * @return {boolean} - Whether the email is valid
	 */
	function isValidEmail( email ) {
		const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		return emailRegex.test( email );
	}

	/**
	 * Validate all recipient emails and return overall validation status
	 *
	 * @param {boolean} showErrors - Whether to show validation errors
	 * @return {boolean} - Whether all emails are valid
	 */
	function validateAllRecipientEmails( showErrors = true ) {
		const $allEmailFields = $( '.recipient_email' );
		let allValid = true;

		// Check each email field
		$allEmailFields.each( function () {
			const $emailField = $( this );
			const $giftingCheckbox = $( this )
				.closest( '.wcsg_add_recipient_fields_container' )
				.find( '.woocommerce_subscription_gifting_checkbox' );
			const email = $emailField.val().trim();

			if ( ! $giftingCheckbox.is( ':checked' ) ) {
				return;
			}

			// Check if email format is valid
			if ( ! isValidEmail( email ) ) {
				if ( showErrors ) {
					showValidationErrorForEmailField( $emailField );
				}
				allValid = false;
			}
		} );

		// Control update cart button state
		const $updateCartButton = $(
			'.woocommerce-cart-form :input[type="submit"][name="update_cart"]'
		);

		if ( $updateCartButton.length && ! allValid ) {
			$updateCartButton.prop( 'disabled', true );
		}

		return allValid;
	}

	/**
	 * Validate recipient email and show error if invalid
	 *
	 * @param {jQuery} $emailField - The email input field jQuery object
	 * @return {boolean} - Whether the email is valid
	 */
	function validateRecipientEmail( $emailField ) {
		const email = $emailField.val().trim();

		hideValidationErrorForEmailField( $emailField );

		// Check if email format is valid
		if ( ! isValidEmail( email ) ) {
			showValidationErrorForEmailField( $emailField );

			// Only validate all emails and update button state on cart and checkout shortcode pages.
			if ( isShortcodeCartOrCheckoutPage() ) {
				validateAllRecipientEmails();
			}
			return false;
		}

		// Only validate all emails and update button state on cart and checkout shortcode pages.
		if ( isShortcodeCartOrCheckoutPage() ) {
			validateAllRecipientEmails();
		}
		return true;
	}

	/**
	 * Handle add to cart button click with email validation
	 */
	$( document ).on(
		'click',
		'.single_add_to_cart_button, .add_to_cart_button',
		function ( e ) {
			// Check if we're on a product page with gifting enabled
			const $giftingContainer = $(
				'.wcsg_add_recipient_fields_container'
			);
			if ( $giftingContainer.length === 0 ) {
				return; // No gifting on this page
			}

			// Check if gifting checkbox is checked
			const $giftingCheckbox = $giftingContainer.find(
				'.woocommerce_subscription_gifting_checkbox'
			);
			if ( ! $giftingCheckbox.is( ':checked' ) ) {
				return; // Gifting not enabled for this item
			}

			// Get the recipient email field
			const $emailField = $giftingContainer.find( '.recipient_email' );
			if ( $emailField.length === 0 ) {
				return; // No email field found
			}

			// Validate the email
			if ( ! validateRecipientEmail( $emailField ) ) {
				e.preventDefault();
				e.stopPropagation();

				// Focus on the email field
				$emailField.focus();
				return false;
			}
		}
	);

	/**
	 * Real-time email validation on input
	 */
	$( document ).on( 'blur', '.recipient_email', function () {
		const $emailField = $( this );
		validateRecipientEmail( $emailField );
	} );

	/**
	 * Clear error styling when user starts typing
	 */
	$( document ).on( 'input', '.recipient_email', function () {
		const $emailField = $( this );

		hideValidationErrorForEmailField( $emailField );
	} );

	/*******************************************
	 * Update checkout on input changed events *
	 *******************************************/
	let updateTimer;

	$( document ).on( 'change', '.recipient_email', function () {
		if ( $( 'form.checkout' ).length === 0 ) {
			return;
		}

		if ( validateAllRecipientEmails() ) {
			updateCheckout();
		}
	} );

	$( document ).on( 'keyup', '.recipient_email', function ( e ) {
		const code = e.keyCode || e.which || 0;

		if ( $( 'form.checkout' ).length === 0 || code === 9 ) {
			return true;
		}

		const currentRecipient = $( this ).val();
		const originalRecipient = $( this ).attr( 'data-recipient' );
		resetCheckoutUpdateTimer();

		// If the recipient has changed since last load, mark the element as needing an update.
		if ( currentRecipient !== originalRecipient ) {
			$( this ).addClass( 'wcsg_needs_update' );
			// Only set timer if all emails are valid
			if ( validateAllRecipientEmails( false ) ) {
				updateTimer = setTimeout( updateCheckout, 1500 );
			}
		} else {
			$( this ).removeClass( 'wcsg_needs_update' );
		}
	} );

	function updateCheckout() {
		resetCheckoutUpdateTimer();
		$( '.recipient_email' ).removeClass( 'wcsg_needs_update' );
		$( document.body ).trigger( 'update_checkout' );
	}

	function resetCheckoutUpdateTimer() {
		clearTimeout( updateTimer );
	}

	function setShippingAddressNoticeVisibility( hide = true ) {
		const notice = $( 'form.checkout' )
			.find( '.woocommerce-shipping-fields' )
			.find( '.woocommerce-info' );

		if ( ! notice.length ) {
			return;
		}

		if ( hide ) {
			notice.css( { display: 'none' } );
		} else {
			notice.css( { display: '' } );
		}
	}

	function isShortcodeCartOrCheckoutPage() {
		return (
			$( 'form.woocommerce-cart-form' ).length > 0 ||
			$( 'form.woocommerce-checkout' ).length > 0
		);
	}

	function showValidationErrorForEmailField( $emailField ) {
		$emailField.addClass( 'wcsg-email-error' );
		$emailField
			.closest( '.wcsg_add_recipient_fields' )
			.find( '.wc-shortcode-components-validation-error' )
			.show();
	}

	function hideValidationErrorForEmailField( $emailField ) {
		$emailField.removeClass( 'wcsg-email-error' );
		$emailField
			.closest( '.wcsg_add_recipient_fields' )
			.find( '.wc-shortcode-components-validation-error' )
			.hide();
	}

	// Triggers
	$( '.woocommerce_subscription_gifting_checkbox[type="checkbox"]' ).trigger(
		'change',
		'pageload'
	);

	// Validate all recipient emails on page load to set initial button state
	$( document ).ready( function () {
		setTimeout( function () {
			// Only run validation on cart and checkout shortcode pages
			if ( isShortcodeCartOrCheckoutPage() ) {
				validateAllRecipientEmails();
			}
		}, 1000 );
	} );
} );
