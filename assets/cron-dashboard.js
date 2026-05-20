/**
 * Signal & Noise Tools — Cron Dashboard admin script.
 *
 * Loaded only on the wp-admin Cron tab. Wires:
 *   - Live filter input → hides rows whose hook doesn't match.
 *   - Run-now buttons → confirm() prompt, then POST /signal-noise/v1/
 *     cron/run via wp.apiFetch, then update the row's last-fired cell
 *     inline + show a toast.
 *
 * Toast falls back through wp.data dispatch → console.log if no
 * standard notice channel is available. Matches the toast helper in
 * assets/desktop-mode.js for consistency.
 *
 * Inline DOM updates use textContent + createElement (NOT innerHTML)
 * to keep the XSS surface zero even though the data is server-derived.
 *
 * @since plugin v3.0.0
 */
( function() {
	'use strict';

	if ( typeof document === 'undefined' ) {
		return;
	}

	function toast( msg, type ) {
		type = type || 'success';
		if ( window.wp && window.wp.data && window.wp.data.dispatch( 'core/notices' ) ) {
			window.wp.data.dispatch( 'core/notices' ).createNotice( type, msg, { isDismissible: true } );
			return;
		}
		// eslint-disable-next-line no-console
		console.log( '[SN cron]', msg );
	}

	function formatTimestamp( unixSeconds ) {
		var d = new Date( unixSeconds * 1000 );
		// "YYYY-MM-DD HH:MM:SS" — matches wp_date( 'Y-m-d H:i:s' ) server-side.
		return d.toISOString().replace( 'T', ' ' ).replace( /\..+/, '' );
	}

	function updateLastFiredCell( tr, lastFiredTs ) {
		var cell = tr.querySelector( '.sn-cron-last-fired' );
		if ( ! cell || ! lastFiredTs ) {
			return;
		}
		// Safe DOM construction — clear, then append text + br + small.
		while ( cell.firstChild ) {
			cell.removeChild( cell.firstChild );
		}
		cell.appendChild( document.createTextNode( formatTimestamp( lastFiredTs ) ) );
		cell.appendChild( document.createElement( 'br' ) );
		var sm = document.createElement( 'small' );
		sm.textContent = 'just now';
		cell.appendChild( sm );
	}

	function wireFilter() {
		var input = document.getElementById( 'sn-cron-filter' );
		var table = document.getElementById( 'sn-cron-table' );
		if ( ! input || ! table ) {
			return;
		}
		input.addEventListener( 'input', function() {
			var needle = input.value.toLowerCase();
			var rows = table.querySelectorAll( 'tbody tr.sn-cron-row' );
			rows.forEach( function( tr ) {
				var hook = ( tr.getAttribute( 'data-hook' ) || '' ).toLowerCase();
				tr.style.display = hook.indexOf( needle ) === -1 ? 'none' : '';
			} );
		} );
	}

	function wireRunNow() {
		var buttons = document.querySelectorAll( '.sn-cron-run-now' );
		buttons.forEach( function( btn ) {
			btn.addEventListener( 'click', function( e ) {
				e.preventDefault();
				var tr = btn.closest( 'tr.sn-cron-row' );
				if ( ! tr ) {
					return;
				}
				var hook = tr.getAttribute( 'data-hook' );
				if ( ! window.confirm( "Run cron event '" + hook + "' now?" ) ) {
					return;
				}
				if ( ! window.wp || ! window.wp.apiFetch ) {
					toast( 'wp.apiFetch unavailable — cannot dispatch.', 'error' );
					return;
				}
				btn.disabled = true;
				btn.textContent = 'Running…';
				window.wp.apiFetch( {
					path: '/signal-noise/v1/cron/run',
					method: 'POST',
					data: { hook: hook }
				} ).then( function( res ) {
					if ( res && res.success ) {
						updateLastFiredCell( tr, res.last_fired_ts );
						toast( hook + ' fired in ' + Math.round( res.elapsed_ms ) + 'ms', 'success' );
					} else {
						toast( 'Run failed: ' + ( ( res && res.error ) || 'unknown error' ), 'error' );
					}
				} ).catch( function( err ) {
					toast( 'Run failed: ' + ( err.message || err ), 'error' );
				} ).finally( function() {
					btn.disabled = false;
					btn.textContent = 'Run now';
				} );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			wireFilter();
			wireRunNow();
		} );
	} else {
		wireFilter();
		wireRunNow();
	}
} )();
