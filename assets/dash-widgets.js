/**
 * S&N fallback dashboard boxes — hydrator (v13.30.0).
 *
 * The four boxes on index.php render server-side as labelled em dashes plus
 * their deep links, because index.php renders on every admin login and the
 * render must stay free. This fills in the numbers afterwards, one call per
 * distinct ability, through the readonly abilities named in the markup.
 *
 * Same discipline as assets/uptime-status.js since v8.2.0: every value lands
 * via textContent, never innerHTML, and a failed call leaves the em dash in
 * place rather than printing a 0 — an unhydrated box must never be readable
 * as a measured zero.
 */
( function () {
	'use strict';

	/**
	 * Walk a dotted path. Supports numeric indexes (families.0.family) and a
	 * trailing .length on arrays (pending.length).
	 */
	function pick( root, path ) {
		var parts = String( path ).split( '.' );
		var cur   = root;
		for ( var i = 0; i < parts.length; i++ ) {
			if ( cur === null || cur === undefined ) {
				return undefined;
			}
			if ( 'length' === parts[ i ] && Array.isArray( cur ) ) {
				return cur.length;
			}
			cur = cur[ parts[ i ] ];
		}
		return cur;
	}

	// '' and null stay as the em dash. 0 does NOT — a real measured zero is a
	// reading, and blanking it would be the inverse of the placeholder rule.
	function format( value ) {
		if ( value === null || value === undefined || '' === value ) {
			return null;
		}
		if ( 'number' === typeof value ) {
			return value.toLocaleString();
		}
		if ( 'boolean' === typeof value ) {
			return value ? 'yes' : 'no';
		}
		return String( value );
	}

	function fill( section, payload ) {
		var rows = section.querySelectorAll( '.sn-dwx__row' );
		Array.prototype.forEach.call( rows, function ( row ) {
			var out = format( pick( payload, row.getAttribute( 'data-sn-dwx-path' ) ) );
			if ( null === out ) {
				return;
			}
			var slot = row.querySelector( '.sn-dwx__n' );
			if ( slot ) {
				slot.textContent = out;
			}
		} );
	}

	function fail( section ) {
		section.classList.add( 'sn-dwx__sec--failed' );
		section.setAttribute( 'title', 'Could not read this ability. The linked screen still works.' );
	}

	function boot() {
		if ( 'function' !== typeof window.sntAbilityRun ) {
			return;
		}
		var sections = document.querySelectorAll( '.sn-dwx__sec[data-sn-dwx-ability]' );
		if ( ! sections.length ) {
			return;
		}

		// One call per distinct ability, not per section: two boxes may name
		// the same ability and the home screen should not pay for it twice.
		var byAbility = {};
		Array.prototype.forEach.call( sections, function ( section ) {
			var name = section.getAttribute( 'data-sn-dwx-ability' );
			( byAbility[ name ] = byAbility[ name ] || [] ).push( section );
		} );

		Object.keys( byAbility ).forEach( function ( name ) {
			window.sntAbilityRun( name, {} ).then( function ( result ) {
				byAbility[ name ].forEach( function ( section ) {
					fill( section, result );
				} );
			} ).catch( function () {
				byAbility[ name ].forEach( fail );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
