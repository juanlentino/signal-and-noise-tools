/**
 * Signal & Noise Tools — desktop-mode "SN Anchors" widget.
 *
 * v9.78.0. The one glanceable that had no Desktop Mode mirror: provenance
 * anchor state. Pending Notes render with their live in-flight Bitcoin
 * transaction (confirmations N/6, captured by the worker's pending
 * callbacks); a Sweep button runs the worker's upgrade sweep on demand;
 * the idle state is an honest "N notes anchored".
 *
 * MOUNT CONTRACT: assigned to window.desktopModeWidgets[ id ] — see
 * desktop-mode-widget-views.js for the full note on why this is the right
 * path for a PHP-declared widget.
 *
 * DATA: the anchor-status ability (fetch-on-render — the aggregate walks
 * every Note's chain meta and must never ride a page-load localize), and
 * the anchor-sweep ability for the action. Both via the shared run-path.
 *
 * Contract (snt_ability_anchor_status): { pending: [ { post_id, title,
 * version, bitcoin_txid, confirmations|null } ], confirmed, total }.
 * `confirmations: null` is "not recorded", never rendered as 0/6.
 *
 * @since plugin v9.78.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	window.desktopModeWidgets = window.desktopModeWidgets || {};

	var data         = window.snDesktopData || {};
	var dashboardUrl = ( data.pages && data.pages.dashboard ) || '';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		if ( opts.title != null ) { node.title = opts.title; }
		return node;
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	function shortTx( txid ) {
		return txid ? String( txid ).slice( 0, 10 ) + '…' : '';
	}

	window.desktopModeWidgets[ 'sn-anchors' ] = function( container ) {
		function render( overview, note ) {
			clearChildren( container );
			var wrap = el( 'div', {
				style: 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;padding:14px 16px;color:inherit;font-size:13px;line-height:1.5;',
			} );

			var pending   = ( overview && overview.pending ) || [];
			var confirmed = overview ? Number( overview.confirmed ) || 0 : 0;
			var total     = overview ? Number( overview.total ) || 0 : 0;

			if ( ! overview ) {
				wrap.appendChild( el( 'p', { style: 'margin:0;opacity:.7;', text: note || 'Anchor status unavailable.' } ) );
			} else if ( ! pending.length ) {
				// The honest idle state — this is what the widget shows most days.
				wrap.appendChild( el( 'p', {
					style: 'margin:0;font-weight:600;color:#3fb950;',
					text:  '✓ ' + confirmed + ' of ' + total + ' notes anchored',
				} ) );
				wrap.appendChild( el( 'p', {
					style: 'margin:4px 0 0;font-size:11px;opacity:.6;',
					text:  'No anchors pending.',
				} ) );
			} else {
				wrap.appendChild( el( 'p', {
					style: 'margin:0 0 6px;font-weight:600;color:#d29922;',
					text:  pending.length + ' pending · ' + confirmed + ' anchored',
				} ) );
				pending.forEach( function( row ) {
					var line = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
					line.appendChild( el( 'span', {
						text:  ( row.title || ( '#' + row.post_id ) ) + ' v' + row.version,
						title: row.title || '',
						style: 'opacity:.75;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;',
					} ) );
					var stat = null === row.confirmations || undefined === row.confirmations
						? ( row.bitcoin_txid ? shortTx( row.bitcoin_txid ) : 'awaiting tx' )
						: row.confirmations + '/6';
					line.appendChild( el( 'span', {
						text:  stat,
						title: row.bitcoin_txid || '',
						style: 'font-variant-numeric:tabular-nums;font-weight:600;color:#d29922;flex:0 0 auto;',
					} ) );
					wrap.appendChild( line );
				} );
			}

			if ( note && overview ) {
				wrap.appendChild( el( 'p', { style: 'margin:8px 0 0;font-size:11px;opacity:.7;', text: note } ) );
			}

			var actions = el( 'div', { style: 'margin-top:10px;display:flex;gap:12px;align-items:center;' } );
			var sweepBtn = el( 'button', { text: 'Sweep now' } );
			sweepBtn.type = 'button';
			sweepBtn.setAttribute( 'style', 'font:inherit;font-size:11px;padding:2px 10px;border-radius:5px;border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit;cursor:pointer;min-height:24px;' );
			sweepBtn.addEventListener( 'click', function() {
				if ( ! window.sntAbilityRun ) {
					return;
				}
				sweepBtn.disabled = true;
				sweepBtn.textContent = 'Sweeping…';
				window.sntAbilityRun( 'anchor-sweep', {} ).then( function( res ) {
					var msg = res && res.ok
						? res.upgraded + ' upgraded, ' + res.still_pending + ' still pending.'
						: 'Sweep could not run (' + ( ( res && res.error ) || 'unknown' ) + ').';
					load( msg );
				} ).catch( function( err ) {
					load( 'Sweep failed: ' + ( ( err && err.message ) || 'unknown error' ) );
				} );
			} );
			actions.appendChild( sweepBtn );
			if ( dashboardUrl ) {
				actions.appendChild( el( 'a', {
					style: 'font-size:11px;color:#4a9eff;text-decoration:none;',
					text:  'Open Dashboard →',
					href:  dashboardUrl,
				} ) );
			}
			wrap.appendChild( actions );
			container.appendChild( wrap );
		}

		function load( note ) {
			if ( ! window.sntAbilityRun ) {
				render( null, 'The abilities client is unavailable.' );
				return;
			}
			window.sntAbilityRun( 'anchor-status', {} ).then( function( overview ) {
				render( overview, note );
			} ).catch( function( err ) {
				render( null, ( err && err.message ) || 'Could not load anchor status.' );
			} );
		}

		load();
	};
} )();
