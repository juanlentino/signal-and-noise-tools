/**
 * Signal & Noise Tools: a leaf table is a card list on a phone (17.9.0, #1624).
 *
 * `<os-table stacked>` lays each row out as a card, which is what a phone
 * wants. The shell decides "phone" by its mode stamp, `html[data-os-mode=
 * "mobile"]`, not by width: a desktop window pulled narrow keeps its grid, and
 * a phone in landscape still gets cards. OpenStation's own `stackOnPhone()`
 * makes that call, but only its list windows and Trash reach it (1.1.11), and
 * a server-painted app view cannot call a function at all. So a painter marks
 * the table `data-snt-stack-on-phone` and this mirrors `stackOnPhone()` for it.
 *
 * Re-applied, not set once: the app runtime's morph strips every attribute the
 * server did not paint, so `stacked` is gone after each repaint and put back
 * here. Setting it when it is already present changes nothing, so the observer
 * cannot loop.
 *
 * Delete this when OpenStation gives server-painted tables a declarative way
 * to ask for it: WordPress/openstation#900.
 */
( function () {
	'use strict';

	var SELECTOR = 'os-table[data-snt-stack-on-phone]';

	function phone() {
		return 'mobile' === document.documentElement.getAttribute( 'data-os-mode' );
	}

	function apply( table ) {
		if ( phone() ) {
			if ( ! table.hasAttribute( 'stacked' ) ) {
				table.setAttribute( 'stacked', '' );
			}
		} else if ( table.hasAttribute( 'stacked' ) ) {
			table.removeAttribute( 'stacked' );
		}
	}

	function applyAll() {
		var tables = document.querySelectorAll( SELECTOR );
		for ( var i = 0; i < tables.length; i++ ) {
			apply( tables[ i ] );
		}
	}

	// The whole shell mutates constantly; one pass per microtask, however many
	// records arrived, keeps this off the paint path.
	var pending = false;
	function schedule() {
		if ( pending ) {
			return;
		}
		pending = true;
		queueMicrotask( function () {
			pending = false;
			applyAll();
		} );
	}

	// A crossing into or out of phone mode.
	new MutationObserver( schedule ).observe( document.documentElement, { attributes: true, attributeFilter: [ 'data-os-mode' ] } );
	// A table painted, repainted, or stripped of `stacked` by a morph.
	new MutationObserver( schedule ).observe( document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'stacked' ] } );
	applyAll();
}() );
