/**
 * Signal & Noise Tools — the "Analytics" NATIVE desktop-mode window.
 *
 * MOUNT CONTRACT (desktop-mode v0.9.5, read from the tag): a PHP-declared
 * native window's render callback lives at
 * `window.desktopModeNativeWindows[ id ]` and takes a SINGLE ( body ) arg.
 * This is NOT the widget contract — widgets register on a DIFFERENT global
 * (see the sibling desktop-mode-widget-*.js files) with ( container, ctx ).
 * The shell has
 * ALREADY cloned the registered <template> into `body` before calling us, so
 * render is ENHANCEMENT, not construction: query the mount points and light
 * them up. The return value is captured as a teardown.
 *
 * Config rides `window.desktopModeWindowConfig[ id ]` (the 'config' arg on
 * desktop_mode_register_window), which desktop-mode injects via
 * wp_add_inline_script( <our handle>, …, 'before' ) — so it is guaranteed
 * present before this file runs, and no script dependency is needed.
 *
 * @since plugin v9.56.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var WINDOW_ID = 'sn-analytics-hud';
	var POLL_MS   = 30000;

	window.desktopModeNativeWindows = window.desktopModeNativeWindows || {};

	/**
	 * null/undefined render as an em dash, never as 0. sn_analytics_realtime()
	 * and sn_analytics_engaged_rate() both document int|null, and null means
	 * "never measured" — a state a warmed-but-quiet site's real 0 must stay
	 * distinguishable from. Collapsing both to 0 here would re-fabricate the
	 * exact zero this HUD was burned by once already.
	 */
	function show( value, suffix ) {
		if ( value === null || typeof value === 'undefined' ) {
			return '—';
		}
		return String( value ) + ( suffix || '' );
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	/** Renders one label/value <wpd-row> per item of `rows` into `node`. */
	function renderRows( node, rows, labelKey, valueKey ) {
		if ( ! node ) { return; }
		clearChildren( node );
		( rows || [] ).forEach( function( row ) {
			var line  = document.createElement( 'wpd-row' );
			var label = document.createElement( 'span' );
			var value = document.createElement( 'strong' );
			label.textContent = String( row[ labelKey ] );
			value.textContent = String( row[ valueKey ] );
			line.appendChild( label );
			line.appendChild( value );
			node.appendChild( line );
		} );
	}

	// Literal id at the assignment site — the ONE window this file registers.
	window.desktopModeNativeWindows[ 'sn-analytics-hud' ] = function( body ) {
		var cfg     = ( window.desktopModeWindowConfig || {} )[ WINDOW_ID ] || {};
		var stopped = false;

		function mount( name ) {
			return body.querySelector( '[data-sn-hud="' + name + '"]' );
		}

		var link = mount( 'full-link' );
		if ( link && cfg.fullUrl ) {
			link.href = cfg.fullUrl;
		}

		function fail( message ) {
			// Report, never swallow. A silent zero reads identically to "no
			// traffic" — this row exists so a fetch/parse failure is visible
			// instead of quietly leaving the last good render on screen.
			var root = mount( 'root' );
			if ( ! root ) { return; }
			var note = root.querySelector( '[data-sn-hud="error"]' );
			if ( ! note ) {
				note = document.createElement( 'wpd-row' );
				note.setAttribute( 'data-sn-hud', 'error' );
				root.appendChild( note );
			}
			note.textContent = message;
		}

		function render( data ) {
			var realtime = mount( 'realtime' );
			if ( realtime ) {
				realtime.textContent = show( data.realtime );
			}

			renderRows( mount( 'kpis' ), [
				{ label: 'Views',   display: show( data.seven_day.views ) },
				{ label: 'Visits',  display: show( data.seven_day.visits ) },
				{ label: 'Engaged', display: show( data.seven_day.engaged_rate, '%' ) }
			], 'label', 'display' );

			// Row keys mirror the REAL accessors, verified against source:
			// sn_analytics_top_paths   → path, views, visits, scroll_avg, time_avg
			//                            (inc/analytics-read.php)
			// sn_analytics_top_sources → value, views, visits, hosts
			//                            (inc/analytics-sources.php) — `value`, NOT `source`.
			renderRows( mount( 'top-content' ), data.top_content, 'path', 'views' );
			renderRows( mount( 'top-sources' ), data.top_sources, 'value', 'visits' );
		}

		function refresh() {
			if ( stopped || ! cfg.endpoint ) { return; }

			window.fetch( cfg.endpoint, {
				headers:     { 'X-WP-Nonce': cfg.nonce || '' },
				credentials: 'same-origin'
			} ).then( function( res ) {
				if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
				return res.json();
			} ).then( function( data ) {
				if ( stopped ) { return; }
				render( data );
			} ).catch( function( err ) {
				if ( ! stopped ) {
					fail( 'Analytics unavailable: ' + ( err && err.message ? err.message : 'unknown error' ) );
				}
			} );
		}

		refresh();
		var timer = window.setInterval( refresh, POLL_MS );

		// Teardown. This is what makes the poll open-window-only — without it
		// every closed HUD would keep firing a request every 30s forever.
		return function teardown() {
			stopped = true;
			window.clearInterval( timer );
		};
	};

} )();
