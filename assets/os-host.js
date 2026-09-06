/**
 * Signal & Noise Tools — the OpenStation host script.
 *
 * WHY THIS EXISTS. The two hosts (`sn-dashboard`, `sn-analytics`) are server
 * views: the window paints the SAME admin HTML the classic page paints, and it
 * repaints it on every action.
 *
 * HOW A PAINT ACTUALLY LANDS — and it is NOT `innerHTML` on the root. The
 * runtime parses the server's HTML into a `<template>` and MORPHS the existing
 * tree into it: `Ut()` (offset 25085 of desktop-mode's assets/js/app-runtime
 * .min.js) hands the parsed children to `Se()` (25455), which matches a child
 * carrying no `os-key`/`id` POSITIONALLY, by tag name, and morphs it in place
 * through `Xt()` (25943) → `zt()` (26198). Every element the server repaints
 * therefore KEEPS its node identity (and its listeners), and `zt`'s second
 * loop — `for (const o of Array.from(e.attributes)) n.hasAttribute(o.name) ||
 * … || e.removeAttribute(o.name)` (26430) — REMOVES every attribute the
 * server's node does not carry. Three consequences this file exists for:
 *
 *   1. A `<script>` that arrives with a paint does not run: a node morphed in
 *      place is never re-prepared, so a leaf's inline block (a chart's data, a
 *      bootstrap call) would silently do nothing. The rewrite pass marks every
 *      such block `data-snt-exec`; this file re-creates each marked node once,
 *      which is the only way to make a parsed-in script run.
 *   2. assets/admin.js binds on DOMContentLoaded, which fired long before the
 *      window opened and never fires again. It now publishes an idempotent
 *      `window.snAdmin.init( root )`; this file calls it after every paint.
 *   3. The nine leaf-owned scripts the host appends (Cron's buttons, the
 *      uptime panel, the provenance stepper, the freshness dot, the analytics
 *      brush) each armed themselves ONCE, against the window's first paint —
 *      which holds nothing but a spinner. This file therefore dispatches a
 *      `snt:paint` CustomEvent on `document` at the end of every pass, with
 *      the painted root in `detail.root`, and each of those scripts re-arms
 *      from it. It is dispatched on `document`, not on the root, so a script
 *      subscribes once for every window rather than per root.
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
 * app root, so anything it writes — a replaced script node, a removed
 * `data-snt-anchor`, whatever a leaf script paints on `snt:paint` — schedules
 * one more pass. Every one of those writes is marked, so that pass finds
 * nothing and the observer settles: one extra no-op pass per paint, never a
 * loop. A marker that must survive the morph is a PROPERTY or a WeakSet, never
 * an attribute (`zt` above); a marker that must be CLEARED by the morph — "the
 * content I painted is still the content on screen" — is exactly an attribute.
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
	 * A script node the runtime morphed into place is never re-prepared, and
	 * one parsed out of a `<template>` is inert where it lands; only a node
	 * created by `document.createElement` and inserted runs. `src`, `type` and
	 * the inline text carry over — nothing else, because nothing else is
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
	 * The paint event is LAST, after all three: a leaf script that re-arms on
	 * it must see the markup the scripts step created and the panel state the
	 * seam applied, and an anchor scroll must not be undone by a leaf script
	 * painting into the section underneath it.
	 *
	 * @param {Element} root App root.
	 */
	function pass( root ) {
		runScripts( root );
		if ( window.snAdmin && typeof window.snAdmin.init === 'function' ) {
			window.snAdmin.init( root );
		}
		scrollToAnchor( root );
		document.dispatchEvent( new CustomEvent( 'snt:paint', { detail: { root: root } } ) );
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

	// ---------------------------------------------------------------- submitter
	// A classic POST carries the clicked submit button's name and value (the
	// browser adds the submitter to the form data set); the runtime ships
	// `new FormData( form )` (`jt()`, offset 22876 of app-runtime.min.js),
	// which never includes the submitter, so a form whose `sn_action` rides
	// its button -- 45 of the estate's forms -- would arrive with no action
	// and save nothing. The rewrite marks named submit buttons
	// `data-snt-submit`; this appends the submitter as a hidden input LAST,
	// before the runtime serialises.
	//
	// PHP'S LATER-VALUE-WINS RULE DOES NOT APPLY HERE, and appending beside a
	// same-named field is not "the last value". The runtime never sends a
	// urlencoded body: `jt()` folds a repeated name into an ARRAY
	// (`o[i]=Array.isArray(r)?[...r,s]:[r,s]`, offset 23311) and the replay
	// requires a SCALAR `sn_action` (inc/openstation-host.php, `is_scalar`),
	// refusing anything else as unknown. inc/admin-forms/ai-settings.php
	// carries both a hidden `sn_action=ai_settings_save` (line 53) and a
	// `sn_action=ml_embed_compare` button (line 252), so a bare append ships
	// [ 'ai_settings_save', 'ml_embed_compare' ] and "Run comparison" answers
	// "Nothing was saved." What later-value-wins MEANS for one scalar is
	// therefore reproduced directly: every other field of the submitter's name
	// is DISABLED for this dispatch -- FormData skips disabled fields -- and
	// re-enabled on the next tick, so a refused dispatch leaves the form
	// usable and the reader can press the button again.
	var lastSubmitter = null;

	function rememberSubmitter( e ) {
		var t = e.target;
		if ( ! t || typeof t.closest !== 'function' ) {
			return;
		}
		var btn = t.closest( '[data-snt-submit]' );
		lastSubmitter = btn && btn.form && btn.form.hasAttribute( 'os-action' ) ? btn : null;
	}

	/**
	 * Disable every serialisable field in the form that already carries the
	 * submitter's name, so the carrier appended after this is the ONLY value
	 * FormData sees for it.
	 *
	 * Only `input`/`select`/`textarea` are touched, and not the button kinds:
	 * a button is never in a `new FormData( form )` entry list, so disabling
	 * one would grey the reader's own button for a tick and buy nothing. A
	 * field the page had already disabled is left alone and unmarked — it must
	 * still be disabled when the tick that re-enables ours runs.
	 *
	 * @param {HTMLFormElement} form Form being submitted.
	 * @param {string}          name The submitter's name.
	 */
	function shadowSameName( form, name ) {
		var fields = form.querySelectorAll( 'input, select, textarea' );
		for ( var i = 0; i < fields.length; i++ ) {
			var field = fields[ i ];
			var type = ( field.type || '' ).toLowerCase();
			if ( field.name !== name || field.disabled ) {
				continue;
			}
			if ( 'submit' === type || 'button' === type || 'reset' === type || 'image' === type ) {
				continue;
			}
			field.disabled = true;
			field.setAttribute( 'data-snt-shadowed', '1' );
		}
	}

	/**
	 * Re-enable what `shadowSameName()` disabled, on the next tick.
	 *
	 * The runtime reads the form synchronously inside its own submit listener,
	 * so a timer of 0 is after the values are taken and before the reader can
	 * touch anything. Only fields this file marked are restored.
	 *
	 * @param {HTMLFormElement} form Form that was submitted.
	 */
	function unshadowSoon( form ) {
		window.setTimeout( function () {
			var shadowed = form.querySelectorAll( '[data-snt-shadowed]' );
			for ( var i = 0; i < shadowed.length; i++ ) {
				shadowed[ i ].disabled = false;
				shadowed[ i ].removeAttribute( 'data-snt-shadowed' );
			}
		}, 0 );
	}

	function carrySubmitter( e ) {
		var form = e.target;
		if ( ! form || 'FORM' !== form.nodeName || ! form.hasAttribute( 'os-action' ) ) {
			return;
		}
		// The event's own submitter first (it also covers an Enter in a field);
		// the remembered click second; the form's default button last, which
		// is what implicit submission uses.
		var btn = e.submitter || ( lastSubmitter && lastSubmitter.form === form ? lastSubmitter : form.querySelector( '[data-snt-submit]' ) );
		lastSubmitter = null;
		if ( ! btn || ! btn.name ) {
			return;
		}
		var stale = form.querySelectorAll( 'input[data-snt-submitter]' );
		for ( var i = 0; i < stale.length; i++ ) {
			stale[ i ].parentNode.removeChild( stale[ i ] );
		}
		// Shadow BEFORE the carrier is appended, or the carrier disables
		// itself; schedule the undo before it too, so appending the carrier
		// stays the last thing this function does to the form data set.
		shadowSameName( form, btn.name );
		unshadowSoon( form );
		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = btn.name;
		input.value = btn.value || '';
		input.setAttribute( 'data-snt-submitter', '1' );
		form.appendChild( input );
	}

	function armSubmitter() {
		if ( armSubmitter.done ) {
			return;
		}
		armSubmitter.done = true;
		// Capture on the document: it runs before the runtime's own submit
		// listener wherever that one sits, so the submitter is in the form
		// when the values are read.
		document.addEventListener( 'click', rememberSubmitter, true );
		document.addEventListener( 'submit', carrySubmitter, true );
	}

	/**
	 * Watch the document for windows that open later. A window is created when
	 * the reader opens it, which is almost always after this file has loaded,
	 * so the roots present at load are the exception rather than the rule.
	 */
	function start() {
		armSubmitter();
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
