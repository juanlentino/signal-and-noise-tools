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
	const remaps = [];
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
				registerNativeUrlRemap: ( entry ) => { remaps.push( entry ); return () => {}; },
				registerSettingsTab: () => {},
			},
		},
	};
	ctx.window = ctx;
	ctx.self = ctx;
	vm.createContext( ctx );
	vm.runInContext( SRC, ctx );
	return { ctx, clicks, opened, remaps };
}

/**
 * assets/os-kit.js in a fake shell: one window per id whose activateTab()
 * fires the strip's own os-window-tab-change, as the shell's does.
 */
function bootKit( storage = new Map() ) {
	const listeners = {};
	const shown = [];
	const dispatched = [];
	const windows = {};
	const fire = ( type, event ) => ( listeners[ type ] || [] ).forEach( ( cb ) => cb( event ) );
	const windowFor = ( id ) => windows[ id ] || ( windows[ id ] = {
		getAttribute: ( n ) => ( 'data-window-id' === n ? id : null ),
		querySelector: () => null,
		activateTab: ( value ) => { shown.push( [ id, value ] ); fire( 'os-window-tab-change', { target: windowFor( id ), detail: { value } } ); },
	} );
	const ctx = {
		console,
		// The tab's sessionStorage, which a reload keeps; `null` stands for one
		// that throws (a private window, blocked site data).
		sessionStorage: null === storage
			? { getItem() { throw new Error( 'blocked' ); }, setItem() { throw new Error( 'blocked' ); } }
			: { getItem: ( k ) => ( storage.has( k ) ? storage.get( k ) : null ), setItem: ( k, v ) => { storage.set( k, String( v ) ); } },
		document: { addEventListener: ( type, cb ) => { ( listeners[ type ] = listeners[ type ] || [] ).push( cb ); } },
		wp: { os: {
			windowManager: { getById: windowFor, getAll: () => Object.values( windows ) },
			apps: {
				dispatch: ( id, action, args, view ) => { dispatched.push( [ id, action, view ] ); return Promise.resolve( true ); },
				session: () => ( { state: { range: '30', class: 'human', compare: 'off' } } ),
			},
		} },
	};
	ctx.window = ctx;
	vm.createContext( ctx );
	vm.runInContext( readFileSync( join( HERE, '../../assets/os-kit.js' ), 'utf8' ), ctx );
	const land = ( appId, view, type = 'snt-land', stamp = '' ) => fire( 'os-app-effect', { detail: { appId, windowId: appId, view, effect: { type, land: stamp } } } );
	return { land, shown, dispatched };
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

scenario( 'every link is stamped, once, outside sn_*: two links to one leaf differ', () => {
	const { ctx, opened, remaps } = boot( { dashboard: true, analytics: true } );
	const url = ORIGIN + '/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=health';
	ctx.sntOpenStationPreferences.tryNativeRemap( url );
	ctx.sntOpenStationPreferences.tryNativeRemap( url );
	const a = ( opened[ 0 ] || [] )[ 1 ] || {};
	const b = ( opened[ 1 ] || [] )[ 1 ] || {};
	const viaShell = remaps.map( ( r ) => r.params( url, new URL( url ) ) );
	const stamped = ( p ) => p && typeof p.land === 'string' && p.land !== '';
	const ok = stamped( a.params ) && stamped( b.params ) && a.params.land !== b.params.land
		&& viaShell.length === 2 && viaShell.every( stamped ) && viaShell[ 0 ].land !== a.params.land;
	return { pass: ok, detail: JSON.stringify( { a, b, viaShell } ) };
} );

scenario( 'a #fragment rides as the anchor the classic page scrolls to', () => {
	const { ctx, opened } = boot( { dashboard: true, analytics: true } );
	ctx.sntOpenStationPreferences.tryNativeRemap( ORIGIN + '/wp-admin/admin.php?page=sn-theme-options&tab=site&sub=identity-and-seo#sn-sec-identity' );
	const p = ( ( opened[ 0 ] || [] )[ 1 ] || {} ).params || {};
	return { pass: p.tab === 'site' && p.sub === 'identity-and-seo' && p.anchor === 'sn-sec-identity', detail: JSON.stringify( p ) };
} );

scenario( 'snt-land shows the tab whose session landed, in that window', () => {
	const { land, shown } = bootKit();
	land( 'sn-dashboard', 'monitoring' );
	land( 'sn-dashboard', 'main' );
	return { pass: JSON.stringify( shown ) === JSON.stringify( [ [ 'sn-dashboard', 'monitoring' ], [ 'sn-dashboard', 'main' ] ] ), detail: JSON.stringify( shown ) };
} );

scenario( 'any other effect, or another app, shows nothing', () => {
	const { land, shown } = bootKit();
	land( 'sn-dashboard', 'monitoring', 'snt-paint' );
	land( 'other-app', 'monitoring' );
	return { pass: shown.length === 0, detail: JSON.stringify( shown ) };
} );

scenario( 'an analytics landing does not carry the old view over the link', () => {
	const { land, shown, dispatched } = bootKit();
	land( 'sn-analytics', 'machine-readers' );
	return { pass: JSON.stringify( shown ) === JSON.stringify( [ [ 'sn-analytics', 'machine-readers' ] ] ) && dispatched.length === 0, detail: JSON.stringify( { shown, dispatched } ) };
} );

scenario( 'a reload does not re-land: a stamp already shown in this tab shows nothing, a new one does', () => {
	const storage = new Map();
	const first = bootKit( storage );
	first.land( 'sn-dashboard', 'monitoring', 'snt-land', 's1' );
	const reload = bootKit( storage ); // same tab, page reloaded: the shell restores the window with the same params
	reload.land( 'sn-dashboard', 'monitoring', 'snt-land', 's1' );
	reload.land( 'sn-dashboard', 'connections', 'snt-land', 's2' );
	const ok = JSON.stringify( first.shown ) === JSON.stringify( [ [ 'sn-dashboard', 'monitoring' ] ] )
		&& JSON.stringify( reload.shown ) === JSON.stringify( [ [ 'sn-dashboard', 'connections' ] ] );
	return { pass: ok, detail: JSON.stringify( { first: first.shown, reload: reload.shown } ) };
} );

scenario( 'storage that throws still lands the link', () => {
	const { land, shown } = bootKit( null );
	land( 'sn-analytics', 'machine-readers', 'snt-land', 's1' );
	return { pass: JSON.stringify( shown ) === JSON.stringify( [ [ 'sn-analytics', 'machine-readers' ] ] ), detail: JSON.stringify( shown ) };
} );

process.stdout.write( JSON.stringify( { results } ) + '\n' );
process.exit( results.every( ( r ) => r.pass ) ? 0 : 1 );
