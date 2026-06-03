/**
 * Subscriptions Health Check — floating tooltip for the warning icons.
 *
 * Renders a single tooltip element appended to <body> and reuses it across
 * every `.woocommerce-subscriptions-health-check-warning` icon on the
 * Health Check Status tab. Keeping the bubble outside the candidates table
 * wrapper avoids the wrapper's `overflow-x: auto` clipping the tooltip on
 * first-row icons (and similar edges) — the wrapper inherits a non-`visible`
 * `overflow-y` per the CSS spec, so a CSS-only `::after` bubble would clip
 * any time the bubble extended past the wrapper bounds.
 *
 * Positioning is recomputed on each show: the tooltip is placed above the
 * icon, horizontally centred, and clamped to stay inside the viewport.
 * Coordinates are absolute (anchored to the document) so the tooltip stays
 * with the icon during page scroll without listening to scroll events.
 */
( function () {
	'use strict';

	const ICON_SELECTOR    = '.woocommerce-subscriptions-health-check-warning';
	const TOOLTIP_CLASS    = 'woocommerce-subscriptions-health-check-tooltip';
	const TOOLTIP_OFFSET   = 6;
	const VIEWPORT_PADDING = 8;

	let tooltip   = null;
	let activeEl  = null;

	function ensureTooltip() {
		if ( tooltip ) {
			return tooltip;
		}
		tooltip = document.createElement( 'div' );
		tooltip.className = TOOLTIP_CLASS;
		tooltip.setAttribute( 'role', 'tooltip' );
		tooltip.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( tooltip );
		return tooltip;
	}

	function show( icon ) {
		const text = icon.getAttribute( 'data-tooltip' ) || '';
		if ( '' === text ) {
			return;
		}

		const el = ensureTooltip();
		el.textContent = text;
		el.classList.add( 'is-visible' );

		// Reset the flip state before measuring so the height we read
		// matches the about-to-be-applied placement.
		el.classList.remove( 'is-below' );

		const iconRect    = icon.getBoundingClientRect();
		const tooltipRect = el.getBoundingClientRect();
		const scrollX     = window.pageXOffset || document.documentElement.scrollLeft;
		const scrollY     = window.pageYOffset || document.documentElement.scrollTop;

		// Default placement: above the icon, horizontally centred on it.
		let top  = iconRect.top + scrollY - tooltipRect.height - TOOLTIP_OFFSET;
		let left = iconRect.left + scrollX + ( iconRect.width / 2 ) - ( tooltipRect.width / 2 );

		// Edge-clamp horizontally so the bubble stays inside the viewport.
		const minLeft = scrollX + VIEWPORT_PADDING;
		const maxLeft = scrollX + document.documentElement.clientWidth - tooltipRect.width - VIEWPORT_PADDING;
		if ( left < minLeft ) {
			left = minLeft;
		}
		if ( left > maxLeft ) {
			left = maxLeft;
		}

		// Flip below the icon if the bubble would land above the
		// visible area. Mirror that on the bubble itself so the CSS
		// arrow rule swaps the triangle to the top edge.
		const minTop = scrollY + VIEWPORT_PADDING;
		if ( top < minTop ) {
			top = iconRect.bottom + scrollY + TOOLTIP_OFFSET;
			el.classList.add( 'is-below' );
		}

		// Anchor the triangle to the icon's centre, not the bubble's
		// centre — when the bubble is edge-clamped horizontally the two
		// don't line up and a centred arrow would float away from the
		// icon. Computing the offset relative to the bubble's left
		// edge keeps the arrow pointing at the icon regardless.
		const iconCenterX  = iconRect.left + scrollX + ( iconRect.width / 2 );
		const arrowOffset  = iconCenterX - left;
		el.style.setProperty( '--arrow-left', arrowOffset + 'px' );

		el.style.top  = top + 'px';
		el.style.left = left + 'px';
		activeEl      = icon;
	}

	function hide( icon ) {
		if ( ! tooltip ) {
			return;
		}
		if ( icon && icon !== activeEl ) {
			return;
		}
		tooltip.classList.remove( 'is-visible' );
		tooltip.style.top  = '';
		tooltip.style.left = '';
		activeEl           = null;
	}

	function handleEnter( event ) {
		const icon = event.target && event.target.closest ? event.target.closest( ICON_SELECTOR ) : null;
		if ( ! icon ) {
			return;
		}
		show( icon );
	}

	function handleLeave( event ) {
		const icon = event.target && event.target.closest ? event.target.closest( ICON_SELECTOR ) : null;
		if ( ! icon ) {
			return;
		}
		hide( icon );
	}

	function init() {
		document.addEventListener( 'mouseover', handleEnter );
		document.addEventListener( 'mouseout', handleLeave );
		document.addEventListener( 'focusin', handleEnter );
		document.addEventListener( 'focusout', handleLeave );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

/**
 * Subscriptions Health Check — WP admin notice injection helper.
 *
 * Used by the Resolve dialog after a terminal AJAX response to surface
 * the outcome as a stackable, dismissible WP admin notice at the top
 * of the candidates page.
 *
 * Attaches under the existing `wcsHealthCheck` global created by the
 * dialog script's `wp_localize_script()` call so both scripts share
 * the same i18n namespace.
 */
( function () {
	'use strict';

	if ( ! window.wcsHealthCheck ) {
		window.wcsHealthCheck = {};
	}
	if ( ! window.wcsHealthCheck.i18n ) {
		window.wcsHealthCheck.i18n = {};
	}

	const DISMISS_LABEL_FALLBACK = 'Dismiss this notice.';
	const TYPE_TO_CLASS          = {
		success: 'notice-success',
		error:   'notice-error',
		info:    'notice-info',
		warning: 'notice-warning'
	};

	/**
	 * Locate (or fall back to) the WP admin notice slot on the
	 * candidates page. Prefers the `.wp-header-end` separator —
	 * the standard WP "notices go here" anchor — and falls back
	 * to immediately after the first `<h1>` so the notice still
	 * lands somewhere reasonable on pages without the separator.
	 *
	 * @returns {HTMLElement|null}
	 */
	function findAnchor() {
		const wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) {
			return null;
		}
		const separator = wrap.querySelector( '.wp-header-end' );
		if ( separator ) {
			return separator;
		}
		const heading = wrap.querySelector( 'h1' );
		if ( heading ) {
			return heading;
		}
		return wrap;
	}

	/**
	 * Pick a focus target for after a notice is dismissed. We try, in
	 * order: a sibling notice still in the page (so a Tab from there
	 * lands on the next dismissible item), then the page heading
	 * (`<h1>`), which gets a temporary `tabindex="-1"` so it can
	 * accept focus without becoming part of the keyboard tab order.
	 *
	 * @param {HTMLElement} dismissedNotice The notice about to be removed.
	 * @returns {HTMLElement|null}
	 */
	function pickPostDismissFocusTarget( dismissedNotice ) {
		let sibling = dismissedNotice.nextElementSibling;
		while ( sibling ) {
			if ( sibling.classList && sibling.classList.contains( 'notice' ) ) {
				return sibling;
			}
			sibling = sibling.nextElementSibling;
		}
		sibling = dismissedNotice.previousElementSibling;
		while ( sibling ) {
			if ( sibling.classList && sibling.classList.contains( 'notice' ) ) {
				return sibling;
			}
			sibling = sibling.previousElementSibling;
		}

		const heading = document.querySelector( '.wrap > h1' );
		if ( heading ) {
			if ( ! heading.hasAttribute( 'tabindex' ) ) {
				heading.setAttribute( 'tabindex', '-1' );
			}
			return heading;
		}

		return null;
	}

	/**
	 * Render a notice DOM node using the standard WP admin notice
	 * markup so common.js's `.notice-dismiss` auto-wiring picks it
	 * up. The notice html is trusted server-rendered markup (passed
	 * through `wp_kses_post()` in AjaxController::build_notice_payload).
	 *
	 * @param {{type: string, html: string}} payload Notice payload.
	 * @returns {HTMLDivElement}
	 */
	function buildNotice( payload ) {
		const typeClass = TYPE_TO_CLASS[ payload.type ] || 'notice-info';
		const notice    = document.createElement( 'div' );
		// The trailing HC class is a styling hook only: the WC Status page is
		// a `woocommerce-embed-page`, whose tight `.notice { padding: 1px }`
		// leaves the absolutely-positioned `.notice-dismiss` button
		// un-centred. Our stylesheet restores comfortable vertical padding
		// via this class.
		notice.className = 'notice ' + typeClass + ' is-dismissible woocommerce-subscriptions-health-check-notice';

		const p = document.createElement( 'p' );
		p.innerHTML = payload.html || '';
		notice.appendChild( p );

		// Standard WP dismiss button. common.js wires the click handler
		// globally; the manual handler below is a fallback for cases
		// where common.js wired only on DOMContentLoaded and missed
		// this dynamically-added notice.
		const dismissBtn = document.createElement( 'button' );
		dismissBtn.type = 'button';
		dismissBtn.className = 'notice-dismiss';
		const srText = document.createElement( 'span' );
		srText.className = 'screen-reader-text';
		srText.textContent = window.wcsHealthCheck.i18n.dismiss || DISMISS_LABEL_FALLBACK;
		dismissBtn.appendChild( srText );

		dismissBtn.addEventListener( 'click', function () {
			// Pre-pick a focus target before removing the notice — the
			// dismiss button itself becomes detached on removeChild(),
			// which collapses focus to <body> (a non-focusable element
			// without an explicit tabindex) on most browsers. Prefer
			// another notice still on the page, then the page heading.
			const focusTarget = pickPostDismissFocusTarget( notice );

			if ( notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}

			if ( focusTarget ) {
				focusTarget.focus();
			}
		} );

		notice.appendChild( dismissBtn );

		return notice;
	}

	/**
	 * Inject a WP admin notice for a Resolve terminal outcome.
	 *
	 * Idempotent against empty input — silently no-ops on missing or
	 * malformed payloads so callers don't have to guard every code path.
	 *
	 * @param {{type: string, html: string}} payload Notice payload from the AJAX envelope.
	 */
	window.wcsHealthCheck.notices = {
		inject: function ( payload ) {
			if ( ! payload || 'string' !== typeof payload.type || 'string' !== typeof payload.html ) {
				return;
			}
			if ( '' === payload.html ) {
				return;
			}

			const anchor = findAnchor();
			if ( ! anchor || ! anchor.parentNode ) {
				return;
			}

			const notice = buildNotice( payload );
			// insertAfter pattern: anchor.nextSibling may be null when
			// the anchor is the last child, in which case insertBefore
			// appends — same end result.
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
		}
	};
}() );

/**
 * Subscriptions Health Check — in-flight scan status poll.
 *
 * Replaces the legacy server-rendered 8 s full-page reload (which flashed the
 * whole tab on every tick) with a lightweight background poll. While a scan is
 * in flight the StatusTab wrapper carries `data-wcs-hc-scan-inflight`; this
 * module reads that hook on init and, only when present, polls the read-only
 * `wcs_health_check_scan_status` endpoint every POLL_INTERVAL_MS, updating the
 * inline "N of M subscriptions scanned" count in place and the visually-hidden
 * `role="status"` live region so screen readers hear progress.
 *
 * Terminal handling: when the endpoint reports `in_flight === false` the scan
 * has completed / cancelled / failed, so the page reloads exactly once to render
 * the terminal LAST SCAN card / tripped notice / candidates list. As a
 * self-healing safety net (network blip, nonce expired after a long scan, 5xx)
 * the page also reloads after MAX_FAILURES consecutive failed requests.
 */
( function () {
	'use strict';

	const INFLIGHT_SELECTOR =
		'.woocommerce-subscriptions-health-check-tab[data-wcs-hc-scan-inflight]';
	const LABEL_SELECTOR =
		'.woocommerce-subscriptions-health-check-progress-label';
	const LIVE_SELECTOR =
		'.woocommerce-subscriptions-health-check-progress-live';
	const POLL_INTERVAL_MS = 5000;
	const MAX_FAILURES     = 2;

	let timerId       = null;
	let failureCount  = 0;
	let reloadStarted = false;
	let stopped       = false;
	let lastAnnounced = null;

	/**
	 * Reload the page exactly once. Guards against a terminal response and a
	 * failure-threshold reload racing into a double navigation.
	 */
	function reloadOnce() {
		if ( reloadStarted ) {
			return;
		}
		reloadStarted = true;
		stop();
		window.location.reload();
	}

	/**
	 * Clear any pending poll and mark the loop stopped so a late-resolving
	 * fetch can't reschedule after pagehide.
	 */
	function stop() {
		stopped = true;
		if ( null !== timerId ) {
			window.clearTimeout( timerId );
			timerId = null;
		}
	}

	/**
	 * Schedule the next poll tick, never stacking more than one pending timer.
	 */
	function scheduleNext() {
		if ( stopped || reloadStarted ) {
			return;
		}
		if ( null !== timerId ) {
			window.clearTimeout( timerId );
		}
		timerId = window.setTimeout( poll, POLL_INTERVAL_MS );
	}

	/**
	 * Apply an in-flight progress reading to the inline count + live region.
	 *
	 * @param {{progress_html: string, progress_text: string}} data Poll response payload.
	 */
	function applyProgress( data ) {
		const label = document.querySelector( LABEL_SELECTOR );
		if ( label && 'string' === typeof data.progress_html ) {
			label.innerHTML = data.progress_html;
		}
		// Only touch the live region when the announced text actually changes.
		// The poll fires every 5s but the scanned count advances ~every 30s, so
		// most polls return an identical reading; re-writing textContent each
		// time re-triggers the aria-live announcement on NVDA/JAWS (WCAG 4.1.3
		// status-message spam). The visible label above can re-render freely.
		const live = document.querySelector( LIVE_SELECTOR );
		if (
			live &&
			'string' === typeof data.progress_text &&
			data.progress_text !== lastAnnounced
		) {
			live.textContent = data.progress_text;
			lastAnnounced    = data.progress_text;
		}
	}

	/**
	 * Treat a failed request as a transient blip until MAX_FAILURES in a row,
	 * then fall back to a full reload so an expired nonce / dropped connection
	 * self-heals (preserving the legacy reload as a safety net).
	 */
	function handleFailure() {
		failureCount += 1;
		if ( failureCount >= MAX_FAILURES ) {
			reloadOnce();
			return;
		}
		scheduleNext();
	}

	/**
	 * Poll the scan-status endpoint and route on the result: update in place
	 * while in flight, reload once on a terminal state, count failures otherwise.
	 */
	function poll() {
		if ( stopped || reloadStarted ) {
			return;
		}

		const cfg = window.wcsHealthCheck || {};
		if ( ! cfg.ajaxUrl || ! cfg.statusNonce ) {
			return;
		}

		const params = new URLSearchParams();
		params.set( 'action', 'wcs_health_check_scan_status' );
		params.set( 'nonce', cfg.statusNonce );

		fetch( cfg.ajaxUrl + '?' + params.toString(), {
			method: 'GET',
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Bad response status ' + response.status );
				}
				return response.json();
			} )
			.then( function ( json ) {
				if ( stopped || reloadStarted ) {
					return;
				}
				if ( ! json || true !== json.success || ! json.data ) {
					handleFailure();
					return;
				}

				failureCount = 0;

				if ( false === json.data.in_flight ) {
					// Terminal state — render the completed/cancelled/failed
					// card via a single reload.
					reloadOnce();
					return;
				}

				applyProgress( json.data );
				scheduleNext();
			} )
			.catch( function () {
				if ( stopped || reloadStarted ) {
					return;
				}
				handleFailure();
			} );
	}

	function init() {
		// Idle: no in-flight scan on the page → never poll.
		if ( ! document.querySelector( INFLIGHT_SELECTOR ) ) {
			return;
		}

		// Stop polling when the page is being unloaded / bfcache-stashed so a
		// late fetch can't fire a reload on a backgrounded tab.
		window.addEventListener( 'pagehide', stop );

		scheduleNext();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
