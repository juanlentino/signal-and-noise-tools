/**
 * Signal & Noise Tools — Health Suggest+Apply actions.
 *
 * Shared click handlers for the Health admin tab's AI Suggest buttons.
 * Serves both check types (missing_alt, drift_time_phrases) via
 * data-attribute-driven dispatch — single module, no per-check JS.
 *
 * Enqueued on the SN admin page via inc/admin-page.php / settings hook
 * (only when snt_ai_is_available() returns true).
 *
 * Calls the Abilities API REST surface:
 *   POST /wp-abilities/v1/abilities/signal-noise/ai-alt-suggest/run
 *   POST /wp-abilities/v1/abilities/signal-noise/ai-alt-apply/run
 *   POST /wp-abilities/v1/abilities/signal-noise/ai-drift-suggest/run
 *   POST /wp-abilities/v1/abilities/signal-noise/ai-drift-apply/run
 *
 * Suggest All: iterates over un-suggested rows in a check section,
 * calls Suggest sequentially with a 500ms throttle, populates each
 * inline. Apply remains per-row (user reviews each suggestion before
 * the destructive write).
 *
 * @since plugin v4.0.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function( s ) { return s; };
	var SUGGEST_THROTTLE_MS = 500;

	var ABILITY_PATH = '/wp-abilities/v1/abilities/signal-noise/';

	var ABILITY_BY_CHECK = {
		missing_alt:         { suggest: 'ai-alt-suggest', apply: 'ai-alt-apply' },
		drift_time_phrases:  { suggest: 'ai-drift-suggest', apply: 'ai-drift-apply' },
	};

	/**
	 * POST to an ability run endpoint.
	 * @param {string} abilitySlug e.g. "ai-alt-suggest"
	 * @param {object} input        Input object matching the ability's input_schema
	 * @return {Promise<object>}   Resolves with response body; rejects with Error(msg).
	 */
	function callAbility( abilitySlug, input ) {
		return window.wp.apiFetch( {
			path:   ABILITY_PATH + abilitySlug + '/run',
			method: 'POST',
			data:   { input: input },
		} ).catch( function( err ) {
			var msg = ( err && err.message ) ? err.message : __( 'Unknown error.', 'signal-noise-tools' );
			throw new Error( msg );
		} );
	}

	/**
	 * Set the color + text of a status span.
	 * @param {Element} node
	 * @param {string}  text
	 * @param {'info'|'ok'|'warn'|'err'} kind
	 */
	function setStatus( node, text, kind ) {
		if ( ! node ) { return; }
		node.textContent = text;
		switch ( kind ) {
			case 'ok':   node.style.color = '#0a5a1a'; break;
			case 'warn': node.style.color = '#6e4d00'; break;
			case 'err':  node.style.color = '#8b1a1a'; break;
			default:     node.style.color = '#646970';
		}
	}

	/**
	 * Handle a click on a [data-snt-suggest] button.
	 * Reads the finding data from data-attributes, calls the appropriate
	 * Suggest ability, replaces the cell with the suggestion editor.
	 */
	function onSuggestClick( event ) {
		var btn = event.target.closest( '[data-snt-suggest]' );
		if ( ! btn || btn.disabled ) { return; }

		var checkType = btn.getAttribute( 'data-check' );
		var slugs     = ABILITY_BY_CHECK[ checkType ];
		if ( ! slugs ) { return; }

		var cell = btn.closest( 'td' );
		if ( ! cell ) { return; }

		// Build input object from data attributes (per check type).
		// Note: v4.0.0 only handles attachment-alt + drift findings.
		// Inline-img findings get no Suggest button at the PHP-render
		// layer (see sn_health_render_suggest_cell in inc/health-checks-admin.php).
		var input = {};
		if ( 'missing_alt' === checkType ) {
			input.attachment_id = parseInt( btn.getAttribute( 'data-attachment-id' ), 10 );
			if ( ! input.attachment_id ) {
				renderError( cell, __( 'Missing attachment ID.', 'signal-noise-tools' ) );
				return;
			}
		} else if ( 'drift_time_phrases' === checkType ) {
			input.post_id         = parseInt( btn.getAttribute( 'data-post-id' ), 10 );
			input.phrase          = btn.getAttribute( 'data-phrase' ) || '';
			input.position        = parseInt( btn.getAttribute( 'data-position' ), 10 );
			input.context_snippet = btn.getAttribute( 'data-context' ) || '';
			if ( ! input.post_id || ! input.phrase ) {
				renderError( cell, __( 'Missing finding data.', 'signal-noise-tools' ) );
				return;
			}
		}

		btn.disabled = true;
		btn.textContent = __( 'Generating…', 'signal-noise-tools' );

		callAbility( slugs.suggest, input )
			.then( function( res ) {
				renderSuggestion( cell, res, slugs.apply, input, checkType );
			} )
			.catch( function( err ) {
				renderError( cell, err.message );
				btn.disabled = false;
				btn.textContent = __( 'Suggest', 'signal-noise-tools' );
			} );
	}

	/**
	 * Replace the cell's contents with the suggestion editor + Apply/Discard.
	 */
	function renderSuggestion( cell, res, applyAbility, input, checkType ) {
		if ( ! res || ! res.suggestion ) {
			renderError( cell, __( 'AI returned no suggestion.', 'signal-noise-tools' ) );
			return;
		}

		// Clear cell.
		while ( cell.firstChild ) { cell.removeChild( cell.firstChild ); }

		var wrap = document.createElement( 'div' );
		wrap.className = 'snt-suggest-panel';
		wrap.setAttribute( 'style', 'display:flex;flex-direction:column;gap:6px;' );

		var ta = document.createElement( 'textarea' );
		ta.className = 'snt-suggest-textarea';
		ta.rows = 3;
		ta.value = res.suggestion;
		ta.setAttribute( 'style', 'width:100%;font-family:inherit;font-size:12px;' );
		wrap.appendChild( ta );

		var status = document.createElement( 'span' );
		status.className = 'snt-suggest-status';
		status.setAttribute( 'style', 'font-size:11px;color:#646970;' );
		wrap.appendChild( status );

		var actions = document.createElement( 'div' );
		actions.setAttribute( 'style', 'display:flex;gap:6px;flex-wrap:wrap;' );

		var applyBtn = document.createElement( 'button' );
		applyBtn.type = 'button';
		applyBtn.className = 'button button-primary button-small';
		applyBtn.textContent = __( 'Apply', 'signal-noise-tools' );
		applyBtn.addEventListener( 'click', function() {
			onApplyClick( cell, ta, status, applyBtn, applyAbility, input, checkType, res );
		} );
		actions.appendChild( applyBtn );

		var discardBtn = document.createElement( 'button' );
		discardBtn.type = 'button';
		discardBtn.className = 'button button-small';
		discardBtn.textContent = __( 'Discard', 'signal-noise-tools' );
		discardBtn.addEventListener( 'click', function() {
			resetCellToSuggestButton( cell, checkType, input, res );
		} );
		actions.appendChild( discardBtn );

		wrap.appendChild( actions );
		cell.appendChild( wrap );
	}

	/**
	 * Handle Apply button click. Confirms, calls the apply ability,
	 * marks the row done on success or surfaces the error on failure.
	 */
	function onApplyClick( cell, textarea, status, applyBtn, applyAbility, suggestInput, checkType, suggestRes ) {
		var msg;
		if ( 'missing_alt' === checkType ) {
			msg = __( 'Write this alt text to the attachment? This cannot be undone via this UI.', 'signal-noise-tools' );
		} else {
			msg = __( 'Replace the phrase in the post? This cannot be undone via this UI.', 'signal-noise-tools' );
		}
		if ( ! window.confirm( msg ) ) { return; }

		applyBtn.disabled = true;
		setStatus( status, __( 'Applying…', 'signal-noise-tools' ), 'info' );

		// Build apply input from suggest input + textarea value + suggest response.
		var applyInput;
		if ( 'missing_alt' === checkType ) {
			applyInput = {
				attachment_id: suggestInput.attachment_id,
				alt_text:      textarea.value,
			};
		} else {
			applyInput = {
				post_id:     suggestInput.post_id,
				phrase:      suggestInput.phrase,
				position:    suggestInput.position,
				replacement: textarea.value,
				fingerprint: suggestRes.fingerprint,
			};
		}

		callAbility( applyAbility, applyInput )
			.then( function() {
				renderApplied( cell );
				var row = cell.closest( 'tr' );
				if ( row ) { row.style.opacity = '0.5'; }
			} )
			.catch( function( err ) {
				setStatus( status, __( 'Failed', 'signal-noise-tools' ) + ': ' + err.message, 'err' );
				applyBtn.disabled = false;
			} );
	}

	function renderApplied( cell ) {
		while ( cell.firstChild ) { cell.removeChild( cell.firstChild ); }
		var span = document.createElement( 'span' );
		span.setAttribute( 'style', 'color:#0a5a1a;font-weight:600;' );
		span.textContent = '✓ ' + __( 'Applied', 'signal-noise-tools' );
		cell.appendChild( span );
	}

	function renderError( cell, msg ) {
		// If cell already has a suggest panel, set status there. Otherwise,
		// replace the whole cell content with an inline error span.
		var status = cell.querySelector( '.snt-suggest-status' );
		if ( status ) {
			setStatus( status, msg, 'err' );
			return;
		}
		var existing = cell.querySelector( '[data-snt-suggest]' );
		if ( existing ) {
			// Render an error sibling, keep the button.
			var existingErr = cell.querySelector( '.snt-suggest-inline-err' );
			if ( existingErr ) { existingErr.parentNode.removeChild( existingErr ); }
			var err = document.createElement( 'div' );
			err.className = 'snt-suggest-inline-err';
			err.setAttribute( 'style', 'font-size:11px;color:#8b1a1a;margin-top:4px;' );
			err.textContent = msg;
			cell.appendChild( err );
			return;
		}
		// Fallback: replace content.
		while ( cell.firstChild ) { cell.removeChild( cell.firstChild ); }
		var span = document.createElement( 'span' );
		span.setAttribute( 'style', 'color:#8b1a1a;' );
		span.textContent = msg;
		cell.appendChild( span );
	}

	/**
	 * Discard: restore the Suggest button (allows user to retry).
	 */
	function resetCellToSuggestButton( cell, checkType, suggestInput, suggestRes ) {
		while ( cell.firstChild ) { cell.removeChild( cell.firstChild ); }
		var btn = buildSuggestButton( checkType, suggestInput );
		cell.appendChild( btn );
	}

	function buildSuggestButton( checkType, input ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-small';
		btn.textContent = __( 'Suggest', 'signal-noise-tools' );
		btn.setAttribute( 'data-snt-suggest', '1' );
		btn.setAttribute( 'data-check', checkType );
		if ( 'missing_alt' === checkType ) {
			btn.setAttribute( 'data-attachment-id', input.attachment_id );
		} else if ( 'drift_time_phrases' === checkType ) {
			btn.setAttribute( 'data-post-id', input.post_id );
			btn.setAttribute( 'data-phrase', input.phrase );
			btn.setAttribute( 'data-position', input.position );
			btn.setAttribute( 'data-context', input.context_snippet );
		}
		return btn;
	}

	/**
	 * Handle "Suggest all N" button click. Iterates remaining Suggest
	 * buttons in the section, calls Suggest sequentially with a 500ms
	 * throttle. Each row populates independently as responses arrive.
	 */
	function onSuggestAllClick( event ) {
		var btn = event.target.closest( '[data-snt-suggest-all]' );
		if ( ! btn || btn.disabled ) { return; }

		var section = btn.closest( '.sn-fieldset' );
		if ( ! section ) { return; }

		var buttons = section.querySelectorAll( '[data-snt-suggest]:not([disabled])' );
		var total   = buttons.length;
		if ( 0 === total ) {
			btn.textContent = __( 'All already suggested', 'signal-noise-tools' );
			btn.disabled = true;
			return;
		}

		btn.disabled = true;
		var done = 0;
		var label = btn.dataset.labelBase || btn.textContent;
		btn.dataset.labelBase = label;

		function step( i ) {
			if ( i >= total ) {
				btn.textContent = __( 'Suggested all', 'signal-noise-tools' );
				return;
			}
			var nextBtn = buttons[ i ];
			if ( ! nextBtn || nextBtn.disabled ) {
				step( i + 1 );
				return;
			}
			btn.textContent = __( 'Suggesting', 'signal-noise-tools' ) + ' (' + ( i + 1 ) + '/' + total + ')…';
			nextBtn.click();
			done = i + 1;
			window.setTimeout( function() { step( i + 1 ); }, SUGGEST_THROTTLE_MS );
		}

		step( 0 );
	}

	function init() {
		document.addEventListener( 'click', function( e ) {
			if ( e.target.closest( '[data-snt-suggest]' ) ) {
				onSuggestClick( e );
			} else if ( e.target.closest( '[data-snt-suggest-all]' ) ) {
				onSuggestAllClick( e );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
