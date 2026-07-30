/**
 * Signal & Noise Tools — ML candidate buttons (v10.19.0).
 *
 * Enqueued on post.php / post-new.php for post type 'post' only, via
 * inc/ml-candidates-ui.php. NO AI dependency — these are the deterministic
 * kernel's surfaces (keyword-candidates, link-candidates), so the buttons
 * appear even where the AI client is absent.
 *
 * Two injections into the Signal & Noise meta box:
 *   1. "Suggest keywords" beside the focus-keyword input
 *      (id="sn_focus_keyword"): ranked candidates render as chips; clicking
 *      a chip FILLS the input and fires input/change. The human still saves
 *      — the kernel computes, a person decides.
 *   2. A "Link candidates" field appended after the focus-keyword field:
 *      related-but-unlinked notes, each an anchor to the resolved permalink
 *      (res.url — never a path hand-built from the slug) plus a Copy button.
 *      Nothing touches the body.
 *
 * Same discipline as assets/ai-meta-description.js: polls for the target
 * (block editor renders meta boxes asynchronously, 10s cap, silent give-up),
 * DOM-built (createElement + textContent — no innerHTML), transport via
 * window.sntAbilityRun only (the tests/ability-run-client.php path guard).
 *
 * Honest-empty handling: zero keyword candidates or zero link candidates is
 * an ANSWER and renders as one; the link 503 while artifacts are unbuilt
 * renders as "not built yet", never as an empty list.
 *
 * @since plugin v10.19.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.apiFetch || ! window.sntAbilityRun ) {
		return;
	}

	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function( s ) { return s; };
	var setStatus = window.sntSetStatus;

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

	function makeActionsRow( btnLabel ) {
		var wrap = document.createElement( 'div' );
		wrap.setAttribute( 'style', 'display:flex;align-items:center;gap:8px;margin-top:6px;' );
		var status = document.createElement( 'span' );
		status.className = 'sn-helper';
		status.setAttribute( 'style', 'flex:1;margin:0;color:#646970;font-size:12px;' );
		wrap.appendChild( status );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-secondary';
		btn.textContent = btnLabel;
		wrap.appendChild( btn );
		return { wrap: wrap, status: status, btn: btn };
	}

	function errMessage( err ) {
		return ( err && err.message ) ? err.message : __( 'Unknown error.', 'signal-noise-tools' );
	}

	// ── 1. Keyword candidates ─────────────────────────────────────────
	function injectKeywords( input ) {
		if ( ! input || input.dataset.sntMlMounted === '1' ) {
			return;
		}
		input.dataset.sntMlMounted = '1';

		var field = input.closest( '.sn-field' ) || input.parentNode;
		var row = makeActionsRow( __( 'Suggest keywords', 'signal-noise-tools' ) );
		row.wrap.className = 'sn-ml-keywords-actions';
		field.appendChild( row.wrap );

		var chips = document.createElement( 'div' );
		chips.className = 'sn-ml-keyword-chips';
		chips.setAttribute( 'style', 'display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;' );
		field.appendChild( chips );

		row.btn.addEventListener( 'click', function() {
			var postId = getPostId();
			if ( ! postId ) {
				setStatus( row.status, __( 'Could not detect post ID — save the post first.', 'signal-noise-tools' ), 'err' );
				return;
			}
			row.btn.disabled = true;
			setStatus( row.status, __( 'Ranking…', 'signal-noise-tools' ), 'info' );
			window.sntAbilityRun( 'keyword-candidates', { post_id: postId } )
				.then( function( res ) {
					var list = ( res && Array.isArray( res.candidates ) ) ? res.candidates : [];
					while ( chips.firstChild ) { chips.removeChild( chips.firstChild ); }
					if ( ! list.length ) {
						// An empty ranking is an ANSWER (empty body, or nothing survives tokenization).
						setStatus( row.status, __( 'No candidates — the body may be empty.', 'signal-noise-tools' ), 'info' );
						return;
					}
					list.forEach( function( c ) {
						if ( ! c || typeof c.term !== 'string' ) { return; }
						var chip = document.createElement( 'button' );
						chip.type = 'button';
						chip.className = 'button button-small sn-ml-keyword-chip';
						chip.textContent = c.term;
						chip.title = __( 'weight', 'signal-noise-tools' ) + ' ' + String( c.weight );
						chip.addEventListener( 'click', function() {
							input.value = c.term;
							input.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
							input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
							setStatus( row.status, __( 'Filled — save the post to keep it.', 'signal-noise-tools' ), 'ok' );
						} );
						chips.appendChild( chip );
					} );
					setStatus( row.status, String( list.length ) + ' ' + __( 'candidates — click one to fill the field.', 'signal-noise-tools' ), 'ok' );
				} )
				.catch( function( err ) {
					setStatus( row.status, __( 'Failed', 'signal-noise-tools' ) + ': ' + errMessage( err ), 'err' );
				} )
				.finally( function() {
					row.btn.disabled = false;
				} );
		} );
	}

	// ── 2. Link candidates ────────────────────────────────────────────
	function injectLinks( anchorField ) {
		if ( document.querySelector( '.sn-ml-links-field' ) ) {
			return;
		}
		var field = document.createElement( 'div' );
		field.className = 'sn-field sn-ml-links-field';

		var label = document.createElement( 'label' );
		label.className = 'sn-field-label';
		label.textContent = __( 'Link candidates', 'signal-noise-tools' );
		field.appendChild( label );

		var helper = document.createElement( 'p' );
		helper.className = 'sn-field-helper';
		helper.textContent = __( 'Related notes the body doesn’t link to yet, ranked by the ML kernel. Copy a link and place it yourself — nothing edits the body.', 'signal-noise-tools' );
		field.appendChild( helper );

		var row = makeActionsRow( __( 'Suggest links', 'signal-noise-tools' ) );
		row.wrap.className = 'sn-ml-links-actions';
		field.appendChild( row.wrap );

		var results = document.createElement( 'ul' );
		results.className = 'sn-ml-link-results';
		results.setAttribute( 'style', 'margin:6px 0 0;padding:0;list-style:none;font-size:12px;' );
		field.appendChild( results );

		anchorField.parentNode.insertBefore( field, anchorField.nextSibling );

		row.btn.addEventListener( 'click', function() {
			var postId = getPostId();
			if ( ! postId ) {
				setStatus( row.status, __( 'Could not detect post ID — save the post first.', 'signal-noise-tools' ), 'err' );
				return;
			}
			row.btn.disabled = true;
			setStatus( row.status, __( 'Ranking…', 'signal-noise-tools' ), 'info' );
			window.sntAbilityRun( 'link-candidates', { post_id: postId } )
				.then( function( res ) {
					var list = ( res && Array.isArray( res.candidates ) ) ? res.candidates : [];
					while ( results.firstChild ) { results.removeChild( results.firstChild ); }
					if ( ! list.length ) {
						// Empty after exclusions is a REAL answer: everything related is already linked.
						setStatus( row.status, __( 'Nothing to add — every related note is already linked.', 'signal-noise-tools' ), 'info' );
						return;
					}
					list.forEach( function( c ) {
						if ( ! c || typeof c.title !== 'string' || typeof c.url !== 'string' || ! c.url ) { return; }
						var li = document.createElement( 'li' );
						li.setAttribute( 'style', 'display:flex;align-items:center;gap:6px;padding:3px 0;' );
						var a = document.createElement( 'a' );
						a.href = c.url; // Resolved permalink from the server — never built from the slug here.
						a.target = '_blank';
						a.rel = 'noopener';
						a.textContent = c.title;
						a.setAttribute( 'style', 'flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' );
						li.appendChild( a );
						var copy = document.createElement( 'button' );
						copy.type = 'button';
						copy.className = 'button button-small';
						copy.textContent = __( 'Copy', 'signal-noise-tools' );
						copy.addEventListener( 'click', function() {
							if ( navigator.clipboard && navigator.clipboard.writeText ) {
								navigator.clipboard.writeText( c.url ).then( function() {
									setStatus( row.status, __( 'Link copied.', 'signal-noise-tools' ), 'ok' );
								}, function() {
									setStatus( row.status, __( 'Copy failed — use the link directly.', 'signal-noise-tools' ), 'err' );
								} );
							} else {
								setStatus( row.status, __( 'Clipboard unavailable — use the link directly.', 'signal-noise-tools' ), 'err' );
							}
						} );
						li.appendChild( copy );
						results.appendChild( li );
					} );
					setStatus( row.status, String( list.length ) + ' ' + __( 'related notes not yet linked.', 'signal-noise-tools' ), 'ok' );
				} )
				.catch( function( err ) {
					// The unbuilt-artifact 503 is a distinct state, not an empty list.
					if ( err && 'snt_ml_not_built' === err.code ) {
						setStatus( row.status, __( 'Related index not built yet — it builds on the next publish or overnight.', 'signal-noise-tools' ), 'info' );
						return;
					}
					setStatus( row.status, __( 'Failed', 'signal-noise-tools' ) + ': ' + errMessage( err ), 'err' );
				} )
				.finally( function() {
					row.btn.disabled = false;
				} );
		} );
	}

	function mount( input ) {
		injectKeywords( input );
		var anchorField = input.closest( '.sn-field' );
		if ( anchorField ) {
			injectLinks( anchorField );
		}
	}

	function waitForInput( id, maxMs, intervalMs ) {
		var elapsed = 0;
		var step = intervalMs || 200;
		var cap  = maxMs || 10000;
		var tick = function() {
			var el = document.getElementById( id );
			if ( el ) {
				mount( el );
				return;
			}
			elapsed += step;
			if ( elapsed >= cap ) { return; }
			window.setTimeout( tick, step );
		};
		tick();
	}

	function start() {
		var el = document.getElementById( 'sn_focus_keyword' );
		if ( el ) {
			mount( el );
			return;
		}
		waitForInput( 'sn_focus_keyword', 10000, 200 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
