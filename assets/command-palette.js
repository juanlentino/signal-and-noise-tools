/**
 * Signal & Noise Tools — WP 7.0 Command Palette commands.
 *
 * Registers 5 SN actions via @wordpress/commands using the imperative API
 *   wp.data.dispatch( 'core/commands' ).registerCommand( config )
 * (no React tree needed, unlike the useCommand hook).
 *
 * Commands:
 *   1. SN: Purge all caches              — POST /cmd/purge-caches
 *   2. SN: Clear template overrides      — POST /cmd/clear-overrides
 *   3. SN: Force-check updates           — POST /cmd/force-check
 *   4. SN: Show deploy status            — GET  /cmd/status (snackbar)
 *   5. SN: Open Signal & Noise settings  — navigate to dashboard
 *
 * Bail policy: if wp.commands, wp.data, or wp.apiFetch is missing (e.g.
 * WP < 7.0, no admin context, or a stripped-down install) we silently
 * return. The plugin behaves identically when the palette is unavailable.
 *
 * Verified against:
 *   - WordPress/gutenberg/packages/commands/src/store/index.js — store
 *     name is 'core/commands'
 *   - .../components/command-menu.js — callback invoked as
 *     command.callback( { close } )
 *
 * @since plugin v2.3.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp ) {
		return;
	}
	var wp = window.wp;

	if ( ! wp.commands || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	var __ = ( wp.i18n && wp.i18n.__ ) || function( s ) { return s; };
	var cfg = window.sntCommandPalette || {};
	var restNs = cfg.restNamespace || 'signal-noise/v1';
	var dashboardUrl = cfg.dashboardUrl || '/wp-admin/admin.php?page=sn-theme-options';

	var dispatch = wp.data.dispatch( 'core/commands' );
	if ( ! dispatch || typeof dispatch.registerCommand !== 'function' ) {
		return;
	}

	// Dashicon-as-React-element helper. The commands API expects icon to
	// be a JSX element; we use createElement to produce a dashicon span
	// without needing a JSX build step.
	function dashicon( name ) {
		if ( ! wp.element || ! wp.element.createElement ) {
			return undefined;
		}
		return wp.element.createElement( 'span', {
			className: 'dashicons dashicons-' + name,
			'aria-hidden': 'true',
		} );
	}

	// Snackbar via core/notices — the canonical WP toast surface. Falls
	// through to console.log only as a defensive last resort (shouldn't
	// fire on a normal 7.0 install).
	function showToast( text, kind ) {
		var notices = wp.data.dispatch( 'core/notices' );
		if ( notices && typeof notices.createNotice === 'function' ) {
			notices.createNotice(
				kind === 'err' ? 'error' : 'success',
				text,
				{ type: 'snackbar', isDismissible: true }
			);
			return;
		}
		// eslint-disable-next-line no-console
		console.log( '[SN]', text );
	}

	// Generic REST runner used by the 3 maintenance commands. Close the
	// palette as soon as the request lands (so the user sees the snackbar
	// against their normal admin surface, not behind the palette overlay).
	function runRest( method, path, close, label ) {
		if ( typeof close === 'function' ) {
			close();
		}
		wp.apiFetch( {
			path: '/' + restNs + path,
			method: method,
		} )
			.then( function( res ) {
				var msg = ( res && res.message ) ? res.message : __( 'Done.', 'signal-noise-tools' );
				showToast( label + ': ' + msg, 'ok' );
			} )
			.catch( function( err ) {
				var msg = ( err && err.message ) ? err.message : __( 'Unknown error.', 'signal-noise-tools' );
				showToast( label + ': ' + msg, 'err' );
			} );
	}

	// ─── Command registrations ───────────────────────────────────────

	dispatch.registerCommand( {
		name: 'signal-noise/purge-caches',
		label: __( 'SN: Purge all caches', 'signal-noise-tools' ),
		icon: dashicon( 'trash' ),
		callback: function( args ) {
			runRest( 'POST', '/cmd/purge-caches', args.close, __( 'Purge caches', 'signal-noise-tools' ) );
		},
	} );

	dispatch.registerCommand( {
		name: 'signal-noise/clear-template-overrides',
		label: __( 'SN: Clear template overrides', 'signal-noise-tools' ),
		icon: dashicon( 'editor-removeformatting' ),
		callback: function( args ) {
			runRest( 'POST', '/cmd/clear-overrides', args.close, __( 'Clear overrides', 'signal-noise-tools' ) );
		},
	} );

	dispatch.registerCommand( {
		name: 'signal-noise/force-check-updates',
		label: __( 'SN: Force-check updates', 'signal-noise-tools' ),
		icon: dashicon( 'update' ),
		callback: function( args ) {
			runRest( 'POST', '/cmd/force-check', args.close, __( 'Force-check', 'signal-noise-tools' ) );
		},
	} );

	dispatch.registerCommand( {
		name: 'signal-noise/get-deploy-status',
		label: __( 'SN: Show deploy status', 'signal-noise-tools' ),
		icon: dashicon( 'chart-line' ),
		callback: function( args ) {
			if ( typeof args.close === 'function' ) {
				args.close();
			}
			wp.apiFetch( {
				path: '/' + restNs + '/cmd/status',
				method: 'GET',
			} )
				.then( function( res ) {
					var theme  = ( res && res.data && res.data.theme )  || {};
					var plugin = ( res && res.data && res.data.plugin ) || {};
					var themeMsg  = 'Theme '  + ( theme.current  || '?' ) + ( theme.state  === 'available' ? ' (update: ' + theme.latest  + ')' : '' );
					var pluginMsg = 'Plugin ' + ( plugin.current || '?' ) + ( plugin.state === 'available' ? ' (update: ' + plugin.latest + ')' : '' );
					showToast( themeMsg + ' · ' + pluginMsg, 'ok' );
				} )
				.catch( function( err ) {
					var msg = ( err && err.message ) ? err.message : __( 'Unknown error.', 'signal-noise-tools' );
					showToast( __( 'Status fetch failed', 'signal-noise-tools' ) + ': ' + msg, 'err' );
				} );
		},
	} );

	dispatch.registerCommand( {
		name: 'signal-noise/open-settings',
		label: __( 'SN: Open Signal & Noise settings', 'signal-noise-tools' ),
		icon: dashicon( 'admin-settings' ),
		callback: function( args ) {
			if ( typeof args.close === 'function' ) {
				args.close();
			}
			window.location.assign( dashboardUrl );
		},
	} );
} )();
