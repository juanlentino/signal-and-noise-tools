/**
 * Signal & Noise Tools — companion of the native windows (v13.106.0).
 *
 * One thing a server view cannot do for itself: a link to a leaf on ANOTHER
 * tab. The window's tabs are the framework's (each a separate session), and a
 * server action cannot switch them, so a painter marks such a link
 * `.snt-go[data-snt-tab]`; this script activates the tab through the shell
 * (`Window.activateTab()`) and dispatches `go` with the leaf on that tab's
 * session (`wp.os.apps.dispatch(id, 'go', args, view)`), so the strip, the
 * session and the paint agree. The button's `data-snt-anchor` is a dispatch
 * argument; the scroll it asks for is assets/os-host.js's, off the runtime's
 * `snt-paint` effect (#1609).
 *
 * Bound by event delegation on the document, so it survives every morph and
 * never marks the markup (the runtime strips attributes the server did not
 * paint).
 */
( function () {
	'use strict';

	var APPS = { 'sn-dashboard': 'dashboard', 'sn-analytics': 'overview' };

	function appRoot( node ) {
		return node.closest ? node.closest( '.snt-app[data-os-app]' ) : null;
	}

	function viewFor( appId, tab ) {
		return tab === APPS[ appId ] ? 'main' : tab;
	}

	function windowFor( appId ) {
		var manager = window.wp && wp.os && wp.os.windowManager;
		if ( ! manager ) {
			return null;
		}
		var win = manager.getById ? manager.getById( appId ) : null;
		if ( win ) {
			return win;
		}
		var all = manager.getAll ? manager.getAll() : [];
		for ( var i = 0; i < all.length; i++ ) {
			if ( all[ i ] && ( all[ i ].baseId === appId || all[ i ].id === appId ) ) {
				return all[ i ];
			}
		}
		return null;
	}

	function go( button ) {
		var root = appRoot( button );
		if ( ! root ) {
			return;
		}
		var appId = root.getAttribute( 'data-os-app' );
		if ( ! APPS.hasOwnProperty( appId ) ) {
			return;
		}
		var tab = button.getAttribute( 'data-snt-tab' ) || '';
		var args = {};
		var raw = button.getAttribute( 'data-snt-args' ) || '';
		if ( raw ) {
			try {
				var parsed = JSON.parse( raw );
				if ( parsed && typeof parsed === 'object' ) {
					args = parsed;
				}
			} catch ( e ) {
				args = {};
			}
		}
		var sub = button.getAttribute( 'data-snt-sub' ) || '';
		var anchor = button.getAttribute( 'data-snt-anchor' ) || '';
		if ( sub ) {
			args.sub = sub;
		}
		if ( anchor ) {
			args.anchor = anchor;
		}
		var win = windowFor( appId );
		if ( win && typeof win.activateTab === 'function' ) {
			win.activateTab( viewFor( appId, tab ) );
		}
		if ( window.wp && wp.os && wp.os.apps && typeof wp.os.apps.dispatch === 'function' ) {
			wp.os.apps.dispatch( appId, 'go', args, viewFor( appId, tab ) );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var button = target.closest( '.snt-go[data-snt-tab]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		go( button );
	}, true );

	/*
	 * A second Save of one <os-form> (2026-10-12). The form's own submit sits
	 * in its shadow footer, at the foot of everything; a long form (Content >
	 * Workflow) puts a Save beside each group of fields instead. Such a button
	 * is `data-snt-form-submit` with no os-action, so the runtime ignores it,
	 * and this submits its enclosing form down the same path as the footer
	 * button: required-field checks, then `os-form-submit`, which the runtime
	 * dispatches. One form, so every save posts everything typed.
	 */
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var button = target && target.closest ? target.closest( '[data-snt-form-submit]' ) : null;
		var form = button ? button.closest( 'os-form' ) : null;
		if ( ! form || typeof form.submit !== 'function' || button.hasAttribute( 'disabled' ) ) {
			return;
		}
		event.preventDefault();
		form.submit();
	} );

	/*
	 * Enter/Space on a link the host un-hrefed. snt_os_host_unhref() gives
	 * every rewritten anchor `tabindex="0" role="link"`, which makes it
	 * focusable and announces it -- but an `<a>` with no href does not fire a
	 * click on Enter the way a real link does, so a focusable-but-dead control
	 * would be worse than the mouse-only one it replaced. Keying off the
	 * rewriter's own marker (rather than `.snt-go`) covers BOTH populations:
	 * the cross-tab links this file dispatches, and the `os-action` links the
	 * framework runtime dispatches. Both want the same thing -- a real click.
	 */
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' !== event.key && ' ' !== event.key && 'Spacebar' !== event.key ) {
			return;
		}
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var link = target.closest( 'a[role="link"][tabindex="0"]:not([href])' );
		if ( ! link ) {
			return;
		}
		event.preventDefault();
		link.click();
	} );

	/*
	 * S&N Analytics: a tab switch carries the window (range, custom dates)
	 * and the class into the new view's session and resets the rest -- the
	 * classic tab link's exact rule (snt_analytics_window_args over the reset
	 * params). Each framework tab is its own session, so the runtime alone
	 * would open every view on its defaults.
	 */
	var lastAnalyticsView = 'main';
	document.addEventListener( 'os-window-tab-change', function ( event ) {
		var win = event.target;
		var id = win && win.getAttribute ? ( win.getAttribute( 'data-window-id' ) || win.id || '' ) : '';
		if ( id.indexOf( 'sn-analytics' ) === -1 && ! ( win && win.querySelector && win.querySelector( '.snt-app[data-os-app="sn-analytics"]' ) ) ) {
			return;
		}
		var next = event.detail && event.detail.value ? String( event.detail.value ) : 'main';
		var apps = window.wp && wp.os && wp.os.apps;
		if ( ! apps || next === lastAnalyticsView ) {
			lastAnalyticsView = next;
			return;
		}
		var from = apps.session ? apps.session( 'sn-analytics', lastAnalyticsView ) : null;
		lastAnalyticsView = next;
		var state = from && from.state ? from.state : null;
		if ( ! state ) {
			return;
		}
		// #1228: sn_compare was dropped on this tab-switch forward, so the
		// active compare mode silently reset when jumping between analytics
		// views — the sibling read path (apps/sn-analytics/parts/state.php)
		// carries it as `compare`/`sn_compare` alongside range/class/from/to.
		var args = { sn_range: String( state.range || '' ), sn_class: String( state[ 'class' ] || '' ), sn_compare: String( state.compare || '' ) };
		if ( args.sn_range === 'custom' ) {
			args.sn_from = String( state.from || '' );
			args.sn_to = String( state.to || '' );
		}
		apps.dispatch( 'sn-analytics', 'go', args, next );
	} );

	/*
	 * 2026-10-12: a deep link shows its tab. A link from a desktop widget, the
	 * palette or another window opens the window with the classic URL's params
	 * (assets/os-settings-tab.js), and every tab's session reads them; the one
	 * the link names lands on its leaf and queues `snt-land` (read_params() in
	 * each app). The server cannot switch the window's tabs, so this does,
	 * through the shell (`Window.activateTab()`). The analytics view is noted
	 * first so the carry-over above does not re-apply the old view's window
	 * over the link's own.
	 *
	 * Once per link. The shell keeps a window's params across a reload but
	 * not its tab, so a restored window lands again with the same stamp. The
	 * last stamp shown is remembered per window id (one key each, so two in
	 * all) in localStorage, which a reload, a new browser tab and a restart
	 * of the installed app all keep: a restore opens on Dashboard or Overview
	 * as it always did. Storage that throws (a private window, blocked site
	 * data) only costs that memory.
	 */
	function shownBefore( windowId, stamp ) {
		if ( ! stamp ) {
			return false;
		}
		try {
			var key = 'snt-land:' + windowId;
			if ( window.localStorage.getItem( key ) === stamp ) {
				return true;
			}
			window.localStorage.setItem( key, stamp );
		} catch ( e ) {
			return false;
		}
		return false;
	}

	document.addEventListener( 'os-app-effect', function ( event ) {
		var detail = event.detail || {};
		if ( ! detail.effect || 'snt-land' !== detail.effect.type || ! APPS.hasOwnProperty( detail.appId ) ) {
			return;
		}
		if ( shownBefore( String( detail.windowId || '' ), String( detail.effect.land || '' ) ) ) {
			return;
		}
		var view = String( detail.view || 'main' );
		if ( 'sn-analytics' === detail.appId ) {
			lastAnalyticsView = view;
		}
		var manager = window.wp && wp.os && wp.os.windowManager;
		var win = manager && manager.getById ? manager.getById( detail.windowId ) : null;
		if ( win && typeof win.activateTab === 'function' ) {
			win.activateTab( view );
		}
	} );

	/*
	 * A kit property assigned before its tag was defined (2026.10.6). The
	 * runtime assigns `os-prop-*` and only then loads a kit tag the page has
	 * not defined yet (openstation src/app-runtime/session.ts finishRender()).
	 * On a cold open the assignment lands as an own property of a plain
	 * element, and after the upgrade it shadows the class setter: an
	 * <os-table> kept its rows on the element and painted "Nothing to show."
	 * (Connections > Credentials, all three tables). After every paint, each
	 * such own property is deleted and assigned again, through the setter,
	 * once the tag is defined. An element fed after its upgrade has no own
	 * property and is left alone.
	 */
	var hasOwn = Object.prototype.hasOwnProperty;

	function propName( attr ) {
		return attr.slice( 'os-prop-'.length ).replace( /-([a-z])/g, function ( m, c ) {
			return c.toUpperCase();
		} );
	}

	var pending = new WeakSet();

	function reassignProps( el ) {
		Array.prototype.forEach.call( el.attributes, function ( attr ) {
			var prop = 0 === attr.name.indexOf( 'os-prop-' ) ? propName( attr.name ) : '';
			if ( ! prop || ! hasOwn.call( el, prop ) ) {
				return;
			}
			var value = el[ prop ];
			delete el[ prop ];
			try {
				el[ prop ] = value;
			} catch ( e ) {
				// A getter-only prop: keep the value rather than lose it.
				Object.defineProperty( el, prop, { value: value, writable: true, configurable: true } );
			}
		} );
	}

	document.addEventListener( 'snt:paint', function ( event ) {
		var root = event.detail && event.detail.root;
		var registry = window.customElements;
		if ( ! root || ! root.querySelectorAll || ! registry ) {
			return;
		}
		Array.prototype.forEach.call( root.querySelectorAll( '*' ), function ( el ) {
			var fed = Array.prototype.some.call( el.attributes, function ( attr ) {
				return 0 === attr.name.indexOf( 'os-prop-' );
			} );
			if ( ! fed ) {
				return;
			}
			if ( el.localName.indexOf( '-' ) < 1 ) {
				return; // Not a custom element name: whenDefined would reject.
			}
			if ( registry.get( el.localName ) ) {
				reassignProps( el );
				return;
			}
			if ( pending.has( el ) ) {
				return; // One wait per element, however many paints pass.
			}
			pending.add( el );
			registry.whenDefined( el.localName ).then( function () {
				pending.delete( el );
				reassignProps( el );
			} );
		} );
	} );
} )();
