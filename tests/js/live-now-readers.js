// Runs assets/live-now.js (admin mode) against a fake "Readers now" list
// carrying the strings of inc/analytics-live-admin.php, answers its one read
// with argv[2] (JSON), and prints { readers, sources }, each row { tag, href,
// text }: the sources list shows apply() went on past the readers list. Used
// by tests/analytics-live-readers.php.
'use strict';
const path = require( 'path' );
const answer = JSON.parse( process.argv[ 2 ] );
function el( tag ) {
	return {
		tagName: tag.toUpperCase(), children: [], className: '', textContent: '', attrs: {}, href: '',
		get firstChild() { return this.children[ 0 ] || null; },
		removeChild( c ) { this.children.splice( this.children.indexOf( c ), 1 ); },
		appendChild( c ) { this.children.push( c ); },
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
	};
}
const list = el( 'ol' );
Object.assign( list.attrs, { 'data-empty': 'Nobody reading right now.', 'data-visit': '%1$s min, %2$s pages', 'data-visit-one': '%s min, 1 page', 'data-from': 'from %s' } );
const sources = el( 'ol' );
sources.attrs[ 'data-empty' ] = 'No arrivals.';
global.document = {
	hidden: false, readyState: 'complete',
	querySelector: () => list,
	querySelectorAll: ( sel ) => ( '[data-sn-live-readers]' === sel ? [ list ] : ( '[data-sn-live-sources]' === sel ? [ sources ] : [] ) ),
	createElement: el,
	addEventListener() {},
	getElementById: () => null,
};
global.window = { setInterval() {}, wp: { apiFetch: () => Promise.resolve( answer ) } };
global.fetch = () => Promise.resolve( { ok: true, json: () => answer } );
require( path.join( __dirname, '../../assets/live-now.js' ) );
const rows = ( l ) => l.children.map( ( li ) => ( {
	tag: li.children[ 0 ] ? li.children[ 0 ].tagName : li.tagName,
	href: li.children[ 0 ] ? li.children[ 0 ].href : '',
	text: li.children.map( ( c ) => c.textContent ).join( '|' ) || li.textContent,
} ) );
setTimeout( () => process.stdout.write( JSON.stringify( { readers: rows( list ), sources: rows( sources ) } ) ), 20 );
