/**
 * Signal & Noise Tools — desktop-mode command bindings.
 *
 * Registered as the 'sn-desktop-mode' WP script handle in
 * inc/desktop-mode-integration.php and loaded by desktop-mode when any
 * SN command is invoked.
 *
 * Pattern: each PHP-registered command has a matching wp.desktop.
 * registerCommand({ slug, run }) call here that attaches the JS callback.
 * Maintenance commands hit REST via wp.apiFetch (auto _wpnonce);
 * Navigation commands set window.location.href; Info commands read
 * from window.snDesktopData (localized in PHP) and dispatch a toast.
 *
 * Gracefully no-ops if wp.desktop isn't available (defensive — script
 * shouldn't be loaded in that case, but better safe).
 *
 * @since plugin v1.15.0
 */
( function() {
	'use strict';

	// Defensive: bail if the shell APIs aren't on the page.
	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.desktop || typeof window.wp.desktop.registerCommand !== 'function' ) {
		return;
	}

	var data = window.snDesktopData || {};
	var pages = data.pages || {};
	var restNs = ( data.restNamespace || 'signal-noise/v1' ) + '/cmd/';

	/**
	 * Toast helper. desktop-mode exposes wp.desktop.notify() per the
	 * hooks reference; we fall back to wp.data dispatch or console if
	 * not available (defensive across shell versions).
	 */
	function toast( message, type ) {
		type = type || 'success';
		if ( window.wp.desktop.notify && typeof window.wp.desktop.notify === 'function' ) {
			window.wp.desktop.notify( { message: message, type: type } );
			return;
		}
		// Fallback: dispatch a WP notice via the standard data store.
		if ( window.wp.data && window.wp.data.dispatch && window.wp.data.dispatch( 'core/notices' ) ) {
			window.wp.data.dispatch( 'core/notices' ).createNotice( type, message, { isDismissible: true } );
			return;
		}
		// Last resort: console + native alert (avoid alert; just log).
		// eslint-disable-next-line no-console
		console.log( '[SN]', message );
	}

	/**
	 * REST helper using wp.apiFetch (auto _wpnonce header from
	 * wp-api-fetch dependency).
	 */
	function callRest( action ) {
		if ( ! window.wp.apiFetch ) {
			toast( 'wp.apiFetch unavailable — SN command cannot dispatch.', 'error' );
			return Promise.reject( new Error( 'no apiFetch' ) );
		}
		return window.wp.apiFetch( {
			path: '/' + restNs + action,
			method: 'POST',
		} );
	}

	/**
	 * Navigation helper. Inside desktop-mode, location nav within the
	 * window triggers the shell's iframe routing automatically — same
	 * behavior as clicking a wp-admin link.
	 */
	function navigate( url ) {
		if ( ! url ) {
			return;
		}
		// Defense-in-depth: every caller passes a server-localized admin_url()
		// value, but resolve + same-origin-check the target anyway, so a future
		// caller can't turn this into an open-redirect or a javascript: sink.
		try {
			var dest = new URL( url, window.location.origin );
			if ( dest.origin === window.location.origin ) {
				window.location.href = dest.href;
			}
		} catch ( e ) {
			// Malformed URL — refuse to navigate.
		}
	}

	/**
	 * Version-info toast helper. Reads from localized snDesktopData.
	 */
	function versionToast( pkg ) {
		var label = pkg === 'theme' ? 'Theme' : 'Plugin';
		var info = data[ pkg ] || {};
		var current = info.current || '—';
		var state = info.state || 'unknown';
		var msg = label + ': v' + current;
		if ( state === 'ok' ) {
			msg += ' (up to date)';
		} else if ( state === 'available' ) {
			msg += ' (v' + ( info.latest || '?' ) + ' available)';
		} else {
			msg += ' (state unknown)';
		}
		toast( msg, state === 'available' ? 'info' : 'success' );
	}

	/* ── COMMAND BINDINGS ──────────────────────────────────────────────── */

	// v2.5.5: aiCallable opt-in per command. Per desktop-mode docs/javascript-
	// reference.md, this flag harvests the command into wp.desktop.ai.ask()'s
	// tool registry. The built-in ⌘K AI Copilot overlay can then invoke it
	// by name when the user types a matching natural-language query.
	//
	// Conservative selection: opt in non-destructive commands (navigation,
	// info, idempotent transient clears). Skip the 3 destructive maintenance
	// commands (purge-caches, clear-overrides, full-reset) — typing them
	// explicitly via the palette IS the safety check. Quote from the
	// desktop-mode docs: "AI tool-calling is a paraphrasing channel, and
	// handing the model every registered command (including destructive
	// ones) would turn a typo into a catastrophe."

	// Maintenance.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-force-check',
		aiCallable: true, // v2.5.5: idempotent, clears transients only — safe.
		run: function() {
			callRest( 'force-check' )
				.then( function( res ) { toast( res.message || 'Update caches cleared.' ); } )
				.catch( function( err ) { toast( 'Force-check failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-purge-caches',
		// v2.5.5: aiCallable INTENTIONALLY OMITTED — destructive. Manual ⌘K only.
		run: function() {
			callRest( 'purge-caches' )
				.then( function( res ) { toast( res.message || 'All caches purged.' ); } )
				.catch( function( err ) { toast( 'Purge failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-clear-overrides',
		// v2.5.5: aiCallable INTENTIONALLY OMITTED — deletes DB rows. Manual only.
		run: function() {
			callRest( 'clear-overrides' )
				.then( function( res ) { toast( res.message || 'Overrides cleared.' ); } )
				.catch( function( err ) { toast( 'Clear overrides failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-full-reset',
		// v2.5.5: aiCallable INTENTIONALLY OMITTED — combines the two destructive
		// commands above; even bigger blast radius. Manual ⌘K only.
		run: function() {
			// v4.1.1 (U-01): sntConfirm replaces window.confirm (which is blocked
			// inside the desktop-mode portal iframe by the chrome-extension boundary).
			// Falls back to native confirm if snt-confirm.js didn't enqueue.
			var prompt = ( typeof window.sntConfirm === 'function' )
				? window.sntConfirm( {
					title:        'Run Full Reset?',
					message:      'This clears every template override AND purges every cache. There is no undo.',
					confirmLabel: 'Full Reset',
					danger:       true,
				} )
				: Promise.resolve( window.confirm( 'Run Full Reset?\n\nThis clears every template override AND purges every cache.' ) );
			prompt.then( function ( confirmed ) {
				if ( ! confirmed ) { return; }
				callRest( 'full-reset' )
					.then( function( res ) { toast( res.message || 'Full reset complete.' ); } )
					.catch( function( err ) { toast( 'Full reset failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
			} );
		},
	} );

	// Navigation. All aiCallable — pure navigation, no state change.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-dashboard',    aiCallable: true, run: function() { navigate( pages.dashboard ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-identity',     aiCallable: true, run: function() { navigate( pages.identity ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-login',        aiCallable: true, run: function() { navigate( pages.login ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-cloudflare',   aiCallable: true, run: function() { navigate( pages.cloudflare ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-rss',          aiCallable: true, run: function() { navigate( pages.rss ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-reading-time', aiCallable: true, run: function() { navigate( pages.reading_time ); } } );

	// Info. Both aiCallable — read-only toast.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-theme',  aiCallable: true, run: function() { versionToast( 'theme' ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-plugin', aiCallable: true, run: function() { versionToast( 'plugin' ); } } );

	// Cron Dashboard (v3.0.0) — both aiCallable, read-only.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-cron-health',
		aiCallable: true,
		run: function() {
			var summary = data.cronSummary || {};
			toast(
				'Cron: ' + ( summary.total || 0 ) + ' events, ' +
				( summary.sn_count || 0 ) + ' SN-owned, ' +
				( summary.orphans || 0 ) + ' orphan' + ( summary.orphans === 1 ? '' : 's' ),
				'info'
			);
			navigate( pages.cron );
		}
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-cron-list',
		aiCallable: true,
		run: function() {
			navigate( pages.cron );
		}
	} );

	// Insights (v3.6.0) — aiCallable, read-only summary toast + navigate.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-insights',
		aiCallable: true,
		run: function() {
			var summary = data.insightsSummary || {};
			if ( summary.active_count !== undefined ) {
				toast(
					summary.active_count + ' active recommendation' +
					( summary.active_count === 1 ? '' : 's' ) +
					' from your last Insights scan.',
					'info'
				);
			}
			navigate( pages.insights );
		}
	} );

	// Audit log (v3.8.3) — both aiCallable, read-only fetch + toast.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-audit-summary',
		aiCallable: true,
		run: function() {
			if ( ! window.wp.apiFetch ) {
				toast( 'wp.apiFetch unavailable.', 'error' );
				return;
			}
			window.wp.apiFetch( { path: '/signal-noise/v1/audit/summary' } )
				.then( function( s ) {
					var msg = 'Last 24h: ' + ( s.last_24h.all_total || 0 ) + ' events (' +
						( s.last_24h.failed_total || 0 ) + ' failed, ' +
						( s.last_24h.recon_total || 0 ) + ' recon). ' +
						'7d trend: ' + ( s.last_7d_vs_prior.pct_delta >= 0 ? '+' : '' ) +
						s.last_7d_vs_prior.pct_delta + '%. ' +
						'Unique IPs (24h): ' + ( s.unique_attackers_24h || 0 ) + '. ' +
						'LLA lockouts: ' + ( s.lla.active_lockouts || 0 ) + '.';
					toast( msg, 'info' );
				} )
				.catch( function( err ) {
					toast( 'Audit summary failed: ' + ( err.message || 'unknown error' ), 'error' );
				} );
		}
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-audit-recent-logins',
		aiCallable: true,
		run: function() {
			if ( ! window.wp.apiFetch ) {
				toast( 'wp.apiFetch unavailable.', 'error' );
				return;
			}
			window.wp.apiFetch( { path: '/signal-noise/v1/audit/login-successes?days=30' } )
				.then( function( rows ) {
					if ( ! rows || ! rows.length ) {
						toast( 'No successful logins in last 30 days.', 'info' );
						return;
					}
					var last10 = rows.slice( 0, 10 );
					var msg = 'Last ' + last10.length + ' logins: ' +
						last10.map( function( r ) { return r.formatted + ' (' + r.user + ')'; } ).join( '; ' );
					toast( msg, 'info' );
				} )
				.catch( function( err ) {
					toast( 'Recent logins failed: ' + ( err.message || 'unknown error' ), 'error' );
				} );
		}
	} );

} )();
