/**
 * Signal & Noise Tools — desktop-mode "SN Pulse" widget.
 *
 * The command-center tile: three rows — views, uptime, content health — in
 * one card, each deep-linking to its tab. Sorts first (sort=40) so it reads
 * top-of-column.
 *
 * MOUNT CONTRACT: assigned to window.desktopModeWidgets[ id ] — see
 * desktop-mode-widget-views.js for the full note.
 *
 * Data: uptime + health come from the localize (cheap durable option reads).
 * Views reuses the SAME site-views REST endpoint as the Site Views widget —
 * both mounting at once means two calls, which the endpoint's 15-minute
 * transient collapses server-side.
 *
 * GRACEFUL ROWS: a null source omits its row entirely rather than rendering
 * a misleading zero or a fake "up".
 *
 * @since plugin v9.52.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	window.desktopModeWidgets = window.desktopModeWidgets || {};

	var data  = window.snDesktopData || {};
	var pages = data.pages || {};

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		return node;
	}

	var ROW = 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:4px 0;font-size:12px;text-decoration:none;color:inherit;';
	var KEY = 'opacity:.6;';
	var VAL = 'font-weight:600;font-variant-numeric:tabular-nums;';

	function row( label, value, href, valueStyle ) {
		var node = href ? el( 'a', { href: href, style: ROW } ) : el( 'div', { style: ROW } );
		node.appendChild( el( 'span', { text: label, style: KEY } ) );
		node.appendChild( el( 'span', { text: value, style: VAL + ( valueStyle || '' ) } ) );
		return node;
	}

	var UPTIME_TEXT = { ok: 'Up', warn: 'Attention', alert: 'Down' };
	var UPTIME_COLOR = { ok: '#3fb950', warn: '#d29922', alert: '#c9503f' };

	window.desktopModeWidgets['sn-pulse'] = function( container, ctx ) {
		var aborted = false;
		var ctrl    = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;

		var wrap = el( 'div', { style: 'padding:10px 12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;' } );

		// ── Row 1: views (async) ──
		var viewsRow = row( 'Views · 14d', '…', pages.analytics || '' );
		wrap.appendChild( viewsRow );

		// ── Row 2: uptime (localized; omitted when unconfigured) ──
		var uptime = data.uptimeSummary;
		if ( uptime && uptime.level ) {
			wrap.appendChild( row(
				'Uptime',
				UPTIME_TEXT[ uptime.level ] || String( uptime.status || uptime.level ),
				pages.dashboard || '',
				'color:' + ( UPTIME_COLOR[ uptime.level ] || 'inherit' ) + ';'
			) );
		}

		// ── Row 3: health (localized; honest "not scanned" state) ──
		var health = data.healthSummary;
		if ( health ) {
			wrap.appendChild( row(
				'Health',
				health.passed + '/' + health.total,
				pages.dashboard || '',
				'color:' + ( health.all_passed ? '#3fb950' : '#d29922' ) + ';'
			) );
		} else {
			wrap.appendChild( row( 'Health', 'Not scanned', pages.dashboard || '', 'opacity:.6;font-weight:400;' ) );
		}

		container.appendChild( wrap );

		function setViews( text, style ) {
			var val = viewsRow.lastChild;
			if ( val ) {
				val.textContent = text;
				if ( style ) { val.setAttribute( 'style', VAL + style ); }
			}
		}

		if ( window.wp && window.wp.apiFetch ) {
			window.wp.apiFetch( {
				path: '/signal-noise/v1/desktop/site-views',
				signal: ctrl ? ctrl.signal : undefined
			} ).then( function( res ) {
				if ( aborted ) { return; }
				res = res || {};
				var total = typeof res.total === 'number' ? res.total : 0;
				var d     = res.delta_pct;
				if ( d === null || typeof d === 'undefined' ) {
					setViews( String( total ) );
				} else {
					setViews(
						String( total ) + '  ' + ( d >= 0 ? '▲' : '▼' ) + Math.abs( d ) + '%',
						'color:' + ( d >= 0 ? '#3fb950' : '#c9503f' ) + ';'
					);
				}
			} ).catch( function() {
				if ( aborted ) { return; }
				setViews( '—', 'opacity:.6;font-weight:400;' );
			} );
		} else {
			setViews( '—', 'opacity:.6;font-weight:400;' );
		}

		return function teardown() {
			aborted = true;
			if ( ctrl ) { ctrl.abort(); }
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
