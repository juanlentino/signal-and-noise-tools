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
 * IN AN OPENSTATION WINDOW every control here is a `type="button"` whose ONLY
 * behaviour is this file, and this file ran once — against the window's first
 * paint, a spinner — so Run now, Unschedule and the history toggles were
 * inert for the life of the window. Everything now arms through init( root ):
 * once at load with `document` (the classic page, unchanged) and again on each
 * `snt:paint` the host script dispatches after a repaint.
 *
 * @since plugin v3.0.0
 */
( function() {
	'use strict';

	if ( typeof document === 'undefined' ) {
		return;
	}

	// Which elements already carry their listener. A WeakSet and not an
	// attribute: a window's paint is a MORPH that reuses the node and strips
	// every attribute the server does not paint (zt, offset 26198 of
	// desktop-mode's app-runtime.min.js), so an attribute marker would be gone
	// by the next paint and each button would fire twice, then three times. A
	// row the diff genuinely replaced is a new element and arms on its own.
	var wired = new WeakSet();

	/**
	 * Bind `fn` to `event` on `el` at most once, ever.
	 *
	 * @param {Element}  el    Element to bind.
	 * @param {string}   event Event name.
	 * @param {Function} fn    Handler.
	 * @return {boolean} Whether this call was the one that bound it.
	 */
	function bindOnce( el, event, fn ) {
		if ( wired.has( el ) ) {
			return false;
		}
		wired.add( el );
		el.addEventListener( event, fn );
		return true;
	}

	// #1611: strings come through wp.i18n, fed by wp_set_script_translations()
	// on the handle in inc/cron-dashboard-admin.php; wp-i18n is a declared
	// dependency, so it is on the page before this file runs. The domain is
	// the plugin's text domain exactly: wp.i18n keys its registry by domain,
	// and a misspelt one falls back to the source string in silence.
	function __( text ) {
		return window.wp.i18n.__( text, 'signal-and-noise-tools' );
	}
	function sprintf() {
		return window.wp.i18n.sprintf.apply( null, arguments );
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
		sm.textContent = __( 'just now' );
		cell.appendChild( sm );
	}

	function wireFilter( scope ) {
		var input = scope.querySelector( '#sn-cron-filter' );
		if ( ! input || ! scope.querySelector( '#sn-cron-table' ) ) {
			return;
		}
		bindOnce( input, 'input', function() {
			// The table is re-read per keystroke, not captured: a repaint can
			// hand back a different <table> under the same reused input.
			var table = scope.querySelector( '#sn-cron-table' );
			if ( ! table ) {
				return;
			}
			var needle = input.value.toLowerCase();
			var rows = table.querySelectorAll( 'tbody tr.sn-cron-row' );
			rows.forEach( function( tr ) {
				var hook = ( tr.getAttribute( 'data-hook' ) || '' ).toLowerCase();
				tr.style.display = hook.indexOf( needle ) === -1 ? 'none' : '';
			} );
		} );
	}

	function wireRunNow( scope ) {
		var buttons = scope.querySelectorAll( '.sn-cron-run-now' );
		buttons.forEach( function( btn ) {
			bindOnce( btn, 'click', function( e ) {
				e.preventDefault();
				var tr = btn.closest( 'tr.sn-cron-row' );
				if ( ! tr ) {
					return;
				}
				var hook = tr.getAttribute( 'data-hook' );
				/* translators: %s is the cron hook name (e.g., wp_version_check) */
				var confirmRun = sprintf( __( "Run cron event '%s' now?" ), hook );
				// v4.1.1 (U-01): sntConfirm replaces window.confirm (which is
				// blocked by the desktop-mode portal iframe). Falls back to
				// window.confirm if the snt-confirm helper failed to enqueue.
				var prompt = ( typeof window.sntConfirm === 'function' )
					? window.sntConfirm( {
						title:             __( 'Run cron event now?' ),
						message:           confirmRun,
						confirmLabel:      __( 'Run now' ),
						originatingButton: btn,
					} )
					: Promise.resolve( window.confirm( confirmRun ) );
				prompt.then( function ( confirmed ) {
					if ( ! confirmed ) { return; }
					if ( ! window.wp || ! window.wp.apiFetch ) {
						toast( __( 'wp.apiFetch unavailable: cannot dispatch.' ), 'error' );
						return;
					}
					btn.disabled = true;
					btn.textContent = __( 'Running…' );
					// v6.55.0: dispatch via the run-cron-event ability run-path.
					// The ability now additively returns ok/last_fired_formatted/
					// elapsed_ms/error, so the inline cell update + toast are
					// preserved. (res.ok replaces the legacy res.success.)
					window.sntAbilityRun( 'run-cron-event', { hook: hook } ).then( function( res ) {
						if ( res && res.ok ) {
							// v3.0.1: use server-formatted timestamp (site
							// timezone, matches the rest of the table) instead
							// of client-side UTC toISOString.
							updateLastFiredCell( tr, res.last_fired_formatted );
							/* translators: 1: hook name, 2: elapsed time in milliseconds */
							toast( sprintf( __( '%1$s fired in %2$dms' ), hook, Math.round( res.elapsed_ms ) ), 'success' );
						} else {
							/* translators: %s is the error message returned by the ability */
							toast( sprintf( __( 'Run failed: %s' ), ( res && res.error ) || __( 'unknown error' ) ), 'error' );
						}
					} ).catch( function( err ) {
						toast( sprintf( __( 'Run failed: %s' ), err.message || err ), 'error' );
					} ).finally( function() {
						btn.disabled = false;
						btn.textContent = __( 'Run now' );
					} );
				} );
			} );
		} );
	}

	function wireHistory( scope ) {
		var toggles = scope.querySelectorAll( '.sn-cron-history-toggle' );
		toggles.forEach( function( btn ) {
			bindOnce( btn, 'click', function( e ) {
				e.preventDefault();
				var tr = btn.closest( 'tr.sn-cron-row' );
				if ( ! tr ) { return; }
				var panel = btn.nextElementSibling;
				if ( ! panel || ! panel.classList.contains( 'sn-cron-history-panel' ) ) { return; }

				var expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
				if ( expanded ) {
					panel.hidden = true;
					btn.setAttribute( 'aria-expanded', 'false' );
					btn.textContent = __( 'history' );
					return;
				}

				// Expanding: show + fetch.
				btn.setAttribute( 'aria-expanded', 'true' );
				btn.textContent = __( 'hide' );
				panel.hidden = false;

				// Loading state.
				while ( panel.firstChild ) { panel.removeChild( panel.firstChild ); }
				var loading = document.createElement( 'small' );
				loading.textContent = __( 'Loading history…' );
				panel.appendChild( loading );

				if ( ! window.wp || ! window.wp.apiFetch ) {
					panel.removeChild( loading );
					var err = document.createElement( 'small' );
					/* translators: %s is the error message returned by the ability */
					err.textContent = sprintf( __( 'Could not load history: %s' ), __( 'wp.apiFetch unavailable: cannot dispatch.' ) );
					panel.appendChild( err );
					return;
				}

				var hook = tr.getAttribute( 'data-hook' );
				// v6.55.0: read history via the get-cron-history ability run-path.
				// GET query params → POST { input: { hook, limit } }. The ability's
				// output_schema is a top-level array (get-cron-history returns the
				// rows bare, no { history } wrapper), so pass res straight through.
				// v7.7.2: readonly ability → GET via the runner (the old POST
				// 405'd; input rides as ?input[hook]=…&input[limit]=10).
				window.sntAbilityRun( 'get-cron-history', { hook: hook, limit: 10 } ).then( function( res ) {
					renderHistoryPanel( panel, Array.isArray( res ) ? res : [] );
				} ).catch( function( fetchErr ) {
					while ( panel.firstChild ) { panel.removeChild( panel.firstChild ); }
					var msg = document.createElement( 'small' );
					msg.textContent = sprintf( __( 'Could not load history: %s' ), fetchErr.message || fetchErr );
					panel.appendChild( msg );
				} );
			} );
		} );
	}

	function renderHistoryPanel( panel, rows ) {
		while ( panel.firstChild ) { panel.removeChild( panel.firstChild ); }

		if ( ! rows.length ) {
			var em = document.createElement( 'small' );
			em.textContent = __( 'No firings recorded yet (history tracking landed in plugin v3.2.0).' );
			panel.appendChild( em );
			return;
		}

		var table = document.createElement( 'table' );
		table.className = 'sn-cron-history-table';
		// Compact inline styles — no separate CSS file for this v3.2.0
		// surface. If usage grows, promote to assets/cron-dashboard.css.
		table.style.marginTop = '0.5rem';
		table.style.fontSize  = '0.85em';
		table.style.width     = '100%';
		table.style.borderCollapse = 'collapse';

		var thead = document.createElement( 'thead' );
		var thr   = document.createElement( 'tr' );
		[ __( 'Fired at' ), __( 'Elapsed' ), __( 'Status' ) ].forEach( function( label ) {
			var th = document.createElement( 'th' );
			th.scope = 'col';
			th.textContent = label;
			th.style.textAlign = 'left';
			th.style.padding   = '2px 6px';
			th.style.borderBottom = '1px solid #ccc';
			thr.appendChild( th );
		} );
		thead.appendChild( thr );
		table.appendChild( thead );

		var tbody = document.createElement( 'tbody' );
		rows.forEach( function( r ) {
			var trEl = document.createElement( 'tr' );

			var tdTime = document.createElement( 'td' );
			// fired_at is server-side UTC; convert client-side for display.
			// fired_at_ts is also server-provided as unix seconds.
			var dt = r.fired_at_ts ? new Date( r.fired_at_ts * 1000 ) : null;
			tdTime.textContent = dt ? dt.toLocaleString() : ( r.fired_at || '—' );
			tdTime.style.padding = '2px 6px';
			trEl.appendChild( tdTime );

			var tdMs = document.createElement( 'td' );
			tdMs.textContent = ( r.elapsed_ms === null || typeof r.elapsed_ms === 'undefined' )
				? '—'
				/* translators: %d is the elapsed time in milliseconds */
				: sprintf( __( '%dms' ), r.elapsed_ms );
			tdMs.style.padding = '2px 6px';
			trEl.appendChild( tdMs );

			var tdStatus = document.createElement( 'td' );
			tdStatus.textContent = r.success ? __( 'ok' ) : __( 'fail' );
			tdStatus.style.padding = '2px 6px';
			if ( ! r.success ) {
				tdStatus.style.color = '#dc3232';
				tdStatus.title = r.error_message || '';
			}
			trEl.appendChild( tdStatus );

			tbody.appendChild( trEl );
		} );
		table.appendChild( tbody );
		panel.appendChild( table );
	}

	function wireUnschedule( scope ) {
		var buttons = scope.querySelectorAll( '.sn-cron-unschedule' );
		buttons.forEach( function( btn ) {
			bindOnce( btn, 'click', function( e ) {
				e.preventDefault();
				var tr = btn.closest( 'tr.sn-cron-row' );
				if ( ! tr ) {
					return;
				}
				var hook = tr.getAttribute( 'data-hook' );
				/* translators: %s is the cron hook name; confirmation prompt before a destructive unschedule */
				var confirmUnschedule = sprintf( __( "Permanently unschedule '%s'?\n\nThis removes both the next firing AND the recurring schedule if any. Cannot be undone: the event will re-appear only if a plugin re-registers it." ), hook );
				// v4.1.1 (U-01): sntConfirm replaces window.confirm.
				var prompt = ( typeof window.sntConfirm === 'function' )
					? window.sntConfirm( {
						title:             __( 'Unschedule this cron event?' ),
						message:           confirmUnschedule,
						confirmLabel:      __( 'Unschedule' ),
						danger:            true,
						originatingButton: btn,
					} )
					: Promise.resolve( window.confirm( confirmUnschedule ) );
				prompt.then( function ( confirmed ) {
					if ( ! confirmed ) { return; }
					if ( ! window.wp || ! window.wp.apiFetch ) {
						toast( __( 'wp.apiFetch unavailable: cannot dispatch.' ), 'error' );
						return;
					}
					// Parse args off the data attribute so we send the exact
					// signature the server scheduled. Falls back to [] on any
					// parse error (matches the unschedule impl's default).
					var args = [];
					try {
						var raw = btn.getAttribute( 'data-args' );
						if ( raw ) {
							args = JSON.parse( raw );
							if ( ! Array.isArray( args ) ) {
								args = [];
							}
						}
					} catch ( err ) {
						args = [];
					}
					btn.disabled = true;
					btn.textContent = __( 'Unscheduling…' );
					// Also disable the Run-now button on the same row during
					// dispatch so users can't double-act on a half-removed event.
					var runBtn = tr.querySelector( '.sn-cron-run-now' );
					if ( runBtn ) { runBtn.disabled = true; }

					// v6.55.0: unschedule via the unschedule-cron-event ability
					// run-path. Output shape { success, hook, args, cleared } is
					// unchanged from the legacy route (same shared impl).
					// v7.7.2: destructive+idempotent → DELETE via the runner (the
					// old POST 405'd). Bracket transport carries args values as
					// strings; non-string cron args therefore no-match (cleared:0)
					// rather than mis-target — acceptable for the orphan-cleanup
					// use case this button serves.
					window.sntAbilityRun( 'unschedule-cron-event', { hook: hook, args: args } ).then( function( res ) {
					if ( res && res.success ) {
						if ( res.cleared > 0 ) {
							/* translators: 1: hook name, 2: number of events cleared */
							toast( sprintf( __( '%1$s unscheduled (%2$d event(s) cleared)' ), hook, res.cleared ), 'success' );
						} else {
							toast( __( 'No matching scheduled event found: likely already gone.' ), 'info' );
						}
						// Remove the row from the table. Wrapped in a
						// short fade so the change isn't jarring.
						tr.style.transition = 'opacity 0.25s ease';
						tr.style.opacity = '0';
						setTimeout( function() {
							if ( tr.parentNode ) {
								tr.parentNode.removeChild( tr );
							}
						}, 260 );
					} else {
						/* translators: %s is the error message returned by the ability */
						toast( sprintf( __( 'Unschedule failed: %s' ), ( res && res.error ) || __( 'unknown error' ) ), 'error' );
						btn.disabled = false;
						btn.textContent = __( 'Unschedule' );
						if ( runBtn ) { runBtn.disabled = false; }
					}
				} ).catch( function( err ) {
					toast( sprintf( __( 'Unschedule failed: %s' ), err.message || err ), 'error' );
					btn.disabled = false;
					btn.textContent = __( 'Unschedule' );
					if ( runBtn ) { runBtn.disabled = false; }
				} );
				} ); // close prompt.then
			} );
		} );
	}

	/**
	 * Wire every cron control inside `root`. Idempotent: a control already
	 * holding its listener is skipped, so the same root can be armed after
	 * every repaint without a button firing twice.
	 *
	 * @param {Element|Document} root Subtree to arm. Defaults to `document`.
	 */
	function init( root ) {
		var scope = root || document;
		wireFilter( scope );
		wireRunNow( scope );
		wireUnschedule( scope );
		wireHistory( scope );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			init( document );
		} );
	} else {
		init( document );
	}

	// assets/os-host.js dispatches this on `document` after every window paint,
	// with the painted root in detail.root. The classic page never fires it, so
	// nothing there changes.
	document.addEventListener( 'snt:paint', function( e ) {
		init( ( e.detail && e.detail.root ) || document );
	} );
} )();
