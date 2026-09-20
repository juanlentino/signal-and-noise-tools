/**
 * Signal & Noise — our columns in OpenStation's native Posts window.
 *
 * The Posts workspace (OpenStation 1.1.8, #779) lists `/wp/v2/posts` and lets
 * a plugin append cells through the `openstation.postsWindow.columns` filter.
 * All three values ride the list request the window already makes (the PHP
 * side appends `sn_provenance`, `sn_edge` and `meta._sn_evergreen` to the
 * window's `_fields`): no extra fetch per row.
 *
 *   Provenance — the anchor-status badge the Explorer paints (`sn_provenance`).
 *   Edge       — the last edge-cache probe verdict for the post (`sn_edge`),
 *                the same row the note dossier's Edge block reads.
 *   Evergreen  - the `_sn_evergreen` flag (`meta._sn_evergreen`), the classic
 *                list-table column's twin (inc/post-evergreen.php); not
 *                flagged paints nothing.
 *
 * ABSENT IS NOT ZERO. A Note without `sn_provenance` is unsigned; a post with
 * no `sn_edge` has no probe in the site-wide twenty-row log. Either cell stays
 * empty — never a gray badge, never "fresh" — the rule the Explorer tile and
 * the dossier both follow.
 *
 * One node per render call, as the workspace docs require: the table, the
 * writing-desk cards and the inspector each render their own instance of a
 * visible column, and a shared singleton would be reparented between them.
 *
 * Plus the ATTENTION PILL (`openstation.postsWindow.toolbarTrailing`): one
 * button, "Attention · N", that opens our app on its Attention section. N is
 * what the app LAST composed — the pill reads the app's 60 s cache through
 * `/openstation/attention` and never triggers the nine-reader scan itself.
 * No cache → "Attention" with no number, marked stale in the title.
 *
 * Loaded on every shell request (beside the settings-tab script), not with
 * the lazily-loaded Explorer bundle: the Posts window paints its columns
 * whether or not the Explorer has ever been opened.
 */
( function () {
	'use strict';

	var hooks = window.wp && window.wp.hooks;
	if ( ! hooks || typeof hooks.addFilter !== 'function' ) {
		return;
	}

	// Same palette as assets/desktop-mode-explorer.js — the two must read the
	// same status the same way, and the Explorer's copy is not on this page.
	var STATUS = {
		confirmed:  { label: 'Anchored',         color: '#3fb950' },
		pending:    { label: 'Awaiting anchor',  color: '#d29922' },
		unanchored: { label: 'Not yet anchored', color: '#8b949e' },
		genesis:    { label: 'Genesis',          color: '#8b949e' }
	};

	function statusOf( key ) {
		return STATUS[ key ] || { label: key || 'Unknown', color: '#8b949e' };
	}

	function badge( prov ) {
		var s    = statusOf( prov.status );
		var node = document.createElement( 'span' );
		node.style.cssText =
			'display:inline-flex;align-items:center;gap:4px;font-size:11px;line-height:1;' +
			'padding:2px 6px;border-radius:8px;white-space:nowrap;' +
			'border:1px solid var( --os-ui-border, rgba(128,128,128,0.25) );' +
			'color: var( --os-ui-fg-muted, #8b949e );';
		var dot = document.createElement( 'span' );
		dot.style.cssText = 'width:6px;height:6px;border-radius:50%;background:' + s.color + ';';
		node.appendChild( dot );
		node.appendChild( document.createTextNode( 'v' + prov.versions ) );
		node.title = s.label + ' · ' + prov.versions + ' signed version' + ( prov.versions === 1 ? '' : 's' );
		node.setAttribute( 'aria-label', node.title );
		return node;
	}

	// Same tones as the dossier's Edge block: success / warning / neutral.
	var EDGE = {
		fresh: { label: 'Edge fresh', color: '#3fb950' },
		stale: { label: 'Edge stale', color: '#d29922' }
	};

	function edgeBadge( edge ) {
		var e    = EDGE[ edge.state ] || { label: 'Edge unread', color: '#8b949e' };
		var node = document.createElement( 'span' );
		node.style.cssText =
			'display:inline-flex;align-items:center;gap:4px;font-size:11px;line-height:1;white-space:nowrap;' +
			'color: var( --os-ui-fg-muted, #8b949e );';
		var dot = document.createElement( 'span' );
		dot.style.cssText = 'width:6px;height:6px;border-radius:50%;background:' + e.color + ';';
		node.appendChild( dot );
		node.appendChild( document.createTextNode( edge.state ) );
		var when = edge.verified_at ? new Date( edge.verified_at * 1000 ).toLocaleString() : '';
		node.title = e.label + ( when ? ' · probed ' + when : '' ) + ( edge.escalated ? ' · zone purge forced' : '' );
		node.setAttribute( 'aria-label', node.title );
		return node;
	}

	var COLUMNS = [
		{
			key: 'sn_provenance',
			label: 'Provenance',
			render: function ( value ) {
				// Unsigned: an empty node, so the cell exists and the
				// column stays aligned, but nothing is painted.
				if ( ! value || ! value.versions ) {
					return document.createElement( 'span' );
				}
				return badge( value );
			}
		},
		{
			key: 'sn_edge',
			label: 'Edge',
			render: function ( value ) {
				// Unprobed: nothing. "No row in the last twenty" is a gap,
				// never a pass.
				if ( ! value || ! value.state ) {
					return document.createElement( 'span' );
				}
				return edgeBadge( value );
			}
		},
		{
			key: 'meta._sn_evergreen',
			label: 'Evergreen',
			render: function ( _value, row ) {
				// os-table hands row[key], a flat lookup; the flag lives under
				// row.meta. Not flagged, or the meta never arrived: an empty node.
				var meta = row && row.meta;
				if ( ! meta || true !== meta._sn_evergreen ) {
					return document.createElement( 'span' );
				}
				var node = document.createElement( 'span' );
				node.style.cssText =
					'display:inline-flex;align-items:center;gap:4px;font-size:11px;line-height:1;white-space:nowrap;' +
					'color: var( --os-ui-fg-muted, #8b949e );';
				var dot = document.createElement( 'span' );
				dot.style.cssText = 'width:6px;height:6px;border-radius:50%;background:#3fb950;';
				node.appendChild( dot );
				node.appendChild( document.createTextNode( 'Evergreen' ) );
				node.title = 'Flagged evergreen: intentionally timeless. The stale-posts check labels it; the lifecycle leaderboard does not list it for refresh.';
				node.setAttribute( 'aria-label', node.title );
				return node;
			}
		}
	];

	// ── Attention pill ───────────────────────────────────────────────────
	var cfg = window.sntOsPosts || {};
	var ATTENTION_TTL_MS = 60000; // the transient's own TTL; refreshing faster reads the same cache.
	var pill = null;
	var lastFetch = 0;

	function paintPill( snap ) {
		if ( ! pill ) {
			return;
		}
		var n = snap && typeof snap.count === 'number' ? snap.count : null;
		pill.textContent = n === null ? 'Attention' : 'Attention · ' + n;
		pill.title = n === null
			? 'Attention queue — not composed yet; open the app to compose it'
			: ( snap.stale ? 'Attention queue — last composed over a minute ago; the app refreshes it' : 'Attention queue — composed within the last minute' );
		pill.setAttribute( 'data-stale', snap && snap.stale ? '1' : '0' );
	}

	function fetchAttention( force ) {
		var now = Date.now();
		if ( ! cfg.attentionEndpoint || ( ! force && now - lastFetch < ATTENTION_TTL_MS ) ) {
			return;
		}
		lastFetch = now;
		// The shell's fetch stamps its own REST nonce header, refreshed on
		// every heartbeat tick; a nonce localized at load went stale once the
		// shell (a PWA) sat open past the nonce window, and the pill vanished
		// on a 403. Runs on the window's opened/dataLoaded hooks, so the shell
		// API exists by then.
		window.wp.os.fetch( cfg.attentionEndpoint, {
			credentials: 'same-origin'
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				// 401/403: not ours to show. Remove, never paint a wrong 0.
				if ( pill && pill.parentNode ) {
					pill.parentNode.removeChild( pill );
				}
				return null;
			}
			return res.json();
		} ).then( function ( snap ) {
			if ( snap ) {
				paintPill( snap );
			}
		} ).catch( function () { /* network: keep the last paint */ } );
	}

	function openAttention() {
		// The one place the shell API is touched, and only on a click: by
		// then wp.os exists (the shell is what rendered the button).
		if ( window.wp.os && typeof window.wp.os.openWindow === 'function' ) {
			window.wp.os.openWindow( 'signal-noise', { params: { section: 'attention' } } );
		}
	}

	function makePill() {
		// The shell's own toolbar element (Refresh and Add New are
		// <os-button variant="ghost">), so the pill looks like a neighbour.
		var node = document.createElement( 'os-button' );
		node.setAttribute( 'variant', 'ghost' );
		node.setAttribute( 'aria-live', 'polite' );
		node.style.whiteSpace = 'nowrap';
		node.textContent = 'Attention';
		node.addEventListener( 'click', openAttention );
		return node;
	}

	if ( cfg.attentionEndpoint ) {
		hooks.addFilter(
			'openstation.postsWindow.toolbarTrailing',
			'signal-noise/attention-pill',
			function ( nodes ) {
				if ( ! Array.isArray( nodes ) ) {
					return nodes;
				}
				// One node per render call, as the workspace docs require.
				pill = makePill();
				paintPill( { count: null, stale: true } );
				return nodes.concat( [ pill ] );
			}
		);
		hooks.addAction( 'openstation.postsWindow.opened', 'signal-noise/attention-pill', function () {
			fetchAttention( true );
		} );
		hooks.addAction( 'openstation.postsWindow.dataLoaded', 'signal-noise/attention-pill', function () {
			fetchAttention( false );
		} );
	}

	hooks.addFilter(
		'openstation.postsWindow.columns',
		'signal-noise/posts-columns',
		function ( columns ) {
			if ( ! Array.isArray( columns ) ) {
				return columns;
			}
			// The filter runs on every paint; add each column once.
			return columns.concat( COLUMNS.filter( function ( col ) {
				return ! columns.some( function ( c ) { return c && c.key === col.key; } );
			} ) );
		}
	);
} )();
