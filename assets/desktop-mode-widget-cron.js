/**
 * Signal & Noise Tools — desktop-mode "SN Cron" widget.
 *
 * Answers "is the site awake?" — the one question the desktop could not
 * previously answer. Scheduled-event count, how many are ours, and whether any
 * are ORPHANED (registered but with no handler, so they fire into nothing).
 *
 * MOUNT CONTRACT: assigned to window.openStationWidgets[ id ] — see
 * desktop-mode-widget-views.js for the full note on why this is the right path
 * for a PHP-declared widget.
 *
 * Data: window.snDesktopData.cronSummary — localized, one accessor call, no
 * REST round trip. Shape { total, sn_count, orphans }.
 *
 * ABSENT IS NOT ZERO. The PHP side sends array() when
 * snt_cron_summary_for_localize() does not exist, which arrives as an empty
 * array/object with no `total` key. That is "we never looked", and it renders
 * as such — a site with genuinely 0 scheduled events is a different fact and
 * says "0 events scheduled".
 *
 * @since plugin v11.29.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	// OpenStation rename compat — a SELF-SUFFICIENT alias, not order-dependent
	// on the external prelude. Same merge-don't-clobber shape as the sibling
	// widgets; see assets/desktop-mode-widget-health.js for the full rationale.
	var __osWidgets = window.openStationWidgets || window.desktopModeWidgets || {};
	if ( window.desktopModeWidgets && window.desktopModeWidgets !== __osWidgets ) {
		for ( var __osKey in window.desktopModeWidgets ) {
			if ( ! ( __osKey in __osWidgets ) ) { __osWidgets[ __osKey ] = window.desktopModeWidgets[ __osKey ]; }
		}
	}
	window.openStationWidgets  = __osWidgets;
	window.desktopModeWidgets  = __osWidgets;

	var data    = window.snDesktopData || {};
	var cronUrl = ( data.pages && data.pages.cron ) || '';

	var OK_FG    = '#3fb950';
	var WARN_FG  = '#d29922';
	var HAIRLINE = 'rgba(255,255,255,0.12)';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.text ) { node.textContent = opts.text; }
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.href ) { node.setAttribute( 'href', opts.href ); }
		if ( opts.title ) { node.setAttribute( 'title', opts.title ); }
		return node;
	}

	/**
	 * wp_localize_script casts only TOP-LEVEL scalars to strings; nested arrays
	 * pass through untouched (WP_Scripts::localize() skips non-scalars), so
	 * these arrive as real numbers today. Coerced anyway: if the payload ever
	 * moves to a top-level key, "0" is a truthy string and the orphan check
	 * would silently invert.
	 */
	function num( v ) {
		var n = Number( v );
		return isNaN( n ) ? 0 : n;
	}

	window.openStationWidgets['sn-cron'] = function( container ) {
		var wrap    = el( 'div', { style: 'padding:10px 12px;' } );
		var summary = data.cronSummary;
		var measured = !! ( summary && Object.prototype.hasOwnProperty.call( summary, 'total' ) );

		if ( ! measured ) {
			// Honest empty state — NOT a synthetic "0 events, all healthy".
			wrap.appendChild( el( 'div', {
				text:  'Cron not measured',
				style: 'font-size:12px;font-weight:600;margin-bottom:4px;'
			} ) );
			wrap.appendChild( el( 'div', {
				text:  'The cron module is not available on this install.',
				style: 'font-size:11px;opacity:.6;'
			} ) );
			container.appendChild( wrap );
			return function teardown() {
				if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
			};
		}

		var total   = num( summary.total );
		var ours    = num( summary.sn_count );
		var orphans = num( summary.orphans );

		// An orphan is the only thing here that asks for action: an event that
		// fires into nothing. Count alone is not a verdict, so the dot tracks
		// orphans, not total.
		var row = el( 'div', { style: 'display:flex;align-items:center;gap:8px;' } );
		row.appendChild( el( 'span', {
			style: 'width:9px;height:9px;border-radius:50%;flex:0 0 auto;background:' +
				( orphans > 0 ? WARN_FG : OK_FG ) + ';'
		} ) );
		row.appendChild( el( 'span', {
			text:  total + ( 1 === total ? ' event scheduled' : ' events scheduled' ),
			style: 'font-size:14px;font-weight:600;font-variant-numeric:tabular-nums;'
		} ) );
		wrap.appendChild( row );

		var list = el( 'div', {
			style: 'margin-top:8px;padding-top:8px;border-top:1px solid ' + HAIRLINE + ';'
		} );

		function detail( label, value, colour ) {
			var r = el( 'div', {
				style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;'
			} );
			r.appendChild( el( 'span', { text: label, style: 'opacity:.7;' } ) );
			r.appendChild( el( 'span', {
				text:  String( value ),
				style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;' +
					( colour ? 'color:' + colour + ';' : '' )
			} ) );
			return r;
		}

		list.appendChild( detail( 'Signal & Noise', ours ) );
		list.appendChild( detail( 'Orphaned', orphans, orphans > 0 ? WARN_FG : '' ) );
		wrap.appendChild( list );

		if ( orphans > 0 ) {
			wrap.appendChild( el( 'div', {
				text:  orphans === 1 ? 'An event is registered with no handler.' : 'Events are registered with no handler.',
				style: 'font-size:10px;opacity:.55;margin-top:6px;'
			} ) );
		}

		if ( cronUrl ) {
			wrap.appendChild( el( 'a', {
				href:  cronUrl,
				text:  'Cron events →',
				style: 'display:inline-block;margin-top:8px;font-size:11px;color:var(--os-window-link-accent, #4a9eff);text-decoration:none;opacity:.75;'
			} ) );
		}

		container.appendChild( wrap );
		return function teardown() {
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};
	window.desktopModeWidgets['sn-cron'] = window.openStationWidgets['sn-cron'];
}() );
