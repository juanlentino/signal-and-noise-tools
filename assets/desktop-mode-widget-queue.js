/**
 * Signal & Noise Tools — desktop-mode "SN Queue" widget (15.8.0).
 *
 * "Is the queue fed, and what goes out next." The next scheduled note as the
 * headline with its site-timezone time, the depth of the schedule and the
 * date it runs to beside the draft count, the first gap in the schedule when
 * there is one, three more upcoming, and the last three published. Core's
 * Activity box frames the same rows as activity; this site lives on a
 * scheduled queue, so the depth line is the reading.
 *
 * Data: the signal-noise/content-queue ability (readonly, GET run-path). Every
 * label and every day is computed server-side in the site timezone; this
 * file paints strings. The calendar (desktop-mode-queue-calendar.js,
 * 2026-10-12) counts days forward from the server's week_start, and only that.
 *
 * Refresh: every 60s while the tab is visible, and at once on reveal when
 * the last run is older than that (Recipe 2, #1603), so a note scheduled in
 * the editor shows up when the desktop comes back into view.
 *
 * Defensive by construction: every read off the payload is guarded (a
 * missing array is an empty list, a missing title is "(untitled)", a missing
 * link is plain text), a failed fetch keeps the last good card with the time
 * it was read (or, before any, paints a sentence instead of a blank card),
 * and every async callback is gated on `torn` so a late response can
 * never repaint a container the user has already removed.
 *
 * Pattern matches assets/desktop-mode-widget-rss.js.
 */
( function() {
	'use strict';

	// 2026-10-12 audit: a failure says what happened in words, never a
	// function name. snt-ability-run.js is the script that reads the site.
	var NO_RUNNER = 'the S&N status reader did not load; reload the desktop';

	if ( typeof window === 'undefined' ) {
		return;
	}

	// v10.43.0 — OpenStation rename compat: self-sufficient alias, merged not
	// clobbered. See assets/desktop-mode-widget-rss.js for the reasoning.
	var __osWidgets = window.openStationWidgets || window.desktopModeWidgets || {};
	if ( window.desktopModeWidgets && window.desktopModeWidgets !== __osWidgets ) {
		for ( var __osKey in window.desktopModeWidgets ) {
			if ( ! ( __osKey in __osWidgets ) ) { __osWidgets[ __osKey ] = window.desktopModeWidgets[ __osKey ]; }
		}
	}
	window.desktopModeWidgets = window.openStationWidgets = __osWidgets;

	var REFRESH_MS = 60 * 1000;

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href ) { node.href = opts.href; }
		return node;
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function asList( v ) {
		return Array.isArray( v ) ? v : [];
	}

	function titleOf( row ) {
		var t = row && typeof row.title === 'string' ? row.title.trim() : '';
		return t || '(untitled)';
	}

	function renderLoading( container ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'padding:14px 16px;font-size:13px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));',
			text:  'Loading the queue…',
		} ) );
	}

	function renderError( container, message ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'padding:14px 16px;font-size:12px;color:#ff9d94;',
			text:  'Couldn\u2019t read the queue: ' + message + '.',
		} ) );
	}

	/**
	 * Why a read failed, in plain words, from the failure itself (review on
	 * #2023: the card guessed "the site did not answer" for every failure).
	 * The site's own message rides through with its HTTP status; only a
	 * request that never got an answer says so.
	 */
	function why( err ) {
		var msg    = err && typeof err.message === 'string' ? err.message.trim() : '';
		var status = err && err.data ? Number( err.data.status ) || 0 : 0;
		if ( ! msg || /failed to fetch|networkerror|load failed/i.test( msg ) ) {
			return status ? 'the site answered HTTP ' + status : 'the site did not answer';
		}
		return status ? msg + ' (HTTP ' + status + ')' : msg;
	}

	/** "14:05" for a time in ms, the clock the cards' "Read at" lines use. */
	function clock( ms ) {
		return new Date( ms ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
	}

	// A row: title (a link when the payload gave one) + a muted label.
	function row( item, muted ) {
		var line = el( 'div', {
			style: 'display:flex;justify-content:space-between;gap:12px;align-items:baseline;font-size:12px;line-height:1.4;' + ( muted ? 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.75));' : '' ),
		} );
		var title = item && item.edit_url
			? el( 'a', { href: item.edit_url, text: titleOf( item ), style: 'color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;' } )
			: el( 'span', { text: titleOf( item ), style: 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;' } );
		line.appendChild( title );
		line.appendChild( el( 'span', {
			style: 'flex:none;font-variant-numeric:tabular-nums;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));font-size:11px;',
			text:  item && typeof item.when === 'string' ? item.when : '',
		} ) );
		return line;
	}

	// Sentence case at 11px/.55, the house label idiom (Top pages, Purposes);
	// 15.8.0 shipped these uppercase and letter-spaced, the one card that did.
	function heading( text ) {
		return el( 'p', {
			style: 'margin:12px 0 2px;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));',
			text:  text,
		} );
	}

	function renderCard( container, data, state ) {
		clearChildren( container );
		data  = data && typeof data === 'object' ? data : {};
		state = state || {};

		var wrap  = el( 'div', { style: 'padding:14px 16px;color:inherit;' } );
		var next  = asList( data.next );
		var done  = asList( data.published );
		// A payload without the count paints it as not read, never as 0.
		var total = typeof data.scheduled_total === 'number' ? data.scheduled_total : null;

		// 2026-10-12, the owner's redesign: the coming weeks as a calendar,
		// the selected day's notes in a box under it (the next-up day first,
		// so the old headline lives there). Without the calendar script or
		// the payload's week_start, the headline and the Then list as before.
		var cal = window.sntQueueCalendar && typeof window.sntQueueCalendar.render === 'function'
			? window.sntQueueCalendar.render( data, { selected: state.day, onSelect: function( day ) { state.day = day; } } )
			: null;
		if ( cal ) {
			wrap.appendChild( cal.node );
			// A "Then" note past the grid stays reachable by name.
			var later = next.slice( 1 ).filter( function( item ) { return item && typeof item.day === 'string' && item.day > cal.lastDay; } );
			if ( later.length ) {
				wrap.appendChild( heading( 'Later' ) );
				later.forEach( function( item ) { wrap.appendChild( row( item, false ) ); } );
			}
		} else if ( next.length ) {
			var first = next[0];
			wrap.appendChild( el( 'p', { style: 'margin:0;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.5));', text: 'Next up' } ) );
			var head = first && first.edit_url
				? el( 'a', { href: first.edit_url, text: titleOf( first ), style: 'display:block;margin:2px 0 0;font-size:14px;font-weight:600;line-height:1.3;color:inherit;text-decoration:none;' } )
				: el( 'p', { text: titleOf( first ), style: 'margin:2px 0 0;font-size:14px;font-weight:600;line-height:1.3;' } );
			wrap.appendChild( head );
			wrap.appendChild( el( 'p', {
				style: 'margin:2px 0 0;font-size:12px;font-variant-numeric:tabular-nums;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.75));',
				text:  first && typeof first.when === 'string' ? first.when : '',
			} ) );
		} else {
			wrap.appendChild( el( 'p', { style: 'margin:0;font-size:14px;font-weight:600;', text: 'Nothing scheduled' } ) );
		}

		// The reading: depth and horizon beside the drafts waiting behind it,
		// one row under the card kit's hairline (SN Traffic's Countries and
		// Sources pairing). Drafts is left out, never painted as 0, when the
		// payload could not count it (or predates 23.8.0).
		var runsTo = data.runs_to && typeof data.runs_to.label === 'string' ? data.runs_to.label : '';
		var cell = 'margin:0;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));font-variant-numeric:tabular-nums;';
		var depth = el( 'p', { style: cell, text: null === total ? 'Scheduled count not read' : total + ' scheduled' + ( total && runsTo ? ' · to ' + runsTo : '' ) } );
		var drafts = typeof data.drafts === 'number' ? el( 'p', { style: cell, text: data.drafts + ( 1 === data.drafts ? ' draft' : ' drafts' ) } ) : null;
		var kit = window.sntCardKit;
		if ( drafts && kit && typeof kit.pair === 'function' ) {
			wrap.appendChild( kit.pair( depth, drafts ) );
		} else {
			depth.style.marginTop = '8px';
			wrap.appendChild( depth );
			if ( drafts ) { wrap.appendChild( drafts ); }
		}

		// 23.8.0: the first hole in the schedule, by the publishing-cadence
		// flag's own threshold (server-side). Amber, and only when there is
		// one; it links to where the queue is fixed.
		var gap = data.gap && typeof data.gap.from_label === 'string' ? data.gap : null;
		var scheduledUrl = ( window.snDesktopData && window.snDesktopData.pages && window.snDesktopData.pages.scheduled ) || '';
		if ( gap ) {
			var gapText = 'Gap: no note ' + gap.from_label + ( gap.to_label && gap.to_label !== gap.from_label ? ' to ' + gap.to_label : '' );
			var gapStyle = 'display:block;margin:4px 0 0;font-size:11px;color:#d29922;font-variant-numeric:tabular-nums;text-decoration:none;';
			var gapLine = el( 'div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:24px;' } );
			var gapLink = scheduledUrl ? el( 'a', { href: scheduledUrl, text: gapText, style: gapStyle + 'display:flex;align-items:center;min-height:24px;margin-top:0;' } ) : el( 'p', { text: gapText, style: gapStyle } );
			gapLink.setAttribute( 'data-sn-gap', '' );
			gapLine.appendChild( gapLink );
			// 2026-10-10: a gap can be on purpose (the holidays). "Planned" records
			// this one and the line goes; a different gap still shows.
			if ( window.wp && window.wp.apiFetch && gap.from && gap.to ) {
				var planned = el( 'button', { text: 'Planned', style: 'flex:none;min-height:24px;padding:0 8px;font-size:11px;background:transparent;color:inherit;border:1px solid var(--os-ui-color-border, rgba(255,255,255,.25));border-radius:4px;cursor:pointer;' } );
				planned.setAttribute( 'type', 'button' );
				planned.setAttribute( 'aria-label', 'Planned: mark the gap ' + gapText.replace( /^Gap: /, '' ) + ' as intended' );
				planned.addEventListener( 'click', function() {
					planned.disabled = true;
					window.wp.apiFetch( { path: '/signal-noise/v1/desktop/queue-gap-planned', method: 'POST', data: { from: gap.from, to: gap.to } } ).then( function() {
						// The focused button leaves with its line: hand focus to the
						// card's next link (Open Scheduled), never to the page body.
						var next = wrap.querySelector ? wrap.querySelector( 'a[href]:not([data-sn-gap])' ) : null;
						if ( gapLine.parentNode ) { gapLine.parentNode.removeChild( gapLine ); }
						if ( next && next.focus ) { next.focus(); }
					} ).catch( function() {
						planned.disabled = false;
						planned.textContent = 'Not saved, retry';
					} );
				} );
				gapLine.appendChild( planned );
			}
			wrap.appendChild( gapLine );
		}

		// The drafts by title, each a link to its editor; the count is the
		// pair's, so a longer list says how many it left out.
		var draftList = asList( data.draft_list );
		if ( draftList.length ) {
			wrap.appendChild( heading( 'Drafts' ) );
			draftList.forEach( function( item ) { wrap.appendChild( row( item, false ) ); } );
			if ( typeof data.drafts === 'number' && data.drafts > draftList.length ) {
				wrap.appendChild( el( 'p', { style: 'margin:0;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));', text: '+' + ( data.drafts - draftList.length ) + ' more' } ) );
			}
		}

		if ( ! cal && next.length > 1 ) {
			wrap.appendChild( heading( 'Then' ) );
			next.slice( 1 ).forEach( function( item ) { wrap.appendChild( row( item, false ) ); } );
		}

		if ( done.length ) {
			wrap.appendChild( heading( 'Just published' ) );
			done.forEach( function( item ) { wrap.appendChild( row( item, true ) ); } );
		}

		// 15.8.2: the leaf where the queue lives (Connections › Scheduled folds
		// native future posts with the fragment queue).
		if ( scheduledUrl ) {
			var open = el( 'a', {
				href:  scheduledUrl,
				text:  'Open Scheduled ',
				style: 'display:inline-flex;align-items:center;min-height:24px;margin-top:10px;font-size:11px;color:var(--snt-link, var(--os-ui-accent-strong, var(--os-ui-color-accent, #4a9eff)));text-decoration:none;',
			} );
			// The arrow is decoration, as on every other card: not read aloud.
			var arrow = el( 'span', { text: '→' } );
			arrow.setAttribute( 'aria-hidden', 'true' );
			open.appendChild( arrow );
			wrap.appendChild( open );
		}

		container.appendChild( wrap );
	}

	function mount( container ) {
		if ( ! container ) { return function() {}; }

		var torn = false;
		// The last good payload and when it was read: a failed poll keeps the
		// card and says how old it is, as the other cards do (2026-10-12 audit).
		var lastData = null;
		var lastReadMs = 0;
		var lastJson = '';
		// The selected calendar day, kept across the 60s repaints so a tap
		// is not reset by the next poll.
		var state = {};
		renderLoading( container );

		function fail( message ) {
			if ( ! lastData ) {
				renderError( container, message );
				return;
			}
			renderCard( container, lastData, state );
			lastJson = '';
			container.appendChild( el( 'p', {
				style: 'margin:0;padding:0 16px 12px;font-size:11px;color:#d29922;',
				text:  'Couldn\u2019t read the queue: ' + message + '; showing the ' + clock( lastReadMs ) + ' reading.',
			} ) );
		}

		function refresh() {
			if ( torn ) { return; }
			if ( typeof window.sntAbilityRun !== 'function' ) {
				fail( NO_RUNNER );
				return;
			}
			var run;
			try {
				run = window.sntAbilityRun( 'content-queue', undefined, { silent: true } );
			} catch ( e ) {
				fail( why( e ) );
				return;
			}
			Promise.resolve( run )
				.then( function( res ) {
					if ( torn ) { return; }
					// 15.8.1: the run-path returns the ability's output AS IS;
					// only abilities that wrap themselves (get-rss-stats) come
					// back as { ok, data }. 15.8.0 read res.data and painted
					// "empty response" over a perfect payload. Accept both.
					var data = res && res.data && typeof res.data === 'object' ? res.data : res;
					if ( data && typeof data === 'object' && ( Array.isArray( data.next ) || Array.isArray( data.published ) ) ) {
						lastData   = data;
						lastReadMs = Date.now();
						// The same reading is not repainted: a keyboard user's
						// place in the calendar survives every quiet poll.
						var json = JSON.stringify( data );
						if ( json === lastJson ) { return; }
						lastJson = json;
						var active = document.activeElement;
						var hadFocus = !! ( active && container.contains && container.contains( active ) && 'gridcell' === active.getAttribute( 'role' ) );
						renderCard( container, data, state );
						if ( hadFocus && container.querySelector ) {
							var back = container.querySelector( '[role="gridcell"][tabindex="0"]' );
							if ( back && back.focus ) { back.focus(); }
						}
					} else {
						fail( 'the site sent no queue' );
					}
				} )
				.catch( function( err ) {
					if ( torn ) { return; }
					fail( why( err ) );
				} );
		}

		refresh();
		// Recipe 2 (OpenStation docs/examples/register-widget.md, #1603): stop
		// the interval while the tab is hidden, restart on reveal, and catch
		// up at once only when the data went stale, so a quick tab flip costs
		// no call.
		var intervalId = null;
		var lastRunMs = Date.now();
		function poll() {
			lastRunMs = Date.now();
			refresh();
		}
		// Focus-aware (assets/snt-poll-cadence.js): the interval is a tick,
		// and a tick calls the ability only once the wait for the window's
		// state has passed: the full rate focused, IDLE_MS when only visible.
		// The second of slack absorbs timer drift so a 5-minute wait is not
		// read as 4:59 and pushed a whole tick later.
		function tick() {
			var wait = window.sntPollCadence ? window.sntPollCadence.wait( REFRESH_MS ) : REFRESH_MS;
			if ( Date.now() - lastRunMs >= wait - 1000 ) { poll(); }
		}
		function startPolling() {
			if ( intervalId === null ) { intervalId = window.setInterval( tick, REFRESH_MS ); }
		}
		function stopPolling() {
			if ( intervalId !== null ) { window.clearInterval( intervalId ); intervalId = null; }
		}
		function onVisibilityChange() {
			if ( document.hidden ) { stopPolling(); return; }
			if ( Date.now() - lastRunMs >= REFRESH_MS ) { poll(); }
			startPolling();
		}
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		// Back in focus, stale data catches up at once, as on reveal.
		var unwatchFocus = window.sntPollCadence ? window.sntPollCadence.onFocusChange( onVisibilityChange ) : function() {};
		// A schedule, publish or edit anywhere moves the pulse's content stamp:
		// read now rather than at the next tick (assets/snt-pulse.js).
		var unwatchPulse = window.sntPulse ? window.sntPulse.on( 'content', poll ) : function() {};
		if ( ! document.hidden ) { startPolling(); }

		return function teardown() {
			torn = true;
			stopPolling();
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
			unwatchFocus();
			unwatchPulse();
			container.textContent = '';
		};
	}

	window.desktopModeWidgets['sn-queue'] = mount;

} )();
