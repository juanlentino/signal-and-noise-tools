/**
 * Signal & Noise Tools — WP 7.0 Command Palette commands (v2.5.0+).
 *
 * Registers 5 SN actions in WordPress 7.0's ⌘K palette. Each command's
 * callback invokes a registered ability via the wp-abilities REST API
 * (with the correct HTTP verb per the ability's annotations) rather than
 * calling our legacy /signal-noise/v1/cmd/* endpoints directly.
 *
 * Why not @wordpress/core-abilities: that package is loaded as an ES
 * script module (wp_enqueue_script_module) and can't be imported from
 * classic-script IIFE files like this one. wp.apiFetch against the
 * abilities REST URLs works fine in classic scripts; we just pick the
 * verb manually based on each ability's annotation set (known at
 * registration time).
 *
 * Annotation → verb mapping (per WordPress/abilities-api docs/rest-api.md):
 *   readonly:    true → GET    (input via ?input=<encoded JSON>)
 *   destructive: true → DELETE (same input shape)
 *   neither           → POST   (input in JSON body as { input: ... })
 *
 * Verified against:
 *   - WordPress/abilities-api docs/rest-api.md (REST endpoint /run shape)
 *   - WordPress/gutenberg/packages/commands/src/store/index.js (store name)
 *   - .../components/command-menu.js (callback({ close }) signature)
 *
 * @since plugin v2.5.0
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
	var dispatch = wp.data.dispatch( 'core/commands' );
	if ( ! dispatch || typeof dispatch.registerCommand !== 'function' ) {
		return;
	}

	var cfg = window.sntCommandPalette || {};
	var dashboardUrl = cfg.dashboardUrl || '/wp-admin/admin.php?page=sn-theme-options';

	// Dashicon as React element via wp.element.createElement (no JSX build step).
	function dashicon( name ) {
		if ( ! wp.element || ! wp.element.createElement ) {
			return undefined;
		}
		return wp.element.createElement( 'span', {
			className: 'dashicons dashicons-' + name,
			'aria-hidden': 'true',
		} );
	}

	// Snackbar via core/notices.
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

	/**
	 * Execute a registered SN ability via the abilities REST API.
	 *
	 * @param {string} name         Full ability name (e.g. 'signal-noise/purge-all-caches')
	 * @param {object} annotations  { readonly?, destructive? } — same shape as PHP meta.annotations
	 * @param {object|null} input   Input payload matching the ability's input_schema. Pass null for no input.
	 * @returns {Promise<any>}      Resolves with the ability's output payload, rejects with WP_Error JSON.
	 */
	function executeAbility( name, annotations, input ) {
		var verb = annotations.readonly    ? 'GET'
		         : annotations.destructive ? 'DELETE'
		         :                           'POST';
		var path = '/wp-abilities/v1/' + name + '/run';
		var opts = { path: path, method: verb };
		if ( input !== null && input !== undefined ) {
			if ( verb === 'POST' ) {
				opts.data = { input: input };
			} else {
				opts.path += '?input=' + encodeURIComponent( JSON.stringify( input ) );
			}
		}
		return wp.apiFetch( opts );
	}

	// Generic runner: close palette → execute → snackbar result.
	function run( label, name, annotations, input, close, onSuccess ) {
		if ( typeof close === 'function' ) {
			close();
		}
		executeAbility( name, annotations, input )
			.then( function( res ) {
				if ( typeof onSuccess === 'function' ) {
					onSuccess( res, label );
				} else {
					var msg = ( res && res.message ) ? res.message : __( 'Done.', 'signal-noise-tools' );
					showToast( label + ': ' + msg, 'ok' );
				}
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
			run(
				__( 'Purge caches', 'signal-noise-tools' ),
				'signal-noise/purge-all-caches',
				{ destructive: true, idempotent: true },
				{},  // ability accepts empty input
				args.close
			);
		},
	} );

	dispatch.registerCommand( {
		name: 'signal-noise/clear-template-overrides',
		label: __( 'SN: Clear template overrides', 'signal-noise-tools' ),
		icon: dashicon( 'editor-removeformatting' ),
		callback: function( args ) {
			run(
				__( 'Clear overrides', 'signal-noise-tools' ),
				'signal-noise/clear-template-overrides',
				{ destructive: true, idempotent: true },
				{},
				args.close
			);
		},
	} );

	dispatch.registerCommand( {
		name: 'signal-noise/force-check-updates',
		label: __( 'SN: Force-check updates', 'signal-noise-tools' ),
		icon: dashicon( 'update' ),
		callback: function( args ) {
			run(
				__( 'Force-check', 'signal-noise-tools' ),
				'signal-noise/force-check-updates',
				{ idempotent: true },  // not readonly (clears state); not destructive (no user data)
				{},
				args.close
			);
		},
	} );

	dispatch.registerCommand( {
		name: 'signal-noise/get-deploy-status',
		label: __( 'SN: Show deploy status', 'signal-noise-tools' ),
		icon: dashicon( 'chart-line' ),
		callback: function( args ) {
			run(
				__( 'Status', 'signal-noise-tools' ),
				'signal-noise/get-deploy-status',
				{ readonly: true, idempotent: true },
				null,
				args.close,
				function( res, label ) {
					var theme  = res && res.theme  || {};
					var plugin = res && res.plugin || {};
					var themeMsg  = 'Theme '  + ( theme.current  || '?' ) + ( theme.state  === 'available' ? ' (update: ' + theme.latest  + ')' : '' );
					var pluginMsg = 'Plugin ' + ( plugin.current || '?' ) + ( plugin.state === 'available' ? ' (update: ' + plugin.latest + ')' : '' );
					showToast( themeMsg + ' · ' + pluginMsg, 'ok' );
				}
			);
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
