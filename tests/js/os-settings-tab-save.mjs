/**
 * assets/os-settings-tab.js: the tab's sections, its one save path, Restore
 * defaults and the test notification (2026-11-23).
 *
 * The script runs in a vm context with a fake shell and a fake DOM; each
 * scenario paints the tab, acts on it and prints one JSON line.
 * tests/os-settings-tab-save.php asserts them.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const SRC = readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), '../../assets/os-settings-tab.js' ), 'utf8' );
const DEFAULTS = { dashboard: true, analytics: true, mio_tips: true, mio_help: true, mio_look: false, alert_notify: true };

class El {
	constructor( tag ) { this.tag = tag; this.attrs = {}; this.children = []; this.listeners = {}; this.isConnected = true; this.textContent = ''; }
	setAttribute( k, v ) { this.attrs[ k ] = String( v ); }
	getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; }
	hasAttribute( k ) { return k in this.attrs; }
	removeAttribute( k ) { delete this.attrs[ k ]; }
	toggleAttribute( k, on ) { if ( on ) { this.attrs[ k ] = ''; } else { delete this.attrs[ k ]; } }
	append( ...kids ) { this.children.push( ...kids ); }
	appendChild( kid ) { this.children.push( kid ); return kid; }
	replaceChildren() { this.children = []; }
	addEventListener( type, cb ) { ( this.listeners[ type ] = this.listeners[ type ] || [] ).push( cb ); }
	fire( type, event = {} ) { ( this.listeners[ type ] || [] ).forEach( ( cb ) => cb( { target: this, ...event } ) ); }
	focus() {}
	all( out = [] ) { this.children.forEach( ( c ) => { out.push( c ); c.all( out ); } ); return out; }
}

function boot( prefs, { fail = false, answer = null } = {} ) {
	const posts = [];
	const notes = [];
	let refreshes = 0;
	let render;
	const ctx = {
		console, URL, Promise,
		location: { href: 'https://example.test/wp-admin/', origin: 'https://example.test' },
		sntOpenStationPreferences: { endpoint: '/prefs', preferences: prefs, defaults: DEFAULTS },
		requestAnimationFrame: ( cb ) => cb(),
		document: { readyState: 'complete', activeElement: null, addEventListener() {}, createElement: ( tag ) => new El( tag ) },
		wp: {
			i18n: { __: ( s ) => s },
			os: {
				registerNativeUrlRemap: () => {},
				registerSettingsTab: ( tab ) => { render = tab.render; },
				refreshMenu: () => { refreshes++; return Promise.resolve(); },
				notify: ( opts ) => { notes.push( opts ); },
				fetch: ( url, init ) => {
					const body = JSON.parse( init.body );
					posts.push( body );
					return Promise.resolve( { ok: ! fail, json: () => Promise.resolve( answer || { ...prefs, ...body } ) } );
				},
			},
		},
	};
	ctx.window = ctx;
	vm.createContext( ctx );
	vm.runInContext( SRC, ctx );
	const body = new El( 'body' );
	render( body );
	const paint = () => {
		const all = body.all();
		return {
			sections: all.filter( ( e ) => 'os-section' === e.tag && e.attrs.heading ).map( ( e ) => e.attrs.heading ),
			sw: ( key ) => all.find( ( e ) => 'os-checkbox-label' === e.tag && key === e.attrs.value ),
			button: ( text ) => all.find( ( e ) => 'os-button' === e.tag && text === e.textContent ),
			status: all.find( ( e ) => 'os-save-status' === e.tag ),
			checked: Object.fromEntries( all.filter( ( e ) => 'os-checkbox-label' === e.tag ).map( ( e ) => [ e.attrs.value, e.hasAttribute( 'checked' ) ] ) ),
		};
	};
	return { paint, posts, notes, refreshes: () => refreshes };
}

const settle = () => new Promise( ( r ) => setTimeout( r, 5 ) );
const results = [];
const scenario = async ( name, fn ) => {
	try {
		const r = await fn();
		results.push( { name, pass: !! r.pass, detail: r.detail } );
	} catch ( e ) {
		results.push( { name, pass: false, detail: 'threw: ' + ( e && e.message ) } );
	}
};

await scenario( 'three headed sections, each switch under its own', () => {
	const { paint } = boot( { ...DEFAULTS } );
	const p = paint();
	const ok = JSON.stringify( p.sections ) === JSON.stringify( [ 'Windows', 'MIO', 'Notifications' ] )
		&& JSON.stringify( Object.keys( p.checked ) ) === JSON.stringify( Object.keys( DEFAULTS ) )
		&& p.checked.alert_notify === true && p.checked.mio_look === false;
	return { pass: ok, detail: JSON.stringify( [ p.sections, p.checked ] ) };
} );

await scenario( 'a switch posts its one key and refreshes the menu', async () => {
	const { paint, posts, refreshes } = boot( { ...DEFAULTS } );
	paint().sw( 'alert_notify' ).fire( 'os-checkbox-change', { detail: { checked: false } } );
	await settle();
	const p = paint();
	const ok = JSON.stringify( posts ) === JSON.stringify( [ { alert_notify: false } ] ) && 1 === refreshes() && 'saved' === p.status.attrs.phase && 'Saved.' === p.status.attrs[ 'saved-label' ] && false === p.checked.alert_notify;
	return { pass: ok, detail: JSON.stringify( [ posts, refreshes(), p.status.attrs, p.checked ] ) };
} );

await scenario( 'a failed save puts the switch back and says so', async () => {
	const { paint, posts } = boot( { ...DEFAULTS }, { fail: true } );
	paint().sw( 'dashboard' ).fire( 'os-checkbox-change', { detail: { checked: false } } );
	await settle();
	const p = paint();
	const ok = 1 === posts.length && true === p.checked.dashboard && 'failed' === p.status.attrs.phase && 'Could not save preference.' === p.status.attrs.error && ! p.sw( 'dashboard' ).hasAttribute( 'disabled' );
	return { pass: ok, detail: JSON.stringify( [ p.status.attrs, p.checked ] ) };
} );

await scenario( 'Restore defaults posts the server list once and repaints from the answer', async () => {
	const { paint, posts, refreshes } = boot( { dashboard: false, analytics: false, mio_tips: false, mio_help: true, mio_look: true, alert_notify: false } );
	paint().button( 'Restore defaults' ).fire( 'click' );
	await settle();
	const p = paint();
	const ok = JSON.stringify( posts ) === JSON.stringify( [ DEFAULTS ] ) && 1 === refreshes() && JSON.stringify( p.checked ) === JSON.stringify( DEFAULTS ) && 'saved' === p.status.attrs.phase && 'Saved. MIO changes on reload.' === p.status.attrs[ 'saved-label' ];
	return { pass: ok, detail: JSON.stringify( [ posts, p.checked, p.status.attrs ] ) };
} );

await scenario( 'a second save while one is in flight is ignored', async () => {
	const { paint, posts } = boot( { ...DEFAULTS } );
	const p = paint();
	p.sw( 'analytics' ).fire( 'os-checkbox-change', { detail: { checked: false } } );
	p.button( 'Restore defaults' ).fire( 'click' );
	p.sw( 'mio_tips' ).fire( 'os-checkbox-change', { detail: { checked: false } } );
	await settle();
	return { pass: JSON.stringify( posts ) === JSON.stringify( [ { analytics: false } ] ), detail: JSON.stringify( posts ) };
} );

await scenario( 'the test notification has its own tag, saves nothing, and works with the switch off', async () => {
	const { paint, posts, notes } = boot( { ...DEFAULTS, alert_notify: false } );
	paint().button( 'Send a test notification' ).fire( 'click' );
	await settle();
	const ok = 1 === notes.length && 'signal-noise/test' === notes[ 0 ].tag && 'Signal & Noise' === notes[ 0 ].title && 0 === posts.length;
	return { pass: ok, detail: JSON.stringify( [ notes, posts ] ) };
} );

process.stdout.write( JSON.stringify( { results } ) + '\n' );
process.exit( results.every( ( r ) => r.pass ) ? 0 : 1 );
