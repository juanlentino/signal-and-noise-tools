/**
 * S&N Uptime status panel loader (v8.2.0; detail monitor v8.4.0).
 *
 * Populates every [data-sn-uptime-status] mount from ONE call to the
 * readonly signal-noise/uptime-status ability via sntAbilityRun. Two mount
 * flavors: plain status lists (the S&N Health widget section + the
 * Webhooks-tab rail) and [data-sn-uptime-detail] (the Analytics page
 * monitor: table with availability windows + response times, plus the
 * incidents log). If ANY detail mount is on the page the single call is
 * upgraded to detail=true and every mount is fed from the same payload.
 * Renders stay zero-cost server-side; the Better Stack round trips happen
 * behind the ability's tiered transients. All API-derived strings land via
 * textContent, never innerHTML.
 */
(function () {
	'use strict';

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	// "checked 2m ago" from an ISO stamp; '' when absent (heartbeats).
	function ago( iso ) {
		if ( ! iso ) {
			return '';
		}
		var s = Math.max( 0, ( Date.now() - Date.parse( iso ) ) / 1000 );
		if ( s < 90 ) {
			return 'checked ' + Math.round( s ) + 's ago';
		}
		if ( s < 5400 ) {
			return 'checked ' + Math.round( s / 60 ) + 'm ago';
		}
		return 'checked ' + Math.round( s / 3600 ) + 'h ago';
	}

	// 30d availability: trim trailing zeros so 100 reads "100%", 99.98 stays.
	function pct( n ) {
		return parseFloat( n.toFixed( 2 ) ) + '%';
	}

	function paint( mount, data ) {
		mount.textContent = '';

		if ( data.error ) {
			mount.appendChild( el( 'p', 'sn-uw-error', 'Better Stack unreachable: ' + data.error ) );
			return;
		}
		if ( ! data.configured ) {
			mount.appendChild( el( 'p', 'sn-uw-loading', 'No Better Stack API token configured.' ) );
			return;
		}
		if ( ! data.rows || ! data.rows.length ) {
			mount.appendChild( el( 'p', 'sn-uw-loading', 'No monitors or heartbeats yet.' ) );
			return;
		}

		var list = el( 'ul', 'sn-uw-list' );
		data.rows.forEach( function ( row ) {
			var item = el( 'li', 'sn-uw-row' );
			var name = el( 'span', 'sn-uw-name', row.name );
			var kind = 'heartbeat' === row.kind ? 'Heartbeat' : 'Monitor';
			var when = ago( row.checked_at );
			name.title = when ? kind + ' · ' + when : kind;
			item.appendChild( name );

			item.appendChild( el( 'span', 'sn-uw-pill sn-uw--' + ( row.level || 'warn' ), row.status ) );
			list.appendChild( item );
		} );
		mount.appendChild( list );

		var down = data.rows.filter( function ( r ) { return 'alert' === r.level; } ).length;
		mount.appendChild( el( 'p', 'sn-uw-meta', down ? down + ' down · Better Stack' : 'All systems go · Better Stack' ) );
	}

	// Duration like "12m" / "1h 04m" for the incidents log.
	function dur( s ) {
		if ( null === s || undefined === s ) {
			return '';
		}
		if ( s < 3600 ) {
			return Math.max( 1, Math.round( s / 60 ) ) + 'm';
		}
		var h = Math.floor( s / 3600 );
		var m = Math.round( ( s % 3600 ) / 60 );
		return h + 'h ' + ( m < 10 ? '0' : '' ) + m + 'm';
	}

	// The Analytics page monitor: per-resource table + incidents log (v8.4.0).
	function paintDetail( mount, data ) {
		mount.textContent = '';

		if ( data.error ) {
			mount.appendChild( el( 'p', 'sn-uw-error', 'Better Stack unreachable: ' + data.error ) );
			return;
		}
		if ( ! data.configured || ! data.rows || ! data.rows.length ) {
			mount.appendChild( el( 'p', 'sn-uw-loading', 'No monitors or heartbeats yet.' ) );
			return;
		}

		var table = el( 'table', 'sn-uw-table' );
		var thead = el( 'thead' );
		var hr = el( 'tr' );
		[ 'Monitor', 'Status', '30d', '90d', 'Resp.', 'Checked' ].forEach( function ( h, i ) {
			hr.appendChild( el( 'th', i > 0 ? 'sn-uw-num' : '', h ) );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		data.rows.forEach( function ( row ) {
			var tr = el( 'tr' );
			var name = el( 'td', '', row.name );
			name.title = 'heartbeat' === row.kind ? 'Heartbeat (push)' : 'HTTP monitor';
			tr.appendChild( name );
			var status = el( 'td', 'sn-uw-num' );
			status.appendChild( el( 'span', 'sn-uw-pill sn-uw--' + ( row.level || 'warn' ), row.status ) );
			tr.appendChild( status );
			var a30 = 'number' === typeof row.availability ? pct( row.availability ) : '—';
			var t30 = el( 'td', 'sn-uw-num', a30 );
			if ( row.incidents_30d ) {
				t30.title = row.incidents_30d + ' incident' + ( 1 === row.incidents_30d ? '' : 's' ) + ' in 30d';
			}
			tr.appendChild( t30 );
			tr.appendChild( el( 'td', 'sn-uw-num', 'number' === typeof row.availability_90d ? pct( row.availability_90d ) : '—' ) );
			tr.appendChild( el( 'td', 'sn-uw-num', 'number' === typeof row.response_ms ? row.response_ms + ' ms' : '—' ) );
			tr.appendChild( el( 'td', 'sn-uw-num sn-uw-quiet', ago( row.checked_at ).replace( 'checked ', '' ) || '—' ) );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		mount.appendChild( table );

		var head = el( 'p', 'sn-uw-head', 'Recent incidents' );
		mount.appendChild( head );
		if ( null === data.incidents || undefined === data.incidents ) {
			mount.appendChild( el( 'p', 'sn-uw-quiet sn-uw-meta', 'Incident log unavailable right now.' ) );
		} else if ( ! data.incidents.length ) {
			mount.appendChild( el( 'p', 'sn-uw-quiet sn-uw-meta', 'No recent incidents.' ) );
		} else {
			var log = el( 'ul', 'sn-uw-incidents' );
			data.incidents.forEach( function ( inc ) {
				var li = el( 'li', 'sn-uw-incident' + ( inc.ongoing ? ' sn-uw-incident--ongoing' : '' ) );
				var when = inc.started_at ? new Date( inc.started_at ).toLocaleString() : '';
				li.appendChild( el( 'span', 'sn-uw-inc-name', inc.name ) );
				li.appendChild( el( 'span', 'sn-uw-inc-detail',
					( inc.cause ? inc.cause : 'Incident' )
					+ ( when ? ' · ' + when : '' )
					+ ( inc.ongoing ? ' · ONGOING' : ( inc.duration_s ? ' · ' + dur( inc.duration_s ) : '' ) )
				) );
				log.appendChild( li );
			} );
			mount.appendChild( log );
		}
		mount.appendChild( el( 'p', 'sn-uw-meta', 'Availability over 30/90 days · response averaged over 24h · Better Stack' ) );
	}

	function boot() {
		var mounts = document.querySelectorAll( '[data-sn-uptime-status]' );
		if ( ! mounts.length || 'function' !== typeof window.sntAbilityRun ) {
			return;
		}
		var wantsDetail = document.querySelector( '[data-sn-uptime-detail]' ) !== null;
		var input = wantsDetail ? { detail: true } : undefined;
		window.sntAbilityRun( 'signal-noise/uptime-status', input ).then( function ( data ) {
			mounts.forEach( function ( m ) {
				if ( m.hasAttribute( 'data-sn-uptime-detail' ) ) {
					paintDetail( m, data || {} );
				} else {
					paint( m, data || {} );
				}
			} );
		} ).catch( function () {
			mounts.forEach( function ( m ) {
				m.textContent = '';
				m.appendChild( el( 'p', 'sn-uw-error', 'Better Stack status unavailable.' ) );
			} );
		} );
	}

	// v8.5.0: lazy detail — the Analytics "Uptime detail" panel fetches the
	// detail tier on FIRST expand (sn-an-panel-open dispatched by
	// assets/admin.js). The eager [data-sn-uptime-detail] path in boot()
	// still serves any surface that wants detail at load; the redesigned
	// Analytics page ships none, so page load costs the status tier only.
	document.addEventListener( 'sn-an-panel-open', function ( e ) {
		var mount = e.target.querySelector( '[data-sn-uptime-lazy-detail]' );
		if ( ! mount || mount.hasAttribute( 'data-sn-uptime-loaded' ) || 'function' !== typeof window.sntAbilityRun ) {
			return;
		}
		mount.setAttribute( 'data-sn-uptime-loaded', '1' );
		window.sntAbilityRun( 'signal-noise/uptime-status', { detail: true } ).then( function ( data ) {
			paintDetail( mount, data || {} );
		} ).catch( function () {
			mount.textContent = '';
			mount.appendChild( el( 'p', 'sn-uw-error', 'Better Stack status unavailable.' ) );
		} );
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
