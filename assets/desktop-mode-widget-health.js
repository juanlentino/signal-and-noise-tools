/**
 * Signal & Noise Tools — desktop-mode "SN Systems" widget (id sn-health, kept
 * so the card keeps its place on a saved desktop).
 *
 * SN Health, SN Uptime and SN Cron in one card, and Quick Actions' "Clear DB
 * overrides" button. One line when everything is fine ("All systems normal"),
 * and the detail rows only for what is not: a monitor that is down, a check
 * with findings or one that could not run, an orphaned cron event.
 *
 * MOUNT CONTRACT: assigned to window.desktopModeWidgets[ id ] — see
 * desktop-mode-widget-views.js for the full note on why this is the right
 * path for a PHP-declared widget.
 *
 * DATA. Health and cron are localized (window.snDesktopData.healthSummary and
 * .cronSummary, each one cheap read; healthSummary is NULL when no scan has
 * ever run, never a 0/0 pass). Uptime is the signal-noise/uptime-status
 * ability, detail tier (statuses from a 90s server cache, availability and
 * response times from their own caches), fetched on mount and
 * every two minutes while the card is visible: it is the one reading here that
 * changes while a desktop sits open.
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

	var data      = window.snDesktopData || {};
	// 2026-10-05: edge, cron-day and cache rows (inc/desktop-mode-status-extra.php), owner only.
	var extra     = ( data.statusExtra && data.statusExtra.systems ) || {};
	var healthUrl = ( data.pages && ( data.pages.health || data.pages.dashboard ) ) || ''; // 15.8.2: Monitoring › Health, the leaf the reading lives on
	// Monitors don't flap by the second; the statuses ride a 90s server cache
	// anyway, so a 2-minute poll never outruns the data underneath it.
	var REFRESH_MS = 2 * 60 * 1000;
	var LIST_CAP   = 2; // rows a growing list shows before "+N more"
	var TOAST_MS   = 3500;
	// WP-Cron runs on a page load, so a job is routinely "due" for seconds; it
	// is late only past this.
	var LATE_S     = 600;
	// A purge's deferred verify lands about 75 s after it; past this, a purge
	// still "verifying" is not in progress any more, it is unverified.
	var VERIFY_S   = 15 * 60;
	var SKEW_S     = 5 * 60;
	// The AI provider's refusal for an empty credit balance: the check is
	// paused, not broken, and nothing on this site can fix it (2026-10-05).
	var AI_CREDIT  = /credit balance is too low/i;
	/*
	 * WHERE EACH SECTION IS FIXED. A section that puts anything in the headline
	 * links to the screen where it is dealt with, so a yellow line is never a
	 * dead end (owner, 2026-10-05). Keys of window.snDesktopData.pages, or a
	 * URL for a fix that lives off the site.
	 */
	var FIX = {
		Uptime: { href: 'https://uptime.betterstack.com/', text: 'Open Better Stack' },
		Health: { page: 'health', text: 'Open Health' },
		Cron:   { page: 'cron', text: 'Open Cron' },
		Edge:   { page: 'cloudflare', text: 'Open Cloudflare' },
		Cache:  { page: 'cloudflare', text: 'Open Cloudflare' },
	};
	var BILLING = { href: 'https://console.anthropic.com/settings/billing', text: 'Open Anthropic billing' };

	/*
	 * PALETTE. The widget card is FIXED DARK GLASS, not a themeable surface
	 * (rgba(20,20,22,.55) + blur, color #fff; still a literal at OpenStation
	 * 1.x), so everything here is light-on-dark and text inherits the card's
	 * white. OpenStation 1.1.5 declared the card's own token contract (#1603,
	 * --os-ui-color-*: surface, text, text-subtle, border, accent): HAIRLINE and
	 * the link read it, each with today's literal as the fallback. The green,
	 * amber and red status colors have no widget token and stay literal; the
	 * shell's --wpd-* body palette is themed to read on a surface that CAN go
	 * light, so it would put dark green on dark glass under a light theme.
	 * Danger text is #ff9d94, lightened from the #c9503f chart red: the same
	 * hue is legible as a 1px line but muddy as text on dark glass.
	 */
	var SURFACE       = 'rgba(255,255,255,0.06)';
	var SURFACE_HOVER = 'rgba(255,255,255,0.13)';
	var HAIRLINE      = 'var(--os-ui-color-border, rgba(255,255,255,0.14))';
	var SUBTLE        = 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));';
	var OK_FG         = '#3fb950';
	var WARN_FG       = '#d29922';
	var DANGER_FG     = '#ff9d94';
	var DANGER_BG     = 'rgba(201,80,63,0.16)';
	var DANGER_LINE   = 'rgba(201,80,63,0.45)';
	var OK_BG         = 'rgba(63,185,80,0.14)';
	var OK_LINE       = 'rgba(63,185,80,0.32)';
	var LEVEL_TEXT    = { ok: 'Up', warn: 'Attention', alert: 'Down' };

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

	function num( v ) {
		var n = Number( v );
		return isNaN( n ) ? 0 : n;
	}

	/**
	 * "3d ago" from scanned_at. sn_health_run_scan() stores it as time() — a
	 * UNIX timestamp in SECONDS, not a MySQL datetime string. Never throws.
	 */
	function ago( scannedAt ) {
		var secs = Number( scannedAt );
		if ( ! secs || isNaN( secs ) || secs <= 0 ) { return ''; }
		var mins = Math.max( 0, Math.floor( ( Date.now() - secs * 1000 ) / 60000 ) );
		if ( mins < 60 ) { return mins + 'm ago'; }
		var hrs = Math.floor( mins / 60 );
		if ( hrs < 24 ) { return hrs + 'h ago'; }
		return Math.floor( hrs / 24 ) + 'd ago';
	}

	/**
	 * What each source says, as rows and a tally for the headline. A row is
	 * { label, value, tone }; `empty` stands in for rows a source could not give.
	 * tally: down, look (to look at), orphaned, skipped (could not run), unknown
	 * (not measured); and two that are not faults and never turn the dot
	 * amber: checking (a purge verifying right now), paused (an AI check
	 * waiting on the provider's credit).
	 */
	function readUptime( up, tally, stale ) {
		if ( 'pending' === up ) { return { rows: [ { label: 'Monitors', value: 'checking…' } ] }; }
		if ( ! up || up.error ) {
			tally.unknown++;
			return { empty: 'Uptime could not be read' + ( up && up.error ? ': ' + up.error : '.' ) };
		}
		// A failed poll after a good one keeps the good reading, says so, and
		// paints none of it green: it is the last answer, not the current one.
		if ( stale ) {
			var kept = readUptime( up, newTally() );
			tally.unknown++;
			return kept.empty ? kept : { rows: kept.rows.map( function( r ) { return { label: r.label, value: r.value, tone: OK_FG === r.tone ? '' : r.tone }; } ).concat( [ { label: 'Last check failed: ' + stale, value: '', tone: WARN_FG } ] ) };
		}
		if ( ! up.configured ) { tally.unknown++; return { empty: 'Better Stack is not configured.' }; }
		var mons = up.rows || [];
		if ( ! mons.length ) { tally.unknown++; return { empty: 'No monitors configured.' }; }
		var upN  = mons.filter( function( m ) { return 'ok' === m.level; } ).length;
		// Red only when a monitor is down; a shortfall of paused, maintenance,
		// pending or validating monitors is amber.
		var anyDown = mons.some( function( m ) { return 'alert' === m.level; } );
		var rows = [ { label: 'Monitors', value: [ upN + ' of ' + mons.length + ' up' ].concat( uptimeSummary( mons ) ).join( ' · ' ), tone: upN === mons.length ? '' : ( anyDown ? DANGER_FG : WARN_FG ) } ].concat( uptimeExtraRows( mons ) );
		// One line when all are up; each monitor only when one is not.
		if ( upN !== mons.length ) {
			var shown = 0;
			// Down monitors first, so the cap never hides an outage behind warnings.
			mons.slice().sort( function( a, b ) { return ( 'alert' === b.level ) - ( 'alert' === a.level ); } ).forEach( function( m ) {
				var level = String( m.level || 'unknown' );
				if ( 'ok' === level ) { return; } // the count above already says how many are up
				if ( 'alert' === level ) { tally.down++; } else { tally.look++; }
				if ( ++shown > LIST_CAP ) { return; } // counted in the verdict, named in the "+N more" row
				rows.push( { label: String( m.name || 'monitor' ), value: LEVEL_TEXT[ level ] || 'Unknown', tone: 'ok' === level ? OK_FG : ( 'alert' === level ? DANGER_FG : WARN_FG ) } );
			} );
			if ( shown > LIST_CAP ) { rows.push( { label: '+' + ( shown - LIST_CAP ) + ' more not up', value: '' } ); }
		}
		return { rows: rows };
	}

	/**
	 * SN Uptime's per-monitor figures, condensed: the mean 30-day availability
	 * and the mean response time across the monitors that report them. A
	 * figure no monitor reports is left out, never shown as 0. No change is
	 * shown: the uptime data carries no prior period to compare against.
	 */
	function uptimeSummary( mons ) {
		var mean = function( key ) {
			var v = mons.map( function( m ) { return m[ key ]; } ).filter( function( x ) { return x !== null && x !== undefined && x !== '' && ! isNaN( Number( x ) ); } ).map( Number );
			return v.length ? v.reduce( function( a, b ) { return a + b; }, 0 ) / v.length : null;
		};
		var out = [];
		var a   = mean( 'availability' );
		var r   = mean( 'response_ms' );
		if ( null !== a ) { out.push( ( Math.round( a * 100 ) / 100 ) + '% over 30 days' ); }
		if ( null !== r ) { out.push( 'average ' + Math.round( r ) + ' ms' ); }
		return out;
	}

	/**
	 * Two figures the uptime read already carries (2026-10-05): incidents over
	 * 30 days across the monitors that report them, and the slowest monitor
	 * when there are two or more to compare. Left out when no monitor reports.
	 */
	function uptimeExtraRows( mons ) {
		var rows = [];
		var inc  = mons.filter( function( m ) { return m.incidents_30d !== null && m.incidents_30d !== undefined && ! isNaN( Number( m.incidents_30d ) ); } );
		if ( inc.length ) {
			// A total only when every monitor reported; otherwise say how many did.
			var sum = inc.reduce( function( a, m ) { return a + Number( m.incidents_30d ); }, 0 );
			rows.push( { label: 'Incidents · 30 days', value: String( sum ) + ( inc.length < mons.length ? ' · ' + inc.length + ' of ' + mons.length + ' monitors read' : '' ) } );
		}
		var timed = mons.filter( function( m ) { return m.response_ms !== null && m.response_ms !== undefined && m.response_ms !== '' && ! isNaN( Number( m.response_ms ) ); } );
		// Heartbeats never carry a response time: they are not in the denominator.
		var timeable = mons.filter( function( m ) { return ! m.kind || 'monitor' === m.kind; } ).length;
		if ( timed.length > 1 ) {
			var slow = timed.reduce( function( a, b ) { return Number( b.response_ms ) > Number( a.response_ms ) ? b : a; } );
			rows.push( { label: 'Slowest', value: String( slow.name || 'monitor' ) + ' · ' + Math.round( Number( slow.response_ms ) ) + ' ms' + ( timed.length < timeable ? ' · ' + timed.length + ' of ' + timeable + ' monitors timed' : '' ) } );
		}
		return rows;
	}

	/** Edge 5xx for the last complete UTC day, against the day before. */
	function readEdge( e, tally ) {
		if ( ! e ) { tally.unknown++; return { empty: 'No complete day in the edge rollup yet.' }; } // not measured is never an all-clear.
		if ( e.failed ) { tally.unknown++; return { empty: 'The 5xx read for ' + String( e.day || 'the newest day' ) + ' failed.' }; }
		var total = num( e.total );
		// In words, not an arrow: a screen reader says the words, not "triangle".
		var delta = null === e.prior || undefined === e.prior ? '' : ( total === num( e.prior ) ? ' · same as the day before' : ' · ' + Math.abs( total - num( e.prior ) ) + ( total > num( e.prior ) ? ' more' : ' fewer' ) + ' than the day before' );
		// Every 5xx reaches the headline: most "through a Worker" rows are a
		// visitor's request the rights Worker forwarded (inc/edge-rollup.php),
		// so "who asked" cannot separate visitors from the Worker's own calls.
		if ( total > 0 ) { tally.look++; }
		return { rows: [ { label: '5xx · ' + String( e.day || 'yesterday' ), value: total + delta, tone: total > 0 ? WARN_FG : '' } ] };
	}

	/** Cron runs over the last 24 hours, and the hooks that failed. */
	function cronDayRows( d, tally ) {
		if ( ! d ) {
			// The owner payload came but the history read did not: not measured.
			if ( data.statusExtra && data.statusExtra.systems ) { tally.unknown++; return [ { label: 'Last 24 hours', value: 'could not be read', tone: WARN_FG } ]; }
			return [];
		}
		var failed = num( d.failed );
		// Runs RECORDED: a scheduled run that dies fatally leaves no row, so "0
		// failed" would claim more than the history knows. Failures only when
		// some were recorded.
		var rows   = [ { label: 'Last 24 hours', value: num( d.fires ) + ' runs recorded' + ( failed > 0 ? ' · ' + failed + ' failed' : '' ), tone: failed > 0 ? WARN_FG : '' } ];
		if ( failed > 0 ) {
			tally.look++;
			var hooks = d.failing || [];
			hooks.slice( 0, LIST_CAP ).forEach( function( h ) { rows.push( { label: String( h ).replace( /^snt?_/, '' ), value: 'failed', tone: WARN_FG } ); } );
			if ( hooks.length > LIST_CAP ) { rows.push( { label: '+' + ( hooks.length - LIST_CAP ) + ' more failed', value: '' } ); }
		}
		return rows;
	}

	/** The last full edge purge and how fresh the edge was after the last check. */
	function readCache( c, tally ) {
		if ( ! c ) { tally.unknown++; return { empty: 'No purge recorded yet.' }; } // no evidence is not a clean edge.
		var rows = [];
		var when = ago( c.last_purge );
		if ( when ) { rows.push( { label: 'Last full purge', value: when } ); }
		// Only a verified fresh edge stays out of the headline: stale is to look
		// at; pending (a purge still verifying) and unknown are not measured yet.
		var fresh = String( c.fresh || 'unknown' );
		// A purge verifying inside its window is in progress, not unmeasured;
		// one still "verifying" past it never got its check.
		// Aged from the report's own time: the ledger's last purge also moves on
		// a manual purge, which never touches a pending report (Codex on #1925).
		var since    = num( c.fresh_time ) > 0 ? Date.now() / 1000 - num( c.fresh_time ) : Infinity;
		// A report from the future is broken timing, not a check in progress
		// (Codex on #1925); SKEW_S allows the ordinary browser/server drift.
		var checking = 'pending' === fresh && since >= -SKEW_S && since < VERIFY_S;
		if ( 'stale' === fresh ) { tally.look++; } else if ( checking ) { tally.checking++; } else if ( 'fresh' !== fresh ) { tally.unknown++; }
		rows.push( { label: 'Edge freshness', value: String( c.headline || ( 'unknown' === fresh ? 'not verified yet' : fresh ) ), tone: 'fresh' === fresh || checking ? '' : WARN_FG } );
		return rows.length ? { rows: rows } : { empty: 'No purge recorded yet.' };
	}

	function readHealth( h, tally ) {
		if ( ! h ) { tally.unknown++; return { empty: 'No health scan yet.' }; }
		var all     = h.skipped || [];
		var paused  = all.filter( function( s ) { return AI_CREDIT.test( String( s.reason || '' ) ); } );
		var skipped = all.filter( function( s ) { return paused.indexOf( s ) < 0; } );
		var lookN   = Math.max( 0, num( h.total ) - num( h.passed ) - all.length );
		var age     = ago( h.scanned_at );
		tally.look    += lookN;
		tally.skipped += skipped.length;
		tally.paused  += paused.length;
		var rows = [ {
			label: 'Checks' + ( age ? ' · scanned ' + age : '' ),
			value: lookN || all.length ? num( h.passed ) + ' of ' + num( h.total ) + ' passed' : 'All ' + num( h.total ) + ' passed',
		} ];
		// WHICH checks, ranked count-desc by the server and capped at 4; a
		// check that could not run is named apart, its reason left to the tab.
		// Two of each at most, the rest counted, so a bad scan never pushes the
		// buttons out of the card; every check is on the Health tab.
		var flagged = h.flagged || [];
		flagged.slice( 0, LIST_CAP ).forEach( function( f ) { rows.push( { label: String( f.label ), value: String( f.count ), tone: WARN_FG } ); } );
		var moreFlagged = Math.max( 0, flagged.length - LIST_CAP ) + num( h.flagged_more );
		if ( moreFlagged > 0 ) { rows.push( { label: '+' + moreFlagged + ' more to look at', value: '' } ); }
		skipped.slice( 0, LIST_CAP ).forEach( function( s ) { rows.push( { label: String( s.label ), value: 'could not run', tone: WARN_FG } ); } );
		if ( skipped.length > LIST_CAP ) { rows.push( { label: '+' + ( skipped.length - LIST_CAP ) + ' more could not run', value: '' } ); }
		// Paused, in the card's plain text: still said, never amber.
		paused.forEach( function( s ) { rows.push( { label: String( s.label ), value: 'paused: AI credit out', tone: 'var(--os-ui-color-text-subtle, rgba(255,255,255,.7))' } ); } );
		// Billing beside the Health tab when both apply (Codex on #1925).
		return { rows: rows, fix: paused.length ? [ BILLING ] : [] };
	}

	function readCron( c, tally ) {
		// ABSENT IS NOT ZERO: no `total` key is "we never looked".
		if ( ! c || ! Object.prototype.hasOwnProperty.call( c, 'total' ) ) { tally.unknown++; return { empty: 'Cron is not measured on this install.' }; }
		var total   = num( c.total );
		var orphans = num( c.orphans );
		var next    = c.next && typeof c.next === 'object' && c.next.hook ? c.next : null;
		var lateS   = next ? Math.max( 0, -num( next.in_s ) ) : 0;
		var late    = lateS > LATE_S;
		var row     = { label: total + ' scheduled', value: next ? 'next: ' + String( next.hook ).replace( /^snt?_/, '' ) + ( late ? ' · ' + Math.round( lateS / 60 ) + ' min late' : '' ) : '' };
		if ( late ) { row.tone = WARN_FG; tally.look++; }
		var rows = [ row ].concat( cronDayRows( extra.cron, tally ) );
		if ( orphans > 0 ) { tally.orphaned += orphans; rows.push( { label: 'Orphaned', value: String( orphans ), tone: WARN_FG } ); }
		// The cron-health verdict, only when it is not ok: the counts cannot say
		// "a recurring job is expected and not scheduled"; this can.
		var health = c.health && typeof c.health === 'object' ? c.health : null;
		if ( health && Object.prototype.hasOwnProperty.call( health, 'ok' ) && ! health.ok && health.summary ) {
			tally.look++;
			rows.push( { label: String( health.summary ), value: '', tone: WARN_FG } );
		}
		return { rows: rows };
	}

	/** "1 to look at · 1 could not run", or '' when there is nothing to say. */
	function headlineText( t ) {
		var parts = [];
		if ( t.down )     { parts.push( t.down + ' down' ); }
		if ( t.look )     { parts.push( t.look + ' to look at' ); }
		if ( t.orphaned ) { parts.push( t.orphaned + ' orphaned' ); }
		if ( t.skipped )  { parts.push( t.skipped + ' could not run' ); }
		if ( t.unknown )  { parts.push( t.unknown + ' not measured' ); }
		if ( t.checking ) { parts.push( t.checking + ' verifying' ); }
		if ( t.paused )   { parts.push( t.paused + ' paused' ); }
		return parts.join( ' · ' );
	}

	function newTally() {
		return { down: 0, look: 0, orphaned: 0, skipped: 0, unknown: 0, checking: 0, paused: 0 };
	}

	/** The counts that make the dot amber (or red): everything but checking and paused. */
	function faults( t ) {
		return t.down + t.look + t.orphaned + t.skipped + t.unknown;
	}

	/** The section's fix link, as { href, text }, or null when the page is not known. */
	function fixFor( title ) {
		var f = FIX[ title ];
		if ( ! f ) { return null; }
		var href = f.href || ( data.pages && data.pages[ f.page ] ) || '';
		return href ? { href: href, text: f.text } : null;
	}

	function section( title, read, first ) {
		var box  = el( 'div', { style: 'margin-top:' + ( first ? '8px' : '6px' ) + ';padding-top:' + ( first ? '8px' : '6px' ) + ';border-top:1px solid ' + HAIRLINE + ';' } );
		var head = el( 'div', { text: title, style: 'font-size:11px;margin-bottom:2px;' + SUBTLE } );
		head.setAttribute( 'role', 'heading' );
		head.setAttribute( 'aria-level', '3' );
		box.appendChild( head );
		if ( read.empty ) {
			box.appendChild( el( 'div', { text: read.empty, style: 'font-size:11px;padding:2px 0;' + SUBTLE } ) );
			return fixLink( box, read.fix );
		}
		var list = el( 'div' );
		list.setAttribute( 'role', 'list' );
		read.rows.forEach( function( r ) {
			var row = el( 'div', { style: 'display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;column-gap:8px;padding:2px 0;font-size:11px;' } );
			row.setAttribute( 'role', 'listitem' );
			row.appendChild( el( 'span', { text: r.label, style: 'min-width:0;white-space:normal;overflow-wrap:break-word;' + SUBTLE } ) );
			if ( r.value ) {
				row.appendChild( el( 'span', { text: r.value, style: 'flex:0 1 auto;max-width:100%;margin-left:auto;text-align:right;overflow-wrap:anywhere;font-variant-numeric:tabular-nums;font-weight:600;' + ( r.tone ? 'color:' + r.tone + ';' : '' ) } ) );
			}
			list.appendChild( row );
		} );
		box.appendChild( list );
		return fixLink( box, read.fix );
	}

	/** Append the section's fix links, styled as the card's "Open Health →". */
	function fixLink( box, fixes ) {
		( fixes || [] ).forEach( function( fix ) { fixOne( box, fix ); } );
		return box;
	}

	function fixOne( box, fix ) {
		var origin   = ( window.location && window.location.origin ) || '';
		var external = /^https?:/.test( fix.href ) && ( ! origin || fix.href.indexOf( origin ) !== 0 );
		var a = el( 'a', { href: fix.href, text: fix.text, style: 'display:inline-flex;align-items:center;gap:4px;margin-right:12px;min-height:24px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;' } );
		if ( external ) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
		var arr = el( 'span', { text: external ? '↗' : '→' } );
		arr.setAttribute( 'aria-hidden', 'true' ); // the arrow is decoration; the words name the place
		a.appendChild( arr );
		if ( external ) {
			// Said to a screen reader, not shown: the visible name stays the words (WCAG 2.5.3).
			a.appendChild( el( 'span', { text: ' (opens in a new tab)', style: 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;' } ) );
		}
		box.appendChild( a );
		return box;
	}

	/** Hover feedback for an inline-styled button (no stylesheet here). */
	function hoverable( btn ) {
		btn.addEventListener( 'mouseenter', function() {
			if ( btn.getAttribute( 'aria-busy' ) !== 'true' ) { btn.style.background = SURFACE_HOVER; }
		} );
		btn.addEventListener( 'mouseleave', function() { btn.style.background = SURFACE; } );
	}

	// The shell's own toast (wp.os.showToast, Stable) paints at the top of the
	// shell and never grows the card. The in-card line is the fallback for a
	// shell without it.
	function shellToast( message ) {
		var os = ( window.wp && ( window.wp.os || window.wp.desktop ) ) || null;
		if ( ! os || typeof os.showToast !== 'function' ) { return false; }
		try {
			os.showToast( { message: String( message ), duration: TOAST_MS, source: 'sn-systems' } );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	// The shell's own confirm dialog when there is one (wp.os.confirm, the one
	// snt-confirm.js opens on the station), else the browser's. Resolves a boolean.
	function confirmAction( message ) {
		var os = window.wp && window.wp.os;
		if ( os && typeof os.confirm === 'function' ) {
			return Promise.resolve( os.confirm( { title: 'Clear DB overrides?', message: message, confirmLabel: 'Clear overrides', danger: true } ) );
		}
		if ( typeof window.sntConfirm === 'function' ) {
			return Promise.resolve( window.sntConfirm( { title: 'Clear DB overrides?', message: message, confirmLabel: 'Clear overrides', danger: true } ) );
		}
		return Promise.resolve( typeof window.confirm === 'function' ? window.confirm( message ) : false );
	}

	window.desktopModeWidgets['sn-health'] = function( container, ctx ) { // eslint-disable-line no-unused-vars
		var torn      = false;
		var timer     = null;
		var pending   = false;
		var controller = null; // the in-flight uptime read, aborted at teardown
		var lastAt    = 0;
		var nextAt    = 0;
		var lastDelay = REFRESH_MS;
		var failures  = 0;
		var uptime    = 'pending';
		var stale     = ''; // why the last poll failed, while a good reading is kept
		var toastTimer = 0;

		var wrap = el( 'div', { style: 'padding:10px 12px;color:inherit;' } );
		// The verdict line is the live region: it is rewritten only when its
		// words change, so a background poll that finds the same answer says nothing.
		var head = el( 'div', { style: 'display:flex;align-items:center;gap:8px;' } );
		var dot  = el( 'span', { style: 'width:9px;height:9px;border-radius:50%;flex:0 0 auto;background:' + WARN_FG + ';' } );
		dot.setAttribute( 'aria-hidden', 'true' );
		var verdict = el( 'span', { text: 'Checking…', style: 'font-size:14px;font-weight:600;font-variant-numeric:tabular-nums;' } );
		verdict.setAttribute( 'role', 'status' );
		head.appendChild( dot );
		head.appendChild( verdict );
		wrap.appendChild( head );
		var detail = el( 'div' );
		wrap.appendChild( detail );

		// Moved from SN Quick Actions: the same ability call, now behind a confirm
		// (it deletes wp_template / wp_template_part / wp_navigation rows).
		var btn = el( 'button', {
			text:  'Clear DB overrides',
			// The cards' one button style (SN Provenance's Sweep now), on the link's line.
			style: 'font:inherit;font-size:11px;padding:2px 10px;border-radius:5px;border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit;cursor:pointer;min-height:24px;',
		} );
		btn.type = 'button';
		hoverable( btn );
		btn.addEventListener( 'click', function() {
			if ( btn.getAttribute( 'aria-busy' ) === 'true' ) { return; }
			if ( typeof window.sntAbilityRun !== 'function' ) { toast( 'sntAbilityRun unavailable', false ); return; }
			// Busy before the dialog: a second click while it is open must not open another.
			btn.setAttribute( 'aria-busy', 'true' );
			confirmAction( 'Remove the wp_template, wp_template_part and wp_navigation rows from the database, so the theme files are what renders?' ).then( function( yes ) {
				if ( ! yes || torn ) { btn.removeAttribute( 'aria-busy' ); return; }
				btn.textContent   = 'Clearing…';
				btn.style.opacity = '0.55';
				return window.sntAbilityRun( 'clear-template-overrides' ).then( function( res ) {
					toast( ( res && res.message ) ? res.message : 'Overrides cleared.', !! ( res && res.ok ) );
				}, function( err ) {
					toast( ( err && err.message ) ? err.message : 'Action failed.', false );
				} ).then( function() {
					btn.textContent   = 'Clear DB overrides';
					btn.style.opacity = '1';
					btn.removeAttribute( 'aria-busy' );
				} );
			} );
		} );
		var actions = el( 'div', { style: 'margin-top:10px;display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center;' } );
		actions.appendChild( btn );
		wrap.appendChild( actions );

		// "Open Health" closes the Health section now (paint()), not this row.
		container.appendChild( wrap );

		function toast( message, success ) {
			if ( shellToast( message ) ) { return; }
			var old = wrap.querySelector ? wrap.querySelector( '.sn-dm-toast' ) : null;
			if ( old && old.parentNode ) { old.parentNode.removeChild( old ); }
			var t = el( 'div', { style: 'margin-top:10px;padding:8px 10px;border-radius:8px;font-size:11px;line-height:1.35;background:' + ( success ? OK_BG : DANGER_BG ) + ';color:' + ( success ? OK_FG : DANGER_FG ) + ';border:1px solid ' + ( success ? OK_LINE : DANGER_LINE ) + ';' } );
			t.className = 'sn-dm-toast';
			// A status region announces a change, so it is attached empty and filled after.
			t.setAttribute( 'role', 'status' );
			wrap.appendChild( t );
			t.textContent = message;
			toastTimer = window.setTimeout( function() {
				toastTimer = 0;
				if ( t.parentNode ) { t.parentNode.removeChild( t ); }
			}, TOAST_MS );
		}

		function paint() {
			if ( torn ) { return; }
			var tally = newTally();
			// Each read with the fix link for whatever it added to the headline.
			var read  = function( title, fn ) {
				var before = faults( tally ), r = fn();
				// Health always links to its tab (it was the bottom row's link,
				// 2026-10-05); every other section only when it added a fault.
				var f = 'Health' === title && healthUrl ? { href: healthUrl, text: 'Open Health' } : ( faults( tally ) > before ? fixFor( title ) : null );
				r.fix = ( r.fix || [] ).concat( f ? [ f ] : [] );
				return [ title, r ];
			};
			var reads = [
				read( 'Uptime', function() { return readUptime( uptime, tally, stale ); } ),
				read( 'Health', function() { return readHealth( data.healthSummary, tally ); } ),
				read( 'Cron', function() { return readCron( data.cronSummary, tally ); } ),
			];
			// Edge and Cache only when the owner payload came: without it (another
			// role, an older build) there is no source to call unmeasured.
			if ( data.statusExtra && data.statusExtra.systems ) {
				reads.push( read( 'Edge', function() { return readEdge( extra.edge, tally ); } ), read( 'Cache', function() { return readCache( extra.cache, tally ); } ) );
			}
			var words = 'pending' === uptime ? 'Checking…' : ( headlineText( tally ) || 'All systems normal' );
			if ( verdict.textContent !== words ) { verdict.textContent = words; }
			// Gray, not green, when the only words are verifying or paused: not a
			// fault, and not everything ran either.
			dot.style.background = 'pending' === uptime ? SURFACE_HOVER : tally.down ? DANGER_FG : faults( tally ) ? WARN_FG : ( 'All systems normal' === words ? OK_FG : SURFACE_HOVER );
			// A repaint rebuilds the section links: a keyboard user on one keeps
			// it (Codex on #1927), found again by its address and words.
			var focused = document.activeElement && detail.contains && detail.contains( document.activeElement ) && 'A' === document.activeElement.tagName ? document.activeElement : null;
			var key     = focused ? focused.href + '|' + focused.textContent : '';
			clearChildren( detail );
			reads.forEach( function( r, i ) { detail.appendChild( section( r[0], r[1], 0 === i ) ); } );
			if ( key ) {
				var back = Array.prototype.filter.call( detail.querySelectorAll( 'a' ), function( a ) { return a.href + '|' + a.textContent === key; } )[0];
				if ( back ) { back.focus(); }
			}
		}

		// Recipe 2 (OpenStation docs/examples/register-widget.md, #1603), as SN
		// Uptime polled: no poll while the tab is hidden, and reveal re-arms for
		// what is left of the wait (zero when the reading went stale). Focus-aware
		// (assets/snt-poll-cadence.js): the full rate while the window is focused,
		// IDLE_MS while it is only visible.
		function cadence( ms ) {
			return window.sntPollCadence ? window.sntPollCadence.wait( ms ) : ms;
		}
		function arm() {
			window.clearTimeout( timer );
			if ( torn || pending || document.hidden ) { return; }
			nextAt = lastAt + Math.max( lastDelay, cadence( REFRESH_MS ) );
			timer = window.setTimeout( refresh, Math.max( 0, nextAt - Date.now() ) );
		}
		function refresh() {
			if ( torn || pending ) { return; }
			pending = true;
			controller = window.AbortController ? new window.AbortController() : null;
			var delay = REFRESH_MS;
			// SN Uptime's resilience contract: the promise boundary catches a
			// missing runner or a synchronous throw; a malformed answer is a
			// failure; a failure backs off (doubling, capped at 15 min, never
			// sooner than the server's retry_after) and keeps the last good reading.
			Promise.resolve().then( function() {
				if ( torn ) { return; }
				if ( typeof window.sntAbilityRun !== 'function' ) { throw new Error( 'sntAbilityRun unavailable' ); }
				// detail: the 30-day availability and response times the first row condenses.
				return window.sntAbilityRun( 'uptime-status', { detail: true }, { signal: controller ? controller.signal : undefined, silent: true } );
			} ).then( function( res ) {
				if ( torn ) { return; }
				if ( ! res || typeof res.configured !== 'boolean' || ( res.configured && ( ! Array.isArray( res.rows ) || ! res.rows.every( function( row ) { return row && typeof row === 'object' && ! Array.isArray( row ); } ) ) ) ) { throw new Error( 'Invalid uptime response' ); }
				if ( res.error ) { throw new Error( res.error ); }
				uptime   = res;
				stale    = '';
				failures = 0;
			} ).catch( function( err ) {
				if ( torn ) { return; }
				failures++;
				delay = Math.min( 15 * 60 * 1000, REFRESH_MS * Math.pow( 2, Math.min( failures - 1, 4 ) ) );
				// wp.apiFetch rejects with parsed WP_Error JSON, NOT a Response.
				var retry = Number( err && err.data && err.data.retry_after );
				// Reject malformed hints beyond the browser's signed 32-bit timer range.
				if ( isFinite( retry ) && retry > 0 && retry <= 2147483 ) { delay = Math.max( delay, retry * 1000 ); }
				var message = ( err && err.message ) || 'unknown error';
				if ( uptime && 'pending' !== uptime && ! uptime.error ) {
					stale = message;
				} else {
					uptime = { configured: true, rows: [], error: message };
				}
			} ).then( function() {
				pending    = false;
				controller = null;
				lastAt    = Date.now();
				lastDelay = delay;
				paint();
				arm();
			} );
		}
		function onVisibilityChange() {
			if ( document.hidden ) { window.clearTimeout( timer ); return; }
			arm();
		}
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		var unwatchFocus = window.sntPollCadence ? window.sntPollCadence.onFocusChange( onVisibilityChange ) : function() {};

		paint();
		refresh();

		return function teardown() {
			torn = true;
			window.clearTimeout( timer );
			if ( toastTimer ) { window.clearTimeout( toastTimer ); }
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
			unwatchFocus();
			if ( controller ) { controller.abort(); }
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
