/**
 * Signal & Noise — a Provenance column in OpenStation's native Posts window.
 *
 * The Posts workspace (OpenStation 1.1.8, #779) lists `/wp/v2/posts` and lets
 * a plugin append cells through the `openstation.postsWindow.columns` filter.
 * The plugin already registers `sn_provenance` as a REST field on posts (for
 * the Explorer), so the value this column reads rides the list request the
 * window already makes: no extra fetch, no PHP.
 *
 * ABSENT IS NOT ZERO. A Note without `sn_provenance` is unsigned: the cell
 * stays empty, never a gray badge — the same rule the Explorer tile follows.
 *
 * One node per render call, as the workspace docs require: the table, the
 * writing-desk cards and the inspector each render their own instance of a
 * visible column, and a shared singleton would be reparented between them.
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

	hooks.addFilter(
		'openstation.postsWindow.columns',
		'signal-noise/provenance-column',
		function ( columns ) {
			if ( ! Array.isArray( columns ) ) {
				return columns;
			}
			if ( columns.some( function ( c ) { return c && c.key === 'sn_provenance'; } ) ) {
				return columns; // the filter runs on every paint; add once.
			}
			return columns.concat( [ {
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
			} ] );
		}
	);
} )();
