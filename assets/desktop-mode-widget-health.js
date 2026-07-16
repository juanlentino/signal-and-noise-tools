/**
 * Signal & Noise Tools — desktop-mode "SN Health" widget.
 *
 * Content-health checks at a glance: pass/fail ratio, a status dot, and how
 * stale the last scan is.
 *
 * MOUNT CONTRACT: assigned to window.desktopModeWidgets[ id ] — see
 * desktop-mode-widget-views.js for the full note on why this is the right
 * path for a PHP-declared widget.
 *
 * Data: window.snDesktopData.healthSummary (localized — one durable option
 * read). NULL when no scan has ever run, and this widget says exactly that
 * rather than rendering a 0/0 green all-clear.
 *
 * @since plugin v9.52.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	window.desktopModeWidgets = window.desktopModeWidgets || {};

	var data       = window.snDesktopData || {};
	var healthUrl  = ( data.pages && data.pages.dashboard ) || '';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		return node;
	}

	/**
	 * "3d ago" from scanned_at. sn_health_run_scan() stores it as time() — a
	 * UNIX timestamp in SECONDS, not a MySQL datetime string — so multiply to
	 * ms rather than Date.parse() it. Best-effort; never throws.
	 */
	function ago( scannedAt ) {
		var secs = Number( scannedAt );
		if ( ! secs || isNaN( secs ) || secs <= 0 ) { return ''; }
		var t = secs * 1000;
		var mins = Math.max( 0, Math.floor( ( Date.now() - t ) / 60000 ) );
		if ( mins < 60 ) { return mins + 'm ago'; }
		var hrs = Math.floor( mins / 60 );
		if ( hrs < 24 ) { return hrs + 'h ago'; }
		return Math.floor( hrs / 24 ) + 'd ago';
	}

	window.desktopModeWidgets['sn-health'] = function( container, ctx ) {
		var wrap = el( 'div', { style: 'padding:10px 12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;' } );
		var summary = data.healthSummary;

		if ( ! summary ) {
			// Honest empty state — NOT a synthetic pass.
			wrap.appendChild( el( 'div', {
				text: 'No health scan yet',
				style: 'font-size:12px;font-weight:600;margin-bottom:4px;'
			} ) );
			wrap.appendChild( el( 'div', {
				text: 'Run a Content-Health scan to see results here.',
				style: 'font-size:11px;opacity:.6;'
			} ) );
			if ( healthUrl ) {
				wrap.appendChild( el( 'a', {
					href: healthUrl,
					text: 'Run a scan →',
					style: 'display:inline-block;margin-top:8px;font-size:11px;text-decoration:none;opacity:.75;'
				} ) );
			}
			container.appendChild( wrap );
			return function teardown() {
				if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
			};
		}

		var row = el( 'div', { style: 'display:flex;align-items:center;gap:8px;' } );
		row.appendChild( el( 'span', {
			style: 'width:9px;height:9px;border-radius:50%;flex:0 0 auto;background:' +
				( summary.all_passed ? '#3fb950' : '#d29922' ) + ';'
		} ) );
		row.appendChild( el( 'span', {
			text: summary.passed + '/' + summary.total + ' checks passed',
			style: 'font-size:14px;font-weight:600;font-variant-numeric:tabular-nums;'
		} ) );
		wrap.appendChild( row );

		var age = ago( summary.scanned_at );
		wrap.appendChild( el( 'div', {
			text: age ? 'Last scanned ' + age : 'Last scan time unknown',
			style: 'font-size:11px;opacity:.6;margin-top:4px;'
		} ) );

		if ( healthUrl ) {
			wrap.appendChild( el( 'a', {
				href: healthUrl,
				text: 'Open Health →',
				style: 'display:inline-block;margin-top:8px;font-size:11px;text-decoration:none;opacity:.75;'
			} ) );
		}

		container.appendChild( wrap );

		return function teardown() {
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
