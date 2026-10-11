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
global.document = { hidden: false, createElement: node, createElementNS: ( ns, t ) => node( t ), addEventListener() {}, removeEventListener() {} };
const payload = JSON.parse( process.argv[ 2 ] );
global.window = { setTimeout: () => 0, clearTimeout() {}, snDesktopData: { pages: { analytics: 'https://example.test/analytics' } }, wp: { apiFetch: () => ( { then( f ) { f( payload ); return { catch() {} }; } } ) } };
require( path.join( __dirname, '../../assets/desktop-mode-widget-views.js' ) );
const root = node( 'div' );
window.desktopModeWidgets[ 'sn-site-views' ]( root, {} );
// The card reads through a promise chain; walk it once that has settled.
setImmediate( () => {
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
const srText = [];
( function walk( n ) { if ( n.className === 'screen-reader-text' ) { srText.push( n.textContent ); } n.children.forEach( walk ); } )( root );
const link = a ? { text: a._text, name: a.attrs[ 'aria-label' ] || a.textContent, arrowHidden: a.children.length > 0 && a.children.every( ( c ) => c.attrs[ 'aria-hidden' ] === 'true' ) } : null;
// The live surge line (filled by live-now.js) and the Read at foot, by their attributes.
let surge = null, readAt = null, hourNote = null;
( function walk( n ) {
	if ( 'data-sn-live-surge' in n.attrs ) { surge = { hidden: !! ( n.parentNode && n.parentNode.hidden ), hideEmpty: !! ( n.parentNode && 'data-sn-live-hide-empty' in n.parentNode.attrs ), text: n.textContent, surgeText: n.attrs[ 'data-surge' ] || '', zeroText: n.attrs[ 'data-surge-zero' ] || '', allAttrs: [ 'data-surge', 'data-surge-zero', 'data-usual', 'data-learning' ].every( ( k ) => k in n.attrs ) }; }
	if ( 'data-sn-read-at' in n.attrs ) { readAt = { text: n.textContent, live: n.attrs[ 'aria-live' ] || '' }; }
	if ( 'data-sn-live-hour-note' in n.attrs ) { const box = n.parentNode, bars = box ? box.children.find( ( c ) => 'data-sn-live-hour' in c.attrs ) : null; hourNote = { hideEmpty: !! ( box && 'data-sn-live-hide-empty' in box.attrs ), withBars: !! bars, baseline: !! ( bars && /border-bottom:1px solid/.test( bars.attrs.style || '' ) ), text: n.textContent }; }
	n.children.forEach( walk );
} )( root );
process.stdout.write( JSON.stringify( { rows: out, helpers: Object.keys( window.snSiteViewsDelta || {} ), links: links.length, link, roles, srText, bodyRole: roles[ 0 ] || '', surge, readAt, hourNote } ) );
} );
