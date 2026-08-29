/**
 * S&N fallback dashboard boxes — hydrator (v13.30.1).
 *
 * The boxes render server-side as signal cells with em-dash placeholders,
 * because index.php renders on every admin login and that render must stay
 * free. This fills the numbers and their comparison lines afterwards, one call
 * per DISTINCT ability.
 *
 * Same discipline as assets/uptime-status.js since v8.2.0: every value lands via
 * textContent, never innerHTML, and a failed call leaves the em dash in place
 * rather than printing a 0 — an unhydrated cell must never be readable as a
 * measured zero.
 */
( function () {
	'use strict';

	/** Walk a dotted path. Supports numeric indexes and a trailing .length. */
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

	// '' and null stay as the em dash. 0 does NOT — a measured zero is a reading,
	// and blanking it would invert the placeholder rule.
	function format( value ) {
		if ( value === null || value === undefined || '' === value ) {
			return null;
		}
		if ( 'number' === typeof value ) {
			return Number.isInteger( value ) ? value.toLocaleString() : value.toFixed( 2 );
		}
		if ( 'boolean' === typeof value ) {
			return value ? 'yes' : 'no';
		}
		return String( value );
	}

	/**
	 * Build the comparison line. `percent_of` renders the value as a share of
	 * another field, which is the only way a raw count like "3,168 AI-training"
	 * says anything on its own.
	 */
	function compareText( payload, spec ) {
		if ( ! spec || ! spec.template ) {
			return null;
		}
		if ( spec.template.indexOf( '%s' ) === -1 ) {
			return spec.template;
		}
		var raw;
		if ( spec.percent_of ) {
			var part  = pick( payload, spec.path );
			var whole = pick( payload, spec.percent_of );
			if ( 'number' !== typeof part || 'number' !== typeof whole || ! whole ) {
				return null;
			}
			raw = ( part / whole * 100 ).toFixed( part / whole < 0.1 ? 1 : 0 ) + '%';
		} else {
			raw = format( pick( payload, spec.path ) );
		}
		if ( null === raw ) {
			return null;
		}
		return spec.template.replace( '%s', raw );
	}


	function shortTx( tx ) {
		var t = String( tx );
		return t.length > 12 ? t.slice( 0, 6 ) + '\u2026' + t.slice( -4 ) : t;
	}

	function row( label, value, sub ) {
		var line = document.createElement( 'div' );
		line.className = 'sn-dwx__li';
		var k = document.createElement( 'span' );
		k.className = 'sn-dwx__li-k';
		k.textContent = label;
		k.title = label;
		var v = document.createElement( 'span' );
		v.className = 'sn-dwx__li-v';
		v.textContent = value;
		line.appendChild( k );
		if ( sub ) {
			var sb = document.createElement( 'span' );
			sb.className = 'sn-dwx__li-s';
			sb.textContent = sub;
			line.appendChild( sb );
		}
		line.appendChild( v );
		return line;
	}

	/**
	 * A pending anchor's status. `confirmations: null` means NOT RECORDED and
	 * must never render as 0/6 — the desktop widget's rule, carried over,
	 * because 0/6 is a measurement and null is the absence of one.
	 */
	function confirmationsOf( item ) {
		if ( null === item.confirmations || undefined === item.confirmations ) {
			return item.bitcoin_txid ? shortTx( item.bitcoin_txid ) : 'awaiting tx';
		}
		return item.confirmations + '/6';
	}

	function renderList( host, payload, spec ) {
		var body = host.querySelector( '.sn-dwx__rows' );
		if ( ! body ) {
			return;
		}
		var data = pick( payload, spec.path );
		var item = spec.item || {};
		body.textContent = '';

		// Keyed object (the RSS windows are keyed 1/7/30, not an array).
		if ( spec.keys ) {
			Object.keys( spec.keys ).forEach( function ( key ) {
				var bucket = ( data && data[ key ] ) || null;
				if ( ! bucket ) {
					return;
				}
				var sub = item.sub && item.sub_template
					? item.sub_template.replace( '%s', format( bucket[ item.sub ] ) || '0' )
					: '';
				body.appendChild( row( spec.keys[ key ], format( bucket[ item.value ] ) || '0', sub ) );
			} );
			return;
		}

		if ( ! Array.isArray( data ) || ! data.length ) {
			if ( spec.empty ) {
				var none = document.createElement( 'p' );
				none.className = 'sn-dwx__muted';
				none.textContent = spec.empty;
				body.appendChild( none );
			}
			return;
		}

		data.slice( 0, spec.limit || 5 ).forEach( function ( entry ) {
			var label = item.label ? String( entry[ item.label ] || '' ) : '';
			var value;
			if ( 'confirmations' === item.format ) {
				value = confirmationsOf( entry );
				if ( entry.version ) {
					label += ' v' + entry.version;
				}
			} else {
				value = format( entry[ item.value ] );
				if ( null === value ) {
					value = '\u2014';
				}
			}
			var sub = item.sub && item.sub_template && entry[ item.sub ]
				? item.sub_template.replace( '%s', String( entry[ item.sub ] ) )
				: '';
			body.appendChild( row( label || '\u2014', value, sub ) );
		} );
	}

	/**
	 * Wire the action buttons. These run WRITE abilities, so the server-side
	 * permission_callback is the real gate; the button only reports back.
	 */
	function wireActions() {
		var buttons = document.querySelectorAll( '.sn-dwx__btn[data-sn-dwx-action]' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( 'function' !== typeof window.sntAbilityRun ) {
					return;
				}
				var out  = btn.parentNode.querySelector( '.sn-dwx__result' );
				var idle = btn.textContent;
				btn.disabled = true;
				btn.textContent = btn.getAttribute( 'data-sn-dwx-busy' ) || 'Working\u2026';
				if ( out ) {
					out.textContent = '';
				}
				window.sntAbilityRun( btn.getAttribute( 'data-sn-dwx-action' ), {} ).then( function ( res ) {
					if ( out ) {
						// Report what came back rather than a blanket "Done":
						// an action that reports success it did not verify is
						// the shape this codebase keeps paying for.
						if ( res && undefined !== res.upgraded ) {
							out.textContent = res.upgraded + ' upgraded, ' + res.still_pending + ' still pending.';
						} else if ( res && undefined !== res.purged ) {
							out.textContent = res.purged + ' cache(s) purged.';
						} else {
							out.textContent = 'Done.';
						}
					}
				} ).catch( function () {
					if ( out ) {
						out.textContent = 'Failed. Try the linked screen.';
					}
				} ).then( function () {
					btn.disabled = false;
					btn.textContent = idle;
				} );
			} );
		} );
	}

	function fill( cell, payload ) {
		var out = format( pick( payload, cell.getAttribute( 'data-sn-dwx-path' ) ) );
		if ( null !== out ) {
			var slot = cell.querySelector( '.sn-dw__n' );
			if ( slot ) {
				slot.textContent = out;
			}
		}
		var raw = cell.getAttribute( 'data-sn-dwx-compare' );
		if ( ! raw ) {
			return;
		}
		var spec;
		try {
			spec = JSON.parse( raw );
		} catch ( e ) {
			return;
		}
		var sub = compareText( payload, spec );
		var box = cell.querySelector( '.sn-dw__c' );
		if ( null !== sub && box ) {
			box.textContent = sub;
		}
	}

	function boot() {
		if ( 'function' !== typeof window.sntAbilityRun ) {
			return;
		}
		wireActions();

		// Cells and lists share the ability grouping, so a box whose grid and
		// list read the same ability costs ONE call, not two.
		var nodes = document.querySelectorAll( '[data-sn-dwx-ability]' );
		if ( ! nodes.length ) {
			return;
		}

		var byAbility = {};
		Array.prototype.forEach.call( nodes, function ( node ) {
			var name = node.getAttribute( 'data-sn-dwx-ability' );
			( byAbility[ name ] = byAbility[ name ] || [] ).push( node );
		} );

		Object.keys( byAbility ).forEach( function ( name ) {
			window.sntAbilityRun( name, {} ).then( function ( result ) {
				byAbility[ name ].forEach( function ( node ) {
					var listSpec = node.getAttribute( 'data-sn-dwx-list' );
					if ( listSpec ) {
						try {
							renderList( node, result, JSON.parse( listSpec ) );
						} catch ( e ) {}
						return;
					}
					fill( node, result );
				} );
			} ).catch( function () {
				byAbility[ name ].forEach( function ( node ) {
					node.classList.add( 'sn-dwx--failed' );
					node.setAttribute( 'title', 'Could not read this ability. The linked screen still works.' );
				} );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
