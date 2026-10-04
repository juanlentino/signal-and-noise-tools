// Renders the SN Traffic card (sn-site-views) against a fake DOM and prints its rows as
// JSON: [{ text, color }]. Payload is argv[2] (JSON). Used by
// tests/desktop-mode-widget-views-delta.php.
'use strict';
const path = require( 'path' );
function node( tag ) {
	return {
		tag, get childNodes() { return this.children; }, children: [], attrs: {}, _text: '', parentNode: null,
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
		appendChild( c ) { c.parentNode = this; this.children.push( c ); return c; },
		removeChild( c ) { this.children = this.children.filter( ( x ) => x !== c ); },
		set textContent( v ) { this._text = String( v ); this.children = []; },
		get textContent() { return this._text + this.children.map( ( c ) => c.textContent ).join( '' ); },
	};
}
global.document = { createElement: node, createElementNS: ( ns, t ) => node( t ) };
const payload = JSON.parse( process.argv[ 2 ] );
global.window = { snDesktopData: { pages: { analytics: 'https://example.test/analytics' } }, wp: { apiFetch: () => ( { then( f ) { f( payload ); return { catch() {} }; } } ) } };
require( path.join( __dirname, '../../assets/desktop-mode-widget-views.js' ) );
const root = node( 'div' );
window.desktopModeWidgets[ 'sn-site-views' ]( root, {} );
const out = [];
( function walk( n ) {
	if ( n.tag === 'span' || ( n.tag === 'div' && ! n.children.length ) ) {
		const m = /(?:^|;)color:([^;]+);/.exec( n.attrs.style || '' );
		out.push( { text: n.textContent, color: m ? m[ 1 ] : '' } );
	}
	n.children.forEach( walk );
} )( root );
// The links, the ARIA roles in document order, and the body's own role.
const links = [], roles = [];
( function walk( n ) {
	if ( n.tag === 'a' ) { links.push( n ); }
	if ( n.attrs.role ) { roles.push( n.attrs.role ); }
	n.children.forEach( walk );
} )( root );
const a = links[ 0 ];
const link = a ? { text: a._text, name: a.attrs[ 'aria-label' ] || a.textContent, arrowHidden: a.children.length > 0 && a.children.every( ( c ) => c.attrs[ 'aria-hidden' ] === 'true' ) } : null;
process.stdout.write( JSON.stringify( { rows: out, helpers: Object.keys( window.snSiteViewsDelta || {} ), links: links.length, link, roles, bodyRole: roles[ 0 ] || '' } ) );
