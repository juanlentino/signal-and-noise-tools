// Runs the classic Provenance poller (assets/provenance-admin.js) once
// against a fake DOM and prints the Commits rows it paints as JSON:
// [{ text, cls }]. argv[2] is {attrs, payload}: the tbody's data-* attributes
// and the /status answer. Used by tests/os-leaf-tools-provenance.php.
'use strict';
const path = require( 'path' );
const input = JSON.parse( process.argv[ 2 ] );
function node( tag ) {
	return {
		tag, children: [], attrs: {}, className: '', _text: '', isConnected: true,
		get firstChild() { return this.children[ 0 ] || null; },
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		hasAttribute( k ) { return k in this.attrs; },
		appendChild( c ) { this.children.push( c ); return c; },
		removeChild( c ) { this.children = this.children.filter( ( x ) => x !== c ); },
		set textContent( v ) { this._text = String( v ); this.children = []; },
		get textContent() { return this._text + this.children.map( ( c ) => c.textContent ).join( '' ); },
	};
}
const live = node( 'tbody' );
Object.keys( input.attrs ).forEach( ( k ) => live.setAttribute( k, input.attrs[ k ] ) );
global.document = { createElement: node, querySelector: () => live, addEventListener() {} };
global.window = {};
global.setInterval = () => 0; // one poll, the one init() runs; no 30 s timer.
global.fetch = () => Promise.resolve( { ok: true, json: () => input.payload } );
require( path.join( __dirname, '../../assets/provenance-admin.js' ) );
setImmediate( () => {
	process.stdout.write( JSON.stringify( live.children.map( ( tr ) => ( { text: tr.textContent, cls: ( tr.children[ 0 ] || {} ).className || '' } ) ) ) );
} );
