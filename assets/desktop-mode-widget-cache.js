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
	var HAIRLINE = 'var(--os-ui-color-border, rgba(255,255,255,0.12))';

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
				style: 'font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));'
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
				style: 'font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.55));margin-top:2px;'
			} ) );
		}
		// 15.8.2: WHAT the verdict covers. "Edge fresh" reads as site-wide; the
		// probe fetches the post's own URL only (archive, sitemap and feed are
		// purged, not probed). The ability says to read probe_scope before
		// `last`; the card now says it too. A fact, not a tally: the v13.87.3
		// ruling against standing counts on this card stands.
		if ( 'permalink' === summary.probe_scope ) {
			wrap.appendChild( el( 'div', {
				text:  'Verdict covers the post\'s own URL',
				title: 'The post-save probe fetches the permalink. Archive pages, the sitemap and the feed are purged but not probed.',
				style: 'font-size:10px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.45));margin-top:4px;'
			} ) );
		}

		var list = el( 'div', {
			style: 'margin-top:8px;padding-top:8px;border-top:1px solid ' + HAIRLINE + ';'
		} );

		function detail( label, value, colour ) {
			var r = el( 'div', {
				style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;'
			} );
			r.appendChild( el( 'span', { text: label, style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));' } ) );
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
			list.appendChild( detail( 'Edits served stale', stale ) );
			showed = true;
		}
		if ( escalated > 0 ) {
			list.appendChild( detail( 'Zone purges forced', escalated ) );
			showed = true;
		}
		// Nothing to report is itself the report; an empty hairline rule reads
		// as a section that failed to load.
		if ( ! showed ) {
			list.style.display = 'none';
		}
		if ( showed ) {
			var history = el( 'details', { style: 'font-size:11px;margin-top:10px;' } );
			history.appendChild( el( 'summary', { text: 'Past post-save checks', style: 'cursor:pointer;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));' } ) );
			history.appendChild( el( 'p', { text: 'Historical results, not the current cache state. Purging does not reset this history.', style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));line-height:1.4;' } ) );
			history.appendChild( list );
			wrap.appendChild( history );
		}

		var cloudflareUrl = ( window.snDesktopData && window.snDesktopData.pages && window.snDesktopData.pages.cloudflare ) || '';
		if ( cloudflareUrl ) {
			wrap.appendChild( el( 'a', {
				href:  cloudflareUrl,
				text:  'Open Cloudflare →',
				style: 'display:inline-flex;align-items:center;min-height:24px;margin-top:10px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;'
			} ) );
		}

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
		var lastRunMs = 0;

		function refresh() {
			window.clearTimeout( timer );
			if ( stopped ) { return; }
			if ( busy ) { again = true; return; }
			if ( document.hidden || ! window.sntAbilityRun ) {
				timer = window.setTimeout( refresh, 60000 );
				return;
			}
			busy = true;
			lastRunMs = Date.now();
			Promise.resolve().then( function() {
				return window.sntAbilityRun( 'cache-freshness', undefined, { silent: true } );
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
				var oldHistory = container.querySelector( 'details' );
				var historyOpen = oldHistory && oldHistory.open;
				var historyFocused = oldHistory && oldHistory.contains( document.activeElement );
				unpaint();
				unpaint = paint( container, summary );
				var newHistory = container.querySelector( 'details' );
				if ( newHistory ) {
					newHistory.open = !! historyOpen;
					if ( historyFocused ) { newHistory.querySelector( 'summary' ).focus(); }
				}
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
		// Recipe 2 (#1603): a reveal refreshes only when the last run is older
		// than the poll, so a quick tab flip costs no call; the hidden branch
		// above already parks the timer while nobody is looking.
		function onVisibilityChange() {
			if ( document.hidden || Date.now() - lastRunMs < 60000 ) { return; }
			refresh();
		}
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		refresh();
		return function teardown() {
			stopped = true;
			window.clearTimeout( timer );
			document.removeEventListener( 'snt-cache-purged', refresh );
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
			unpaint();
			if ( errorNote ) { errorNote.remove(); }
		};
	};
	window.desktopModeWidgets['sn-cache'] = window.openStationWidgets['sn-cache'];
}() );
