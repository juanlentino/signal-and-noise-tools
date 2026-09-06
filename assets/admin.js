/**
 * Signal & Noise Tools — admin JS.
 *
 * Loaded only on SN admin pages (same hook guard as assets/admin.css).
 * Pure vanilla JS, no jQuery, no build step. Keeps the zero-build-
 * pipeline architecture of the rest of the plugin.
 *
 * Responsibilities:
 *
 *   1. section tabs (composite leaves like Identity & SEO)
 *      — turns the `.sn-section-tabs` in-form anchor nav into a one-panel-at-
 *        a-time switcher (WAI-ARIA tabs pattern). Progressive enhancement:
 *        without JS the anchors degrade to in-page jump links with every
 *        section visible. Independent of the form below.
 *
 *   2. dirty-tracking on the sticky save bar (scoped to `.sn-identity-form`)
 *      — snapshots all input values on load; on any change, compares
 *        current vs initial; updates the save bar hint to show
 *        "N unsaved change(s)" or "No unsaved changes"
 *
 *   3. "+ Add another profile URL" button (scoped to `.sn-identity-form`)
 *      — clones the existing input template into a new row above the
 *        button. Submission still works as social_same_as[] array.
 *
 * All three are armed through ONE seam, `window.snAdmin.init( root )`, which
 * the classic page calls once on DOMContentLoaded with `document`. An
 * OpenStation window paints its leaves by innerHTML long after that event has
 * fired and repaints on every action, so the host script (assets/os-host.js)
 * calls the same seam with the window's app root after every paint. The seam
 * is therefore IDEMPOTENT and ROOT-SCOPED: it marks each element it binds with
 * `data-snt-init` and skips anything already marked, and it looks its nav,
 * panels and form up inside the root it was given rather than in `document`,
 * so a window never binds a second desktop window's leaf. Calling it twice
 * with the same root binds nothing twice; calling it with `document` is exactly
 * what the page did before the seam existed.
 *
 * Added in v1.9.6 (2026-05-16); section tabs replaced the TOC scroll-spy in
 * v6.19.4; the `snAdmin.init( root )` seam landed with the OpenStation hosts.
 */
( function () {
	'use strict';

	/**
	 * Arm every admin behaviour inside `root` (an Element or `document`).
	 *
	 * Idempotent: each element it binds carries `data-snt-init` afterwards and
	 * a second call over the same root finds the marker and returns. The body
	 * is what DOMContentLoaded used to run inline, moved here unchanged apart
	 * from the scoping.
	 *
	 * @param {Element|Document} root Subtree to arm. Defaults to `document`.
	 */
	function init( root ) {
		var scope = root || document;

		// Section tabs are independent of the Identity form — run first so the
		// switcher works on any composite leaf that renders a .sn-section-tabs nav.
		initSectionTabs( scope );

		var form = scope.querySelector( '.sn-identity-form' );
		if ( ! form ) {
			return;
		}
		if ( form.hasAttribute( 'data-snt-init' ) ) {
			return;
		}
		form.setAttribute( 'data-snt-init', '1' );

		initDirtyTracking( form );
		initAddRowButton( form );
	}

	// The seam, published before DOMContentLoaded so a host script loaded
	// after this file can call it against a root that paints later. Merged
	// onto any existing object rather than replacing it: this file is
	// enqueued once, but a window that appends the handle a second time must
	// not drop a sibling's property.
	window.snAdmin = window.snAdmin || {};
	window.snAdmin.init = init;

	document.addEventListener( 'DOMContentLoaded', function () {
		init( document );
	} );

	/**
	 * Find an element by id INSIDE a root, without a selector.
	 *
	 * `document.getElementById()` searches the whole document, which in an
	 * OpenStation window is the desktop — every other open window included.
	 * An element root has no `getElementById`, and building a `#id` selector
	 * would need CSS escaping for ids the server never promised to keep
	 * selector-safe, so the attribute is compared as a string instead.
	 *
	 * @param {Element|Document} root Subtree to search.
	 * @param {string}           id   Element id.
	 * @return {Element|null} The element, or null.
	 */
	function byId( root, id ) {
		if ( typeof root.getElementById === 'function' ) {
			return root.getElementById( id );
		}
		var candidates = root.querySelectorAll( '[id]' );
		for ( var i = 0; i < candidates.length; i++ ) {
			if ( candidates[ i ].id === id ) {
				return candidates[ i ];
			}
		}
		return null;
	}

	/**
	 * Section tabs: turn the in-form `.sn-section-tabs` anchor nav into a
	 * one-panel-at-a-time switcher (WAI-ARIA tabs pattern). Vanilla, no build.
	 *
	 * The server renders the nav as plain in-page anchors (`<a href="#sn-sec-X">`)
	 * over the `.sn-fieldset` panels, so without JS they degrade to jump links
	 * with every section visible (the pre-v6.19.4 behaviour). With JS we hide all
	 * but the active panel, upgrade nav + panels to role="tablist"/"tabpanel",
	 * and wire click + Left/Right/Home/End keyboard navigation (roving tabindex).
	 *
	 * No-op when there's no `.sn-section-tabs` nav or fewer than 2 resolvable
	 * tab→panel pairs (nothing to switch between).
	 *
	 * @param {Element|Document} root Subtree holding the nav and its panels.
	 */
	function initSectionTabs( root ) {
		var scope = root || document;
		var nav = scope.querySelector( '.sn-section-tabs' );
		if ( ! nav ) {
			return;
		}
		// Already armed: the panels are hidden, the listeners are bound, and a
		// second `activate()` would fight whichever tab the reader is on.
		if ( nav.hasAttribute( 'data-snt-init' ) ) {
			return;
		}

		// Pair each tab with its panel; skip any tab whose panel is missing.
		var tabs = [];
		var panels = [];
		Array.prototype.slice.call(
			nav.querySelectorAll( 'a[href^="#sn-sec-"]' )
		).forEach( function ( tab ) {
			var panel = byId( scope, tab.getAttribute( 'href' ).slice( 1 ) );
			if ( panel ) {
				tabs.push( tab );
				panels.push( panel );
			}
		} );
		if ( tabs.length < 2 ) {
			return; // nothing to switch between — and nothing bound, so no marker
		}
		nav.setAttribute( 'data-snt-init', '1' );

		// Upgrade the nav + panels to the WAI-ARIA tabs pattern.
		nav.setAttribute( 'role', 'tablist' );
		tabs.forEach( function ( tab, i ) {
			var panel = panels[ i ];
			var tabId = 'sn-tab-' + panel.id.replace( /^sn-sec-/, '' );
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'id', tabId );
			tab.setAttribute( 'aria-controls', panel.id );
			panel.setAttribute( 'role', 'tabpanel' );
			panel.setAttribute( 'aria-labelledby', tabId );
			panel.setAttribute( 'tabindex', '0' );
		} );

		var activate = function ( index, focusTab ) {
			tabs.forEach( function ( tab, i ) {
				var isActive = ( i === index );
				if ( isActive ) {
					tab.classList.add( 'is-active' );
				} else {
					tab.classList.remove( 'is-active' );
				}
				tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
				panels[ i ].hidden = ! isActive;
			} );
			if ( focusTab ) {
				tabs[ index ].focus();
			}
		};

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				activate( i, false );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				var next;
				switch ( e.key ) {
					case 'ArrowRight':
						next = ( i + 1 ) % tabs.length;
						break;
					case 'ArrowLeft':
						next = ( i - 1 + tabs.length ) % tabs.length;
						break;
					case 'Home':
						next = 0;
						break;
					case 'End':
						next = tabs.length - 1;
						break;
					default:
						return;
				}
				e.preventDefault();
				activate( next, true );
			} );
		} );

		// Open the panel named by location.hash when it matches a tab, else first.
		var initial = 0;
		tabs.forEach( function ( tab, i ) {
			if ( tab.getAttribute( 'href' ) === window.location.hash ) {
				initial = i;
			}
		} );
		activate( initial, false );
	}

	/**
	 * Dirty-tracking: snapshot initial values, listen for input changes,
	 * update the save bar hint with the count of changed fields.
	 */
	function initDirtyTracking( form ) {
		var hint = form.querySelector( '.sn-savebar-hint' );
		if ( ! hint ) {
			return;
		}

		var initial = snapshotForm( form );
		hint.dataset.cleanCopy = hint.textContent;

		var update = function () {
			var current = snapshotForm( form );
			var changed = countChanges( initial, current );
			if ( changed === 0 ) {
				form.removeAttribute( 'data-dirty' );
				hint.textContent = hint.dataset.cleanCopy;
			} else {
				form.setAttribute( 'data-dirty', 'true' );
				hint.textContent = changed === 1
					? '1 unsaved change'
					: changed + ' unsaved changes';
			}
		};

		form.addEventListener( 'input', update );
		// Dynamic rows added via the "+ Add" button also fire 'input'
		// when typed in, so update naturally catches them. We also need
		// to refresh the initial snapshot if a new row is added empty —
		// otherwise adding a row appears "dirty" before any typing.
		form.addEventListener( 'sn:row-added', function () {
			initial = snapshotForm( form );
			update();
		} );
	}

	/**
	 * Snapshot all form input/textarea/select values into a plain
	 * key+index map. Repeated input names (e.g. social_same_as[]) get
	 * stable per-index keys so adding/removing rows shows accurately.
	 */
	function snapshotForm( form ) {
		var snap = {};
		var counters = {};
		var inputs = form.querySelectorAll( 'input, textarea, select' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var el = inputs[ i ];
			if ( el.type === 'hidden' || el.type === 'submit' || el.disabled ) {
				continue;
			}
			var name = el.name || '';
			if ( ! name ) {
				continue;
			}
			counters[ name ] = ( counters[ name ] || 0 ) + 1;
			var key = name + '#' + counters[ name ];
			snap[ key ] = el.type === 'checkbox' ? el.checked : el.value;
		}
		return snap;
	}

	/**
	 * Count keys that differ between two snapshots. Keys present in only
	 * one snapshot count as changed (covers row add/remove cases).
	 */
	function countChanges( a, b ) {
		var changed = 0;
		var keys = {};
		Object.keys( a ).forEach( function ( k ) { keys[ k ] = true; } );
		Object.keys( b ).forEach( function ( k ) { keys[ k ] = true; } );
		Object.keys( keys ).forEach( function ( k ) {
			if ( a[ k ] !== b[ k ] ) {
				changed++;
			}
		} );
		return changed;
	}

	/**
	 * "+ Add another profile URL" button handler. Clones the sameAs
	 * input template into a new row above the button. New input is
	 * empty and immediately focused.
	 */
	function initAddRowButton( form ) {
		var btn = form.querySelector( '.sn-add-row-btn' );
		if ( ! btn ) {
			return;
		}
		var container = form.querySelector( '.sn-sameas' );
		if ( ! container ) {
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var input = document.createElement( 'input' );
			input.type = 'url';
			input.name = 'social_same_as[]';
			input.value = '';
			input.placeholder = 'https://...';
			input.className = 'sn-row-fresh';
			// WCAG 4.1.2 — screen readers need an accessible name.
			// Placeholder is not a label. The visible .sn-field-label above
			// applies to the group; each row also needs its own name.
			input.setAttribute( 'aria-label', 'Profile URL' );
			container.insertBefore( input, btn );
			input.focus();

			// Custom event so the dirty-tracker can refresh its snapshot
			// (otherwise an empty new row reads as "dirty" before typing).
			form.dispatchEvent( new CustomEvent( 'sn:row-added' ) );
		} );
	}
} )();

/* v8.5.0: Analytics row clamp + collapsible panels. The clamp is
 * display-only (rows are already in the DOM — zero extra queries); the
 * collapse persists per-panel in localStorage and announces opens via the
 * sn-an-panel-open event so lazy consumers (the uptime detail tier) can
 * fetch on first expand instead of on page load. */
( function () {
	'use strict';
	var LS_PREFIX = 'sn-an-panel-';

	document.addEventListener( 'click', function ( e ) {
		var viewall = e.target.closest( '.sn-an-viewall' );
		if ( viewall ) {
			var clamp = viewall.closest( '.sn-an-clamp' );
			if ( clamp ) {
				var open = clamp.classList.toggle( 'sn-an-clamp--open' );
				viewall.textContent = open
					? 'Show fewer'
					: 'View all ' + ( clamp.getAttribute( 'data-sn-an-total' ) || '' );
			}
			return;
		}
		var toggle = e.target.closest( '.sn-an-toggle' );
		if ( toggle ) {
			var panel = toggle.closest( '[data-sn-an-collapsible]' );
			if ( ! panel ) { return; }
			var collapsed = panel.classList.toggle( 'sn-an-collapsed' );
			toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			try { window.localStorage.setItem( LS_PREFIX + panel.getAttribute( 'data-sn-an-collapsible' ), collapsed ? '1' : '0' ); } catch ( err ) {}
			if ( ! collapsed ) {
				panel.dispatchEvent( new CustomEvent( 'sn-an-panel-open', { bubbles: true } ) );
			}
		}
	} );

	// Restore persisted state on load; fire the open event for restored-open
	// panels so lazy consumers fetch when the user left the panel open.
	document.querySelectorAll( '[data-sn-an-collapsible]' ).forEach( function ( panel ) {
		var key = LS_PREFIX + panel.getAttribute( 'data-sn-an-collapsible' );
		var saved = null;
		try { saved = window.localStorage.getItem( key ); } catch ( err ) {}
		if ( null === saved ) { return; }
		var collapsed = '1' === saved;
		panel.classList.toggle( 'sn-an-collapsed', collapsed );
		var btn = panel.querySelector( '.sn-an-toggle' );
		if ( btn ) { btn.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' ); }
		if ( ! collapsed ) {
			panel.dispatchEvent( new CustomEvent( 'sn-an-panel-open', { bubbles: true } ) );
		}
	} );
} )();
