/**
 * Signal & Noise Tools: an alert, as a system notification in the app.
 *
 * The hourly alert run stores its headline (inc/alerts-notice.php). This shows
 * it once per device through wp.os.notify, which falls back to a toast when
 * notifications are not allowed. OpenStation v1 has no Web Push, so this runs
 * only while the app is open; the email covers a closed app.
 *
 * @package SignalNoiseTools
 */
( function () {
	'use strict';
	var KEY = 'sntAlertNoticeSeen';
	var EVERY_MS = 15 * 60 * 1000; // the alert run is hourly; four reads an hour.
	var data = window.snDesktopData || {};
	// Only in the shell's own document. A classic window is an iframe with no
	// wp.os of its own; without this guard every open window would poll.
	if ( ! window.wp || ! window.wp.os || typeof window.wp.os.notify !== 'function' ) {
		return;
	}

	function seen() {
		try { return parseInt( window.localStorage.getItem( KEY ) || '0', 10 ) || 0; } catch ( e ) { return 0; }
	}
	function show( n ) {
		var os = window.wp && window.wp.os;
		if ( ! n || ! n.id || ! n.title || n.id <= seen() || ! os || typeof os.notify !== 'function' ) {
			return false;
		}
		try { window.localStorage.setItem( KEY, String( n.id ) ); } catch ( e ) {}
		os.notify( {
			title: String( n.title ),
			body: String( n.body || '' ),
			tag: 'signal-noise/alert',
			onClick: function ( note ) {
				window.focus();
				if ( typeof os.openWindow === 'function' ) { os.openWindow( n.app || 'sn-analytics' ); }
				if ( note && typeof note.close === 'function' ) { note.close(); }
			}
		} );
		return true;
	}
	function poll() {
		if ( ! window.wp || ! window.wp.apiFetch ) { return; }
		window.wp.apiFetch( { path: '/' + ( data.restNamespace || 'signal-noise/v1' ) + '/desktop/alert-notice' } )
			.then( function ( r ) { show( r && r.notice ); } )
			.catch( function () {} ); // a failed read is retried on the next tick.
	}

	window.sntAlertNotify = { show: show };
	show( data.alertNotice );
	window.setInterval( poll, EVERY_MS );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) { poll(); }
	} );
}() );
