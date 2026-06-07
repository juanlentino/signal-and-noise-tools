/**
 * Signal & Noise Tools — Heartbeat live-refresh (v4.9.0, Task 5).
 *
 * Piggybacks the WordPress Heartbeat API to live-refresh two admin tables
 * without a full reload:
 *   - Cron tab: ".sn-cron-last-fired" cells, matched to their row's
 *     data-hook, get their leading timestamp text patched.
 *   - Webhooks tab: each delivery-log "table[data-webhook-id]" gets its
 *     <tbody> re-rendered from the server payload (newest first).
 *
 * On heartbeat-send we attach data.sn_heartbeat = which of the two tables
 * are present on the page, so the server responder only does work for what
 * is visible (and only for manage_options users). We bump the heartbeat to
 * the 'fast' (5s) tick while the page is visible, and back to default when
 * hidden, so the tables feel live without hammering admin-ajax.
 *
 * All DOM writes use textContent + createElement (never innerHTML) to keep
 * the XSS surface at zero even though the data is server-derived — matches
 * assets/cron-dashboard.js.
 *
 * @since plugin v4.9.0
 */
( function () {
	'use strict';

	if ( typeof jQuery === 'undefined' ) {
		return;
	}
	var $ = jQuery;

	function hasCronTable() {
		return document.querySelector( '.sn-cron-last-fired' ) !== null;
	}

	function hasWebhookTables() {
		return document.querySelector( 'table[data-webhook-id]' ) !== null;
	}

	function wantList() {
		var want = [];
		if ( hasCronTable() ) {
			want.push( 'cron' );
		}
		if ( hasWebhookTables() ) {
			want.push( 'webhooks' );
		}
		return want;
	}

	// ── heartbeat-send: declare which tables we care about. ──────────────
	$( document ).on( 'heartbeat-send', function ( event, data ) {
		var want = wantList();
		if ( want.length ) {
			data.sn_heartbeat = want;
		}
	} );

	// ── heartbeat-tick: patch the DOM from the server payload. ───────────
	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( data.sn_cron_last_fired ) {
			patchCron( data.sn_cron_last_fired );
		}
		if ( data.sn_webhook_logs ) {
			patchWebhooks( data.sn_webhook_logs );
		}
	} );

	/**
	 * Patch each cron row's last-fired cell. We only replace the cell's
	 * LEADING text node (the timestamp) so the <small> relative label, the
	 * history toggle button, and the history panel are left intact.
	 */
	function patchCron( map ) {
		var rows = document.querySelectorAll( 'tr[data-hook]' );
		rows.forEach( function ( row ) {
			var hook = row.getAttribute( 'data-hook' );
			if ( ! Object.prototype.hasOwnProperty.call( map, hook ) ) {
				return;
			}
			var entry = map[ hook ];
			var cell = row.querySelector( '.sn-cron-last-fired' );
			if ( ! cell ) {
				return;
			}
			var text = entry && entry.formatted ? entry.formatted : '—';
			// Find the first text node; replace its value (or insert one).
			var firstText = null;
			for ( var i = 0; i < cell.childNodes.length; i++ ) {
				if ( cell.childNodes[ i ].nodeType === Node.TEXT_NODE ) {
					firstText = cell.childNodes[ i ];
					break;
				}
			}
			if ( firstText ) {
				firstText.nodeValue = text;
			} else {
				cell.insertBefore( document.createTextNode( text ), cell.firstChild );
			}
		} );
	}

	/**
	 * Re-render each webhook delivery-log table body from the payload.
	 * Rows render newest-first (the server sends oldest-first, capped 20).
	 */
	function patchWebhooks( logs ) {
		var tables = document.querySelectorAll( 'table[data-webhook-id]' );
		tables.forEach( function ( table ) {
			var id = table.getAttribute( 'data-webhook-id' );
			if ( ! Object.prototype.hasOwnProperty.call( logs, id ) ) {
				return;
			}
			var tbody = table.querySelector( 'tbody' );
			if ( ! tbody ) {
				return;
			}
			var rows = logs[ id ] || [];
			// Clear existing rows.
			while ( tbody.firstChild ) {
				tbody.removeChild( tbody.firstChild );
			}
			rows
				.slice()
				.reverse()
				.forEach( function ( entry ) {
					tbody.appendChild( buildWebhookRow( entry ) );
				} );
		} );
	}

	function buildWebhookRow( entry ) {
		var tr = document.createElement( 'tr' );
		// Fix D (T5): prefer the server's site-TZ formatted string (mirrors the
		// cron path); fall back to the browser-local format only if absent.
		appendCell( tr, entry.fired_at_formatted ? entry.fired_at_formatted : formatTs( entry.fired_at ) );
		appendCell( tr, String( entry.attempt != null ? entry.attempt : '' ) );
		appendCell( tr, String( entry.response_code != null ? entry.response_code : '' ) );

		var statusTd = document.createElement( 'td' );
		var pill = document.createElement( 'span' );
		pill.className = 'sn-pill ' + ( entry.success ? 'sn-pill--ok' : 'sn-pill--warn' );
		pill.textContent = entry.success ? 'ok' : 'fail';
		statusTd.appendChild( pill );
		tr.appendChild( statusTd );

		var respTd = document.createElement( 'td' );
		var code = document.createElement( 'code' );
		code.textContent = entry.response_excerpt != null ? String( entry.response_excerpt ) : '';
		respTd.appendChild( code );
		tr.appendChild( respTd );

		return tr;
	}

	function appendCell( tr, text ) {
		var td = document.createElement( 'td' );
		td.textContent = text;
		tr.appendChild( td );
	}

	function formatTs( ts ) {
		if ( ! ts ) {
			return '';
		}
		// Server already caps + provides unix seconds; render in local time.
		var d = new Date( ts * 1000 );
		if ( isNaN( d.getTime() ) ) {
			return String( ts );
		}
		function pad( n ) {
			return n < 10 ? '0' + n : '' + n;
		}
		return (
			d.getFullYear() +
			'-' +
			pad( d.getMonth() + 1 ) +
			'-' +
			pad( d.getDate() ) +
			' ' +
			pad( d.getHours() ) +
			':' +
			pad( d.getMinutes() ) +
			':' +
			pad( d.getSeconds() )
		);
	}

	// ── Speed up the heartbeat while the page is visible. ────────────────
	function applyInterval() {
		if ( typeof wp === 'undefined' || ! wp.heartbeat ) {
			return;
		}
		if ( ! wantList().length ) {
			return;
		}
		if ( document.hidden ) {
			wp.heartbeat.interval( 'standard' );
		} else {
			wp.heartbeat.interval( 'fast' );
		}
	}

	$( function () {
		applyInterval();
	} );
	document.addEventListener( 'visibilitychange', applyInterval );
} )();
