// Runs assets/live-now.js (admin mode) against a fake "Being read now" list
// carrying the scroll strings of inc/analytics-live-admin.php, answers its one
// read with argv[2] (JSON), and prints each row as { text, depth }, depth
// being the scroll line's text (null when the row has none). argv[3] 'public'
// runs it as the /stats strip (window.snLiveNow set); 'carrier' runs it as the
// /stats strip reading its config from the JSON data block (argv[4]) the way
// the page prints it since 2026-10-20. Used by tests/analytics-live-scroll.php
// and tests/public-stats-live-config.php.
'use strict';
const path = require( 'path' );
const answer = JSON.parse( process.argv[ 2 ] );
const pub = 'public' === process.argv[ 3 ];
function el( tag ) {
	return {
		tagName: tag.toUpperCase(), children: [], className: '', textContent: '', attrs: {},
		get firstChild() { return this.children[ 0 ] || null; },
		removeChild( c ) { this.children.splice( this.children.indexOf( c ), 1 ); },
		appendChild( c ) { this.children.push( c ); },
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
	};
}
const list = el( 'ol' );
Object.assign( list.attrs, {
	'data-empty': 'Nobody on a page right now.',
	'data-scroll-none': 'not scrolled yet',
	'data-scroll-quarter': '%s past a quarter',
	'data-scroll-half': '%s past halfway',
	'data-scroll-end': '%s at the end',
} );
global.document = {
	hidden: false, readyState: 'complete',
	querySelector: () => list,
	querySelectorAll: ( sel ) => ( '[data-sn-live-pages]' === sel ? [ list ] : [] ),
	createElement: el,
	addEventListener() {},
	getElementById: ( id ) => ( 'carrier' === process.argv[ 3 ] && 'sn-live-now-config' === id ? { textContent: process.argv[ 4 ] } : null ),
};
global.window = { setInterval() {}, wp: { apiFetch: () => Promise.resolve( answer ) } };
global.fetch = () => Promise.resolve( { ok: true, json: () => answer } );
if ( pub ) {
	window.snLiveNow = { url: 'https://x.test/wp-json/signal-noise/v1/live', interval: 60, readers: 'readers', reader: 'reader', nobody: 'Nobody', updated: '%s', unknown: '' };
}
require( path.join( __dirname, '../../assets/live-now.js' ) );
setTimeout( () => process.stdout.write( JSON.stringify( list.children.map( ( li ) => {
	const d = li.children.find( ( c ) => /sn-live-admin__depth/.test( c.className ) );
	return { text: li.children.map( ( c ) => c.textContent ).join( '|' ) || li.textContent, depth: d ? d.textContent : null };
} ) ) ), 20 );
