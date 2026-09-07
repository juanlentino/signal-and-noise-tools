/**
 * Signal & Noise Tools — desktop-mode "SN Cache" widget.
 *
 * Quick Actions could purge the edge but nothing reported whether a purge had
 * actually WORKED, so you purged blind. This is the verdict half.
 *
 * MOUNT CONTRACT: assigned to window.openStationWidgets[ id ] — see
 * desktop-mode-widget-views.js for the full note.
 *
 * Data: window.snDesktopData.cacheFreshness, from snt_cf_freshness_summary().
 * Shape { last, last_time, total, stale, escalated }, or NULL.
 *
 * NULL IS NOT "ALL FRESH". The verification log records nothing when a probe is
 * unreadable — an outage is a gap in evidence, not a verdict — so an empty log
 * means verification has never run. Rendering that as a green edge would be the
 * exact green-readout-over-a-stale-page failure the module exists to catch
 * (2026-08-15: three purges fired, the edge served a 27-hour-old render for
 * fifty minutes, and every readout stayed green).
 *
 * @since plugin v11.29.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	// OpenStation rename compat — self-sufficient alias, merge don't clobber.
	var __osWidgets = window.openStationWidgets || window.desktopModeWidgets || {};
	if ( window.desktopModeWidgets && window.desktopModeWidgets !== __osWidgets ) {
		for ( var __osKey in window.desktopModeWidgets ) {
			if ( ! ( __osKey in __osWidgets ) ) { __osWidgets[ __osKey ] = window.desktopModeWidgets[ __osKey ]; }
		}
	}
	window.openStationWidgets = __osWidgets;
	window.desktopModeWidgets = __osWidgets;

	var data     = window.snDesktopData || {};
	var OK_FG    = '#3fb950';
	var WARN_FG  = '#d29922';
	var ERR_FG   = '#f85149';
	var HAIRLINE = 'rgba(255,255,255,0.12)';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.text ) { node.textContent = opts.text; }
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.title ) { node.setAttribute( 'title', opts.title ); }
		return node;
	}

	function num( v ) {
		var n = Number( v );
		return isNaN( n ) ? 0 : n;
	}

	/** "3d ago" from a UNIX timestamp in SECONDS. Best-effort; never throws. */
	function ago( secs ) {
		var t = num( secs );
		if ( t <= 0 ) { return ''; }
		var mins = Math.max( 0, Math.floor( ( Date.now() - t * 1000 ) / 60000 ) );
		if ( mins < 60 ) { return mins + 'm ago'; }
		var hrs = Math.floor( mins / 60 );
		if ( hrs < 24 ) { return hrs + 'h ago'; }
		return Math.floor( hrs / 24 ) + 'd ago';
	}

	function paint( container, summary ) {
		var wrap    = el( 'div', { style: 'padding:10px 12px;' } );

		if ( ! summary || ! summary.last ) {
			// Honest empty state. NOT a green edge.
			wrap.appendChild( el( 'div', {
				text:  'No purge verified yet',
				style: 'font-size:12px;font-weight:600;margin-bottom:4px;'
			} ) );
			wrap.appendChild( el( 'div', {
				text:  'Verification records a verdict after the next post purge.',
				style: 'font-size:11px;opacity:.6;'
			} ) );
			container.appendChild( wrap );
			return function teardown() {
				if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
			};
		}

		var last      = String( summary.last );
		var escalated = num( summary.escalated );
		var stale     = num( summary.stale );

		// The headline reflects the current verdict; older post-save failures
		// remain historical diagnostics below, never a failed current purge.
		var dot = OK_FG;
		if ( 'stale' === last ) { dot = ERR_FG; }
		else if ( 'unknown' === last || 'pending' === last ) { dot = WARN_FG; }

		// v13.87.2: the words come from PHP, one producer for both surfaces.
		// This widget and the Classic Admin cell used to phrase the same verdict
		// differently — "Edge served a stale render" beside "still stale after 4
		// mins" — because each built its own sentence. Owner ruling: the two
		// must say the same thing about the cache, from the authoritative
		// record. The local fallbacks below only cover a payload from an older
		// plugin build.
		var headline = summary.headline
			|| ( 'stale' === last
				? 'Edge served a stale render'
				: ( 'unknown' === last ? 'Last verdict unrecognised' : 'Edge fresh' ) );

		var row = el( 'div', { style: 'display:flex;align-items:center;gap:8px;' } );
		row.appendChild( el( 'span', {
			style: 'width:9px;height:9px;border-radius:50%;flex:0 0 auto;background:' + dot + ';'
		} ) );
		row.appendChild( el( 'span', {
			text:  headline,
			style: 'font-size:14px;font-weight:600;'
		} ) );
		wrap.appendChild( row );

		var when = summary.phrase || ago( summary.last_time );
		if ( when ) {
			wrap.appendChild( el( 'div', {
				text:  when,
				style: 'font-size:11px;opacity:.55;margin-top:2px;'
			} ) );
		}

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

		// v13.87.3 — NO STANDING TALLY. This rendered "Verdicts recorded 20 /
		// Stale N" on every paint, and that construct was already ruled out on
		// the sibling surface: the Classic Admin cell answers ONE question about
		// ONE event, and v13.70.1 removed its running count for the same reason
		// ("If it's fresh, it is fresh. If it isn't, it shouldn't say.").
		//
		// The tally survived here and caused every misreading of 2026-09-02/03.
		// It climbed when you purged, then fell when you purged, and a falling
		// count read as progress when it was only a bounded buffer flushing
		// history. Labelling it "post-save" made the source legible without
		// making the NUMBER any more useful: "3 checks performed" is not
		// something anyone acts on at a glance.
		//
		// So: bad news only. A stale edit is a real fact worth surfacing the
		// moment it exists; zero of them is not a fact worth a row. The full
		// series still has two homes for anyone who wants it — the Cloudflare
		// tab renders the rows, and signal-noise/purge-verification-log returns
		// them with counts and per-source splits.
		var showed = false;
		if ( stale > 0 ) {
			list.appendChild( detail( 'Edits served stale', stale, ERR_FG ) );
			showed = true;
		}
		if ( escalated > 0 ) {
			list.appendChild( detail( 'Zone purges forced', escalated, WARN_FG ) );
			showed = true;
		}
		// Nothing to report is itself the report; an empty hairline rule reads
		// as a section that failed to load.
		if ( ! showed ) {
			list.style.display = 'none';
		}
		if ( showed ) {
			wrap.appendChild( el( 'div', { text: 'Recent post-save checks', style: 'font-size:11px;opacity:.7;margin-top:10px;', title: 'Historical checks, not caches waiting to be cleared. Manual purges do not reset these counts.' } ) );
		}
		wrap.appendChild( list );

		container.appendChild( wrap );
		return function teardown() {
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};
	window.openStationWidgets['sn-cache'] = function( container ) {
		var stopped = false;
		var busy = false;
		var again = false;
		var timer = 0;
		var summary = data.cacheFreshness;
		var unpaint = paint( container, summary );
		var errorNote = null;

		function refresh() {
			window.clearTimeout( timer );
			if ( stopped ) { return; }
			if ( busy ) { again = true; return; }
			if ( document.hidden || ! window.sntAbilityRun ) {
				timer = window.setTimeout( refresh, 60000 );
				return;
			}
			busy = true;
			Promise.resolve().then( function() {
				return window.sntAbilityRun( 'cache-freshness' );
			} ).then( function( result ) {
				if ( stopped ) { return; }
				if ( ! result || ! result.post_save || ! result.last ) { throw new Error( 'Invalid cache status' ); }
				summary = result.state === 'never_probed' ? null : Object.assign( {}, result, {
					total: result.post_save.probes,
					stale: result.post_save.stale,
					escalated: result.post_save.escalated
				} );
				data.cacheFreshness = summary;
				if ( errorNote ) { errorNote.remove(); errorNote = null; }
				unpaint();
				unpaint = paint( container, summary );
			} ).catch( function() {
				if ( stopped || errorNote ) { return; }
				errorNote = el( 'p', { text: 'Could not refresh cache status. Showing the last known result.', style: 'font-size:11px;color:#ff9d94;padding:0 12px;' } );
				errorNote.setAttribute( 'role', 'status' );
				container.appendChild( errorNote );
			} ).finally( function() {
				busy = false;
				if ( stopped ) { return; }
				timer = window.setTimeout( refresh, again ? 0 : ( summary && summary.last === 'pending' ? 15000 : 60000 ) );
				again = false;
			} );
		}
		document.addEventListener( 'snt-cache-purged', refresh );
		document.addEventListener( 'visibilitychange', refresh );
		refresh();
		return function teardown() {
			stopped = true;
			window.clearTimeout( timer );
			document.removeEventListener( 'snt-cache-purged', refresh );
			document.removeEventListener( 'visibilitychange', refresh );
			unpaint();
			if ( errorNote ) { errorNote.remove(); }
		};
	};
	window.desktopModeWidgets['sn-cache'] = window.openStationWidgets['sn-cache'];
}() );
