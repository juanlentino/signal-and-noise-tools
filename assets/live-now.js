/**
 * Signal & Noise Tools: keeps every live figure current.
 *
 * Finds [data-sn-live="now|today"] on each tick (rescanned, so a repainted
 * view or a widget's freshly built DOM is found) and fills it from the live
 * route (inc/analytics-live.php). Public pages pass window.snLiveNow and
 * read the cached public route; admin pages carry no config and read the
 * gated route through wp.apiFetch, which supplies the REST nonce.
 *
 * A figure the route cannot answer stays "—", never 0; a figure for a class
 * the answer does not carry (a public answer, a suspect view) is left as the
 * server painted it. Ticks pause while the tab is hidden. No animation.
 */
( function () {
	'use strict';

	var cfg     = window.snLiveNow || null;
	var admin   = ! cfg;
	var seconds = admin ? 30 : Math.max( 30, Number( cfg.interval ) || 60 );
	var BUCKET  = 30000; // the edge keeps /wp-json/ long: the query changes every 30 s

	function format( n ) {
		return typeof n === 'number' ? n.toLocaleString() : '—';
	}

	function valueFor( data, key, cls ) {
		if ( key === 'today' ) {
			return data.today;
		}
		if ( data.classes && Object.prototype.hasOwnProperty.call( data.classes, cls ) ) {
			return data.classes[ cls ];
		}
		return cls === 'human' ? data.now : undefined;
	}

	function write( el, text ) {
		if ( el.tagName === 'OS-STAT' ) {
			el.setAttribute( 'value', text );
		} else {
			el.textContent = text;
		}
	}

	function meta( text ) {
		document.querySelectorAll( '[data-sn-live-meta]' ).forEach( function ( m ) {
			m.textContent = text;
		} );
	}

	function apply( data ) {
		document.querySelectorAll( '[data-sn-live]' ).forEach( function ( el ) {
			var v = valueFor( data, el.getAttribute( 'data-sn-live' ), el.getAttribute( 'data-sn-live-class' ) || 'human' );
			if ( v !== undefined ) {
				write( el, format( v ) );
			}
		} );
		if ( cfg ) {
			meta( typeof data.fetched === 'number'
				? String( cfg.updated ).replace( '%s', new Date( data.fetched * 1000 ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ) )
				: String( cfg.unknown ) );
		}
	}

	function load() {
		var b = Math.floor( Date.now() / BUCKET );
		if ( admin ) {
			if ( ! window.wp || ! window.wp.apiFetch ) {
				return Promise.reject( new Error( 'no apiFetch' ) );
			}
			return window.wp.apiFetch( { path: '/signal-noise/v1/live/admin?b=' + b } );
		}
		var url = String( cfg.url );
		return fetch( url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'b=' + b, { credentials: 'omit' } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
				return r.json();
			} );
	}

	function tick() {
		if ( document.hidden || ! document.querySelector( '[data-sn-live]' ) ) {
			return;
		}
		load().then( apply, function () {
			// The figures keep what they showed; the strip says it could not read.
			if ( cfg ) {
				meta( String( cfg.unknown ) );
			}
		} );
	}

	function start() {
		tick();
		window.setInterval( tick, seconds * 1000 );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				tick();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
