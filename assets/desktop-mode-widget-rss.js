/**
 * Signal & Noise Tools — desktop-mode "RSS subscribers" widget.
 *
 * Surfaces RSS feed activity that previously lived only behind the
 * S&N → RSS tab + (since v2.0.1) a single line on the SN Dashboard
 * tab. This widget puts last-request-time + 24h / 7d / 30d unique
 * subscriber counts on the desktop at-a-glance.
 *
 * Data source: the signal-noise/get-rss-stats ability run-path (v6.55.0;
 * previously GET /signal-noise/v1/cmd/rss-stats). Wraps
 * sn_rss_tracker_window_stats_multi().
 *
 * Polling: every 5 min (RSS counts don't change rapidly; far less
 * urgent than the deploy-status widget's 60s cadence).
 *
 * Pattern matches assets/desktop-mode-widget.js exactly.
 *
 * v9.52.4: re-themed for the dark glass card. This was styled for a white
 * admin page (#1d2327 text, #646970 labels, #2271b1 links) — legible-but-dim
 * on `.desktop-mode-widgets__card`, which is fixed dark glass with color:#fff
 * and is NOT theme-switchable. Text now inherits the card's white and muting
 * is done with opacity, so the card owns the palette. (Not using
 * --wpd-color-*: first-party CSS consumes those tokens but desktop-mode v0.9.5
 * defines them nowhere, so var() always hits its fallback.)
 *
 * v10.28.0: still true at desktop-mode v0.9.8, desktop themes and all — the
 * card background is a literal, so it cannot go light. Full reasoning for why
 * the themeable --wpd-* body palette is the WRONG fit for this surface lives
 * in assets/desktop-mode-widget-actions.js.
 *
 * @since plugin v2.1.0
 */
( function() {
	'use strict';

	// v9.52.0 — MOUNT CONTRACT FIX. This widget is PHP-declared via
	// desktop_mode_register_widget(), so desktop-mode's server-sync loads this
	// script and then reads the mount callback off window.desktopModeWidgets[ id ].
	// Until v9.52.0 this file called wp.desktop.registerWidget({id, render}) —
	// the OTHER (client-side) path, and with the wrong shape: that path
	// hard-validates id + label + description + icon + mount and THROWS
	// otherwise, so this widget never registered on either path. It was
	// silently dead. Gate on nothing but `window`: the global is ours to own,
	// and wp.desktop need not exist yet when this script runs.
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

	var data        = window.snDesktopData || {};
	var rssPageUrl  = ( data.pages && data.pages.rss ) || '';
	// v6.55.0: read RSS activity via the get-rss-stats ability run-path.
	var REFRESH_MS  = 5 * 60 * 1000;

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		return node;
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function renderLoading( container ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'padding:14px 16px;font-size:13px;opacity:.6;',
			text:  'Loading RSS activity…',
		} ) );
	}

	function renderError( container, message ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'padding:14px 16px;font-size:12px;color:#ff9d94;',
			text:  'RSS fetch failed: ' + ( message || 'unknown' ),
		} ) );
	}

	function renderCard( container, stats ) {
		clearChildren( container );

		var wrap = el( 'div', {
			style: 'padding:14px 16px;color:inherit;',
		} );

		// v9.52.4: no title row — desktop-mode's chrome header (grip + label +
		// remove), rendered since movable:true in v9.52.2, already shows this
		// card's name. Painting it here printed "RSS Subscribers" twice.

		wrap.appendChild( el( 'p', {
			style: 'margin:0 0 10px;font-size:11px;opacity:.5;',
			text:  stats.last_request_relative
				? 'Last request: ' + stats.last_request_relative
				: 'No requests yet',
		} ) );

		var windows = stats.windows || {};
		var grid = el( 'div', {
			style: 'display:grid;grid-template-columns:auto 1fr auto;gap:4px 14px;font-size:13px;line-height:1.4;align-items:baseline;',
		} );

		[
			{ key: '1',  label: '24h' },
			{ key: '7',  label: '7d'  },
			{ key: '30', label: '30d' },
		].forEach( function( w ) {
			var bucket = windows[ w.key ] || { total: 0, uniques: 0 };
			grid.appendChild( el( 'span', {
				style: 'opacity:.6;',
				text:  w.label,
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;font-weight:500;',
				text:  Number( bucket.total || 0 ).toLocaleString() + ' req',
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;opacity:.75;',
				text:  Number( bucket.uniques || 0 ).toLocaleString() + ' uniq',
			} ) );
		} );

		wrap.appendChild( grid );

		if ( rssPageUrl ) {
			wrap.appendChild( el( 'a', {
				style: 'display:inline-block;margin-top:10px;font-size:11px;color:var(--os-window-link-accent, #4a9eff);text-decoration:none;',
				text:  'Open RSS tab →',
				href:  rssPageUrl,
			} ) );
		}

		container.appendChild( wrap );
	}

	/**
	 * v9.52.0: mount( container, ctx ) → teardown. `torn` also gates the
	 * async callbacks: an in-flight sntAbilityRun that resolves after the
	 * user removes the widget must not repaint a detached container.
	 */
	function mount( container, ctx ) {
		if ( ! container ) { return function() {}; }

		var torn = false;
		renderLoading( container );

		function refresh() {
			if ( torn ) { return; }
			if ( ! window.sntAbilityRun ) {
				renderError( container, 'sntAbilityRun unavailable' );
				return;
			}
			// v7.7.2: readonly ability → the runner GETs it (POST 405'd).
			window.sntAbilityRun( 'get-rss-stats' )
				.then( function( res ) {
					if ( torn ) { return; }
					if ( res && res.data ) {
						renderCard( container, res.data );
					}
				} )
				.catch( function( err ) {
					if ( torn ) { return; }
					renderError( container, err && err.message ? err.message : 'unknown' );
				} );
		}

		refresh();

		var intervalId = window.setInterval( refresh, REFRESH_MS );

		return function teardown() {
			torn = true;
			window.clearInterval( intervalId );
			container.textContent = '';
		};
	}

	window.desktopModeWidgets['sn-rss-subscribers'] = mount;

} )();
