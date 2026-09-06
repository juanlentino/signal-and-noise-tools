/**
 * Signal & Noise — the client view.
 *
 * The body, as a function of the state `signal-noise.os.php` declared and
 * the data it returns, painted with the shell's own parts: folder tiles at
 * the root the way WP Explorer's root is folders; `<os-tile>` canvases with
 * the kit's status ribbons and an anchor badge beside each tile; the shared
 * status pills (`statusControl`) and search; an `<os-table>` list view; and
 * an item dossier in a side pane, or a page on the phone. Everything that
 * only re-slices what is already in the browser -- open, close, search,
 * filter, the view switch, the selection -- is a `local` action and never
 * waits. Opening the editor, changing section, and the four note actions
 * dispatch to PHP.
 *
 * The control surface is the WP Explorer's, seam for seam: Finder selection
 * through the framework's own `applySelection`, a drawn marquee through
 * `createMarquee`, one `<os-context-menu>` reached three ways (right-click, a
 * "More actions" button, a long press), a status footer the app paints because
 * the framework has no footer slot, and a `shortcut` lift into the shell's
 * DragManager. Nothing here is invented chrome.
 *
 * Written against the runtime's public API (the queue below), as the App
 * Framework documents for a plugin outside OpenStation's repo: no build.
 * The section vocabulary is data (inc/openstation-app.php); nothing here
 * knows what a Note or an album is.
 */
( window.openStationAppsPending ??= [] ).push( ( api ) => {
	const { defineApp, html, __, _n, sprintf, statusControl, applySelection, createMarquee, copyText } = api;

	const isPhone = () => {
		try {
			return !! ( window.wp && wp.os && wp.os.mode && typeof wp.os.mode.isMobile === 'function' && wp.os.mode.isMobile() );
		} catch ( e ) {
			return false;
		}
	};

	/** Under a finger a tap navigates; there is no double tap to wait for. */
	const opensOnTap = () => isPhone() || ( typeof window.matchMedia === 'function' && window.matchMedia( '(pointer: coarse)' ).matches );

	/** Client-only state that never travels. ONE bag per mounted view (ctx.ui runs its factory once), so everything lives here. */
	const uiOf = ( ctx ) => ctx.ui( () => ( { folderSel: null, dossiers: new Map(), errors: new Map(), inflight: new Set(), days: 30, menu: null } ) );
	/** The fetched half of a dossier lives in the same bag; keys are `${id}:${days}`. */
	const dossierOf = uiOf;

	const WINDOWS = [ 7, 30, 90 ];
	/** A failed fetch is remembered this long before a repaint retries it. */
	const ERROR_TTL_MS = 15000;
	/** A finger holds this long, drifting no further than this, to mean a right-click (the framework's own numbers). */
	const LONG_PRESS_MS = 500;
	const LONG_PRESS_SLOP_PX = 10;
	/**
	 * The drop-target key a lifted row carries, per section. A section opts IN
	 * by name: the shell's targets route on this string, so a section that has
	 * not been thought about must not lift at all.
	 */
	const DRAG_ENTITY = { notes: 'signal-noise:notes' };

	/** The selection, always strings -- `state.item` is a string, and a mixed set never matches. */
	const selectedIds = ( state ) => ( Array.isArray( state.selected ) ? state.selected.map( String ) : [] );

	/**
	 * Whether this section's rows may be acted on and lifted. Read from the
	 * DESCRIPTOR, never from the section id: the four actions are post actions
	 * and the lift routes through the section's REST collection, so a section
	 * that declares neither is simply not one of them.
	 */
	const isPostSection = ( data ) => !! ( data && data.section && 'post' === data.section.kind && data.section.restPath );

	/**
	 * The nearest matching element on an event's path. `closest()` stops at a
	 * shadow boundary and the list view's rows live inside `<os-table>`'s, so
	 * the path -- which crosses it -- is the only way to reach them.
	 */
	const closestInPath = ( e, selector ) => {
		const path = typeof e.composedPath === 'function' ? e.composedPath() : [];
		for ( const node of path ) {
			if ( node && 1 === node.nodeType && typeof node.matches === 'function' && node.matches( selector ) ) {
				return node;
			}
		}
		const target = e.target;
		return target && typeof target.closest === 'function' ? target.closest( selector ) : null;
	};

	/** A toast, when the shell offers one. */
	const toast = ( ctx, message ) => {
		if ( ctx.host && typeof ctx.host.toast === 'function' ) {
			ctx.host.toast( { message } );
		}
	};

	/**
	 * Copy one value and say what actually happened. `copyText` resolves false
	 * on an insecure origin or an old WebView, and a toast that claims success
	 * there is the lie the framework wrote it to stop telling.
	 */
	const copyAndSay = ( ctx, text, done ) => {
		Promise.resolve( copyText( String( text || '' ) ) ).then( ( copied ) => {
			toast( ctx, copied ? done : __( 'Could not copy — the clipboard is not available here.' ) );
		} );
	};

	/** Forget every fetched dossier of one note (all windows): after a re-check the trust blocks may differ. */
	const forgetDossier = ( ctx, itemId ) => {
		const ui = dossierOf( ctx );
		for ( const k of [ ...ui.dossiers.keys(), ...ui.errors.keys() ] ) {
			if ( k.startsWith( `${ itemId }:` ) ) {
				ui.dossiers.delete( k );
				ui.errors.delete( k );
			}
		}
	};

	/** Fetch a note's dossier once per (note, window); repaint when it lands. */
	const loadDossier = ( ctx, itemId ) => {
		const ui = dossierOf( ctx );
		const key = `${ itemId }:${ ui.days }`;
		if ( ui.dossiers.has( key ) || ui.inflight.has( key ) ) {
			return;
		}
		// Failures live in their own map, never in the success cache: a
		// transient error must not be pinned for the life of the window. It is
		// held for ERROR_TTL_MS so a repaint does not become a retry storm.
		const err = ui.errors.get( key );
		if ( err && Date.now() - err.at < ERROR_TTL_MS ) {
			return;
		}
		const base = ctx.extra && ctx.extra.dossierUrl ? String( ctx.extra.dossierUrl ) : '';
		if ( ! base ) {
			// Permanent for this window (at: Infinity never ages out).
			ui.errors.set( key, { error: __( 'The dossier endpoint is not configured.' ), at: Infinity } );
			// This runs from updated(), after the paint that showed the spinner:
			// one repaint, in a microtask so it does not re-enter the render on
			// the stack. It cannot loop: the next updated() finds the key held.
			Promise.resolve().then( () => ctx.repaint() );
			return;
		}
		ui.inflight.add( key );
		const url = base + ( base.includes( '?' ) ? '&' : '?' ) + 'input[post_id]=' + encodeURIComponent( itemId ) + '&input[days]=' + ui.days;
		ctx.fetch( url )
			.then( ( res ) => ( res.ok ? res.json() : Promise.reject( new Error( String( res.status ) ) ) ) )
			.then( ( body ) => {
				if ( body && Array.isArray( body.blocks ) ) {
					ui.dossiers.set( key, body );
					ui.errors.delete( key );
				} else {
					ui.errors.set( key, { error: __( 'The dossier answered without blocks.' ), at: Date.now() } );
				}
			} )
			.catch( ( e ) => {
				ui.errors.set( key, { error: sprintf( /* translators: %s: HTTP status or message. */ __( 'The dossier could not be read (%s).' ), e && e.message ? e.message : '' ), at: Date.now() } );
			} )
			.then( () => {
				ui.inflight.delete( key );
				ctx.repaint();
			} );
	};

	/** A time for a reader: local, short; the raw value when it cannot be parsed. */
	const whenText = ( value ) => {
		const d = typeof value === 'number' ? new Date( value * 1000 ) : new Date( value );
		return Number.isNaN( d.getTime() ) ? String( value ) : d.toLocaleString();
	};

	/** A door: an admin URL opens as a window; any other origin opens a tab (the shell would iframe it, and most sites refuse to be framed). */
	const openDoor = ( ctx, door ) => {
		let external = false;
		try {
			external = new URL( door.url, window.location.href ).origin !== window.location.origin;
		} catch ( e ) {
			external = false;
		}
		if ( external ) {
			window.open( door.url, '_blank', 'noopener,noreferrer' );
		} else {
			ctx.host.openUrl( door.url, door.label, 'dashicons-shield-alt' );
		}
	};

	const norm = ( s ) => String( s || '' ).toLowerCase();

	/** The items the facets leave visible, in the order the server sent them. */
	const visibleItems = ( state, data ) => {
		const q = norm( state.query ).trim();
		const status = String( state.status || '' );
		return ( data.items || [] ).filter( ( item ) => {
			if ( status && item.status !== status ) {
				return false;
			}
			if ( ! q ) {
				return true;
			}
			return norm( item.title ).includes( q ) || norm( item.subtitle ).includes( q ) || Object.values( item.columns || {} ).some( ( v ) => norm( v ).includes( q ) );
		} );
	};

	const openItem = ( state, data ) => ( data.items || [] ).find( ( i ) => i.id === state.item ) || null;

	// ---------------------------------------------------------------- long press
	// A finger has no right button, and iOS never turns a held press into a
	// `contextmenu`. The press is read from Pointer Events, and the state hangs
	// off the ELEMENT rather than the closure: the view is a template that
	// re-renders, and a repaint mid-press would otherwise strand a timer nobody
	// can cancel -- the menu opening a moment after the finger had lifted.
	const presses = new WeakMap();

	const cancelPress = ( el ) => {
		const held = presses.get( el );
		if ( held ) {
			clearTimeout( held.timer );
			presses.delete( el );
		}
	};

	/** A pointer with no right button. A pen counts: its barrel button is not what people reach for. */
	const pressesForMenu = ( e ) => !! e.isPrimary && ( 'touch' === e.pointerType || 'pen' === e.pointerType );

	/** The four listeners a template hangs on the pressed element; `fire( x, y, e )` gets the press. */
	const longPress = ( fire ) => ( {
		pointerdown: ( e ) => {
			const el = e.currentTarget;
			cancelPress( el );
			if ( ! pressesForMenu( e ) ) {
				return;
			}
			const x = e.clientX;
			const y = e.clientY;
			const path = typeof e.composedPath === 'function' ? e.composedPath() : [];
			const timer = setTimeout( () => {
				presses.delete( el );
				// The release that follows would be a click -- a select, an
				// open. It is the end of the press, not a tap; swallow it.
				const swallow = ( ev ) => {
					ev.stopPropagation();
					ev.preventDefault();
				};
				el.addEventListener( 'click', swallow, { capture: true, once: true } );
				setTimeout( () => el.removeEventListener( 'click', swallow, { capture: true } ), 700 );
				fire( x, y, { composedPath: () => path, target: e.target } );
			}, LONG_PRESS_MS );
			presses.set( el, { pointerId: e.pointerId, x, y, timer } );
		},
		pointermove: ( e ) => {
			const held = presses.get( e.currentTarget );
			if ( ! held || held.pointerId !== e.pointerId ) {
				return;
			}
			if ( Math.hypot( e.clientX - held.x, e.clientY - held.y ) > LONG_PRESS_SLOP_PX ) {
				cancelPress( e.currentTarget );
			}
		},
		pointerup: ( e ) => {
			const held = presses.get( e.currentTarget );
			if ( held && held.pointerId === e.pointerId ) {
				cancelPress( e.currentTarget );
			}
		},
		pointercancel: ( e ) => cancelPress( e.currentTarget ),
	} );

	// ---------------------------------------------------------------- menu
	const openMenu = ( ctx, x, y, item ) => {
		uiOf( ctx ).menu = { x, y, item };
		ctx.repaint();
	};

	const closeMenu = ( ctx ) => {
		uiOf( ctx ).menu = null;
		ctx.repaint();
	};

	/** The same menu, anchored under a button rather than at a pointer. */
	const openMenuAt = ( ctx, anchor, item ) => {
		const rect = anchor.getBoundingClientRect();
		openMenu( ctx, rect.left, rect.bottom + 2, item );
	};

	/** The item a pointer landed on, resolved through the path (the table's rows are in shadow DOM). */
	const itemFromEvent = ( ctx, e ) => {
		const marked = closestInPath( e, '[data-item-id]' );
		if ( ! marked ) {
			return null;
		}
		const id = String( marked.getAttribute( 'data-item-id' ) );
		return ( ctx.data.items || [] ).find( ( i ) => String( i.id ) === id ) || null;
	};

	/**
	 * The menu, in ONE fixed order. A row is disabled, never hidden, when a
	 * capability is missing -- so the reader learns the operation exists and
	 * that this account may not run it. A row is hidden only when the operation
	 * has no meaning for this note at all: no unanchored commit to re-dispatch,
	 * nothing to publish, no chain to re-check.
	 */
	const menuOptions = ( ctx, item ) => {
		const can = ( ctx.data && ctx.data.can ) || {};
		// A note with a signed chain is the one that carries a `verify`
		// dispatch in its dossier; a draft has none, and re-checking it is
		// meaningless rather than merely forbidden.
		const hasChain = ( ( item.detail && item.detail.actions ) || [] ).some( ( a ) => 'verify' === a.dispatch );
		const options = [
			{ id: 'edit', label: __( 'Open in editor' ), icon: 'dashicons-edit', disabled: ! item.canEdit },
			{ id: 'view', label: __( 'View on site' ), icon: 'dashicons-admin-site-alt3', disabled: ! item.link },
			{ id: 'copy-link', label: __( 'Copy link' ), icon: 'dashicons-admin-links', disabled: ! item.link },
			{ id: 'copy-id', label: __( 'Copy ID' ), icon: 'dashicons-shortcode' },
		];
		if ( hasChain ) {
			options.push( { id: 'verify', label: __( 'Re-check now' ), icon: 'dashicons-yes-alt', disabled: ! item.canEdit } );
		}
		options.push( { id: 'purge', label: __( 'Purge edge cache' ), icon: 'dashicons-cloud', disabled: ! can.purge } );
		if ( item.unanchored ) {
			options.push( { id: 'anchor', label: __( 'Retry anchor dispatch' ), icon: 'dashicons-shield', disabled: ! can.anchor } );
		}
		if ( item.canPublish ) {
			options.push( { id: 'publish', label: __( 'Publish' ), icon: 'dashicons-megaphone' } );
		}
		options.push( { id: 'trash', label: __( 'Move to Trash' ), icon: 'dashicons-trash', danger: true, disabled: ! item.canDelete } );
		return options;
	};

	/**
	 * Run one menu option. The Explorer's scoping rule: an action reaches the
	 * whole selection ONLY when the clicked item is in it and it holds more
	 * than one member; otherwise it reaches the clicked item alone. The server
	 * re-checks that against its own copy of the selection, so a forged
	 * `selection: true` still acts on one note.
	 */
	const runAction = ( ctx, id, item ) => {
		closeMenu( ctx );
		if ( 'refresh' === id || ! item ) {
			// The canvas menu's one row: the shell's own refresh of this app.
			try {
				if ( window.wp && wp.os && wp.os.apps && typeof wp.os.apps.refresh === 'function' ) {
					wp.os.apps.refresh( 'signal-noise' );
				}
			} catch ( e ) {
				// The shell is not there; nothing to refresh.
			}
			return;
		}
		const selected = selectedIds( ctx.state );
		const many = selected.includes( String( item.id ) ) && selected.length > 1;
		const n = many ? selected.length : 1;
		const args = { item: item.id, selection: many };
		const targets = many ? selected : [ String( item.id ) ];
		if ( 'edit' === id ) {
			void ctx.dispatch( 'edit', { item: item.id, title: item.title } );
			return;
		}
		if ( 'view' === id ) {
			if ( item.link ) {
				window.open( item.link, '_blank', 'noopener,noreferrer' );
			}
			return;
		}
		if ( 'copy-link' === id ) {
			copyAndSay( ctx, item.link, __( 'Link copied.' ) );
			return;
		}
		if ( 'copy-id' === id ) {
			copyAndSay( ctx, item.id, __( 'ID copied.' ) );
			return;
		}
		if ( 'verify' === id ) {
			// The trust blocks may change with the verdict: refetch.
			forgetDossier( ctx, item.id );
			void ctx.dispatch( 'verify', { item: item.id } );
			return;
		}
		if ( 'purge' === id ) {
			void ctx.dispatch( 'purge', args );
			targets.forEach( ( target ) => forgetDossier( ctx, target ) );
			return;
		}
		if ( 'anchor' === id ) {
			forgetDossier( ctx, item.id );
			void ctx.dispatch( 'anchor', { item: item.id } );
			return;
		}
		if ( 'publish' === id ) {
			void ctx.dispatch( 'publish', { item: item.id }, {
				confirm: {
					title: __( 'Publish this note?' ),
					message: __( 'It will be signed now and, once the editing pass goes quiet, anchored in Bitcoin. A published version is permanent.' ),
					label: __( 'Publish' ),
				},
			} ).then( ( done ) => {
				if ( done ) {
					forgetDossier( ctx, item.id );
				}
			} );
			return;
		}
		if ( 'trash' === id ) {
			void ctx.dispatch( 'trash', args, {
				confirm: {
					title: '',
					message: many
						? sprintf( /* translators: %d: selected item count. */ __( 'Move %d items to the Trash?' ), n )
						: __( 'Move this to the Trash?' ),
					label: __( 'Trash' ),
					danger: true,
				},
			} );
		}
	};

	/** The canvas menu: what the Explorer offers on empty canvas minus its server sort, so Refresh alone. */
	const canvasOptions = () => [ { id: 'refresh', label: __( 'Refresh' ), icon: 'dashicons-update' } ];

	const renderMenu = ( ctx ) => {
		const ui = uiOf( ctx );
		if ( ! ui.menu ) {
			return '';
		}
		const { x, y, item } = ui.menu;
		const close = () => closeMenu( ctx );
		const options = item ? menuOptions( ctx, item ) : canvasOptions();
		return html`
			<div
				class="snt-menu-backdrop"
				@click=${ close }
				@contextmenu=${ ( e ) => {
					e.preventDefault();
					close();
				} }
			></div>
			<os-context-menu
				open
				class="snt-menu"
				style="position:fixed;left:${ x }px;top:${ y }px;visibility:hidden"
				@os-context-menu-pick=${ ( e ) => runAction( ctx, String( ( e.detail && e.detail.id ) || '' ), item ) }
			>
				${ options.map( ( o ) => html`
					<os-context-menu-option
						id=${ o.id }
						icon=${ o.icon || '' }
						?danger=${ !! o.danger }
						?disabled=${ !! o.disabled }
					>${ o.label }</os-context-menu-option>
				` ) }
			</os-context-menu>
		`;
	};

	/** The "More actions" button: the menu's second trigger, anchored under itself. */
	const moreButton = ( ctx, item ) => html`
		<os-button
			variant="ghost"
			class="snt-more"
			title=${ __( 'More actions' ) }
			aria-label=${ __( 'More actions' ) }
			aria-haspopup="menu"
			@click=${ ( e ) => {
				e.stopPropagation();
				openMenuAt( ctx, e.currentTarget, item );
			} }
			@dblclick=${ ( e ) => e.stopPropagation() }
		><span class="dashicons dashicons-ellipsis" aria-hidden="true"></span></os-button>
	`;

	// ---------------------------------------------------------------- root
	const renderRoot = ( ctx ) => {
		const { data } = ctx;
		const sections = data.sections || [];
		if ( sections.length === 0 ) {
			return html`<os-empty-state class="snt-empty" icon="dashicons-megaphone" heading=${ __( 'Nothing to show' ) } description=${ __( 'No section is available to this account.' ) }></os-empty-state>`;
		}
		const ui = uiOf( ctx );
		const go = ( s ) => {
			ui.folderSel = null;
			void ctx.dispatch( 'go', { section: s.id } );
		};
		return html`
			<div class="snt-folders" role="list">
				${ sections.map( ( s ) => html`
					<div
						class="snt-folder ${ ui.folderSel === s.id ? 'is-selected' : '' }"
						role="listitem"
						data-section=${ s.id }
						@click=${ () => {
							if ( opensOnTap() ) {
								go( s );
								return;
							}
							ui.folderSel = s.id;
							ctx.repaint();
						} }
						@dblclick=${ () => go( s ) }
						@keydown=${ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								e.stopPropagation();
								go( s );
							}
						} }
					>
						<span class="snt-tilebox">
							<os-tile
								kind="folder"
								type=${ s.kind }
								ref=${ s.id }
								label=${ s.label }
								icon=${ s.icon }
							></os-tile>
							<os-badge class="snt-count" tone="neutral" no-dot>${ String( s.count ) }</os-badge>
						</span>
					</div>
				` ) }
			</div>
		`;
	};

	// ---------------------------------------------------------------- crumbs
	const renderCrumbs = ( ctx ) => {
		const { state, data } = ctx;
		const section = data.section;
		const item = section ? openItem( state, data ) : null;
		const onItemPage = !! item && isPhone();
		const link = ( label, go ) => html`<button type="button" class="snt-crumb-link" @click=${ go }>${ label }</button>`;
		const current = ( label ) => html`<span class="snt-crumb-current" aria-current="page">${ label }</span>`;
		const sep = () => html`<span class="snt-crumb-sep" aria-hidden="true">›</span>`;
		const crumbs = [];
		if ( ! section ) {
			crumbs.push( current( __( 'Signal & Noise' ) ) );
		} else {
			crumbs.push( link( __( 'Signal & Noise' ), () => void ctx.dispatch( 'go', {} ) ) );
			crumbs.push( sep() );
			crumbs.push( onItemPage ? link( section.label, () => ctx.local( 'close' ) ) : current( section.label ) );
			if ( onItemPage ) {
				crumbs.push( sep() );
				crumbs.push( current( item.title ) );
			}
		}
		return html`<nav class="snt-crumbs" aria-label=${ __( 'Location' ) }>${ crumbs }</nav>`;
	};

	// ---------------------------------------------------------------- toolbar
	const renderToolbar = ( ctx, shown ) => {
		const { state, data } = ctx;
		const section = data.section;
		const segments = [ { value: '', label: __( 'All' ) }, ...( section.statuses || [] ) ];
		return html`
			<div class="os-app-list__toolbar snt-toolbar">
				<div class="os-app-list__toolbar-left snt-toolbar__left">
					${ section.statuses && section.statuses.length
						? statusControl( { segments, value: String( state.status || '' ), bind: 'status', action: 'filter', label: __( 'Status' ) } )
						: '' }
				</div>
				<div class="os-app-list__toolbar-right snt-toolbar__right">
					<os-text-field
						class="os-app-list__search snt-search"
						placeholder=${ sprintf( /* translators: %s: section label. */ __( 'Search %s' ), section.label ) }
						value=${ String( state.query || '' ) }
						clearable
						os-bind="query"
						os-action="search"
						os-debounce="120"
					></os-text-field>
					<os-segmented class="snt-view" os-bind="view" os-action="set-view" value=${ state.view === 'list' ? 'list' : 'icons' } label=${ __( 'View' ) }>
						<os-segment value="icons" title=${ __( 'Icons' ) }><os-icon name="dashicons-grid-view"></os-icon></os-segment>
						<os-segment value="list" title=${ __( 'List' ) }><os-icon name="dashicons-list-view"></os-icon></os-segment>
					</os-segmented>
					<span class="os-app-list__count snt-count-label">${ sprintf( /* translators: %s: a number. */ _n( '%s item', '%s items', shown.length ), String( shown.length ) ) }</span>
				</div>
			</div>
		`;
	};

	// ---------------------------------------------------------------- canvas
	/**
	 * Click: the Finder rules, then -- for a PLAIN click only -- the dossier.
	 * Selecting and previewing are one gesture here, as they are in the
	 * Explorer; a modified click only changes the selection.
	 */
	const selectClick = ( ctx, item, order ) => ( e ) => {
		const toggle = e.metaKey || e.ctrlKey;
		const shift = e.shiftKey;
		ctx.local( 'select', { id: item.id, shift, toggle, order } );
		if ( toggle || shift ) {
			return;
		}
		ctx.local( 'open', { item: item.id } );
	};

	const renderTile = ( ctx, item, order ) => {
		const { state, data } = ctx;
		const isOpen = state.item === item.id;
		const isSelected = selectedIds( state ).includes( String( item.id ) );
		const actionable = isPostSection( data );
		const press = longPress( ( x, y ) => {
			if ( actionable ) {
				openMenu( ctx, x, y, item );
			}
		} );
		const activate = () => {
			if ( data.section && data.section.canEdit ) {
				void ctx.dispatch( 'edit', { item: item.id, title: item.title } );
			}
		};
		return html`
			<div
				class="snt-cell ${ isOpen ? 'is-open' : '' } ${ isSelected ? 'is-selected' : '' }"
				role="option"
				aria-selected=${ isSelected ? 'true' : 'false' }
				data-item-id=${ item.id }
				data-snt-drag=${ actionable ? data.section.kind : '' }
				@click=${ selectClick( ctx, item, order ) }
				@dblclick=${ activate }
				@contextmenu=${ ( e ) => {
					if ( ! actionable ) {
						return;
					}
					e.preventDefault();
					e.stopPropagation();
					openMenu( ctx, e.clientX, e.clientY, item );
				} }
				@pointerdown=${ press.pointerdown }
				@pointermove=${ press.pointermove }
				@pointerup=${ press.pointerup }
				@pointercancel=${ press.pointercancel }
			>
				<span class="snt-tilebox">
					<os-tile
						kind="entry"
						type=${ data.section ? data.section.kind : 'entry' }
						ref=${ item.id }
						label=${ item.title }
						icon=${ item.thumbnail ? '' : item.icon }
						thumbnail=${ item.thumbnail || '' }
						status=${ item.status && item.status !== 'publish' ? item.status : '' }
						?selected=${ isSelected }
					></os-tile>
					${ item.badge ? html`<os-badge class="snt-badge" tone=${ item.badge.tone } no-dot title=${ item.badge.title || '' }>${ item.badge.text }</os-badge>` : '' }
				</span>
			</div>
		`;
	};

	const renderCanvas = ( ctx, shown ) => {
		const { state, data } = ctx;
		if ( shown.length === 0 ) {
			return html`<os-empty-state class="snt-empty" icon=${ data.section.icon.startsWith( 'dashicons-' ) ? data.section.icon : 'dashicons-portfolio' } heading=${ state.query ? __( 'Nothing matches the search.' ) : __( 'Nothing here yet.' ) }></os-empty-state>`;
		}
		// Shift extends across the VISUAL order, so the order the marquee and
		// the range walk is the order on screen, not the server's.
		const order = shown.map( ( item ) => item.id );
		// A right-click on the empty canvas is ours too: the Explorer paints a
		// canvas menu there (sort and refresh); this app has no server sort, so
		// the menu is Refresh alone, and the browser's own menu never shows.
		return html`<div
			class="snt-canvas"
			role="listbox"
			aria-multiselectable="true"
			aria-label=${ data.section.label }
			@contextmenu=${ ( e ) => {
				e.preventDefault();
				openMenu( ctx, e.clientX, e.clientY, null );
			} }
		>${ shown.map( ( item ) => renderTile( ctx, item, order ) ) }</div>`;
	};

	// ---------------------------------------------------------------- list view
	const listColumns = ( ctx ) => {
		const { data } = ctx;
		const extra = ( data.section.columns || [] ).map( ( c ) => ( { key: c.key, label: c.label, sortable: true } ) );
		const actionable = isPostSection( data );
		// `<os-table>` paints its rows imperatively into its own shadow root,
		// so a row cannot be given attributes from here. The title cell carries
		// the row's mark instead -- the drag lift and the menu both resolve
		// through the event's composed path, which crosses that boundary.
		const title = actionable
			? {
				key: 'title',
				label: __( 'Title' ),
				sortable: true,
				sticky: true,
				render: ( value, row ) => html`<span data-item-id=${ String( row.id ) } data-snt-drag=${ data.section.kind }>${ value }</span>`,
			}
			: { key: 'title', label: __( 'Title' ), sortable: true, sticky: true };
		const columns = [
			title,
			{ key: 'statusLabel', label: __( 'Status' ), sortable: true },
			{ key: 'dateLabel', label: __( 'Date' ), sortable: true },
			...extra,
		];
		if ( actionable ) {
			columns.push( {
				key: 'actions',
				label: '',
				render: ( value, row ) => {
					const item = ( ctx.data.items || [] ).find( ( i ) => String( i.id ) === String( row.id ) );
					return item ? html`<span data-noclick>${ moreButton( ctx, item ) }</span>` : '';
				},
			} );
		}
		return columns;
	};

	const listRows = ( shown ) => shown.map( ( item ) => ( {
		id: item.id,
		title: item.title,
		statusLabel: item.statusLabel,
		dateLabel: item.dateLabel,
		...( item.columns || {} ),
	} ) );

	const renderList = ( ctx, shown ) => {
		if ( shown.length === 0 ) {
			return renderCanvas( ctx, shown );
		}
		const order = shown.map( ( item ) => String( item.id ) );
		const actionable = isPostSection( ctx.data );
		// Selection is handed to the component rather than painted here: the
		// kit already styles a selected row, and `getRowId` is what makes the
		// set mean note ids instead of row indexes.
		const press = longPress( ( x, y, source ) => {
			const item = itemFromEvent( ctx, source );
			if ( actionable && item ) {
				openMenu( ctx, x, y, item );
			}
		} );
		return html`
			<os-table
				class="snt-table"
				sticky-columns="1"
				.columns=${ listColumns( ctx ) }
				.data=${ listRows( shown ) }
				.getRowId=${ ( row ) => String( row.id ) }
				.selection=${ selectedIds( ctx.state ) }
				@os-table-row-click=${ ( e ) => {
					const row = e.detail && e.detail.row;
					if ( ! row || row.id === undefined ) {
						return;
					}
					const original = ( e.detail && e.detail.originalEvent ) || {};
					const toggle = !! ( original.metaKey || original.ctrlKey );
					const shift = !! original.shiftKey;
					ctx.local( 'select', { id: String( row.id ), shift, toggle, order } );
					if ( toggle || shift ) {
						return;
					}
					ctx.local( 'open', { item: String( row.id ) } );
				} }
				@contextmenu=${ ( e ) => {
					const item = itemFromEvent( ctx, e );
					e.preventDefault();
					e.stopPropagation();
					if ( ! actionable || ! item ) {
						// Off a row, or a section that cannot be acted on: the canvas menu.
						openMenu( ctx, e.clientX, e.clientY, null );
						return;
					}
					openMenu( ctx, e.clientX, e.clientY, item );
				} }
				@pointerdown=${ press.pointerdown }
				@pointermove=${ press.pointermove }
				@pointerup=${ press.pointerup }
				@pointercancel=${ press.pointercancel }
			></os-table>
		`;
	};

	// ---------------------------------------------------------------- dossier
	const cell = ( value ) => {
		if ( value && typeof value === 'object' ) {
			if ( value.code !== undefined ) {
				return html`<os-code title=${ value.title || '' }>${ value.code }</os-code>`;
			}
			if ( value.text !== undefined ) {
				return html`<os-badge tone=${ value.tone || 'neutral' } no-dot>${ value.text }</os-badge>`;
			}
		}
		return String( value ?? '' );
	};

	/** The body of a block, by kind. Unknown kinds paint their text, muted. */
	const renderBlockBody = ( ctx, block ) => {
		if ( block.kind === 'table' ) {
			return html`
				<table class="snt-facts-table">
					<thead><tr>${ ( block.columns || [] ).map( ( c ) => html`<th scope="col">${ c.label }</th>` ) }</tr></thead>
					<tbody>${ ( block.rows || [] ).map( ( row ) => html`<tr>${ ( block.columns || [] ).map( ( c ) => html`<td>${ cell( row[ c.key ] ) }</td>` ) }</tr>` ) }</tbody>
				</table>
			`;
		}
		if ( block.kind === 'code' ) {
			return html`<os-code wrap>${ block.text }</os-code>`;
		}
		if ( block.kind === 'stats' ) {
			return html`<div class="snt-tiles">${ ( block.tiles || [] ).map( ( t ) => html`
				<div class="snt-tile">
					<span class="snt-tile__label">${ t.label }</span>
					<span class="snt-tile__value">${ t.value }</span>
					${ t.window ? html`<span class="snt-tile__window">${ t.window }</span>` : '' }
					${ t.note ? html`<span class="snt-tile__note">${ t.note }</span>` : '' }
				</div>` ) }</div>`;
		}
		if ( block.kind === 'status' ) {
			return html`
				<p class="snt-status"><os-badge tone=${ block.tone || 'neutral' } no-dot>${ block.text }</os-badge></p>
				${ block.meta ? html`<p class="snt-muted">${ block.meta }</p>` : '' }
			`;
		}
		return html`<p class="snt-muted">${ block.text }</p>`;
	};

	/** An inline block: heading + body (the local half of a dossier). */
	const renderBlock = ( ctx, block ) => html`<h3 class="snt-h">${ block.heading }</h3>${ renderBlockBody( ctx, block ) }`;

	/** A fetched block: heading, body, then its source and door (a window belongs to a tile, not a block). */
	const renderFetchedBlock = ( ctx, block ) => html`
		<section class="snt-block snt-block--${ block.group || 'fetched' }">
			<h3 class="snt-h">${ block.heading }</h3>
			${ renderBlockBody( ctx, block ) }
			${ block.source || block.door ? html`<p class="snt-source">
				${ block.source ? html`<span>${ block.source }</span>` : '' }
				${ block.door ? html`<os-button variant="secondary" @click=${ () => openDoor( ctx, block.door ) }>${ block.door.label }</os-button>` : '' }
			</p>` : '' }
		</section>
	`;

	/** The last re-check verdict, when it is this note's. */
	const renderVerdict = ( ctx, item ) => {
		const v = ctx.data.verdict;
		if ( ! v || String( v.post_id ) !== String( item.id ) ) {
			return '';
		}
		return html`
			<section class="snt-block snt-block--verdict">
				<h3 class="snt-h">${ __( 'Re-check' ) }</h3>
				<p class="snt-status"><os-badge tone=${ v.tone || 'neutral' } no-dot>${ v.text }</os-badge></p>
				<p class="snt-muted">${ v.meta }${ v.checked_at ? ( v.meta ? ' · ' : '' ) + whenText( v.checked_at ) : '' }</p>
			</section>
		`;
	};

	/** The fetched dossier: the window switch, the verdict, then the blocks or their state. */
	const renderDossier = ( ctx, item ) => {
		const ui = dossierOf( ctx );
		const key = `${ item.id }:${ ui.days }`;
		const got = ui.dossiers.get( key );
		const err = ui.errors.get( key );
		const retry = () => {
			ui.errors.delete( key );
			loadDossier( ctx, item.id );
			ctx.repaint();
		};
		const pick = ( e ) => {
			const v = parseInt( e.detail && e.detail.value, 10 );
			if ( WINDOWS.includes( v ) && v !== ui.days ) {
				ui.days = v;
				ctx.repaint();
			}
		};
		const sw = html`<os-segmented class="snt-window" value=${ String( ui.days ) } label=${ __( 'Window' ) } @os-pick=${ pick }>
			${ WINDOWS.map( ( d ) => html`<os-segment value=${ String( d ) }>${ sprintf( /* translators: %d: days. */ __( '%dd' ), d ) }</os-segment>` ) }
		</os-segmented>`;
		let body;
		if ( got ) {
			body = html`${ got.blocks.map( ( b ) => renderFetchedBlock( ctx, b ) ) }${ got.fetched_at ? html`<p class="snt-muted snt-asof">${ sprintf( /* translators: %s: a local date and time. */ __( 'Read %s' ), whenText( got.fetched_at ) ) }</p>` : '' }`;
		} else if ( err ) {
			body = html`<p class="snt-status"><os-badge tone="warning" no-dot>${ err.error }</os-badge>${ Number.isFinite( err.at ) ? html` <os-button variant="secondary" class="snt-retry" @click=${ retry }>${ __( 'Try again' ) }</os-button>` : '' }</p>`;
		} else {
			body = html`<p class="snt-muted snt-loading"><os-spinner></os-spinner> ${ __( 'Reading the estate…' ) }</p>`;
		}
		return html`<div class="snt-dossier">${ sw }${ renderVerdict( ctx, item ) }${ body }</div>`;
	};

	const renderDetail = ( ctx, item ) => {
		const { data } = ctx;
		const d = item.detail || {};
		return html`
			<article class="snt-detail" aria-label=${ item.title }>
				${ isPostSection( data ) && isPhone() ? html`<span class="snt-detail__more">${ moreButton( ctx, item ) }</span>` : '' }
				<os-button variant="ghost" class="snt-detail__close" aria-label=${ __( 'Close details' ) } @click=${ () => ctx.local( 'close' ) }>✕</os-button>
				${ d.hero ? html`<img class="snt-detail__hero" src=${ d.hero } alt="" />` : '' }
				<h2 class="snt-detail__title">${ item.title }</h2>
				${ item.badge ? html`<p class="snt-detail__meta"><os-badge tone=${ item.badge.tone } no-dot>${ item.badge.text }</os-badge> ${ item.badge.title || '' }</p>` : '' }
				${ ( d.facts || [] ).length
					? html`<dl class="snt-facts">${ d.facts.map( ( f ) => html`<dt>${ f[ 0 ] }</dt><dd>${ f[ 1 ] }</dd>` ) }</dl>`
					: '' }
				${ ( d.blocks || [] ).map( ( b ) => renderBlock( ctx, b ) ) }
				${ data.section && data.section.id === 'notes' ? renderDossier( ctx, item ) : '' }
				${ ( d.actions || [] ).length
					? html`<div class="snt-actions">
						${ d.actions.map( ( a ) => a.url
							? html`<os-button variant=${ a.variant || 'secondary' } @click=${ () => window.open( a.url, '_blank', 'noopener' ) }>${ a.label }</os-button>`
							: html`<os-button variant=${ a.variant || 'secondary' } @click=${ () => {
								if ( a.dispatch === 'verify' ) {
									// The trust blocks may change with the verdict: refetch.
									forgetDossier( ctx, item.id );
								}
								void ctx.dispatch( a.dispatch || 'edit', a.args || {} );
							} }>${ a.label }</os-button>` ) }
					</div>`
					: '' }
			</article>
		`;
	};

	// ---------------------------------------------------------------- status bar
	/**
	 * The Explorer's status footer, and the only place a selection count is
	 * ever shown. The framework has no footer slot; the Explorer authors its
	 * own, and so does this.
	 */
	const renderStatusBar = ( ctx, shown ) => {
		const selected = selectedIds( ctx.state );
		const total = ( ctx.data.items || [] ).length;
		return html`
			<footer class="snt-status-bar">
				<span>${ sprintf( /* translators: 1: shown count. 2: total count. */ __( '%1$d of %2$d items' ), shown.length, total ) }${ selected.length ? sprintf( /* translators: %d: selected count. */ __( ' — %d selected' ), selected.length ) : '' }</span>
			</footer>
		`;
	};

	// ---------------------------------------------------------------- view
	defineApp( 'signal-noise', {
		local: {
			open: ( state, args ) => {
				state.item = String( args.item ?? '' );
			},
			// The framework's own Finder math, called with ITS signature
			// (selected, order, id, mods) -- ids are strings in this app, as
			// `state.item` is, so a set never half-matches.
			select: ( state, args ) => {
				const order = ( args.order || [] ).map( String );
				state.selected = applySelection( selectedIds( state ), order, String( args.id ), { ctrl: !! args.toggle, shift: !! args.shift } );
			},
			// What the marquee reports: a replace, every time.
			'select-set': ( state, args ) => {
				state.selected = ( args.ids || [] ).map( String );
			},
			close: ( state ) => {
				state.item = '';
			},
			// A facet change drops the selection, as the Explorer's navigations do:
			// a selection the reader can no longer see must not be what a
			// confirmed "Move N items to the Trash?" acts on.
			search: ( state ) => {
				state.item = '';
				state.selected = [];
			},
			filter: ( state ) => {
				state.item = '';
				state.selected = [];
			},
			'set-view': ( state, args ) => {
				// `os-bind="view"` already wrote the pick; this only keeps it to the two values.
				const picked = args.value !== undefined ? args.value : state.view;
				state.view = picked === 'list' ? 'list' : 'icons';
				state.selected = [];
			},
		},
		view: ( ctx ) => {
			const { state, data } = ctx;
			const phone = isPhone();
			if ( ! data.section ) {
				return html`<div class="snt-app ${ phone ? 'is-phone' : '' }">${ renderCrumbs( ctx ) }${ ctx.loading ? html`<os-spinner></os-spinner>` : renderRoot( ctx ) }</div>`;
			}
			const shown = visibleItems( state, data );
			const item = openItem( state, data );
			const body = state.view === 'list' ? renderList( ctx, shown ) : renderCanvas( ctx, shown );
			return html`
				<div class="snt-app ${ phone ? 'is-phone' : '' } ${ item ? 'is-open' : '' }">
					${ renderCrumbs( ctx ) }
					${ phone && item ? '' : renderToolbar( ctx, shown ) }
					<div class="snt-body ${ item ? 'is-open' : '' }">
						${ phone && item ? '' : html`<div class="snt-main">${ body }</div>` }
						${ item ? html`<aside class="snt-pane">${ renderDetail( ctx, item ) }</aside>` : '' }
					</div>
					${ phone && item ? '' : renderStatusBar( ctx, shown ) }
					${ renderMenu( ctx ) }
				</div>
			`;
		},
		// After every paint: an open item whose dossier is not cached or in
		// flight gets fetched. Idempotent -- the cache and the inflight set
		// make a second call a no-op -- so painting often costs nothing.
		// Notes only: a Discography id (or a third-party section's) is not a
		// post id, and the ability would answer 400 for it.
		updated: ( ctx ) => {
			if ( ctx.state.item && ctx.data && ctx.data.section && ctx.data.section.id === 'notes' ) {
				loadDossier( ctx, ctx.state.item );
			}
			// The menu paints hidden and is placed HERE, a frame after the
			// paint: the component's shadow root lands in a microtask, so a
			// measure on the paint's own line reads nothing and a clamp built
			// on it never fires (the Explorer's rig, wire.ts). Clamped inside
			// the viewport with an 8px margin, then revealed.
			const menuEl = ctx.root.querySelector( 'os-context-menu.snt-menu' );
			if ( menuEl && menuEl.style.visibility === 'hidden' ) {
				requestAnimationFrame( () => {
					if ( ! menuEl.isConnected ) {
						return;
					}
					const margin = 8;
					const rect = menuEl.getBoundingClientRect();
					let left = parseFloat( menuEl.style.left ) || 0;
					let top = parseFloat( menuEl.style.top ) || 0;
					if ( rect.right > window.innerWidth - margin ) {
						left = Math.max( margin, left - ( rect.right - ( window.innerWidth - margin ) ) );
					}
					if ( rect.bottom > window.innerHeight - margin ) {
						top = Math.max( margin, top - ( rect.bottom - ( window.innerHeight - margin ) ) );
					}
					menuEl.style.left = left + 'px';
					menuEl.style.top = top + 'px';
					menuEl.style.visibility = '';
				} );
			}
		},
		mounted: ( ctx ) => {
			const teardowns = [];
			// Escape closes the MENU first and the dossier only when no menu is
			// open -- one keystroke, one thing closed, innermost first.
			// Listened for on the document, because focus often sits on the
			// window chrome rather than in the body; gated on the shell's
			// focused-window class so a second window's Escape is not ours.
			const onKey = ( e ) => {
				if ( e.key !== 'Escape' ) {
					return;
				}
				const ui = uiOf( ctx );
				if ( ! ui.menu && ! ctx.state.item ) {
					return;
				}
				const win = ctx.root.closest( '.os-window' );
				if ( ! ctx.root.contains( e.target ) && ! ( win && win.classList.contains( 'os-window--focused' ) ) ) {
					return;
				}
				if ( ui.menu ) {
					closeMenu( ctx );
					return;
				}
				ctx.local( 'close' );
			};
			document.addEventListener( 'keydown', onKey );
			teardowns.push( () => document.removeEventListener( 'keydown', onKey ) );

			// Everything below is desk-only: a phone has no rubber band (a
			// finger on the canvas scrolls it) and no drag and drop.
			if ( ! isPhone() ) {
				if ( typeof createMarquee === 'function' ) {
					// The framework's drawn marquee, on the mount root -- which
					// the runtime morphs but never replaces, so one attachment
					// survives every repaint, exactly as the Explorer's does.
					teardowns.push( createMarquee( {
						root: ctx.root,
						canvas: '.snt-canvas',
						item: '[data-item-id]',
						className: 'snt-marquee',
						// The framework reports NUMBERS; this app's ids are strings and a
						// section's may not be numeric at all, so only ids that name a real
						// item survive -- the count never reports a selection nothing shows.
						select: ( ids ) => ctx.local( 'select-set', { ids: ( ids || [] ).map( String ).filter( ( id ) => ( ( ctx.data && ctx.data.items ) || [] ).some( ( i ) => String( i.id ) === id ) ) } ),
					} ) );
				}

				// The drag lift. Refused on a non-primary button, on any
				// modifier (those are selection gestures), and on the phone.
				const onPointerDown = ( e ) => {
					if ( e.button !== 0 || e.shiftKey || e.ctrlKey || e.metaKey || isPhone() ) {
						return;
					}
					// An EMPTY data-snt-drag is not a drag flag: a section that
					// cannot be acted on paints the attribute blank rather
					// than not at all, and `[data-snt-drag]` alone would match
					// it.
					const row = closestInPath( e, '[data-snt-drag]:not([data-snt-drag=""])[data-item-id]' );
					if ( ! row ) {
						return;
					}
					let dragManager = null;
					try {
						dragManager = window.wp && wp.os && wp.os.dragManager ? wp.os.dragManager : null;
					} catch ( err ) {
						dragManager = null;
					}
					if ( ! dragManager || typeof dragManager.start !== 'function' ) {
						return;
					}
					const section = ctx.data && ctx.data.section;
					const restPath = String( ( section && section.restPath ) || '' );
					const entityId = String( DRAG_ENTITY[ String( section && section.id ) ] || '' );
					// Both are how a drop target routes the object: the Trash
					// deletes through the REST collection, other targets gate
					// on the entity. Without either, nothing may be lifted.
					if ( ! restPath || ! entityId ) {
						return;
					}
					const kind = row.getAttribute( 'data-snt-drag' ) || '';
					const id = String( row.getAttribute( 'data-item-id' ) );
					const all = ( ctx.data && ctx.data.items ) || [];
					const item = all.find( ( i ) => String( i.id ) === id );
					if ( ! item ) {
						return;
					}
					const shortcut = ( one ) => ( { kind, ref: String( one.id ), title: one.title, icon: one.thumbnail || '', entityId, restPath } );
					const selected = selectedIds( ctx.state );
					const dragged = selected.includes( id ) ? all.filter( ( i ) => selected.includes( String( i.id ) ) ) : [ item ];
					dragManager.start( {
						payload: {
							type: 'shortcut',
							source: row,
							data: {
								...shortcut( item ),
								...( dragged.length > 1 ? { items: dragged.map( shortcut ) } : {} ),
							},
						},
						origin: e,
					} );
				};
				ctx.root.addEventListener( 'pointerdown', onPointerDown );
				teardowns.push( () => ctx.root.removeEventListener( 'pointerdown', onPointerDown ) );
			}

			return () => teardowns.forEach( ( off ) => off() );
		},
	} );
} );
