/**
 * Signal & Noise Tools — AI meta description button.
 *
 * Enqueued on post.php / post-new.php via inc/ai-meta-description.php
 * (only when snt_ai_is_available() returns true — i.e., WP 7.0+ with the
 * AI client bundled, or 6.x with the wp-ai-client plugin active).
 *
 * Injects a "Generate with AI" button next to the meta description
 * textarea (id="sn_meta_description"), wires it to the REST endpoint via
 * wp.apiFetch (auto _wpnonce + permission gating).
 *
 * Polls for the textarea (it may not be in the DOM at script load — block
 * editor renders meta boxes asynchronously). Gives up after 10 seconds
 * silently — no harm done, button just doesn't appear.
 *
 * DOM-built (createElement + textContent — no innerHTML), per the same
 * XSS-discipline as inc/admin-tab-dashboard.php and the desktop-mode
 * widget.
 *
 * @since plugin v1.16.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var cfg = window.sntAiMetaDesc || {};
	var targetId = cfg.targetId || 'sn_meta_description';
	var restPath = cfg.restPath || '/signal-noise/v1/ai/generate-meta-description';
	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function( s ) { return s; };

	// Find the post ID — block editor exposes it via wp.data; classic
	// editor has a hidden input named "post_ID". Fall back to ?post= URL.
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

		// Container — flexbox row: helper text on left, button on right.
		var wrap = document.createElement( 'div' );
		wrap.className = 'sn-ai-meta-desc-actions';
		wrap.setAttribute( 'style', 'display:flex;align-items:center;gap:8px;margin-top:6px;' );

		// Status text (empty until first click).
		var status = document.createElement( 'span' );
		status.className = 'sn-ai-meta-desc-status sn-helper';
		status.setAttribute( 'style', 'flex:1;margin:0;color:#646970;font-size:12px;' );
		wrap.appendChild( status );

		// Button.
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-secondary';
		btn.textContent = __( 'Generate with AI', 'signal-noise-tools' );
		wrap.appendChild( btn );

		// Insert AFTER the existing helper text paragraph, BEFORE the next field.
		// Strategy: insert after the textarea's parent .sn-field's last child.
		var field = textarea.closest( '.sn-field' );
		if ( field ) {
			field.appendChild( wrap );
		} else {
			// Defensive: insert directly after the textarea if .sn-field
			// wrapper isn't found (post-settings.php structure changed).
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

			window.wp.apiFetch( {
				path: restPath,
				method: 'POST',
				data: { post_id: postId },
			} )
				.then( function( res ) {
					if ( ! res || ! res.description ) {
						throw new Error( __( 'AI returned no description.', 'signal-noise-tools' ) );
					}
					textarea.value = res.description;
					// Fire input/change events so any other listeners (block
					// editor meta-sync, dirty-tracking) pick up the change.
					textarea.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
					textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					setStatus(
						status,
						__( 'Generated', 'signal-noise-tools' ) + ' · ' + res.length + ' ' + __( 'chars', 'signal-noise-tools' ),
						'ok'
					);
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
		// In block editor, post-settings.php meta box may render later.
		// Poll up to 10s — quick path: try once now, then poll if missed.
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
