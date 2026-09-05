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
 * filter, the view switch -- is a `local` action and never waits. Opening the
 * editor and changing section dispatch to PHP.
 *
 * Written against the runtime's public API (the queue below), as the App
 * Framework documents for a plugin outside OpenStation's repo: no build.
 * The section vocabulary is data (inc/openstation-app.php); nothing here
 * knows what a Note or an album is.
 */
( window.openStationAppsPending ??= [] ).push( ( api ) => {
	const { defineApp, html, __, _n, sprintf, statusControl } = api;

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
	const uiOf = ( ctx ) => ctx.ui( () => ( { folderSel: null, dossiers: new Map(), errors: new Map(), inflight: new Set(), days: 30 } ) );
	/** The fetched half of a dossier lives in the same bag; keys are `${id}:${days}`. */
	const dossierOf = uiOf;

	const WINDOWS = [ 7, 30, 90 ];
	/** A failed fetch is remembered this long before a repaint retries it. */
	const ERROR_TTL_MS = 15000;

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
	const renderTile = ( ctx, item ) => {
		const { state, data } = ctx;
		const isOpen = state.item === item.id;
		const open = () => ctx.local( 'open', { item: item.id } );
		const activate = () => {
			if ( data.section && data.section.canEdit ) {
				void ctx.dispatch( 'edit', { item: item.id, title: item.title } );
			}
		};
		return html`
			<div
				class="snt-cell ${ isOpen ? 'is-open' : '' }"
				role="option"
				aria-selected=${ isOpen ? 'true' : 'false' }
				data-item-id=${ item.id }
				@click=${ open }
				@dblclick=${ activate }
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
						?selected=${ isOpen }
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
		return html`<div class="snt-canvas" role="listbox" aria-label=${ data.section.label }>${ shown.map( ( item ) => renderTile( ctx, item ) ) }</div>`;
	};

	// ---------------------------------------------------------------- list view
	const listColumns = ( ctx ) => {
		const { data } = ctx;
		const extra = ( data.section.columns || [] ).map( ( c ) => ( { key: c.key, label: c.label, sortable: true } ) );
		return [
			{ key: 'title', label: __( 'Title' ), sortable: true, sticky: true },
			{ key: 'statusLabel', label: __( 'Status' ), sortable: true },
			{ key: 'dateLabel', label: __( 'Date' ), sortable: true },
			...extra,
		];
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
		return html`
			<os-table
				class="snt-table"
				sticky-columns="1"
				.columns=${ listColumns( ctx ) }
				.data=${ listRows( shown ) }
				@os-table-row-click=${ ( e ) => {
					const row = e.detail && e.detail.row;
					if ( row && row.id !== undefined ) {
						ctx.local( 'open', { item: String( row.id ) } );
					}
				} }
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

	// ---------------------------------------------------------------- view
	defineApp( 'signal-noise', {
		local: {
			open: ( state, args ) => {
				state.item = String( args.item ?? '' );
			},
			close: ( state ) => {
				state.item = '';
			},
			search: ( state ) => {
				state.item = '';
			},
			filter: ( state ) => {
				state.item = '';
			},
			'set-view': ( state, args ) => {
				// `os-bind="view"` already wrote the pick; this only keeps it to the two values.
				const picked = args.value !== undefined ? args.value : state.view;
				state.view = picked === 'list' ? 'list' : 'icons';
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
		},
		mounted: ( ctx ) => {
			// Escape closes the dossier. Listened for on the document, because
			// focus often sits on the window chrome rather than in the body;
			// gated on the shell's focused-window class so a second window's
			// Escape is not ours.
			const onKey = ( e ) => {
				if ( e.key !== 'Escape' || ! ctx.state.item ) {
					return;
				}
				const win = ctx.root.closest( '.os-window' );
				if ( ctx.root.contains( e.target ) || ( win && win.classList.contains( 'os-window--focused' ) ) ) {
					ctx.local( 'close' );
				}
			};
			document.addEventListener( 'keydown', onKey );
			return () => document.removeEventListener( 'keydown', onKey );
		},
	} );
} );
