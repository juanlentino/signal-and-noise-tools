/**
 * Signal & Noise Tools — desktop "SN Reading" widget.
 *
 * A group painter: the server hands a list of groups, each a list of rows
 * (label, value) or an `empty` sentence, and this paints them the way the
 * tiles beside it paint theirs. It knows no metric; what a row means is
 * decided in inc/desktop-mode-reading.php. (SN Audience, its other tile, was
 * folded into SN Traffic, which paints the same group shape itself.)
 *
 * MOUNT CONTRACT: the shell looks the mount up at
 * `window.desktopModeWidgets[ id ]`, a plain global (see
 * desktop-mode-widget-views.js). mount( container, ctx ) → teardown.
 *
 * Data: GET signal-noise/v1/desktop/reading, fetched on render.
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	// OpenStation rename compat, self-sufficient (see desktop-mode-widget-views.js).
	var __osWidgets = window.openStationWidgets || window.desktopModeWidgets || {};
	if ( window.desktopModeWidgets && window.desktopModeWidgets !== __osWidgets ) {
		for ( var __osKey in window.desktopModeWidgets ) {
			if ( ! ( __osKey in __osWidgets ) ) { __osWidgets[ __osKey ] = window.desktopModeWidgets[ __osKey ]; }
		}
	}
	window.desktopModeWidgets = window.openStationWidgets = __osWidgets;

	var TONE   = { up: '#3fb950', down: '#ff9d94' }; // a row's change when the server calls it meaningful; the down red is the cards' text red (#ff9d94), legible on the dark card.
	var SUBTLE = 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));';

	function el( tag, style, text ) {
		var node = document.createElement( tag );
		if ( style ) { node.setAttribute( 'style', style ); }
		if ( text != null ) { node.textContent = text; }
		return node;
	}

	function paint( body, payload ) {
		while ( body.firstChild ) { body.removeChild( body.firstChild ); }
		var win  = payload && payload.window;
		var hero = payload && payload.hero;
		var span = win && win.days ? 'last ' + win.days + ' days' : '';
		if ( hero && hero.value ) {
			// The one figure the tile opens with, the way SN Site Views opens.
			body.appendChild( el( 'div', 'font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.1;', hero.value ) );
			body.appendChild( el( 'div', 'font-size:11px;' + SUBTLE, hero.label + ( span ? ' · ' + span : '' ) ) );
			if ( hero.change ) {
				body.appendChild( el( 'div', 'font-size:11px;margin-top:2px;' + ( TONE[ hero.tone ] ? 'color:' + TONE[ hero.tone ] + ';' : SUBTLE ), hero.change ) );
			}
			body.appendChild( el( 'div', 'height:6px;' ) );
		} else if ( span ) {
			body.appendChild( el( 'div', 'font-size:11px;margin-bottom:4px;' + SUBTLE, 'Last ' + win.days + ' days' ) );
		}
		( ( payload && payload.groups ) || [] ).forEach( function( group, i ) {
			var head = el( 'div', 'font-size:11px;margin:' + ( i ? '10px' : '2px' ) + ' 0 2px;padding-top:' + ( i ? '8px' : '0' ) + ';' + ( i ? 'border-top:1px solid rgba(128,128,128,.25);' : '' ) + SUBTLE, group.title );
			head.setAttribute( 'role', 'heading' );
			head.setAttribute( 'aria-level', '3' );
			body.appendChild( head );
			if ( ! group.rows || ! group.rows.length ) {
				body.appendChild( el( 'div', 'font-size:11px;padding:2px 0;' + SUBTLE, group.empty || 'Nothing to show.' ) );
				return;
			}
			var list = el( 'div' );
			list.setAttribute( 'role', 'list' );
			group.rows.forEach( function( r ) {
				var row = el( 'div', 'display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;column-gap:8px;padding:2px 0;font-size:11px;' );
				row.setAttribute( 'role', 'listitem' );
				row.appendChild( el( 'span', 'white-space:normal;overflow-wrap:break-word;min-width:0;', r.label ) );
				row.appendChild( el( 'span', 'flex:none;max-width:100%;margin-left:auto;text-align:right;overflow-wrap:anywhere;font-variant-numeric:tabular-nums;font-weight:600;' + ( TONE[ r.tone ] ? 'color:' + TONE[ r.tone ] + ';' : '' ), r.value ) );
				list.appendChild( row );
			} );
			body.appendChild( list );
		} );
		// When these figures were read, as a clock time: the tile paints once,
		// so a relative age would go stale on a desktop left open.
		var at = payload && Number( payload.generated_at );
		if ( at > 0 ) {
			body.appendChild( el( 'div', 'font-size:11px;margin-top:8px;' + SUBTLE, 'Read at ' + new Date( at * 1000 ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ) ) );
		}
	}

	// A failure is said once, assertively, inside the polite status region.
	function alertLine( body, text ) {
		while ( body.firstChild ) { body.removeChild( body.firstChild ); }
		var line = el( 'div', '', text );
		line.setAttribute( 'role', 'alert' );
		body.appendChild( line );
	}

	function mounter( route, linkText, widgetName ) {
		return function mount( container, ctx ) { // eslint-disable-line no-unused-vars
			var torn = false;
			var wrap = el( 'div', 'padding:14px 16px;color:inherit;font-size:13px;line-height:1.5;' );
			var body = el( 'div', 'font-size:12px;' + SUBTLE, 'Loading…' );
			body.setAttribute( 'role', 'status' );
			wrap.appendChild( body );
			var url = ( window.snDesktopData && window.snDesktopData.pages && window.snDesktopData.pages.analytics ) || '';
			if ( url ) {
				// SN Traffic shares this link text; the name starts with the visible words (WCAG 2.5.3).
				var link = el( 'a', 'display:inline-flex;align-items:center;gap:4px;min-height:24px;margin-top:8px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;', linkText );
				var arrow = el( 'span', '', '→' );
				arrow.setAttribute( 'aria-hidden', 'true' );
				link.appendChild( arrow );
				link.setAttribute( 'aria-label', linkText + ', from the ' + widgetName + ' widget' );
				link.href = url;
				wrap.appendChild( link );
			}
			container.appendChild( wrap );
			if ( window.wp && window.wp.apiFetch ) {
				window.wp.apiFetch( { path: '/signal-noise/v1/desktop/' + route } ).then( function( payload ) {
					if ( ! torn ) { body.setAttribute( 'style', '' ); paint( body, payload ); }
				} ).catch( function() {
					if ( ! torn ) { alertLine( body, 'Could not load this reading.' ); }
				} );
			} else {
				alertLine( body, 'The API client is unavailable.' );
			}
			return function teardown() { torn = true; };
		};
	}

	window.desktopModeWidgets['sn-reading']  = mounter( 'reading', 'Open Analytics', 'SN Reading' );
} )();
