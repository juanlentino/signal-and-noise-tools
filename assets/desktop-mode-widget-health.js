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
 * ability, light tier (statuses from a 90s server cache), fetched on mount and
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
	var healthUrl = ( data.pages && ( data.pages.health || data.pages.dashboard ) ) || ''; // 15.8.2: Monitoring › Health, the leaf the reading lives on
	// Monitors don't flap by the second; the statuses ride a 90s server cache
	// anyway, so a 2-minute poll never outruns the data underneath it.
	var REFRESH_MS = 2 * 60 * 1000;
	var TOAST_MS   = 3500;
	// WP-Cron runs on a page load, so a job is routinely "due" for seconds; it
	// is late only past this.
	var LATE_S     = 600;

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
	 * (not measured).
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
			var kept = readUptime( up, { down: 0, look: 0, orphaned: 0, skipped: 0, unknown: 0 } );
			tally.unknown++;
			return kept.empty ? kept : { rows: kept.rows.map( function( r ) { return { label: r.label, value: r.value, tone: OK_FG === r.tone ? '' : r.tone }; } ).concat( [ { label: 'Last check failed: ' + stale, value: '', tone: WARN_FG } ] ) };
		}
		if ( ! up.configured ) { tally.unknown++; return { empty: 'Better Stack is not configured.' }; }
		var mons = up.rows || [];
		if ( ! mons.length ) { tally.unknown++; return { empty: 'No monitors configured.' }; }
		var upN  = mons.filter( function( m ) { return 'ok' === m.level; } ).length;
		var rows = [ { label: 'Monitors', value: upN + ' of ' + mons.length + ' up', tone: upN === mons.length ? '' : DANGER_FG } ];
		// One line when all are up; each monitor only when one is not.
		if ( upN !== mons.length ) {
			mons.forEach( function( m ) {
				var level = String( m.level || 'unknown' );
				if ( 'ok' === level ) { return; } // the count above already says how many are up
				if ( 'alert' === level ) { tally.down++; } else { tally.look++; }
				rows.push( { label: String( m.name || 'monitor' ), value: LEVEL_TEXT[ level ] || 'Unknown', tone: 'ok' === level ? OK_FG : ( 'alert' === level ? DANGER_FG : WARN_FG ) } );
			} );
		}
		return { rows: rows };
	}

	function readHealth( h, tally ) {
		if ( ! h ) { tally.unknown++; return { empty: 'No health scan yet.' }; }
		var skipped = h.skipped || [];
		var lookN   = Math.max( 0, num( h.total ) - num( h.passed ) - skipped.length );
		var age     = ago( h.scanned_at );
		tally.look    += lookN;
		tally.skipped += skipped.length;
		var rows = [ {
			label: 'Checks' + ( age ? ' · scanned ' + age : '' ),
			value: lookN || skipped.length ? num( h.passed ) + ' of ' + num( h.total ) + ' passed' : 'All ' + num( h.total ) + ' passed',
		} ];
		// WHICH checks, ranked count-desc by the server and capped at 4; a
		// check that could not run is named apart, its reason left to the tab.
		( h.flagged || [] ).forEach( function( f ) { rows.push( { label: String( f.label ), value: String( f.count ), tone: WARN_FG } ); } );
		if ( num( h.flagged_more ) > 0 ) { rows.push( { label: '+' + num( h.flagged_more ) + ' more', value: '' } ); }
		skipped.forEach( function( s ) { rows.push( { label: String( s.label ), value: 'could not run', tone: WARN_FG } ); } );
		return { rows: rows };
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
		var rows = [ row ];
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
		return parts.join( ' · ' );
	}

	function section( title, read, first ) {
		var box  = el( 'div', { style: 'margin-top:' + ( first ? '8px' : '6px' ) + ';padding-top:' + ( first ? '8px' : '6px' ) + ';border-top:1px solid ' + HAIRLINE + ';' } );
		var head = el( 'div', { text: title, style: 'font-size:11px;margin-bottom:2px;' + SUBTLE } );
		head.setAttribute( 'role', 'heading' );
		head.setAttribute( 'aria-level', '3' );
		box.appendChild( head );
		if ( read.empty ) {
			box.appendChild( el( 'div', { text: read.empty, style: 'font-size:11px;padding:2px 0;' + SUBTLE } ) );
			return box;
		}
		var list = el( 'div' );
		list.setAttribute( 'role', 'list' );
		read.rows.forEach( function( r ) {
			var row = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
			row.setAttribute( 'role', 'listitem' );
			row.appendChild( el( 'span', { text: r.label, style: 'min-width:0;white-space:normal;overflow-wrap:anywhere;' + SUBTLE } ) );
			if ( r.value ) {
				row.appendChild( el( 'span', { text: r.value, style: 'flex:0 1 auto;text-align:right;overflow-wrap:anywhere;font-variant-numeric:tabular-nums;font-weight:600;' + ( r.tone ? 'color:' + r.tone + ';' : '' ) } ) );
			}
			list.appendChild( row );
		} );
		box.appendChild( list );
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
			style: 'display:block;width:100%;min-height:24px;margin:10px 0 0;padding:8px 10px;background:' + SURFACE + ';color:inherit;border:1px solid ' + HAIRLINE +
				';border-radius:8px;font-size:13px;line-height:1.2;cursor:pointer;text-align:left;transition:background 120ms ease,border-color 120ms ease;',
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
		wrap.appendChild( btn );

		if ( healthUrl ) {
			var link = el( 'a', {
				href:  healthUrl,
				text:  'Open Health',
				style: 'display:inline-flex;align-items:center;gap:4px;min-height:24px;margin-top:8px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;'
			} );
			var arrow = el( 'span', { text: '→' } );
			arrow.setAttribute( 'aria-hidden', 'true' ); // a link's trailing arrow is decoration
			link.appendChild( arrow );
			wrap.appendChild( link );
		}
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
			var tally = { down: 0, look: 0, orphaned: 0, skipped: 0, unknown: 0 };
			var reads = [ [ 'Uptime', readUptime( uptime, tally, stale ) ], [ 'Health', readHealth( data.healthSummary, tally ) ], [ 'Cron', readCron( data.cronSummary, tally ) ] ];
			var words = 'pending' === uptime ? 'Checking…' : ( headlineText( tally ) || 'All systems normal' );
			if ( verdict.textContent !== words ) { verdict.textContent = words; }
			dot.style.background = 'pending' === uptime ? SURFACE_HOVER : tally.down ? DANGER_FG : ( 'All systems normal' === words ? OK_FG : WARN_FG );
			clearChildren( detail );
			reads.forEach( function( r, i ) { detail.appendChild( section( r[0], r[1], 0 === i ) ); } );
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
			var delay = REFRESH_MS;
			// SN Uptime's resilience contract: the promise boundary catches a
			// missing runner or a synchronous throw; a malformed answer is a
			// failure; a failure backs off (doubling, capped at 15 min, never
			// sooner than the server's retry_after) and keeps the last good reading.
			Promise.resolve().then( function() {
				if ( torn ) { return; }
				if ( typeof window.sntAbilityRun !== 'function' ) { throw new Error( 'sntAbilityRun unavailable' ); }
				return window.sntAbilityRun( 'uptime-status', {}, { silent: true } );
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
				pending   = false;
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
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
