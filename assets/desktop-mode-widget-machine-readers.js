/**
 * Signal & Noise Tools — desktop-mode "SN Machine Readers" widget.
 *
 * The machine half of the audience, at a glance: how many machine reads in
 * the window, which crawler families lead, how much of it is declared
 * AI-training traffic, and whether the edge sensor is actually answering.
 * Human readership is the sn-site-views tile's job and the two are NEVER
 * summed — beacons see people, the edge sees crawlers.
 *
 * DESIGN: deliberately a copy of desktop-mode-widget-views.js (the analytics
 * tile) — same el() helper, same 26px hero + 11px/.55 labels, same
 * separator rule between sections, same ellipsised label/value rows, same
 * "Open … →" footer. A tile should look like the tiles beside it.
 *
 * MOUNT CONTRACT: assigned to window.desktopModeWidgets[ id ] — the
 * PHP-declared path (desktop-mode's server-sync reads the global). NOT
 * wp.desktop.registerWidget(), which is the client-side path and
 * hard-validates a def the server already owns.
 *
 * mount( container, ctx ) → teardown.
 *
 * Data: GET signal-noise/v1/desktop/machine-readers (fetch-on-render — the
 * aggregate is a worker round-trip, so it never rides the page localize).
 *
 * @since plugin v10.1.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	window.desktopModeWidgets = window.desktopModeWidgets || {};

	var data   = window.snDesktopData || {};
	var mrUrl  = ( data.pages && data.pages.machine_readers ) || '';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		if ( opts.title != null ) { node.title = opts.title; }
		return node;
	}

	/** A label/value row — the site-views statRow, unchanged. */
	function statRow( label, value, valueStyle ) {
		var row = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
		row.appendChild( el( 'span', {
			text:  label,
			title: label,
			style: 'opacity:.55;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
		} ) );
		row.appendChild( el( 'span', {
			text:  value,
			style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;' + ( valueStyle || '' )
		} ) );
		return row;
	}

	/** A section: the shared separator + an 11px/.55 sentence-case heading. */
	function section( heading ) {
		var wrap = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.12);' } );
		wrap.appendChild( el( 'div', {
			text:  heading,
			style: 'font-size:11px;opacity:.55;margin-bottom:2px;'
		} ) );
		return wrap;
	}

	window.desktopModeWidgets['sn-machine-readers'] = function( container, ctx ) {
		var aborted = false;
		var ctrl    = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;

		var wrap = el( 'div', { style: 'padding:10px 12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;' } );
		var body = el( 'div', { text: 'Loading…', style: 'font-size:12px;opacity:.6;' } );
		wrap.appendChild( body );
		container.appendChild( wrap );

		function render( payload ) {
			body.textContent = '';

			// The sensor is the precondition for everything else on this tile,
			// so an unconfigured/unreachable sensor is the whole message — never
			// a zero, which would read as "no crawlers came".
			if ( ! payload.ok ) {
				body.appendChild( el( 'div', {
					text:  ( 'not_configured' === payload.error ) ? 'Sensor not configured' : 'Sensor unreachable',
					style: 'font-size:12px;opacity:.6;'
				} ) );
				body.appendChild( el( 'div', {
					text:  ( 'not_configured' === payload.error )
						? 'Add the read token on the Machine Readers tab.'
						: 'The edge sensor did not answer; it retries on the next load.',
					style: 'font-size:11px;opacity:.45;margin-top:2px;'
				} ) );
				return;
			}

			body.appendChild( el( 'div', {
				text:  String( payload.total ),
				style: 'font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.1;'
			} ) );
			body.appendChild( el( 'div', {
				text:  'machine reads · last ' + String( payload.days ) + ' days',
				style: 'font-size:11px;opacity:.6;margin-bottom:6px;'
			} ) );

			// An empty window is a real answer, not a failure.
			if ( ! payload.families || ! payload.families.length ) {
				body.appendChild( el( 'div', {
					text:  'No machine reads in this window yet',
					style: 'font-size:11px;opacity:.55;'
				} ) );
			} else {
				var fam = section( 'Top families' );
				payload.families.forEach( function( row ) {
					fam.appendChild( statRow( String( row.family ), String( row.hits ) ) );
				} );
				body.appendChild( fam );
			}

			// AI-training share: the number this whole surface exists to show.
			// null means "not measured", never rendered as 0.
			if ( payload.ai_training !== null && typeof payload.ai_training !== 'undefined' ) {
				var ai = section( 'Declared AI-training' );
				ai.appendChild( statRow( 'Reads', String( payload.ai_training ) ) );
				if ( payload.ai_rights !== null && typeof payload.ai_rights !== 'undefined' ) {
					ai.appendChild( statRow(
						'…of the rights files',
						String( payload.ai_rights ),
						// Not an alarm either way: a crawler reading the rights
						// declarations is the good case, so this is informational.
						''
					) );
				}
				// ADDITIVE (v10.27.0): the per-surface split, e.g. "did AI-training
				// crawlers fetch robots.txt/llms.txt here". Guarded on presence —
				// an older cached payload or worker response without the field
				// renders exactly as before, byte-identical.
				if ( payload.ai_surfaces && payload.ai_surfaces.length ) {
					payload.ai_surfaces.forEach( function( row ) {
						ai.appendChild( statRow( '…on ' + String( row.surface ), String( row.hits ) ) );
					} );
				}
				body.appendChild( ai );
			}

			// Sensor line: version + drift verdict, the two facts that say
			// whether to trust the numbers above.
			var sensor = section( 'Sensor' );
			sensor.appendChild( statRow( 'Version', payload.sensor_version ? String( payload.sensor_version ) : '—' ) );
			if ( payload.crawler_list ) {
				sensor.appendChild( statRow(
					'Crawler list',
					String( payload.crawler_list ),
					( 'in sync' === payload.crawler_list ) ? '' : 'color:#d29922;'
				) );
			}
			body.appendChild( sensor );
		}

		function fail() {
			body.textContent = '';
			body.appendChild( el( 'div', {
				text:  'Machine readers unavailable',
				style: 'font-size:12px;opacity:.6;'
			} ) );
		}

		if ( window.wp && window.wp.apiFetch ) {
			window.wp.apiFetch( {
				path:   '/signal-noise/v1/desktop/machine-readers',
				signal: ctrl ? ctrl.signal : undefined
			} ).then( function( res ) {
				if ( aborted ) { return; }
				render( res || {} );
			} ).catch( function() {
				if ( aborted ) { return; }
				fail();
			} );
		} else {
			fail();
		}

		if ( mrUrl ) {
			wrap.appendChild( el( 'a', {
				href:  mrUrl,
				text:  'Open Machine Readers →',
				style: 'display:inline-block;margin-top:8px;font-size:11px;text-decoration:none;opacity:.75;'
			} ) );
		}

		return function teardown() {
			aborted = true;
			if ( ctrl ) { ctrl.abort(); }
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
