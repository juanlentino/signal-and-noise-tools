/**
 * Signal & Noise Tools — desktop-mode "SN Traffic" widget (id sn-site-views,
 * kept so the card keeps its place on a saved desktop).
 *
 * A 14-day first-party pageview sparkline + total + delta, then who came and
 * from where: countries, devices, sources, Hacker News, search, feed
 * subscribers (SN Audience and SN RSS Subscribers, folded in), and top pages. The stock
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

	// v10.43.0 — OpenStation rename compat (REJECT #11 MEDIUM fix): a
	// SELF-SUFFICIENT alias, not order-dependent on the external
	// assets/desktop-mode-os-compat.js prelude. openstation_resolve_script_payload()
	// (upstream payload.php:1371-1449) resolves only the handle's own src,
	// never walks deps, and server-sync/command-sync inject one bare
	// <script src="..."> tag per URL — so under a post-#475 mid-session
	// shell activation this file can run BEFORE the external prelude ever
	// does. Merge, don't clobber: if both globals already exist and differ
	// (a genuine race), copy the loser's keys into the survivor first.
	// Survivor = window.openStationWidgets, the name upstream itself reads
	// post-#475 (src/widgets/server-sync.ts) — see docs/openstation-compat.md.
	var __osWidgets = window.openStationWidgets || window.desktopModeWidgets || {};
	if ( window.desktopModeWidgets && window.desktopModeWidgets !== __osWidgets ) {
		for ( var __osKey in window.desktopModeWidgets ) {
			if ( ! ( __osKey in __osWidgets ) ) { __osWidgets[ __osKey ] = window.desktopModeWidgets[ __osKey ]; }
		}
	}
	window.desktopModeWidgets = window.openStationWidgets = __osWidgets;

	var data         = window.snDesktopData || {};
	var REFRESH_MS   = 5 * 60 * 1000; // SN RSS Subscribers' rate, now carried here
	var analyticsUrl = ( data.pages && data.pages.analytics ) || '';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		// v9.53.0: title carries the honesty explainers (what `confidence`
		// actually measures; that advisories are not faults) and the hover
		// text for ellipsised rows. Without this branch every title: passed
		// to el() was silently discarded — the explainers never rendered.
		if ( opts.title != null ) { node.title = opts.title; }
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

	// One delta idiom for every row: the arrow carries the direction, so the
	// number prints unsigned ("▲ 3", never "▲ +3"). Colour only when the
	// change is meaningful; otherwise the muted text colour, so a 4-to-3 wobble
	// does not paint an alarm.
	var UP = '#3fb950', DOWN = '#c9503f', MUTED = 'var(--os-ui-color-text-subtle, rgba(255,255,255,.6))';
	var MIN_ABS = 5, MIN_REL = 20, MIN_PTS = 5;

	function deltaText( d, suffix ) {
		return ( d >= 0 ? '▲ ' : '▼ ' ) + Math.abs( d ) + ( suffix || '' );
	}

	/** absChange in units; relPct in percent (null when no base). isPts: a points change. */
	function deltaColor( d, absChange, relPct, isPts ) {
		var a = Math.abs( absChange );
		var meaningful = isPts ? a >= MIN_PTS : ( a >= MIN_ABS && relPct !== null && Math.abs( relPct ) >= MIN_REL );
		return meaningful ? ( d > 0 ? UP : DOWN ) : MUTED;
	}

	/** Relative change of d against its base (prior value); null with no base. */
	function relOf( d, prior ) {
		return prior > 0 ? ( d / prior ) * 100 : null;
	}

	window.snSiteViewsDelta = { text: deltaText, color: deltaColor, rel: relOf };

	function deltaLine( pct, total ) {
		if ( pct === null || typeof pct === 'undefined' ) {
			// No prior window to compare — say nothing rather than imply flat.
			return el( 'div', { text: 'vs. prior 14 days: —', style: 'font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));' } );
		}
		// Prior total recovered from the percentage, for the absolute floor.
		var prior = typeof total === 'number' && pct > -100 ? total / ( 1 + pct / 100 ) : 0;
		var node = el( 'div', {
			text: deltaText( pct, '%' ) + ' vs. prior 14 days',
			style: 'font-size:11px;font-weight:600;color:' + deltaColor( pct, total - prior, pct, false ) + ';'
		} );
		return node;
	}

	/** A label/value row for the secondary stats. */
	function statRow( label, value, valueStyle ) {
		var row = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
		// A long label (a source name, a page path) wraps; the count never leaves the card.
		row.appendChild( el( 'span', { text: label, style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));min-width:0;overflow-wrap:anywhere;' } ) );
		row.appendChild( el( 'span', {
			text:  value,
			style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;' + ( valueStyle || '' )
		} ) );
		return row;
	}

	/**
	 * A group the way SN Reading paints one (assets/desktop-mode-widget-groups.js):
	 * a hairline, a heading, the rows as a list; `empty` when there are none.
	 */
	function group( title, rows, empty ) {
		var box  = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );
		var head = el( 'div', { text: title, style: 'font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));margin-bottom:2px;' } );
		head.setAttribute( 'role', 'heading' );
		head.setAttribute( 'aria-level', '3' );
		box.appendChild( head );
		if ( ! rows.length ) {
			box.appendChild( el( 'div', { text: empty || 'Nothing to show.', style: 'font-size:11px;padding:2px 0;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));' } ) );
			return box;
		}
		box.appendChild( list( rows ) );
		return box;
	}

	/** Label/value rows as a list (role=list, each row a listitem). */
	function list( rows ) {
		var ul = el( 'div' );
		ul.setAttribute( 'role', 'list' );
		rows.forEach( function( r ) {
			var row = statRow( String( r.label ), String( r.value ), r.style );
			row.setAttribute( 'role', 'listitem' );
			ul.appendChild( row );
		} );
		return ul;
	}

	/**
	 * Engaged readers with their change, DOI downloads and inquiries, the
	 * owner's pick of Site Views' north star rows (2026-10-04). Additive: an
	 * absent key (analytics unset, an older cached payload) paints no row, and
	 * a measured 0 is a number and paints.
	 */
	function weekRows( ns ) {
		var rows = [];
		if ( ! ns || typeof ns.value !== 'number' ) { return rows; }
		var d = ns.value - ( ns.previous || 0 );
		rows.push( { label: 'Engaged readers · 7d', value: String( ns.value ) + ( d ? ' ' + deltaText( d ) : '' ), style: d ? 'color:' + deltaColor( d, d, relOf( d, ns.previous || 0 ), false ) + ';' : '' } );
		if ( ns.doi && typeof ns.doi.value === 'number' ) { rows.push( { label: 'DOI downloads · ' + ns.doi.window, value: ns.doi.value } ); }
		if ( typeof ns.inquiries === 'number' ) { rows.push( { label: 'Inquiries · 7d', value: ns.inquiries } ); }
		return rows;
	}

	/**
	 * A change, said twice: the arrow for the eye (aria-hidden) and the
	 * direction in words for a screen reader. Null for no change or no prior.
	 */
	function changeNode( d ) {
		if ( typeof d !== 'number' || ! d ) { return null; }
		var wrap  = el( 'span', { style: 'margin-left:4px;font-weight:600;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));' } );
		var arrow = el( 'span', { text: d > 0 ? '▲' : '▼' } );
		arrow.setAttribute( 'aria-hidden', 'true' );
		var words = el( 'span', { text: d > 0 ? 'up' : 'down' } );
		words.className = 'screen-reader-text';
		wrap.appendChild( arrow );
		wrap.appendChild( words );
		wrap.appendChild( el( 'span', { text: ' ' + Math.abs( d ) } ) );
		return wrap;
	}

	/** "Reach · 14 days: 5 countries ▲ 2 · 5 sources", as one listed row. */
	function reachRow( r ) {
		var box = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );
		var ul  = el( 'div' );
		ul.setAttribute( 'role', 'list' );
		var row = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
		row.setAttribute( 'role', 'listitem' );
		row.appendChild( el( 'span', { text: 'Reach · 14 days', style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));min-width:0;' } ) );
		var val = el( 'span', { style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 1 auto;text-align:right;' } );
		var p   = r.prior && typeof r.prior.countries === 'number' ? r.prior : null;
		[ [ r.countries, 'countries', p && p.countries ], [ r.sources, 'sources', p && p.sources ] ].forEach( function( f, i ) {
			val.appendChild( el( 'span', { text: ( i ? ' · ' : '' ) + f[0] + ' ' + f[1] } ) );
			var c = p ? changeNode( f[0] - f[2] ) : null;
			if ( c ) { val.appendChild( c ); }
		} );
		row.appendChild( val );
		ul.appendChild( row );
		box.appendChild( ul );
		return box;
	}

	window.desktopModeWidgets['sn-site-views'] = function( container, ctx ) {
		var aborted   = false;
		var ctrl      = null;
		var timer     = null;
		var pending   = false;
		var lastAt    = 0;
		var lastDelay = REFRESH_MS;
		var failures  = 0;
		var shownJson = ''; // the payload on screen; an identical poll repaints nothing

		var wrap = el( 'div', { style: 'padding:10px 12px;' } );
		var body = el( 'div', { text: 'Loading…', style: 'font-size:12px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));' } );
		body.setAttribute( 'role', 'status' ); // the load and its outcome are announced politely
		wrap.appendChild( body );
		container.appendChild( wrap );

		function render( payload ) {
			body.textContent = '';
			body.setAttribute( 'style', '' );

			// No views is a headline of its own; the groups below still paint (a
			// feed can have subscribers in a window no page was viewed).
			if ( ! payload.days || ! payload.days.length ) {
				body.appendChild( el( 'div', {
					text: 'No views in the last 14 days',
					style: 'font-size:12px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));'
				} ) );
			} else {
				headline( payload );
			}

			// This week: three of the north star's rows, right under the headline.
			var week = weekRows( payload.north_star );
			if ( week.length ) {
				body.appendChild( group( 'This week', week ) );
			}

			// Reach: distinct countries and named sources with views in the window,
			// each with its change against the prior 14 days. Absent when it could
			// not be read: never a 0.
			if ( payload.reach && typeof payload.reach.countries === 'number' ) {
				body.appendChild( reachRow( payload.reach ) );
			}

			// SN Traffic: SN Audience's rows and SN RSS Subscribers' windows
			// paint here as groups (inc/desktop-mode-audience.php), then the top
			// pages. Read 2+ pages, downloads outbound, the bot share and the top
			// mover are not painted (owner's pick, 2026-10-04): they live in S&N
			// Analytics. Additive: an older cached payload without `groups` paints none.
			( payload.groups || [] ).forEach( function( g ) {
				if ( g && g.title ) { body.appendChild( group( g.title, g.rows || [], g.empty ) ); }
			} );

			var pageRows = ( payload.top_paths || [] ).filter( function( pg ) { return pg && pg.path; } ).map( function( pg ) {
				return { label: pg.path, value: String( pg.views ) };
			} );
			if ( pageRows.length ) {
				body.appendChild( group( 'Top pages', pageRows ) );
			}
		}

		function headline( payload ) {
			body.appendChild( el( 'div', {
				text: String( payload.total ),
				style: 'font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.1;'
			} ) );
			body.appendChild( el( 'div', {
				text: 'views · last 14 days',
				style: 'font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));margin-bottom:6px;'
			} ) );

			// Additive: an older cached payload without `today` paints nothing.
			// A measured 0 is a number and renders; absent/null does not.
			if ( typeof payload.today === 'number' ) {
				body.appendChild( list( [ { label: 'Today so far', value: payload.today } ] ) );
			}

			// The spark line and the links below ride the card token contract's
			// --os-ui-color-accent (OpenStation 1.1.5, #1603): with no theme worn
			// it chains to --os-ui-accent and follows the picker; Legacy pins it
			// to its own #3b82f6 (see the palette note in widget-health.js). The
			// fallback is the plugin's own blue.
			var chart = el( 'div', { style: 'color:var(--os-ui-color-accent, #4a9eff);margin:4px 0 6px;' } );
			chart.appendChild( sparkline( payload.days ) );
			body.appendChild( chart );

			body.appendChild( deltaLine( payload.delta_pct, payload.total ) );
		}

		function fail() {
			body.textContent = '';
			var alert = el( 'div', {
				text: 'Views unavailable',
				style: 'font-size:12px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));'
			} );
			alert.setAttribute( 'role', 'alert' );
			body.appendChild( alert );
		}

		// SN RSS Subscribers refreshed every five minutes; SN Traffic carries its
		// rows, so the whole payload polls at that rate (the payload itself is a
		// 15-minute server cache). The shape is the deploy card's
		// (assets/desktop-mode-widget.js): no poll while the tab is hidden,
		// reveal re-arms for what is left of the wait, a failure backs off and
		// keeps the last good reading, and teardown aborts the read in flight.
		function repaint( payload ) {
			var json = JSON.stringify( payload );
			if ( json === shownJson ) { return; }
			// Keep the reader's place: the card body scrolls, and a rebuild of the
			// same height must not jump it.
			var scrollers = [ container, container.parentNode ].filter( function( n ) { return n && typeof n.scrollTop === 'number'; } );
			var tops      = scrollers.map( function( n ) { return n.scrollTop; } );
			render( payload );
			shownJson = json;
			scrollers.forEach( function( n, k ) { n.scrollTop = tops[ k ]; } );
		}

		function refresh() {
			if ( aborted || pending ) { return; }
			pending = true;
			ctrl = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;
			var delay = REFRESH_MS;
			Promise.resolve().then( function() {
				if ( aborted ) { return; }
				if ( ! window.wp || ! window.wp.apiFetch ) { throw new Error( 'the API client is unavailable' ); }
				return window.wp.apiFetch( { path: '/signal-noise/v1/desktop/site-views', signal: ctrl ? ctrl.signal : undefined } );
			} ).then( function( res ) {
				if ( aborted ) { return; }
				failures = 0;
				repaint( res || {} );
			} ).catch( function( err ) {
				if ( aborted ) { return; }
				failures++;
				delay = Math.min( 15 * 60 * 1000, REFRESH_MS * Math.pow( 2, Math.min( failures - 1, 4 ) ) );
				var retry = Number( err && err.data && err.data.retry_after );
				if ( isFinite( retry ) && retry > 0 && retry <= 2147483 ) { delay = Math.max( delay, retry * 1000 ); }
				// The last good reading stays; only a card that never loaded says so.
				if ( ! shownJson ) { fail(); }
			} ).then( function() {
				pending   = false;
				ctrl      = null;
				lastAt    = Date.now();
				lastDelay = delay;
				arm();
			} );
		}

		function cadence( ms ) {
			return window.sntPollCadence ? window.sntPollCadence.wait( ms ) : ms;
		}
		function arm() {
			window.clearTimeout( timer );
			if ( aborted || pending || document.hidden ) { return; }
			var nextAt = lastAt + Math.max( lastDelay, cadence( REFRESH_MS ) );
			timer = window.setTimeout( refresh, Math.max( 0, nextAt - Date.now() ) );
		}
		function onVisibilityChange() {
			if ( document.hidden ) { window.clearTimeout( timer ); return; }
			arm();
		}
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		var unwatchFocus = window.sntPollCadence ? window.sntPollCadence.onFocusChange( onVisibilityChange ) : function() {};

		refresh();

		// The card's one link. SN Reading's says the same words, so the name
		// starts with them and says which card it is on (WCAG 2.5.3).
		if ( analyticsUrl ) {
			var link = el( 'a', {
				href: analyticsUrl,
				text: 'Open Analytics',
				style: 'display:inline-flex;align-items:center;gap:4px;min-height:24px;margin-top:8px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;'
			} );
			var arrow = el( 'span', { text: '→' } );
			arrow.setAttribute( 'aria-hidden', 'true' );
			link.appendChild( arrow );
			link.setAttribute( 'aria-label', 'Open Analytics, from the SN Traffic widget' );
			wrap.appendChild( link );
		}

		return function teardown() {
			aborted = true;
			window.clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
			unwatchFocus();
			if ( ctrl ) { ctrl.abort(); }
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
