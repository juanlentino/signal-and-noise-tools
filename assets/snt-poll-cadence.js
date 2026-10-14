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

	/*
	 * A brief miss (2026-10-14). WordPress answers 503 for the seconds a plugin
	 * or theme install holds it in maintenance mode, and a request that never
	 * got an answer (offline, a dropped connection) is the same kind of miss.
	 * A card keeps its reading, says "Updating, retrying." and tries again at
	 * 5 s, 15 s and 30 s; only a fourth miss shows its error state. Maintenance
	 * sends Retry-After: 600 as a header the cards never see; a brief miss
	 * honors no hint either way.
	 *
	 * Brief: HTTP 503; wp.apiFetch's fetch_error (no answer); a page that is
	 * not JSON with no status (an HTML 503 through wp.apiFetch, which drops the
	 * status); the browser's own "Failed to fetch" family.
	 */
	var BRIEF_MS = [ 5000, 15000, 30000 ];

	function brief( err ) {
		if ( ! err ) {
			return false;
		}
		var status = err.data ? Number( err.data.status ) || 0 : 0;
		// Accepted: an HTML 403 or 429 through wp.apiFetch loses its status too,
		// so it counts as brief (about 50 s more before its error, 3 extra requests).
		if ( 503 === status || 'fetch_error' === err.code || ( 'invalid_json' === err.code && ! status ) ) {
			return true;
		}
		return ! status && /failed to fetch|networkerror|load failed/i.test( String( err.message || '' ) );
	}

	/**
	 * How long to wait after a miss, when it is brief and the ladder has a step left.
	 *
	 * @param {*}      err   The rejection.
	 * @param {number} tries Brief misses since the last good read.
	 * @return {number} ms, or 0: not brief (or the ladder is spent), take the error path.
	 */
	function briefWait( err, tries ) {
		return brief( err ) && tries < BRIEF_MS.length ? BRIEF_MS[ tries ] : 0;
	}

	window.sntPollCadence = { IDLE_MS: IDLE_MS, BRIEF_TEXT: 'Updating, retrying.', state: state, wait: wait, onFocusChange: onFocusChange, brief: brief, briefWait: briefWait };
}() );
