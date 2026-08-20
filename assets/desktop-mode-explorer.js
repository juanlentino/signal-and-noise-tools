/**
 * Signal & Noise Tools — WP Explorer companion bundle.
 *
 * Rides the shell's WP Explorer window as a `scripts` companion (see
 * inc/desktop-mode-explorer.php for the delivery contract): loaded when the
 * window first opens, never on ordinary admin pages. Three jobs:
 *
 *   1. NOTES TILE BADGE — `os.my-wordpress.list-tile` action: a small
 *      version pill on each Note tile, colored by the head commit's anchor
 *      status. Data: the `sn_provenance` REST field the PHP side registers.
 *   2. NOTES PREVIEW PANE — `os.my-wordpress.preview-extras` action, `meta`
 *      slot: the signed commit chain (version · status · date · short hash)
 *      plus the ledger UID.
 *   3. DISCOGRAPHY RENDERER — registers the `signal-noise/album` entity
 *      kind via wp.os.myWordpress.registerEntityKind(): cover-art grid with
 *      a right-hand preview pane (roles, identifiers, track list with
 *      per-track roles + Spotify/Muso links). Fetches the plugin's own
 *      /desktop/discography route with window.fetch + the inline-config
 *      nonce — NOT wp.apiFetch, because the shell's lazy loader injects
 *      script tags by URL and never walks the dependency graph, so nothing
 *      here may assume another handle ran first (the REJECT #11 lesson).
 *
 * ABSENT IS NOT ZERO. A Note without `sn_provenance` is unsigned — the badge
 * and pane render NOTHING, never an empty ledger. A discography fetch error
 * says so; it does not paint an empty shelf.
 *
 * OpenStation rename compat: wp.os / wp.desktop are self-aliased at each
 * read (same posture as the widget scripts — self-sufficient, not
 * order-dependent on the external prelude). registerEntityKind is safe at
 * script-load time: the shell's always-loaded stub buffers calls until the
 * lazy Explorer bundle drains them. On a shell too old to have the Explorer
 * (pre-rename v0.9.8) the API is absent and this file no-ops gracefully —
 * and without any shell it is never loaded at all.
 *
 * @since plugin v12.4.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var hooks = window.wp && window.wp.hooks;
	if ( ! hooks || typeof hooks.addAction !== 'function' ) {
		return; // No wp.hooks in this tab — nothing below can attach.
	}

	/** Inline config, read lazily so blob replay order never matters. */
	function cfg() {
		return window.snExplorerConfig || {};
	}

	/** The shell API under whichever naming family is live. */
	function osApi() {
		var wp = window.wp || {};
		return wp.os || wp.desktop || null;
	}

	function notesEntityId() {
		return cfg().notesEntityId || 'sn-notes';
	}

	// Anchor-status presentation. Statuses come from the provenance chain
	// (inc/provenance-core.php / the Worker webhook): confirmed = anchored in
	// Bitcoin, pending = submitted + awaiting confirmation, unanchored = not
	// yet dispatched, genesis = the v0 chain root.
	var STATUS = {
		confirmed:  { label: 'Anchored',   color: '#3fb950' },
		pending:    { label: 'Awaiting anchor', color: '#d29922' },
		unanchored: { label: 'Not yet anchored', color: '#8b949e' },
		genesis:    { label: 'Genesis',    color: '#8b949e' }
	};

	function statusOf( key ) {
		return STATUS[ key ] || { label: key || 'Unknown', color: '#8b949e' };
	}

	function el( tag, style, text ) {
		var node = document.createElement( tag );
		if ( style ) {
			node.style.cssText = style;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	// Shell theme tokens with honest fallbacks — the fallbacks are the
	// shell's own dark-surface defaults, matching the widget scripts.
	var FG_MUTED = 'color: var( --os-ui-fg-muted, #8b949e );';
	var BORDER   = '1px solid var( --os-ui-border, rgba(128,128,128,0.25) )';

	/* ────────────────────────────────────────────────────────────────────
	 * 1. Notes tile badge.
	 * ──────────────────────────────────────────────────────────────────── */
	hooks.addAction(
		'os.my-wordpress.list-tile',
		'signal-noise/notes-provenance-badge',
		function ( ctx ) {
			if ( ! ctx || ! ctx.tile || ctx.entityId !== notesEntityId() ) {
				return;
			}
			var prov = ctx.item && ctx.item.sn_provenance;
			if ( ! prov || ! prov.versions ) {
				return; // Unsigned Note — no badge, not a gray one.
			}
			var s     = statusOf( prov.status );
			var badge = el(
				'span',
				'display:inline-flex;align-items:center;gap:4px;' +
					'font-size:10px;line-height:1;padding:2px 5px;border-radius:8px;' +
					'border:' + BORDER + ';' + FG_MUTED,
				'v' + prov.versions
			);
			var dot = el(
				'span',
				'width:6px;height:6px;border-radius:50%;background:' + s.color + ';'
			);
			badge.insertBefore( dot, badge.firstChild );
			badge.title = s.label + ' · ' + prov.versions + ' signed version' + ( prov.versions === 1 ? '' : 's' );
			ctx.tile.appendChild( badge );
		}
	);

	/* ────────────────────────────────────────────────────────────────────
	 * 2. Notes preview-pane provenance block.
	 * ──────────────────────────────────────────────────────────────────── */
	hooks.addAction(
		'os.my-wordpress.preview-extras',
		'signal-noise/notes-provenance-pane',
		function ( ctx ) {
			if ( ! ctx || ctx.slot !== 'meta' || ctx.entityId !== notesEntityId() || ! ctx.container ) {
				return;
			}
			var prov = ctx.item && ctx.item.sn_provenance;
			if ( ! prov || ! prov.commits || ! prov.commits.length ) {
				return;
			}
			var s    = statusOf( prov.status );
			var wrap = el( 'div', 'margin-top:10px;padding-top:8px;border-top:' + BORDER + ';' );

			var head = el(
				'div',
				'display:flex;align-items:center;gap:6px;font-size:11px;' +
					'text-transform:uppercase;letter-spacing:0.04em;' + FG_MUTED
			);
			head.appendChild( el( 'span', 'width:7px;height:7px;border-radius:50%;background:' + s.color + ';' ) );
			head.appendChild( el( 'span', '', 'Provenance · ' + s.label ) );
			wrap.appendChild( head );

			var list = el( 'div', 'margin-top:6px;display:flex;flex-direction:column;gap:2px;' );
			// Newest first in the pane; the wire order is oldest→newest.
			prov.commits.slice().reverse().forEach( function ( commit ) {
				var cs  = statusOf( commit.status );
				var row = el(
					'div',
					'display:flex;gap:8px;align-items:baseline;font-size:12px;' +
						'font-family:ui-monospace,Menlo,monospace;'
				);
				row.appendChild( el( 'span', 'min-width:26px;', 'v' + commit.version ) );
				row.appendChild( el( 'span', 'color:' + cs.color + ';', cs.label.toLowerCase() ) );
				if ( commit.committed_at ) {
					row.appendChild( el( 'span', FG_MUTED, commit.committed_at.slice( 0, 10 ) ) );
				}
				if ( commit.content_hash ) {
					var hash = el( 'span', FG_MUTED + 'overflow:hidden;text-overflow:ellipsis;', commit.content_hash.slice( 0, 12 ) );
					hash.title = commit.content_hash;
					row.appendChild( hash );
				}
				list.appendChild( row );
			} );
			wrap.appendChild( list );

			if ( prov.uid ) {
				var uid = el( 'div', 'margin-top:6px;font-size:11px;' + FG_MUTED, 'Ledger UID: ' + prov.uid );
				uid.title = 'Stable ledger key — survives slug changes and migrations.';
				wrap.appendChild( uid );
			}
			ctx.container.appendChild( wrap );
		}
	);

	/* ────────────────────────────────────────────────────────────────────
	 * 2b. The Notes folder-tile count.
	 *
	 * The shell's counter probes an entity's bare restPath and IGNORES
	 * listQuery (fetchEntityTotal, upstream src/my-wordpress/rest.ts), so
	 * the Notes tile would claim the site's entire post count over a
	 * category-scoped list. The `group-extras` action fires as our group
	 * view renders (the folder grid paints into the same body right
	 * after), so: fetch the REAL count from `notesCountUrl` (the same
	 * probe shape, WITH the category), then repaint the tile's label —
	 * and hold it for a few seconds with a MutationObserver, because the
	 * shell's own unscoped ping is in flight concurrently and whichever
	 * response lands last would otherwise win.
	 * ──────────────────────────────────────────────────────────────────── */
	hooks.addAction(
		'os.my-wordpress.group-extras',
		'signal-noise/notes-folder-count',
		function ( ctx ) {
			var conf = cfg();
			if ( ! ctx || ! ctx.container || ctx.groupId !== ( conf.groupId || 'plugin:signal-and-noise-tools' ) || ! conf.notesCountUrl ) {
				return;
			}
			var countPromise = window
				.fetch( conf.notesCountUrl, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': conf.restNonce || '' }
				} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}
					response.json().catch( function () { return null; } );
					return Number( response.headers.get( 'X-WP-Total' ) );
				} );

			// The folder grid does not exist yet at action time — it paints
			// right after the extras slot is appended. One frame later both
			// the grid and its labels are queryable.
			window.requestAnimationFrame( function () {
				countPromise.then( function ( total ) {
					if ( ! isFinite( total ) ) {
						return; // Probe failed — leave the shell's label alone.
					}
					var label   = null;
					var prefix  = ( conf.notesLabel || 'Notes' );
					var labels  = ( ctx.container.parentElement || ctx.container ).querySelectorAll( '.os-file-tile__label' );
					Array.prototype.forEach.call( labels, function ( node ) {
						var text = ( node.textContent || '' );
						if ( text === prefix || text.indexOf( prefix + ' · ' ) === 0 ) {
							label = node;
						}
					} );
					if ( ! label ) {
						return;
					}
					var wanted = prefix + ' · ' + total.toLocaleString();
					label.textContent = wanted;
					// Re-assert over the shell's late-landing unscoped count,
					// then stand down — this is a repaint, not a takeover.
					var observer = new MutationObserver( function () {
						if ( label.textContent !== wanted ) {
							label.textContent = wanted;
						}
					} );
					observer.observe( label, { childList: true, characterData: true, subtree: true } );
					window.setTimeout( function () {
						observer.disconnect();
					}, 6000 );
				} ).catch( function () {
					// Probe failed — the shell's own label stands.
				} );
			} );
		}
	);

	/* ────────────────────────────────────────────────────────────────────
	 * 3. Discography — the `signal-noise/album` entity kind.
	 * ──────────────────────────────────────────────────────────────────── */

	var discographyPromise = null;

	/**
	 * Fetch the store once per window lifetime. The promise is cached, and a
	 * FAILED promise is evicted so a transient error is retryable on the
	 * next visit instead of poisoning the section forever.
	 */
	function fetchDiscography() {
		if ( discographyPromise ) {
			return discographyPromise;
		}
		var url = cfg().discographyUrl;
		if ( ! url ) {
			return Promise.reject( new Error( 'no-endpoint' ) );
		}
		discographyPromise = window
			.fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg().restNonce || '' }
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.catch( function ( err ) {
				discographyPromise = null;
				throw err;
			} );
		return discographyPromise;
	}

	function albumCard( entry, onSelect ) {
		var card = el(
			'button',
			'all:unset;cursor:pointer;display:flex;flex-direction:column;gap:6px;' +
				'width:148px;padding:8px;border-radius:8px;box-sizing:border-box;'
		);
		card.type = 'button';
		card.setAttribute( 'aria-label', entry.title + ( entry.artist ? ' — ' + entry.artist : '' ) );
		card.addEventListener( 'mouseenter', function () {
			card.style.background = 'var( --os-ui-hover, rgba(128,128,128,0.12) )';
		} );
		card.addEventListener( 'mouseleave', function () {
			card.style.background = 'transparent';
		} );

		var coverCss = 'width:132px;height:132px;border-radius:6px;object-fit:cover;' +
			'border:' + BORDER + ';background:var( --os-ui-surface, rgba(128,128,128,0.08) );';
		if ( entry.image ) {
			var img = el( 'img', coverCss );
			img.src = entry.image;
			img.alt = '';
			img.loading = 'lazy';
			card.appendChild( img );
		} else {
			card.appendChild( el(
				'div',
				coverCss + 'display:flex;align-items:center;justify-content:center;font-size:32px;',
				'♪'
			) );
		}
		var title = el( 'div', 'font-size:12px;font-weight:600;line-height:1.3;', entry.title );
		title.title = entry.title;
		card.appendChild( title );
		card.appendChild( el(
			'div',
			'font-size:11px;' + FG_MUTED,
			( entry.artist || '' ) + ( entry.year ? ' · ' + entry.year : '' )
		) );
		card.addEventListener( 'click', function () {
			onSelect( entry );
		} );
		return card;
	}

	function metaRow( pane, label, value ) {
		if ( ! value ) {
			return;
		}
		var row = el( 'div', 'display:flex;gap:8px;font-size:12px;margin-top:4px;' );
		row.appendChild( el( 'span', 'min-width:64px;' + FG_MUTED, label ) );
		row.appendChild( el( 'span', 'overflow-wrap:anywhere;', value ) );
		pane.appendChild( row );
	}

	function linkRow( pane, links ) {
		var wrap = el( 'div', 'display:flex;gap:12px;margin-top:10px;' );
		links.forEach( function ( link ) {
			if ( ! link.url ) {
				return;
			}
			var a = el( 'a', 'font-size:12px;color:var( --os-link, var( --wp-admin-theme-color, #72aee6 ) );', link.label + ' ↗' );
			a.href = link.url;
			a.target = '_blank';
			a.rel = 'noreferrer noopener';
			wrap.appendChild( a );
		} );
		if ( wrap.childNodes.length ) {
			pane.appendChild( wrap );
		}
	}

	function renderAlbumPane( pane, entry ) {
		pane.replaceChildren();
		if ( ! entry ) {
			pane.appendChild( el( 'div', 'padding:24px;text-align:center;font-size:12px;' + FG_MUTED, 'Select a release' ) );
			return;
		}
		if ( entry.image ) {
			var img = el( 'img', 'width:100%;max-width:220px;border-radius:8px;border:' + BORDER + ';' );
			img.src = entry.image;
			img.alt = '';
			pane.appendChild( img );
		}
		pane.appendChild( el( 'div', 'font-size:15px;font-weight:700;margin-top:10px;', entry.title ) );
		pane.appendChild( el( 'div', 'font-size:12px;' + FG_MUTED, entry.artist || '' ) );

		var meta = el( 'div', 'margin-top:10px;' );
		metaRow( meta, 'Type', entry.type );
		metaRow( meta, 'Released', entry.date || ( entry.year ? String( entry.year ) : '' ) );
		metaRow( meta, 'Roles', ( entry.roles || [] ).join( ', ' ) );
		metaRow( meta, 'ISRC', entry.isrc );
		metaRow( meta, 'UPC', entry.upc );
		pane.appendChild( meta );

		linkRow( pane, [
			{ label: 'Spotify', url: entry.spotify_url },
			{ label: 'Muso.AI', url: entry.muso_url }
		] );

		var tracks = entry.tracks || [];
		if ( tracks.length ) {
			pane.appendChild( el(
				'div',
				'margin-top:14px;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;' + FG_MUTED,
				'Tracks · ' + tracks.length
			) );
			var list = el( 'ol', 'margin:6px 0 0;padding-inline-start:20px;display:flex;flex-direction:column;gap:3px;' );
			tracks.forEach( function ( track ) {
				var li = el( 'li', 'font-size:12px;' );
				li.appendChild( el( 'span', '', track.title ) );
				if ( track.roles && track.roles.length ) {
					li.appendChild( el( 'span', FG_MUTED, ' — ' + track.roles.join( ', ' ) ) );
				}
				if ( track.preview_url ) {
					var preview = el( 'a', 'margin-inline-start:6px;font-size:11px;color:var( --os-link, var( --wp-admin-theme-color, #72aee6 ) );', '▶ preview' );
					preview.href = track.preview_url;
					preview.target = '_blank';
					preview.rel = 'noreferrer noopener';
					li.appendChild( preview );
				}
				list.appendChild( li );
			} );
			pane.appendChild( list );
		}
	}

	/**
	 * The section renderer: cover-art grid + right-hand preview pane, the
	 * Explorer's own two-pane paradigm. All state is local to one render;
	 * the shell tears the body down on route changes, and we register no
	 * subscriptions, so there is nothing for addTeardown to release.
	 */
	function renderAlbums( host, entity ) {
		var body = host.body;
		body.replaceChildren();

		var root = el( 'div', 'display:flex;height:100%;min-height:0;' );
		var main = el( 'div', 'flex:1;overflow:auto;padding:12px;' );
		var pane = el( 'div', 'width:260px;flex:none;overflow:auto;padding:12px;border-inline-start:' + BORDER + ';' );
		root.appendChild( main );
		root.appendChild( pane );
		body.appendChild( root );

		main.appendChild( el( 'div', 'padding:16px;font-size:12px;' + FG_MUTED, 'Loading discography…' ) );
		renderAlbumPane( pane, null );

		fetchDiscography().then(
			function ( data ) {
				main.replaceChildren();
				var entries = ( data && data.entries ) || [];
				if ( ! entries.length ) {
					main.appendChild( el( 'div', 'padding:16px;font-size:12px;' + FG_MUTED, 'No releases synced yet.' ) );
					return;
				}
				var headline = ( entity && entity.label ? entity.label : 'Discography' ) + ' · ' + entries.length;
				if ( data.last_synced ) {
					headline += ' · synced ' + new Date( data.last_synced * 1000 ).toLocaleDateString();
				}
				main.appendChild( el( 'div', 'font-size:11px;margin:0 0 10px 8px;text-transform:uppercase;letter-spacing:0.04em;' + FG_MUTED, headline ) );

				var grid = el( 'div', 'display:flex;flex-wrap:wrap;gap:4px;align-content:flex-start;' );
				entries.forEach( function ( entry ) {
					grid.appendChild( albumCard( entry, function ( selected ) {
						renderAlbumPane( pane, selected );
					} ) );
				} );
				main.appendChild( grid );
			},
			function () {
				main.replaceChildren();
				// An error is an error — not an empty shelf.
				main.appendChild( el( 'div', 'padding:16px;font-size:12px;color:var( --os-ui-danger, #f85149 );', 'Could not load the discography. Reopen the window to retry.' ) );
			}
		);
	}

	var api = osApi();
	if ( api && api.myWordpress && typeof api.myWordpress.registerEntityKind === 'function' ) {
		api.myWordpress.registerEntityKind( cfg().albumKind || 'signal-noise/album', renderAlbums );
	}
} )();
