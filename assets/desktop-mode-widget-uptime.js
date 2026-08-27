/**
 * Signal & Noise Tools — desktop-mode "SN Uptime" widget.
 *
 * v9.53.0. Uptime was one row inside SN Pulse; it earns its own card now that
 * it can show 30-day availability and response time per monitor.
 *
 * MOUNT CONTRACT: assigned to window.desktopModeWidgets[ id ] — see
 * desktop-mode-widget-views.js for the full note on why this is the right path
 * for a PHP-declared widget.
 *
 * DATA. The signal-noise/uptime-status ability with {detail:true}, via the
 * shared run-path (the same seam Deploy Status and RSS use). NOT localized:
 * unlike health (one durable option read), uptime detail makes Better Stack API
 * calls — statuses on a 90s transient, availability on 1h/6h, response times on
 * 15min. Those must never run on every wp-admin page load, so this is
 * fetch-on-render like Site Views.
 *
 * Contract (snt_ability_uptime_status): { configured, fetched_at, rows[],
 * incidents, error }. `configured:false` is an HONEST STATE, not an error — no
 * token saved yet. Each row: { name, status, level, availability,
 * incidents_30d, availability_90d, response_ms }, where any stat may be null
 * because the tiers cache independently and fail soft: a partial Better Stack
 * outage degrades a row, never the card.
 *
 * @since plugin v9.53.0
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
	var dashboardUrl = ( data.pages && data.pages.dashboard ) || '';
	// Monitors don't flap by the second; the statuses ride a 90s server cache
	// anyway, so a 2-minute poll never outruns the data underneath it.
	var REFRESH_MS   = 2 * 60 * 1000;

	var LEVEL_COLOR = { ok: '#3fb950', warn: '#d29922', alert: '#ff9d94' };
	var LEVEL_TEXT  = { ok: 'Up', warn: 'Attention', alert: 'Down' };

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		if ( opts.title != null ) { node.title = opts.title; }
		return node;
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	/** "99.98%" — null stays null, never a fabricated 100%. */
	function pct( v ) {
		if ( v === null || typeof v === 'undefined' || isNaN( Number( v ) ) ) { return null; }
		var n = Number( v );
		// Two decimals matter here: 99.9% and 99.99% are an order of magnitude
		// apart in downtime (8.8h/yr vs 53m/yr).
		return ( Math.round( n * 100 ) / 100 ) + '%';
	}

	function ms( v ) {
		if ( v === null || typeof v === 'undefined' || isNaN( Number( v ) ) ) { return null; }
		return Math.round( Number( v ) ) + 'ms';
	}

	function note( container, text ) {
		clearChildren( container );
		var wrap = el( 'div', { style: 'padding:14px 16px;color:inherit;' } );
		wrap.appendChild( el( 'div', { text: text, style: 'font-size:12px;opacity:.6;' } ) );
		if ( dashboardUrl ) {
			wrap.appendChild( el( 'a', {
				href: dashboardUrl,
				text: 'Open Dashboard →',
				style: 'display:inline-block;margin-top:8px;font-size:11px;color:var(--os-window-link-accent, #4a9eff);text-decoration:none;'
			} ) );
		}
		container.appendChild( wrap );
	}

	function renderCard( container, payload ) {
		clearChildren( container );

		// configured:false is not a failure — say so plainly rather than
		// rendering a green "Up" for monitors that don't exist.
		if ( ! payload.configured ) {
			note( container, 'Better Stack not configured — no uptime data yet.' );
			return;
		}
		if ( payload.error ) {
			note( container, 'Uptime unavailable: ' + payload.error );
			return;
		}
		var rows = payload.rows || [];
		if ( ! rows.length ) {
			note( container, 'No monitors configured.' );
			return;
		}

		var wrap = el( 'div', { style: 'padding:14px 16px;color:inherit;' } );

		rows.forEach( function( row ) {
			var level = String( row.level || 'ok' );
			var line  = el( 'div', { style: 'display:flex;align-items:baseline;gap:8px;padding:5px 0;' } );

			line.appendChild( el( 'span', {
				style: 'width:8px;height:8px;border-radius:50%;flex:0 0 auto;background:' + ( LEVEL_COLOR[ level ] || LEVEL_COLOR.ok ) + ';'
			} ) );
			line.appendChild( el( 'span', {
				text:  String( row.name || 'monitor' ),
				style: 'font-size:12px;font-weight:500;flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
			} ) );
			line.appendChild( el( 'span', {
				text:  LEVEL_TEXT[ level ] || String( row.status || level ),
				style: 'font-size:11px;font-weight:600;color:' + ( LEVEL_COLOR[ level ] || 'inherit' ) + ';'
			} ) );
			wrap.appendChild( line );

			// Stats row. Each tier caches independently and fails soft to null,
			// so omit what's missing rather than printing a placeholder that
			// reads like a measurement.
			var a30  = pct( row.availability );
			var rt   = ms( row.response_ms );
			var bits = [];
			if ( a30 ) { bits.push( a30 + ' · 30d' ); }
			if ( rt )  { bits.push( rt ); }
			if ( bits.length ) {
				wrap.appendChild( el( 'div', {
					text:  bits.join( '  ·  ' ),
					style: 'font-size:11px;opacity:.55;margin:0 0 4px 16px;font-variant-numeric:tabular-nums;'
				} ) );
			}
		} );

		if ( dashboardUrl ) {
			wrap.appendChild( el( 'a', {
				href: dashboardUrl,
				text: 'Open Uptime →',
				style: 'display:inline-block;margin-top:8px;font-size:11px;color:var(--os-window-link-accent, #4a9eff);text-decoration:none;'
			} ) );
		}

		container.appendChild( wrap );
	}

	function mount( container, ctx ) {
		if ( ! container ) { return function() {}; }

		var torn = false;
		note( container, 'Loading uptime…' );

		function refresh() {
			if ( torn ) { return; }
			if ( ! window.sntAbilityRun ) {
				note( container, 'sntAbilityRun unavailable' );
				return;
			}
			window.sntAbilityRun( 'uptime-status', { detail: true } )
				.then( function( res ) {
					if ( torn ) { return; }
					renderCard( container, res || {} );
				} )
				.catch( function( err ) {
					if ( torn ) { return; }
					note( container, 'Uptime fetch failed: ' + ( ( err && err.message ) ? err.message : 'unknown' ) );
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

	window.desktopModeWidgets['sn-uptime'] = mount;

} )();
