/**
 * Signal & Noise Tools — boot-order harness for assets/desktop-mode.js.
 *
 * WHY THIS EXECUTES THE FILE INSTEAD OF GREPPING IT.
 *
 * tests/desktop-mode-integration.php already asserted the self-alias line
 * `window.wp.desktop = window.wp.desktop || window.wp.os` is PRESENT in the
 * source — and it is, and it passed, for the entire period the commands were
 * dead in production. A source-presence assertion cannot see REACHABILITY: the
 * line sits below an early `return` that fires first when neither global
 * exists, so the string is in the file and never runs. Only executing the file
 * against a hostile ordering can tell those two apart.
 *
 * THE ORDERING THIS PINS (measured live 2026-08-14, OpenStation v1.1.0):
 * OpenStation ships its shell bundle `desktop.min.js` — the script that
 * installs `window.wp.os` — with `defer`. Our scripts are NOT deferred.
 * Deferred scripts execute after EVERY non-deferred script, so the shell that
 * appears earlier in the document (DOM index 56) runs LAST, after ours (63,
 * 89). Our file therefore executes while `window.wp.os` does not yet exist.
 * `wp_register_script` dependency edges order the printed markup, not the
 * execution — once a dependency defers and its dependent does not, the edge is
 * silently inverted at runtime.
 *
 * Run standalone: `node tests/js/desktop-mode-boot.mjs`
 * Driven by: tests/desktop-mode-boot-order.php (so tests/run.sh sweeps it).
 * Emits a single JSON line; exit 0 = all scenarios pass, 1 = a scenario failed.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const SRC = readFileSync( join( HERE, '../../assets/desktop-mode.js' ), 'utf8' );

/**
 * A browser-shaped context. `ctx.window === ctx` mirrors the real thing, so a
 * `window.wp = …` assignment also satisfies the file's bare `wp.desktop.…`
 * references — exactly as it does in a browser. Getting this wrong would make
 * the harness fail for a reason the browser never would.
 */
function makeContext() {
	const listeners = {};
	const ctx = {
		console,
		registered: [],
		document: {
			readyState: 'loading',
			addEventListener: ( ev, cb ) => {
				( listeners[ ev ] ||= [] ).push( cb );
			},
		},
		snDesktopData: { pages: {} },
		snDesktopAttention: { total: '2', iconId: 'sn-icon-dashboard' },
	};
	ctx.window = ctx;
	ctx.self = ctx;
	vm.createContext( ctx );
	return {
		ctx,
		fire: ( ev ) => {
			ctx.document.readyState = 'complete';
			( listeners[ ev ] || [] ).forEach( ( cb ) => cb() );
		},
		listenerCount: ( ev ) => ( listeners[ ev ] || [] ).length,
	};
}

/** The subset of the OpenStation API this file actually touches. */
function installShellApi( ctx ) {
	const api = {
		registerCommand: ( c ) => ctx.registered.push( c && c.slug ),
		notify: () => {},
		dock: { setBadge: () => {} },
		sideDock: null, // v1.1.0 default 'unified' layout — genuinely null.
		icons: { setBadge: () => {} },
		whenReady: ( cb ) => cb(),
	};
	ctx.wp = ctx.wp || {};
	ctx.wp.os = api;
	ctx.wp.apiFetch = () => Promise.resolve( {} );
	ctx.wp.hooks = { addFilter: () => {}, filters: {} };
	return api;
}

const run = ( src, ctx ) => vm.runInContext( src, ctx, { filename: 'desktop-mode.js' } );

const results = [];
const check = ( name, pass, detail ) => results.push( { name, pass: !! pass, detail } );

// ── Scenario 1 — THE REGRESSION: shell is deferred, so it lands AFTER us.
{
	const { ctx, fire, listenerCount } = makeContext();
	ctx.wp = {}; // wp core exists; wp.os does NOT yet — the deferred shell hasn't run.
	run( SRC, ctx );
	const registeredBeforeShell = ctx.registered.length;

	installShellApi( ctx ); // the deferred bundle finally executes…
	fire( 'DOMContentLoaded' ); // …and deferred scripts all run before this event.

	check(
		'deferred-shell: commands register after the shell lands',
		ctx.registered.length >= 20,
		`registered ${ ctx.registered.length } (before shell: ${ registeredBeforeShell }), ` +
			`DOMContentLoaded listeners: ${ listenerCount( 'DOMContentLoaded' ) }`
	);
	check(
		'deferred-shell: sn-cmd slugs present',
		ctx.registered.filter( ( s ) => typeof s === 'string' && s.startsWith( 'sn-cmd' ) ).length >= 20,
		`sn-cmd slugs: ${ ctx.registered.filter( ( s ) => typeof s === 'string' && s.startsWith( 'sn-cmd' ) ).length }`
	);
	check(
		'deferred-shell: window.wp.desktop is aliased for the 65 legacy call sites',
		ctx.wp && ctx.wp.desktop && typeof ctx.wp.desktop.registerCommand === 'function',
		`typeof wp.desktop = ${ typeof ( ctx.wp && ctx.wp.desktop ) }`
	);
}

// ── Scenario 2 — CONTROL: shell already present (the pre-defer ordering).
// Must still register SYNCHRONOUSLY; the fix must not make the good path lazy.
{
	const { ctx } = makeContext();
	installShellApi( ctx );
	run( SRC, ctx );
	check(
		'shell-first: commands register synchronously, no event needed',
		ctx.registered.length >= 20,
		`registered ${ ctx.registered.length } with no DOMContentLoaded`
	);
}

// ── Scenario 3 — CONTROL: pre-rename family only (wp.desktop, no wp.os).
{
	const { ctx } = makeContext();
	ctx.wp = {
		desktop: { registerCommand: ( c ) => ctx.registered.push( c && c.slug ), notify: () => {} },
		apiFetch: () => Promise.resolve( {} ),
		hooks: { addFilter: () => {}, filters: {} },
	};
	run( SRC, ctx );
	check(
		'pre-rename family: still registers via wp.desktop alone',
		ctx.registered.length >= 20,
		`registered ${ ctx.registered.length }`
	);
}

// ── Scenario 4 — NEGATIVE CONTROL: no shell ever arrives.
// Proves the harness can distinguish "registered" from "did not", and that the
// file stays a clean no-op off-shell rather than throwing.
{
	const { ctx, fire } = makeContext();
	ctx.wp = {};
	let threw = null;
	try {
		run( SRC, ctx );
		fire( 'DOMContentLoaded' );
	} catch ( e ) {
		threw = e.message;
	}
	check(
		'no-shell: registers nothing and does not throw',
		ctx.registered.length === 0 && threw === null,
		`registered ${ ctx.registered.length }, threw: ${ threw }`
	);
}

const failed = results.filter( ( r ) => ! r.pass );
console.log( JSON.stringify( { passed: results.length - failed.length, failed: failed.length, results } ) );
process.exit( failed.length ? 1 : 0 );
