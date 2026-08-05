/**
 * Signal & Noise — /verify section nav (the tab bar), v10.49.0.
 *
 * The page answers three different questions — did the checks pass, what are
 * the raw values, what changed between versions — and before this it answered
 * all three at once, stacked, in 2,400px of scroll where the verdict was the
 * first 15%. This asset turns those three into panels behind one nav.
 *
 * A real WAI-ARIA tablist: roving tabindex (exactly one tab is in the tab
 * order), Left/Right/Home/End move selection, and aria-selected drives both
 * the styling and the panel's `hidden`. Progressive by construction — the
 * shell ships every panel visible and this file is what hides the inactive
 * ones, so a blocked or failed script leaves the old always-visible page
 * rather than three unreachable panels.
 *
 * Two states that look alike but are not, and must not be conflated:
 *   - a panel is not the selected tab  → owned HERE (the wrapper's hidden);
 *   - the proof walk has no data yet   → owned by prov-verify.js (the inner
 *     <section data-role="walk"> hidden). A selected-but-empty walk panel
 *     shows its own empty-state line instead of a blank.
 *
 * Classic script IIFE; no dependency on SNProvVerifyCore.
 *
 * @since 10.49.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	var root = document.querySelector( '.sn-verify' );
	var list = root ? root.querySelector( '[data-role="tablist"]' ) : null;
	if ( ! root || ! list ) {
		return;
	}

	var tabs = [].slice.call( list.querySelectorAll( '[role="tab"]' ) );
	if ( ! tabs.length ) {
		return;
	}

	function panelFor( tab ) {
		return document.getElementById( tab.getAttribute( 'aria-controls' ) );
	}

	/**
	 * Select one tab and hide every other panel. `focusTab` is false for the
	 * initial paint and for a hash-driven selection (moving focus on load
	 * would yank a reader out of the verdict they just landed on) and true
	 * for keyboard navigation, where focus must follow selection.
	 */
	function select( target, focusTab ) {
		tabs.forEach( function ( tab ) {
			var isTarget = tab === target;
			var panel    = panelFor( tab );
			tab.setAttribute( 'aria-selected', isTarget ? 'true' : 'false' );
			// Roving tabindex: only the selected tab is tabbable, so Tab moves
			// OUT of the tablist rather than through every tab in it.
			tab.tabIndex = isTarget ? 0 : -1;
			if ( panel ) {
				panel.hidden = ! isTarget;
			}
		} );
		if ( focusTab ) {
			target.focus();
		}
		syncWalkEmpty();
	}

	/** A selected walk panel with no steps in it must say so, not sit blank. */
	function syncWalkEmpty() {
		var empty = root.querySelector( '[data-role="walk-empty"]' );
		var walk  = root.querySelector( '[data-role="walk"]' );
		if ( empty && walk ) {
			empty.hidden = ! walk.hidden;
		}
	}

	list.addEventListener( 'click', function ( evt ) {
		var tab = evt.target.closest ? evt.target.closest( '[role="tab"]' ) : null;
		if ( tab && -1 !== tabs.indexOf( tab ) ) {
			select( tab, false );
		}
	} );

	list.addEventListener( 'keydown', function ( evt ) {
		var i = tabs.indexOf( document.activeElement );
		if ( -1 === i ) {
			return;
		}
		var next = null;
		if ( 'ArrowRight' === evt.key || 'ArrowDown' === evt.key ) {
			next = tabs[ ( i + 1 ) % tabs.length ];
		} else if ( 'ArrowLeft' === evt.key || 'ArrowUp' === evt.key ) {
			next = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
		} else if ( 'Home' === evt.key ) {
			next = tabs[ 0 ];
		} else if ( 'End' === evt.key ) {
			next = tabs[ tabs.length - 1 ];
		}
		if ( next ) {
			evt.preventDefault();
			select( next, true );
		}
	} );

	/**
	 * The docket writes verdicts into the checks panel while another tab may
	 * be showing. Mirror a one-glance state onto the checks tab so the reader
	 * is never unaware that the evidence behind them finished changing, and
	 * un-blank the walk panel the moment its steps land.
	 */
	var badge = root.querySelector( '[data-role="tab-badge"]' );
	function syncBadge() {
		if ( ! badge ) {
			return;
		}
		var rows = [].slice.call( root.querySelectorAll( '.sn-verify-check' ) );
		var settled = rows.filter( function ( li ) {
			var s = li.getAttribute( 'data-state' );
			return s && 'pending' !== s;
		} );
		badge.textContent = settled.length + '/' + rows.length;
		badge.setAttribute( 'data-complete', settled.length === rows.length ? 'true' : 'false' );
	}

	if ( typeof MutationObserver !== 'undefined' ) {
		var mo = new MutationObserver( function () {
			syncBadge();
			syncWalkEmpty();
		} );
		[].slice.call( root.querySelectorAll( '.sn-verify-check' ) ).forEach( function ( li ) {
			mo.observe( li, { attributes: true, attributeFilter: [ 'data-state' ] } );
		} );
		var walk = root.querySelector( '[data-role="walk"]' );
		if ( walk ) {
			mo.observe( walk, { attributes: true, attributeFilter: [ 'hidden' ] } );
		}
	}

	// A #hash makes each panel addressable — the sections were linkable when
	// they were all on one scroll, and turning them into tabs must not quietly
	// take that away.
	var HASH = { '#proof-walk': 'walk', '#compare': 'compare', '#checks': 'checks' };
	function fromHash() {
		var want = HASH[ window.location.hash ];
		if ( ! want ) {
			return null;
		}
		for ( var i = 0; i < tabs.length; i++ ) {
			if ( want === tabs[ i ].getAttribute( 'data-panel' ) ) {
				return tabs[ i ];
			}
		}
		return null;
	}
	window.addEventListener( 'hashchange', function () {
		var tab = fromHash();
		if ( tab ) {
			select( tab, false );
		}
	} );

	select( fromHash() || tabs[ 0 ], false );
	syncBadge();
} )();
