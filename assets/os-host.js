/**
 * Signal & Noise Tools — the OpenStation host script.
 *
 * WHY THIS EXISTS. The two hosts (`sn-dashboard`, `sn-analytics`) are server
 * views: the window paints the SAME admin HTML the classic page paints, and it
 * repaints it on every action. Two things the classic page gets for free do not
 * survive that:
 *
 *   1. Painted HTML lands by `innerHTML`, and `innerHTML` never executes a
 *      `<script>`. A leaf that ships an inline block (a chart's data, a
 *      bootstrap call) would silently do nothing. The rewrite pass marks every
 *      such block `data-snt-exec`; this file re-creates each marked node once,
 *      which is the only way to make a parsed-in script run.
 *   2. assets/admin.js binds on DOMContentLoaded, which fired long before the
 *      window opened and never fires again. It now publishes an idempotent
 *      `window.snAdmin.init( root )`; this file calls it after every paint.
 *
 * And one thing a window does differently: the classic page scrolls to a
 * `#sn-sec-*` fragment after a save. A window has no URL to carry a fragment,
 * so the server paints `data-snt-anchor` and this file scrolls to it once.
 *
 * WHAT IT DOES NOT DO. It never reloads, never fetches, and knows no endpoint:
 * every request in these windows is the framework's own dispatch. It is plain
 * ES2019 with no dependency beyond the seam admin.js publishes, and no build
 * step — the rest of the plugin's JS is written the same way.
 *
 * IDEMPOTENCE IS THE WHOLE DESIGN. The pass runs on a MutationObserver over the
 * app root, so its own three writes (replacing a script node, `data-snt-init`
 * on a bound element, removing `data-snt-anchor`) schedule one more pass. Each
 * of the three is marked, so that pass finds nothing and the observer settles —
 * one extra no-op pass per paint, never a loop.
 *
 * @package SignalNoiseTools
 */
( function () {
	'use strict';

	/**
	 * The app roots this file hosts. The framework's window template is
	 * `<div class="os-app" data-os-app="<id>" data-os-view="<view>">`
	 * (desktop-mode `includes/framework/wordpress.php`), one per view, and the
	 * runtime paints into it.
	 */
	var ROOT_SELECTOR = '.os-app[data-os-app="sn-dashboard"], .os-app[data-os-app="sn-analytics"]';

	/** Roots already given an observer. */
	var hosted = new WeakSet();

	/** Roots with a pass already scheduled for the next paint. */
	var pending = new WeakSet();

	/**
	 * Find an element by id INSIDE a root, without a selector.
	 *
	 * The desktop document holds every open window, so `getElementById` would
	 * reach another window's leaf; and an id the server minted is not promised
	 * to be safe inside a `#…` selector. Compare the attribute instead.
	 *
	 * @param {Element} root Subtree to search.
	 * @param {string}  id   Element id.
	 * @return {Element|null} The element, or null.
	 */
	function byId( root, id ) {
		var candidates = root.querySelectorAll( '[id]' );
		for ( var i = 0; i < candidates.length; i++ ) {
			if ( candidates[ i ].id === id ) {
				return candidates[ i ];
			}
		}
		return null;
	}

	/**
	 * Re-create every marked-but-unrun `<script>` so the browser executes it.
	 *
	 * A script node that arrived through `innerHTML` is inert forever; only a
	 * node created by `document.createElement` and inserted runs. `src`, `type`
	 * and the inline text carry over — nothing else, because nothing else is
	 * behaviour. The marker moves to the fresh node BEFORE the swap so the
	 * mutation this causes cannot find the same block again.
	 *
	 * @param {Element} root App root.
	 */
	function runScripts( root ) {
		var stale = root.querySelectorAll( 'script[data-snt-exec]:not([data-snt-ran])' );
		for ( var i = 0; i < stale.length; i++ ) {
			var old = stale[ i ];
			old.setAttribute( 'data-snt-ran', '1' );
			if ( ! old.parentNode ) {
				continue;
			}
			var fresh = document.createElement( 'script' );
			var src = old.getAttribute( 'src' );
			var type = old.getAttribute( 'type' );
			if ( src ) {
				fresh.src = src;
			}
			if ( type ) {
				fresh.type = type;
			}
			fresh.text = old.text;
			fresh.setAttribute( 'data-snt-exec', old.getAttribute( 'data-snt-exec' ) || '1' );
			fresh.setAttribute( 'data-snt-ran', '1' );
			old.parentNode.replaceChild( fresh, old );
		}
	}

	/**
	 * Scroll to the anchor the server asked for, once.
	 *
	 * `sn_admin_post_redirect_target()` names a `#sn-sec-*` section after a
	 * save; the host paints it as `data-snt-anchor`. The attribute is looked
	 * for on the app root AND on any descendant, because the view's own
	 * outermost element is what carries it when the host paints the attribute
	 * into its markup rather than onto the framework's container.
	 *
	 * Removed whether or not the element was found: the attribute and the body
	 * that holds the section land in the SAME paint, so a miss means the id is
	 * wrong, and a kept attribute would scroll on some unrelated later paint.
	 *
	 * @param {Element} root App root.
	 */
	function scrollToAnchor( root ) {
		var holder = root.hasAttribute( 'data-snt-anchor' ) ? root : root.querySelector( '[data-snt-anchor]' );
		if ( ! holder ) {
			return;
		}
		var id = holder.getAttribute( 'data-snt-anchor' );
		holder.removeAttribute( 'data-snt-anchor' );
		if ( ! id ) {
			return;
		}
		var target = byId( root, id );
		if ( target && typeof target.scrollIntoView === 'function' ) {
			target.scrollIntoView( { block: 'start' } );
		}
	}

	/**
	 * One pass over a freshly painted root, in the only order that works:
	 * scripts first (a leaf's own behaviour may create the markup the next two
	 * steps read), then the admin.js seam (which HIDES every section panel but
	 * the active one), then the anchor — scrolling to a section that a panel
	 * switch is about to hide would land nowhere.
	 *
	 * @param {Element} root App root.
	 */
	function pass( root ) {
		runScripts( root );
		if ( window.snAdmin && typeof window.snAdmin.init === 'function' ) {
			window.snAdmin.init( root );
		}
		scrollToAnchor( root );
	}

	/**
	 * Coalesce a paint's mutations into one pass.
	 *
	 * A frame and a short timer race, first one wins: a window in a hidden
	 * document (a background tab, a minimised desktop) is never painted and so
	 * never gets a frame, and a leaf that only ever armed itself on a frame
	 * would sit dead until the tab was looked at. The `pending` latch is what
	 * makes the loser a no-op.
	 *
	 * @param {Element} root App root.
	 */
	function schedule( root ) {
		if ( pending.has( root ) ) {
			return;
		}
		pending.add( root );
		var run = function () {
			if ( ! pending.has( root ) ) {
				return;
			}
			pending.delete( root );
			pass( root );
		};
		if ( typeof window.requestAnimationFrame === 'function' ) {
			window.requestAnimationFrame( run );
		}
		window.setTimeout( run, 50 );
	}

	/**
	 * Give a root its observer and run the paint it already has.
	 *
	 * @param {Element} root App root.
	 */
	function host( root ) {
		if ( hosted.has( root ) ) {
			return;
		}
		hosted.add( root );
		if ( typeof window.MutationObserver === 'function' ) {
			new window.MutationObserver( function () {
				schedule( root );
			} ).observe( root, { childList: true, subtree: true } );
		}
		schedule( root );
	}

	/**
	 * Host every app root at or under a node.
	 *
	 * @param {Node} node Node to inspect.
	 */
	function scan( node ) {
		if ( ! node || 1 !== node.nodeType ) {
			return;
		}
		if ( typeof node.matches === 'function' && node.matches( ROOT_SELECTOR ) ) {
			host( node );
		}
		if ( typeof node.querySelectorAll !== 'function' ) {
			return;
		}
		var found = node.querySelectorAll( ROOT_SELECTOR );
		for ( var i = 0; i < found.length; i++ ) {
			host( found[ i ] );
		}
	}

	/**
	 * Watch the document for windows that open later. A window is created when
	 * the reader opens it, which is almost always after this file has loaded,
	 * so the roots present at load are the exception rather than the rule.
	 */
	function start() {
		if ( ! document.body ) {
			return;
		}
		scan( document.body );
		if ( typeof window.MutationObserver !== 'function' ) {
			return;
		}
		new window.MutationObserver( function ( records ) {
			for ( var i = 0; i < records.length; i++ ) {
				var added = records[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					scan( added[ j ] );
				}
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
