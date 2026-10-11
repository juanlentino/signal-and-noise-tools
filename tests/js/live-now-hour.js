// Runs assets/live-now.js (admin mode) against a fake DOM holding SN Traffic's
// last-hour bars and their caption, answers its one read with argv[2] (JSON),
// and prints { hidden, note, bars }. Used by tests/desktop-mode-widget-views-delta.php.
'use strict';
const path = require( 'path' );
const answer = JSON.parse( process.argv[ 2 ] );
const box = { hidden: true };
const svg = {
	children: [], viewBox: { baseVal: { height: 18 } }, attrs: {},
	get firstChild() { return this.children[ 0 ] || null; },
	removeChild( c ) { this.children.splice( this.children.indexOf( c ), 1 ); },
	appendChild( c ) { this.children.push( c ); },
	getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
	setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
	closest( sel ) { return '[data-sn-live-hide-empty]' === sel ? box : null; },
};
const note = { textContent: '' };
global.document = {
	hidden: false, readyState: 'complete',
	querySelector: () => svg,
	querySelectorAll: ( sel ) => ( '[data-sn-live-hour]' === sel ? [ svg ] : '[data-sn-live-hour-note]' === sel ? [ note ] : [] ),
	createElementNS: () => ( { attrs: {}, setAttribute( k, v ) { this.attrs[ k ] = String( v ); } } ),
	addEventListener() {},
};
global.window = { setInterval() {}, wp: { apiFetch: () => Promise.resolve( answer ) } };
require( path.join( __dirname, '../../assets/live-now.js' ) );
setImmediate( () => process.stdout.write( JSON.stringify( { hidden: box.hidden, note: note.textContent, bars: svg.children.length } ) ) );
