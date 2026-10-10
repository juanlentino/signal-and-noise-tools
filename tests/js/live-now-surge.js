// Runs assets/live-now.js (admin mode) against a fake DOM holding SN Traffic's
// surge line, answers its one read with argv[2] (JSON), and prints the line's
// state: { hidden, text }. Used by tests/desktop-mode-widget-views-delta.php.
'use strict';
const path = require( 'path' );
const answer = JSON.parse( process.argv[ 2 ] );
const box = { hidden: true };
const line = {
	textContent: '', className: '', tagName: 'DIV',
	attrs: { 'data-surge': 'Unusual: about %1$s× the usual for this time of day (%2$s readers, usually %3$s).', 'data-surge-zero': 'Unusual: %s readers at a time of day that is usually empty.', 'data-usual': '', 'data-learning': '' },
	getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
	closest( sel ) { return '[data-sn-live-hide-empty]' === sel ? box : null; },
};
global.document = {
	hidden: false, readyState: 'complete',
	querySelector: () => line,
	querySelectorAll: ( sel ) => ( '[data-sn-live-surge]' === sel ? [ line ] : [] ),
	addEventListener() {},
};
global.window = { setInterval() {}, wp: { apiFetch: () => Promise.resolve( answer ) } };
require( path.join( __dirname, '../../assets/live-now.js' ) );
setImmediate( () => process.stdout.write( JSON.stringify( { hidden: box.hidden, text: line.textContent } ) ) );
