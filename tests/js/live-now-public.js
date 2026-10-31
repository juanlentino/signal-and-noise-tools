// Runs assets/live-now.js (public /stats mode) against a fake DOM shaped like
// sn_public_stats_live_html(), answers its first read with argv[2] (JSON) or
// fails it when argv[2] is 'fail', and prints the strip before and after.
// Used by tests/public-stats-live-pending.php.
'use strict';
const path = require( 'path' );
const mode = process.argv[ 2 ];
function el( attrs, text ) {
	return {
		tagName: 'SPAN', attrs: { ...attrs }, textContent: text, className: '',
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
		removeAttribute( k ) { delete this.attrs[ k ]; },
		hasAttribute( k ) { return k in this.attrs; },
		closest() { return null; },
	};
}
// The server paints "not read" as real text in every pending part.
const section = el( { 'data-sn-live-busy': '' }, '' );
const now = el( { 'data-sn-live': 'now', 'data-sn-live-class': 'human', 'data-sn-live-pending': '' }, 'not read' );
const today = el( { 'data-sn-live': 'today', 'data-sn-live-class': 'human', 'data-sn-live-pending': '' }, 'not read' );
const li = el( { 'data-sn-live-pending': '' }, 'not read' );
const list = el( { 'data-sn-live-pages': '' }, '' );
const meta = el( { 'data-sn-live-meta': '' }, 'The live figures need JavaScript.' );
const all = [ section, now, today, li, list, meta ];
const has = ( e, a ) => a in e.attrs;
const pick = ( sel ) => {
	if ( '[data-sn-live]' === sel ) return [ now, today ];
	if ( '[data-sn-live-pages]' === sel ) return [ list ];
	if ( '[data-sn-live-meta]:not([data-updated])' === sel ) return [ meta ];
	const m = /^\[([\w-]+)\]$/.exec( sel );
	return m ? all.filter( ( e ) => has( e, m[ 1 ] ) ) : [];
};
const cfg = { url: 'https://x.test/wp-json/signal-noise/v1/live', interval: 60, updated: 'Updated %s', unknown: 'Not measured right now.', nobody: 'Nobody on a page right now.', readers: 'readers', reader: 'reader', hourPeak: '%1$s' };
global.document = {
	hidden: false, readyState: 'complete',
	getElementById: ( id ) => ( 'sn-live-now-config' === id ? { textContent: JSON.stringify( cfg ) } : null ),
	querySelector: ( sel ) => sel.split( ',' ).map( pick ).flat()[ 0 ] || null,
	querySelectorAll: pick,
	addEventListener() {},
};
global.window = { setInterval() {} };
// 'null': a 200 whose body the strip cannot use (apply() throws on it).
global.fetch = () => ( 'fail' === mode ? Promise.reject( new Error( 'offline' ) ) : Promise.resolve( { ok: true, json: () => Promise.resolve( JSON.parse( mode ) ) } ) );
const snap = () => ( { busy: section.getAttribute( 'aria-busy' ), now: now.textContent, today: today.textContent, li: li.textContent, meta: meta.textContent, pending: all.filter( ( e ) => has( e, 'data-sn-live-pending' ) ).length } );
require( path.join( __dirname, '../../assets/live-now.js' ) );
const before = snap();
setTimeout( () => process.stdout.write( JSON.stringify( { before, after: snap() } ) ), 20 );
