/**
 * S&N Uptime status panel loader (v8.2.0; availability enrichment v8.3.0).
 *
 * Populates every [data-sn-uptime-status] mount (the Uptime section of the
 * S&N Health widget + the Webhooks-tab rail) from ONE call to the readonly
 * signal-noise/uptime-status ability via sntAbilityRun — renders stay
 * zero-cost server-side; the Better Stack round trip happens here, backed
 * by server transients (90s statuses, 1h availability). All API-derived
 * strings land via textContent, never innerHTML.
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

			// v8.3.0: 30-day availability, quiet, availability=null degrades
			// to statuses-only (the summary endpoints failing soft server-side).
			if ( 'number' === typeof row.availability ) {
				var avail = el( 'span', 'sn-uw-avail', pct( row.availability ) );
				avail.title = '30-day availability'
					+ ( row.incidents_30d ? ' · ' + row.incidents_30d + ' incident' + ( 1 === row.incidents_30d ? '' : 's' ) : ' · no incidents' );
				item.appendChild( avail );
			}

			item.appendChild( el( 'span', 'sn-uw-pill sn-uw--' + ( row.level || 'warn' ), row.status ) );
			list.appendChild( item );
		} );
		mount.appendChild( list );

		var down = data.rows.filter( function ( r ) { return 'alert' === r.level; } ).length;
		var incidents = data.rows.reduce( function ( sum, r ) { return sum + ( r.incidents_30d || 0 ); }, 0 );
		var meta = down ? down + ' down · Better Stack' : 'All systems go · Better Stack';
		if ( ! down && incidents ) {
			meta = incidents + ' incident' + ( 1 === incidents ? '' : 's' ) + ' in 30d · Better Stack';
		}
		mount.appendChild( el( 'p', 'sn-uw-meta', meta ) );
	}

	function boot() {
		var mounts = document.querySelectorAll( '[data-sn-uptime-status]' );
		if ( ! mounts.length || 'function' !== typeof window.sntAbilityRun ) {
			return;
		}
		window.sntAbilityRun( 'signal-noise/uptime-status' ).then( function ( data ) {
			mounts.forEach( function ( m ) { paint( m, data || {} ); } );
		} ).catch( function () {
			mounts.forEach( function ( m ) {
				m.textContent = '';
				m.appendChild( el( 'p', 'sn-uw-error', 'Better Stack status unavailable.' ) );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
