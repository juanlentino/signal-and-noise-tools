/**
 * Signal & Noise Tools — focus-aware poll cadence for the desktop widgets.
 *
 * The widgets already stop polling while the tab is hidden (Recipe 2, #1603).
 * That left one state unpriced: VISIBLE BUT NOT FOCUSED, the OpenStation PWA
 * sitting open on a screen while its owner works in another app. On
 * 2026-09-25 the edge log showed the deploy, queue and cache widgets each
 * polling ~650 times a day, the arithmetic of one visible window for ~11 h
 * at 60 s. A desktop app does what this file encodes: full rate in the
 * foreground, throttled in the background, instant catch-up on return.
 *
 *   focused  -> the widget's own interval (unchanged)
 *   visible  -> at least IDLE_MS
 *   hidden   -> the widget's own hidden handling (paused)
 *
 * FOCUS IS READ FROM THE TOP DOCUMENT. OpenStation paints classic windows as
 * iframes, and moving focus into one fires `blur` on the parent while the
 * owner is still working in the desktop. So `blur` is only a prompt to
 * re-read; the answer is top.document.hasFocus(), which stays true while any
 * descendant frame holds focus. A cross-origin top falls back to this
 * document.
 *
 * Absent this file (a widget painted by a mid-session shell activation
 * before it loaded), every widget keeps its fixed interval: the helper is an
 * optimisation, never a dependency the widget cannot run without.
 *
 * @package SignalNoiseTools
 */
( function () {
	'use strict';
	if ( window.sntPollCadence ) {
		return;
	}

	var IDLE_MS = 5 * 60 * 1000;

	function topWindow() {
		try {
			if ( window.top && window.top.document ) {
				return window.top;
			}
		} catch ( e ) {}
		return window;
	}

	/**
	 * @return {string} 'hidden' | 'focused' | 'visible'
	 */
	function state() {
		if ( document.hidden ) {
			return 'hidden';
		}
		var focused = true;
		try {
			var doc = topWindow().document;
			focused = 'function' === typeof doc.hasFocus ? doc.hasFocus() : true;
		} catch ( e ) {
			focused = true;
		}
		return focused ? 'focused' : 'visible';
	}

	/**
	 * The wait for a widget whose focused interval is `focusedMs`.
	 *
	 * @param {number} focusedMs
	 * @return {number}
	 */
	function wait( focusedMs ) {
		return 'visible' === state() ? Math.max( focusedMs, IDLE_MS ) : focusedMs;
	}

	/**
	 * Call `cb` after focus moves in or out of the window. Deferred one tick so
	 * hasFocus() reads the settled state, not the one mid-transition.
	 *
	 * @param {Function} cb
	 * @return {Function} unsubscribe
	 */
	function onFocusChange( cb ) {
		var t = 0;
		var targets = [ window ];
		var top = topWindow();
		if ( top !== window ) {
			targets.push( top );
		}
		function fire() {
			window.clearTimeout( t );
			t = window.setTimeout( cb, 0 );
		}
		targets.forEach( function ( w ) {
			w.addEventListener( 'focus', fire );
			w.addEventListener( 'blur', fire );
		} );
		return function () {
			window.clearTimeout( t );
			targets.forEach( function ( w ) {
				w.removeEventListener( 'focus', fire );
				w.removeEventListener( 'blur', fire );
			} );
		};
	}

	window.sntPollCadence = { IDLE_MS: IDLE_MS, state: state, wait: wait, onFocusChange: onFocusChange };
}() );
