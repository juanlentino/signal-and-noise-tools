/**
 * Signal & Noise Tools — desktop-mode "RSS subscribers" widget.
 *
 * Surfaces RSS feed activity that previously lived only behind the
 * S&N → RSS tab + (since v2.0.1) a single line on the SN Dashboard
 * tab. This widget puts last-request-time + 24h / 7d / 30d unique
 * subscriber counts on the desktop at-a-glance.
 *
 * Data source: the signal-noise/get-rss-stats ability run-path (v6.55.0;
 * previously GET /signal-noise/v1/cmd/rss-stats). Wraps
 * sn_rss_tracker_window_stats_multi().
 *
 * Polling: every 5 min (RSS counts don't change rapidly; far less
 * urgent than the deploy-status widget's 60s cadence).
 *
 * Pattern matches assets/desktop-mode-widget.js exactly.
 *
 * @since plugin v2.1.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.desktop || typeof window.wp.desktop.registerWidget !== 'function' ) {
		return;
	}

	var data        = window.snDesktopData || {};
	var rssPageUrl  = ( data.pages && data.pages.rss ) || '';
	// v6.55.0: read RSS activity via the get-rss-stats ability run-path.
	var REFRESH_MS  = 5 * 60 * 1000;

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		return node;
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function renderLoading( container ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:14px 16px;font-size:13px;color:#646970;',
			text:  'Loading RSS activity…',
		} ) );
	}

	function renderError( container, message ) {
		clearChildren( container );
		container.appendChild( el( 'p', {
			style: 'font-family:sans-serif;padding:14px 16px;font-size:12px;color:#cc1818;',
			text:  'RSS fetch failed: ' + ( message || 'unknown' ),
		} ) );
	}

	function renderCard( container, stats ) {
		clearChildren( container );

		var wrap = el( 'div', {
			style: 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;padding:14px 16px;color:#1d2327;',
		} );

		wrap.appendChild( el( 'p', {
			style: 'margin:0 0 4px;font-size:0.72rem;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#646970;',
			text:  'RSS subscribers',
		} ) );

		wrap.appendChild( el( 'p', {
			style: 'margin:0 0 10px;font-size:11px;color:#8c8f94;',
			text:  stats.last_request_relative
				? 'Last request: ' + stats.last_request_relative
				: 'No requests yet',
		} ) );

		var windows = stats.windows || {};
		var grid = el( 'div', {
			style: 'display:grid;grid-template-columns:auto 1fr auto;gap:4px 14px;font-size:13px;line-height:1.4;align-items:baseline;',
		} );

		[
			{ key: '1',  label: '24h' },
			{ key: '7',  label: '7d'  },
			{ key: '30', label: '30d' },
		].forEach( function( w ) {
			var bucket = windows[ w.key ] || { total: 0, uniques: 0 };
			grid.appendChild( el( 'span', {
				style: 'color:#646970;',
				text:  w.label,
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;font-weight:500;',
				text:  Number( bucket.total || 0 ).toLocaleString() + ' req',
			} ) );
			grid.appendChild( el( 'span', {
				style: 'font-variant-numeric:tabular-nums;color:#646970;',
				text:  Number( bucket.uniques || 0 ).toLocaleString() + ' uniq',
			} ) );
		} );

		wrap.appendChild( grid );

		if ( rssPageUrl ) {
			wrap.appendChild( el( 'a', {
				style: 'display:inline-block;margin-top:10px;font-size:11px;color:#2271b1;text-decoration:none;',
				text:  'Open RSS tab →',
				href:  rssPageUrl,
			} ) );
		}

		container.appendChild( wrap );
	}

	function render( container ) {
		if ( ! container ) { return; }

		renderLoading( container );

		function refresh() {
			if ( ! window.sntAbilityRun ) {
				renderError( container, 'sntAbilityRun unavailable' );
				return;
			}
			// v7.7.2: readonly ability → the runner GETs it (POST 405'd).
			window.sntAbilityRun( 'get-rss-stats' )
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
		id:     'sn-rss-subscribers',
		render: render,
	} );

} )();
