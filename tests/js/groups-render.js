// Renders SN Audience or SN Reading against a fake DOM and prints its text
// rows as JSON. argv[2] = widget id, argv[3] = payload (JSON) or "FAIL".
// Used by tests/desktop-mode-analytics-widgets.php.
'use strict';
const path = require( 'path' );
function node( tag ) {
	return {
		tag, children: [], attrs: {}, _text: '', href: '',
		get firstChild() { return this.children[ 0 ] || null; },
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
		appendChild( c ) { this.children.push( c ); return c; },
		removeChild( c ) { this.children = this.children.filter( ( x ) => x !== c ); },
		set textContent( v ) { this._text = String( v ); this.children = []; },
		get textContent() { return this._text + this.children.map( ( c ) => c.textContent ).join( ' | ' ); },
	};
}
global.document = { createElement: node };
const fail = 'FAIL' === process.argv[ 3 ];
const payload = fail ? null : JSON.parse( process.argv[ 3 ] );
const paths = [];
global.window = {
	snDesktopData: { pages: { analytics: 'https://example.test/analytics' } },
	wp: { apiFetch: ( o ) => { paths.push( o.path ); return { then( f ) { if ( ! fail ) { f( payload ); } return { catch( g ) { if ( fail ) { g( new Error( 'x' ) ); } } }; } }; } },
};
require( path.join( __dirname, '../../assets/desktop-mode-widget-groups.js' ) );
const root = node( 'div' );
const teardown = window.desktopModeWidgets[ process.argv[ 2 ] ]( root, {} );
const lines = [];
( function walk( n ) { if ( ! n.children.length ) { lines.push( n.textContent ); } n.children.forEach( walk ); } )( root );
process.stdout.write( JSON.stringify( { lines, paths, teardown: typeof teardown, same: window.openStationWidgets === window.desktopModeWidgets } ) );
