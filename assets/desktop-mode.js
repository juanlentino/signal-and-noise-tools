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

	// Maintenance.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-force-check',
		run: function() {
			callRest( 'force-check' )
				.then( function( res ) { toast( res.message || 'Update caches cleared.' ); } )
				.catch( function( err ) { toast( 'Force-check failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-purge-caches',
		run: function() {
			callRest( 'purge-caches' )
				.then( function( res ) { toast( res.message || 'All caches purged.' ); } )
				.catch( function( err ) { toast( 'Purge failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-clear-overrides',
		run: function() {
			callRest( 'clear-overrides' )
				.then( function( res ) { toast( res.message || 'Overrides cleared.' ); } )
				.catch( function( err ) { toast( 'Clear overrides failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-full-reset',
		run: function() {
			if ( ! window.confirm( 'Run Full Reset?\n\nThis clears every template override AND purges every cache.' ) ) {
				return;
			}
			callRest( 'full-reset' )
				.then( function( res ) { toast( res.message || 'Full reset complete.' ); } )
				.catch( function( err ) { toast( 'Full reset failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	// Navigation.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-dashboard',    run: function() { navigate( pages.dashboard ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-identity',     run: function() { navigate( pages.identity ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-login',        run: function() { navigate( pages.login ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-cloudflare',   run: function() { navigate( pages.cloudflare ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-plausible',    run: function() { navigate( pages.plausible ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-rss',          run: function() { navigate( pages.rss ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-reading-time', run: function() { navigate( pages.reading_time ); } } );

	// Info.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-theme',  run: function() { versionToast( 'theme' ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-plugin', run: function() { versionToast( 'plugin' ); } } );

} )();
