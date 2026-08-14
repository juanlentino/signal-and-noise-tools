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

/**
 * `registerCommand` VALIDATES in OpenStation v1.1.0 and throws on a missing
 * `label`, aborting the caller's whole script:
 *
 *   RegistrationError: [openstation] Command registration rejected —
 *     fields: label (missing).
 *
 * Captured live 2026-08-14 from `desktop.min.js`, thrown out of our own
 * `boot` at `desktop-mode.js:206` — the FIRST registerCommand call. One throw
 * there kills all 22. The stub must reproduce that or the harness would
 * happily accept registrations the real shell rejects, which is precisely the
 * stub-drift trap: a fake that is more permissive than the callee turns a
 * production error into a green test.
 */
function makeRegisterCommand( ctx ) {
	return ( c ) => {
		const missing = [];
		if ( ! c || typeof c.slug !== 'string' || ! c.slug ) { missing.push( 'slug' ); }
		if ( ! c || typeof c.label !== 'string' || ! c.label ) { missing.push( 'label' ); }
		if ( missing.length ) {
			throw new Error(
				'[openstation] Command registration rejected — fields: ' +
					missing.join( ', ' ) + ' (missing).'
			);
		}
		ctx.registered.push( c.slug );
	};
}

/** The subset of the OpenStation API this file actually touches. */
function installShellApi( ctx ) {
	const api = {
		registerCommand: makeRegisterCommand( ctx ),
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

/**
 * Swallow-and-report, never crash the harness. A registration throw is a
 * RESULT we want reported next to the count — if it propagated, the run would
 * abort and every later scenario would go unmeasured, which reads as "no
 * output" rather than "this failed here, for this reason".
 */
const attempt = ( fn ) => {
	try { fn(); return null; } catch ( e ) { return e.message; }
};

const results = [];
const check = ( name, pass, detail ) => results.push( { name, pass: !! pass, detail } );

// ── Scenario 1 — THE REGRESSION: shell is deferred, so it lands AFTER us.
{
	const { ctx, fire, listenerCount } = makeContext();
	ctx.wp = {}; // wp core exists; wp.os does NOT yet — the deferred shell hasn't run.
	const threwEarly = attempt( () => run( SRC, ctx ) );
	const registeredBeforeShell = ctx.registered.length;

	installShellApi( ctx ); // the deferred bundle finally executes…
	// …and deferred scripts all run before this event.
	const threwOnRetry = attempt( () => fire( 'DOMContentLoaded' ) );

	check(
		'deferred-shell: commands register after the shell lands',
		ctx.registered.length >= 20,
		`registered ${ ctx.registered.length } (before shell: ${ registeredBeforeShell }), ` +
			`listeners: ${ listenerCount( 'DOMContentLoaded' ) }, ` +
			`threw early: ${ threwEarly }, threw on retry: ${ threwOnRetry }`
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
	const threw = attempt( () => run( SRC, ctx ) );
	check(
		'shell-first: commands register synchronously, no event needed',
		ctx.registered.length >= 20 && threw === null,
		`registered ${ ctx.registered.length } with no DOMContentLoaded, threw: ${ threw }`
	);
}

// ── Scenario 3 — CONTROL: pre-rename family only (wp.desktop, no wp.os).
{
	const { ctx } = makeContext();
	ctx.wp = {
		desktop: { registerCommand: makeRegisterCommand( ctx ), notify: () => {} },
		apiFetch: () => Promise.resolve( {} ),
		hooks: { addFilter: () => {}, filters: {} },
	};
	const threw = attempt( () => run( SRC, ctx ) );
	check(
		'pre-rename family: still registers via wp.desktop alone',
		ctx.registered.length >= 20 && threw === null,
		`registered ${ ctx.registered.length }, threw: ${ threw }`
	);
}

// ── Scenario 4 — NEGATIVE CONTROL: no shell ever arrives.
// Proves the harness can distinguish "registered" from "did not", and that the
// file stays a clean no-op off-shell rather than throwing.
{
	const { ctx, fire } = makeContext();
	ctx.wp = {};
	const threw = attempt( () => { run( SRC, ctx ); fire( 'DOMContentLoaded' ); } );
	check(
		'no-shell: registers nothing and does not throw',
		ctx.registered.length === 0 && threw === null,
		`registered ${ ctx.registered.length }, threw: ${ threw }`
	);
}

const failed = results.filter( ( r ) => ! r.pass );
console.log( JSON.stringify( { passed: results.length - failed.length, failed: failed.length, results } ) );
process.exit( failed.length ? 1 : 0 );
