/**
 * Signal & Noise Tools — desktop-mode widget render.
 *
 * Registered as the 'sn-desktop-mode-widget' WP script handle in
 * inc/desktop-mode-integration.php and loaded by desktop-mode when the
 * 'sn-deploy-status' widget is placed on the desktop.
 *
 * Renders a compact card with theme + plugin versions, last deploy time,
 * and a click target that opens the SN Dashboard.
 *
 * Fetches the signal-noise/get-deploy-status ability run-path (v6.55.0;
 * previously /signal-noise/v1/cmd/status) on mount and every 60s thereafter
 * (matches the GHA runs cache TTL).
 *
 * DOM-built via createElement + textContent (no innerHTML) — eliminates
 * the entire XSS-from-string-concat risk class. Inline styles are kept
 * here because desktop-mode widget surfaces don't load the SN admin
 * stylesheet; the widget needs to be self-contained.
 *
 * @since plugin v1.15.0
 */
( function() {
	'use strict';

	// v9.52.0 — MOUNT CONTRACT FIX. PHP-declared widgets are mounted by
	// desktop-mode's server-sync, which reads the callback off
	// window.desktopModeWidgets[ id ]. This file previously called
	// wp.desktop.registerWidget({id, render}) — the client-side path, and with
	// a shape that path rejects (it requires id + label + description + icon +
	// mount, and throws otherwise). The widget never registered either way.
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

	var data = window.snDesktopData || {};
	var dashboardUrl = ( data.pages && data.pages.dashboard ) || '';
	// v6.55.0: read deploy status via the get-deploy-status ability run-path.
	var REFRESH_MS = 60 * 1000;

	/**
	 * Tiny helper to create an element with optional inline styles and
	 * text content. No innerHTML — all text via textContent.
	 */
	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		return node;
	}

	function stateGlyph( state ) {
		switch ( state ) {
			case 'ok':        return { label: '✓', color: '#3fb950' };
			case 'available': return { label: '↑', color: '#d29922' };
			// v11.11.2: worker rows say 'behind' where theme/plugin say
			// 'available' — same amber arrow, different producer vocabulary.
			case 'behind':    return { label: '↑', color: '#d29922' };
			default:          return { label: '?',      color: '#ff9d94' };
		}
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function renderLoading( container ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'padding:14px 16px;font-size:13px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));',
			text:  'Loading deploy status…',
		} ) );
	}

	/** "just now", "6 min ago", "2 h ago" from an ISO time. */
	function agoWords( iso ) {
		var s = Math.max( 0, Math.round( ( Date.now() - Date.parse( iso ) ) / 1000 ) );
		return s < 60 ? 'just now' : s < 3600 ? Math.round( s / 60 ) + ' min ago' : Math.round( s / 3600 ) + ' h ago';
	}

	// Only actual failures get a footer and an inline detail cue.
	function renderRefreshStatus( container, lastSuccess, message, delay ) {
		// A current reading needs no footer; only a failed refresh says anything,
		// in words ("Last good reading 6 min ago"), never a raw timestamp.
		if ( ! message ) { return null; }
		var footer = el( 'p', {
			style: 'position:relative;padding:0 32px 0 16px;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));'
		} );
		// No interval: the real wait depends on focus (snt-poll-cadence), so a
		// number here would promise a time the poll does not keep. The age is
		// its own node so the mount can keep it current while the footer shows.
		var age = el( 'span', { text: failText( lastSuccess ) } );
		footer.appendChild( age );
		if ( message ) {
			var detail = ( lastSuccess ? 'Showing last-known data. ' : 'No successful refresh yet. ' ) +
				'Current status unavailable: ' + message + '.';
			var cue = el( 'span', { text: '⚠', style: 'position:absolute;right:16px;top:0;color:#d29922;' } );
			cue.title = detail;
			cue.setAttribute( 'role', 'img' );
			cue.setAttribute( 'aria-label', detail );
			cue.setAttribute( 'tabindex', '0' );
			footer.appendChild( cue );
		}
		container.appendChild( footer );
		return { node: age, since: lastSuccess };
	}

	function failText( lastSuccess ) {
		return lastSuccess ? 'Last good reading ' + agoWords( lastSuccess ) + ' · retrying' : 'Status unavailable · retrying';
	}

	function renderCard( container, status, stale ) {
		clearChildren( container );

		var wrap = el( 'div', {
			className: 'sn-dm-widget',
			style:     'padding:14px 16px;color:inherit;',
		} );

		// v9.52.4: no title row — desktop-mode's chrome header (grip + label +
		// remove), rendered since movable:true in v9.52.2, already names this
		// card. Painting "Signal & Noise" here put a second title on the card.

		var grid = el( 'div', {
			style: 'display:grid;grid-template-columns:auto 1fr auto;gap:4px 12px;font-size:13px;line-height:1.4;align-items:baseline;',
		} );

		// Core joins theme/plugin when the payload carries it (contract 13);
		// an older payload renders the old rows.
		[ 'theme', 'plugin', 'core' ].forEach( function( pkg ) {
			if ( pkg === 'core' && ! status.core ) { return; }
			var info = status[ pkg ] || {};
			var glyph = stateGlyph( stale ? 'unknown' : ( info.state || 'unknown' ) );
			// 19.8.0: core 'unknown' with a version means only that WordPress's
			// cached update check is missing. Say so in muted text; a stale payload
			// or a missing version keeps the red '?'.
			var unchecked = pkg === 'core' && ! stale && !! info.current && ( info.state || 'unknown' ) === 'unknown';
			if ( unchecked ) { glyph = { label: '–', color: 'var(--os-ui-color-text-subtle, rgba(255,255,255,.6))' }; }

			grid.appendChild( el( 'span', {
				style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));',
				text:  { theme: 'Theme', plugin: 'Plugin', core: 'Core' }[ pkg ],
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;font-weight:500;',
				// Core names what is waiting: "behind (point)" / "behind (major)".
				text:  ( info.current || '—' ) + ( pkg === 'core' && info.state === 'behind' && info.offer ? ' · behind (' + info.offer + ')' : '' ) + ( unchecked ? ' · update check not cached' : '' ),
			} ) );
			var glyphEl = el( 'span', {
				style: 'color:' + glyph.color + ';font-weight:600;',
				text:  glyph.label,
			} );
			// v9.54.0: a bare '?' is a dead end. When the fetch layer recorded
			// why, hang it on the glyph so hovering explains it even before the
			// line below is read.
			if ( info.reason ) { glyphEl.title = info.reason; }
			grid.appendChild( glyphEl );
		} );

		// v11.11.2: the five workers join the card beneath theme/plugin —
		// same grid, same glyph vocabulary. Rows come from the ability's
		// additive `workers` array; a missing/older payload (array absent)
		// renders exactly the old two-row card. Each row: label, live
		// version ('unprobeable' shortens to an em dash with the reason on
		// the glyph), state ok/behind/unknown.
		( Array.isArray( status.workers ) ? status.workers : [] ).forEach( function( w ) {
			if ( ! w || typeof w !== 'object' ) { return; }
			var wGlyph = stateGlyph( stale ? 'unknown' : ( w.state || 'unknown' ) );
			grid.appendChild( el( 'span', {
				style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));',
				text:  w.label || w.id || 'worker',
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;font-weight:500;',
				text:  ( w.live && w.live !== 'unprobeable' ) ? w.live : '—',
			} ) );
			var wGlyphEl = el( 'span', {
				style: 'color:' + wGlyph.color + ';font-weight:600;',
				text:  wGlyph.label,
			} );
			if ( w.reason ) { wGlyphEl.title = w.reason; }
			else if ( w.live === 'unprobeable' ) { wGlyphEl.title = 'no version route to probe'; }
			grid.appendChild( wGlyphEl );
		} );

		wrap.appendChild( grid );

		// v9.54.0: print WHY, not just '?'. Theme and plugin authenticate with
		// the SAME wp-config constant, so a dead token yields two identical
		// reasons — say it once rather than stuttering the same sentence twice.
		var reasons = [];
		[ 'theme', 'plugin' ].forEach( function ( pkg ) {
			var reason = ( status[ pkg ] || {} ).reason;
			if ( reason && reasons.indexOf( reason ) === -1 ) { reasons.push( reason ); }
		} );
		reasons.forEach( function ( reason ) {
			wrap.appendChild( el( 'p', {
				style: 'margin:8px 0 0;font-size:11px;line-height:1.4;color:#ff9d94;',
				text:  reason,
			} ) );
		} );

		// v12.13.0: name the subject. This line sits under seven independently
		// versioned rows — theme, plugin, five workers — so a bare age read as
		// though it covered the whole card. It never did: only theme and plugin
		// install through the WP upgrader, and only they have records in the
		// feed behind it. The package name answers "of what" in the visible
		// text, and doubles as the scope; the title states the scope outright
		// for the case where the feed names nothing.
		var deployAge  = status.last_deploy || 'unknown';
		var deployWhat = status.last_deploy_component || '';
		var deployEl   = el( 'p', {
			style: 'margin:10px 0 0;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.14));font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.6));',
			text:  deployWhat
				? 'Last deploy: ' + deployWhat + ' · ' + deployAge
				: 'Last deploy: ' + deployAge,
		} );
		deployEl.title = 'Theme and plugin only. The Cloudflare workers deploy outside the WordPress upgrader, so their releases are not recorded in this feed.';
		wrap.appendChild( deployEl );

		container.appendChild( wrap );
	}

	/**
	 * v9.52.0: mount( container, ctx ) → teardown. See the contract note at
	 * the top of this file.
	 */
	// The shell's own toast (wp.os.showToast, Stable) paints at the top of the
	// shell and never grows the card; false when there is none.
	function shellToast( message ) {
		var os = ( window.wp && ( window.wp.os || window.wp.desktop ) ) || null;
		if ( ! os || typeof os.showToast !== 'function' ) { return false; }
		try {
			os.showToast( { message: String( message ), duration: 3500, source: 'sn-deploy-status' } );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * "Check for updates", moved from SN Quick Actions with the same call:
	 * get-deploy-status with force_refresh clears the GitHub-tag, update and
	 * worker-probe transients and re-fetches (the removed force-check-updates
	 * ability's job). The card then repaints from the fresh reading.
	 */
	function checkButton( isTorn, repaint ) {
		var btn  = el( 'button', {
			text:  'Check for updates',
			// The cards' one button style (SN Provenance's Sweep now).
			style: 'font:inherit;font-size:11px;padding:2px 10px;border-radius:5px;border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit;cursor:pointer;min-height:24px;',
		} );
		btn.type  = 'button';
		btn.title = 'Clear the GitHub tag + WordPress update transients and re-fetch';
		btn.addEventListener( 'mouseenter', function() { if ( btn.getAttribute( 'aria-busy' ) !== 'true' ) { btn.style.background = 'rgba(255,255,255,0.08)'; } } );
		btn.addEventListener( 'mouseleave', function() { btn.style.background = 'transparent'; } );
		var note = el( 'p', { style: 'margin:0 16px 8px;font-size:11px;' } );
		note.setAttribute( 'role', 'status' ); // the fallback when the shell has no toast
		btn.addEventListener( 'click', function() {
			if ( btn.getAttribute( 'aria-busy' ) === 'true' ) { return; }
			function say( message ) {
				if ( ! shellToast( message ) ) { note.textContent = message; }
			}
			if ( typeof window.sntAbilityRun !== 'function' ) { say( 'sntAbilityRun unavailable' ); return; }
			btn.setAttribute( 'aria-busy', 'true' );
			btn.textContent   = 'Checking…';
			btn.style.opacity = '0.55';
			note.textContent  = '';
			window.sntAbilityRun( 'get-deploy-status', { force_refresh: true } ).then( function() {
				say( 'Update check complete.' );
				if ( ! isTorn() ) { repaint(); }
			}, function( err ) {
				say( ( err && err.message ) ? err.message : 'Action failed.' );
			} ).then( function() {
				btn.textContent   = 'Check for updates';
				btn.style.opacity = '1';
				btn.removeAttribute( 'aria-busy' );
			} );
		} );
		// Button and link on one line, outside the repainted reading, so a
		// repaint never drops the button's focus or its busy state.
		var box  = el( 'div' );
		var line = el( 'div', { style: 'margin:8px 16px 12px;display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center;' } );
		line.appendChild( btn );
		if ( dashboardUrl ) {
			var link  = el( 'a', {
				style: 'display:inline-flex;align-items:center;gap:4px;min-height:24px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;',
				text:  'Open Dashboard',
				href:  dashboardUrl,
			} );
			var arrow = el( 'span', { text: '→' } );
			arrow.setAttribute( 'aria-hidden', 'true' );
			link.appendChild( arrow );
			line.appendChild( link );
		}
		box.appendChild( line );
		box.appendChild( note );
		return box;
	}

	function mount( container, ctx ) {
		if ( ! container ) { return function() {}; }

		var torn = false;
		var timer = null;
		var pending = false;
		var again = false; // a forced check landed while a poll was in flight
		var nextAt = 0;
		var lastAt = 0;
		var lastDelay = REFRESH_MS;
		var controller = null;
		var lastGood = null;
		var lastSuccess = '';
		var failures = 0;
		// The polled reading repaints `region` on every refresh; the button
		// sits outside it, so a repaint never drops its focus or its busy state.
		var region = el( 'div' );
		// The failure footer's age, kept current while it shows (a footer that
		// says "just now" ten minutes later misstates how old the reading is).
		var stale    = null;
		var ageTimer = window.setInterval( function() {
			if ( stale && ! torn ) { stale.node.textContent = failText( stale.since ); }
		}, 30000 );
		container.appendChild( region );
		container.appendChild( checkButton( function() { return torn; }, function() {
			window.clearTimeout( timer );
			if ( pending ) { again = true; } else { refresh(); }
		} ) );
		renderLoading( region );

		function refresh() {
			if ( torn || pending ) { return; }
			pending = true;
			// Background polls leave content, focus and recency untouched until success.
			controller = window.AbortController ? new window.AbortController() : null;
			var delay = REFRESH_MS;
			// Promise boundary also handles a missing runner or a synchronous throw.
			Promise.resolve().then( function() {
				if ( torn ) { return; }
				if ( typeof window.sntAbilityRun !== 'function' ) { throw new Error( 'sntAbilityRun unavailable' ); }
				return window.sntAbilityRun( 'get-deploy-status', undefined, { signal: controller ? controller.signal : undefined, silent: true } );
			} ).then( function( res ) {
				if ( torn ) { return; }
				var validPackages = res && typeof res === 'object' && ! Array.isArray( res ) && [ 'theme', 'plugin' ].every( function( name ) {
					var info = res[ name ];
					return info && typeof info === 'object' && ! Array.isArray( info ) &&
						typeof info.current === 'string' && typeof info.state === 'string' && info.state.length > 0;
				} );
				if ( ! validPackages ) { throw new Error( 'Invalid deploy status response' ); }
				lastGood = res;
				lastSuccess = new Date().toISOString();
				failures = 0;
				renderCard( region, res );
				stale = renderRefreshStatus( region, lastSuccess );
			} ).catch( function( err ) {
				if ( torn ) { return; }
				failures++;
				delay = Math.min( 15 * 60 * 1000, REFRESH_MS * Math.pow( 2, Math.min( failures - 1, 4 ) ) );
				// wp.apiFetch rejects with parsed WP_Error JSON, NOT a Response.
				var retry = Number( err && err.data && err.data.retry_after );
				// Reject malformed hints beyond the browser's signed 32-bit timer range.
				if ( isFinite( retry ) && retry > 0 && retry <= 2147483 ) { delay = Math.max( delay, retry * 1000 ); }
				var message = ( err && err.message ) || 'unknown error';
				if ( lastGood ) {
					renderCard( region, lastGood, true );
				} else {
					clearChildren( region );
				}
				stale = renderRefreshStatus( region, lastSuccess, message, delay );
			} ).then( function() {
				pending = false;
				controller = null;
				lastAt = Date.now();
				lastDelay = delay;
				arm();
				// The poll in flight may predate the forced check: read once more.
				if ( again && ! torn ) {
					again = false;
					window.clearTimeout( timer );
					refresh();
				}
			} );
		}

		// Recipe 2 (OpenStation docs/examples/register-widget.md, #1603): no
		// poll while the tab is hidden. The chain keeps its backoff: `nextAt`
		// is when the next call is due, `arm()` schedules it only while visible,
		// and reveal re-arms for whatever of that wait is left (zero when the
		// data went stale in the background), so a quick tab flip costs no call.
		// Focus-aware (assets/snt-poll-cadence.js): the full rate while the
		// window is focused, IDLE_MS while it is only visible. A failure's
		// backoff (or the server's retry_after) still wins when it is longer.
		function cadence( ms ) {
			return window.sntPollCadence ? window.sntPollCadence.wait( ms ) : ms;
		}
		function arm() {
			window.clearTimeout( timer );
			if ( torn || pending || document.hidden ) { return; }
			nextAt = lastAt + Math.max( lastDelay, cadence( REFRESH_MS ) );
			timer = window.setTimeout( refresh, Math.max( 0, nextAt - Date.now() ) );
		}
		function onVisibilityChange() {
			if ( document.hidden ) { window.clearTimeout( timer ); return; }
			arm();
		}
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		// Focus in or out re-arms the same way: back in focus, a wait that ran
		// past the full rate fires at once; out of focus, it stretches.
		var unwatchFocus = window.sntPollCadence ? window.sntPollCadence.onFocusChange( onVisibilityChange ) : function() {};

		refresh();

		return function teardown() {
			torn = true;
			window.clearTimeout( timer );
			window.clearInterval( ageTimer );
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
			unwatchFocus();
			if ( controller ) { controller.abort(); }
			container.textContent = '';
		};
	}

	window.desktopModeWidgets['sn-deploy-status'] = mount;

} )();
