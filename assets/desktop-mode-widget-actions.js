/**
 * Signal & Noise Tools — desktop-mode "Quick Actions" widget.
 *
 * Renders three buttons for the most-used maintenance operations.
 * Dispatches each via its ability run-path (v6.55.0; previously the
 * /signal-noise/v1/cmd/{action} REST endpoints) — already auth + capability
 * gated. Replaces the 3-click path of S&N → Dashboard tab → Maintenance
 * section with single-click access from the desktop.
 *
 * Actions: Purge All Caches | Clear DB Overrides | Full Reset
 *
 * Pattern matches assets/desktop-mode-widget.js (same DOM-built,
 * textContent-only, self-contained inline styles, wp.apiFetch for
 * REST + nonce handling).
 *
 * @since plugin v2.1.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.desktop || typeof window.wp.desktop.registerWidget !== 'function' ) {
		return;
	}

	var data    = window.snDesktopData || {};
	// v6.55.0: dispatch each maintenance action via its ability run-path.
	var ABILITY_BASE = '/wp-abilities/v1/abilities/signal-noise/';
	var CMD_ABILITY = {
		'purge-caches':    'purge-all-caches',
		'clear-overrides': 'clear-template-overrides',
		'full-reset':      'full-reset',
	};
	var TOAST_MS = 3500;

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.title != null ) { node.title = opts.title; }
		return node;
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function toast( widget, message, success ) {
		var existing = widget.querySelector( '.sn-dm-toast' );
		if ( existing ) { existing.remove(); }

		var t = el( 'div', {
			className: 'sn-dm-toast',
			style:     'margin-top:10px;padding:8px 10px;border-radius:3px;font-size:11px;line-height:1.35;background:' + ( success ? '#dff4dc' : '#fbe2e2' ) + ';color:' + ( success ? '#0a5a1a' : '#8b1a1a' ) + ';',
			text:      message,
		} );
		widget.appendChild( t );

		window.setTimeout( function() {
			if ( t.parentNode ) { t.parentNode.removeChild( t ); }
		}, TOAST_MS );
	}

	function runAction( widget, button, action, busyLabel, defaultMessage ) {
		if ( ! window.wp.apiFetch ) {
			toast( widget, 'wp.apiFetch unavailable', false );
			return;
		}
		if ( button.dataset.snBusy === '1' ) { return; }

		var originalText = button.textContent;
		button.dataset.snBusy = '1';
		button.textContent    = busyLabel;
		button.style.opacity  = '0.55';

		window.wp.apiFetch( {
			path:   ABILITY_BASE + ( CMD_ABILITY[ action ] || action ) + '/run',
			method: 'POST',
			data:   { input: {} },
		} )
			.then( function( res ) {
				var ok      = !! ( res && res.ok );
				var message = ( res && res.message ) ? res.message : defaultMessage;
				toast( widget, message, ok );
			} )
			.catch( function( err ) {
				toast( widget, ( err && err.message ) ? err.message : 'Action failed.', false );
			} )
			.finally( function() {
				button.textContent    = originalText;
				button.style.opacity  = '1';
				delete button.dataset.snBusy;
			} );
	}

	function render( container ) {
		if ( ! container ) { return; }
		clearChildren( container );

		var wrap = el( 'div', {
			style: 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;padding:14px 16px;color:#1d2327;',
		} );

		wrap.appendChild( el( 'p', {
			style: 'margin:0 0 10px;font-size:0.72rem;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#646970;',
			text:  'Quick actions',
		} ) );

		var btnStyle = 'display:block;width:100%;margin:0 0 6px;padding:7px 10px;background:#fff;color:#1d2327;border:1px solid #c3c4c7;border-radius:3px;font:13px/1.2 -apple-system,BlinkMacSystemFont,sans-serif;cursor:pointer;text-align:left;transition:background 120ms ease;';
		var dangerStyle = btnStyle + 'border-color:#a04848;';

		var btnPurge = el( 'button', {
			text:  'Purge all caches',
			style: btnStyle,
			title: 'Object cache + Breeze + Varnish + Cloudflare',
		} );
		btnPurge.addEventListener( 'click', function() {
			runAction( wrap, btnPurge, 'purge-caches', 'Purging…', 'Caches purged.' );
		} );
		wrap.appendChild( btnPurge );

		var btnClear = el( 'button', {
			text:  'Clear DB overrides',
			style: btnStyle,
			title: 'Remove wp_template / wp_template_part / wp_navigation DB rows',
		} );
		btnClear.addEventListener( 'click', function() {
			runAction( wrap, btnClear, 'clear-overrides', 'Clearing…', 'Overrides cleared.' );
		} );
		wrap.appendChild( btnClear );

		var btnReset = el( 'button', {
			text:  'Full reset',
			style: dangerStyle,
			title: 'Clear DB overrides AND purge every cache',
		} );
		btnReset.addEventListener( 'click', function() {
			runAction( wrap, btnReset, 'full-reset', 'Resetting…', 'Full reset complete.' );
		} );
		wrap.appendChild( btnReset );

		wrap.appendChild( el( 'p', {
			style: 'margin:8px 0 0;font-size:10px;color:#8c8f94;',
			text:  'Same actions as S&N → Dashboard → Maintenance',
		} ) );

		container.appendChild( wrap );
	}

	window.wp.desktop.registerWidget( {
		id:     'sn-quick-actions',
		render: render,
	} );

} )();
