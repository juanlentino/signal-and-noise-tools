/**
 * Signal & Noise Tools: the desktop widgets' pulse.
 *
 * One poller for every widget: GET /signal-noise/v1/desktop/pulse returns a
 * stamp per kind of change (content, deploy). A widget subscribes to a stamp
 * and re-reads its own data only when that stamp moves, so a post scheduled
 * in another window reaches SN Queue in seconds without every widget polling
 * fast (owner, 2026-10-10).
 *
 *   focused  -> every PULSE_MS
 *   visible  -> every 5 min (assets/snt-poll-cadence.js)
 *   hidden   -> paused; back in view or in focus, read at once
 *
 * The first answer is the baseline and fires nothing. A failure doubles the
 * wait up to 5 min. No subscriber, no request.
 *
 *   var off = window.sntPulse.on( 'content', refresh ); // off() unsubscribes
 *
 * @package SignalNoiseTools
 */
( function () {
	'use strict';
	if ( window.sntPulse ) {
		return;
	}

	var PULSE_MS = 20 * 1000;
	var MAX_MS   = 5 * 60 * 1000;
	var subs     = {};   // key -> [callbacks]
	var count    = 0;
	var last     = null; // the previous stamps
	var timer    = 0;
	var pending  = false;
	var failures = 0;
	var unwatch  = null;
	var lastAt   = 0;    // when the last read started
	var FLOOR_MS = 5 * 1000; // focus moving into an OpenStation frame fires blur; not a read each time

	function wait() {
		var base = window.sntPollCadence ? window.sntPollCadence.wait( PULSE_MS ) : PULSE_MS;
		return failures ? Math.min( MAX_MS, Math.max( base, PULSE_MS * Math.pow( 2, failures ) ) ) : base;
	}

	function arm() {
		window.clearTimeout( timer );
		timer = 0;
		if ( ! count || document.hidden ) {
			return;
		}
		timer = window.setTimeout( read, wait() );
	}

	function fire( stamps ) {
		Object.keys( subs ).forEach( function ( key ) {
			if ( last && stamps[ key ] !== last[ key ] ) {
				subs[ key ].slice().forEach( function ( cb ) {
					try { cb(); } catch ( e ) {}
				} );
			}
		} );
	}

	function read() {
		if ( pending || ! count || ! window.wp || ! window.wp.apiFetch ) {
			arm();
			return;
		}
		pending = true;
		lastAt  = Date.now();
		window.wp.apiFetch( { path: '/signal-noise/v1/desktop/pulse' } ).then( function ( stamps ) {
			if ( ! stamps || 'object' !== typeof stamps ) {
				throw new Error( 'bad pulse' );
			}
			failures = 0;
			fire( stamps );
			last = count ? stamps : null;
		} ).catch( function () {
			failures = Math.min( failures + 1, 5 );
		} ).then( function () {
			pending = false;
			arm();
		} );
	}

	// Back in view or in focus: read now, so a change made elsewhere shows on return.
	function onReturn() {
		if ( document.hidden ) {
			window.clearTimeout( timer );
			timer = 0;
			return;
		}
		// Focus left (only visible now): stretch the wait, no read. Focus back,
		// or shown again: read now, unless a read just started.
		if ( wait() > PULSE_MS || Date.now() - lastAt < FLOOR_MS ) {
			arm();
			return;
		}
		read();
	}

	function start() {
		document.addEventListener( 'visibilitychange', onReturn );
		unwatch = window.sntPollCadence ? window.sntPollCadence.onFocusChange( onReturn ) : null;
		read();
	}

	function stop() {
		window.clearTimeout( timer );
		timer = 0;
		document.removeEventListener( 'visibilitychange', onReturn );
		if ( unwatch ) { unwatch(); unwatch = null; }
		last = null;
	}

	window.sntPulse = {
		/**
		 * @param {string}   key 'content' | 'deploy'
		 * @param {Function} cb  Called when that stamp moves.
		 * @return {Function} Unsubscribe.
		 */
		on: function ( key, cb ) {
			( subs[ key ] = subs[ key ] || [] ).push( cb );
			if ( 1 === ++count ) {
				start();
			}
			var done = false;
			return function () {
				if ( done ) { return; }
				done = true;
				subs[ key ] = ( subs[ key ] || [] ).filter( function ( f ) { return f !== cb; } );
				if ( 0 === --count ) {
					stop();
				}
			};
		},
	};
} )();
