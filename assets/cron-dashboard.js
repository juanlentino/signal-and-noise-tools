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

	// v3.0.2: strings come from PHP via wp_localize_script (sntCronI18n).
	// Defensive fallback to English so the module still works if the
	// localize call ever fails to enqueue (e.g., during a botched deploy).
	var I = ( typeof window !== 'undefined' && window.sntCronI18n ) || {
		running:           'Running…',
		runNow:            'Run now',
		justNow:           'just now',
		confirmRun:        "Run cron event '%s' now?",
		apiFetchMissing:   'wp.apiFetch unavailable — cannot dispatch.',
		unknownError:      'unknown error',
		firedTemplate:     '%1$s fired in %2$dms',
		runFailedTemplate: 'Run failed: %s'
	};

	function fmt1( tmpl, a ) { return String( tmpl ).replace( '%s', a ); }
	function fmtFired( hook, ms ) {
		return String( I.firedTemplate )
			.replace( '%1$s', hook )
			.replace( '%2$d', Math.round( ms ) );
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

	function updateLastFiredCell( tr, formatted ) {
		var cell = tr.querySelector( '.sn-cron-last-fired' );
		if ( ! cell || ! formatted ) {
			return;
		}
		// Safe DOM construction — clear, then append text + br + small.
		// v3.0.1: `formatted` is the server-side wp_date() output, so the
		// inline cell matches the rest of the table's timezone exactly.
		while ( cell.firstChild ) {
			cell.removeChild( cell.firstChild );
		}
		cell.appendChild( document.createTextNode( formatted ) );
		cell.appendChild( document.createElement( 'br' ) );
		var sm = document.createElement( 'small' );
		sm.textContent = I.justNow;
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
				if ( ! window.confirm( fmt1( I.confirmRun, hook ) ) ) {
					return;
				}
				if ( ! window.wp || ! window.wp.apiFetch ) {
					toast( I.apiFetchMissing, 'error' );
					return;
				}
				btn.disabled = true;
				btn.textContent = I.running;
				window.wp.apiFetch( {
					path: '/signal-noise/v1/cron/run',
					method: 'POST',
					data: { hook: hook }
				} ).then( function( res ) {
					if ( res && res.success ) {
						// v3.0.1: use server-formatted timestamp (site
						// timezone, matches the rest of the table) instead
						// of client-side UTC toISOString.
						updateLastFiredCell( tr, res.last_fired_formatted );
						toast( fmtFired( hook, res.elapsed_ms ), 'success' );
					} else {
						toast( fmt1( I.runFailedTemplate, ( res && res.error ) || I.unknownError ), 'error' );
					}
				} ).catch( function( err ) {
					toast( fmt1( I.runFailedTemplate, err.message || err ), 'error' );
				} ).finally( function() {
					btn.disabled = false;
					btn.textContent = I.runNow;
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
