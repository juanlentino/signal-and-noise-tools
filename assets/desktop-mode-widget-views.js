/**
 * Signal & Noise Tools — desktop-mode "SN Site Views" widget.
 *
 * A 14-day first-party pageview sparkline + total + delta. The stock
 * desktop-mode "Site Views" tile can't show our numbers: it reads Jetpack
 * or `_post_views_YYYY-MM-DD` postmeta, and we write neither by design —
 * our views come from the edge beacon → Analytics Engine → the durable
 * wp_sn_analytics_daily rollup. Hence our own widget.
 *
 * MOUNT CONTRACT: desktop-mode's server-sync (src/widgets/server-sync.ts)
 * loads this script for a PHP-declared widget and then looks for the mount
 * callback at `window.desktopModeWidgets[ id ]`. It must be assigned as a
 * plain global — NOT via wp.desktop.registerWidget(), which is the separate
 * client-side path and hard-validates a full def (id/label/description/
 * icon/mount) that the server already owns for us.
 *
 * mount( container, ctx ) → teardown.
 *
 * Data: GET signal-noise/v1/desktop/site-views (fetch-on-render — the
 * series costs two aggregate SQL queries, so it never rides the localize).
 *
 * @since plugin v9.52.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	window.desktopModeWidgets = window.desktopModeWidgets || {};

	var data         = window.snDesktopData || {};
	var analyticsUrl = ( data.pages && data.pages.analytics ) || '';

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.href != null ) { node.href = opts.href; }
		// v9.53.0: title carries the honesty explainers (what `confidence`
		// actually measures; that advisories are not faults) and the hover
		// text for ellipsised rows. Without this branch every title: passed
		// to el() was silently discarded — the explainers never rendered.
		if ( opts.title != null ) { node.title = opts.title; }
		return node;
	}

	/**
	 * Inline SVG sparkline. Built with createElementNS (no innerHTML) so
	 * nothing user-controlled can reach the DOM as markup.
	 */
	function sparkline( days ) {
		var W = 220, H = 40, PAD = 2;
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
		svg.setAttribute( 'width', '100%' );
		svg.setAttribute( 'height', String( H ) );
		svg.setAttribute( 'aria-hidden', 'true' );

		var max = 0, i;
		for ( i = 0; i < days.length; i++ ) {
			if ( days[ i ].views > max ) { max = days[ i ].views; }
		}
		// A flat-zero window would divide by zero below; bail to a baseline.
		if ( max <= 0 || days.length < 2 ) { return svg; }

		var step = ( W - PAD * 2 ) / ( days.length - 1 );
		var pts  = [];
		for ( i = 0; i < days.length; i++ ) {
			var x = PAD + i * step;
			var y = H - PAD - ( days[ i ].views / max ) * ( H - PAD * 2 );
			pts.push( x.toFixed( 1 ) + ',' + y.toFixed( 1 ) );
		}

		var poly = document.createElementNS( 'http://www.w3.org/2000/svg', 'polyline' );
		poly.setAttribute( 'points', pts.join( ' ' ) );
		poly.setAttribute( 'fill', 'none' );
		poly.setAttribute( 'stroke', 'currentColor' );
		poly.setAttribute( 'stroke-width', '1.5' );
		poly.setAttribute( 'stroke-linejoin', 'round' );
		poly.setAttribute( 'stroke-linecap', 'round' );
		svg.appendChild( poly );
		return svg;
	}

	function deltaLine( pct ) {
		if ( pct === null || typeof pct === 'undefined' ) {
			// No prior window to compare — say nothing rather than imply flat.
			return el( 'div', { text: 'vs. prior 14 days: —', style: 'font-size:11px;opacity:.6;' } );
		}
		var up   = pct >= 0;
		var node = el( 'div', {
			text: ( up ? '▲ ' : '▼ ' ) + Math.abs( pct ) + '% vs. prior 14 days',
			style: 'font-size:11px;font-weight:600;color:' + ( up ? '#3fb950' : '#c9503f' ) + ';'
		} );
		return node;
	}

	/** A label/value row for the secondary stats. */
	function statRow( label, value, valueStyle ) {
		var row = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
		row.appendChild( el( 'span', { text: label, style: 'opacity:.55;' } ) );
		row.appendChild( el( 'span', {
			text:  value,
			style: 'font-variant-numeric:tabular-nums;font-weight:600;' + ( valueStyle || '' )
		} ) );
		return row;
	}

	/**
	 * v9.53.0 — the forecast.
	 *
	 * THE INTERVAL IS THE STORY, not a footnote. sn_analytics_forecast_of()
	 * returns a ~95% interval whose width is honest about how noisy the traffic
	 * is: on real low-volume data a point of ~21/day can carry a 0–87 interval.
	 * Rendering "21/day" large with the range hidden would be the dishonest
	 * version — it reads as precision the model never claimed. So the range gets
	 * equal billing, and `confidence` is shown for what it actually is: the
	 * backtest's MEASURED interval coverage ("how often reality landed inside
	 * this band"), NOT "how accurate the number is".
	 *
	 * The server sends null when the engine suppressed (under 21 days of
	 * history, or a zero median level). We render NOTHING then — never a
	 * fabricated number.
	 */
	function forecastBlock( f ) {
		if ( ! f || typeof f.value === 'undefined' || ! f.interval ) {
			return null;
		}
		var wrap = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.12);' } );

		var arrow = ( 'up' === f.direction ) ? '▲' : ( ( 'down' === f.direction ) ? '▼' : '→' );
		var tint  = ( 'up' === f.direction ) ? '#3fb950' : ( ( 'down' === f.direction ) ? '#c9503f' : 'inherit' );

		wrap.appendChild( el( 'div', {
			text:  'Next 7 days',
			style: 'font-size:11px;opacity:.55;margin-bottom:2px;'
		} ) );

		var line = el( 'div', { style: 'display:flex;align-items:baseline;gap:6px;' } );
		line.appendChild( el( 'span', {
			text:  arrow + ' ≈' + Math.round( f.value ) + '/day',
			style: 'font-size:14px;font-weight:600;font-variant-numeric:tabular-nums;color:' + tint + ';'
		} ) );
		line.appendChild( el( 'span', {
			text:  Math.round( f.interval.low ) + '–' + Math.round( f.interval.high ),
			style: 'font-size:12px;opacity:.75;font-variant-numeric:tabular-nums;'
		} ) );
		wrap.appendChild( line );

		wrap.appendChild( el( 'div', {
			// Name what `confidence` measures. "high" here means the interval
			// reliably contained reality in backtesting — not that ≈N is exact.
			text:  '95% interval · ' + String( f.confidence ) + ' interval coverage',
			title: 'Coverage is measured by backtesting the forecast against history: how often the real value landed inside this band. A wide band with high coverage means honest uncertainty, not precision.',
			style: 'font-size:10px;opacity:.45;margin-top:2px;'
		} ) );

		return wrap;
	}

	window.desktopModeWidgets['sn-site-views'] = function( container, ctx ) {
		var aborted = false;
		var ctrl    = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;

		var wrap = el( 'div', { style: 'padding:10px 12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;' } );
		var body = el( 'div', { text: 'Loading…', style: 'font-size:12px;opacity:.6;' } );
		wrap.appendChild( body );
		container.appendChild( wrap );

		function render( payload ) {
			body.textContent = '';

			if ( ! payload.days || ! payload.days.length ) {
				body.appendChild( el( 'div', {
					text: 'No views in the last 14 days',
					style: 'font-size:12px;opacity:.6;'
				} ) );
				return;
			}

			body.appendChild( el( 'div', {
				text: String( payload.total ),
				style: 'font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.1;'
			} ) );
			body.appendChild( el( 'div', {
				text: 'views · last 14 days',
				style: 'font-size:11px;opacity:.6;margin-bottom:6px;'
			} ) );

			var chart = el( 'div', { style: 'color:#4a9eff;margin:4px 0 6px;' } );
			chart.appendChild( sparkline( payload.days ) );
			body.appendChild( chart );

			body.appendChild( deltaLine( payload.delta_pct ) );

			// ── v9.53.0 secondary stats ──
			var stats = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.12);' } );

			if ( typeof payload.visits === 'number' ) {
				stats.appendChild( statRow( 'Visits', String( payload.visits ) ) );
			}

			// bot_pct is null (not 0) when there was nothing to divide by —
			// "no data" is not "0% bots", so omit the row rather than claim a
			// clean feed we never measured.
			if ( payload.bot_pct !== null && typeof payload.bot_pct !== 'undefined' ) {
				stats.appendChild( statRow(
					'Bot share',
					payload.bot_pct + '%',
					// Not an alarm — the beacon already excludes bots from the
					// human class. This is a data-quality read, so it only tints
					// once it's high enough to be worth a glance.
					payload.bot_pct >= 50 ? 'color:#d29922;' : ''
				) );
			}

			if ( payload.top_path && payload.top_path.path ) {
				var top = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
				top.appendChild( el( 'span', {
					text:  payload.top_path.path,
					title: payload.top_path.path,
					style: 'opacity:.55;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
				} ) );
				top.appendChild( el( 'span', {
					text:  String( payload.top_path.views ),
					style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;'
				} ) );
				stats.appendChild( top );
			}

			if ( stats.childNodes.length ) {
				body.appendChild( stats );
			}

			// ── v9.57.0: top sources ──
			// The desktop had no surface for WHERE traffic comes from. The retired
			// sn-analytics-hud existed largely to show this; everything else it
			// showed, this tile already covered — better. Three rows: a tile is a
			// glance, and the full list is one click away on the analytics page.
			//
			// Rows are the accessor's own shape: `value` + `visits`
			// (inc/analytics-sources.php), sorted by views DESC. NOT `source` —
			// that invented key cost a release.
			//
			// An EMPTY array is a real answer ("no attributed sources yet"), not a
			// failure, so it simply renders nothing rather than claiming anything.
			if ( payload.top_sources && payload.top_sources.length ) {
				var srcs = el( 'div', { style: 'margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.12);' } );
				// Sentence case at 11px/.55 — the same shape forecastBlock() uses for
				// "Next 7 days". NOT uppercase: the suite forbids
				// text-transform:uppercase in every widget file, because the label
				// registered in PHP is the single source of truth for a card's name
				// and the chrome header already paints it. A section heading is not
				// a card title, but matching the house voice beats arguing the point.
				srcs.appendChild( el( 'div', {
					text:  'Top sources',
					style: 'font-size:11px;opacity:.55;margin-bottom:2px;'
				} ) );
				payload.top_sources.forEach( function( src ) {
					var row = el( 'div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:2px 0;font-size:11px;' } );
					row.appendChild( el( 'span', {
						text:  src.value,
						title: src.value,
						style: 'opacity:.55;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
					} ) );
					row.appendChild( el( 'span', {
						text:  String( src.visits ),
						style: 'font-variant-numeric:tabular-nums;font-weight:600;flex:0 0 auto;'
					} ) );
					srcs.appendChild( row );
				} );
				body.appendChild( srcs );
			}

			var fc = forecastBlock( payload.forecast );
			if ( fc ) { body.appendChild( fc ); }
		}

		function fail() {
			body.textContent = '';
			body.appendChild( el( 'div', {
				text: 'Views unavailable',
				style: 'font-size:12px;opacity:.6;'
			} ) );
		}

		if ( window.wp && window.wp.apiFetch ) {
			window.wp.apiFetch( {
				path: '/signal-noise/v1/desktop/site-views',
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

		if ( analyticsUrl ) {
			var link = el( 'a', {
				href: analyticsUrl,
				text: 'Open Analytics →',
				style: 'display:inline-block;margin-top:8px;font-size:11px;text-decoration:none;opacity:.75;'
			} );
			wrap.appendChild( link );
		}

		return function teardown() {
			aborted = true;
			if ( ctrl ) { ctrl.abort(); }
			if ( wrap.parentNode ) { wrap.parentNode.removeChild( wrap ); }
		};
	};

} )();
