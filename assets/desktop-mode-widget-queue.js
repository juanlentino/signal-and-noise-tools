/**
 * Signal & Noise Tools — desktop-mode "SN Queue" widget (15.8.0).
 *
 * "Is the queue fed, and what goes out next." The next scheduled note as the
 * headline with its site-timezone time, the depth of the schedule and the
 * date it runs to, three more upcoming, and the last three published. Core's
 * Activity box frames the same rows as activity; this site lives on a
 * scheduled queue, so the depth line is the reading.
 *
 * Data: the signal-noise/content-queue ability (readonly, GET run-path). Every
 * label is computed server-side in the site timezone; this file paints
 * strings and never does date arithmetic.
 *
 * Refresh: every 60s, plus on window focus, so a note scheduled in the editor
 * shows up when the desktop comes back into view.
 *
 * Defensive by construction: every read off the payload is guarded (a
 * missing array is an empty list, a missing title is "(untitled)", a missing
 * link is plain text), a failed fetch paints a message instead of a blank
 * card, and every async callback is gated on `torn` so a late response can
 * never repaint a container the user has already removed.
 *
 * Pattern matches assets/desktop-mode-widget-rss.js.
 */
( function() {
	'use strict';

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
			style: 'padding:14px 16px;font-size:13px;opacity:.6;',
			text:  'Loading the queue…',
		} ) );
	}

	function renderError( container, message ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'padding:14px 16px;font-size:12px;color:#ff9d94;',
			text:  'Queue read failed: ' + ( message || 'unknown' ),
		} ) );
	}

	// A row: title (a link when the payload gave one) + a muted label.
	function row( item, muted ) {
		var line = el( 'div', {
			style: 'display:flex;justify-content:space-between;gap:12px;align-items:baseline;font-size:12px;line-height:1.4;' + ( muted ? 'opacity:.75;' : '' ),
		} );
		var title = item && item.edit_url
			? el( 'a', { href: item.edit_url, text: titleOf( item ), style: 'color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;' } )
			: el( 'span', { text: titleOf( item ), style: 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;' } );
		line.appendChild( title );
		line.appendChild( el( 'span', {
			style: 'flex:none;font-variant-numeric:tabular-nums;opacity:.6;font-size:11px;',
			text:  item && typeof item.when === 'string' ? item.when : '',
		} ) );
		return line;
	}

	// Sentence case at 11px/.55, the house label idiom (Top pages, Purposes);
	// 15.8.0 shipped these uppercase and letter-spaced, the one card that did.
	function heading( text ) {
		return el( 'p', {
			style: 'margin:12px 0 2px;font-size:11px;opacity:.55;',
			text:  text,
		} );
	}

	function renderCard( container, data ) {
		clearChildren( container );
		data = data && typeof data === 'object' ? data : {};

		var wrap  = el( 'div', { style: 'padding:14px 16px;color:inherit;' } );
		var next  = asList( data.next );
		var done  = asList( data.published );
		var total = Number( data.scheduled_total ) || 0;

		// Headline: the next note, or the honest empty state.
		if ( next.length ) {
			var first = next[0];
			wrap.appendChild( el( 'p', { style: 'margin:0;font-size:11px;opacity:.5;', text: 'Next up' } ) );
			var head = first && first.edit_url
				? el( 'a', { href: first.edit_url, text: titleOf( first ), style: 'display:block;margin:2px 0 0;font-size:14px;font-weight:600;line-height:1.3;color:inherit;text-decoration:none;' } )
				: el( 'p', { text: titleOf( first ), style: 'margin:2px 0 0;font-size:14px;font-weight:600;line-height:1.3;' } );
			wrap.appendChild( head );
			wrap.appendChild( el( 'p', {
				style: 'margin:2px 0 0;font-size:12px;font-variant-numeric:tabular-nums;opacity:.75;',
				text:  first && typeof first.when === 'string' ? first.when : '',
			} ) );
		} else {
			wrap.appendChild( el( 'p', { style: 'margin:0;font-size:14px;font-weight:600;', text: 'Nothing scheduled' } ) );
		}

		// The reading: depth and horizon.
		var runsTo = data.runs_to && typeof data.runs_to.label === 'string' ? data.runs_to.label : '';
		wrap.appendChild( el( 'p', {
			style: 'margin:8px 0 0;font-size:11px;opacity:.6;font-variant-numeric:tabular-nums;',
			text:  total
				? total + ' scheduled' + ( runsTo ? ' · runs to ' + runsTo : '' )
				: 'The queue is empty',
		} ) );

		if ( next.length > 1 ) {
			wrap.appendChild( heading( 'Then' ) );
			next.slice( 1 ).forEach( function( item ) { wrap.appendChild( row( item, false ) ); } );
		}

		if ( done.length ) {
			wrap.appendChild( heading( 'Just published' ) );
			done.forEach( function( item ) { wrap.appendChild( row( item, true ) ); } );
		}

		// 15.8.2: the leaf where the queue lives (Connections › Scheduled folds
		// native future posts with the fragment queue).
		var scheduledUrl = ( window.snDesktopData && window.snDesktopData.pages && window.snDesktopData.pages.scheduled ) || '';
		if ( scheduledUrl ) {
			wrap.appendChild( el( 'a', {
				href:  scheduledUrl,
				text:  'Open Scheduled →',
				style: 'display:inline-flex;align-items:center;min-height:24px;margin-top:10px;font-size:11px;color:var(--os-window-link-accent, #4a9eff);text-decoration:none;',
			} ) );
		}

		container.appendChild( wrap );
	}

	function mount( container ) {
		if ( ! container ) { return function() {}; }

		var torn = false;
		renderLoading( container );

		function refresh() {
			if ( torn ) { return; }
			if ( typeof window.sntAbilityRun !== 'function' ) {
				renderError( container, 'sntAbilityRun unavailable' );
				return;
			}
			var run;
			try {
				run = window.sntAbilityRun( 'content-queue', undefined, { silent: true } );
			} catch ( e ) {
				renderError( container, e && e.message ? e.message : 'unknown' );
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
						renderCard( container, data );
					} else {
						renderError( container, 'empty response' );
					}
				} )
				.catch( function( err ) {
					if ( torn ) { return; }
					renderError( container, err && err.message ? err.message : 'unknown' );
				} );
		}

		refresh();
		var intervalId = window.setInterval( refresh, REFRESH_MS );
		window.addEventListener( 'focus', refresh );

		return function teardown() {
			torn = true;
			window.clearInterval( intervalId );
			window.removeEventListener( 'focus', refresh );
			container.textContent = '';
		};
	}

	window.desktopModeWidgets['sn-queue'] = mount;

} )();
