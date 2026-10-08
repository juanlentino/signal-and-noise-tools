/**
 * Signal & Noise Tools: the desktop cards' shared marks. A share bar (one
 * thin bar split by percentages), the dot that ties a row to its segment, and
 * a pair (two short groups on one row). Decoration only: every figure a bar
 * draws is also printed in a row, so the marks are hidden from assistive tech.
 *
 * A card that loads without this file (an older shell that injects only the
 * card's own script) draws no bar and stacks its pairs: every figure is still
 * there. Hence `window.sntCardKit ? ... : ...` at each use.
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var ACCENT = 'var(--os-ui-color-accent, #4a9eff)';
	// The accent, strongest first: a segment and its row's dot share a shade.
	// Dimmed (23.3.1, the owner's call): a share is not a warning, and an
	// accent at full strength (red on this desk) read like one beside SN
	// Systems' real alarms. Quality bars keep their full colors.
	var SHADES = [ 0.6, 0.42, 0.28, 0.18 ];
	// Good, needs work, poor (the cards' own greens, ambers and reds).
	var QUALITY = [ '#3fb950', '#d29922', '#ff9d94' ];

	function mark( style ) {
		var s = document.createElement( 'span' );
		s.setAttribute( 'style', style );
		s.setAttribute( 'aria-hidden', 'true' );
		return s;
	}

	function fill( i, quality ) {
		return quality ? 'background:' + QUALITY[ Math.min( i, QUALITY.length - 1 ) ] + ';' : 'background:' + ACCENT + ';opacity:' + SHADES[ Math.min( i, SHADES.length - 1 ) ] + ';';
	}

	/**
	 * A bar split by `shares` (percent of the whole, so the track left over is
	 * everything not listed). quality: good, needs work, poor colors instead
	 * of accent shades. Null when no share is above zero.
	 */
	function bar( shares, quality ) {
		var any = ( shares || [] ).some( function( n ) { return typeof n === 'number' && n > 0; } );
		if ( ! any ) { return null; }
		var b = document.createElement( 'div' );
		b.setAttribute( 'aria-hidden', 'true' );
		b.setAttribute( 'style', 'flex:0 0 100%;display:flex;gap:1px;height:6px;border-radius:3px;overflow:hidden;margin:3px 0 4px;background:var(--os-ui-color-border, rgba(255,255,255,0.12));' );
		// Grow factors, not fixed widths: the 1px gaps then come out of the
		// segments instead of pushing the last one past the clip. The rest of
		// the whole is an empty segment of its own, so the track still shows it.
		var used = 0;
		shares.forEach( function( n, i ) {
			if ( typeof n === 'number' && n > 0 ) {
				var w = Math.min( 100 - used, n );
				used += w;
				if ( w > 0 ) { b.appendChild( mark( 'flex:' + w + ' 1 0;min-width:1px;' + fill( i, quality ) ) ); }
			}
		} );
		if ( used < 100 ) { b.appendChild( mark( 'flex:' + ( 100 - used ) + ' 1 0;' ) ); }
		return b;
	}

	/** The dot that names a row's segment, in the segment's shade. */
	function dot( i, quality ) {
		return mark( 'display:inline-block;width:6px;height:6px;border-radius:50%;margin-right:4px;vertical-align:1px;' + fill( i, quality ) );
	}

	/**
	 * Two groups on one row under one hairline. Each column starts at its own
	 * content's width and they share what is left; a card too narrow for both
	 * stacks them. Pass groups built without a hairline of their own.
	 */
	function pair( a, b ) {
		var box = document.createElement( 'div' );
		box.setAttribute( 'style', 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));display:flex;flex-wrap:wrap;gap:6px 12px;' );
		[ a, b ].forEach( function( col ) {
			col.style.flex = '1 1 auto';
			col.style.minWidth = '100px';
			box.appendChild( col );
		} );
		return box;
	}

	window.sntCardKit = { bar: bar, dot: dot, pair: pair };
}() );
