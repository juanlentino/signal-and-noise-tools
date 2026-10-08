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

	// A part marked data-sn-live-hide-empty (the Traffic widget's hour bars
	// and Top now row) shows only when the answer has something for it.
	function showIf( el, has ) {
		var box = el.closest ? el.closest( '[data-sn-live-hide-empty]' ) : null;
		if ( box ) {
			box.hidden = ! has;
		}
	}

	function meta( text ) {
		document.querySelectorAll( '[data-sn-live-meta]:not([data-updated])' ).forEach( function ( m ) {
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
		document.querySelectorAll( '[data-sn-live-hour]' ).forEach( function ( svg ) {
			hour( svg, data );
		} );
		document.querySelectorAll( '[data-sn-live-pages]' ).forEach( function ( list ) {
			pages( list, data );
		} );
		document.querySelectorAll( '[data-sn-live-sources]' ).forEach( function ( list ) {
			rows( list, data.sources, false );
		} );
		document.querySelectorAll( '[data-sn-live-top]' ).forEach( function ( el ) {
			if ( Array.isArray( data.pages ) ) {
				el.textContent = data.pages.length ? String( data.pages[ 0 ].label ) + ' · ' + Number( data.pages[ 0 ].readers ).toLocaleString() : '—';
				showIf( el, data.pages.length > 0 );
			}
		} );
		// The live-surge verdict (admin): its words ride on the element.
		document.querySelectorAll( '[data-sn-live-surge]' ).forEach( function ( el ) {
			var s = data.surge;
			if ( ! s || typeof s !== 'object' ) {
				// Nothing read at all: a loud line must not outlive its reading.
				if ( typeof data.fetched !== 'number' ) {
					el.textContent = '';
					el.className = 'sn-live-admin__surge';
				}
				return;
			}
			var text = '';
			if ( s.state === 'learning' ) {
				text = el.getAttribute( 'data-learning' ).replace( '%1$s', String( s.days ) ).replace( '%2$s', String( s.need || 4 ) );
			} else if ( s.state === 'surge' ) {
				text = typeof s.ratio === 'number'
					? el.getAttribute( 'data-surge' ).replace( '%1$s', String( s.ratio ) ).replace( '%2$s', String( s.readers ) ).replace( '%3$s', String( s.usual ) )
					: el.getAttribute( 'data-surge-zero' ).replace( '%s', String( s.readers ) );
			} else {
				text = el.getAttribute( 'data-usual' );
			}
			el.textContent = text;
			el.className = 'sn-live-admin__surge' + ( s.state === 'surge' ? ' is-surge' : '' );
		} );
		// Admin meta carries its own strings; the public one reads the config.
		document.querySelectorAll( '[data-sn-live-meta][data-updated]' ).forEach( function ( m ) {
			m.textContent = typeof data.fetched === 'number'
				? m.getAttribute( 'data-updated' ).replace( '%s', new Date( data.fetched * 1000 ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ) )
				: m.getAttribute( 'data-unknown' );
		} );
		if ( cfg ) {
			// "Not measured" only when nothing was: views today can come from the
			// same-day last-good while the 5-minute reading has lapsed, and the
			// line must not deny a figure it sits under (review on #1961).
			if ( typeof data.fetched === 'number' ) {
				meta( String( cfg.updated ).replace( '%s', new Date( data.fetched * 1000 ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ) ) );
			} else {
				meta( typeof data.now === 'number' || typeof data.today === 'number' ? '' : String( cfg.unknown ) );
			}
		}
	}

	// The last hour: twelve bars in the text color, the current slot in red,
	// scaled to the hour's own peak; the note says the peak in words. Null
	// leaves the chart as it was.
	var SVGNS = 'http://www.w3.org/2000/svg';
	function hour( svg, data ) {
		if ( ! Array.isArray( data.hour ) || ! data.hour.length ) {
			return;
		}
		while ( svg.firstChild ) {
			svg.removeChild( svg.firstChild );
		}
		var slots = data.hour, n = slots.length, w = 240 / n, peak = 0, at = 0;
		var vb = svg.viewBox && svg.viewBox.baseVal && svg.viewBox.baseVal.height ? svg.viewBox.baseVal.height : 34;
		// The last bar is red only while it still is the current slot: an answer
		// read from a cache a few seconds old can end on the slot before
		// (review on #1966); then no bar claims to be now.
		var current = Date.now() / 1000 - Number( slots[ n - 1 ].t ) < 300;
		slots.forEach( function ( s, i ) {
			if ( Number( s.readers ) >= peak ) { peak = Number( s.readers ); at = i; }
		} );
		showIf( svg, peak > 0 );
		slots.forEach( function ( s, i ) {
			var h = peak > 0 ? Math.max( Number( s.readers ) > 0 ? 2 : 0, Math.round( ( vb - 2 ) * Number( s.readers ) / peak ) ) : 0;
			var r = document.createElementNS( SVGNS, 'rect' );
			r.setAttribute( 'x', String( i * w + 1 ) );
			r.setAttribute( 'y', String( vb - h ) );
			r.setAttribute( 'width', String( w - 3 ) );
			r.setAttribute( 'height', String( h ) );
			// Presentation fills, so a surface with no stylesheet for the bars (the
			// widget) still draws them; a stylesheet's rules win over these.
			r.setAttribute( 'fill', 'currentColor' );
			if ( i === n - 1 && current ) { r.setAttribute( 'class', 'is-now' ); r.setAttribute( 'fill', '#e5484d' ); }
			svg.appendChild( r );
		} );
		if ( ! cfg ) {
			// Admin: the label carries the peak in words, as the public note does.
			var base = svg.getAttribute( 'data-label' ) || svg.getAttribute( 'aria-label' ) || '';
			svg.setAttribute( 'data-label', base );
			if ( peak > 0 ) {
				var ago = Math.round( ( Number( slots[ n - 1 ].t ) - Number( slots[ at ].t ) ) / 60 );
				svg.setAttribute( 'aria-label', base + ': peak ' + peak + ( ago > 0 ? ', ' + ago + ' minutes ago' : ', in the current 5 minutes' ) );
			} else {
				svg.setAttribute( 'aria-label', base + ': no readers' );
			}
			return;
		}
		var note = String( cfg.hourNone );
		if ( peak > 0 ) {
			var mins = Math.round( ( Number( slots[ n - 1 ].t ) - Number( slots[ at ].t ) ) / 60 );
			note = String( cfg.hourPeak ).replace( '%1$s', peak.toLocaleString() ).replace( '%2$s', mins > 0 ? String( cfg.agoMin ).replace( '%s', String( mins ) ) : String( cfg.agoNow ) );
		}
		document.querySelectorAll( '[data-sn-live-hour-note]' ).forEach( function ( el ) { el.textContent = note; } );
	}

	// Being read now: a link per page with its reader count. Null (not read)
	// leaves the list as it was; an empty answer says nobody is on a page.
	// A ranked list (pages, or admin sources). Strings ride on the list's own
	// attributes (admin) or the public config; the public list says "reader(s)",
	// the admin one prints the bare count.
	function rows( list, items, linked ) {
		if ( ! Array.isArray( items ) ) {
			return;
		}
		var empty = list.getAttribute( 'data-empty' ) || ( cfg ? String( cfg.nobody ) : '—' );
		// Fixed per surface, never copied from whatever row is first: after a
		// list with rows went empty, the copied class was '' (review on #1967).
		var emptyClass = cfg ? 'sn-public-stats__live-empty' : 'sn-live-admin__empty';
		while ( list.firstChild ) {
			list.removeChild( list.firstChild );
		}
		if ( ! items.length ) {
			var none = document.createElement( 'li' );
			none.className = emptyClass;
			none.textContent = empty;
			list.appendChild( none );
			return;
		}
		items.forEach( function ( p ) {
			var li = document.createElement( 'li' ), name = document.createElement( linked && p.url ? 'a' : 'span' ), n = document.createElement( 'span' );
			if ( linked && p.url ) { name.href = String( p.url ); }
			name.textContent = String( p.label );
			n.className = cfg ? 'sn-public-stats__views' : 'sn-live-admin__n';
			n.textContent = Number( p.readers ).toLocaleString() + ( cfg ? ' ' + String( Number( p.readers ) === 1 ? cfg.reader : cfg.readers ) : '' );
			li.appendChild( name );
			li.appendChild( n );
			list.appendChild( li );
		} );
	}
	function pages( list, data ) { rows( list, data.pages, true ); }

	function load() {
		var b = Math.floor( Date.now() / BUCKET );
		if ( admin ) {
			if ( ! window.wp || ! window.wp.apiFetch ) {
				return Promise.reject( new Error( 'no apiFetch' ) );
			}
			// Unique per request, never the shared bucket: an edge that caches
			// /wp-json/ past its headers can then neither serve an admin answer
			// to anyone else nor freeze the admin on a cached 401 (review on #1961).
			return window.wp.apiFetch( { path: '/signal-noise/v1/live/admin?n=' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 ) } );
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
		if ( document.hidden || ! document.querySelector( '[data-sn-live],[data-sn-live-hour],[data-sn-live-pages],[data-sn-live-sources],[data-sn-live-top],[data-sn-live-surge]' ) ) {
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
		// A surface that paints its figures after load (the Traffic widget) asks
		// for a read now instead of waiting up to a whole interval.
		document.addEventListener( 'sn-live-refresh', tick );
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
