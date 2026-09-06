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
 * OpenStation window paints its leaves long after that event has fired and
 * repaints them on every action, so the host script (assets/os-host.js) calls
 * the same seam with the window's app root after every paint. The seam is
 * therefore IDEMPOTENT and ROOT-SCOPED: it looks its nav, panels and form up
 * inside the root it was given rather than in `document`, so a window never
 * binds a second desktop window's leaf.
 *
 * WHAT MAKES IT IDEMPOTENT IS A WeakSet, NOT AN ATTRIBUTE. A window's paint is
 * a MORPH, not an `innerHTML`: desktop-mode's runtime parses the server's HTML
 * into a template (`Ut()`, offset 25085 of its assets/js/app-runtime.min.js),
 * matches an unkeyed child POSITIONALLY by tag name (`Se()`, 25455) and syncs
 * it in place (`Xt()` 25943 → `zt()` 26198), whose second loop removes EVERY
 * attribute the server's node does not carry (`for (const o of Array.from(
 * e.attributes)) n.hasAttribute(o.name) || … || e.removeAttribute(o.name)`,
 * 26430). The element and its listeners therefore survive a repaint while any
 * attribute THIS file wrote does not: the old `data-snt-init` marker was
 * deleted on every paint, so the seam re-bound, and "+ Add another profile
 * URL" added one more empty row per repaint. The bound elements live in
 * module-scope WeakSets instead — `zt` cannot reach a WeakSet.
 *
 * The same fact runs the other way for STATE this file writes as attributes:
 * `role`, `aria-*`, `tabindex`, `hidden` and `is-active` on the section tabs,
 * the dirty baseline and the save bar's clean copy. The morph strips or
 * restores all of those, so they are RE-APPLIED on every call — with the
 * reader's own open panel remembered and restored, never reset to the first.
 * Binding happens once; state is re-applied every time. Calling the seam twice
 * with the same root binds nothing twice and leaves the reader where they
 * were; calling it with `document` is exactly what the page did before the
 * seam existed.
 *
 * Added in v1.9.6 (2026-05-16); section tabs replaced the TOC scroll-spy in
 * v6.19.4; the `snAdmin.init( root )` seam landed with the OpenStation hosts.
 */
( function () {
	'use strict';

	/**
	 * Elements whose listeners are already attached.
	 *
	 * WeakSets and not attributes: see the file header. The window's runtime
	 * removes any attribute the server does not paint while REUSING the node,
	 * so an attribute marker is gone by the next repaint and the element is
	 * bound a second time. Each set is keyed by the element the listeners
	 * actually sit on, so a node the diff DID replace re-arms on its own.
	 */
	var boundNavs    = new WeakSet();
	var boundForms   = new WeakSet();
	var boundButtons = new WeakSet();

	/** Per-nav: the panel index the reader is on, and the root its panels live in. */
	var navIndex = new WeakMap();
	var navScope = new WeakMap();

	/** Per-form: the dirty baseline and the save bar's server-painted copy. */
	var dirtyBaseline = new WeakMap();
	var cleanCopy     = new WeakMap();

	/**
	 * Arm every admin behaviour inside `root` (an Element or `document`).
	 *
	 * Idempotent: every listener this reaches is attached once per element,
	 * tracked in the WeakSets above, while the attribute state a repaint
	 * strips is re-applied on each call. The body is what DOMContentLoaded
	 * used to run inline, moved here unchanged apart from the scoping.
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

		// Each initialiser guards ITSELF, on the element it binds: the form
		// for the dirty-tracker's listeners, the button for the add-row click.
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

		// Pair each tab with its panel; skip any tab whose panel is missing.
		// Derived on EVERY call, never cached: a repaint can add, drop or
		// re-label a section while the nav element itself is reused.
		var pairs = sectionPairs( nav, scope );
		if ( pairs.tabs.length < 2 ) {
			return; // nothing to switch between — and nothing bound, so no marker
		}
		navScope.set( nav, scope );

		// Upgrade the nav + panels to the WAI-ARIA tabs pattern. RE-APPLIED on
		// every call: `role`, `id`, `aria-controls`, `aria-labelledby` and
		// `tabindex` are written here and appear nowhere in the server's
		// markup, so a repaint's attribute sync deletes every one of them.
		nav.setAttribute( 'role', 'tablist' );
		pairs.tabs.forEach( function ( tab, i ) {
			var panel = pairs.panels[ i ];
			var tabId = 'sn-tab-' + panel.id.replace( /^sn-sec-/, '' );
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'id', tabId );
			tab.setAttribute( 'aria-controls', panel.id );
			panel.setAttribute( 'role', 'tabpanel' );
			panel.setAttribute( 'aria-labelledby', tabId );
			panel.setAttribute( 'tabindex', '0' );
		} );

		if ( ! boundNavs.has( nav ) ) {
			boundNavs.add( nav );
			bindSectionTabs( nav );
		}

		// Open the panel named by location.hash when it matches a tab, else the
		// first — but only the FIRST time this nav is armed. On a repaint the
		// reader is already reading a panel, and re-activating the hash's would
		// throw them back mid-read.
		activateSection( nav, navIndex.has( nav ) ? navIndex.get( nav ) : hashIndex( pairs.tabs ), false );
	}

	/**
	 * The tab→panel pairs a nav resolves to right now.
	 *
	 * @param {Element}          nav   The `.sn-section-tabs` nav.
	 * @param {Element|Document} scope Subtree holding the panels.
	 * @return {{tabs: Element[], panels: Element[]}} Paired tabs and panels.
	 */
	function sectionPairs( nav, scope ) {
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
		return { tabs: tabs, panels: panels };
	}

	/**
	 * Which tab `location.hash` names, or 0.
	 *
	 * @param {Element[]} tabs Resolved tabs.
	 * @return {number} Index.
	 */
	function hashIndex( tabs ) {
		var initial = 0;
		tabs.forEach( function ( tab, i ) {
			if ( tab.getAttribute( 'href' ) === window.location.hash ) {
				initial = i;
			}
		} );
		return initial;
	}

	/**
	 * Show one panel and mark its tab; hide the rest. Roving tabindex.
	 *
	 * Re-resolves the pairs rather than closing over them: the ARIA upgrade
	 * gives each tab an `id`, which makes it KEYED for the window runtime's
	 * diff while the server keeps painting the anchors unkeyed — so the tab
	 * NODES are replaced on every repaint even though the nav is not.
	 *
	 * @param {Element} nav      The nav.
	 * @param {number}  index    Panel to show.
	 * @param {boolean} focusTab Move focus to the tab (keyboard navigation).
	 */
	function activateSection( nav, index, focusTab ) {
		var pairs = sectionPairs( nav, navScope.get( nav ) || document );
		if ( index < 0 || index >= pairs.tabs.length ) {
			index = 0;
		}
		navIndex.set( nav, index );
		pairs.tabs.forEach( function ( tab, i ) {
			var isActive = ( i === index );
			if ( isActive ) {
				tab.classList.add( 'is-active' );
			} else {
				tab.classList.remove( 'is-active' );
			}
			tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
			pairs.panels[ i ].hidden = ! isActive;
		} );
		if ( focusTab && pairs.tabs[ index ] ) {
			pairs.tabs[ index ].focus();
		}
	}

	/**
	 * Bind the nav ONCE, by delegation.
	 *
	 * The listeners cannot sit on the tabs: the ARIA upgrade above gives each
	 * `<a>` an `id`, which is exactly what the runtime's `te()` reads as a
	 * diff KEY, while the server paints the same anchors with no id — so
	 * `Se()` finds no match for them and replaces every tab node on each
	 * repaint, taking its listeners with it. The nav carries no id, is matched
	 * positionally and morphed in place, so one listener on it survives every
	 * paint and reads the pressed tab out of the event.
	 *
	 * @param {Element} nav The `.sn-section-tabs` nav.
	 */
	function bindSectionTabs( nav ) {
		var indexOfTab = function ( target ) {
			var tab = ( target && target.closest ) ? target.closest( 'a[href^="#sn-sec-"]' ) : null;
			if ( ! tab || ! nav.contains( tab ) ) {
				return -1;
			}
			return sectionPairs( nav, navScope.get( nav ) || document ).tabs.indexOf( tab );
		};

		nav.addEventListener( 'click', function ( e ) {
			var i = indexOfTab( e.target );
			if ( i < 0 ) {
				return;
			}
			e.preventDefault();
			activateSection( nav, i, false );
		} );

		nav.addEventListener( 'keydown', function ( e ) {
			var i = indexOfTab( e.target );
			if ( i < 0 ) {
				return;
			}
			var count = sectionPairs( nav, navScope.get( nav ) || document ).tabs.length;
			var next;
			switch ( e.key ) {
				case 'ArrowRight':
					next = ( i + 1 ) % count;
					break;
				case 'ArrowLeft':
					next = ( i - 1 + count ) % count;
					break;
				case 'Home':
					next = 0;
					break;
				case 'End':
					next = count - 1;
					break;
				default:
					return;
			}
			e.preventDefault();
			activateSection( nav, next, true );
		} );
	}

	/**
	 * Dirty-tracking: snapshot initial values, listen for input changes,
	 * update the save bar hint with the count of changed fields.
	 *
	 * The baseline and the clean copy are STATE, not a binding, so they are
	 * re-read on every call — a repaint has just restored the server's saved
	 * values and the server's hint copy, and a baseline taken before the save
	 * would report every saved change as still unsaved. `data-dirty` is the
	 * one attribute that says otherwise: it is written only here, so a repaint
	 * strips it, and a form still carrying it is mid-edit and left alone.
	 * The two listeners are attached once, tracked by the element they sit on.
	 */
	function initDirtyTracking( form ) {
		var hint = form.querySelector( '.sn-savebar-hint' );
		if ( ! hint ) {
			return;
		}

		if ( ! form.hasAttribute( 'data-dirty' ) ) {
			dirtyBaseline.set( form, snapshotForm( form ) );
			cleanCopy.set( form, hint.textContent );
		}

		if ( boundForms.has( form ) ) {
			return;
		}
		boundForms.add( form );

		var update = function () {
			// Re-read the hint: a repaint can replace the node the first call
			// closed over, and writing into a detached one says nothing.
			var mark = form.querySelector( '.sn-savebar-hint' ) || hint;
			var current = snapshotForm( form );
			var changed = countChanges( dirtyBaseline.get( form ) || {}, current );
			if ( changed === 0 ) {
				form.removeAttribute( 'data-dirty' );
				mark.textContent = cleanCopy.get( form ) || '';
			} else {
				form.setAttribute( 'data-dirty', 'true' );
				mark.textContent = changed === 1
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
			dirtyBaseline.set( form, snapshotForm( form ) );
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
		// Guarded on the BUTTON, which the diff reuses across repaints — the
		// double-bind this replaced added one more empty row per paint.
		if ( boundButtons.has( btn ) ) {
			return;
		}
		boundButtons.add( btn );

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
