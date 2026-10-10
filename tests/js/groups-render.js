// Renders SN Audience or SN Reading against a fake DOM and prints its text
// rows as JSON. argv[2] = widget id, argv[3] = payload (JSON) or "FAIL".
// Used by tests/desktop-mode-analytics-widgets.php.
'use strict';
const path = require( 'path' );
// Text is a child node, as in a browser: setting textContent replaces the
// children, and removing children removes the text.
function textNode( v ) { return { tag: '#text', children: [], attrs: {}, textContent: v }; }
function node( tag ) {
	return {
		tag, children: [], attrs: {}, href: '',
		get firstChild() { return this.children[ 0 ] || null; },
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
		appendChild( c ) { this.children.push( c ); return c; },
		removeChild( c ) { this.children = this.children.filter( ( x ) => x !== c ); },
		set textContent( v ) { this.children = '' === String( v ) ? [] : [ textNode( String( v ) ) ]; },
		get textContent() { return this.children.map( ( c ) => c.textContent ).join( ' | ' ); },
	};
}
global.document = { createElement: node, hidden: false, addEventListener() {}, removeEventListener() {} };
const fail = 'FAIL' === process.argv[ 3 ];
const payload = fail ? null : JSON.parse( process.argv[ 3 ] );
const paths = [];
global.window = {
	snDesktopData: { pages: { analytics: 'https://example.test/analytics' } },
	// A synchronous thenable, chainable as far as the widget chains it (then, catch, then).
	wp: { apiFetch: ( o ) => { paths.push( o.path ); return { then( f ) { if ( ! fail ) { f( payload ); } return { catch( g ) { if ( fail ) { g( new Error( 'x' ) ); } return { then( h ) { h(); } }; } }; } }; } },
	// The re-read timer is armed and never fires here: one read per run.
	setTimeout() { return 1; },
	clearTimeout() {},
};
require( path.join( __dirname, '../../assets/desktop-mode-widget-groups.js' ) );
const root = node( 'div' );
const teardown = window.desktopModeWidgets[ process.argv[ 2 ] ]( root, {} );
const lines = [];
const roles = [];
let link = null;
( function walk( n ) {
	if ( ! n.children.length ) { lines.push( n.textContent ); }
	if ( n.attrs.role ) { roles.push( n.attrs.role ); }
	if ( 'a' === n.tag ) { link = { name: n.attrs[ 'aria-label' ] || '', arrowHidden: n.children.some( ( c ) => '→' === c.textContent && 'true' === c.attrs[ 'aria-hidden' ] ) }; }
	n.children.forEach( walk );
} )( root );
process.stdout.write( JSON.stringify( { lines, roles, link, paths, teardown: typeof teardown, same: window.openStationWidgets === window.desktopModeWidgets } ) );
