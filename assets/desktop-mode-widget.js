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
			style: 'padding:14px 16px;font-size:13px;opacity:.6;',
			text:  'Loading deploy status…',
		} ) );
	}

	function renderError( container, message ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'padding:14px 16px;font-size:12px;color:#ff9d94;',
			text:  'Status fetch failed: ' + ( message || 'unknown' ),
		} ) );
	}

	function renderCard( container, status ) {
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

		[ 'theme', 'plugin' ].forEach( function( pkg ) {
			var info = status[ pkg ] || {};
			var glyph = stateGlyph( info.state || 'unknown' );

			grid.appendChild( el( 'span', {
				style: 'opacity:.6;',
				text:  pkg === 'theme' ? 'Theme' : 'Plugin',
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;font-weight:500;',
				text:  info.current || '—',
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
			var wGlyph = stateGlyph( w.state || 'unknown' );
			grid.appendChild( el( 'span', {
				style: 'opacity:.6;',
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
			style: 'margin:10px 0 0;padding-top:8px;border-top:1px solid rgba(255,255,255,0.14);font-size:11px;opacity:.6;',
			text:  deployWhat
				? 'Last deploy: ' + deployWhat + ' · ' + deployAge
				: 'Last deploy: ' + deployAge,
		} );
		deployEl.title = 'Theme and plugin only. The Cloudflare workers deploy outside the WordPress upgrader, so their releases are not recorded in this feed.';
		wrap.appendChild( deployEl );

		if ( dashboardUrl ) {
			wrap.appendChild( el( 'a', {
				style: 'display:inline-block;margin-top:8px;font-size:11px;color:var(--os-window-link-accent, #4a9eff);text-decoration:none;',
				text:  'Open Dashboard →',
				href:  dashboardUrl,
			} ) );
		}

		container.appendChild( wrap );
	}

	/**
	 * v9.52.0: mount( container, ctx ) → teardown. See the contract note at
	 * the top of this file.
	 */
	function mount( container, ctx ) {
		if ( ! container ) { return function() {}; }

		var torn = false;
		renderLoading( container );

		function refresh() {
			if ( torn ) { return; }
			if ( ! window.sntAbilityRun ) {
				renderError( container, 'sntAbilityRun unavailable' );
				return;
			}
			// v7.7.2: readonly ability → the runner GETs it (POST 405'd).
			window.sntAbilityRun( 'get-deploy-status' )
				.then( function( res ) {
					if ( torn ) { return; }
					// The ability returns { theme, plugin, last_deploy,
					// last_deploy_component, last_gha_run }
					// at the root (no legacy { ok, data } envelope). v9.63.3:
					// last_deploy reads the MERGED feed (wp-admin installs + GHA
					// runs), so wp-admin Updates installs finally move this line;
					// last_gha_run is the old GHA-only reading, kept additively.
					if ( res && res.theme ) {
						renderCard( container, res );
					}
				} )
				.catch( function( err ) {
					if ( torn ) { return; }
					renderError( container, err && err.message ? err.message : 'unknown' );
				} );
		}

		refresh();

		var intervalId = window.setInterval( refresh, REFRESH_MS );

		return function teardown() {
			torn = true;
			window.clearInterval( intervalId );
			container.textContent = '';
		};
	}

	window.desktopModeWidgets['sn-deploy-status'] = mount;

} )();
