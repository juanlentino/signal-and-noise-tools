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
} )();
