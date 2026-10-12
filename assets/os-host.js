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
 *   3. The leaf-owned scripts the host appends (Cron's buttons, the
 *      uptime panel, the provenance stepper, the analytics brush) each
 *      armed themselves ONCE, against the window's first paint, which
 *      holds nothing but a spinner. This file therefore dispatches a
 *      `snt:paint` CustomEvent on `document` at the end of every pass, with
 *      the painted root in `detail.root`, and each of those scripts re-arms
 *      from it. It is dispatched on `document`, not on the root, so a script
 *      subscribes once for every window rather than per root.
 *
 * And one thing a window does differently: the classic page scrolls to a
 * `#sn-sec-*` fragment after a save. A window has no URL to carry a fragment,
 * so the view queues the id on the paint effect and this file scrolls to it.
 *
 * WHAT IT DOES NOT DO. It never reloads, never fetches, and knows no endpoint:
 * every request in these windows is the framework's own dispatch. It is plain
 * ES2019 with no dependency beyond the seam admin.js publishes, and no build
 * step — the rest of the plugin's JS is written the same way.
 *
 * HOW A PAINT IS KNOWN (#1609). The runtime announces one: each view
 * callable queues `$os->effects->add( 'snt-paint', array( 'anchor' => … ) )`,
 * and the runtime, having morphed the body and finished the render, re-
 * dispatches every effect type it does not perform itself as an
 * `os-app-effect` CustomEvent on the app root (bubbles, composed, `detail =
 * { appId, windowId, view, effect }`): openstation src/app-runtime/session.ts
 * `performEffect()` default branch, docs/app-framework.md "Effects" and
 * docs/javascript-reference.md. Status: Experimental (App Framework), present
 * since v1.1.6, the plugin's verified floor. Every paint these windows get
 * (mount, the prewarmed mount, a dispatch) runs through the same `apply()`
 * and performs the effects; the one `apply()` fed `effects: []` is
 * `paintEagerly()`, a client-view (`.os.ts`) path neither host uses. So there
 * is no observer here: the root is named by the event, never discovered, and
 * a write the pass makes schedules nothing. A marker that must survive the
 * morph is still a PROPERTY or a WeakSet, never an attribute (`zt` above).
 *
 * @package SignalNoiseTools
 */
( function () {
	'use strict';

	/**
	 * The PHP frame owns the app identity inside each native window. The event
	 * fires on the framework's container; the pass runs over this frame.
	 */
	var ROOT_SELECTOR = '.snt-app[data-os-app="sn-dashboard"], .snt-app[data-os-app="sn-analytics"]';

	/** Roots with a pass already scheduled for the next paint. */
	var pending = new WeakSet();

	/** The anchor the latest effect for a root asked for, until its pass runs. */
	var anchors = new WeakMap();

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
	 * behaviour. Both nodes are marked ran so a later pass over a node the
	 * morph kept cannot run the same block again.
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
	 * Scroll to the anchor the server asked for.
	 *
	 * `sn_admin_post_redirect_target()` names a `#sn-sec-*` section after a
	 * save; the view queues it as the `anchor` of the `snt-paint` effect, and
	 * the id and the body that holds the section land in the SAME paint, so a
	 * miss means the id is wrong and there is nothing to scroll to.
	 *
	 * @param {Element} root   App root.
	 * @param {string}  anchor Element id, or '' for none.
	 */
	function scrollToAnchor( root, anchor ) {
		if ( ! anchor ) {
			return;
		}
		var target = byId( root, anchor );
		if ( target && typeof target.scrollIntoView === 'function' ) {
			target.scrollIntoView( { block: 'start' } );
		}
		// 2026-10-11: a target that asks (`data-snt-focus`, with tabindex -1)
		// takes focus too: a row's Edit opens one region below a long table,
		// and the keyboard must land there, not stay on the row (WCAG 2.4.3).
		if ( target && target.hasAttribute( 'data-snt-focus' ) ) {
			// A region (tabindex -1) takes focus itself; a row's anchor wraps the
			// row's own os-button, whose focusable <button> is in its shadow root
			// (2026-10-12: Cancel lands back on the row it left).
			var focusable = target.hasAttribute( 'tabindex' ) ? target : target.querySelector( 'os-button' );
			if ( focusable && focusable.shadowRoot && focusable.shadowRoot.querySelector( 'button' ) ) {
				focusable = focusable.shadowRoot.querySelector( 'button' );
			}
			if ( focusable && typeof focusable.focus === 'function' ) {
				focusable.focus( { preventScroll: true } );
			}
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
	/**
	 * 16.3.1: an <os-table> painted into a leaf right after an action (the
	 * Health leaf after "Re-run scan") can hold its rows in `data` and still
	 * show the empty state: the component rendered before its os-prop-data
	 * landed and did not render again. Reopening the leaf fixed it, which is
	 * the tell. Re-assigning the same rows makes it render once more. Only a
	 * table in exactly that state is touched; a table with rows on screen, or
	 * with no rows, is left alone. Upstream watch: OpenStation (os-table renders once
	 * before late props), beside #808/#809.
	 *
	 * @param {Element} root App root.
	 */
	function renudgeTables( root ) {
		var tables = root.querySelectorAll( 'os-table' );
		for ( var i = 0; i < tables.length; i++ ) {
			var t = tables[ i ];
			if ( ! Array.isArray( t.data ) || ! t.data.length || ! t.shadowRoot ) {
				continue;
			}
			var rows = t.shadowRoot.querySelectorAll( 'tbody tr' );
			var empty = t.getAttribute( 'empty' ) || '';
			if ( rows.length === 1 && empty && ( rows[ 0 ].textContent || '' ).trim() === empty ) {
				t.data = t.data.slice();
			}
		}
	}

	function pass( root, anchor ) {
		runScripts( root );
		renudgeTables( root );
		if ( window.snAdmin && typeof window.snAdmin.init === 'function' ) {
			window.snAdmin.init( root );
		}
		scrollToAnchor( root, anchor );
		mioSync( root );
		document.dispatchEvent( new CustomEvent( 'snt:paint', { detail: { root: root } } ) );
	}

	/**
	 * Defer one pass to the frame after the paint.
	 *
	 * A frame and a short timer race, first one wins: a window in a hidden
	 * document (a background tab, a minimised desktop) is never painted and so
	 * never gets a frame, and a leaf that only ever armed itself on a frame
	 * would sit dead until the tab was looked at. The `pending` latch is what
	 * makes the loser a no-op, and it is one-shot: the effect that set it is
	 * the paint the pass answers. The deferral itself stays because 16.3.1's
	 * renudgeTables() reads a component that may still be upgrading.
	 *
	 * @param {Element} root   App root.
	 * @param {string}  anchor Element id to land on, or ''.
	 */
	function schedule( root, anchor ) {
		anchors.set( root, anchor );
		if ( pending.has( root ) ) {
			return;
		}
		pending.add( root );
		var run = function () {
			if ( ! pending.has( root ) ) {
				return;
			}
			pending.delete( root );
			var id = anchors.get( root ) || '';
			anchors.delete( root );
			pass( root, id );
		};
		if ( typeof window.requestAnimationFrame === 'function' ) {
			window.requestAnimationFrame( run );
		}
		window.setTimeout( run, 50 );
	}

	// ----------------------------------------------------------------- FOLDS
	// 2026-10-12. An earned fold (snt_kit_fold(), `data-snt-fold`) keeps the
	// reader's choice across repaints. The morph mirrors the painted
	// attributes, so it strips an `open` the reader set (a filter keystroke, a
	// watch tick, a save); os-disclosure reports each click as
	// `os-disclosure-toggle`, and the choice goes back on the paint's own
	// effect, in the task that morphed, so the fold never flickers shut.
	// The choice holds only while the page's own verdict (`data-snt-open`)
	// is the one the reader answered: an event that turns late reopens a fold
	// the reader closed. Setting the attribute from code emits nothing.
	var folds = {};

	function rememberFold( event ) {
		var el = event.target;
		var id = el && el.getAttribute ? el.getAttribute( 'data-snt-fold' ) : null;
		if ( id ) {
			folds[ id ] = { open: !! ( event.detail && event.detail.open ), verdict: el.getAttribute( 'data-snt-open' ) };
		}
	}

	function restoreFolds( root ) {
		var list = root.querySelectorAll( 'os-disclosure[data-snt-fold]' );
		for ( var i = 0; i < list.length; i++ ) {
			var id   = list[ i ].getAttribute( 'data-snt-fold' );
			var memo = Object.prototype.hasOwnProperty.call( folds, id ) ? folds[ id ] : null;
			if ( ! memo ) {
				continue;
			}
			if ( memo.verdict !== list[ i ].getAttribute( 'data-snt-open' ) ) {
				delete folds[ id ]; // the page changed its answer; follow the paint
				continue;
			}
			if ( memo.open !== list[ i ].hasAttribute( 'open' ) ) {
				list[ i ].toggleAttribute( 'open', memo.open );
			}
		}
	}

	// --------------------------------------------------------------- NOTICES
	// 2026-10-12 audit. os-notice's close sets `hidden`, and the morph strips
	// it on the next paint, so a watch tick (Provenance polls every 30
	// seconds) brought a closed "Saved" back. A closed notice is remembered
	// per window by its tone and words and hidden again on the paint's own
	// effect, like a fold. A new answer (a save or a refusal) shows its
	// notice again even in the same words, and a notice that leaves the page
	// is forgotten.
	var closedNotices = new WeakMap();

	function noticeKey( el ) {
		return ( el.getAttribute( 'tone' ) || '' ) + '|' + String( el.textContent || '' ).trim();
	}

	function rememberNotice( event ) {
		var el = event.target;
		var root = el && el.closest ? el.closest( ROOT_SELECTOR ) : null;
		if ( root ) {
			var memo = closedNotices.get( root ) || {};
			memo[ noticeKey( el ) ] = true;
			closedNotices.set( root, memo );
		}
	}

	function restoreNotices( root, answered ) {
		var memo = closedNotices.get( root );
		if ( ! memo ) {
			return;
		}
		var next = {};
		var list = answered ? [] : root.querySelectorAll( 'os-notice' );
		for ( var i = 0; i < list.length; i++ ) {
			var key = noticeKey( list[ i ] );
			if ( memo[ key ] ) {
				list[ i ].hidden = true;
				next[ key ] = true;
			}
		}
		closedNotices.set( root, next );
	}

	// ---------------------------------------------------------------- DRAFTS
	// 2026-10-12 audit. The morph puts every control the reader is not in
	// back to the value the server painted (openstation morph.ts
	// syncAttributes()/syncFormValue() skip only the focused one), so text
	// typed into one field was lost when the page repainted for anything
	// else: a list's Edit on Workflow and Resume, a watch tick, a refused
	// save. A draft is what the reader typed, kept per window against the
	// value the server painted under it: it goes back after a paint that
	// still paints that value, and is dropped when the server paints a new
	// one, when the field leaves the leaf (Cancel closes an editor), when the
	// window shows another leaf, or when the paint answering its form's submit
	// says `saved` (an add form repaints blank after a save that took).
	//
	// Recorded on the BUBBLE: a kit field sets its own value in its input
	// handler, so a capture listener read it one keystroke late. os-select
	// reports a pick as `os-pick` only. A draft is keyed by its form (the
	// form's action, then its place among forms with that action) and its
	// place in that form, so an editor opening above an add form does not
	// shift the add form's keys. Password fields are never kept.
	var DRAFT_FIELDS = 'os-text-field[name], os-textarea[name], os-number-field[name], os-select[name], textarea[name], select[name], input[name]';
	var DRAFT_SKIP = { hidden: 1, checkbox: 1, radio: 1, submit: 1, button: 1, reset: 1, image: 1, file: 1, password: 1 };
	var drafts = new WeakMap();
	var submitted = new WeakMap(); // root -> [ { scope, action } ] submitted and not yet answered

	function formOf( el ) {
		return el && el.closest ? el.closest( 'form, os-form' ) : null;
	}

	function formAction( form ) {
		var a = form.querySelector( 'input[name="action"], input[name="sn_action"]' );
		return ( a && a.value ) || form.getAttribute( 'os-action' ) || '';
	}

	function formKeys( root ) {
		var keys = new Map();
		var seen = {};
		var forms = root.querySelectorAll( 'form, os-form' );
		for ( var i = 0; i < forms.length; i++ ) {
			var action = formAction( forms[ i ] );
			seen[ action ] = ( seen[ action ] || 0 ) + 1;
			keys.set( forms[ i ], action + '@' + seen[ action ] );
		}
		return keys;
	}

	function draftFields( root ) {
		var out = [];
		var seen = {};
		var keys = formKeys( root );
		var list = root.querySelectorAll( DRAFT_FIELDS );
		for ( var i = 0; i < list.length; i++ ) {
			var el = list[ i ];
			var type = String( ( 'INPUT' === el.nodeName ? el.type : el.getAttribute( 'type' ) ) || '' ).toLowerCase();
			if ( ( 'INPUT' === el.nodeName && DRAFT_SKIP[ type ] ) || ( 'OS-TEXT-FIELD' === el.nodeName && 'password' === type ) ) {
				continue;
			}
			var form = formOf( el );
			var scope = ( form && keys.get( form ) ) || '-';
			var name = scope + '|' + el.getAttribute( 'name' );
			seen[ name ] = ( seen[ name ] || 0 ) + 1;
			out.push( { key: name + '#' + seen[ name ], scope: scope, el: el } );
		}
		return out;
	}

	function paintedValue( el ) {
		// A kit control reflects its value into the attribute the morph sets;
		// a native one holds it as the property the morph sets.
		return 0 === el.nodeName.indexOf( 'OS-' ) ? ( el.getAttribute( 'value' ) || '' ) : String( el.value == null ? '' : el.value );
	}

	function leafOf( root ) {
		var leaf = root.querySelector( '.snt-leaf' );
		return leaf ? leaf.getAttribute( 'data-snt-leaf' ) || '' : '';
	}

	function rememberDraft( event ) {
		var el = event.target;
		var root = el && el.closest ? el.closest( ROOT_SELECTOR ) : null;
		var memo = root ? drafts.get( root ) : null;
		if ( ! memo ) {
			return;
		}
		var picked = event.detail && Object.prototype.hasOwnProperty.call( event.detail, 'value' ) ? event.detail.value : el.value;
		var fields = draftFields( root );
		for ( var i = 0; i < fields.length; i++ ) {
			if ( fields[ i ].el === el && Object.prototype.hasOwnProperty.call( memo.base, fields[ i ].key ) ) {
				memo.typed[ fields[ i ].key ] = String( picked == null ? '' : picked );
				return;
			}
		}
	}

	function rememberSubmit( event ) {
		var form = event.target;
		var root = form && form.closest ? form.closest( ROOT_SELECTOR ) : null;
		if ( ! root || ! ( 'FORM' === form.nodeName || 'OS-FORM' === form.nodeName ) ) {
			return;
		}
		var scope = formKeys( root ).get( form );
		if ( scope ) {
			// Every submit waits for the answer that names its action; two
			// quick submits queue, and a confirm the reader cancelled is
			// never answered, so its drafts stay.
			var list = submitted.get( root ) || [];
			list.push( { scope: scope, action: formAction( form ) } );
			submitted.set( root, list );
		}
	}

	function restoreDrafts( root, refused, saved, answered ) {
		var memo = drafts.get( root );
		var leaf = leafOf( root );
		var same = !! memo && memo.leaf === leaf;
		// Only the answer that names a form's action ends that form's submit:
		// `saved` drops its drafts, `refused` keeps them; any other paint (a
		// watch tick, another form's answer, a one-click action) leaves it
		// pending.
		var pending = submitted.get( root ) || [];
		var dropped = [];
		if ( answered && ( saved || refused ) ) {
			pending = pending.filter( function ( p ) {
				if ( p.action !== answered ) {
					return true;
				}
				if ( saved ) {
					dropped.push( p.scope );
				}
				return false;
			} );
			submitted.set( root, pending );
		}
		var next = { leaf: leaf, base: {}, typed: {} };
		var active = document.activeElement;
		var fields = draftFields( root );
		for ( var i = 0; i < fields.length; i++ ) {
			var key = fields[ i ].key;
			var el = fields[ i ].el;
			// A save that took ends its form's drafts; a refused one keeps them.
			var had = same && Object.prototype.hasOwnProperty.call( memo.typed, key ) && -1 === dropped.indexOf( fields[ i ].scope );
			if ( active && ( el === active || ( el.contains && el.contains( active ) ) ) ) {
				// The morph left the reader's field alone: so does this.
				next.base[ key ] = same && Object.prototype.hasOwnProperty.call( memo.base, key ) ? memo.base[ key ] : paintedValue( el );
				if ( had ) {
					next.typed[ key ] = memo.typed[ key ];
				}
				continue;
			}
			var painted = paintedValue( el );
			next.base[ key ] = painted;
			if ( had && memo.base[ key ] === painted ) {
				if ( String( el.value == null ? '' : el.value ) !== memo.typed[ key ] ) {
					el.value = memo.typed[ key ];
				}
				next.base[ key ] = memo.base[ key ];
				next.typed[ key ] = memo.typed[ key ];
			}
		}
		drafts.set( root, next );
	}

	/**
	 * The runtime's own word that a paint landed.
	 *
	 * `os-app-effect` (openstation src/app-runtime/session.ts, Experimental,
	 * v1.1.6+) fires on the app's container for every effect the runtime does
	 * not perform itself, after the morph and finishRender(). The two views
	 * queue `snt-paint`; anything else is another app's business.
	 *
	 * @param {CustomEvent} event The effect event.
	 */
	function onEffect( event ) {
		var detail = event.detail || {};
		var effect = detail.effect || {};
		if ( 'snt-paint' !== effect.type ) {
			return;
		}
		var target = event.target;
		if ( ! target || 1 !== target.nodeType ) {
			return;
		}
		var root = target.matches( ROOT_SELECTOR ) ? target : target.querySelector( ROOT_SELECTOR );
		if ( ! root ) {
			return;
		}
		restoreFolds( root );
		restoreNotices( root, !! ( effect.saved || effect.refused ) );
		restoreDrafts( root, !! effect.refused, !! effect.saved, String( effect.answered || '' ) );
		schedule( root, String( effect.anchor || '' ) );
	}


	// ------------------------------------------------------------------- MIO
	// 14.8.0. The shell's companion in the two host windows: the plugin's help
	// and a prompt scoped to the window when help is on, and one plain-text
	// callout when tips are on (an empty Commits table whose last sweep
	// reported failures points at Trust checks). Both follow the per-user
	// switches in OS Settings › Signal & Noise (window.sntMio, localized by
	// inc/openstation-mio.php). A lease per root, disposed when the root
	// leaves the document; the shell disposes it on its own too, this just
	// keeps the map honest. Nothing here calls a model or writes.
	var mioLeases = new WeakMap();

	function mioBag() {
		return window.sntMio || {};
	}

	function mioWindowId( root ) {
		var win = root.closest ? root.closest( '.os-window' ) : null;
		var id  = win && win.id ? String( win.id ) : '';
		return id.indexOf( 'wp-window-' ) === 0 ? id.slice( 'wp-window-'.length ) : '';
	}

	function mioRegister( root ) {
		var bag = mioBag();
		var api = window.wp && window.wp.os && window.wp.os.mio;
		if ( ! api || typeof api.registerWindow !== 'function' || ( ! bag.tips && ! bag.help ) ) {
			return null;
		}
		var app      = root.getAttribute( 'data-os-app' ) === 'sn-analytics' ? 'analytics' : 'dashboard';
		var windowId = mioWindowId( root );
		if ( ! windowId ) {
			return null;
		}
		var title = app === 'analytics' ? 'S&N Analytics' : 'S&N Home';
		try {
			return api.registerWindow( windowId, {
				host: root,
				title: title,
				prompt: function () {
					var where = app === 'analytics'
						? 'View: ' + ( root.getAttribute( 'data-snt-view' ) || '' )
						: 'Tab: ' + ( root.getAttribute( 'data-snt-tab' ) || '' );
					return String( ( bag.prompts && bag.prompts[ app ] ) || '' ) + ' ' + where + '.';
				},
				documents: bag.help ? ( bag.documents || [] ) : [],
				abilities: function () { return []; },
			} );
		} catch ( e ) {
			return null;
		}
	}

	function mioSync( root ) {
		var lease = mioLeases.get( root );
		if ( ! lease ) {
			lease = mioRegister( root );
			if ( ! lease ) {
				return;
			}
			mioLeases.set( root, lease );
		}
		if ( ! mioBag().tips || typeof lease.showCallout !== 'function' ) {
			return;
		}
		// The one tip: an empty Commits table that quotes a failing sweep.
		var table = root.querySelector( 'os-table[empty*="failing"]' );
		if ( table ) {
			lease.showCallout( {
				id: 'commits-failing',
				target: function () { return root.querySelector( 'os-table[empty*="failing"]' ); },
				message: 'This table lists pending proofs only. The failures it quotes are in Trust checks, with the sweep\'s time on each.',
			} );
		} else if ( typeof lease.clearCallout === 'function' ) {
			lease.clearCallout();
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
	 * One listener on the document for every window, open now or later: the
	 * event bubbles from whichever app root the runtime painted.
	 */
	function start() {
		armSubmitter();
		// Capture: the toggle is the reader's, wherever it bubbles or not.
		document.addEventListener( 'os-disclosure-toggle', rememberFold, true );
		document.addEventListener( 'os-notice-dismiss', rememberNotice, true );
		// Bubble, not capture: a kit field has set its value by then.
		document.addEventListener( 'input', rememberDraft );
		document.addEventListener( 'change', rememberDraft );
		document.addEventListener( 'os-pick', rememberDraft );
		document.addEventListener( 'submit', rememberSubmit, true );
		document.addEventListener( 'os-form-submit', rememberSubmit, true );
		document.addEventListener( 'os-app-effect', onEffect );
	}

	start();
} )();
