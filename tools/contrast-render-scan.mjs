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
 *
 * Usage:
 *   node tools/contrast-render-scan.mjs                        # default URL set
 *   node tools/contrast-render-scan.mjs https://a/ https://b/  # explicit URLs
 *   node tools/contrast-render-scan.mjs --json report.json     # machine output
 *   node tools/contrast-render-scan.mjs --no-states            # resting only
 *
 * Requires: playwright-core and a local Google Chrome. Deliberately NOT a
 * committed dependency of this plugin — install it where you run the tool:
 *   npm i playwright-core
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

	// The effective backdrop: walk up compositing translucent layers until an
	// opaque one. A background IMAGE anywhere in that stack makes the answer
	// depend on pixels, so we refuse rather than guess.
	const backdrop = ( el ) => {
		const layers = [];
		for ( let n = el; n; n = n.parentElement ) {
			const s = getComputedStyle( n );
			if ( s.backgroundImage && s.backgroundImage !== 'none' ) {
				return { unscoreable: 'background-image' };
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
	for ( const el of document.querySelectorAll( 'body *' ) ) {
		// Own text only — otherwise a wrapper is credited with its children's text.
		const own = Array.from( el.childNodes )
			.filter( ( n ) => n.nodeType === 3 )
			.map( ( n ) => n.textContent )
			.join( '' )
			.trim();
		if ( ! own ) continue;

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
	return rows;
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
const unscoreable = [];
const seen = new Set();
let measured = 0;

const browser = await chromium.launch( { channel: 'chrome', headless: true } );
const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );

for ( const url of targets ) {
	process.stdout.write( `scanning ${ url } … ` );
	try {
		await page.goto( url, { waitUntil: 'networkidle', timeout: 45000 } );
	} catch ( e ) {
		console.log( `SKIP (${ e.message.split( '\n' )[ 0 ] })` );
		continue;
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

	record( await page.evaluate( COLLECT ), 'rest' );

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
			record( await page.evaluate( COLLECT ), state );
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
} else {
	console.log( '\nNo rendered pairing falls below AA.' );
}

if ( unscoreable.length ) {
	console.log( `\n${ unscoreable.length } unscoreable (text over a background image — needs a human eye):` );
	for ( const u of unscoreable.slice( 0, 10 ) ) console.log( `  ${ u.path } — ${ u.url }` );
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
				pairings: seen.size,
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
