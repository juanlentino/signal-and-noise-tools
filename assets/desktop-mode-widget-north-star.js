/**
 * Signal & Noise Tools: desktop widget "SN North Star".
 *
 * The weekly engaged readers, the change against last week, and the three
 * intent rows under it. Reads the signal-noise/north-star ability (cached an
 * hour server-side), so a 15-minute poll costs no Analytics Engine call.
 * Same mount contract and visibility-aware polling as the RSS widget.
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}
	var __osWidgets = window.openStationWidgets || window.desktopModeWidgets || {};
	if ( window.desktopModeWidgets && window.desktopModeWidgets !== __osWidgets ) {
		for ( var __osKey in window.desktopModeWidgets ) {
			if ( ! ( __osKey in __osWidgets ) ) { __osWidgets[ __osKey ] = window.desktopModeWidgets[ __osKey ]; }
		}
	}
	window.desktopModeWidgets = window.openStationWidgets = __osWidgets;

	var REFRESH_MS = 15 * 60 * 1000;
	var SUBTLE     = 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));';
	var ROWS       = [
		[ 'deep_readers', 'Deep readers' ],
		[ 'actions', 'Downloads, outbound' ],
		[ 'career_visits', 'Resume, contact' ],
	];

	function el( tag, style, text ) {
		var node = document.createElement( tag );
		if ( style ) { node.setAttribute( 'style', style ); }
		if ( text != null ) { node.textContent = text; }
		return node;
	}

	function render( container, r ) {
		container.textContent = '';
		var wrap = el( 'div', 'padding:14px 16px;color:inherit;' );
		if ( ! r || ! r.configured ) {
			wrap.appendChild( el( 'p', 'margin:0;font-size:12px;' + SUBTLE, 'Needs the analytics credentials.' ) );
			container.appendChild( wrap );
			return;
		}
		var delta = ( r.value || 0 ) - ( r.previous || 0 );
		wrap.appendChild( el( 'div', 'font-size:28px;font-weight:600;line-height:1.1;font-variant-numeric:tabular-nums;', Number( r.value || 0 ).toLocaleString() ) );
		wrap.appendChild( el( 'p', 'margin:2px 0 10px;font-size:11px;' + SUBTLE,
			'engaged readers · 7 days · ' + ( delta > 0 ? '+' : '' ) + delta + ' vs last week' ) );

		var intent = ( r.layers && r.layers.intent ) || {};
		var grid = el( 'div', 'display:grid;grid-template-columns:1fr auto;gap:4px 14px;font-size:12px;line-height:1.4;' );
		ROWS.forEach( function( row ) {
			var v = intent[ row[0] ] ? intent[ row[0] ].value : null;
			grid.appendChild( el( 'span', SUBTLE, row[1] ) );
			grid.appendChild( el( 'span', 'font-variant-numeric:tabular-nums;font-weight:500;', v == null ? '·' : Number( v ).toLocaleString() ) );
		} );
		wrap.appendChild( grid );
		container.appendChild( wrap );
	}

	function mount( container, ctx ) { // eslint-disable-line no-unused-vars
		if ( ! container ) { return function() {}; }
		var torn = false;
		render( container, null );
		container.firstChild.firstChild.textContent = 'Loading…';

		function refresh() {
			if ( torn || ! window.sntAbilityRun ) { return; }
			window.sntAbilityRun( 'north-star', undefined, { silent: true } )
				.then( function( res ) {
					if ( torn ) { return; }
					// The run-path may hand the output back bare or under data.
					render( container, res && res.data && res.data.value !== undefined ? res.data : res );
				} )
				.catch( function( err ) {
					if ( torn ) { return; }
					container.textContent = '';
					container.appendChild( el( 'p', 'padding:14px 16px;font-size:12px;color:#ff9d94;', 'North star failed: ' + ( err && err.message ? err.message : 'unknown' ) ) );
				} );
		}
		refresh();

		var intervalId = null;
		var lastRunMs  = Date.now();
		function poll() { lastRunMs = Date.now(); refresh(); }
		function start() { if ( intervalId === null ) { intervalId = window.setInterval( poll, REFRESH_MS ); } }
		function stop() { if ( intervalId !== null ) { window.clearInterval( intervalId ); intervalId = null; } }
		function onVisibility() {
			if ( document.hidden ) { stop(); return; }
			if ( Date.now() - lastRunMs >= REFRESH_MS ) { poll(); }
			start();
		}
		document.addEventListener( 'visibilitychange', onVisibility );
		if ( ! document.hidden ) { start(); }

		return function teardown() {
			torn = true;
			stop();
			document.removeEventListener( 'visibilitychange', onVisibility );
			container.textContent = '';
		};
	}

	window.desktopModeWidgets['sn-north-star'] = mount;
} )();
