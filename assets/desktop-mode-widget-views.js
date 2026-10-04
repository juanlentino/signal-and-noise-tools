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
		row.appendChild( el( 'span', { text: label, style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));' } ) );
		row.appendChild( el( 'span', {
			text:  value,
			style: 'font-variant-numeric:tabular-nums;font-weight:600;' + ( valueStyle || '' )
		} ) );
		return row;
	}

	window.desktopModeWidgets['sn-site-views'] = function( container, ctx ) {
		var aborted = false;
		var ctrl    = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;

		var wrap = el( 'div', { style: 'padding:10px 12px;' } );
		var body = el( 'div', { text: 'Loading…', style: 'font-size:12px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));' } );
		wrap.appendChild( body );
		container.appendChild( wrap );

		function render( payload ) {
			body.textContent = '';

			if ( ! payload.days || ! payload.days.length ) {
				body.appendChild( el( 'div', {
					text: 'No views in the last 14 days',
					style: 'font-size:12px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));'
				} ) );
				return;
			}

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
				body.appendChild( statRow( 'Today so far', String( payload.today ) ) );
			}

			// The spark line and the links below ride the card token contract's
			// --os-ui-color-accent (OpenStation 1.1.5, #1603): with no theme worn
			// it chains to --os-ui-accent and follows the picker; Legacy pins it
			// to its own #3b82f6 (see the palette note in widget-actions.js). The
			// fallback is the plugin's own blue.
			var chart = el( 'div', { style: 'color:var(--os-ui-color-accent, #4a9eff);margin:4px 0 6px;' } );
			chart.appendChild( sparkline( payload.days ) );
			body.appendChild( chart );

			body.appendChild( deltaLine( payload.delta_pct, payload.total ) );

			// The north star: views say how many came, this says how many read.
			// Additive: absent key (analytics unset, older cached payload) paints nothing.
			var ns = payload.north_star;
			if ( ns && typeof ns.value === 'number' ) {
				var nsBox = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );
				var nsDelta = ns.value - ( ns.previous || 0 );
				nsBox.appendChild( statRow( 'Engaged readers · 7d', String( ns.value ) + ( nsDelta ? ' ' + deltaText( nsDelta ) : '' ), nsDelta ? 'color:' + deltaColor( nsDelta, nsDelta, relOf( nsDelta, ns.previous || 0 ), false ) + ';' : '' ) );
				if ( typeof ns.deep === 'number' ) { nsBox.appendChild( statRow( 'Read 2+ pages', String( ns.deep ) ) ); }
				if ( typeof ns.actions === 'number' ) { nsBox.appendChild( statRow( 'Downloads, outbound', String( ns.actions ) ) ); }
				if ( ns.doi && typeof ns.doi.value === 'number' ) { nsBox.appendChild( statRow( 'DOI downloads · ' + ns.doi.window, String( ns.doi.value ) ) ); }
				if ( typeof ns.inquiries === 'number' ) { nsBox.appendChild( statRow( 'Inquiries · 7d', String( ns.inquiries ) ) ); }
				body.appendChild( nsBox );
			}

			// ── v9.53.0 secondary stats ──
			var stats = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );

			// 21.2.1: Visits and Engaged moved to SN Reading, and Top sources to
			// SN Audience. This tile is the overview: the headline, the north
			// star, the mover, the bot share and the top pages. The payload still
			// carries the moved keys; only the paint changed.

			// Additive: the strongest PATH mover (path + signed views delta,
			// from the rail tile's own producer). Absent/malformed key → no
			// row. Same path-row idiom as Top pages.
			if ( payload.top_mover && payload.top_mover.path
				&& typeof payload.top_mover.delta === 'number' && payload.top_mover.delta !== 0 ) {
				var mvD = payload.top_mover.delta;
				var mvPrior = typeof payload.top_mover.views === 'number' ? payload.top_mover.views - mvD : 0;
				var mv = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
				mv.appendChild( el( 'span', {
					text:  payload.top_mover.path,
					style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
				} ) );
				mv.appendChild( el( 'span', {
					text:  deltaText( mvD ),
					style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;color:' + deltaColor( mvD, mvD, relOf( mvD, mvPrior ), false ) + ';'
				} ) );
				stats.appendChild( mv );
			}

			// bot_pct is null (not 0) when there was nothing to divide by —
			// "no data" is not "0% bots", so omit the row rather than claim a
			// clean feed we never measured.
			if ( payload.bot_pct !== null && typeof payload.bot_pct !== 'undefined' ) {
				stats.appendChild( statRow(
					'Bot share',
					payload.bot_pct + '%',
					// Not an alarm — the beacon already excludes bots from the
					// human class. This is a data-quality read, so it only tints
					// once it's high enough to be worth a glance.
					payload.bot_pct >= 50 ? 'color:#d29922;' : ''
				) );
			}

			// Prefer the additive top_paths list. An older cached payload
			// without that key falls back to the original single top_path row.
			if ( ! ( payload.top_paths && payload.top_paths.length ) && payload.top_path && payload.top_path.path ) {
				var top = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
				top.appendChild( el( 'span', {
					text:  payload.top_path.path,
					title: payload.top_path.path,
					style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
				} ) );
				top.appendChild( el( 'span', {
					text:  String( payload.top_path.views ),
					style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;'
				} ) );
				stats.appendChild( top );
			}

			if ( stats.childNodes.length ) {
				body.appendChild( stats );
			}

			if ( payload.top_paths && payload.top_paths.length ) {
				var pages = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );
				pages.appendChild( el( 'div', {
					text:  'Top pages',
					style: 'font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));margin-bottom:2px;'
				} ) );
				payload.top_paths.forEach( function( pg ) {
					if ( ! pg || ! pg.path ) { return; }
					var prow = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
					prow.appendChild( el( 'span', {
						text:  pg.path,
						title: pg.path,
						style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
					} ) );
					prow.appendChild( el( 'span', {
						text:  String( pg.views ),
						style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;'
					} ) );
					pages.appendChild( prow );
				} );
				body.appendChild( pages );
			}

			// ── v9.57.0: top sources ──
		}

		function fail() {
			body.textContent = '';
			body.appendChild( el( 'div', {
				text: 'Views unavailable',
				style: 'font-size:12px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));'
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
				style: 'display:inline-flex;align-items:center;min-height:24px;margin-top:8px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;'
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
