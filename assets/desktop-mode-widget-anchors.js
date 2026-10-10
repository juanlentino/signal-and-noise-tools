/**
 * Signal & Noise Tools — desktop-mode "SN Provenance" widget (id sn-anchors,
 * kept so the card keeps its place on a saved desktop). SN Anchors with SN
 * Machine Readers' glance folded in under it.
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
 * version, bitcoin_txid, confirmations|null } ], recording: [ { post_id,
 * title, version } ], confirmed, total }.
 * `confirmations: null` is "not recorded", never rendered as 0/6.
 *
 * @since plugin v9.78.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	// v10.43.0 — OpenStation rename compat (REJECT #11 MEDIUM fix): a
	// SELF-SUFFICIENT alias, not order-dependent on the external
	// assets/desktop-mode-os-compat.js prelude. openstation_resolve_script_payload()
	// (upstream payload.php:1371-1449) resolves only the handle's own src,
	// never walks deps, and server-sync/command-sync inject one bare
	// <script src="..."> tag per URL — so under a post-#475 mid-session
	// shell activation this file can run BEFORE the external prelude ever
	// does. Merge, don't clobber: if both globals already exist and differ
	// (a genuine race), copy the loser's keys into the survivor first.
	// Survivor = window.openStationWidgets, the name upstream itself reads
	// post-#475 (src/widgets/server-sync.ts) — see docs/openstation-compat.md.
	var __osWidgets = window.openStationWidgets || window.desktopModeWidgets || {};
	if ( window.desktopModeWidgets && window.desktopModeWidgets !== __osWidgets ) {
		for ( var __osKey in window.desktopModeWidgets ) {
			if ( ! ( __osKey in __osWidgets ) ) { __osWidgets[ __osKey ] = window.desktopModeWidgets[ __osKey ]; }
		}
	}
	window.desktopModeWidgets = window.openStationWidgets = __osWidgets;

	var data         = window.snDesktopData || {};
	// 2026-10-05: signatures, rights evidence and DOIs (inc/desktop-mode-status-extra.php), owner only.
	var provExtra    = ( data.statusExtra && data.statusExtra.provenance ) || {};
	var dashboardUrl = ( data.pages && ( data.pages.provenance || data.pages.dashboard ) ) || ''; // 15.8.2: Tools › Provenance
	var readersUrl   = ( data.pages && data.pages.machine_readers ) || '';
	var SUBTLE       = 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));';

	/**
	 * A change, said twice: the arrow for the eye (aria-hidden) and the
	 * direction in words for a screen reader. Null for no change.
	 */
	function changeNode( d, unit ) {
		if ( typeof d !== 'number' || ! d ) { return null; }
		var wrap  = el( 'span', { style: 'margin-left:4px;' + SUBTLE } );
		var arrow = el( 'span', { text: d > 0 ? '▲' : '▼' } );
		arrow.setAttribute( 'aria-hidden', 'true' );
		var words = el( 'span', { text: d > 0 ? 'up' : 'down' } );
		words.className = 'screen-reader-text';
		wrap.appendChild( arrow );
		wrap.appendChild( words );
		wrap.appendChild( el( 'span', { text: ' ' + Math.abs( d ) + ( unit || '' ) } ) );
		return wrap;
	}

	/** A label/value row as a listitem; `amber` tones the value; `extra` follows it. */
	function listRow( label, value, amber, extra ) {
		var line = el( 'div', { style: 'display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;column-gap:8px;padding:2px 0;font-size:11px;' } );
		line.setAttribute( 'role', 'listitem' );
		line.appendChild( el( 'span', { text: label, style: 'min-width:0;' + SUBTLE } ) );
		var val = el( 'span', { text: value, style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 1 auto;min-width:0;white-space:normal;overflow-wrap:break-word;text-align:right;' + ( amber ? 'color:#d29922;' : '' ) } );
		if ( extra ) { val.appendChild( extra ); }
		line.appendChild( val );
		return line;
	}

	/**
	 * The machine half of provenance, from SN Machine Readers (folded into this
	 * card): how many machine reads, who the edge vouched for, and the declared
	 * AI-training reads. A sensor that did not answer is one line, never a zero.
	 */
	function readersBox( mr ) {
		var box  = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );
		var head = el( 'div', { text: 'Machine readers · last ' + ( Number( mr.days ) || 30 ) + ' days', style: 'font-size:11px;margin-bottom:2px;' + SUBTLE } );
		head.setAttribute( 'role', 'heading' );
		head.setAttribute( 'aria-level', '3' );
		box.appendChild( head );
		if ( ! mr.ok ) {
			var hint = mr.hint && mr.hint.title ? mr.hint.title : ( 'not_configured' === mr.error ? 'Sensor not configured' : 'Sensor unreachable' );
			box.appendChild( el( 'p', { style: 'margin:0;font-size:11px;' + SUBTLE, text: hint + '.' } ) );
			return box;
		}
		var list = el( 'div' );
		list.setAttribute( 'role', 'list' );
		var reads = listRow( 'Machine reads', String( Number( mr.total ) || 0 ) );
		list.appendChild( reads );
		// A user agent is a claim; only the verified share is vouched for by the edge.
		var id = mr.edge_verified; // its own key: `identity` is the summary's signature evidence
		var sum = id ? id.verified + id.unverified + id.not_measured : 0;
		if ( id && sum > 0 ) {
			// The three parts as a share bar under Machine reads, each row with its
			// segment's dot (assets/desktop-mode-card-kit.js); without the kit, the rows alone.
			var kit   = window.sntCardKit;
			var parts = [ [ 'Verified by Cloudflare', id.verified ], [ 'Named themselves, not verified', id.unverified ], [ 'Not measured', id.not_measured ] ];
			var bar   = kit ? kit.bar( parts.map( function( p ) { return 100 * p[1] / sum; } ) ) : null;
			if ( bar ) { reads.appendChild( bar ); }
			parts.forEach( function( p, i ) {
				if ( 2 === i && ! ( p[1] > 0 ) ) { return; }
				var row = listRow( p[0], String( p[1] ) );
				if ( bar ) { row.firstChild.insertBefore( kit.dot( i ), row.firstChild.firstChild ); }
				list.appendChild( row );
			} );
		}
		// null means "not measured", never painted as 0.
		if ( mr.ai_training !== null && typeof mr.ai_training !== 'undefined' ) {
			list.appendChild( listRow( 'Declared AI-training reads', String( mr.ai_training ) ) );
			// A crawler that went looking for the declarations on purpose. The
			// reservation rides every response, so 0 here is healthy, never an alarm.
			if ( mr.ai_rights !== null && typeof mr.ai_rights !== 'undefined' ) {
				list.appendChild( listRow( 'Fetched the rights files directly', String( mr.ai_rights ) ) );
			}
		}
		// The top crawler family's share of the window's reads, with its change
		// in points against the prior 30 days when that window was read. Absent
		// when there are no reads: never a 0%.
		var top = mr.top_family;
		if ( top && top.family && typeof top.share === 'number' ) {
			list.appendChild( listRow( 'Top crawler family', top.family + ', ' + top.share + '%', false, typeof top.prior_share === 'number' ? changeNode( top.share - top.prior_share, ' pts' ) : null ) );
		}
		box.appendChild( list );
		// Crawler-list drift stays loud: one amber line, only when the verdict is
		// not the healthy 'in sync' ('drift' | 'check failed'; null is unknown).
		if ( mr.crawler_list && 'in sync' !== mr.crawler_list ) {
			box.appendChild( el( 'p', { style: 'margin:2px 0 0;font-size:11px;color:#d29922;', text: 'Crawler list ' + String( mr.crawler_list ) } ) );
		}
		return box;
	}

	/** "3d ago" from a UNIX time in seconds, the form SN Systems uses; '' when absent. */
	function agoWords( ts ) {
		var secs = Number( ts );
		if ( ! secs || isNaN( secs ) || secs <= 0 ) { return ''; }
		var mins = Math.max( 0, Math.floor( ( Date.now() - secs * 1000 ) / 60000 ) );
		if ( mins < 60 ) { return mins + 'm ago'; }
		var hrs = Math.floor( mins / 60 );
		if ( hrs < 24 ) { return hrs + 'h ago'; }
		return Math.floor( hrs / 24 ) + 'd ago';
	}

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
		// Official mount contract: mount(container, ctx) → teardown. This
		// widget returned nothing (audit finding 2026-08-17), so disabling it
		// mid-session left its DOM painted and in-flight ability calls
		// rendering into a dead card. The torn flag gates every async render.
		var torn = false;
		// 21.1.0: the Internet Archive line (archive-status). A second, separate
		// read: the anchors paint without it, and a failed read paints nothing.
		var archive = null;
		// SN Provenance: the machine readers (GET desktop/machine-readers, the
		// route that carries the edge's identity split). A third separate read.
		var readers = null;
		var loading = el( 'p', { style: 'margin:0;padding:14px 16px;font-size:12px;' + SUBTLE, text: 'Loading…' } );
		loading.setAttribute( 'role', 'status' );
		container.appendChild( loading );

		// `waiting`: anchor-status has not answered yet. The anchor part then
		// says it is loading (no alert, no Sweep) while the readers paint.
		var LIST_CAP = 2;

		function render( overview, note, waiting ) {
			// A list that grows (notes being minted, anchors pending) shows two rows
			// and a count of the rest, so a busy day never pushes the buttons out.
			function moreLine( n, what ) {
				if ( n <= LIST_CAP ) { return; }
				wrap.appendChild( el( 'p', { style: 'margin:0 0 4px;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));', text: '+' + ( n - LIST_CAP ) + ' more ' + what + ' in Provenance' } ) );
			}

			if ( torn ) {
				return;
			}
			// The rebuild detaches whatever had focus; put it back on the button.
			var hadFocus = !! ( document.activeElement && container.contains( document.activeElement ) );
			var onLink   = hadFocus && 'A' === document.activeElement.tagName ? document.activeElement.textContent : '';
			clearChildren( container );
			var wrap = el( 'div', {
				style: 'padding:14px 16px;color:inherit;font-size:13px;line-height:1.5;',
			} );

			var pending   = ( overview && overview.pending ) || [];
			var recording = ( overview && overview.recording ) || []; // v14.6.2: minted, not yet at the Worker.
			var confirmed = overview ? Number( overview.confirmed ) || 0 : 0;
			var total     = overview ? Number( overview.total ) || 0 : 0;
			// 19.2.0: signed Pages, counted apart from Notes.
			var pages     = ( overview && overview.pages ) || { confirmed: 0, total: 0 };
			var pagesLine = Number( pages.total ) > 0
				? ( Number( pages.confirmed ) || 0 ) + ' of ' + Number( pages.total ) + ' pages anchored'
				: '';

			if ( waiting ) {
				var wait = el( 'p', { style: 'margin:0;' + SUBTLE, text: 'Loading anchor status…' } );
				wait.setAttribute( 'role', 'status' );
				wrap.appendChild( wait );
			} else if ( ! overview ) {
				var down = el( 'p', { style: 'margin:0;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));', text: note || 'Anchor status unavailable.' } );
				down.setAttribute( 'role', 'alert' );
				wrap.appendChild( down );
			} else if ( ! pending.length && ! recording.length ) {
				// The honest idle state — this is what the widget shows most days.
				// One line for "all good"; the detail rows return when something is pending.
				var allPages = Number( pages.total ) > 0 ? ', ' + Number( pages.total ) + ' pages' : '';
				var allDone  = confirmed === total && ( Number( pages.confirmed ) || 0 ) === ( Number( pages.total ) || 0 );
				// Nothing signed is not "all anchored": no green claim over an empty corpus.
				var none     = 0 === total && 0 === ( Number( pages.total ) || 0 );
				wrap.appendChild( el( 'p', {
					style: none ? 'margin:0;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));' : 'margin:0;font-weight:600;color:#3fb950;',
					text:  none ? 'No signed notes or pages yet.' : allDone
						? '✓ All anchored: ' + total + ' notes' + allPages
						: '✓ ' + confirmed + ' of ' + total + ' notes anchored' + ( pagesLine ? ', ' + pagesLine : '' ),
				} ) );
			} else {
				var parts = [];
				if ( recording.length ) { parts.push( recording.length + ' recording' ); }
				if ( pending.length )   { parts.push( pending.length + ' pending' ); }
				wrap.appendChild( el( 'p', {
					style: 'margin:0 0 6px;font-weight:600;color:#d29922;',
					text:  parts.join( ' · ' ) + ' · ' + confirmed + ' of ' + total + ' notes anchored',
				} ) );
				if ( pagesLine ) {
					wrap.appendChild( el( 'p', {
						style: 'margin:-4px 0 6px;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));',
						text:  pagesLine,
					} ) );
				}
				// A freshly minted version: the commit exists, the Worker has not
				// answered yet. Nothing to poll; the settle window does the work.
				recording.slice( 0, LIST_CAP ).forEach( function( row ) {
					var line = el( 'div', { style: 'display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;column-gap:8px;padding:2px 0;font-size:11px;' } );
					line.appendChild( el( 'span', {
						text:  ( 'page' === row.type ? 'Page: ' : '' ) + ( row.title || ( '#' + row.post_id ) ) + ' v' + row.version,
						style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.75));min-width:0;white-space:normal;overflow-wrap:break-word;',
					} ) );
					line.appendChild( el( 'span', {
						text:  'recording',
						style: 'font-weight:600;color:#d29922;flex:0 0 auto;',
					} ) );
					wrap.appendChild( line );
				} );
				moreLine( recording.length, 'recording' );
				if ( recording.length ) {
					wrap.appendChild( el( 'p', {
						style: 'margin:0 0 4px;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));',
						text:  'Recording: committed locally; the anchor dispatch has not reached the Worker yet.',
					} ) );
				}
				pending.slice( 0, LIST_CAP ).forEach( function( row ) {
					var line = el( 'div', { style: 'display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;column-gap:8px;padding:2px 0;font-size:11px;' } );
					line.appendChild( el( 'span', {
						text:  ( 'page' === row.type ? 'Page: ' : '' ) + ( row.title || ( '#' + row.post_id ) ) + ' v' + row.version,
						style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.75));min-width:0;white-space:normal;overflow-wrap:break-word;',
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
				moreLine( pending.length, 'pending' );
			}

			if ( archive && overview ) {
				// Two rows, not a sentence: the run over the notes, and what the
				// Archive has confirmed. A halted run and a failed capture are amber.
				var run      = archive.run || {};
				var caps     = archive.captures || {};
				var asked    = Number( run.asked ) || 0;
				var pendingN = Number( archive.pending ) || 0;
				var halted   = 'halted' === run.state;
				// Each state says what it is: a run that never started or has
				// finished must not read like one in progress.
				var runText = ! archive.configured ? 'not configured'
					: halted ? 'halted' + ( run.reason ? ': ' + run.reason : '' ) + ( pendingN > 0 ? ' · ' + pendingN + ' to go' : '' )
					: 'running' === run.state ? asked + ' asked · ' + pendingN + ' to go'
					: pendingN > 0 ? pendingN + ' not pushed yet'
					: 'every note asked';
				var failedN  = Number( caps.failed ) || 0;
				var silentN  = Number( caps.unconfirmed ) || 0; // past the cutoff with no answer: not waiting any more.
				var capText  = ( Number( caps.captured ) || 0 ) + ' confirmed · ' + ( Number( caps.waiting ) || 0 ) + ' waiting'
					+ ( silentN > 0 ? ' · ' + silentN + ' no answer' : '' ) + ( failedN > 0 ? ' · ' + failedN + ' failed' : '' );
				var rows = [ [ 'Internet Archive', runText, halted || ! archive.configured ] ];
				if ( archive.configured ) { rows.push( [ 'Captures', capText, failedN > 0 || silentN > 0 ] ); }
				var box = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );
				rows.forEach( function( r ) {
					var line = el( 'div', { style: 'display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;column-gap:8px;padding:2px 0;font-size:11px;' } );
					line.appendChild( el( 'span', { text: r[0], style: 'color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));' } ) );
					line.appendChild( el( 'span', { text: r[1], style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 1 auto;min-width:0;white-space:normal;overflow-wrap:break-word;text-align:right;' + ( r[2] ? 'color:#d29922;' : '' ) } ) );
					box.appendChild( line );
				} );
				// The rows above already say what the line says, except when the run
				// is halted (the reason) or Archive is not configured (the setup step).
				if ( archive.line && ( halted || ! archive.configured ) ) {
					box.appendChild( el( 'p', { style: 'margin:2px 0 0;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));', text: archive.line } ) );
				}
				wrap.appendChild( box );
			}

			// The provenance record beyond the anchors: what verifies, where the
			// rights-evidence ledger stands, and the DOIs. Each row only when its
			// source answered; unknown is left out, never painted as zero.
			var prow = [];
			var integ = provExtra.integrity;
			if ( integ && Number( integ.fleet ) > 0 ) {
				// The sweep's checks (hash, twin, ledger, key), not a signature re-verify:
				// so "pass", and a subject not reached yet is said, never counted as passing.
				var unchecked = Number( integ.fleet ) - Number( integ.checked );
				prow.push( [ 'Integrity checks', Number( integ.clean ) + ' of ' + Number( integ.fleet ) + ' pass' + ( Number( integ.failing ) > 0 ? ' · ' + Number( integ.failing ) + ' failing' : '' ) + ( Number( integ.unreachable ) > 0 ? ' · ' + Number( integ.unreachable ) + ' unreachable' : '' ) + ( unchecked > 0 ? ' · ' + unchecked + ' not checked yet' : '' ), Number( integ.failing ) > 0 || !! integ.keys ] );
				// A fleet-level key finding is said on its own row: no subject can pass it away.
				var KEY_WORDS = { key_mismatch: 'the published key does not match', keys_missing: 'the key file is missing', keys_unreachable: 'the key file could not be read' };
				if ( integ.keys ) { prow.push( [ 'Signing key', KEY_WORDS[ integ.keys ] || String( integ.keys ), true ] ); }
			}
			var rights = provExtra.rights;
			if ( rights && rights.text ) {
				prow.push( [ 'Rights evidence · ' + String( rights.month ), String( rights.text ), !! rights.attention ] );
				var postedAgo = agoWords( rights.last_posted );
				if ( postedAgo ) { prow.push( [ 'Last posted', postedAgo, false ] ); }
			}
			var zen = provExtra.zenodo;
			if ( zen && Number( zen.total ) > 0 ) {
				prow.push( [ 'DOIs', Number( zen.minted ) + ' of ' + Number( zen.total ) + ' minted', false ] );
			}
			if ( prow.length ) {
				var pbox = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid var(--os-ui-color-border, rgba(255,255,255,0.12));' } );
				var phead = el( 'div', { text: 'Provenance', style: 'font-size:11px;margin-bottom:2px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));' } );
				phead.setAttribute( 'role', 'heading' );
				phead.setAttribute( 'aria-level', '3' );
				pbox.appendChild( phead );
				prow.forEach( function( r ) {
					var line = el( 'div', { style: 'display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;column-gap:8px;padding:2px 0;font-size:11px;' } );
					line.appendChild( el( 'span', { text: r[0], style: 'min-width:0;overflow-wrap:break-word;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));' } ) );
					line.appendChild( el( 'span', { text: r[1], style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 1 auto;max-width:100%;margin-left:auto;min-width:0;white-space:normal;overflow-wrap:break-word;text-align:right;' + ( r[2] ? 'color:#d29922;' : '' ) } ) );
					pbox.appendChild( line );
				} );
				wrap.appendChild( pbox );
			}

			// The readers are their own read: they paint beside an anchor error too.
			var rbox = null;
			if ( readers ) {
				rbox = readersBox( readers );
				wrap.appendChild( rbox );
			}

			if ( note && overview ) {
				wrap.appendChild( el( 'p', { style: 'margin:8px 0 0;font-size:11px;color:var(--os-ui-color-text-subtle, rgba(255,255,255,.7));', text: note } ) );
			}

			var actions = el( 'div', { style: 'margin-top:10px;display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center;' } );
			var sweepBtn = el( 'button', { text: sweeping ? 'Sweeping…' : 'Sweep now' } );
			sweepBtn.type = 'button';
			// A repaint mid-sweep (a reader answer landing) keeps the busy state.
			if ( sweeping ) { sweepBtn.setAttribute( 'aria-disabled', 'true' ); }
			sweepBtn.setAttribute( 'style', 'font:inherit;font-size:11px;padding:2px 10px;border-radius:5px;border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit;cursor:pointer;min-height:24px;' );
			sweepBtn.addEventListener( 'click', function() {
				// aria-disabled, not disabled: a disabled button drops keyboard focus to <body>.
				if ( ! window.sntAbilityRun || sweeping || 'true' === sweepBtn.getAttribute( 'aria-disabled' ) ) {
					return;
				}
				sweeping = true;
				sweepBtn.setAttribute( 'aria-disabled', 'true' );
				sweepBtn.textContent = 'Sweeping…';
				// 15.8.1: the sweep's result goes to the shell toast
				// (wp.os.showToast, Stable) and the card just refreshes; the
				// in-card note line grew the card by a row until the next
				// refresh. The note stays as the fallback for a shell without
				// showToast. `still_pending` counts the worker's whole queue
				// (notes AND rights-signal documents), so say so.
				function report( msg ) {
					sweeping = false;
					var os = ( window.wp && ( window.wp.os || window.wp.desktop ) ) || null;
					if ( os && typeof os.showToast === 'function' ) {
						try { os.showToast( { message: msg, duration: 3500, source: 'sn-anchors' } ); load(); return; } catch ( e ) { /* fall through */ }
					}
					load( msg );
				}
				window.sntAbilityRun( 'anchor-sweep', {} ).then( function( res ) {
					report( res && res.ok
						? 'Sweep: ' + res.upgraded + ' upgraded, ' + res.still_pending + ' still pending in the worker\'s queue.'
						: 'Sweep could not run (' + ( ( res && res.error ) || 'unknown' ) + ').' );
				} ).catch( function( err ) {
					report( 'Sweep failed: ' + ( ( err && err.message ) || 'unknown error' ) );
				} );
			} );
			if ( ! waiting ) {
				actions.appendChild( sweepBtn );
			}
			var links = [];
			// Each link closes the section it opens (owner, 2026-10-05, as SN
			// Systems does); with no section painted it stays on the action row.
			[ [ 'Open Provenance', dashboardUrl, pbox ], [ 'Open Machine Readers', readersUrl, rbox ] ].forEach( function( l ) {
				if ( ! l[1] ) { return; }
				var link = el( 'a', {
					style: 'display:inline-flex;align-items:center;gap:4px;min-height:24px;font-size:11px;color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;',
					text:  l[0],
					href:  l[1],
				} );
				var arrow = el( 'span', { text: '→' } );
				arrow.setAttribute( 'aria-hidden', 'true' );
				link.appendChild( arrow );
				( l[2] || actions ).appendChild( link );
				links.push( link );
			} );
			wrap.appendChild( actions );
			container.appendChild( wrap );
			if ( hadFocus ) {
				var back = links.filter( function( a ) { return a.textContent === onLink; } )[0];
				var to = back || ( waiting ? null : sweepBtn );
				if ( to ) { to.focus(); }
			}
		}

		// Each load() takes the next generation; every answer it started bails
		// once a newer load() has begun, so a read from before a Sweep can never
		// repaint over the one after it.
		var gen      = 0;
		var sweeping = false; // survives repaints, so a rebuilt button stays busy
		var readCtl  = null;  // the reader request in flight, aborted when superseded or torn down
		var lastOverview = null; // the last anchor answer, kept on screen through a quiet re-read
		var lastLoadAt   = 0;
		var loud         = false; // a non-quiet read is in flight

		// quiet (2026-10-10): a background re-read. It keeps the last reading
		// on screen, paints only what changed, and never shows the waiting
		// state; a failed quiet read leaves the card as it was.
		function load( note, quiet ) {
			// Nothing read yet (or the last read failed): a background read must
			// be able to paint its error, so it is not quiet.
			quiet = quiet && null !== lastOverview;
			var mine = ++gen;
			loud = ! quiet;
			if ( readCtl ) { readCtl.abort(); }
			readCtl = window.AbortController ? new window.AbortController() : null;
			var live = function() { return mine === gen && ! torn; };
			lastLoadAt = Date.now();
			if ( ! window.sntAbilityRun ) {
				loud = false;
				render( null, 'The abilities client is unavailable.' );
				return;
			}
			if ( ! quiet ) {
				archive = null; // a refresh whose archive read fails must not keep the last reading
				readers = null;
			}
			// The last anchor answer (or its error), so a reader answer that lands
			// later repaints with it rather than without it.
			var shown = quiet && lastOverview ? { overview: lastOverview, note: note, waiting: false } : { overview: null, note: note, waiting: true };
			// The page-load provenance rows (integrity, rights, Zenodo), read again.
			if ( quiet && data.statusExtra && window.wp && window.wp.apiFetch ) {
				window.wp.apiFetch( { path: '/signal-noise/v1/desktop/systems' } ).then( function( res ) {
					if ( live() && res && res.statusExtra && res.statusExtra.provenance ) {
						provExtra = res.statusExtra.provenance;
						if ( ! shown.waiting ) { render( shown.overview, shown.note ); }
					}
				} ).catch( function() {} );
			}
			if ( window.wp && window.wp.apiFetch ) {
				window.wp.apiFetch( { path: '/signal-noise/v1/desktop/machine-readers', signal: readCtl ? readCtl.signal : undefined } ).then( function( res ) {
					if ( live() && res && typeof res === 'object' ) {
						readers = res;
						render( shown.overview, shown.note, shown.waiting );
					}
				} ).catch( function() {
					if ( ! live() || quiet ) { return; }
					readers = { ok: false, error: 'unreachable' };
					render( shown.overview, shown.note, shown.waiting );
				} );
			}
			window.sntAbilityRun( 'anchor-status', {}, { silent: true } ).then( function( overview ) {
				if ( ! live() ) { return; }
				lastOverview   = overview;
				loud           = false;
				shown.overview = overview;
				shown.waiting  = false;
				render( overview, note );
				window.sntAbilityRun( 'archive-status', {}, { silent: true } ).then( function( res ) {
					if ( live() && res && res.ok ) {
						archive = res;
						render( overview, note );
					}
				} ).catch( function() {} );
			} ).catch( function( err ) {
				if ( ! live() || quiet ) { return; }
				loud          = false;
				shown.note    = ( err && err.message ) || 'Could not load anchor status.';
				shown.waiting = false;
				render( null, shown.note );
			} );
		}

		load();

		// 2026-10-10: re-read every REFRESH_MS while the window is focused (5 min
		// visible, paused hidden: assets/snt-poll-cadence.js), and at once when
		// a publish or edit moves the pulse's content stamp (assets/snt-pulse.js).
		// Never during a sweep: its own reload follows it.
		var REFRESH_MS = 5 * 60 * 1000;
		var timer = 0;
		function background() {
			if ( torn ) { return; }
			// Never over a sweep or a read the owner started (its note would be lost).
			if ( ! sweeping && ! loud ) { load( undefined, true ); }
			arm();
		}
		function arm() {
			window.clearTimeout( timer );
			if ( torn || document.hidden ) { return; }
			var wait = window.sntPollCadence ? window.sntPollCadence.wait( REFRESH_MS ) : REFRESH_MS;
			timer = window.setTimeout( background, Math.max( 0, lastLoadAt + wait - Date.now() ) );
		}
		function onVisibilityChange() {
			if ( document.hidden ) { window.clearTimeout( timer ); return; }
			arm();
		}
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		var unwatchFocus = window.sntPollCadence ? window.sntPollCadence.onFocusChange( onVisibilityChange ) : function() {};
		var unwatchPulse = window.sntPulse ? window.sntPulse.on( 'content', background ) : function() {};
		arm();

		return function teardown() {
			torn = true;
			window.clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
			unwatchFocus();
			unwatchPulse();
			if ( readCtl ) { readCtl.abort(); }
			clearChildren( container );
		};
	};
} )();
