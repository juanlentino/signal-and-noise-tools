/**
 * Signal & Noise, Resume editor repeatable rows (v10.33.0).
 *
 * Generic list mechanics for the Content → Resume Page structured form:
 * [data-rsm-add] clones its <template data-rsm-tpl> into the matching
 * [data-rsm-list]; row controls (<button> or <os-button>) move or remove
 * their [data-rsm-row].
 *
 * Nested lists: templates bake placeholder tokens (declared as
 * data-rsm-token) into input names and data-rsm ids. At clone time every
 * token occurrence is swapped for a unique key, including inside NESTED
 * <template> content, reached through template.content (inert but
 * traversable), so no markup strings are ever written. PHP receives string
 * array keys and reindexes at normalize, so uniqueness is all that matters.
 *
 * Self-gating: with no [data-rsm-add] and no [data-rsm-row] on the page this
 * file does nothing but listen.
 *
 * The native Resume leaf (apps/sn-dashboard/parts/leaves/content-resume-
 * parts.php) paints each list as an <os-repeater> (OpenStation, Stable):
 * the component owns the handles, the Remove and Add buttons and Alt+Arrow,
 * never mutates its own keys, and emits os-repeater-add / -remove / -move
 * with the intended key list in detail. The three listeners below apply
 * that intent to the DOM (#1598): the leaf still posts the whole document
 * in light-DOM order, so a move has to move the slotted node, not just the
 * key; an add clones the list's <template data-rsm-tpl> exactly as the
 * classic add button does; a remove drops the node and the key.
 */
( function () {
	'use strict';

	var counter = 0;

	function uid() {
		counter += 1;
		return 'n' + Date.now().toString( 36 ) + counter;
	}

	/** Swap token for key in the rewritable attributes of one element. */
	function rewriteAttrs( el, token, key ) {
		[ 'name', 'id', 'aria-describedby', 'data-rsm-add', 'data-rsm-tpl', 'data-rsm-list', 'slot', 'os-key' ].forEach( function ( attr ) {
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

	/**
	 * Name each row's controls by position ("Remove row 3"), so a screen
	 * reader does not hear a column of identical "Remove" buttons (WCAG
	 * 2.4.6). Rerun after every add, remove and move.
	 */
	function nameRows( parent ) {
		if ( ! parent ) {
			return;
		}
		var rows = Array.prototype.filter.call( parent.children, function ( el ) {
			return el.hasAttribute( 'data-rsm-row' );
		} );
		rows.forEach( function ( row, i ) {
			var n = i + 1;
			var set = function ( sel, label ) {
				var b = row.querySelector( ':scope > .sn-rsm-card-head > .sn-rsm-controls > ' + sel + ', :scope > .sn-rsm-role-head > .sn-rsm-controls > ' + sel + ', :scope > .sn-rsm-controls > ' + sel );
				if ( b ) {
					b.setAttribute( 'aria-label', label );
				}
			};
			set( '.sn-rsm-up', 'Move row ' + n + ' up' );
			set( '.sn-rsm-down', 'Move row ' + n + ' down' );
			set( '.sn-rsm-del', 'Remove row ' + n );
		} );
	}

	/** Announce a change politely where wp-admin offers wp.a11y. */
	function say( text ) {
		if ( window.wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( text );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-rsm-row]' ).forEach( function ( row ) {
			nameRows( row.parentNode );
		} );
	} );

	function findByAttr( attr, id ) {
		return document.querySelector( '[' + attr + '="' + id + '"]' );
	}

	document.addEventListener( 'click', function ( e ) {
		// A click inside an <os-button> retargets to the host at this
		// listener (shadow root), so the kit twin's arrows match too.
		var btn = e.target.closest ? e.target.closest( 'button, os-button' ) : null;
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
				nameRows( list );
				say( 'Row added.' );
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
		var parent = row.parentNode;
		if ( btn.classList.contains( 'sn-rsm-del' ) ) {
			e.preventDefault();
			// Focus lands on a neighbor's first field (or the list's Add
			// button), never on <body> (WCAG 2.4.3).
			var next = row.nextElementSibling || row.previousElementSibling;
			parent.removeChild( row );
			nameRows( parent );
			var target = next && next.hasAttribute( 'data-rsm-row' ) ? next.querySelector( 'input, textarea' ) : null;
			var add    = parent.hasAttribute( 'data-rsm-list' ) ? document.querySelector( '[data-rsm-add="' + parent.getAttribute( 'data-rsm-list' ) + '"]' ) : null;
			var to     = target || add;
			if ( to ) {
				to.focus();
			}
			say( 'Row removed.' );
		} else if ( btn.classList.contains( 'sn-rsm-up' ) && row.previousElementSibling ) {
			e.preventDefault();
			parent.insertBefore( row, row.previousElementSibling );
			nameRows( parent );
			btn.focus();
			say( 'Moved up.' );
		} else if ( btn.classList.contains( 'sn-rsm-down' ) && row.nextElementSibling ) {
			e.preventDefault();
			parent.insertBefore( row.nextElementSibling, row );
			nameRows( parent );
			btn.focus();
			say( 'Moved down.' );
		}
	} );

	// ── The native leaf's <os-repeater> lists.

	/** The repeater an event came from, if it is one of ours (it carries the classic template). */
	function repeaterOf( e ) {
		var rep = e.target && e.target.closest ? e.target.closest( 'os-repeater' ) : null;
		return rep && templateOf( rep ) ? rep : null;
	}

	function templateOf( rep ) {
		return rep.querySelector( ':scope > template[data-rsm-tpl]' );
	}

	function rowOf( rep, key ) {
		return rep.querySelector( ':scope > [slot="row-' + key + '"]' );
	}

	/**
	 * Apply a new key list. The runtime's applyProps skips an os-prop-*
	 * value it already assigned, and a moved list saves and re-renders to
	 * the SAME key string it had before the move, so the property would
	 * keep the client's order over rows the server has since reindexed. A
	 * fresh os-key makes the next server render replace the element rather
	 * than morph it, and a fresh element takes every prop.
	 */
	function setKeys( rep, keys ) {
		rep.keys = keys;
		rep.setAttribute( 'os-prop-keys', JSON.stringify( keys ) );
		rep.setAttribute( 'os-key', ( rep.getAttribute( 'os-key' ) || '' ).split( '#' )[ 0 ] + '#' + uid() );
	}

	/**
	 * Focus a kit field. An <os-text-field> / <os-textarea> host is not
	 * focusable (OpenStation 1.1.10: no delegatesFocus, no focus()
	 * override); its control is the input in the shadow root.
	 */
	function focusField( host ) {
		( ( host.shadowRoot && host.shadowRoot.querySelector( 'input, textarea' ) ) || host ).focus();
	}

	document.addEventListener( 'os-repeater-add', function ( e ) {
		var rep = repeaterOf( e );
		if ( ! rep ) {
			return;
		}
		var tpl = templateOf( rep );
		var key = uid();
		// Before the template, so the DOM order stays the display order.
		rep.insertBefore( tpl.content.cloneNode( true ), tpl );
		var row = tpl.previousElementSibling;
		rewriteTokens( row, tpl.getAttribute( 'data-rsm-token' ), key );
		// A nested repeater (an employer's roles) gets its keys from the
		// runtime only on a server render; feed the clone's now.
		row.querySelectorAll( 'os-repeater[os-prop-keys]' ).forEach( function ( inner ) {
			inner.keys = JSON.parse( inner.getAttribute( 'os-prop-keys' ) );
		} );
		setKeys( rep, e.detail.keys.concat( key ) );
		var first = row.querySelector( 'os-text-field, os-textarea, input, textarea' );
		if ( first ) {
			// The clone renders its shadow input on the runtime's next
			// microtask; queue behind it so there is a control to focus.
			queueMicrotask( function () {
				focusField( first );
			} );
		}
	} );

	document.addEventListener( 'os-repeater-remove', function ( e ) {
		var rep = repeaterOf( e );
		if ( ! rep ) {
			return;
		}
		var row = rowOf( rep, e.detail.key );
		if ( row ) {
			rep.removeChild( row );
		}
		setKeys( rep, e.detail.keys );
	} );

	document.addEventListener( 'os-repeater-move', function ( e ) {
		var rep = repeaterOf( e );
		if ( ! rep ) {
			return;
		}
		var row = rowOf( rep, e.detail.key );
		if ( ! row ) {
			return;
		}
		var next   = e.detail.keys[ e.detail.to + 1 ];
		// document.activeElement retargets to the field's HOST; the caret
		// is in its shadow input. Read that before the move blurs it.
		var active = document.activeElement;
		var inner  = active && active.shadowRoot ? active.shadowRoot.activeElement : null;
		rep.insertBefore( row, next ? rowOf( rep, next ) : templateOf( rep ) );
		setKeys( rep, e.detail.keys );
		// Moving a node blurs it; Alt+Arrow from inside a field keeps the field.
		if ( active && row.contains( active ) ) {
			( inner || active ).focus();
		}
	} );
} )();
