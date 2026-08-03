/**
 * Signal & Noise — Resume editor repeatable rows (v10.33.0).
 *
 * Generic list mechanics for the Content → Resume Page structured form:
 * [data-rsm-add] clones its <template data-rsm-tpl> into the matching
 * [data-rsm-list]; row controls move or remove their [data-rsm-row].
 *
 * Nested lists: templates bake placeholder tokens (declared as
 * data-rsm-token) into input names and data-rsm ids. At clone time every
 * token occurrence is swapped for a unique key — including inside NESTED
 * <template> content, reached through template.content (inert but
 * traversable), so no markup strings are ever written. PHP receives string
 * array keys and reindexes at normalize, so uniqueness is all that matters.
 *
 * Self-gating: no [data-rsm-add] on the page → this file does nothing.
 */
( function () {
	'use strict';

	var counter = 0;

	function uid() {
		counter += 1;
		return 'n' + Date.now().toString( 36 ) + counter;
	}

	/** Swap token → key in the rewritable attributes of one element. */
	function rewriteAttrs( el, token, key ) {
		[ 'name', 'data-rsm-add', 'data-rsm-tpl', 'data-rsm-list' ].forEach( function ( attr ) {
			var v = el.getAttribute && el.getAttribute( attr );
			if ( v && v.indexOf( token ) !== -1 ) {
				el.setAttribute( attr, v.split( token ).join( key ) );
			}
		} );
	}

	/**
	 * Swap every token occurrence for key across a subtree, descending into
	 * nested <template> content fragments (querySelectorAll alone never
	 * reaches them).
	 */
	function rewriteTokens( root, token, key ) {
		if ( ! token ) {
			return;
		}
		var nodes = [ root ].concat( Array.prototype.slice.call( root.querySelectorAll( '*' ) ) );
		nodes.forEach( function ( el ) {
			rewriteAttrs( el, token, key );
			if ( 'TEMPLATE' === el.tagName && el.content ) {
				el.content.querySelectorAll( '*' ).forEach( function ( inner ) {
					rewriteAttrs( inner, token, key );
					if ( 'TEMPLATE' === inner.tagName && inner.content ) {
						rewriteTokens( inner.content, token, key );
					}
				} );
			}
		} );
	}

	function findByAttr( attr, id ) {
		return document.querySelector( '[' + attr + '="' + id + '"]' );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( 'button' ) : null;
		if ( ! btn ) {
			return;
		}

		var addId = btn.getAttribute( 'data-rsm-add' );
		if ( addId ) {
			e.preventDefault();
			var tpl  = findByAttr( 'data-rsm-tpl', addId );
			var list = findByAttr( 'data-rsm-list', addId );
			if ( ! tpl || ! list ) {
				return;
			}
			list.appendChild( tpl.content.cloneNode( true ) );
			var row = list.lastElementChild;
			if ( row ) {
				rewriteTokens( row, tpl.getAttribute( 'data-rsm-token' ), uid() );
				var first = row.querySelector( 'input, textarea' );
				if ( first ) {
					first.focus();
				}
			}
			return;
		}

		var row = e.target.closest ? e.target.closest( '[data-rsm-row]' ) : null;
		if ( ! row ) {
			return;
		}
		if ( btn.classList.contains( 'sn-rsm-del' ) ) {
			e.preventDefault();
			row.parentNode.removeChild( row );
		} else if ( btn.classList.contains( 'sn-rsm-up' ) && row.previousElementSibling ) {
			e.preventDefault();
			row.parentNode.insertBefore( row, row.previousElementSibling );
		} else if ( btn.classList.contains( 'sn-rsm-down' ) && row.nextElementSibling ) {
			e.preventDefault();
			row.parentNode.insertBefore( row.nextElementSibling, row );
		}
	} );
} )();
