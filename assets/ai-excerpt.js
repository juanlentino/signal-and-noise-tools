/**
 * Signal & Noise Tools — AI excerpt button.
 *
 * Enqueued on post.php / post-new.php via inc/ai-excerpt.php (only when
 * snt_ai_is_available() returns true). Injects an "AI helpers" section
 * at the bottom of the SN meta box (.sn-post-settings) with a single
 * "Generate excerpt with AI" button.
 *
 * On click:
 *   1. REST POST → AI generates 50-75 word excerpt
 *   2. Result is written to WP's native excerpt via:
 *      wp.data.dispatch('core/editor').editPost({ excerpt: result })
 *      — works regardless of whether the Excerpt panel is expanded in
 *      the block editor, because we go through the data layer instead
 *      of the DOM.
 *   3. For classic editor fallback: also fill #excerpt textarea if found.
 *
 * Same DOM-built / XSS-safe pattern as the other AI buttons in this plugin.
 *
 * @since plugin v2.4.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var cfg = window.sntAiExcerpt || {};
	var restPath = cfg.restPath || '/signal-noise/v1/ai/generate-excerpt';
	var metaBoxClass = cfg.metaBoxClass || 'sn-post-settings';
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

	function writeExcerpt( text ) {
		// Block editor — canonical data path.
		var dispatched = false;
		if ( window.wp.data && window.wp.data.dispatch( 'core/editor' ) ) {
			var editor = window.wp.data.dispatch( 'core/editor' );
			if ( editor && typeof editor.editPost === 'function' ) {
				editor.editPost( { excerpt: text } );
				dispatched = true;
			}
		}

		// Classic editor fallback — directly fill the textarea if present.
		// Both can be true on the same screen if WP renders both surfaces
		// during a transition (harmless redundancy).
		var classic = document.getElementById( 'excerpt' );
		if ( classic && classic.tagName === 'TEXTAREA' ) {
			classic.value = text;
			classic.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
			classic.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		return dispatched || !! classic;
	}

	function injectSection( container ) {
		if ( ! container || container.dataset.sntAiExcerptMounted === '1' ) {
			return;
		}
		container.dataset.sntAiExcerptMounted = '1';

		var section = document.createElement( 'div' );
		section.className = 'sn-field';

		var label = document.createElement( 'div' );
		label.className = 'sn-field-label';
		label.textContent = __( 'AI helpers', 'signal-noise-tools' );
		section.appendChild( label );

		var helper = document.createElement( 'p' );
		helper.className = 'sn-field-helper';
		helper.textContent = __( 'Generates a 50-75 word excerpt from the post content and writes it to the WordPress Excerpt field (Document panel → Excerpt).', 'signal-noise-tools' );
		section.appendChild( helper );

		var actions = document.createElement( 'div' );
		actions.setAttribute( 'style', 'display:flex;align-items:center;gap:8px;margin-top:6px;' );

		var status = document.createElement( 'span' );
		status.className = 'sn-ai-excerpt-status sn-helper';
		status.setAttribute( 'style', 'flex:1;margin:0;color:#646970;font-size:12px;' );
		actions.appendChild( status );

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-secondary';
		btn.textContent = __( 'Generate excerpt with AI', 'signal-noise-tools' );
		actions.appendChild( btn );

		section.appendChild( actions );
		container.appendChild( section );

		btn.addEventListener( 'click', function() {
			var postId = getPostId();
			if ( ! postId ) {
				setStatus( status, __( 'Could not detect post ID — save the post first.', 'signal-noise-tools' ), 'err' );
				return;
			}

			btn.disabled = true;
			setStatus( status, __( 'Generating…', 'signal-noise-tools' ), 'info' );

			// v2.5.0+: route through the abilities REST API instead of the
			// legacy /signal-noise/v1/ai/generate-excerpt endpoint.
			// v2.5.2: URL fix — abilities route includes /abilities/ segment.
			window.wp.apiFetch( {
				path: '/wp-abilities/v1/abilities/signal-noise/ai-generate-excerpt/run',
				method: 'POST',
				data: { input: { post_id: postId } },
			} )
				.then( function( res ) {
					if ( ! res || ! res.excerpt ) {
						throw new Error( __( 'AI returned no excerpt.', 'signal-noise-tools' ) );
					}
					var wrote = writeExcerpt( res.excerpt );
					if ( ! wrote ) {
						throw new Error( __( 'Could not write to excerpt field.', 'signal-noise-tools' ) );
					}
					setStatus(
						status,
						__( 'Generated', 'signal-noise-tools' ) + ' · ' + res.words + ' ' + __( 'words', 'signal-noise-tools' ) + ' · ' + __( 'written to Excerpt panel', 'signal-noise-tools' ),
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

	function waitForContainer( cls, maxMs, intervalMs ) {
		var elapsed = 0;
		var step = intervalMs || 200;
		var cap  = maxMs || 10000;
		var tick = function() {
			var el = document.getElementsByClassName( cls )[ 0 ];
			if ( el ) {
				injectSection( el );
				return;
			}
			elapsed += step;
			if ( elapsed >= cap ) { return; }
			window.setTimeout( tick, step );
		};
		tick();
	}

	function start() {
		var el = document.getElementsByClassName( metaBoxClass )[ 0 ];
		if ( el ) {
			injectSection( el );
			return;
		}
		waitForContainer( metaBoxClass, 10000, 200 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
