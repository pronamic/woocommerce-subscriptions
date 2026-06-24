jQuery( document ).ready( function ( $ ) {
	setShippingAddressNoticeVisibility( true );

	// Capture whether subscription plans control gifting visibility for this product.
	// PHP adds the 'hidden' class when one-time purchase is the default and
	// a subscription plan must be selected before gifting is available.
	// This must run before any event handler can modify the container classes.
	$( '.wcsg_add_recipient_fields_container' ).each( function () {
		$( this ).data(
			'wcsg_plans_controls_visibility',
			$( this ).hasClass( 'hidden' )
		);
	} );

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
					} )
					.removeClass( 'hidden' );

				const shipToDifferentAddressCheckbox = $( document ).find(
					'#ship-to-different-address-checkbox'
				);
				if ( ! shipToDifferentAddressCheckbox.is( ':checked' ) ) {
					shipToDifferentAddressCheckbox.trigger( 'click' );
				}
				setShippingAddressNoticeVisibility( false );
			} else {
				$( this )
					.closest( '.wcsg_add_recipient_fields_container' )
					.find( '.wcsg_add_recipient_fields' )
					.slideUp( 250 )
					.addClass( 'hidden' );

				const recipientEmailElement = $( this )
					.closest( '.wcsg_add_recipient_fields_container' )
					.find( '.recipient_email' );
				recipientEmailElement.val( '' );
				hideValidationErrorForEmailField( recipientEmailElement );
				setShippingAddressNoticeVisibility( true );

				if ( $( 'form.checkout' ).length !== 0 ) {
					// Trigger the event to update the checkout after the recipient field has been cleared.
					updateCheckout();
				}
			}
		}
	);

	/**
	 * Hide the gifting container.
	 */
	function hideGiftingCheckbox() {
		$( '.wcsg_add_recipient_fields_container' ).addClass( 'hidden' );
	}

	/**
	 * Show the gifting container.
	 */
	function showGiftingCheckbox() {
		$( '.wcsg_add_recipient_fields_container' ).removeClass( 'hidden' );
	}

	/**
	 * Reset gifting fields: uncheck the checkbox and clear the email.
	 */
	function resetGiftingFields() {
		$( '.woocommerce_subscription_gifting_checkbox[type="checkbox"]' )
			.prop( 'checked', false )
			.trigger( 'change' );
	}

	// When a variation is found, show the gifting checkbox if it's enabled for the variation, otherwise hide it.
	// For products with subscription plans, defer visibility to the plan selection listener.
	$( document ).on( 'found_variation', function ( event, variationData ) {
		if ( variationData.gifting ) {
			const $container = $( '.wcsg_add_recipient_fields_container' );

			// If subscription plans control visibility (non-subscription products with subscription plans),
			// defer to the change:active_scheme_key listener which shows the
			// container only when a subscription plan is actually selected.
			// We use the flag captured at page load because subscription plans JS may not have
			// added its DOM elements yet when found_variation fires.
			if ( $container.data( 'wcsg_plans_controls_visibility' ) ) {
				return;
			}

			showGiftingCheckbox();
			return;
		}

		resetGiftingFields();
		hideGiftingCheckbox();
	} );

	// When the data is reset, reset and hide the gifting checkbox.
	$( document ).on( 'reset_data', function () {
		resetGiftingFields();
		hideGiftingCheckbox();
	} );

	/**
	 * Subscription plans integration.
	 *
	 * Shows/hides the gifting checkbox when the user toggles between
	 * one-time purchase and subscription plans on product pages.
	 *
	 * Uses per-form tracking (via jQuery data) instead of a global flag so that
	 * dynamically added forms (e.g. quickview modals) get their own listener.
	 * This mirrors how the subscription plans extension uses maybe_initialize_form() with a per-form guard.
	 */
	function bindSubscriptionPlanListener() {
		$( 'form.cart' ).each( function () {
			const $form = $( this );

			if ( $form.data( 'wcsg_listener_bound' ) ) {
				return;
			}

			const sattScript = $form.data( 'satt_script' );
			if ( ! sattScript || ! sattScript.schemes_model ) {
				return;
			}

			$form.data( 'wcsg_listener_bound', true );

			sattScript.schemes_model.on(
				'change:active_scheme_key',
				function ( model, value ) {
					// '0' is the one-time purchase key - only show for actual subscription plans.
					if ( value && value !== '0' ) {
						showGiftingCheckbox();
					} else if (
						$form.find( '.wcsatt-options-wrapper' ).length
					) {
						// Only hide when subscription plans actually control the
						// purchase mode for this product. On native subscription
						// products without plans, the scheme key may reset when a
						// variation changes - the found_variation handler manages
						// gifting visibility in that case.
						hideGiftingCheckbox();
					}
				}
			);

			// If a subscription plan is already active on page load (subscription-only product), show immediately.
			const initialSchemeKey =
				sattScript.schemes_model.get( 'active_scheme_key' );
			if ( initialSchemeKey && initialSchemeKey !== '0' ) {
				showGiftingCheckbox();
			}
		} );
	}

	// Try binding immediately (works when subscription plans script loads before gifting).
	bindSubscriptionPlanListener();

	// Deferred fallback: subscription plans script typically loads after gifting, so defer to run
	// after all document.ready callbacks complete.
	setTimeout( function () {
		bindSubscriptionPlanListener();
	}, 0 );

	// Window load fallback: handles cases where subscription plans script initializes very late.
	$( window ).on( 'load', function () {
		bindSubscriptionPlanListener();
	} );

	// External initialization fallback: handles quickview modals and other
	// cases where subscription plans reinitialize dynamically after page load.
	$( document.body ).on( 'wcsatt-initialize', function () {
		bindSubscriptionPlanListener();
	} );

	/**
	 * Repositions the gifting container below the subscription plans options.
	 *
	 * For bundles and composites, subscription plans JS moves its options inside the
	 * bundle_wrap/composite_wrap div (after bundle_price/composite_price).
	 * This runs after subscription plans initialization to place the gifting container
	 * right after the relocated subscription plans options.
	 */
	function repositionGiftingContainer() {
		$( 'form.cart' ).each( function () {
			const $form = $( this );

			if ( $form.data( 'wcsg_gifting_repositioned' ) ) {
				return;
			}

			const $giftingContainer = $form.find(
				'.wcsg_add_recipient_fields_container'
			);
			const $planOptions = $form.find( '.wcsatt-options-wrapper' );

			if ( ! $giftingContainer.length || ! $planOptions.length ) {
				return;
			}

			// Only reposition if they aren't already adjacent.
			if (
				$giftingContainer.prev( '.wcsatt-options-wrapper' ).length > 0
			) {
				$form.data( 'wcsg_gifting_repositioned', true );
				return;
			}

			$planOptions.after( $giftingContainer );
			$form.data( 'wcsg_gifting_repositioned', true );
		} );
	}

	// Reposition after subscription plans JS has finished moving its UI elements.
	// For bundles: subscription plans JS hooks into 'woocommerce-product-bundle-initializing' and
	// moves options in initialize_ui(). We listen for 'initialized' (fires after).
	$( '.bundle_form .bundle_data' ).on(
		'woocommerce-product-bundle-initialized',
		repositionGiftingContainer
	);

	// For composites: subscription plans JS moves options during 'wc-composite-initializing'.
	// Defer to the next tick so subscription plans JS finishes its DOM move first.
	$( '.composite_form .composite_data' ).on(
		'wc-composite-initializing',
		function () {
			setTimeout( repositionGiftingContainer, 0 );
		}
	);

	// For dynamically loaded forms (quickview modals, etc.).
	$( document.body ).on( 'wcsatt-initialize', repositionGiftingContainer );

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
				$( '[type="submit"][name="update_cart"]' ).attr(
					'clicked',
					'true'
				);
			}
		},
	};
	cart.init();

	// Classic cart: show/hide gifting when subscription plan radio buttons change.
	// The radio buttons (.wcsatt-options input[type="radio"]) and the gifting
	// container (.wcsg_add_recipient_fields_container) are in different <td>
	// elements but the same <tr>, so closest('tr') scopes per cart item.
	$( document ).on(
		'change',
		'.wcsatt-options input[type="radio"]',
		function () {
			var $radio = $( this );
			var value = $radio.val();
			var $row = $radio.closest( 'tr' );
			var $container = $row.find(
				'.wcsg_add_recipient_fields_container'
			);

			if ( ! $container.length ) {
				return;
			}

			// '0' is the one-time purchase value.
			if ( value && value !== '0' ) {
				$container.removeClass( 'hidden' );
			} else {
				// Hide without resetting state so gifting checkbox and
				// email persist across plan toggles.
				$container.addClass( 'hidden' );
			}
		}
	);

	// Clear gifting data on form submit when one-time purchase is selected.
	// Since we preserve gifting state visually across plan toggles, the
	// recipient email input may still have a value when one-time is active.
	// Clear it before submit so gifting data is not applied to one-time purchases.

	// Product page: clear gifting fields if the active scheme is one-time.
	$( document ).on( 'submit', 'form.cart', function () {
		const $form = $( this );

		// Only act when subscription plan options are present. Native
		// subscription products have no plan toggle and should not be
		// affected by this cleanup.
		if ( ! $form.find( '.wcsatt-options' ).length ) {
			return;
		}

		const sattScript = $form.data( 'satt_script' );

		if ( ! sattScript || ! sattScript.schemes_model ) {
			return;
		}

		const activeScheme =
			sattScript.schemes_model.get( 'active_scheme_key' );

		if ( ! activeScheme || activeScheme === '0' ) {
			$form
				.find( '.woocommerce_subscription_gifting_checkbox' )
				.prop( 'checked', false );
			$form.find( '.recipient_email' ).val( '' );
		}
	} );

	// Classic cart: clear gifting fields for rows where one-time is selected.
	$( document ).on( 'submit', 'form.woocommerce-cart-form', function () {
		$( this )
			.find( '.wcsatt-options input[type="radio"]:checked' )
			.each( function () {
				if ( $( this ).val() === '0' ) {
					const $row = $( this ).closest( 'tr' );
					$row.find( '.woocommerce_subscription_gifting_checkbox' ).prop(
						'checked',
						false
					);
					$row.find( '.recipient_email' ).val( '' );
				}
			} );
	} );

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
			'.woocommerce-cart-form [type="submit"][name="update_cart"]'
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
				$emailField.trigger( 'focus' );
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
