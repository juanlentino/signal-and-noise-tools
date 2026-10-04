/**
 * Signal & Noise Tools — desktop "SN Audience" and "SN Reading" widgets.
 *
 * One painter for both: the server hands a list of groups, each a list of
 * rows (label, value) or an `empty` sentence, and this paints them the way
 * the tiles beside it paint theirs. It knows no metric; what a row means is
 * decided in inc/desktop-mode-audience.php and inc/desktop-mode-reading.php.
 *
 * MOUNT CONTRACT: the shell looks the mount up at
 * `window.desktopModeWidgets[ id ]`, a plain global (see
 * desktop-mode-widget-views.js). mount( container, ctx ) → teardown.
 *
 * Data: GET signal-noise/v1/desktop/audience | reading, fetched on render.
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

	var TONE   = { up: '#3fb950', down: '#c9503f' }; // a row's change when the server calls it meaningful; SN Site Views' two colors.
	var SUBTLE = 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));';

	function el( tag, style, text ) {
		var node = document.createElement( tag );
		if ( style ) { node.setAttribute( 'style', style ); }
		if ( text != null ) { node.textContent = text; }
		return node;
	}

	function paint( body, payload ) {
		while ( body.firstChild ) { body.removeChild( body.firstChild ); }
		var win = payload && payload.window;
		if ( win && win.days ) {
			body.appendChild( el( 'div', 'font-size:11px;margin-bottom:4px;' + SUBTLE, 'Last ' + win.days + ' days' ) );
		}
		( ( payload && payload.groups ) || [] ).forEach( function( group, i ) {
			body.appendChild( el( 'div', 'font-size:11px;margin:' + ( i ? '10px' : '2px' ) + ' 0 2px;padding-top:' + ( i ? '8px' : '0' ) + ';' + ( i ? 'border-top:1px solid rgba(128,128,128,.25);' : '' ) + SUBTLE, group.title ) );
			if ( ! group.rows || ! group.rows.length ) {
				body.appendChild( el( 'div', 'font-size:11px;padding:2px 0;' + SUBTLE, group.empty || 'Nothing to show.' ) );
				return;
			}
			group.rows.forEach( function( r ) {
				var row = el( 'div', 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' );
				row.appendChild( el( 'span', 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;', r.label ) );
				row.appendChild( el( 'span', 'flex:none;font-variant-numeric:tabular-nums;font-weight:600;' + ( TONE[ r.tone ] ? 'color:' + TONE[ r.tone ] + ';' : '' ), r.value ) );
				body.appendChild( row );
			} );
		} );
	}

	function mounter( route, linkText ) {
		return function mount( container, ctx ) { // eslint-disable-line no-unused-vars
			var torn = false;
			var wrap = el( 'div', 'padding:14px 16px;color:inherit;font-size:13px;line-height:1.5;' );
			var body = el( 'div', 'font-size:12px;' + SUBTLE, 'Loading…' );
			wrap.appendChild( body );
			var url = ( window.snDesktopData && window.snDesktopData.pages && window.snDesktopData.pages.analytics ) || '';
			if ( url ) {
				var link = el( 'a', 'display:inline-flex;align-items:center;min-height:24px;margin-top:8px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;', linkText );
				link.href = url;
				wrap.appendChild( link );
			}
			container.appendChild( wrap );
			if ( window.wp && window.wp.apiFetch ) {
				window.wp.apiFetch( { path: '/signal-noise/v1/desktop/' + route } ).then( function( payload ) {
					if ( ! torn ) { body.setAttribute( 'style', '' ); paint( body, payload ); }
				} ).catch( function() {
					if ( ! torn ) { body.textContent = 'Could not load this reading.'; }
				} );
			} else {
				body.textContent = 'The API client is unavailable.';
			}
			return function teardown() { torn = true; };
		};
	}

	window.desktopModeWidgets['sn-audience'] = mounter( 'audience', 'Open Analytics →' );
	window.desktopModeWidgets['sn-reading']  = mounter( 'reading', 'Open Analytics →' );
} )();
