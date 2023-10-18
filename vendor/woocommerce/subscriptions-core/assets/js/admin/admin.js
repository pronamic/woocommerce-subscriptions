jQuery( function ( $ ) {
	$.extend( {
		getParameterByName: function ( name ) {
			name = name.replace( /[\[]/, '\\[' ).replace( /[\]]/, '\\]' );
			var regexS = '[\\?&]' + name + '=([^&#]*)';
			var regex = new RegExp( regexS );
			var results = regex.exec( window.location.search );
			if ( results == null ) {
				return '';
			} else {
				return decodeURIComponent( results[ 1 ].replace( /\+/g, ' ' ) );
			}
		},
		daysInMonth: function ( month ) {
			// Intentionally choose a non-leap year because we want february to have only 28 days.
			return new Date( Date.UTC( 2001, month, 0 ) ).getUTCDate();
		},
		showHideSubscriptionMeta: function () {
			if (
				$( 'select#product-type' ).val() == WCSubscriptions.productType
			) {
				$( '.show_if_simple' ).show();
				$( '.grouping_options' ).hide();
				$( '.options_group.pricing ._regular_price_field' ).hide();
				$( '#sale-price-period' ).show();
				$( '.hide_if_subscription' ).hide();
				$( 'input#_manage_stock' ).trigger( 'change' );

				if ( 'day' == $( '#_subscription_period' ).val() ) {
					$( '.subscription_sync' ).hide();
				}
			} else {
				$( '.options_group.pricing ._regular_price_field' ).show();
				$( '#sale-price-period' ).hide();
			}
		},
		showHideVariableSubscriptionMeta: function () {
			// In order for WooCommerce not to show the stock_status_field on variable subscriptions, make sure it has the hide if variable subscription class.
			$( 'p.stock_status_field' ).addClass(
				'hide_if_variable-subscription'
			);

			var product_type = $( 'select#product-type' ).val();

			if ( 'variable-subscription' === product_type ) {
				// Hide and show subscription fields when variable subscription is selected
				$( 'input#_downloadable' ).prop( 'checked', false );
				$( 'input#_virtual' ).prop( 'checked', false );

				// Variable subscriptions inherit fields from variable products.
				$( '.show_if_variable' ).show();
				$( '.hide_if_variable' ).hide();
				$( '.show_if_variable-subscription' ).show();
				$( '.hide_if_variable-subscription' ).hide();
				$.showOrHideStockFields();

				// Make the sale price row full width
				$( '.sale_price_dates_fields' )
					.prev( '.form-row' )
					.addClass( 'form-row-full' )
					.removeClass( 'form-row-last' );
			} else {
				if ( 'subscription' === product_type ) {
					$( '.show_if_subscription' ).show();
					$( '.hide_if_subscription' ).hide();
				}

				// Restore the sale price row width to half
				$( '.sale_price_dates_fields' )
					.prev( '.form-row' )
					.removeClass( 'form-row-full' )
					.addClass( 'form-row-last' );
			}
		},
		enableSubscriptionProductFields: function () {
			product_type = $( 'select#product-type' ).val();
			enable_type  = '';

			// Variable subscriptions need to enable variable product fields and subscriptions products need to enable simple product fields.
			if ( 'variable-subscription' === product_type ) {
				enable_type = 'variable'
			} else if ( 'subscription' === product_type ) {
				enable_type = 'simple';
			}

			if ( enable_type ) {
				$( `.enable_if_${ enable_type }` ).each( function () {
					$( this ).removeClass( 'disabled' );
					if ( $( this ).is( 'input' ) ) {
						$( this ).prop( 'disabled', false );
					}
				} );
			}
		},
		showOrHideStockFields: function () {
			if ( $( 'input#_manage_stock' ).is( ':checked' ) ) {
				$( 'div.stock_fields' ).show();
			} else {
				$( 'div.stock_fields' ).hide();
			}
		},
		setSubscriptionLengths: function () {
			$(
				'[name^="_subscription_length"], [name^="variable_subscription_length"]'
			).each( function () {
				var $lengthElement = $( this ),
					selectedLength = $lengthElement.val(),
					hasSelectedLength = false,
					matches = $lengthElement
						.attr( 'name' )
						.match( /\[(.*?)\]/ ),
					periodSelector,
					interval;

				if ( matches ) {
					// Variation
					periodSelector =
						'[name="variable_subscription_period[' +
						matches[ 1 ] +
						']"]';
					billingInterval = parseInt(
						$(
							'[name="variable_subscription_period_interval[' +
								matches[ 1 ] +
								']"]'
						).val()
					);
				} else {
					periodSelector = '#_subscription_period';
					billingInterval = parseInt(
						$( '#_subscription_period_interval' ).val()
					);
				}

				$lengthElement.empty();

				$.each(
					WCSubscriptions.subscriptionLengths[
						$( periodSelector ).val()
					],
					function ( length, description ) {
						if (
							parseInt( length ) == 0 ||
							0 == parseInt( length ) % billingInterval
						) {
							$lengthElement.append(
								$( '<option></option>' )
									.attr( 'value', length )
									.text( description )
							);
						}
					}
				);

				$lengthElement.children( 'option' ).each( function () {
					if ( this.value == selectedLength ) {
						hasSelectedLength = true;
						return false;
					}
				} );

				if ( hasSelectedLength ) {
					$lengthElement.val( selectedLength );
				} else {
					$lengthElement.val( 0 );
				}
			} );
		},
		setTrialPeriods: function () {
			$(
				'[name^="_subscription_trial_length"], [name^="variable_subscription_trial_length"]'
			).each( function () {
				var $trialLengthElement = $( this ),
					trialLength = $trialLengthElement.val(),
					matches = $trialLengthElement
						.attr( 'name' )
						.match( /\[(.*?)\]/ ),
					periodStrings;

				if ( matches ) {
					// Variation
					$trialPeriodElement = $(
						'[name="variable_subscription_trial_period[' +
							matches[ 1 ] +
							']"]'
					);
				} else {
					$trialPeriodElement = $( '#_subscription_trial_period' );
				}

				selectedTrialPeriod = $trialPeriodElement.val();

				$trialPeriodElement.empty();

				if ( parseInt( trialLength ) == 1 ) {
					periodStrings = WCSubscriptions.trialPeriodSingular;
				} else {
					periodStrings = WCSubscriptions.trialPeriodPlurals;
				}

				$.each( periodStrings, function ( key, description ) {
					$trialPeriodElement.append(
						$( '<option></option>' )
							.attr( 'value', key )
							.text( description )
					);
				} );

				$trialPeriodElement.val( selectedTrialPeriod );
			} );
		},
		setSalePeriod: function () {
			$( '#sale-price-period' ).fadeOut( 80, function () {
				$( '#sale-price-period' ).text(
					$(
						'#_subscription_period_interval option:selected'
					).text() +
						' ' +
						$( '#_subscription_period option:selected' ).text()
				);
				$( '#sale-price-period' ).fadeIn( 180 );
			} );
		},
		setSyncOptions: function ( periodField ) {
			if ( typeof periodField != 'undefined' ) {
				if (
					$( 'select#product-type' ).val() == 'variable-subscription'
				) {
					var $container = periodField
						.closest( '.woocommerce_variable_attributes' )
						.find( '.variable_subscription_sync' );
				} else {
					$container = periodField
						.closest( '#general_product_data' )
						.find( '.subscription_sync' );
				}

				var $syncWeekMonthContainer = $container.find(
						'.subscription_sync_week_month'
					),
					$syncWeekMonthSelect = $syncWeekMonthContainer.find(
						'select'
					),
					$syncAnnualContainer = $container.find(
						'.subscription_sync_annual'
					),
					$varSubField = $container.find(
						'[name^="variable_subscription_payment_sync_date"]'
					),
					billingPeriod;

				if ( $varSubField.length > 0 ) {
					// Variation
					var matches = $varSubField
						.attr( 'name' )
						.match( /\[(.*?)\]/ );
					$subscriptionPeriodElement = $(
						'[name="variable_subscription_period[' +
							matches[ 1 ] +
							']"]'
					);
				} else {
					$subscriptionPeriodElement = $( '#_subscription_period' );
				}

				billingPeriod = $subscriptionPeriodElement.val();

				if ( 'day' == billingPeriod ) {
					$syncWeekMonthSelect.val( 0 );
					$syncAnnualContainer
						.find( 'input[type="number"]' )
						.val( 0 )
						.trigger( 'change' );
				} else {
					if ( 'year' == billingPeriod ) {
						// Make sure the year sync fields are reset
						$syncAnnualContainer
							.find( 'input[type="number"]' )
							.val( 0 )
							.trigger( 'change' );
						// And the week/month field has no option selected
						$syncWeekMonthSelect.val( 0 );
					} else {
						// Make sure the year sync value is 0
						$syncAnnualContainer
							.find( 'input[type="number"]' )
							.val( 0 )
							.trigger( 'change' );

						// And the week/month field has the appropriate options
						$syncWeekMonthSelect.empty();
						$.each(
							WCSubscriptions.syncOptions[ billingPeriod ],
							function ( key, description ) {
								$syncWeekMonthSelect.append(
									$( '<option></option>' )
										.attr( 'value', key )
										.text( description )
								);
							}
						);
					}
				}
			}
		},
		showHideSyncOptions: function () {
			if (
				$( '#_subscription_payment_sync_date' ).length > 0 ||
				$( '.wc_input_subscription_payment_sync' ).length > 0
			) {
				$( '.subscription_sync, .variable_subscription_sync' ).each(
					function () {
						// loop through all sync field groups
						var $syncWeekMonthContainer = $( this ).find(
								'.subscription_sync_week_month'
							),
							$syncWeekMonthSelect = $syncWeekMonthContainer.find(
								'select'
							),
							$syncAnnualContainer = $( this ).find(
								'.subscription_sync_annual'
							),
							$varSubField = $( this ).find(
								'[name^="variable_subscription_payment_sync_date"]'
							),
							$slideSwitch = false, // stop the general sync field group sliding down if editing a variable subscription
							billingPeriod;

						if ( $varSubField.length > 0 ) {
							// Variation
							var matches = $varSubField
								.attr( 'name' )
								.match( /\[(.*?)\]/ );
							$subscriptionPeriodElement = $(
								'[name="variable_subscription_period[' +
									matches[ 1 ] +
									']"]'
							);

							if (
								$( 'select#product-type' ).val() ==
								'variable-subscription'
							) {
								$slideSwitch = true;
							}
						} else {
							$subscriptionPeriodElement = $(
								'#_subscription_period'
							);
							if (
								$( 'select#product-type' ).val() ==
								WCSubscriptions.productType
							) {
								$slideSwitch = true;
							}
						}

						billingPeriod = $subscriptionPeriodElement.val();

						if ( 'day' == billingPeriod ) {
							$( this ).slideUp( 400 );
						} else {
							if ( $slideSwitch ) {
								$( this ).slideDown( 400 );
								if ( 'year' == billingPeriod ) {
									// Make sure the year sync fields are visible
									$syncAnnualContainer.slideDown( 400 );
									// And the week/month field is hidden
									$syncWeekMonthContainer.slideUp( 400 );
								} else {
									// Make sure the year sync fields are hidden
									$syncAnnualContainer.slideUp( 400 );
									// And the week/month field is visible
									$syncWeekMonthContainer.slideDown( 400 );
								}
							}
						}
					}
				);
			}
		},
		moveSubscriptionVariationFields: function () {
			$( '#variable_product_options .variable_subscription_pricing' )
				.not( 'wcs_moved' )
				.each( function () {
					var $regularPriceRow = $( this ).siblings(
							'.variable_pricing'
						),
						$trialSignUpRow = $( this ).siblings(
							'.variable_subscription_trial_sign_up'
						),
						$saleDatesRow;

					$saleDatesRow = $( this ).siblings( '.variable_pricing' );

					// Add the subscription price fields above the standard price fields
					$( this ).insertBefore( $regularPriceRow );

					$trialSignUpRow.insertBefore( $( this ) );

					// Replace the regular price field with the trial period field
					$regularPriceRow
						.children( ':first' )
						.addClass( 'hide_if_variable-subscription' );

					$( this ).addClass( 'wcs_moved' );
				} );
		},
		getVariationBulkEditValue: function ( variation_action ) {
			var value;

			switch ( variation_action ) {
				case 'variable_subscription_period':
				case 'variable_subscription_trial_period':
					value = prompt( WCSubscriptions.bulkEditPeriodMessage );
					break;
				case 'variable_subscription_period_interval':
					value = prompt( WCSubscriptions.bulkEditIntervalhMessage );
					break;
				case 'variable_subscription_trial_length':
				case 'variable_subscription_length':
					value = prompt( WCSubscriptions.bulkEditLengthMessage );
					break;
				case 'variable_subscription_sign_up_fee':
					value = prompt(
						woocommerce_admin_meta_boxes_variations.i18n_enter_a_value
					);
					value = accounting.unformat(
						value,
						woocommerce_admin.mon_decimal_point
					);
					break;
			}

			return value;
		},
		disableEnableOneTimeShipping: function () {
			var is_synced_or_has_trial = false;

			if ( 'variable-subscription' == $( 'select#product-type' ).val() ) {
				var variations = $(
						'.woocommerce_variations .woocommerce_variation'
					),
					variations_checked = {},
					number_of_pages = $( '.woocommerce_variations' ).attr(
						'data-total_pages'
					);

				$( variations ).each( function () {
					var period_field = $( this ).find(
							'.wc_input_subscription_period'
						),
						variation_index = $( period_field )
							.attr( 'name' )
							.match( /\[(.*?)\]/ ),
						variation_id = $(
							'[name="variable_post_id[' +
								variation_index[ 1 ] +
								']"]'
						).val(),
						period = period_field.val(),
						trial = $( this )
							.find( '.wc_input_subscription_trial_length' )
							.val(),
						sync_date = 0;

					if ( 0 != trial ) {
						is_synced_or_has_trial = true;

						// break
						return false;
					}

					if (
						$( this ).find( '.variable_subscription_sync' ).length
					) {
						if ( 'month' == period || 'week' == period ) {
							sync_date = $(
								'[name="variable_subscription_payment_sync_date[' +
									variation_index[ 1 ] +
									']"]'
							).val();
						} else if ( 'year' == period ) {
							sync_date = $(
								'[name="variable_subscription_payment_sync_date_day[' +
									variation_index[ 1 ] +
									']"]'
							).val();
						}

						if ( 0 != sync_date ) {
							is_synced_or_has_trial = true;

							// break
							return false;
						}
					}

					variations_checked[ variation_index[ 1 ] ] = variation_id;
				} );

				// if we haven't found a variation synced or with a trial at this point check the backend for other product variations
				if (
					( number_of_pages > 1 || 0 == variations.length ) &&
					false == is_synced_or_has_trial
				) {
					var data = {
						action: 'wcs_product_has_trial_or_is_synced',
						product_id:
							woocommerce_admin_meta_boxes_variations.post_id,
						variations_checked: variations_checked,
						nonce: WCSubscriptions.oneTimeShippingCheckNonce,
					};

					$.ajax( {
						url: WCSubscriptions.ajaxUrl,
						data: data,
						type: 'POST',
						success: function ( response ) {
							$( '#_subscription_one_time_shipping' ).prop(
								'disabled',
								response.is_synced_or_has_trial
							);
							// trigger an event now we have determined the one time shipping availability, in case we need to update the backend
							$(
								'#_subscription_one_time_shipping'
							).trigger(
								'subscription_one_time_shipping_updated',
								[ response.is_synced_or_has_trial ]
							);
						},
					} );
				} else {
					// trigger an event now we have determined the one time shipping availability, in case we need to update the backend
					$(
						'#_subscription_one_time_shipping'
					).trigger( 'subscription_one_time_shipping_updated', [
						is_synced_or_has_trial,
					] );
				}
			} else {
				var trial = $(
					'#general_product_data #_subscription_trial_length'
				).val();

				if ( 0 != trial ) {
					is_synced_or_has_trial = true;
				}

				if (
					$( '.subscription_sync' ).length &&
					false == is_synced_or_has_trial
				) {
					var period = $( '#_subscription_period' ).val(),
						sync_date = 0;

					if ( 'month' == period || 'week' == period ) {
						sync_date = $(
							'#_subscription_payment_sync_date'
						).val();
					} else if ( 'year' == period ) {
						sync_date = $(
							'#_subscription_payment_sync_date_day'
						).val();
					}

					if ( 0 != sync_date ) {
						is_synced_or_has_trial = true;
					}
				}
			}

			$( '#_subscription_one_time_shipping' ).prop(
				'disabled',
				is_synced_or_has_trial
			);
		},
		showHideSubscriptionsPanels: function () {
			var tab = $( 'div.panel-wrap' )
				.find( 'ul.wc-tabs li' )
				.eq( 0 )
				.find( 'a' );
			var panel = tab.attr( 'href' );
			var visible = $( panel )
				.children( '.options_group' )
				.filter( function () {
					return 'none' != $( this ).css( 'display' );
				} );
			if ( 0 != visible.length ) {
				tab.trigger( 'click' ).parent().show();
			}
		},
		maybeDisableRemoveLinks: function () {
			$( '#variable_product_options .woocommerce_variation' ).each(
				function () {
					var $removeLink = $( this ).find( '.remove_variation' );

					if ( WCSubscriptions.isLargeSite ) {
						$removeLink.addClass( 'wcs_validate_variation_delete' );
						$removeLink.removeClass( 'remove_variation' );
					} else {
						var can_remove_variation =
							'1' ===
							$( this )
								.find( 'input.wcs-can-remove-variation' )
								.val();
						var $msg = $( this ).find(
							'.wcs-can-not-remove-variation-msg'
						);

						if ( ! can_remove_variation ) {
							$msg.text( $removeLink.text() );
							$removeLink.replaceWith( $msg );
						}
					}
				}
			);
		},
	} );

	$( '.options_group.pricing ._sale_price_field .description' ).prepend(
		'<span id="sale-price-period" style="display: none;"></span>'
	);

	// Move the subscription pricing section to the same location as the normal pricing section
	$( '.options_group.subscription_pricing' )
		.not(
			'.variable_subscription_pricing .options_group.subscription_pricing'
		)
		.insertBefore( $( '.options_group.pricing:first' ) );
	$( '.show_if_subscription.clear' ).insertAfter(
		$( '.options_group.subscription_pricing' )
	);

	// Move the subscription variation pricing section to a better location in the DOM on load
	if (
		$( '#variable_product_options .variable_subscription_pricing' ).length >
		0
	) {
		$.moveSubscriptionVariationFields();
	}
	// When a variation is added
	$( '#woocommerce-product-data' ).on(
		'woocommerce_variations_added woocommerce_variations_loaded',
		function () {
			$.moveSubscriptionVariationFields();
			$.showHideVariableSubscriptionMeta();
			$.showHideSyncOptions();
			$.setSubscriptionLengths();
		}
	);

	if ( $( '.options_group.pricing' ).length > 0 ) {
		$.setSalePeriod();
		$.showHideSubscriptionMeta();
		$.enableSubscriptionProductFields();
		$.showHideVariableSubscriptionMeta();
		$.setSubscriptionLengths();
		$.setTrialPeriods();
		$.showHideSyncOptions();
		$.disableEnableOneTimeShipping();
		$.showHideSubscriptionsPanels();
	}

	// Update subscription ranges when subscription period or interval is changed
	$( '#woocommerce-product-data' ).on(
		'change',
		'[name^="_subscription_period"], [name^="_subscription_period_interval"], [name^="variable_subscription_period"], [name^="variable_subscription_period_interval"]',
		function () {
			$.setSubscriptionLengths();
			$.showHideSyncOptions();
			$.setSyncOptions( $( this ) );
			$.setSalePeriod();
			$.disableEnableOneTimeShipping();
		}
	);

	$( '#woocommerce-product-data' ).on(
		'propertychange keyup input paste change',
		'[name^="_subscription_trial_length"], [name^="variable_subscription_trial_length"]',
		function () {
			$.setTrialPeriods();
		}
	);

	// Handles changes to sync date select/input for yearly subscription products.
	$( '#woocommerce-product-data' )
		.on(
			'change',
			'[name^="_subscription_payment_sync_date_day"], [name^="variable_subscription_payment_sync_date_day"]',
			function () {
				if ( 0 == $( this ).val() ) {
					$( this )
						.siblings(
							'[name^="_subscription_payment_sync_date_month"], [name^="variable_subscription_payment_sync_date_month"]'
						)
						.val( 0 );
					$( this ).prop( 'disabled', true );
				}
			}
		)
		.on(
			'change',
			'[name^="_subscription_payment_sync_date_month"], [name^="variable_subscription_payment_sync_date_month"]',
			function () {
				var $syncDayOfMonthInput = $( this ).siblings(
					'[name^="_subscription_payment_sync_date_day"], [name^="variable_subscription_payment_sync_date_day"]'
				);

				if ( 0 < $( this ).val() ) {
					$syncDayOfMonthInput
						.val( 1 )
						.attr( {
							step: '1',
							min: '1',
							max: $.daysInMonth( $( this ).val() ),
						} )
						.prop( 'disabled', false );
				} else {
					$syncDayOfMonthInput.val( 0 ).trigger( 'change' );
				}
			}
		);

	$( 'body' ).on( 'woocommerce-product-type-change', function () {
		$.showHideSubscriptionMeta();
		$.showHideVariableSubscriptionMeta();
		$.enableSubscriptionProductFields();
		$.showHideSyncOptions();
		$.showHideSubscriptionsPanels();
	} );

	// WC Core enable/disable product fields when saving attributes. We need to make sure we re-enable our fields.
	$( document.body ).on( 'woocommerce_attributes_saved', function () {
		$.enableSubscriptionProductFields();
	} );

	/**
	 * This function is called after WC Core fetches new attribute HTML and appends the elements asynchronously.
	 * It triggers relevant functions to hide/show and enable/disable fields.
	 *
	 * @see add_attribute_to_list() in assets/js/admin/meta-boxes-product.js
	 *
	 * Since there is no specific event to hook into when the async call is resolved, we rely on WC clicking
	 * the attribute metabox heading after fetching the HTML but before disabling the product fields.
	 * We take advantage of this by attaching a click event listener to the '.woocommerce_attribute.wc-metabox h3'
	 * element and waiting a short time before re-enabling the product fields.
	 */
	$( document ).on( 'click', '.woocommerce_attribute.wc-metabox h3', function() {
		setTimeout( function() {
			$.showHideSubscriptionMeta();
			$.showHideVariableSubscriptionMeta();
			$.enableSubscriptionProductFields();
		}, 100 );
	});

	$( 'input#_downloadable, input#_virtual' ).on( 'change', function () {
		$.showHideSubscriptionMeta();
		$.showHideVariableSubscriptionMeta();
	} );

	// Make sure the "Used for variations" checkbox is visible when adding attributes to a variable subscription
	$( 'body' ).on( 'woocommerce_added_attribute', function () {
		$.showHideVariableSubscriptionMeta();
	} );

	if ( $.getParameterByName( 'select_subscription' ) == 'true' ) {
		$(
			'select#product-type option[value="' +
				WCSubscriptions.productType +
				'"]'
		).attr( 'selected', 'selected' );
		$( 'select#product-type' ).trigger( 'select' ).trigger( 'change' );
	}

	// Before saving a subscription product, validate the trial period
	$( '#post' ).on( 'submit', function ( e ) {
		if ( WCSubscriptions.subscriptionLengths !== undefined ) {
			var trialLength = $( '#_subscription_trial_length' ).val(),
				selectedTrialPeriod = $( '#_subscription_trial_period' ).val();

			if (
				parseInt( trialLength ) >=
				WCSubscriptions.subscriptionLengths[ selectedTrialPeriod ]
					.length
			) {
				alert(
					WCSubscriptions.trialTooLongMessages[ selectedTrialPeriod ]
				);
				$( '#ajax-loading' ).hide();
				$( '#publish' ).removeClass( 'button-primary-disabled' );
				e.preventDefault();
			}
		}
	} );

	// Notify store manager that deleting an order via the Orders screen also deletes subscriptions associated with the orders
	$( '#posts-filter' ).on( 'submit', function () {
		if (
			$( '[name="post_type"]' ).val() == 'shop_order' &&
			( $( '[name="action"]' ).val() == 'trash' ||
				$( '[name="action2"]' ).val() == 'trash' )
		) {
			var containsSubscription = false;
			$( '[name="post[]"]:checked' ).each( function () {
				if (
					true ===
					$(
						'.contains_subscription',
						$( '#post-' + $( this ).val() )
					).data( 'contains_subscription' )
				) {
					containsSubscription = true;
				}
				return false === containsSubscription;
			} );
			if ( containsSubscription ) {
				return confirm( WCSubscriptions.bulkTrashWarning );
			}
		}
	} );

	$( '.order_actions .submitdelete' ).on( 'click', function () {
		if ( $( '[name="contains_subscription"]' ).val() == 'true' ) {
			return confirm( WCSubscriptions.trashWarning );
		}
	} );

	$( '#variable_product_options' ).on(
		'click',
		'.delete.wcs-can-not-remove-variation-msg',
		function ( e ) {
			e.preventDefault();
			e.stopPropagation();
		}
	);

	// Notify the store manager that trashing an order via the admin orders table row action also deletes the associated subscription if it exists
	$( '.row-actions .submitdelete' ).on( 'click', function () {
		var order = $( this ).closest( '.type-shop_order' ).attr( 'id' );

		if (
			true ===
			$( '.contains_subscription', $( '#' + order ) ).data(
				'contains_subscription'
			)
		) {
			return confirm( WCSubscriptions.trashWarning );
		}
	} );

	// Editing a variable product
	$( '#variable_product_options' ).on(
		'change',
		'[name^="variable_regular_price"]',
		function () {
			var matches = $( this )
				.attr( 'name' )
				.match( /\[(.*?)\]/ );

			if ( matches ) {
				var loopIndex = matches[ 1 ];
				$(
					'[name="variable_subscription_price[' + loopIndex + ']"]'
				).val( $( this ).val() );
			}
		}
	);

	// Editing a variable product
	$( '#variable_product_options' ).on(
		'change',
		'[name^="variable_subscription_price"]',
		function () {
			var matches = $( this )
				.attr( 'name' )
				.match( /\[(.*?)\]/ );

			if ( matches ) {
				var loopIndex = matches[ 1 ];
				$( '[name="variable_regular_price[' + loopIndex + ']"]' ).val(
					$( this ).val()
				);
			}
		}
	);

	// Update hidden regular price when subscription price is update on simple products
	$( '#general_product_data' ).on(
		'change',
		'[name^="_subscription_price"]',
		function () {
			$( '[name="_regular_price"]' ).val( $( this ).val() );
		}
	);

	// Notify store manager that deleting an user via the Users screen also removed them from any subscriptions.
	$( '.users-php .submitdelete' ).on( 'click', function () {
		return confirm( WCSubscriptions.deleteUserWarning );
	} );

	// WC 2.4+ variation bulk edit handling
	$( 'select.variation_actions' ).on(
		'variable_subscription_sign_up_fee_ajax_data variable_subscription_period_interval_ajax_data variable_subscription_period_ajax_data variable_subscription_trial_period_ajax_data variable_subscription_trial_length_ajax_data variable_subscription_length_ajax_data',
		function ( event, data ) {
			bulk_action = event.type.replace( /_ajax_data/g, '' );
			value = $.getVariationBulkEditValue( bulk_action );

			if ( 'variable_subscription_trial_length' == bulk_action ) {
				// After variations have their trial length bulk updated in the backend, flag the One Time Shipping field as needing to be updated
				$( '#_subscription_one_time_shipping' ).addClass(
					'wcs_ots_needs_update'
				);
			}

			if ( value != null ) {
				data.value = value;
			}
			return data;
		}
	);

	var $allowSwitching = $(
			document.getElementById(
				'woocommerce_subscriptions_allow_switching'
			)
		),
		$syncRenewals = $(
			document.getElementById( 'woocommerce_subscriptions_sync_payments' )
		);

	// We're on the Subscriptions settings page
	if ( $allowSwitching.length > 0 ) {
		var allowSwitchingEnabled = $allowSwitching.find( 'input:checked' )
				.length,
			$switchSettingsRows = $allowSwitching
				.parents( 'tr' )
				.siblings( 'tr' ),
			$prorateFirstRenewal = $(
				document.getElementById(
					'woocommerce_subscriptions_prorate_synced_payments'
				)
			),
			$syncRows = $syncRenewals.parents( 'tr' ).siblings( 'tr' ),
			$daysNoFeeRow = $(
				document.getElementById(
					'woocommerce_subscriptions_days_no_fee'
				)
			).parents( 'tr' ),
			$suspensionExtensionRow = $(
				'#woocommerce_subscriptions_recoup_suspension'
			).parents( 'tr' );

		// No animation for initial hiding when switching is disabled.
		if ( 0 === allowSwitchingEnabled ) {
			$switchSettingsRows.hide();
		}

		$allowSwitching.find( 'input' ).on( 'change', function () {
			var isEnabled = $allowSwitching.find( 'input:checked' ).length;

			if ( 0 === isEnabled ) {
				$switchSettingsRows.fadeOut();
			} else if ( 0 === allowSwitchingEnabled ) {
				// switching was previously disabled, so settings will be hidden
				$switchSettingsRows.fadeIn();
			}
			allowSwitchingEnabled = isEnabled;
		} );

		// Show/hide suspension extension setting
		$( '#woocommerce_subscriptions_max_customer_suspensions' )
			.on( 'change', function () {
				if ( $( this ).val() > 0 ) {
					$suspensionExtensionRow.show();
				} else {
					$suspensionExtensionRow.hide();
				}
			} )
			.trigger( 'change' );

		// No animation when initially hiding prorated rows.
		if ( ! $syncRenewals.is( ':checked' ) ) {
			$syncRows.hide();
		} else if ( 'recurring' !== $prorateFirstRenewal.val() ) {
			$daysNoFeeRow.hide();
		}

		// Animate showing and hiding the synchronization rows.
		$syncRenewals.on( 'change', function () {
			if ( $( this ).is( ':checked' ) ) {
				$syncRows.not( $daysNoFeeRow ).fadeIn();
				$prorateFirstRenewal.trigger( 'change' );
			} else {
				$syncRows.fadeOut();
			}
		} );

		// Watch the Prorate First Renewal field for changes.
		$prorateFirstRenewal.on( 'change', function () {
			if ( 'recurring' === $( this ).val() ) {
				$daysNoFeeRow.fadeIn();
			} else {
				$daysNoFeeRow.fadeOut();
			}
		} );
	}

	// Don't display the variation notice for variable subscription products
	$( 'body' ).on( 'woocommerce-display-product-type-alert', function (
		e,
		select_val
	) {
		if ( select_val == 'variable-subscription' ) {
			return false;
		}
	} );

	$( '.wcs_payment_method_selector' ).on( 'change', function () {
		var payment_method = $( this ).val();

		$( '.wcs_payment_method_meta_fields' ).hide();
		$( '#wcs_' + payment_method + '_fields' ).show();
	} );

	// After variations have been saved/updated in the backend, flag the One Time Shipping field as needing to be updated
	$( '#woocommerce-product-data' ).on(
		'woocommerce_variations_saved',
		function () {
			$( '#_subscription_one_time_shipping' ).addClass(
				'wcs_ots_needs_update'
			);
		}
	);

	// After variations have been loaded and if the One Time Shipping field needs updating, check if One Time Shipping is still available
	$( '#woocommerce-product-data' ).on(
		'woocommerce_variations_loaded',
		function () {
			if ( $( '.wcs_ots_needs_update' ).length ) {
				$.disableEnableOneTimeShipping();
			}
		}
	);

	// After variations have been loaded, check which ones are tied to subscriptions to prevent them from being deleted.
	$( '#woocommerce-product-data' ).on(
		'woocommerce_variations_loaded',
		function () {
			$.maybeDisableRemoveLinks();
		}
	);

	// Validate the request to remove a variation on large sites.
	$( document ).on( 'click', '.wcs_validate_variation_delete', function (
		e
	) {
		e.preventDefault();

		// Prevent WC from expanding the meta box on click.
		$( this )
			.closest( '.wc-metaboxes-wrapper' )
			.find( '.wc-metabox > .wc-metabox-content' )
			.hide();

		var variation_id = $( this ).attr( 'rel' );
		var container = $( '#woocommerce-product-data' );
		var data = {
			action: 'wcs_validate_variation_deletion',
			variation_id: variation_id,
			nonce: WCSubscriptions.nonce,
		};

		// Block the UI while we check if the variation can be deleted.
		container.block( {
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6,
			},
		} );

		$.ajax( {
			url: WCSubscriptions.ajaxUrl,
			data: data,
			type: 'POST',
			success: function ( response ) {
				container.unblock();

				remove_link = $(
					'.wcs_validate_variation_delete[rel=' + variation_id + ']'
				);

				if ( 'yes' === response.can_remove ) {
					// Restore the remove variation link and click it.
					remove_link.addClass( 'remove_variation' );
					remove_link.removeClass( 'wcs_validate_variation_delete' );
					remove_link.trigger( 'click' );
				} else {
					alert( WCSubscriptions.variationDeleteFailMessage );

					// Now that we know this variation cannot be deleted, block the remove variation link.
					msg = $(
						'.wcs-can-not-remove-variation-msg[rel=' +
							variation_id +
							']'
					);
					msg.text( remove_link.text() );
					remove_link.replaceWith( msg );
				}
			},
			error: function () {
				container.unblock();
				alert( WCSubscriptions.variationDeleteErrorMessage );
			},
		} );

		return false;
	} );

	// Triggered by $.disableEnableOneTimeShipping() after One Time shipping has been enabled or disabled for variations.
	// If the One Time Shipping field needs updating, send the ajax request to update the product setting in the backend
	$( '#_subscription_one_time_shipping' ).on(
		'subscription_one_time_shipping_updated',
		function ( event, is_synced_or_has_trial ) {
			if ( $( '.wcs_ots_needs_update' ).length ) {
				var data = {
					action: 'wcs_update_one_time_shipping',
					product_id: woocommerce_admin_meta_boxes_variations.post_id,
					one_time_shipping_enabled: ! is_synced_or_has_trial,
					one_time_shipping_selected: $(
						'#_subscription_one_time_shipping'
					).prop( 'checked' ),
					nonce: WCSubscriptions.oneTimeShippingCheckNonce,
				};

				$.ajax( {
					url: WCSubscriptions.ajaxUrl,
					data: data,
					type: 'POST',
					success: function ( response ) {
						// remove the flag requiring the one time shipping field to be updated
						$( '#_subscription_one_time_shipping' ).removeClass(
							'wcs_ots_needs_update'
						);
						$( '#_subscription_one_time_shipping' ).prop(
							'checked',
							response.one_time_shipping == 'yes' ? true : false
						);
					},
				} );
			}
		}
	);

	$( '#general_product_data, #variable_product_options' ).on(
		'change',
		'[class^="wc_input_subscription_payment_sync"], [class^="wc_input_subscription_trial_length"]',
		function () {
			$.disableEnableOneTimeShipping();
		}
	);

	/**
	 * Prevents removal of variations in use by a subscription.
	 */
	var wcs_prevent_variation_removal = {
		init: function () {
			if ( 0 === $( '#woocommerce-product-data' ).length ) {
				return;
			}

			$( 'body' ).on(
				'woocommerce-product-type-change',
				this.product_type_change
			);
			$( '#variable_product_options' ).on(
				'reload',
				this.product_type_change
			);
			$( 'select.variation_actions' ).on(
				'delete_all_no_subscriptions_ajax_data',
				this.bulk_action_data
			);
			this.product_type_change();
		},

		product_type_change: function () {
			var product_type = $( '#product-type' ).val();
			var $variation_actions = $( 'select.variation_actions' );
			var $delete_all = $variation_actions.find(
				'option[value="delete_all"], option[value="delete_all_no_subscriptions"]'
			);

			if (
				'variable-subscription' === product_type &&
				'delete_all' === $delete_all.val()
			) {
				$delete_all
					.data( 'wcs_original_wc_label', $delete_all.text() )
					.attr( 'value', 'delete_all_no_subscriptions' )
					.text( WCSubscriptions.bulkDeleteOptionLabel );
			} else if (
				'variable-subscription' !== product_type &&
				'delete_all_no_subscriptions' === $delete_all.val()
			) {
				$delete_all
					.text( $delete_all.data( 'wcs_original_wc_label' ) )
					.attr( 'value', 'delete_all' );
			}
		},

		bulk_action_data: function ( event, data ) {
			if (
				window.confirm(
					woocommerce_admin_meta_boxes_variations.i18n_delete_all_variations
				)
			) {
				if (
					window.confirm(
						woocommerce_admin_meta_boxes_variations.i18n_last_warning
					)
				) {
					data.allowed = true;

					// do_variation_action() in woocommerce/assets/js/admin/meta-boxes-product-variation.js doesn't
					// allow us to do anything after the AJAX request, so we need to listen to all AJAX requests for a
					// little while to update the quantity and refresh the variation list.
					$( document ).on(
						'ajaxComplete',
						wcs_prevent_variation_removal.update_qty_after_removal
					);
				}
			}

			return data;
		},

		update_qty_after_removal: function ( event, jqXHR, ajaxOptions ) {
			var $variations = $(
				'#variable_product_options .woocommerce_variations'
			);
			var removed;

			// Not our bulk edit request. Ignore.
			if (
				-1 ===
					ajaxOptions.data.indexOf(
						'action=woocommerce_bulk_edit_variations'
					) ||
				-1 ===
					ajaxOptions.data.indexOf(
						'bulk_action=delete_all_no_subscriptions'
					)
			) {
				return;
			}

			// Unbind so this doesn't get called every time an AJAX request is performed.
			$( document ).off(
				'ajaxComplete',
				wcs_prevent_variation_removal.update_qty_after_removal
			);

			// Update variation quantity.
			removed =
				'OK' === jqXHR.statusText
					? parseInt( jqXHR.responseText, 10 )
					: 0;
			$variations.attr(
				'data-total',
				Math.max(
					0,
					parseInt( $variations.attr( 'data-total' ), 10 ) - removed
				)
			);
			$( '#variable_product_options' ).trigger( 'reload' );
		},
	};
	wcs_prevent_variation_removal.init();

	/*
	 * Prevents changing of the product type for subscription products in use by a subscription.
	 */
	var wcs_prevent_product_type_change = {
		init: function () {
			if ( 'yes' !== WCSubscriptions.productHasSubscriptions ) {
				return;
			}

			var $select = $( 'select#product-type' );
			var $options = $select.find( 'option' );
			var $selection = $options.filter( 'option:selected' );

			if (
				'subscription' !== $selection.val() &&
				'variable-subscription' !== $selection.val()
			) {
				return;
			}

			$options.not( $selection ).prop( 'disabled', true );
			$select
				.addClass( 'tips' )
				.attr( 'data-tip', WCSubscriptions.productTypeWarning );
		},
	};
	wcs_prevent_product_type_change.init();

	/*
	 * Handles enabling and disabling PayPal Standard for Subscriptions.
	 */
	var wcs_paypal_standard_settings = {
		init: function () {
			if ( 0 === $( '#woocommerce_paypal_enabled' ).length ) {
				return;
			}

			$( '#woocommerce_paypal_enabled' ).on(
				'change',
				this.paypal_enabled_change
			);
			$( '#woocommerce_paypal_enabled_for_subscriptions' ).on(
				'change',
				this.paypal_for_subscriptions_enabled
			);
			this.paypal_enabled_change();
		},

		/**
		 * Show and hide the enable PayPal for Subscriptions checkbox when PayPal is enabled or disabled.
		 */
		paypal_enabled_change: function () {
			var $enabled_for_subscriptions_element = $(
				'#woocommerce_paypal_enabled_for_subscriptions'
			).closest( 'tr' );

			if ( $( '#woocommerce_paypal_enabled' ).is( ':checked' ) ) {
				$enabled_for_subscriptions_element.show();
			} else {
				$enabled_for_subscriptions_element.hide();
			}
		},

		/**
		 * Display a confirm dialog when PayPal for Subscriptions is enabled (checked).
		 */
		paypal_for_subscriptions_enabled: function () {
			if (
				$( this ).is( ':checked' ) &&
				! confirm( WCSubscriptions.enablePayPalWarning )
			) {
				$( this ).prop( 'checked', false );
			}
		},
	};
	wcs_paypal_standard_settings.init();

	/*
	 * Handles showing and hiding subscription related settings on the Accounts and Privacy settings page
	 */
	var wcs_accounts_and_privacy_settings = {
		init: function () {
			if (
				0 ===
				$( '#woocommerce_enable_signup_and_login_from_checkout' ).length
			) {
				return;
			}

			$( '#woocommerce_enable_signup_and_login_from_checkout' ).on(
				'change',
				this.checkout_account_creation_changed
			);
			this.checkout_account_creation_changed( 'no_animation' );
		},

		/**
		 * Hides or shows the setting to allow customers to create an account on checkout while purchasing subscriptions.
		 */
		checkout_account_creation_changed: function (
			animation_behaviour = ''
		) {
			var subscription_registration_setting = $(
				'#woocommerce_enable_signup_from_checkout_for_subscriptions'
			).closest( 'fieldset' );
			var hide_function =
				animation_behaviour === 'no_animation' ? 'hide' : 'slideUp';
			var show_function =
				animation_behaviour === 'no_animation' ? 'show' : 'slideDown';

			// This setting isn't necessary if the store already allows registration on checkout.
			if (
				$( '#woocommerce_enable_signup_and_login_from_checkout' ).is(
					':checked'
				)
			) {
				subscription_registration_setting[ hide_function ]();
			} else {
				subscription_registration_setting[ show_function ]();
			}
		},
	};
	wcs_accounts_and_privacy_settings.init();

	$( '#wpbody' ).on( 'click', '#doaction, #doaction2', function () {
		var action = $( this ).is( '#doaction' )
			? $( '#bulk-action-selector-top' ).val()
			: $( '#bulk-action-selector-bottom' ).val();

		if ( 'wcs_remove_personal_data' === action ) {
			return window.confirm(
				WCSubscriptions.i18n_remove_personal_data_notice
			);
		}
	} );

	// On the subscriptions list table empty state screen, add the is-busy class to the button when clicked.
	$( '.woo_subscriptions_empty_state__button_container a' ).on( 'click', function ( e ) {
		$( this ).addClass( 'is-busy' );
	} );
} );
