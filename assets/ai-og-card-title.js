/**
 * Signal & Noise Tools — AI OG card title button.
 *
 * Enqueued on post.php / post-new.php via inc/ai-og-card-title.php (only
 * when snt_ai_is_available() returns true). Injects a "Generate with AI"
 * button next to the OG card title textarea (id="sn_og_card_title") in
 * the per-post SN meta box.
 *
 * On click: REST POST → AI generates 60-90 char title → backend writes
 * _sn_og_card_title post meta AND re-runs sn_generate_og_card() so the
 * baked PNG reflects the new title immediately. Result populates the
 * textarea + status row shows char count + card regen flag.
 *
 * Same DOM-built / XSS-safe pattern as assets/ai-meta-description.js.
 *
 * @since plugin v2.4.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var cfg = window.sntAiOgCardTitle || {};
	var targetId = cfg.targetId || 'sn_og_card_title';
	var restPath = cfg.restPath || '/signal-noise/v1/ai/generate-og-card-title';
	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function( s ) { return s; };

	function getPostId() {
		if ( window.wp.data && window.wp.data.select( 'core/editor' ) ) {
			var id = window.wp.data.select( 'core/editor' ).getCurrentPostId();
			if ( id ) { return id; }
		}
		var classic = document.getElementById( 'post_ID' );
		if ( classic && classic.value ) { return parseInt( classic.value, 10 ); }
		var match = window.location.search.match( /[?&]post=(\d+)/ );
		if ( match ) { return parseInt( match[ 1 ], 10 ); }
		return 0;
	}

	function injectButton( textarea ) {
		if ( ! textarea || textarea.dataset.sntAiMounted === '1' ) {
			return;
		}
		textarea.dataset.sntAiMounted = '1';

		var wrap = document.createElement( 'div' );
		wrap.className = 'sn-ai-og-card-title-actions';
		wrap.setAttribute( 'style', 'display:flex;align-items:center;gap:8px;margin-top:6px;' );

		var status = document.createElement( 'span' );
		status.className = 'sn-ai-og-card-title-status sn-helper';
		status.setAttribute( 'style', 'flex:1;margin:0;color:#646970;font-size:12px;' );
		wrap.appendChild( status );

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-secondary';
		btn.textContent = __( 'Generate with AI', 'signal-noise-tools' );
		wrap.appendChild( btn );

		var field = textarea.closest( '.sn-field' );
		if ( field ) {
			field.appendChild( wrap );
		} else {
			textarea.parentNode.insertBefore( wrap, textarea.nextSibling );
		}

		btn.addEventListener( 'click', function() {
			var postId = getPostId();
			if ( ! postId ) {
				setStatus( status, __( 'Could not detect post ID — save the post first.', 'signal-noise-tools' ), 'err' );
				return;
			}

			btn.disabled = true;
			setStatus( status, __( 'Generating…', 'signal-noise-tools' ), 'info' );

			// v2.5.0+: route through the abilities REST API instead of the
			// legacy /signal-noise/v1/ai/generate-og-card-title endpoint.
			// v2.5.2: URL fix — abilities route includes /abilities/ segment.
			window.wp.apiFetch( {
				path: '/wp-abilities/v1/abilities/signal-noise/ai-generate-og-card-title/run',
				method: 'POST',
				data: { input: { post_id: postId } },
			} )
				.then( function( res ) {
					if ( ! res || ! res.title ) {
						throw new Error( __( 'AI returned no title.', 'signal-noise-tools' ) );
					}
					textarea.value = res.title;
					textarea.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
					textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					var msg = __( 'Generated', 'signal-noise-tools' ) + ' · ' + res.length + ' ' + __( 'chars', 'signal-noise-tools' );
					if ( res.card_regenerated ) {
						msg += ' · ' + __( 'card refreshed', 'signal-noise-tools' );
					}
					setStatus( status, msg, 'ok' );
				} )
				.catch( function( err ) {
					var msg = ( err && err.message ) ? err.message : __( 'Unknown error.', 'signal-noise-tools' );
					setStatus( status, __( 'Failed', 'signal-noise-tools' ) + ': ' + msg, 'err' );
				} )
				.finally( function() {
					btn.disabled = false;
				} );
		} );
	}

	function setStatus( node, text, kind ) {
		node.textContent = text;
		switch ( kind ) {
			case 'ok':   node.style.color = '#0a5a1a'; break;
			case 'warn': node.style.color = '#6e4d00'; break;
			case 'err':  node.style.color = '#8b1a1a'; break;
			default:     node.style.color = '#646970';
		}
	}

	function waitForTextarea( id, maxMs, intervalMs ) {
		var elapsed = 0;
		var step = intervalMs || 200;
		var cap  = maxMs || 10000;
		var tick = function() {
			var el = document.getElementById( id );
			if ( el ) {
				injectButton( el );
				return;
			}
			elapsed += step;
			if ( elapsed >= cap ) { return; }
			window.setTimeout( tick, step );
		};
		tick();
	}

	function start() {
		var el = document.getElementById( targetId );
		if ( el ) {
			injectButton( el );
			return;
		}
		waitForTextarea( targetId, 10000, 200 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
