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
		// v9.53.0: title carries the honesty explainers (what `confidence`
		// actually measures; that advisories are not faults) and the hover
		// text for ellipsised rows. Without this branch every title: passed
		// to el() was silently discarded — the explainers never rendered.
		if ( opts.title != null ) { node.title = opts.title; }
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

		// ── v9.53.0: WHICH checks failed ──
		// "11/11" answers "is anything wrong". This answers "what". The server
		// sends them already ranked count-desc with the advisory tier excluded
		// (sn_health_flagged_checks), capped at 4 with the remainder counted —
		// the card is a glance, not the Health tab.
		var flagged = summary.flagged || [];
		if ( flagged.length ) {
			var list = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.12);' } );
			flagged.forEach( function( f ) {
				var row = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
				row.appendChild( el( 'span', {
					text:  String( f.label ),
					title: String( f.label ),
					style: 'opacity:.7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
				} ) );
				row.appendChild( el( 'span', {
					text:  String( f.count ),
					style: 'font-variant-numeric:tabular-nums;font-weight:600;color:#d29922;flex:0 0 auto;'
				} ) );
				list.appendChild( row );
			} );
			if ( summary.flagged_more > 0 ) {
				list.appendChild( el( 'div', {
					text:  '+' + summary.flagged_more + ' more',
					style: 'font-size:10px;opacity:.45;margin-top:2px;'
				} ) );
			}
			wrap.appendChild( list );
		}

		// Advisories are reported apart from faults, never folded in:
		// external_links / link_opportunities carry findings by nature, so
		// counting them as problems would make a healthy site read as alarming.
		if ( summary.advisory_total > 0 ) {
			wrap.appendChild( el( 'div', {
				text:  summary.advisory_total + ' advisories (not faults)',
				title: 'Advisory checks — external links and link opportunities — always carry findings. They are informational, not problems.',
				style: 'font-size:10px;opacity:.45;margin-top:6px;'
			} ) );
		}

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
