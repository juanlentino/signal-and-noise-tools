#!/usr/bin/env node
/**
 * The RENDERED-PAIR contrast tier (r3-prep §3C).
 *
 * The third and last tier. The other two live in inc/health-contrast-tokens.php
 * and inc/health-contrast-usage.php, and both are honest about what they cannot
 * see:
 *
 *   1. ARITHMETIC  — every unordered token pair, scored as would-fail/pass.
 *                    Cannot say which pairs meet on screen. Its count is a
 *                    property of the palette and never drops when defects
 *                    are fixed.
 *   2. USAGE       — pairings DECLARED in stylesheets, scored under every
 *                    shipped palette. Blind to non-resting states, colours
 *                    inlined in block markup, and the computed cascade,
 *                    because it reads declarations rather than renders.
 *   3. RENDERED    — this file. Reads getComputedStyle from a real page, so
 *                    it sees all three of those.
 *
 * §3C: "the rendered tier must read computed styles, not token declarations —
 * or it inherits the same blind spot one level up." That is what this does.
 *
 * ── WHY THIS IS NOT A TEST AND NOT IN CI ──────────────────────────────────
 * It needs a browser and a live site. CI has neither, and the Actions quota
 * on this account runs at ~99% of 3,000 minutes/month — a headless browser in
 * CI would be both broken and expensive. This is a local instrument you point
 * at the site, like a light meter. It exits non-zero on findings so it can gate
 * a release by hand, but nothing runs it automatically.
 *
 * ── WHAT IT ACTUALLY MEASURES ─────────────────────────────────────────────
 * For every element with its own visible text: the computed colour, the
 * effective background (walking ancestors and compositing any translucent
 * layers), the font size and weight (which decide the 4.5 vs 3.0 threshold),
 * and the resulting WCAG 2.1 ratio. Then, for interactive elements, it forces
 * :hover and :focus-visible through the DevTools protocol and measures again —
 * §3C's own worked example is a hover link that measured 3.29:1 and that no
 * declaration-reading scan could reach.
 *
 * ── WHAT IT STILL CANNOT SEE, stated because every tier here states it ────
 *   - Pages not in the URL list. Coverage is exactly what you point it at.
 *   - Text over background IMAGES or gradients: reported as unscoreable rather
 *     than guessed, because the answer depends on the pixels behind the glyphs.
 *   - States needing real interaction to exist at all (an open menu, a
 *     validation error). Forced pseudo-classes restyle what is already there;
 *     they do not create it.
 *   - CSS TRANSITIONS, deterministically. Forcing a pseudo-class starts any
 *     transition it triggers, and computed styles are sampled at whatever
 *     moment the pass reaches the element — so a transitioning property can
 *     read as its start value, its end value, or anything between, and can
 *     differ between two runs against an unchanged site. Measured 2026-08-11 on
 *     /notes/, where an animated underline's background-size was caught
 *     mid-flight. Treat a single run's unscoreable list as a sample, not a
 *     census, and re-run before concluding an entry appeared or vanished.
 *
 * ── RUN THIS ON YOUR WORKSTATION, NEVER ON THE WEB SERVER ─────────────────
 * The site is the SUBJECT, not the host. This drives a local Chrome and
 * fetches the pages over HTTP, so it belongs on a laptop with a browser.
 *
 * Running it on the Cloudways host fails three ways and was tried once
 * (2026-08-11): there is no Chrome there, the host runs Node 18 while
 * playwright-core needs >=20, and `npm i` inside public_html leaves a
 * node_modules/ and package.json PUBLICLY SERVED from the docroot —
 * `https://<site>/package.json` returned 200 until it was cleaned up.
 *
 * Usage — from a checkout of this repo, on your own machine:
 *   npm i playwright-core                                      # once
 *   node tools/contrast-render-scan.mjs                        # default URL set
 *   node tools/contrast-render-scan.mjs https://a/ https://b/  # explicit URLs
 *   node tools/contrast-render-scan.mjs --json report.json     # machine output
 *   node tools/contrast-render-scan.mjs --no-states            # resting only
 *   node tools/contrast-render-scan.mjs --deterministic file://…/fixture.html
 *                                                              # pinned + repeatable
 *
 * --deterministic pins every source of render variance this instrument can pin
 * (transitions and animations frozen, emulated media, `load` not `networkidle`,
 * deviceScaleFactor 1) and REFUSES the live URL list, because a deterministic
 * run needs repo-controlled input. See the block above `const deterministic`.
 *
 * Either mode now EXITS NON-ZERO when nothing was measured. A run where every
 * target failed to load used to print the all-clear and exit 0.
 *
 * Requires: playwright-core and a local Google Chrome. Deliberately NOT a
 * committed dependency of this plugin.
 */

import { chromium } from 'playwright-core';
import { writeFileSync } from 'node:fs';

const DEFAULT_URLS = [
	'https://juanlentino.com/',
	'https://juanlentino.com/notes/',
	'https://juanlentino.com/provenance/over-detection/',
	'https://juanlentino.com/verify/',
	'https://juanlentino.com/now/',
	'https://juanlentino.com/resume/',
	'https://juanlentino.com/about/uses/',
];

const AA_BODY = 4.5;
const AA_LARGE = 3.0;

const args = process.argv.slice(2);
const jsonAt = args.indexOf( '--json' );
const jsonPath = jsonAt === -1 ? null : args[ jsonAt + 1 ];
const withStates = ! args.includes( '--no-states' );
// file:// is allowed so the calibration fixture can be scanned by the same
// code path as the live site — a self-test that exercised a different path
// would prove nothing about the instrument you actually point at production.
const urls = args.filter( ( a ) => a.startsWith( 'http' ) || a.startsWith( 'file:' ) );

/**
 * --deterministic (Increment 0): pin every source of render variance this
 * instrument can pin, and refuse the ones it cannot.
 *
 * WHY. An unpinned run of this scanner can disagree with itself against an
 * unchanged site — measured on /notes/, where an animated underline sampled
 * mid-transition produced two invented 1:1 failures. A scan whose findings
 * move on their own trains the reader to ignore it, which is worse than no
 * scan. This mode makes a run repeatable, and the self-test proves it by
 * running twice and comparing.
 *
 * What it pins: transitions and animations frozen before any sample;
 * prefers-reduced-motion and prefers-color-scheme emulated rather than
 * inherited from the machine; `load` instead of `networkidle` (which is a
 * function of the network, not the page); deviceScaleFactor 1.
 *
 * What it REFUSES: the live DEFAULT_URLS. Deterministic means repo-controlled
 * input — pointing a "deterministic" run at production would pin the browser
 * and leave the CDN, the minify layer and the content free to move underneath
 * it, which is the most misleading combination available.
 *
 * What it CANNOT pin, stated rather than hidden: the Chrome major version
 * (`channel: 'chrome'` is whatever is installed), so the report names it.
 */
const deterministic = args.includes( '--deterministic' );

if ( deterministic && ! urls.length ) {
	console.error(
		'--deterministic refuses the built-in live URL list: a deterministic run needs\n' +
		'repo-controlled input. Pass explicit file:// fixtures (or a loopback URL).\n' +
		'  node tools/contrast-render-scan.mjs --deterministic file://…/contrast-render-fixture.html'
	);
	process.exit( 2 );
}

const targets = urls.length ? urls : DEFAULT_URLS;

/**
 * Collect every scoreable text pairing on the current page.
 *
 * Runs INSIDE the page. Everything it needs must be self-contained — no
 * closures over Node scope.
 */
const COLLECT = () => {
	const parse = ( s ) => {
		const m = String( s ).match( /[\d.]+/g );
		if ( ! m ) return null;
		const [ r, g, b, a ] = m.map( Number );
		return [ r, g, b, a === undefined ? 1 : a ];
	};
	const composite = ( fg, bg ) => {
		const a = fg[ 3 ];
		return [ 0, 1, 2 ].map( ( i ) => fg[ i ] * a + bg[ i ] * ( 1 - a ) ).concat( 1 );
	};
	const lum = ( [ r, g, b ] ) => {
		const f = ( c ) => {
			c /= 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * f( r ) + 0.7152 * f( g ) + 0.0722 * f( b );
	};
	const ratio = ( x, y ) => {
		const a = lum( x );
		const b = lum( y );
		const hi = Math.max( a, b );
		const lo = Math.min( a, b );
		return ( hi + 0.05 ) / ( lo + 0.05 );
	};
	const hex = ( [ r, g, b ] ) =>
		'#' + [ r, g, b ].map( ( c ) => Math.round( c ).toString( 16 ).padStart( 2, '0' ) ).join( '' );

	// A readable-ish path, enough to find the element again by hand.
	const pathOf = ( el ) => {
		const parts = [];
		for ( let n = el; n && n.nodeType === 1 && parts.length < 4; n = n.parentElement ) {
			let p = n.tagName.toLowerCase();
			if ( n.id ) {
				p += '#' + n.id;
				parts.unshift( p );
				break;
			}
			const cls = ( n.getAttribute( 'class' ) || '' ).trim().split( /\s+/ ).filter( Boolean ).slice( 0, 2 );
			if ( cls.length ) p += '.' + cls.join( '.' );
			parts.unshift( p );
		}
		return parts.join( ' > ' );
	};

	// What a background-image contributes to the backdrop.
	//
	// Refusing to guess is right when the answer genuinely depends on pixels.
	// It is NOT right when the image can be resolved exactly — a refusal costs
	// the measurement just as a wrong answer does, and the two are
	// indistinguishable in the report. Measured on /notes/ 2026-08-11: two
	// black-on-white titles at 21:1 were refused because their <a> carried a
	// `linear-gradient(#000,#000)` sized `0 1px` — a zero-width animated
	// underline covering no glyph at all.
	//
	// Returns 'skip' (paints nothing), an rgba array (a solid layer), or null
	// (genuinely unresolvable — refuse).
	const imageLayer = ( s ) => {
		const img = s.backgroundImage;
		if ( ! img || img === 'none' ) return 'skip';

		// Multiple comma-separated layers: not worth resolving, and rare. A
		// gradient string contains commas too, so only treat it as multi-layer
		// when a comma sits outside every parenthesis.
		let depth = 0;
		for ( const ch of img ) {
			if ( ch === '(' ) depth++;
			else if ( ch === ')' ) depth--;
			else if ( ch === ',' && depth === 0 ) return null;
		}

		// THREE OUTCOMES, and the middle one is the whole point:
		//
		//   paints nothing   -> skip      (a zero dimension in background-size)
		//   covers the box   -> resolve   (if it is a single colour)
		//   anything else    -> refuse
		//
		// The middle case earned its own rule the hard way. The first version of
		// this fix resolved ANY single-colour gradient, and on the live /notes/
		// page it reported two invented 1:1 failures — black composited under
		// black text — on titles that are plainly 21:1.
		//
		// The mechanism is a TRANSITION RACE, verified rather than assumed. Those
		// titles carry a `linear-gradient(#000,#000)` underline sized `0px 1px`
		// at rest, whose WIDTH animates on hover/focus. Sampled mid-transition it
		// reads as a partial width at a 1px height — a hairline along the bottom
		// edge, never a surface behind the glyphs. (Probed directly afterwards it
		// reads `0px 1px` again, which is why the first diagnosis was wrong: the
		// value depends on WHEN you look.)
		//
		// Inventing a failure is strictly worse than declining to measure, so a
		// hairline goes back to unscoreable. Deciding it by geometry (a 1px rule
		// cannot sit behind a 30px glyph) would mean modelling layout this
		// scanner deliberately does not model.
		const size = ( s.backgroundSize || '' ).trim();
		if ( size && /(^|\s)0(px|%|)(\s|$)/.test( size ) ) return 'skip';
		if ( size && ! /^(auto|auto auto|cover|contain|100%|100% 100%|100% auto)$/.test( size ) ) {
			return null;
		}

		// A gradient whose every colour stop is identical is a solid colour,
		// and composites exactly. Anything else depends on pixels.
		if ( /gradient\(/.test( img ) ) {
			const stops = img.match( /rgba?\([^)]*\)/g ) || [];
			if ( stops.length ) {
				const first = parse( stops[ 0 ] );
				if ( first && stops.every( ( c ) => {
					const p = parse( c );
					return p && p.every( ( v, i ) => v === first[ i ] );
				} ) ) {
					return first;
				}
			}
		}
		return null;
	};

	// The effective backdrop: walk up compositing translucent layers until an
	// opaque one. A background IMAGE anywhere in that stack normally makes the
	// answer depend on pixels — unless imageLayer() can resolve it exactly.
	const backdrop = ( el ) => {
		const layers = [];
		for ( let n = el; n; n = n.parentElement ) {
			const s = getComputedStyle( n );
			const img = imageLayer( s );
			if ( img === null ) {
				return { unscoreable: 'background-image' };
			}
			if ( img !== 'skip' && img[ 3 ] > 0 ) {
				layers.push( img );
				if ( img[ 3 ] === 1 ) break;
			}
			const c = parse( s.backgroundColor );
			if ( c && c[ 3 ] > 0 ) {
				layers.push( c );
				if ( c[ 3 ] === 1 ) break;
			}
		}
		let base = [ 255, 255, 255, 1 ];
		for ( let i = layers.length - 1; i >= 0; i-- ) base = composite( layers[ i ], base );
		return { color: base };
	};

	const rows = [];
	const decorative = [];
	for ( const el of document.querySelectorAll( 'body *' ) ) {
		// Own text only — otherwise a wrapper is credited with its children's text.
		const own = Array.from( el.childNodes )
			.filter( ( n ) => n.nodeType === 3 )
			.map( ( n ) => n.textContent )
			.join( '' )
			.trim();
		if ( ! own ) continue;

		// aria-hidden="true" is the author declaring this decorative, and SC
		// 1.4.3 exempts pure decoration. Reported separately rather than
		// silently dropped: the attribute is also the easiest way to make a
		// scanner quiet about a real defect, so the count stays visible and a
		// reviewer can spot a suspicious spike in it.
		//
		// This came from the first live run: it flagged /verify's "·"
		// separator and its step numerals, all three already aria-hidden. The
		// numerals duplicate <ol> position, the dot is a dot. Three findings
		// that were the instrument being eager, not the page being wrong.
		if ( el.closest( '[aria-hidden="true"]' ) ) {
			decorative.push( { path: pathOf( el ), text: own.slice( 0, 60 ) } );
			continue;
		}

		const s = getComputedStyle( el );
		if ( s.display === 'none' || s.visibility === 'hidden' || Number( s.opacity ) === 0 ) continue;
		// Visually-hidden helper text (the 1x1 clip-rect idiom) is not seen by
		// anyone, so scoring it invents defects.
		const box = el.getBoundingClientRect();
		if ( box.width < 1 || box.height < 1 ) continue;

		const fgRaw = parse( s.color );
		if ( ! fgRaw ) continue;
		const bg = backdrop( el );
		if ( bg.unscoreable ) {
			rows.push( { path: pathOf( el ), text: own.slice( 0, 60 ), unscoreable: bg.unscoreable } );
			continue;
		}

		const fg = fgRaw[ 3 ] === 1 ? fgRaw : composite( fgRaw, bg.color );
		const size = parseFloat( s.fontSize );
		const weight = Number( s.fontWeight ) || 400;
		// WCAG large text: >=24px, or >=18.66px when bold.
		const large = size >= 24 || ( size >= 18.66 && weight >= 700 );

		rows.push( {
			path: pathOf( el ),
			text: own.slice( 0, 60 ),
			fg: hex( fg ),
			bg: hex( bg.color ),
			size: Math.round( size * 100 ) / 100,
			weight,
			large,
			ratio: Math.round( ratio( fg, bg.color ) * 100 ) / 100,
		} );
	}
	return { rows, decorative };
};

const threshold = ( row ) => ( row.large ? AA_LARGE : AA_BODY );
const fails = ( row ) => ! row.unscoreable && row.ratio < threshold( row );
const keyOf = ( row, state ) => [ state, row.path, row.fg, row.bg, row.large ].join( '|' );
// What an element looked like at rest, so a state pass can tell "this element
// restyles on hover" from "this element was already failing and I measured the
// whole page again". Without it every resting failure is reported three times
// and the one genuinely state-specific finding drowns in its own echo.
const restingLook = new Map();
const lookOf = ( row ) => `${ row.fg }|${ row.bg }|${ row.unscoreable || '' }`;

const findings = [];
let decorativeSkipped = 0;
const unscoreable = [];
const seen = new Set();
let measured = 0;

// Freezing motion before any sample. Applied per navigation, because a
// document swap drops injected styles. `transition: none` is what makes the
// hover pass measure an END STATE rather than whatever the animation happened
// to be showing when the pass reached the element.
const FREEZE_MOTION = '*, *::before, *::after { transition: none !important; animation: none !important; }';

// Fail closed on a missing browser. A wrapper that "best-effort"s this would
// turn "no Chrome" into "no findings", which reads as a clean site.
let browser;
try {
	browser = await chromium.launch( { channel: 'chrome', headless: true } );
} catch ( e ) {
	console.error( `Could not launch Chrome: ${ e.message.split( '\n' )[ 0 ] }` );
	console.error( 'This instrument needs a local Google Chrome. It reports nothing rather than reporting zero.' );
	process.exit( 2 );
}
const page = await browser.newPage( {
	viewport: { width: 1280, height: 900 },
	deviceScaleFactor: 1,
} );
if ( deterministic ) {
	// Emulated rather than inherited: the answer must not depend on the OS
	// settings of whoever happens to run this.
	await page.emulateMedia( { reducedMotion: 'no-preference', colorScheme: 'light' } );
}

let skipped = 0;

for ( const url of targets ) {
	process.stdout.write( `scanning ${ url } … ` );
	try {
		// networkidle is a property of the NETWORK, not the page — it is the
		// single largest source of run-to-run variance here.
		await page.goto( url, {
			waitUntil: deterministic ? 'load' : 'networkidle',
			timeout: 45000,
		} );
	} catch ( e ) {
		console.log( `SKIP (${ e.message.split( '\n' )[ 0 ] })` );
		skipped++;
		continue;
	}
	if ( deterministic ) {
		await page.addStyleTag( { content: FREEZE_MOTION } );
	}

	const record = ( rows, state ) => {
		for ( const row of rows ) {
			measured++;
			const id = `${ url }|${ row.path }`;

			if ( state === 'rest' ) {
				restingLook.set( id, lookOf( row ) );
			} else if ( restingLook.get( id ) === lookOf( row ) ) {
				// Identical to rest: the forced state changed nothing here, so
				// this is the resting row measured again, not a state defect.
				continue;
			}

			const k = keyOf( row, state );
			if ( seen.has( k ) ) continue;
			seen.add( k );
			if ( row.unscoreable ) {
				unscoreable.push( { ...row, url, state } );
			} else if ( fails( row ) ) {
				findings.push( { ...row, url, state, needs: threshold( row ) } );
			}
		}
	};

	const restPass = await page.evaluate( COLLECT );
	decorativeSkipped += restPass.decorative.length;
	record( restPass.rows, 'rest' );

	// Forced pseudo-states. §3C's worked example is a :hover link, and no
	// declaration-reading scan can reach it. CSS.forcePseudoState restyles the
	// element without a real pointer, so this stays fast and deterministic —
	// a mouse-move sweep would be neither.
	if ( withStates ) {
		const cdp = await page.context().newCDPSession( page );
		await cdp.send( 'DOM.enable' );
		await cdp.send( 'CSS.enable' );
		const { root } = await cdp.send( 'DOM.getDocument', { depth: -1 } );
		const { nodeIds } = await cdp.send( 'DOM.querySelectorAll', {
			nodeId: root.nodeId,
			selector: 'a, button, [tabindex], summary, input, select, textarea',
		} );

		for ( const state of [ 'hover', 'focus-visible' ] ) {
			for ( const nodeId of nodeIds ) {
				try {
					await cdp.send( 'CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [ state ] } );
				} catch {
					/* node went away between query and force; nothing to measure. */
				}
			}
			record( ( await page.evaluate( COLLECT ) ).rows, state );
			for ( const nodeId of nodeIds ) {
				try {
					await cdp.send( 'CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] } );
				} catch {
					/* as above */
				}
			}
		}
		await cdp.detach();
	}

	console.log( 'done' );
}

await browser.close();

findings.sort( ( a, b ) => a.ratio - b.ratio );

console.log( '\n── rendered-pair contrast, WCAG 2.1 AA ──' );
console.log( `pages: ${ targets.length }   measurements: ${ measured }   distinct pairings: ${ seen.size }` );

if ( findings.length ) {
	console.log( `\n${ findings.length } FAILING:\n` );
	for ( const f of findings ) {
		console.log(
			`  ${ String( f.ratio ).padStart( 5 ) }:1  (needs ${ f.needs })  [${ f.state }]  ${ f.fg } on ${ f.bg }  ${ f.size }px/${ f.weight }`
		);
		console.log( `         ${ f.path }` );
		console.log( `         "${ f.text }"  — ${ f.url }` );
	}
} else if ( measured > 0 ) {
	console.log( '\nNo rendered pairing falls below AA.' );
}

/**
 * FAIL CLOSED when nothing was measured — unconditional, not gated behind
 * --deterministic.
 *
 * Before this, a `page.goto` failure logged SKIP and continued, and the process
 * exited `findings.length ? 1 : 0`. So a run where EVERY target failed to load
 * printed "No rendered pairing falls below AA" and exited 0: a clean bill of
 * health from a scan that measured nothing. That is this repo's most-repeated
 * defect shape (a suite with no summary line counting as green), and it is a
 * false green in the unpinned mode exactly as much as in the pinned one — so
 * the gate is not a feature of the new flag.
 */
if ( targets.length > 0 && measured === 0 ) {
	console.error(
		`\nMEASURED NOTHING: ${ skipped } of ${ targets.length } target(s) skipped, 0 pairings sampled.`
	);
	console.error(
		'This is not a clean site — it is a failed run. Exiting non-zero so it cannot be read as a pass.'
	);
	await browser.close();
	process.exit( 2 );
}

if ( unscoreable.length ) {
	console.log( `\n${ unscoreable.length } unscoreable (text over a background image — needs a human eye):` );
	for ( const u of unscoreable.slice( 0, 10 ) ) console.log( `  ${ u.path } — ${ u.url }` );
}

if ( decorativeSkipped ) {
	console.log(
		`\n${ decorativeSkipped } element(s) skipped as aria-hidden="true" — decoration is exempt from SC 1.4.3.`
	);
	console.log(
		'A sudden jump in that number is worth a look: aria-hidden is also the easiest way to silence this scan.'
	);
}

console.log(
	'\nCoverage: only the pages listed above, and only states reachable by forcing'
);
console.log(
	'pseudo-classes. Text over background images is refused, not guessed.'
);

if ( jsonPath ) {
	writeFileSync(
		jsonPath,
		JSON.stringify(
			{
				generated: new Date().toISOString(),
				urls: targets,
				states: withStates ? [ 'rest', 'hover', 'focus-visible' ] : [ 'rest' ],
				measurements: measured,
				skipped,
				decorativeSkipped,
				pairings: seen.size,
				// A pinned report and an unpinned one must be tellable apart
				// later, or neither can be used as evidence. The Chrome version
				// is the source this mode CANNOT pin, so it is named: two runs
				// that disagree are then diagnosable instead of mysterious.
				deterministic,
				chrome: browser.version(),
				pins: deterministic
					? {
						transitions: 'disabled',
						animations: 'disabled',
						waitUntil: 'load',
						reducedMotion: 'no-preference',
						colorScheme: 'light',
						deviceScaleFactor: 1,
						viewport: '1280x900',
					}
					: null,
				findings,
				unscoreable,
			},
			null,
			2
		)
	);
	console.log( `\nwrote ${ jsonPath }` );
}

process.exit( findings.length ? 1 : 0 );
