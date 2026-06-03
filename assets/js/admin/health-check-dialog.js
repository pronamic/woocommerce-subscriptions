/* global jQuery, wcsHealthCheck */
jQuery( function ( $ ) {

	let currentSubscriptionId = 0;
	let currentRunId = 0;
	// Server-authoritative remaining-candidate counts, populated from
	// envelope.badges on the tool-call AJAX response and consumed by
	// updateDisplayedCount().
	let lastRemainingCount = null;
	let lastRemainingCounts = null;
	// Reference to the row-action <a> that opened the modal. Cleared in
	// fadeOutRow() (success path: trigger row is being removed, so we
	// pre-pick a sibling to focus instead) and after restoring focus
	// from a non-fade close path.
	let $currentTrigger = null;

	// ── Open modal and load classification ──────────────────────────
	$( document ).on( 'click', '.wcs-health-check-resolve', function ( e ) {
		e.preventDefault();

		$currentTrigger = $( this );
		currentSubscriptionId = $currentTrigger.data( 'subscription-id' );

		$currentTrigger.WCBackboneModal( {
			template: 'wcs-health-check-dialog-modal',
		} );

		const $modal = $( '.wcs-health-check-dialog-modal' );
		resetModal( $modal );
		loadClassification( $modal, currentSubscriptionId );

		// Move keyboard focus into the modal. Target the dialog section
		// itself (tabindex=-1) rather than the × button so SR users
		// hear "Subscriptions Health Insights, dialog" via
		// aria-labelledby on entry — and so we don't paint a visible
		// focus ring on the close button before the user has had a
		// chance to read the explanation. Tab from here lands on the
		// close button, then on the action buttons once they load.
		$modal.find( '.wc-backbone-modal-main' ).trigger( 'focus' );

		// Trap focus inside the modal so keyboard users cannot Tab into
		// the visually-blocked background (WCAG 2.4.3). Cycles focus
		// between the first and last focusable elements on Tab/Shift+Tab.
		$modal.off( 'keydown.wcsHealthCheckTrap' ).on( 'keydown.wcsHealthCheckTrap', function ( ev ) {
			if ( ev.key !== 'Tab' ) {
				return;
			}

			const $focusable = $modal.find( 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])' ).filter( ':visible' );
			if ( ! $focusable.length ) {
				return;
			}

			const $first = $focusable.first();
			const $last  = $focusable.last();

			if ( ev.shiftKey && document.activeElement === $first[0] ) {
				ev.preventDefault();
				$last.trigger( 'focus' );
			} else if ( ! ev.shiftKey && document.activeElement === $last[0] ) {
				ev.preventDefault();
				$first.trigger( 'focus' );
			}
		} );

		return false;
	} );

	// ── Restore focus when the modal closes ─────────────────────────
	// WCBackboneModal fires `wc_backbone_modal_before_remove` for every
	// close path (×, Esc, backdrop click). The terminal-outcome paths
	// (fadeOutRow / swapRow) clear $currentTrigger first, so a trigger
	// row about to be removed or replaced isn't focused; here we only
	// restore focus when the trigger is still in the DOM (cancel /
	// dismiss paths).
	$( document.body ).on( 'wc_backbone_modal_before_remove', function () {
		if ( $currentTrigger && $currentTrigger.length && $currentTrigger.is( ':visible' ) ) {
			const $trigger = $currentTrigger;
			$currentTrigger = null;
			// Defer so focus lands after WC's modal teardown completes.
			setTimeout( function () {
				$trigger.trigger( 'focus' );
			}, 0 );
		}
	} );

	// ── Action button click ─────────────────────────────────────────
	$( document ).on( 'click', '.wcs-health-check-action-primary, .wcs-health-check-action-secondary', function ( e ) {
		e.preventDefault();
		e.stopPropagation();

		const $btn = $( this );

		// `aria-disabled` doesn't block clicks the way HTML `disabled`
		// does. Bail manually so a second activation while a tool call
		// is in flight doesn't re-fire the runner.
		if ( 'true' === $btn.attr( 'aria-disabled' ) ) {
			return;
		}

		const action = $btn.attr( 'data-action' );
		const $modal = $btn.closest( '.wcs-health-check-dialog-modal' );

		// No data-action means a cancel/close button — just dismiss
		// the modal without dispatching any remediation.
		if ( ! action ) {
			closeModal( $modal );
			return;
		}

		setButtonsLoading( $modal, true, $btn );

		$.ajax( {
			url: wcsHealthCheck.ajaxUrl,
			type: 'POST',
			timeout: 60000,
			data: {
				action: 'wcs_health_check_tool_call',
				nonce: wcsHealthCheck.toolNonce,
				subscription_id: currentSubscriptionId,
				tool_action: action,
				run_id: currentRunId,
				view: getCurrentView(),
			},
			success: function ( response ) {
				if ( ! response.success ) {
					setButtonsLoading( $modal, false );
					showError( $modal, response.data && response.data.message
						? response.data.message
						: wcsHealthCheck.i18n.unexpectedError );
					return;
				}

				routeOutcome( $modal, response.data || {} );
			},
			error: function () {
				setButtonsLoading( $modal, false );
				showError( $modal, wcsHealthCheck.i18n.unexpectedError );
			},
		} );
	} );

	/**
	 * Drive the post-dispatch UI from the envelope outcome (T10/T12).
	 *
	 *   resolved    -> close modal, fadeOutRow, success notice
	 *   transformed -> close modal, swapRow,    success notice
	 *   failed      -> close modal, swapRow,    error notice
	 *   stale       -> close modal, fadeOutRow, info notice
	 *
	 * Any unexpected outcome falls back to closing the modal so the
	 * merchant doesn't get a half-rendered modal stuck open.
	 */
	function routeOutcome( $modal, data ) {
		captureRemainingCounts( data );

		// Default to a benign close for an unexpected outcome (server bug,
		// network rewrite, etc.) so the merchant doesn't end up with a
		// half-rendered modal stuck open.
		const outcome = data.outcome || 'failed';

		switch ( outcome ) {
			case 'resolved':
			case 'stale':
				closeModal( $modal );
				fadeOutRow();
				break;
			case 'transformed':
			case 'failed':
				// Both outcomes can change the row before the response
				// returns: 'transformed' means the renewal is now in
				// progress, and 'failed' can mutate state before erroring
				// (e.g. a pending renewal order is created, then the gateway
				// charge declines). The server sends fresh row_html for
				// both, so swap it in to reflect current state rather than
				// going stale until reload. swapRow() no-ops when row_html
				// is absent.
				closeModal( $modal );
				swapRow( data.row_html );
				break;
			default:
				closeModal( $modal );
				break;
		}

		injectNotice( data.notice );
		announceOutcome( data );
	}

	/**
	 * Populate lastRemainingCount/lastRemainingCounts from the response
	 * envelope's badges field.
	 */
	function captureRemainingCounts( data ) {
		if ( ! data || ! data.badges || typeof data.badges.all !== 'number' ) {
			return;
		}
		lastRemainingCount = data.badges.all;
		lastRemainingCounts = {
			supports_auto_renewal: data.badges.supports_auto_renewal,
			missing_renewals: data.badges.missing_renewal,
		};
	}

	/**
	 * Read the current candidates-table view slug from the active tab
	 * link's parent <li> class. Falls back to 'supports_auto_renewal' which
	 * is the server-side default for view-keyed renderers.
	 */
	function getCurrentView() {
		const liClass = $( '.subsubsub a.current' ).closest( 'li' ).attr( 'class' ) || '';
		if ( liClass.indexOf( 'all' ) !== -1 ) {
			return 'all';
		}
		if ( liClass.indexOf( 'missing_renewals' ) !== -1 ) {
			return 'missing_renewals';
		}
		return 'supports_auto_renewal';
	}

	/**
	 * Inject the server-rendered notice into the WP admin notice slot
	 * via the shared wcsHealthCheck.notices helper (T11). Silent no-op
	 * when the helper isn't available or the payload is empty.
	 */
	function injectNotice( notice ) {
		if ( ! notice || ! notice.type || ! notice.html ) {
			return;
		}
		if ( window.wcsHealthCheck && window.wcsHealthCheck.notices && typeof window.wcsHealthCheck.notices.inject === 'function' ) {
			window.wcsHealthCheck.notices.inject( notice );
		}
	}

	/**
	 * Announce the outcome to screen-reader users when wp.a11y is
	 * available. Uses 'assertive' for failed so the merchant doesn't
	 * miss an error, 'polite' for success / info.
	 */
	function announceOutcome( data ) {
		if ( ! wp || ! wp.a11y || ! wp.a11y.speak || ! data.notice ) {
			return;
		}
		const politeness = 'error' === data.notice.type ? 'assertive' : 'polite';
		// Strip HTML so the screen reader reads the message, not markup.
		const spoken = $( '<div>' ).html( data.notice.html ).text();
		if ( spoken ) {
			wp.a11y.speak( spoken, politeness );
		}
	}

	/**
	 * Swap the existing <tr> for the subscription with the server-
	 * rendered HTML carried in envelope.row_html. Re-targets focus to
	 * the new row's Resolve link when present (falls back to adjacent
	 * row's Resolve link, matching fadeOutRow()'s focus behavior).
	 */
	function swapRow( html ) {
		if ( ! html ) {
			return;
		}
		const $oldRow = $( 'a.wcs-health-check-resolve' ).filter( function () {
			return $( this ).data( 'subscription-id' ) === currentSubscriptionId;
		} ).closest( 'tr' );

		if ( ! $oldRow.length ) {
			return;
		}

		const $newRow = $( html );
		$oldRow.replaceWith( $newRow );

		// Clear $currentTrigger — the original trigger element is gone.
		$currentTrigger = null;

		// Move focus to the new row's Resolve link if present, otherwise
		// to an adjacent row's Resolve link.
		let $focus = $newRow.find( 'a.wcs-health-check-resolve' );
		if ( ! $focus.length ) {
			$focus = findFocusFallback( $newRow );
		}
		if ( $focus && $focus.length ) {
			$focus.trigger( 'focus' );
		}

		updateDisplayedCount();
	}

	// ── Helpers ─────────────────────────────────────────────────────

	function resetModal( $modal ) {
		$modal.find( '.wcs-health-check-dialog-loading' ).show();
		$modal.find( '.wcs-health-check-dialog-explanation' ).hide();
		$modal.find( '.wcs-health-check-dialog-error' ).hide();
		$modal.find( '.wcs-health-check-dialog-actions' ).hide();
	}

	function loadClassification( $modal, subscriptionId ) {
		$.ajax( {
			url: wcsHealthCheck.ajaxUrl,
			type: 'GET',
			timeout: 60000,
			data: {
				action: 'wcs_health_check_suggest_remediation',
				nonce: wcsHealthCheck.nonce,
				subscription_id: subscriptionId,
				view: getCurrentView(),
			},
			success: function ( response ) {
				// Hard auth/transport errors (wp_send_json_error) still
				// surface in the modal — the merchant needs feedback that
				// the click failed, not a closed modal with no notice.
				if ( ! response.success ) {
					$modal.find( '.wcs-health-check-dialog-loading' ).hide();
					showError( $modal, response.data && response.data.message
						? response.data.message
						: wcsHealthCheck.i18n.unexpectedError );
					return;
				}

				const data = response.data || {};

				// 'ready' = classification payload available, render the
				// modal body. Any other outcome ('stale' today) is a
				// terminal envelope — close the modal + run the same
				// routeOutcome pipeline ajax_tool_call uses so the row
				// fades and the notice is injected in one place.
				if ( 'ready' === data.outcome && data.classification ) {
					currentRunId = data.classification.runId || 0;
					updateModalContent( $modal, data.classification );
					return;
				}

				routeOutcome( $modal, data );
			},
			error: function () {
				$modal.find( '.wcs-health-check-dialog-loading' ).hide();
				showError( $modal, wcsHealthCheck.i18n.unexpectedError );
			},
		} );
	}

	function updateModalContent( $modal, data ) {
		$modal.find( '.wcs-health-check-dialog-loading' ).hide();
		$modal.find( '.wcs-health-check-dialog-error' ).hide();

		// Override the modal header when the advisor payload carries a
		// case-specific title (e.g. "Process renewal" for Missing-renewals).
		// Falls back to the template default when title is absent.
		if ( data.title ) {
			$modal.find( '#wcs-health-check-dialog-title' ).text( data.title );
		}

		// Render the explanation as one <p> per paragraph (split on
		// blank lines). Keeps the dialog markup semantically correct
		// when advisors emit multi-paragraph copy (e.g. body + closing
		// question), since the surrounding CSS does not preserve
		// `\n\n` inside a single <p>.
		const $explanation = $modal.find( '.wcs-health-check-dialog-explanation' );
		$explanation.empty();
		String( data.explanation || '' ).split( /\n\n+/ ).forEach( function ( paragraph ) {
			$explanation.append( $( '<p>' ).html( linkifyParagraph( paragraph, data ) ) );
		} );
		$explanation.show();

		const $actions = $modal.find( '.wcs-health-check-dialog-actions' );
		const $primary = $actions.find( '.wcs-health-check-action-primary' );
		const $secondary = $actions.find( '.wcs-health-check-action-secondary' );

		if ( data.primaryAction && data.primaryActionLabel ) {
			$primary
				.text( data.primaryActionLabel )
				.attr( 'data-action', data.primaryAction )
				.removeAttr( 'aria-disabled' )
				.show();
		} else {
			$primary.hide();
		}

		// Secondary slot — used for either a second action constant or
		// for the Cancel button when the advisor specifies cancelLabel.
		// Cancel has no data-action so the click handler dispatches
		// closeModal() instead of an AJAX action.
		if ( data.secondaryAction && data.secondaryActionLabel ) {
			$secondary
				.text( data.secondaryActionLabel )
				.attr( 'data-action', data.secondaryAction )
				.removeAttr( 'aria-disabled' )
				.show();
		} else if ( data.cancelLabel ) {
			$secondary
				.text( data.cancelLabel )
				.removeAttr( 'data-action' )
				.removeAttr( 'aria-disabled' )
				.show();
		} else {
			$secondary.hide();
		}

		$actions.removeAttr( 'aria-busy' );
		$actions.find( '.spinner' ).remove();
		$actions.show();

		if ( wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( data.explanation, 'polite' );
		}
	}

	function closeModal( $modal ) {
		$modal.find( '.modal-close-link' ).trigger( 'click' );
	}

	/**
	 * Replace "#NNN" in a paragraph of explanation text with a link to
	 * the subscription edit screen when `subscriptionUrl` is available.
	 * Input is plain text; output is HTML-safe.
	 */
	function linkifyParagraph( paragraph, data ) {
		let text = $( '<span>' ).text( paragraph ).html();

		if ( data.subscriptionUrl && data.subscriptionId ) {
			const needle = '#' + data.subscriptionId;
			const safeUrl = $( '<a>' ).attr( 'href', data.subscriptionUrl ).prop( 'href' );
			const link   = '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + needle + '<span class="screen-reader-text"> (' + wcsHealthCheck.i18n.opensInNewTab + ')</span></a>';
			// Anchor the match with a word boundary so e.g. id=12 in an
			// explanation that mentions #12345 doesn't replace the prefix
			// and leave a dangling "345" after the link. The boundary
			// requires the trailing position to be between a word char
			// (the digit) and a non-word char (whitespace, punctuation,
			// EOL).
			const pattern = new RegExp( needle + '\\b', 'g' );
			text = text.replace( pattern, link );
		}

		return text;
	}

	function showError( $modal, message ) {
		$modal.find( '.wcs-health-check-dialog-error p' ).text( message );
		$modal.find( '.wcs-health-check-dialog-error' ).show();

		if ( wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, 'assertive' );
		}
	}

	function setButtonsLoading( $modal, loading, $clicked ) {
		const $actions = $modal.find( '.wcs-health-check-dialog-actions' );
		const $buttons = $actions.find( 'button' );

		// Use `aria-disabled` + `aria-busy` instead of HTML `disabled`
		// so the focused button stays in the tab order during the
		// in-flight request. HTML `disabled` would drop focus to <body>
		// the moment the user submits, leaving SR / keyboard users
		// without context. The action click handler bails out manually
		// when `aria-disabled` is set. Visual disabled styling is
		// applied via CSS on [aria-disabled="true"].
		if ( loading ) {
			$buttons.attr( 'aria-disabled', 'true' );
			$actions.attr( 'aria-busy', 'true' );
			// Place the spinner inside the clicked button so the loading
			// state is visually attached to the action the user just
			// took — matching the "Running ◌" pattern used by the Scan
			// Now button in the header.
			if ( $clicked && $clicked.length && ! $clicked.find( '.wcs-health-check-dialog-spinner' ).length ) {
				$clicked.append( '<span class="wcs-health-check-dialog-spinner" aria-hidden="true"></span>' );
			}
		} else {
			$buttons.removeAttr( 'aria-disabled' );
			$actions.removeAttr( 'aria-busy' );
			$buttons.find( '.wcs-health-check-dialog-spinner' ).remove();
		}
	}

	function fadeOutRow() {
		const $row = $( 'a.wcs-health-check-resolve' ).filter( function () {
			return $( this ).data( 'subscription-id' ) === currentSubscriptionId;
		} ).closest( 'tr' );

		if ( ! $row.length || $row.is( ':hidden' ) ) {
			return;
		}

		// Pick a focus target before the trigger row goes away —
		// otherwise focus drops to <body> when keyboard / SR users
		// resolve a row. Prefer an adjacent row's resolve link so the
		// user lands somewhere actionable; fall back to the search
		// input as a stable element above the table.
		const $focusTarget = findFocusFallback( $row );

		// Clear the trigger so the modal-close handler above doesn't
		// also try to focus the (about-to-be-removed) row.
		$currentTrigger = null;

		// Honour prefers-reduced-motion: skip the fade animation for
		// users who've opted out of motion. The row still disappears,
		// just instantly instead of over 400ms.
		let fadeDuration = 400;
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			fadeDuration = 0;
		}

		$row.fadeOut( fadeDuration, function () {
			$row.remove();
			if ( $focusTarget && $focusTarget.length ) {
				$focusTarget.trigger( 'focus' );
			}
			updateDisplayedCount();
		} );
	}

	function findFocusFallback( $removedRow ) {
		const $next = $removedRow.nextAll( 'tr' ).not( '.no-items' ).first().find( 'a.wcs-health-check-resolve' );
		if ( $next.length ) {
			return $next;
		}
		const $prev = $removedRow.prevAll( 'tr' ).not( '.no-items' ).first().find( 'a.wcs-health-check-resolve' );
		if ( $prev.length ) {
			return $prev;
		}
		const $search = $( '#woocommerce-subscriptions-health-check-search-input' );
		if ( $search.length ) {
			return $search;
		}
		return null;
	}

	function updateDisplayedCount() {
		// Use the server-authoritative remaining counts when available;
		// fall back to counting visible DOM rows for older responses
		// that don't include them (e.g. cached pages before the deploy).
		let remaining;
		let counts = lastRemainingCounts;
		if ( null !== lastRemainingCount ) {
			remaining = lastRemainingCount;
			lastRemainingCount = null;
			lastRemainingCounts = null;
		} else {
			const $tbody = $( '.woocommerce-subscriptions-health-check-tab .wp-list-table tbody' );
			remaining = $tbody.length ? $tbody.find( 'tr' ).not( '.no-items' ).length : 0;
			counts = null;
		}

		// Update per-signal tab counts from the server when available.
		if ( counts ) {
			$( '.subsubsub .supports_auto_renewal .count' ).text( '(' + counts.supports_auto_renewal.toLocaleString() + ')' );
			$( '.subsubsub .missing_renewals .count' ).text( '(' + counts.missing_renewals.toLocaleString() + ')' );
		}

		// Update the current view's pagination label. On signal-specific
		// tabs, use the signal count; on "All", use the total.
		const $currentLink = $( '.subsubsub a.current' );
		const currentView = $currentLink.closest( 'li' ).attr( 'class' ) || '';
		let paginationCount = remaining;
		if ( counts && currentView.indexOf( 'supports_auto_renewal' ) !== -1 ) {
			paginationCount = counts.supports_auto_renewal;
		} else if ( counts && currentView.indexOf( 'missing_renewals' ) !== -1 ) {
			paginationCount = counts.missing_renewals;
		}

		const formatted = paginationCount.toLocaleString();

		// Update the "N items" pagination label.
		$( '.woocommerce-subscriptions-health-check-tab .displaying-num' ).each( function () {
			$( this ).text( $( this ).text().replace( /\d[\d,.]*/, formatted ) );
		} );

		// Update the current view tab count (for the "All" tab, or
		// fallback when per-signal counts aren't available).
		if ( ! counts ) {
			$( '.subsubsub a.current .count' ).text( '(' + formatted + ')' );
		}

		// Update the "N items are ready for review" summary card label.
		$( '.woocommerce-subscriptions-health-check-summary-col-scope .woocommerce-subscriptions-health-check-card-secondary strong' )
			.each( function () {
				$( this ).text( $( this ).text().replace( /\d[\d,.]*/, formatted ) );
			} );

		// When the table body has no more data rows, show an empty-state row.
		const $tbody = $( '.woocommerce-subscriptions-health-check-tab .wp-list-table tbody' );
		if ( $tbody.length && $tbody.find( 'tr' ).not( '.no-items' ).length === 0 ) {
			const colCount = $tbody.closest( 'table' ).find( 'thead th, thead td' ).length || 1;
			$tbody.append(
				'<tr class="no-items"><td class="colspanchange" colspan="' + colCount + '">' +
				wcsHealthCheck.i18n.noItemsToReview +
				'</td></tr>'
			);
		}
	}
} );
