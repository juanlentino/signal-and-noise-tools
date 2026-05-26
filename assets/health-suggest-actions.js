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
		missing_alt:         { suggest: 'ai-alt-suggest',         apply: 'ai-alt-apply' },
		missing_alt_inline:  { suggest: 'ai-alt-inline-suggest',  apply: null },  // v4.0.2: no-apply variant
		drift_time_phrases:  { suggest: 'ai-drift-suggest',       apply: 'ai-drift-apply' },
		orphaned_media:      { suggest: 'ai-orphan-suggest',      apply: 'ai-orphan-apply' },
	};

	// v4.0.3: Active modal state. Only one modal can be open at a time.
	// Stores { backdrop, originatingButton, escapeHandler, focusHandler, applyCallback, cancelCallback }
	// or null when no modal is open.
	var activeModal = null;

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
	 * Open the Apply preview modal.
	 *
	 * Builds the modal DOM, appends to body, installs event listeners,
	 * moves focus into the modal. Calls onApply() if user accepts;
	 * onCancel() if user cancels (or modal is dismissed via Esc/backdrop/×).
	 *
	 * @param {object}   opts
	 * @param {string}   opts.title        Modal title text
	 * @param {Element}  opts.beforeNode   DOM node for the Before pane
	 * @param {Element}  opts.afterNode    DOM node for the After pane
	 * @param {Element}  opts.originatingButton  Where focus returns on close
	 * @param {function} opts.onApply      Called when user clicks Apply
	 * @param {function} opts.onCancel     Called when user cancels
	 */
	function openApplyModal( opts ) {
		if ( activeModal ) {
			// Only one modal at a time — close the existing one first.
			closeApplyModal();
		}

		var isMobile = window.matchMedia && window.matchMedia( '(max-width: 600px)' ).matches;

		var backdrop = document.createElement( 'div' );
		backdrop.className = 'snt-modal-backdrop';
		backdrop.setAttribute( 'style', 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:100000;' );

		var box = document.createElement( 'div' );
		box.className = 'snt-modal-box';
		box.setAttribute( 'style', 'background:#fff;border-radius:6px;box-shadow:0 8px 32px rgba(0,0,0,0.2);max-width:720px;width:90vw;max-height:90vh;overflow-y:auto;display:flex;flex-direction:column;' );

		var header = document.createElement( 'div' );
		header.className = 'snt-modal-header';
		header.setAttribute( 'style', 'display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e0e0e0;' );

		var titleEl = document.createElement( 'h2' );
		titleEl.textContent = opts.title;
		titleEl.setAttribute( 'style', 'margin:0;font-size:16px;font-weight:600;' );
		header.appendChild( titleEl );

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'snt-modal-close';
		closeBtn.textContent = '×';
		closeBtn.setAttribute( 'aria-label', __( 'Close', 'signal-noise-tools' ) );
		closeBtn.setAttribute( 'style', 'background:none;border:none;font-size:24px;line-height:1;cursor:pointer;color:#646970;padding:0 4px;' );
		header.appendChild( closeBtn );

		box.appendChild( header );

		var body = document.createElement( 'div' );
		body.className = 'snt-modal-body';
		body.setAttribute( 'style', 'padding:20px;display:grid;grid-template-columns:' + ( isMobile ? '1fr' : '1fr 1fr' ) + ';gap:24px;' );

		var beforePane = document.createElement( 'div' );
		beforePane.className = 'snt-modal-pane-before';
		var beforeLabel = document.createElement( 'div' );
		beforeLabel.textContent = __( 'Before', 'signal-noise-tools' );
		beforeLabel.setAttribute( 'style', 'font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;margin-bottom:8px;' );
		beforePane.appendChild( beforeLabel );
		beforePane.appendChild( opts.beforeNode );
		body.appendChild( beforePane );

		if ( isMobile ) {
			var hr = document.createElement( 'hr' );
			hr.setAttribute( 'style', 'border:none;border-top:1px solid #e0e0e0;margin:0;' );
			body.appendChild( hr );
		}

		var afterPane = document.createElement( 'div' );
		afterPane.className = 'snt-modal-pane-after';
		var afterLabel = document.createElement( 'div' );
		afterLabel.textContent = __( 'After', 'signal-noise-tools' );
		afterLabel.setAttribute( 'style', 'font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;margin-bottom:8px;' );
		afterPane.appendChild( afterLabel );
		afterPane.appendChild( opts.afterNode );
		body.appendChild( afterPane );

		box.appendChild( body );

		var footer = document.createElement( 'div' );
		footer.className = 'snt-modal-footer';
		footer.setAttribute( 'style', 'display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid #e0e0e0;' );

		var cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'button';
		cancelBtn.textContent = __( 'Cancel', 'signal-noise-tools' );
		footer.appendChild( cancelBtn );

		var applyBtn = document.createElement( 'button' );
		applyBtn.type = 'button';
		applyBtn.className = 'button button-primary';
		applyBtn.textContent = __( 'Apply', 'signal-noise-tools' );
		footer.appendChild( applyBtn );

		box.appendChild( footer );
		backdrop.appendChild( box );
		document.body.appendChild( backdrop );

		// Wire up close paths.
		var dismiss = function() { closeApplyModal(); opts.onCancel(); };
		var accept  = function() { closeApplyModal(); opts.onApply(); };

		closeBtn.addEventListener( 'click', dismiss );
		cancelBtn.addEventListener( 'click', dismiss );
		applyBtn.addEventListener( 'click', accept );
		backdrop.addEventListener( 'click', function( e ) {
			if ( e.target === backdrop ) { dismiss(); }
		} );

		// Keyboard handler: Escape = cancel, Enter (not in textarea) = apply.
		var escapeHandler = function( e ) {
			if ( ! activeModal ) { return; }
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				dismiss();
			} else if ( 'Enter' === e.key && 'TEXTAREA' !== ( e.target && e.target.tagName ) ) {
				e.preventDefault();
				accept();
			}
		};
		document.addEventListener( 'keydown', escapeHandler );

		// Focus trap: redirect any focus escaping the modal back to Apply button.
		var focusHandler = function( e ) {
			if ( ! activeModal ) { return; }
			if ( ! box.contains( e.target ) ) {
				e.preventDefault();
				applyBtn.focus();
			}
		};
		document.addEventListener( 'focusin', focusHandler );

		activeModal = {
			backdrop:            backdrop,
			originatingButton:   opts.originatingButton,
			escapeHandler:       escapeHandler,
			focusHandler:        focusHandler,
		};

		// Move focus to the Apply button (primary action).
		applyBtn.focus();
	}

	/**
	 * Close the currently-open modal. Idempotent — safe to call when no modal exists.
	 */
	function closeApplyModal() {
		if ( ! activeModal ) { return; }

		document.removeEventListener( 'keydown', activeModal.escapeHandler );
		document.removeEventListener( 'focusin', activeModal.focusHandler );

		if ( activeModal.backdrop && activeModal.backdrop.parentNode ) {
			activeModal.backdrop.parentNode.removeChild( activeModal.backdrop );
		}

		if ( activeModal.originatingButton ) {
			activeModal.originatingButton.focus();
		}

		activeModal = null;
	}

	/**
	 * Build modal Before+After nodes for the attachment-alt variant.
	 *
	 * @param {object}  res      Suggest response (must include thumbnail_url, suggestion)
	 * @param {object}  input    Input from onApplyClick (has attachment_id)
	 * @param {string}  altText  The user-edited alt text (from textarea)
	 * @return {{ before: Element, after: Element }}
	 */
	function buildAttachmentAltModalContent( res, input, altText ) {
		var beforeNode = document.createElement( 'div' );

		if ( res.thumbnail_url && '' !== res.thumbnail_url ) {
			var img = document.createElement( 'img' );
			img.src = res.thumbnail_url;
			img.setAttribute( 'style', 'max-width:160px;max-height:160px;display:block;border-radius:4px;border:1px solid #e0e0e0;' );
			beforeNode.appendChild( img );
		}

		var filenameEl = document.createElement( 'p' );
		var filename = res.filename && '' !== res.filename
			? res.filename
			: ( '#' + ( input.attachment_id || 0 ) );
		filenameEl.textContent = filename;
		filenameEl.setAttribute( 'style', 'font-family:monospace;font-size:12px;margin:8px 0 4px 0;color:#1d2327;word-break:break-all;' );
		beforeNode.appendChild( filenameEl );

		var captionEl = document.createElement( 'p' );
		captionEl.textContent = __( '(no existing alt)', 'signal-noise-tools' );
		captionEl.setAttribute( 'style', 'font-style:italic;color:#646970;font-size:12px;margin:0;' );
		beforeNode.appendChild( captionEl );

		var afterNode = document.createElement( 'div' );

		var afterTa = document.createElement( 'textarea' );
		afterTa.readOnly = true;
		afterTa.rows = 4;
		afterTa.value = altText;
		afterTa.setAttribute( 'style', 'width:100%;font-family:inherit;font-size:13px;background:#f6f7f7;color:#1d2327;border:1px solid #c3c4c7;border-radius:4px;padding:8px;' );
		afterNode.appendChild( afterTa );

		var countEl = document.createElement( 'p' );
		countEl.textContent = altText.length + ' ' + __( 'chars', 'signal-noise-tools' );
		countEl.setAttribute( 'style', 'text-align:right;font-size:11px;color:#646970;margin:4px 0 0 0;' );
		afterNode.appendChild( countEl );

		return { before: beforeNode, after: afterNode };
	}

	/**
	 * Build modal Before+After nodes for the drift-phrase variant.
	 *
	 * @param {object}  res          Suggest response (has suggestion)
	 * @param {object}  input        Input from onApplyClick (has phrase, position, context_snippet)
	 * @param {string}  replacement  The user-edited replacement text (from textarea)
	 * @return {{ before: Element, after: Element }}
	 */
	function buildDriftModalContent( res, input, replacement ) {
		var snippet = input.context_snippet || '';
		var phrase  = input.phrase || '';
		var snippetPhraseIndex = snippet.indexOf( phrase );

		var beforeNode = document.createElement( 'div' );
		beforeNode.setAttribute( 'style', 'white-space:pre-wrap;font-family:monospace;font-size:13px;line-height:1.5;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:12px;' );

		if ( snippetPhraseIndex >= 0 ) {
			var beforeLeft = document.createElement( 'span' );
			beforeLeft.textContent = snippet.substring( 0, snippetPhraseIndex );
			beforeNode.appendChild( beforeLeft );

			var beforePhrase = document.createElement( 'span' );
			beforePhrase.textContent = phrase;
			beforePhrase.setAttribute( 'style', 'background:#fde8e8;color:#8b1a1a;padding:2px 4px;border-radius:2px;font-weight:600;' );
			beforeNode.appendChild( beforePhrase );

			var beforeRight = document.createElement( 'span' );
			beforeRight.textContent = snippet.substring( snippetPhraseIndex + phrase.length );
			beforeNode.appendChild( beforeRight );
		} else {
			// Phrase not found in snippet — fallback to plain snippet.
			beforeNode.textContent = snippet;
		}

		var afterNode = document.createElement( 'div' );
		afterNode.setAttribute( 'style', 'white-space:pre-wrap;font-family:monospace;font-size:13px;line-height:1.5;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:12px;' );

		if ( snippetPhraseIndex >= 0 ) {
			var afterLeft = document.createElement( 'span' );
			afterLeft.textContent = snippet.substring( 0, snippetPhraseIndex );
			afterNode.appendChild( afterLeft );

			var afterPhrase = document.createElement( 'span' );
			afterPhrase.textContent = replacement;
			afterPhrase.setAttribute( 'style', 'background:#e8f5e9;color:#0a5a1a;padding:2px 4px;border-radius:2px;font-weight:600;' );
			afterNode.appendChild( afterPhrase );

			var afterRight = document.createElement( 'span' );
			afterRight.textContent = snippet.substring( snippetPhraseIndex + phrase.length );
			afterNode.appendChild( afterRight );
		} else {
			afterNode.textContent = snippet;
		}

		return { before: beforeNode, after: afterNode };
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
		} else if ( 'missing_alt_inline' === checkType ) {
			// v4.0.2: inline-<img> findings carry post_id + image_src
			input.post_id   = parseInt( btn.getAttribute( 'data-post-id' ), 10 );
			input.image_src = btn.getAttribute( 'data-image-src' ) || '';
			if ( ! input.post_id || ! input.image_src ) {
				renderError( cell, __( 'Missing finding data.', 'signal-noise-tools' ) );
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
		} else if ( 'orphaned_media' === checkType ) {
			input.attachment_id = parseInt( btn.getAttribute( 'data-attachment-id' ), 10 );
			if ( ! input.attachment_id ) {
				renderError( cell, __( 'Missing attachment ID.', 'signal-noise-tools' ) );
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
		// v4.1.0: verdict-shaped responses (orphaned_media) take a different render path.
		if ( res && res.verdict ) {
			renderVerdictSuggestion( cell, res, applyAbility, input, checkType );
			return;
		}

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

		if ( null === applyAbility ) {
			// v4.0.2: no-apply variant — read-only textarea + Copy button + helper text.
			// Used by Suggest-only check types like missing_alt_inline (inline-<img> alt
			// where Apply is deferred indefinitely per block-serialization risk).
			ta.readOnly = true;
			setStatus( status, __( 'Open the editor to apply.', 'signal-noise-tools' ), 'info' );

			var copyBtn = document.createElement( 'button' );
			copyBtn.type = 'button';
			copyBtn.className = 'button button-small';
			copyBtn.textContent = __( 'Copy', 'signal-noise-tools' );
			copyBtn.addEventListener( 'click', function() {
				if ( window.navigator && window.navigator.clipboard ) {
					window.navigator.clipboard.writeText( ta.value ).then( function() {
						setStatus( status, __( 'Copied to clipboard.', 'signal-noise-tools' ), 'ok' );
					}, function() {
						setStatus( status, __( 'Copy failed.', 'signal-noise-tools' ), 'err' );
					} );
				} else {
					setStatus( status, __( 'Clipboard API not available.', 'signal-noise-tools' ), 'err' );
				}
			} );
			actions.appendChild( copyBtn );
		} else {
			// Standard Apply + Discard flow (unchanged from v4.0.0).
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
		}

		wrap.appendChild( actions );
		cell.appendChild( wrap );
	}

	/**
	 * v4.1.0: render a verdict-shaped Suggest response (orphaned_media).
	 *
	 * Three branches:
	 *   verdict=delete → Delete button (red) + Discard
	 *   verdict=keep   → "✓ Likely keep" + reason + Discard (no Apply)
	 *   verdict=unsure → "? Manual review" + reason (no Apply; Edit link is in the adjacent Action cell, rendered by PHP)
	 *
	 * @param {Element} cell
	 * @param {object}  res          Suggest response with { verdict, reason, attachment_id, thumbnail_url, filename }
	 * @param {string}  applyAbility ability slug for Apply (used in delete branch)
	 * @param {object}  input        Input from onSuggestClick (has attachment_id)
	 * @param {string}  checkType    'orphaned_media'
	 */
	function renderVerdictSuggestion( cell, res, applyAbility, input, checkType ) {
		while ( cell.firstChild ) { cell.removeChild( cell.firstChild ); }

		var wrap = document.createElement( 'div' );
		wrap.className = 'snt-verdict-panel';
		wrap.setAttribute( 'style', 'display:flex;flex-direction:column;gap:6px;' );

		var headline = document.createElement( 'div' );
		headline.setAttribute( 'style', 'font-size:12px;font-weight:600;' );

		var reasonEl = document.createElement( 'div' );
		reasonEl.setAttribute( 'style', 'font-size:11px;color:#646970;' );
		reasonEl.textContent = res.reason || '';

		var actions = document.createElement( 'div' );
		actions.setAttribute( 'style', 'display:flex;gap:6px;flex-wrap:wrap;' );

		// `status` is only used by the delete branch (passed into onOrphanDeleteClick
		// so async progress/errors can be surfaced inline). Declared here only because
		// the delete branch closes over it; keep/unsure branches don't render it.
		var status = document.createElement( 'span' );
		status.className = 'snt-suggest-status';
		status.setAttribute( 'style', 'font-size:11px;color:#646970;' );

		if ( 'delete' === res.verdict ) {
			headline.textContent = '⚠ ' + __( 'Likely orphan — safe to delete', 'signal-noise-tools' );
			headline.style.color = '#8b1a1a';
			wrap.appendChild( headline );
			wrap.appendChild( reasonEl );

			var deleteBtn = document.createElement( 'button' );
			deleteBtn.type = 'button';
			deleteBtn.className = 'button button-small';
			deleteBtn.style.color = '#8b1a1a';
			deleteBtn.style.borderColor = '#8b1a1a';
			deleteBtn.textContent = __( 'Delete', 'signal-noise-tools' );
			deleteBtn.addEventListener( 'click', function() {
				onOrphanDeleteClick( cell, status, deleteBtn, applyAbility, input, res );
			} );
			actions.appendChild( deleteBtn );

			var discardBtn = document.createElement( 'button' );
			discardBtn.type = 'button';
			discardBtn.className = 'button button-small';
			discardBtn.textContent = __( 'Discard', 'signal-noise-tools' );
			discardBtn.addEventListener( 'click', function() {
				resetCellToSuggestButton( cell, checkType, input, res );
			} );
			actions.appendChild( discardBtn );

			wrap.appendChild( actions );
			wrap.appendChild( status );
		} else if ( 'keep' === res.verdict ) {
			headline.textContent = '✓ ' + __( 'Likely keep — false positive', 'signal-noise-tools' );
			headline.style.color = '#0a5a1a';
			wrap.appendChild( headline );
			wrap.appendChild( reasonEl );

			var discardBtnKeep = document.createElement( 'button' );
			discardBtnKeep.type = 'button';
			discardBtnKeep.className = 'button button-small';
			discardBtnKeep.textContent = __( 'Discard', 'signal-noise-tools' );
			discardBtnKeep.addEventListener( 'click', function() {
				resetCellToSuggestButton( cell, checkType, input, res );
			} );
			actions.appendChild( discardBtnKeep );

			wrap.appendChild( actions );
		} else {
			// 'unsure' (and any other verdict that slipped through).
			headline.textContent = '? ' + __( 'Manual review', 'signal-noise-tools' );
			headline.style.color = '#6e4d00';
			wrap.appendChild( headline );
			wrap.appendChild( reasonEl );
			// No Apply button. The existing row's [Edit] link in the adjacent
			// Action cell handles "open the attachment for manual review."
		}

		cell.appendChild( wrap );
	}

	/**
	 * v4.1.0: Handle Delete click for an orphan verdict=delete row.
	 *
	 * Opens the modal with thumbnail + filename + reason in the Before pane
	 * and a warning text in the After pane. On Apply (modal primary button,
	 * label stays "Apply" per spec), calls ai-orphan-apply ability.
	 */
	function onOrphanDeleteClick( cell, status, deleteBtn, applyAbility, input, res ) {
		var modalContent = buildOrphanDeleteModalContent( res );
		openApplyModal( {
			title:             __( 'Permanently delete this attachment?', 'signal-noise-tools' ),
			beforeNode:        modalContent.before,
			afterNode:         modalContent.after,
			originatingButton: deleteBtn,
			onApply:           function() { doOrphanDelete(); },
			onCancel:          function() { /* no-op */ },
		} );

		function doOrphanDelete() {
			deleteBtn.disabled = true;
			setStatus( status, __( 'Deleting…', 'signal-noise-tools' ), 'info' );
			callAbility( applyAbility, { attachment_id: input.attachment_id } )
				.then( function() {
					while ( cell.firstChild ) { cell.removeChild( cell.firstChild ); }
					var span = document.createElement( 'span' );
					span.setAttribute( 'style', 'color:#0a5a1a;font-weight:600;' );
					span.textContent = '✓ ' + __( 'Deleted', 'signal-noise-tools' );
					cell.appendChild( span );
					var row = cell.closest( 'tr' );
					if ( row ) { row.style.opacity = '0.5'; }
				} )
				.catch( function( err ) {
					setStatus( status, __( 'Failed', 'signal-noise-tools' ) + ': ' + err.message, 'err' );
					deleteBtn.disabled = false;
				} );
		}
	}

	/**
	 * v4.1.0: Build modal Before+After nodes for the orphan-delete confirmation.
	 *
	 * Before pane: attachment thumbnail + filename + AI reason text.
	 * After pane: warning text ("This deletes the file and DB record. No undo.").
	 *
	 * @param {object} res Suggest response with { thumbnail_url, filename, reason }
	 * @return {{ before: Element, after: Element }}
	 */
	function buildOrphanDeleteModalContent( res ) {
		var beforeNode = document.createElement( 'div' );

		if ( res.thumbnail_url && '' !== res.thumbnail_url ) {
			var img = document.createElement( 'img' );
			img.src = res.thumbnail_url;
			img.setAttribute( 'style', 'max-width:160px;max-height:160px;display:block;border-radius:4px;border:1px solid #e0e0e0;' );
			beforeNode.appendChild( img );
		}

		var filenameEl = document.createElement( 'p' );
		filenameEl.textContent = res.filename || ( '#' + ( res.attachment_id || 0 ) );
		filenameEl.setAttribute( 'style', 'font-family:monospace;font-size:12px;margin:8px 0 4px 0;color:#1d2327;word-break:break-all;' );
		beforeNode.appendChild( filenameEl );

		var reasonEl = document.createElement( 'p' );
		reasonEl.textContent = res.reason || '';
		reasonEl.setAttribute( 'style', 'font-style:italic;color:#646970;font-size:12px;margin:0;' );
		beforeNode.appendChild( reasonEl );

		var afterNode = document.createElement( 'div' );
		afterNode.setAttribute( 'style', 'background:#fde8e8;border:1px solid #8b1a1a;border-radius:4px;padding:12px;' );

		var warnEl = document.createElement( 'p' );
		warnEl.textContent = __( 'This permanently deletes the attachment file and database record. No undo.', 'signal-noise-tools' );
		warnEl.setAttribute( 'style', 'margin:0;color:#8b1a1a;font-size:13px;font-weight:600;' );
		afterNode.appendChild( warnEl );

		var noteEl = document.createElement( 'p' );
		noteEl.textContent = __( 'If this attachment is used in a widget, customizer setting, or theme template, you will see a broken image on the site.', 'signal-noise-tools' );
		noteEl.setAttribute( 'style', 'margin:8px 0 0 0;color:#646970;font-size:11px;' );
		afterNode.appendChild( noteEl );

		return { before: beforeNode, after: afterNode };
	}

	/**
	 * Handle Apply button click. Confirms, calls the apply ability,
	 * marks the row done on success or surfaces the error on failure.
	 */
	function onApplyClick( cell, textarea, status, applyBtn, applyAbility, suggestInput, checkType, suggestRes ) {
		// v4.0.3: replace window.confirm() with Before/After preview modal.
		var modalTitle;
		var modalContent;
		var currentEditedValue = textarea.value;

		if ( 'missing_alt' === checkType ) {
			modalTitle   = __( 'Apply alt text to attachment?', 'signal-noise-tools' );
			modalContent = buildAttachmentAltModalContent( suggestRes, suggestInput, currentEditedValue );
		} else {
			modalTitle   = __( 'Replace phrase in post?', 'signal-noise-tools' );
			modalContent = buildDriftModalContent( suggestRes, suggestInput, currentEditedValue );
		}

		openApplyModal( {
			title:             modalTitle,
			beforeNode:        modalContent.before,
			afterNode:         modalContent.after,
			originatingButton: applyBtn,
			onApply:           function() { doApply(); },
			onCancel:          function() { /* no-op */ },
		} );

		function doApply() {
			applyBtn.disabled = true;
			setStatus( status, __( 'Applying…', 'signal-noise-tools' ), 'info' );

			// Build apply input from suggest input + textarea value + suggest response.
			var applyInput;
			if ( 'missing_alt' === checkType ) {
				applyInput = {
					attachment_id: suggestInput.attachment_id,
					alt_text:      currentEditedValue,
				};
			} else {
				applyInput = {
					post_id:     suggestInput.post_id,
					phrase:      suggestInput.phrase,
					position:    suggestInput.position,
					replacement: currentEditedValue,
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
		} else if ( 'orphaned_media' === checkType ) {
			btn.setAttribute( 'data-attachment-id', input.attachment_id );
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
