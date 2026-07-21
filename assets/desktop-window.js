/**
 * Signal & Noise Tools — "S&N Monitor" native Desktop Mode window.
 *
 * MOUNT CONTRACT (upstream WordPress/desktop-mode trunk @ 0.9.5,
 * docs/examples/native-windows.md): the shell clones the registered
 * template into the window body BEFORE invoking
 * window.desktopModeNativeWindows['sn-monitor'](body) — render is
 * enhancement against guaranteed mount points, and the return value is
 * the teardown the shell calls on window close.
 *
 * Panes reuse the widget data plumbing verbatim (see the sibling
 * desktop-mode-widget-*.js files for each contract):
 *   Analytics — GET signal-noise/v1/desktop/site-views via wp.apiFetch.
 *   Health    — window.snDesktopData.healthSummary (localized).
 *   Uptime    — sntAbilityRun('uptime-status', {detail:true}), 2-min poll.
 *   Deploy    — window.snDesktopData.theme / .plugin.
 *
 * Honesty rule carried over from the widgets: null data renders an honest
 * empty state ("not scanned yet", "not configured"), never a fabricated
 * zero or a fake pass.
 *
 * @since plugin v9.76.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var LEVEL_COLOR = { ok: '#3fb950', warn: '#d29922', alert: '#ff9d94' };
	var LEVEL_TEXT  = { ok: 'Up', warn: 'Attention', alert: 'Down' };
	var STATE_GLYPH = {
		current: { label: 'current', color: '#3fb950' },
		behind:  { label: 'update available', color: '#d29922' },
		unknown: { label: 'unknown', color: '#8b949e' },
	};

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.title != null ) { node.title = opts.title; }
		return node;
	}

	function setText( body, role, text ) {
		var node = body.querySelector( '[data-role="' + role + '"]' );
		if ( node ) { node.textContent = text; }
		return node;
	}

	function mount( body, role ) {
		return body.querySelector( '[data-role="' + role + '"]' );
	}

	/* ---- Analytics (main tab) ------------------------------------------ */

	function renderSeries( container, days ) {
		container.textContent = '';
		var max = 0;
		days.forEach( function ( d ) { max = Math.max( max, Number( d.views ) || 0 ); } );
		days.forEach( function ( d ) {
			var v   = Number( d.views ) || 0;
			var bar = el( 'div', { className: 'sn-monitor-bar', title: ( d.date || '' ) + ': ' + v + ' views' } );
			// 4% floor so a zero day still paints a readable tick.
			bar.style.height = ( max > 0 ? Math.max( 4, Math.round( ( v / max ) * 100 ) ) : 4 ) + '%';
			container.appendChild( bar );
		} );
	}

	function renderAnalytics( body ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			setText( body, 'views-note', 'wp.apiFetch is unavailable — cannot load analytics.' );
			return;
		}
		window.wp.apiFetch( { path: '/signal-noise/v1/desktop/site-views' } ).then( function ( payload ) {
			payload = payload || {};
			if ( ! payload.days || ! payload.days.length ) {
				setText( body, 'views-note', 'No analytics rows yet — the daily rollup has not landed.' );
				return;
			}
			setText( body, 'views-total', String( payload.total != null ? payload.total : '—' ) );
			setText( body, 'views-visits', String( payload.visits != null ? payload.visits : '—' ) );
			var delta = payload.delta_pct;
			setText( body, 'views-delta', delta == null ? '—' : ( delta > 0 ? '+' : '' ) + delta + '%' );

			var series = mount( body, 'views-series' );
			if ( series ) { renderSeries( series, payload.days ); }

			var bits = [];
			if ( payload.bot_pct != null ) { bits.push( payload.bot_pct + '% bot share' ); }
			if ( payload.top_path && payload.top_path.path ) { bits.push( 'top: ' + payload.top_path.path ); }
			if ( payload.top_sources && payload.top_sources.length && payload.top_sources[ 0 ].value ) {
				bits.push( 'via ' + payload.top_sources[ 0 ].value );
			}
			setText( body, 'views-note', bits.length ? bits.join( ' · ' ) : '' );
		} ).catch( function () {
			setText( body, 'views-note', 'Could not reach the analytics endpoint.' );
		} );
	}

	/* ---- Health --------------------------------------------------------- */

	function renderHealth( body ) {
		var data    = window.snDesktopData || {};
		var summary = data.healthSummary;
		if ( ! summary ) {
			setText( body, 'health-headline', 'Not scanned yet' );
			setText( body, 'health-note', 'Run a health scan from the Monitoring tab to populate this pane.' );
			return;
		}
		setText( body, 'health-headline', summary.passed + '/' + summary.total + ' checks passed' );
		var list = mount( body, 'health-list' );
		if ( list ) {
			list.textContent = '';
			( summary.flagged || [] ).forEach( function ( f ) {
				var row = el( 'li', { className: 'sn-monitor-row' } );
				row.appendChild( el( 'span', { text: String( f.label || f.key || '' ), className: 'sn-monitor-row-label' } ) );
				row.appendChild( el( 'span', { text: String( f.count != null ? f.count : '' ), className: 'sn-monitor-row-count' } ) );
				list.appendChild( row );
			} );
			if ( summary.flagged_more > 0 ) {
				list.appendChild( el( 'li', { className: 'sn-monitor-row sn-monitor-row-more', text: '+' + summary.flagged_more + ' more' } ) );
			}
		}
		setText( body, 'health-note', ( summary.flagged || [] ).length ? '' : 'Nothing flagged.' );
	}

	/* ---- Uptime --------------------------------------------------------- */

	function pct( v ) { return v == null ? '—' : v + '%'; }

	function renderUptimeRows( body, res ) {
		res = res || {};
		if ( res.configured === false ) {
			setText( body, 'uptime-note', 'Better Stack is not configured — no monitors to show.' );
			return;
		}
		var rows = res.rows || [];
		if ( ! rows.length ) {
			setText( body, 'uptime-note', res.error ? String( res.error ) : 'No monitors reported.' );
			return;
		}
		var wrap = mount( body, 'uptime-rows' );
		if ( ! wrap ) { return; }
		wrap.textContent = '';
		rows.forEach( function ( r ) {
			var row   = el( 'div', { className: 'sn-monitor-row' } );
			var level = LEVEL_COLOR[ r.level ] ? r.level : 'warn';
			var dot   = el( 'span', { className: 'sn-monitor-dot', title: LEVEL_TEXT[ level ] } );
			dot.style.background = LEVEL_COLOR[ level ];
			row.appendChild( dot );
			row.appendChild( el( 'span', { text: String( r.name || '' ), className: 'sn-monitor-row-label' } ) );
			var stats = [ pct( r.availability ) + ' 30d' ];
			if ( r.response_ms != null ) { stats.push( r.response_ms + 'ms' ); }
			row.appendChild( el( 'span', { text: stats.join( ' · ' ), className: 'sn-monitor-row-count' } ) );
			wrap.appendChild( row );
		} );
		setText( body, 'uptime-note', res.incidents > 0 ? res.incidents + ' incident(s) in the last 30 days.' : '' );
	}

	function renderUptime( body ) {
		if ( typeof window.sntAbilityRun !== 'function' ) {
			setText( body, 'uptime-note', 'The abilities client is unavailable — cannot query monitors.' );
			return;
		}
		window.sntAbilityRun( 'uptime-status', { detail: true } ).then( function ( res ) {
			renderUptimeRows( body, res );
		} ).catch( function () {
			setText( body, 'uptime-note', 'Could not reach the uptime ability.' );
		} );
	}

	/* ---- Deploy --------------------------------------------------------- */

	function renderDeploy( body ) {
		var data = window.snDesktopData || {};
		var wrap = mount( body, 'deploy-cards' );
		if ( ! wrap ) { return; }
		wrap.textContent = '';
		var reasons = [];
		[ 'theme', 'plugin' ].forEach( function ( pkg ) {
			var info  = data[ pkg ] || {};
			var glyph = STATE_GLYPH[ info.state ] || STATE_GLYPH.unknown;
			var row   = el( 'div', { className: 'sn-monitor-row' } );
			row.appendChild( el( 'span', { text: 'theme' === pkg ? 'Theme' : 'Plugin', className: 'sn-monitor-row-label' } ) );
			row.appendChild( el( 'span', { text: info.current || '—', className: 'sn-monitor-row-version' } ) );
			var state = el( 'span', { text: glyph.label, className: 'sn-monitor-row-count' } );
			state.style.color = glyph.color;
			if ( info.reason ) { state.title = info.reason; }
			row.appendChild( state );
			wrap.appendChild( row );
			if ( info.reason && reasons.indexOf( info.reason ) === -1 ) { reasons.push( info.reason ); }
		} );
		setText( body, 'deploy-note', reasons.join( ' ' ) );
	}

	/* ---- The window ------------------------------------------------------ */

	window.desktopModeNativeWindows = window.desktopModeNativeWindows || {};
	window.desktopModeNativeWindows[ 'sn-monitor' ] = function ( body ) {
		renderAnalytics( body );
		renderHealth( body );
		renderUptime( body );
		renderDeploy( body );

		// Monitors don't flap by the second (statuses ride a 90s server
		// cache), so a 2-minute poll matches the uptime widget's cadence.
		var uptimeTimer = window.setInterval( function () { renderUptime( body ); }, 2 * 60 * 1000 );

		return function () {
			window.clearInterval( uptimeTimer );
		};
	};
} )();
