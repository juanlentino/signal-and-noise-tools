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
	var newNoteUrl = cfg.newNoteUrl || '/wp-admin/post-new.php';
	var tabs = Array.isArray( cfg.tabs ) ? cfg.tabs : [];
	var notesCategoryId = parseInt( cfg.notesCategoryId, 10 ) || 0;

	// Entity-decode REST title.rendered (get_the_title() runs the_title →
	// HTML-entity-encoded). wp.htmlEntities.decodeEntities is the canonical
	// WP helper (@wordpress/html-entities, a hard dependency of this script).
	// The map fallback covers only the handful of named entities the_title
	// emits, in case the dep is ever stripped — labels are passed as plain
	// text to registerCommand so no markup ever renders.
	var ENTITY_FALLBACK = {
		'&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"',
		'&#039;': "'", '&#39;': "'", '&#8217;': '’', '&#8216;': '‘',
		'&#8220;': '“', '&#8221;': '”', '&#8211;': '–', '&#8212;': '—',
		'&nbsp;': ' ', '&hellip;': '…',
	};
	function decodeText( html ) {
		var s = html == null ? '' : String( html );
		if ( wp.htmlEntities && typeof wp.htmlEntities.decodeEntities === 'function' ) {
			return wp.htmlEntities.decodeEntities( s );
		}
		return s.replace( /&[a-z#0-9]+;/gi, function( m ) {
			return Object.prototype.hasOwnProperty.call( ENTITY_FALLBACK, m ) ? ENTITY_FALLBACK[ m ] : m;
		} );
	}

	// Close the palette (if open) then hard-navigate. Used by the New-Note,
	// tab-jump, and recent-Notes commands — these are plain navigations, not
	// ability calls, so they bypass the run()/executeAbility() machinery.
	function navigateTo( url, close ) {
		if ( typeof close === 'function' ) {
			close();
		}
		window.location.assign( url );
	}

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

	// Visible feedback — DOM-injected admin notice.
	//
	// v2.5.1: replaces the earlier `wp.data.dispatch('core/notices').createNotice`
	// path because @wordpress/core-commands (the WP-admin Command Palette
	// integration) only renders <CommandMenu />, NOT a <SnackbarList>. The
	// createNotice call would succeed (notice added to store) but no visible UI
	// would render on wp-admin pages outside the block editor. Verified by
	// inspecting WordPress/gutenberg/packages/core-commands/src/index.js on
	// 2026-05-20 — only element/router/commands imports, no notices import.
	//
	// Native WP `.notice` classes are universally styled in wp-admin. Auto-
	// dismiss after 6s. Click the × to dismiss earlier. Multiple notices stack.
	function showToast( text, kind ) {
		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-' + ( kind === 'err' ? 'error' : 'success' ) + ' is-dismissible';
		notice.setAttribute( 'role', 'alert' );
		notice.style.position = 'relative';

		var p = document.createElement( 'p' );
		p.textContent = text;
		notice.appendChild( p );

		var dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'notice-dismiss';
		dismiss.addEventListener( 'click', function() {
			if ( notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}
		} );
		notice.appendChild( dismiss );

		// Inject after .wp-header-end (WP convention for auto-dismiss notices).
		// Fallback to prepending to #wpbody-content. Last resort: body.
		var anchor = document.querySelector( '.wp-header-end' );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
		} else {
			var wpbody = document.getElementById( 'wpbody-content' );
			if ( wpbody ) {
				wpbody.insertBefore( notice, wpbody.firstChild );
			} else {
				document.body.insertBefore( notice, document.body.firstChild );
			}
		}

		window.setTimeout( function() {
			if ( notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}
		}, 6000 );
	}

	/**
	 * Execute a registered SN ability via the abilities REST API.
	 *
	 * @param {string} name         Full ability name (e.g. 'signal-noise/purge-all-caches')
	 * @param {object} annotations  { readonly?, destructive? } — same shape as PHP meta.annotations
	 * @param {object|null} input   Input payload matching the ability's input_schema. Pass null for no input.
	 * @returns {Promise<any>}      Resolves with the ability's output payload, rejects with WP_Error JSON.
	 */
	// v7.7.2: transport delegated to the shared runner (assets/snt-ability-run.js).
	// The verb comes from a map localized off the SERVER's annotations, so a
	// client verb can never drift from the registration again (the v2.5.x
	// client-side annotation guesses here 405'd whenever server annotations
	// changed — most recently the v7.7.0 force-check POST of the readonly
	// get-deploy-status). GET/DELETE input rides as bracket-encoded query
	// params (?input[k]=v), which the run controller's raw query read parses
	// to a real array — a JSON `?input=` string fails object schemas.
	function executeAbility( name, input ) {
		// In vanilla wp-admin the dep array guarantees the runner loaded
		// before us. In a REPLAYED document (OpenStation's shell ⌘K hoist)
		// that guarantee is soft: upstream's all_deps() bails wholesale if
		// ANY contributor on the site has an unregistered dependency, and
		// the fallback replays contributors without their chains. Throw a
		// named error so the palette's failure surface (openstation#712,
		// "Command /x failed: …") says what is missing and why, instead of
		// a bare TypeError.
		if ( typeof window.sntAbilityRun !== 'function' ) {
			throw new Error(
				'window.sntAbilityRun is missing: assets/snt-ability-run.js did not load in this document. ' +
				'It is a declared dependency of snt-command-palette — if this is the OpenStation shell palette, ' +
				'the contributor hoist replayed this script without its dependency chain (all_deps() bail; see WordPress/openstation#712).'
			);
		}
		return window.sntAbilityRun( name, input );
	}

	// Generic runner: close palette → execute → DOM notice with result.
	function run( label, name, input, close, onSuccess ) {
		if ( typeof close === 'function' ) {
			close();
		}
		executeAbility( name, input )
			.then( function( res ) {
				if ( typeof onSuccess === 'function' ) {
					onSuccess( res, label );
				} else {
					var msg = ( res && res.message ) ? res.message : __( 'Done.', 'signal-noise-tools' );
					showToast( label + ': ' + msg, 'ok' );
				}
			} )
			.catch( function( err ) {
				// eslint-disable-next-line no-console
				console.error( '[SN] ability error:', name, err );
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
				{},
				args.close
			);
		},
	} );

	dispatch.registerCommand( {
		// v7.7.0: dispatches get-deploy-status {force_refresh:true} — the
		// force-check-updates ability was removed in v8.0.0. The command
		// name/label keep the user-facing verb (a palette-local identifier,
		// not an ability slug).
		name: 'signal-noise/force-check-updates',
		label: __( 'SN: Force-check updates', 'signal-noise-tools' ),
		icon: dashicon( 'update' ),
		callback: function( args ) {
			run(
				__( 'Force-check', 'signal-noise-tools' ),
				'signal-noise/get-deploy-status',
				// v7.7.2: the runner sends this readonly ability as GET with
				// ?input[force_refresh]=true (the v7.7.0 POST 405'd — readonly
				// abilities REQUIRE GET, and bracket params are how GET input
				// actually travels).
				{ force_refresh: true },
				args.close,
				function( res, label ) {
					var msg = ( res && res.theme && res.plugin )
						? 'Theme ' + res.theme.current + ' (' + res.theme.state + '), plugin ' + res.plugin.current + ' (' + res.plugin.state + ').'
						: __( 'Update check refreshed.', 'signal-noise-tools' );
					showToast( label + ': ' + msg, 'ok' );
				}
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
			navigateTo( dashboardUrl, args.close );
		},
	} );

	// ─── v4.11.0: editor-flavored navigation commands ────────────────

	// New Note → the post editor (Notes are the default post type).
	dispatch.registerCommand( {
		name: 'signal-noise/new-note',
		label: __( 'SN: New Note', 'signal-noise-tools' ),
		icon: dashicon( 'edit' ),
		callback: function( args ) {
			navigateTo( newNoteUrl, args.close );
		},
	} );

	// Six "Go to <Tab>" jumps, one per top-level admin tab (from the PHP SSOT).
	// The payload is {label,url}; derive a stable command-name slug from the
	// ?page= value (falling back to the index) so names stay clean.
	tabs.forEach( function( tab, i ) {
		if ( ! tab || ! tab.url ) {
			return;
		}
		var pageMatch = tab.url.match( /[?&]page=([^&]+)/ );
		var slug = pageMatch ? pageMatch[ 1 ] : String( i );
		var label = decodeText( tab.label || tab.url );
		dispatch.registerCommand( {
			name: 'signal-noise/goto-' + slug,
			label: __( 'SN: Go to ', 'signal-noise-tools' ) + label,
			icon: dashicon( 'menu' ),
			callback: function( args ) {
				navigateTo( tab.url, args.close );
			},
		} );
	} );

	// Up to 5 most-recent Notes → edit screen. ONE apiFetch; commands register
	// after it resolves. status=any so drafts/private Notes show (admin-gated).
	if ( notesCategoryId > 0 ) {
		wp.apiFetch( {
			path: '/wp/v2/posts?categories=' + notesCategoryId + '&per_page=5&status=any',
		} ).then( function( posts ) {
			if ( ! Array.isArray( posts ) ) {
				return;
			}
			posts.forEach( function( post ) {
				if ( ! post || ! post.id ) {
					return;
				}
				var rendered = post.title && post.title.rendered ? post.title.rendered : '';
				var title = decodeText( rendered ) || __( '(no title)', 'signal-noise-tools' );
				var editUrl = '/wp-admin/post.php?post=' + post.id + '&action=edit';
				dispatch.registerCommand( {
					name: 'signal-noise/edit-note-' + post.id,
					label: __( 'SN: Edit Note — ', 'signal-noise-tools' ) + title,
					icon: dashicon( 'admin-page' ),
					callback: function( args ) {
						navigateTo( editUrl, args.close );
					},
				} );
			} );
		} ).catch( function( err ) {
			// eslint-disable-next-line no-console
			console.error( '[SN] recent-Notes fetch error:', err );
		} );
	}
} )();
