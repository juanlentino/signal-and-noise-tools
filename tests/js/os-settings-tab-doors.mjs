/**
 * assets/os-settings-tab.js — a door to one of this plugin's own pages opens
 * the NATIVE window, not the framework's iframe (14.7.6).
 *
 * The script is run in a vm context with a fake shell (wp.os) and a fake
 * document; scenarios drive tryNativeRemap and the capture-phase door click
 * and print one JSON line per scenario. tests/os-settings-tab-doors.php
 * asserts them.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const SRC = readFileSync( join( HERE, '../../assets/os-settings-tab.js' ), 'utf8' );
const ORIGIN = 'https://example.test';

function boot( prefs, openReturns = true ) {
	const clicks = [];
	const opened = [];
	const ctx = {
		console,
		URL,
		location: { href: ORIGIN + '/wp-admin/', origin: ORIGIN },
		sntOpenStationPreferences: { endpoint: '', nonce: '', preferences: prefs },
		document: {
			readyState: 'complete',
			addEventListener: ( ev, cb, capture ) => clicks.push( { ev, cb, capture } ),
			querySelector: () => null,
			getElementById: () => null,
			head: { appendChild() {} },
			createElement: () => ( { setAttribute() {}, appendChild() {}, addEventListener() {}, style: {} } ),
		},
		wp: {
			i18n: { __: ( s ) => s },
			os: {
				openWindow: ( id, opts ) => { opened.push( [ id, opts ] ); return openReturns; },
				registerNativeUrlRemap: () => () => {},
				registerSettingsTab: () => {},
			},
		},
	};
	ctx.window = ctx;
	ctx.self = ctx;
	vm.createContext( ctx );
	vm.runInContext( SRC, ctx );
	return { ctx, clicks, opened };
}

const results = [];
const scenario = ( name, fn ) => {
	try {
		const r = fn();
		results.push( { name, pass: !! r.pass, detail: r.detail } );
	} catch ( e ) {
		results.push( { name, pass: false, detail: 'threw: ' + ( e && e.message ) } );
	}
};

scenario( 'dashboard page: native window with tab/sub/anchor params', () => {
	const { ctx, opened } = boot( { dashboard: true, analytics: true } );
	const took = ctx.sntOpenStationPreferences.tryNativeRemap( ORIGIN + '/wp-admin/admin.php?page=sn-theme-options&tab=integrity&sub=trust&sn_x=1&foo=bar' );
	const [ id, opts ] = opened[ 0 ] || [];
	const ok = took === true && id === 'sn-dashboard' && opts && opts.params && opts.params.tab === 'integrity' && opts.params.sub === 'trust' && opts.params.sn_x === '1' && ! ( 'foo' in opts.params );
	return { pass: ok, detail: JSON.stringify( opened ) };
} );

scenario( 'analytics page: native window, sn_* params only', () => {
	const { ctx, opened } = boot( { dashboard: true, analytics: true } );
	const took = ctx.sntOpenStationPreferences.tryNativeRemap( ORIGIN + '/wp-admin/admin.php?page=sn-analytics&sn_view=overview&tab=x' );
	const [ id, opts ] = opened[ 0 ] || [];
	return { pass: took === true && id === 'sn-analytics' && opts.params.sn_view === 'overview' && ! ( 'tab' in opts.params ), detail: JSON.stringify( opened ) };
} );

scenario( 'preference off: not taken, no window opened', () => {
	const { ctx, opened } = boot( { dashboard: false, analytics: true } );
	const took = ctx.sntOpenStationPreferences.tryNativeRemap( ORIGIN + '/wp-admin/admin.php?page=sn-theme-options' );
	return { pass: took === false && opened.length === 0, detail: JSON.stringify( opened ) };
} );

scenario( 'another admin page, another origin, an empty url: not taken', () => {
	const { ctx, opened } = boot( { dashboard: true, analytics: true } );
	const a = ctx.sntOpenStationPreferences.tryNativeRemap( ORIGIN + '/wp-admin/update-core.php' );
	const b = ctx.sntOpenStationPreferences.tryNativeRemap( 'https://evil.test/wp-admin/admin.php?page=sn-theme-options' );
	const c = ctx.sntOpenStationPreferences.tryNativeRemap( '' );
	return { pass: a === false && b === false && c === false && opened.length === 0, detail: JSON.stringify( [ a, b, c ] ) };
} );

scenario( 'shell refuses (unregistered window): not taken, so the caller falls back', () => {
	const { ctx, opened } = boot( { dashboard: true, analytics: true }, false );
	const took = ctx.sntOpenStationPreferences.tryNativeRemap( ORIGIN + '/wp-admin/admin.php?page=sn-theme-options' );
	return { pass: took === false && opened.length === 1, detail: JSON.stringify( opened ) };
} );

scenario( 'a kit door click to our page is claimed in the capture phase and stopped', () => {
	const { clicks, opened } = boot( { dashboard: true, analytics: true } );
	const handler = clicks.find( ( c ) => c.ev === 'click' && c.capture === true );
	if ( ! handler ) {
		return { pass: false, detail: 'no capture-phase click listener' };
	}
	const door = { attrs: { 'os-action': 'door', 'os-arg-url': ORIGIN + '/wp-admin/admin.php?page=sn-theme-options&tab=integrity' }, hasAttribute: ( n ) => n === 'disabled' ? false : true, getAttribute( n ) { return this.attrs[ n ]; } };
	const target = { closest: ( sel ) => sel === '[os-action="door"][os-arg-url]' ? door : null };
	let prevented = false; let stopped = false;
	handler.cb( { target, button: 0, defaultPrevented: false, preventDefault: () => { prevented = true; }, stopImmediatePropagation: () => { stopped = true; } } );
	return { pass: prevented && stopped && opened.length === 1 && opened[ 0 ][ 0 ] === 'sn-dashboard', detail: JSON.stringify( { prevented, stopped, opened } ) };
} );

scenario( 'a kit door click to another admin page is left to the framework', () => {
	const { clicks, opened } = boot( { dashboard: true, analytics: true } );
	const handler = clicks.find( ( c ) => c.ev === 'click' && c.capture === true );
	const door = { attrs: { 'os-arg-url': ORIGIN + '/wp-admin/update-core.php' }, hasAttribute: () => false, getAttribute( n ) { return this.attrs[ n ]; } };
	const target = { closest: () => door };
	let prevented = false;
	handler.cb( { target, button: 0, defaultPrevented: false, preventDefault: () => { prevented = true; }, stopImmediatePropagation: () => {} } );
	return { pass: ! prevented && opened.length === 0, detail: JSON.stringify( { prevented, opened } ) };
} );

scenario( 'a modified click (cmd/ctrl/middle) is never claimed', () => {
	const { clicks, opened } = boot( { dashboard: true, analytics: true } );
	const handler = clicks.find( ( c ) => c.ev === 'click' && c.capture === true );
	const door = { attrs: { 'os-arg-url': ORIGIN + '/wp-admin/admin.php?page=sn-theme-options' }, hasAttribute: () => false, getAttribute( n ) { return this.attrs[ n ]; } };
	const target = { closest: () => door };
	handler.cb( { target, button: 0, metaKey: true, defaultPrevented: false, preventDefault: () => {}, stopImmediatePropagation: () => {} } );
	handler.cb( { target, button: 1, defaultPrevented: false, preventDefault: () => {}, stopImmediatePropagation: () => {} } );
	return { pass: opened.length === 0, detail: JSON.stringify( opened ) };
} );

process.stdout.write( JSON.stringify( { results } ) + '\n' );
process.exit( results.every( ( r ) => r.pass ) ? 0 : 1 );
