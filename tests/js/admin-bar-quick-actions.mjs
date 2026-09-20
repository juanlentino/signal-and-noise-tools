/**
 * Signal & Noise Tools: the admin-bar quick actions, executed.
 *
 * WHY THIS RUNS THE BLOCK INSTEAD OF GREPPING IT.
 *
 * tests/admin-bar-quick-actions.php pins the inline block the attacher records
 * by substring: `os.confirm(` before the label swap, `os.showToast(`,
 * `sntAbilityRun(meta.ability`. Every one of those held while the block threw
 * `ReferenceError: os is not defined` on every click, on every surface: the
 * shipped `toast()` read an `os` declared inside the click listener, so no
 * toast ever painted and the request fired with no feedback. A substring
 * cannot see scope; `node --check` cannot see a free variable. Only running
 * the block against a click can tell "the seam is named" from "the seam is
 * reached".
 *
 * Only the DOM, fetch, the shared runner and wp.os are faked. The block is
 * the one `sn_admin_bar_print_script()` handed to wp_add_inline_script, byte
 * for byte, written to a file by the PHP suite.
 *
 * Usage: node tests/js/admin-bar-quick-actions.mjs <script-file> <classic|shell>
 * Driven by tests/admin-bar-quick-actions.php (so tests/run.sh sweeps it).
 * Emits one JSON line; exit 0 = every scenario passed, 1 = one failed.
 */

import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const [ , , file, surface ] = process.argv;
if ( ! file || ! [ 'classic', 'shell' ].includes( surface ) ) {
	console.log( JSON.stringify( { results: [ { name: 'usage', pass: false, detail: 'node admin-bar-quick-actions.mjs <script-file> <classic|shell>' } ] } ) );
	process.exit( 1 );
}

const SRC = readFileSync( file, 'utf8' );
const cfg = JSON.parse( SRC.match( /const cfg = (\{.*?\});/s )[ 1 ] );

/** The narrowest element the block touches: dataset, textContent, listeners. */
function element( tag ) {
	const el = {
		tag,
		dataset: {},
		style: {},
		attrs: {},
		textContent: '',
		listeners: {},
		removed: false,
		addEventListener( ev, fn ) { ( el.listeners[ ev ] ||= [] ).push( fn ); },
		setAttribute( k, v ) { el.attrs[ k ] = v; },
		remove() { el.removed = true; },
		click() { ( el.listeners.click || [] ).forEach( ( fn ) => fn( { preventDefault() {} } ) ); },
	};
	return el;
}

const links = {};
for ( const id of Object.keys( cfg.nodes ) ) {
	links[ id ] = element( 'a' );
	links[ id ].textContent = '⌘ ' + id;
}

const painted = [];   // snToast divs appended to body (off the shell)
const toasts = [];    // wp.os.showToast calls (on the shell)
const confirms = [];  // wp.os.confirm calls, each with its resolver
const runs = [];      // window.sntAbilityRun calls
const fetches = [];   // fetch calls (URLSearchParams bodies)
const unhandled = [];
process.on( 'unhandledRejection', ( err ) => unhandled.push( String( ( err && err.message ) || err ) ) );

let windowConfirmAnswer = true;

const ctx = {
	console,
	Promise,
	URLSearchParams,
	requestAnimationFrame: ( fn ) => fn(),
	setTimeout: () => 0,
	clearTimeout: () => undefined,
	document: {
		body: { appendChild: ( el ) => painted.push( el ) },
		createElement: ( tag ) => element( tag ),
		querySelector: ( sel ) => {
			const m = sel.match( /^#wp-admin-bar-([a-z0-9-]+) > a\.ab-item$/ );
			return ( m && links[ m[ 1 ] ] ) || null;
		},
	},
	fetch: ( url, init ) => {
		fetches.push( { url, body: String( init && init.body ) } );
		return Promise.resolve( { ok: true, json: () => Promise.resolve( { success: true, data: { message: 'All caches purged.' } } ) } );
	},
	confirm: () => windowConfirmAnswer,
	sntAbilityRun: ( ability, input ) => {
		runs.push( { ability, input } );
		return Promise.resolve( { ok: true, message: 'All caches purged.' } );
	},
};
ctx.window = ctx;
if ( 'shell' === surface ) {
	ctx.wp = {
		os: {
			confirm: ( opts ) => new Promise( ( resolve ) => confirms.push( { opts, resolve } ) ),
			showToast: ( opts ) => toasts.push( opts ),
		},
	};
}
vm.createContext( ctx );
vm.runInContext( SRC, ctx, { filename: 'admin-bar-inline.js' } );

// The click's chain is microtasks only (the gate, the transport, the toast,
// the finally); a macrotask turn drains it and lets an unhandled rejection
// surface on the process before the next read.
const settle = () => new Promise( ( r ) => setImmediate( r ) );

const results = [];
function scenario( name, pass, detail ) {
	results.push( { name: surface + ': ' + name, pass: !! pass, detail } );
}

// 1. A plain click paints exactly one toast with the response's message and
//    throws nothing. Purge All Caches has no confirm on either surface.
links[ 'sn-quick-purge-caches' ].click();
await settle();
const landed = 'shell' === surface ? toasts : painted;
const message = 'shell' === surface ? ( toasts[ 0 ] && toasts[ 0 ].message ) : ( painted[ 0 ] && painted[ 0 ].textContent );
scenario(
	'a click paints the toast',
	1 === landed.length && 'All caches purged.' === message && 0 === unhandled.length,
	'toasts=' + landed.length + ' message=' + JSON.stringify( message || null ) + ' unhandled=' + JSON.stringify( unhandled )
);
scenario(
	'the request fired once and the label restored',
	1 === ( 'shell' === surface ? runs : fetches ).length && links[ 'sn-quick-purge-caches' ].textContent === '⌘ sn-quick-purge-caches' && ! links[ 'sn-quick-purge-caches' ].dataset.snBusy,
	'requests=' + ( 'shell' === surface ? runs : fetches ).length + ' label=' + JSON.stringify( links[ 'sn-quick-purge-caches' ].textContent )
);

// 2. The destructive item. On the shell the gate is asynchronous and core's
//    bar paints above the confirm scrim, so a second click while the dialog
//    is open must not mount a second dialog: the busy flag is taken BEFORE
//    the gate. Declining must give the flag back, so the row is re-clickable.
const destructive = links[ 'sn-quick-clear-overrides' ];
if ( 'shell' === surface ) {
	destructive.click();
	destructive.click();
	await settle();
	scenario(
		'two clicks on the destructive item open one confirm',
		1 === confirms.length && 0 === runs.filter( ( r ) => 'clear-template-overrides' === r.ability ).length,
		'confirms=' + confirms.length + ' runs=' + JSON.stringify( runs.map( ( r ) => r.ability ) )
	);
	scenario(
		'the confirm carries the title, the prose and the danger flag',
		!! confirms[ 0 ] && 'string' === typeof confirms[ 0 ].opts.title && 'string' === typeof confirms[ 0 ].opts.message && confirms[ 0 ].opts.message.length > 40 && true === confirms[ 0 ].opts.danger,
		JSON.stringify( confirms[ 0 ] ? confirms[ 0 ].opts : null )
	);
	confirms.forEach( ( c ) => c.resolve( false ) );
	await settle();
	destructive.click();
	await settle();
	scenario(
		'declining leaves the row untouched and re-clickable',
		2 === confirms.length && 0 === runs.filter( ( r ) => 'clear-template-overrides' === r.ability ).length && destructive.textContent === '⌘ sn-quick-clear-overrides',
		'confirms=' + confirms.length + ' label=' + JSON.stringify( destructive.textContent ) + ' busy=' + JSON.stringify( destructive.dataset.snBusy || null )
	);
	confirms[ confirms.length - 1 ].resolve( true );
	await settle();
	scenario(
		'confirming dispatches the ability once and toasts',
		1 === runs.filter( ( r ) => 'clear-template-overrides' === r.ability ).length && 2 === toasts.length && 0 === unhandled.length,
		'runs=' + JSON.stringify( runs.map( ( r ) => r.ability ) ) + ' toasts=' + toasts.length + ' unhandled=' + JSON.stringify( unhandled )
	);
} else {
	windowConfirmAnswer = false;
	destructive.click();
	await settle();
	scenario(
		'declining window.confirm fires nothing and leaves the row re-clickable',
		1 === fetches.length && ! destructive.dataset.snBusy && destructive.textContent === '⌘ sn-quick-clear-overrides',
		'fetches=' + fetches.length + ' busy=' + JSON.stringify( destructive.dataset.snBusy || null )
	);
	windowConfirmAnswer = true;
	destructive.click();
	await settle();
	scenario(
		'confirming posts admin-ajax with the page-load nonce and toasts',
		2 === fetches.length && /_ajax_nonce=nonce-/.test( fetches[ 1 ].body ) && 2 === painted.length && 0 === unhandled.length,
		'fetches=' + fetches.length + ' body=' + JSON.stringify( fetches[ 1 ] ? fetches[ 1 ].body : null ) + ' unhandled=' + JSON.stringify( unhandled )
	);
}

console.log( JSON.stringify( { results } ) );
process.exit( results.every( ( r ) => r.pass ) ? 0 : 1 );
