/**
 * Signal & Noise Tools — desktop-mode "SN Site Views" widget.
 *
 * A 14-day first-party pageview sparkline + total + delta. The stock
 * desktop-mode "Site Views" tile can't show our numbers: it reads Jetpack
 * or `_post_views_YYYY-MM-DD` postmeta, and we write neither by design —
 * our views come from the edge beacon → Analytics Engine → the durable
 * wp_sn_analytics_daily rollup. Hence our own widget.
 *
 * MOUNT CONTRACT: desktop-mode's server-sync (src/widgets/server-sync.ts)
 * loads this script for a PHP-declared widget and then looks for the mount
 * callback at `window.desktopModeWidgets[ id ]`. It must be assigned as a
 * plain global — NOT via wp.desktop.registerWidget(), which is the separate
 * client-side path and hard-validates a full def (id/label/description/
 * icon/mount) that the server already owns for us.
 *
 * mount( container, ctx ) → teardown.
 *
 * Data: GET signal-noise/v1/desktop/site-views (fetch-on-render — the
 * series costs two aggregate SQL queries, so it never rides the localize).
 *
 * @since plugin v9.52.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	window.desktopModeWidgets = window.desktopModeWidgets || {};

	var data         = window.snDesktopData || {};
	var analyticsUrl = ( data.pages && data.pages.analytics ) || '';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		return node;
	}

	/**
	 * Inline SVG sparkline. Built with createElementNS (no innerHTML) so
	 * nothing user-controlled can reach the DOM as markup.
	 */
	function sparkline( days ) {
		var W = 220, H = 40, PAD = 2;
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
		svg.setAttribute( 'width', '100%' );
		svg.setAttribute( 'height', String( H ) );
		svg.setAttribute( 'aria-hidden', 'true' );

		var max = 0, i;
		for ( i = 0; i < days.length; i++ ) {
			if ( days[ i ].views > max ) { max = days[ i ].views; }
		}
		// A flat-zero window would divide by zero below; bail to a baseline.
		if ( max <= 0 || days.length < 2 ) { return svg; }

		var step = ( W - PAD * 2 ) / ( days.length - 1 );
		var pts  = [];
		for ( i = 0; i < days.length; i++ ) {
			var x = PAD + i * step;
			var y = H - PAD - ( days[ i ].views / max ) * ( H - PAD * 2 );
			pts.push( x.toFixed( 1 ) + ',' + y.toFixed( 1 ) );
		}

		var poly = document.createElementNS( 'http://www.w3.org/2000/svg', 'polyline' );
		poly.setAttribute( 'points', pts.join( ' ' ) );
		poly.setAttribute( 'fill', 'none' );
		poly.setAttribute( 'stroke', 'currentColor' );
		poly.setAttribute( 'stroke-width', '1.5' );
		poly.setAttribute( 'stroke-linejoin', 'round' );
		poly.setAttribute( 'stroke-linecap', 'round' );
		svg.appendChild( poly );
		return svg;
	}

	function deltaLine( pct ) {
		if ( pct === null || typeof pct === 'undefined' ) {
			// No prior window to compare — say nothing rather than imply flat.
			return el( 'div', { text: 'vs. prior 14 days: —', style: 'font-size:11px;opacity:.6;' } );
		}
		var up   = pct >= 0;
		var node = el( 'div', {
			text: ( up ? '▲ ' : '▼ ' ) + Math.abs( pct ) + '% vs. prior 14 days',
			style: 'font-size:11px;font-weight:600;color:' + ( up ? '#3fb950' : '#c9503f' ) + ';'
		} );
		return node;
	}

	window.desktopModeWidgets['sn-site-views'] = function( container, ctx ) {
		var aborted = false;
		var ctrl    = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;

		var wrap = el( 'div', { style: 'padding:10px 12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;' } );
		var body = el( 'div', { text: 'Loading…', style: 'font-size:12px;opacity:.6;' } );
		wrap.appendChild( body );
		container.appendChild( wrap );

		function render( payload ) {
			body.textContent = '';

			if ( ! payload.days || ! payload.days.length ) {
				body.appendChild( el( 'div', {
					text: 'No views in the last 14 days',
					style: 'font-size:12px;opacity:.6;'
				} ) );
				return;
			}

			body.appendChild( el( 'div', {
				text: String( payload.total ),
				style: 'font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.1;'
			} ) );
			body.appendChild( el( 'div', {
				text: 'views · last 14 days',
				style: 'font-size:11px;opacity:.6;margin-bottom:6px;'
			} ) );

			var chart = el( 'div', { style: 'color:#4a9eff;margin:4px 0 6px;' } );
			chart.appendChild( sparkline( payload.days ) );
			body.appendChild( chart );

			body.appendChild( deltaLine( payload.delta_pct ) );
		}

		function fail() {
			body.textContent = '';
			body.appendChild( el( 'div', {
				text: 'Views unavailable',
				style: 'font-size:12px;opacity:.6;'
			} ) );
		}

		if ( window.wp && window.wp.apiFetch ) {
			window.wp.apiFetch( {
				path: '/signal-noise/v1/desktop/site-views',
				signal: ctrl ? ctrl.signal : undefined
			} ).then( function( res ) {
				if ( aborted ) { return; }
				render( res || {} );
			} ).catch( function() {
				if ( aborted ) { return; }
				fail();
			} );
		} else {
			fail();
		}

		if ( analyticsUrl ) {
			var link = el( 'a', {
				href: analyticsUrl,
				text: 'Open Analytics →',
				style: 'display:inline-block;margin-top:8px;font-size:11px;text-decoration:none;opacity:.75;'
			} );
			wrap.appendChild( link );
		}

		return function teardown() {
			aborted = true;
			if ( ctrl ) { ctrl.abort(); }
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
