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
 * Fetches /signal-noise/v1/cmd/status (read-only, capability-gated) on
 * mount and every 60s thereafter (matches the GHA runs cache TTL).
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

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.desktop || typeof window.wp.desktop.registerWidget !== 'function' ) {
		return;
	}

	var data = window.snDesktopData || {};
	var dashboardUrl = ( data.pages && data.pages.dashboard ) || '';
	var restNs = ( data.restNamespace || 'signal-noise/v1' ) + '/cmd/';
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
			case 'ok':        return { label: '✓', color: '#0a5a1a' };
			case 'available': return { label: '↑', color: '#b78103' };
			default:          return { label: '?',      color: '#8b1a1a' };
		}
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function renderLoading( container ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:14px 16px;font-size:13px;color:#646970;',
			text:  'Loading deploy status…',
		} ) );
	}

	function renderError( container, message ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'font-family:sans-serif;padding:14px 16px;font-size:12px;color:#cc1818;',
			text:  'Status fetch failed: ' + ( message || 'unknown' ),
		} ) );
	}

	function renderCard( container, status ) {
		clearChildren( container );

		var wrap = el( 'div', {
			className: 'sn-dm-widget',
			style:     'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;padding:14px 16px;color:#1d2327;',
		} );

		wrap.appendChild( el( 'p', {
			style: 'margin:0 0 10px;font-size:0.72rem;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#646970;',
			text:  'Signal & Noise',
		} ) );

		var grid = el( 'div', {
			style: 'display:grid;grid-template-columns:auto 1fr auto;gap:4px 12px;font-size:13px;line-height:1.4;align-items:baseline;',
		} );

		[ 'theme', 'plugin' ].forEach( function( pkg ) {
			var info = status[ pkg ] || {};
			var glyph = stateGlyph( info.state || 'unknown' );

			grid.appendChild( el( 'span', {
				style: 'color:#646970;',
				text:  pkg === 'theme' ? 'Theme' : 'Plugin',
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;font-weight:500;',
				text:  info.current || '—',
			} ) );
			grid.appendChild( el( 'span', {
				style: 'color:' + glyph.color + ';font-weight:600;',
				text:  glyph.label,
			} ) );
		} );

		wrap.appendChild( grid );

		wrap.appendChild( el( 'p', {
			style: 'margin:10px 0 0;padding-top:8px;border-top:1px solid #e0e0e0;font-size:11px;color:#646970;',
			text:  'Last deploy: ' + ( status.last_deploy || 'unknown' ),
		} ) );

		if ( dashboardUrl ) {
			wrap.appendChild( el( 'a', {
				style: 'display:inline-block;margin-top:8px;font-size:11px;color:#2271b1;text-decoration:none;',
				text:  'Open Dashboard →',
				href:  dashboardUrl,
			} ) );
		}

		container.appendChild( wrap );
	}

	function render( container ) {
		if ( ! container ) { return; }

		renderLoading( container );

		function refresh() {
			if ( ! window.wp.apiFetch ) {
				renderError( container, 'wp.apiFetch unavailable' );
				return;
			}
			window.wp.apiFetch( { path: '/' + restNs + 'status' } )
				.then( function( res ) {
					if ( res && res.data ) {
						renderCard( container, res.data );
					}
				} )
				.catch( function( err ) {
					renderError( container, err && err.message ? err.message : 'unknown' );
				} );
		}

		refresh();

		var intervalId = window.setInterval( function() {
			if ( ! container.isConnected ) {
				window.clearInterval( intervalId );
				return;
			}
			refresh();
		}, REFRESH_MS );
	}

	window.wp.desktop.registerWidget( {
		id:     'sn-deploy-status',
		render: render,
	} );

} )();
