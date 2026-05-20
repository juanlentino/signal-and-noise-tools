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
		if ( url ) {
			window.location.href = url;
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
			if ( ! window.confirm( 'Run Full Reset?\n\nThis clears every template override AND purges every cache.' ) ) {
				return;
			}
			callRest( 'full-reset' )
				.then( function( res ) { toast( res.message || 'Full reset complete.' ); } )
				.catch( function( err ) { toast( 'Full reset failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	// Navigation. All aiCallable — pure navigation, no state change.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-dashboard',    aiCallable: true, run: function() { navigate( pages.dashboard ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-identity',     aiCallable: true, run: function() { navigate( pages.identity ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-login',        aiCallable: true, run: function() { navigate( pages.login ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-cloudflare',   aiCallable: true, run: function() { navigate( pages.cloudflare ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-plausible',    aiCallable: true, run: function() { navigate( pages.plausible ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-rss',          aiCallable: true, run: function() { navigate( pages.rss ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-reading-time', aiCallable: true, run: function() { navigate( pages.reading_time ); } } );

	// Info. Both aiCallable — read-only toast.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-theme',  aiCallable: true, run: function() { versionToast( 'theme' ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-plugin', aiCallable: true, run: function() { versionToast( 'plugin' ); } } );

} )();
