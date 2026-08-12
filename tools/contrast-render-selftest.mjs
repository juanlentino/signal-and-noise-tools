#!/usr/bin/env node
/**
 * Calibration self-test for tools/contrast-render-scan.mjs.
 *
 * WHY THIS EXISTS. A contrast scanner that measures nothing reports a clean
 * site, and a clean report from a broken instrument is indistinguishable from a
 * clean site. This repo has now been fooled by that shape four times in the
 * test sweep alone. So the scanner does not get to claim "0 failing" until it
 * has proved, against a fixture with hand-derived ratios, that it finds exactly
 * the failures planted there — no more, no fewer.
 *
 * The five planted cases are each a way an earlier tier was blind:
 *   1.83 rest   translucent black    — needs alpha compositing, not the declared value
 *   3.29 hover  hover-only link      — r3-prep §3C's own worked example
 *   3.49 rest   chip green on white  — the live provenance-chip defect
 *   3.49 rest   inherited colour     — no `color` declaration on the element at all
 *   3.80 rest   blood on asphalt     — passes at root, fails under the served palette
 * plus three PASSING cases that must stay silent (muted 4.83, large text at
 * 3:1, black on white) and one UNSCOREABLE (text over a gradient).
 *
 * NOT part of the tests/*.php sweep: it needs playwright-core and a real
 * Chrome, which CI has neither of. It lives in tools/ beside the instrument it
 * calibrates. Run it after touching the scanner.
 *
 *   npm i playwright-core && node tools/contrast-render-selftest.mjs
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, unlinkSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const scanner = join( here, 'contrast-render-scan.mjs' );
const fixture = 'file://' + join( here, 'contrast-render-fixture.html' );
const out = join( here, '.selftest-report.json' );

let pass = 0;
let fail = 0;
const ok = ( cond, msg ) => {
	if ( cond ) {
		pass++;
		console.log( `PASS: ${ msg }` );
	} else {
		fail++;
		console.log( `FAIL: ${ msg }` );
	}
};

// The scanner exits 1 when it finds anything, which is the point here.
try {
	execFileSync( process.execPath, [ scanner, fixture, '--json', out ], { stdio: 'pipe' } );
} catch {
	/* non-zero exit is expected — the fixture is full of planted failures. */
}

let report;
try {
	report = JSON.parse( readFileSync( out, 'utf8' ) );
} catch ( e ) {
	console.log( `FAIL: scanner produced no readable JSON report (${ e.message })` );
	console.log( '\nResult: 0 passed, 1 failed.' );
	process.exit( 1 );
}

const found = report.findings || [];
const at = ( ratio, state ) => found.filter( ( f ) => f.ratio === ratio && f.state === state );

console.log( '\nGroup 1: every planted failure is found' );
ok( at( 1.83, 'rest' ).length === 1, 'translucent black composites to 1.83:1 (declared value would say 21:1)' );
ok( at( 3.29, 'hover' ).length === 1, 'HOVER-ONLY link at 3.29:1 — r3-prep §3C\'s example, unreachable by any declaration scan' );
ok( at( 3.49, 'rest' ).length === 2, 'both 3.49:1 greens found — the chip, and the INHERITED undeclared one' );
ok( at( 3.8, 'rest' ).length === 1, 'blood on served asphalt at 3.80:1' );

console.log( '\nGroup 2: passing cases stay silent' );
const ratios = found.map( ( f ) => f.ratio );
ok( ! ratios.includes( 4.83 ), 'muted grey at 4.83:1 is NOT reported' );
ok( ! found.some( ( f ) => f.large ), 'large text at 3:1 is NOT reported — the 3.0 threshold applies' );
ok( ! ratios.includes( 21 ), 'black on white is NOT reported' );

// A REFUSAL IS A VERDICT TOO. Measured on /notes/ 2026-08-11: the scan called
// two 21:1 black-on-white titles unscoreable because their <a> carried a
// background-image — which was a linear-gradient(#000,#000) at
// `background-size: 0 1px`, a zero-width animated underline that covers no
// glyph and is a solid colour besides. Refusing costs the measurement exactly
// as a wrong answer does, and an unscoreable list padded with non-problems
// trains the reader to skip it — which is where a real one would then hide.
// So: refuse only what genuinely cannot be resolved.
console.log( '\nGroup 2b: resolvable background-images are scored, not refused' );
ok( at( 2.74, 'rest' ).length === 1, 'a single-colour gradient composites EXACTLY and its 2.74:1 failure is FOUND, not refused' );
ok(
	! ( report.unscoreable || [] ).some( ( u ) => /s-underline/.test( u.path || '' ) ),
	'a background-image with a zero dimension in background-size paints nothing and is NOT refused'
);
// The other half of the same coin, and the reason this fix needed a second
// pass: the FIRST version resolved any single-colour gradient as a solid layer,
// which on the live /notes/ page composited a drawn 1px underline behind its
// own black text and reported two invented 1:1 failures in the focus state. A
// hairline is neither "paints nothing" nor "covers the box". Refusing is the
// honest answer; inventing a failure is worse than declining to measure.
ok(
	( report.unscoreable || [] ).some( ( u ) => /s-hairline/.test( u.path || '' ) ),
	'a DRAWN 1px underline is REFUSED, not resolved as a surface — no invented 1:1'
);
ok( ! found.some( ( f ) => f.ratio === 1 ), 'no 1:1 finding — black composited under black text is an artefact, never a real pairing' );

console.log( '\nGroup 3: exact count — no phantoms, no misses' );
ok( found.length === 6, `exactly 6 findings, got ${ found.length }` );
ok( ( report.unscoreable || [] ).length === 2, `exactly 2 unscoreable — the REAL two-colour gradient and the drawn hairline, both refused rather than guessed — got ${ ( report.unscoreable || [] ).length }` );

console.log( '\nGroup 4: state handling' );
ok(
	found.filter( ( f ) => f.state !== 'rest' ).length === 1,
	'only ONE non-rest finding — resting failures are not re-reported once per forced state'
);
ok( report.states.includes( 'hover' ), 'the report records which states were exercised' );

console.log( '\nGroup 5: the report is self-describing' );
ok( Array.isArray( report.urls ) && report.urls.length === 1, 'report names the URLs scanned — coverage is exactly what was pointed at' );
ok( typeof report.generated === 'string', 'report is timestamped, so a stale one is recognisable as stale' );
ok( report.measurements > report.pairings, 'measurements exceed distinct pairings (states re-measure the page)' );

try {
	unlinkSync( out );
} catch {
	/* best effort */
}

console.log( `\nResult: ${ pass } passed, ${ fail } failed.` );
process.exit( fail > 0 ? 1 : 0 );
