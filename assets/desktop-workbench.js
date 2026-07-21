/**
 * Signal & Noise Tools — "S&N Workbench" native Desktop Mode window.
 *
 * One queue engine drives both panes (Migrations + Patterns): scan →
 * candidate rows → Preview (suggest ability, inline markup preview,
 * never a silent mutation) → Apply / Dismiss. Transport is exclusively
 * the abilities run-path via window.sntAbilityRun (annotation-derived
 * verbs — see assets/health-suggest-actions.js, the admin-side sibling
 * of this engine).
 *
 * Ability contracts (pinned against inc/*-detect.php, *-suggest.php,
 * *-apply.php, abilities-dismiss.php — do NOT invent keys, a wrong key
 * cost a release once):
 *   scan     → { candidates: [ { post_id, block_fingerprint,
 *                migration_type|pattern_type, post_title, permalink,
 *                block_path, … } ], counts, scanned_at }
 *   suggest  ← { post_id, block_fingerprint, migration_type|pattern_type }
 *            → { ok, suggestion_markup, fingerprint, … }
 *   apply    ← { post_id, block_fingerprint, replacement_markup,
 *                migration_type|pattern_type }
 *   dismiss  ← { surface, post_id, block_fingerprint, candidate_type }
 *
 * MOUNT CONTRACT: window.desktopModeNativeWindows['sn-workbench'](body)
 * — the shell clones the registered templates first, so every mount
 * point exists; the return value is the teardown.
 *
 * @since plugin v9.77.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var PANES = [
		{
			surface:  'block-migrations',
			typeKey:  'migration_type',
			scan:     'block-migrations-scan',
			suggest:  'block-migrations-suggest',
			apply:    'block-migrations-apply',
			roles:    { list: 'mig-list', count: 'mig-count', note: 'mig-note', refresh: 'mig-refresh' },
			describe: function ( c ) {
				if ( 'heading-hierarchy-skip' === c.migration_type ) {
					return 'H' + ( c.current_level || 3 ) + ' → H' + ( c.target_level || 2 ) + ' (hierarchy skip)';
				}
				return String( c.migration_type || 'migration' );
			},
		},
		{
			surface:  'pattern-adoption',
			typeKey:  'pattern_type',
			scan:     'pattern-adoption-scan',
			suggest:  'pattern-adoption-suggest',
			apply:    'pattern-adoption-apply',
			roles:    { list: 'pat-list', count: 'pat-count', note: 'pat-note', refresh: 'pat-refresh' },
			describe: function ( c ) {
				return String( c.pattern_type || 'pattern' ) + ' adoption';
			},
		},
	];

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.title != null ) { node.title = opts.title; }
		if ( opts.href != null ) { node.href = opts.href; node.target = '_blank'; node.rel = 'noopener'; }
		return node;
	}

	function toast( title, body ) {
		if ( window.wp && window.wp.desktop && typeof window.wp.desktop.notify === 'function' ) {
			window.wp.desktop.notify( { title: title, body: body || '' } );
		}
	}

	function abilityError( err ) {
		return ( err && err.message ) ? err.message : 'The ability call failed.';
	}

	/**
	 * One candidate row: title line + action strip; Preview expands an
	 * inline <pre> with the suggested markup and arms Apply. Nothing
	 * mutates without a visible preview first.
	 */
	function renderRow( pane, body, candidate, note ) {
		var row = el( 'li', { className: 'sn-wb-row' } );

		var head = el( 'div', { className: 'sn-wb-row-head' } );
		var title = el( 'a', {
			className: 'sn-wb-row-title',
			text:      candidate.post_title || ( 'Post #' + candidate.post_id ),
			title:     candidate.post_title || '',
		} );
		if ( candidate.permalink ) { title.href = candidate.permalink; title.target = '_blank'; title.rel = 'noopener'; }
		head.appendChild( title );
		head.appendChild( el( 'span', { className: 'sn-wb-row-kind', text: pane.describe( candidate ) } ) );
		row.appendChild( head );

		var actions   = el( 'div', { className: 'sn-wb-row-actions' } );
		var previewBt = el( 'button', { className: 'sn-wb-btn', text: 'Preview' } );
		var applyBt   = el( 'button', { className: 'sn-wb-btn sn-wb-btn-apply', text: 'Apply' } );
		var dismissBt = el( 'button', { className: 'sn-wb-btn sn-wb-btn-quiet', text: 'Dismiss' } );
		var status    = el( 'span', { className: 'sn-wb-row-status' } );
		applyBt.type = previewBt.type = dismissBt.type = 'button';
		applyBt.disabled = true; // armed only by a successful preview
		actions.appendChild( previewBt );
		actions.appendChild( applyBt );
		actions.appendChild( dismissBt );
		actions.appendChild( status );
		row.appendChild( actions );

		var previewBox = el( 'pre', { className: 'sn-wb-preview' } );
		previewBox.hidden = true;
		row.appendChild( previewBox );

		var suggested = null;

		function busy( on ) {
			previewBt.disabled = on;
			dismissBt.disabled = on;
			applyBt.disabled   = on || null === suggested;
		}

		previewBt.addEventListener( 'click', function () {
			busy( true );
			status.textContent = 'Generating preview…';
			var input = { post_id: candidate.post_id, block_fingerprint: candidate.block_fingerprint };
			input[ pane.typeKey ] = candidate[ pane.typeKey ];
			window.sntAbilityRun( pane.suggest, input ).then( function ( res ) {
				res = res || {};
				if ( ! res.suggestion_markup ) {
					status.textContent = 'No suggestion returned — the block may have changed since the scan.';
					busy( false );
					return;
				}
				suggested = String( res.suggestion_markup );
				previewBox.textContent = suggested;
				previewBox.hidden = false;
				status.textContent = 'Preview ready — Apply writes this markup.';
				busy( false );
			} ).catch( function ( err ) {
				status.textContent = abilityError( err );
				busy( false );
			} );
		} );

		applyBt.addEventListener( 'click', function () {
			if ( null === suggested ) {
				return;
			}
			busy( true );
			status.textContent = 'Applying…';
			var input = {
				post_id:            candidate.post_id,
				block_fingerprint:  candidate.block_fingerprint,
				replacement_markup: suggested,
			};
			input[ pane.typeKey ] = candidate[ pane.typeKey ];
			window.sntAbilityRun( pane.apply, input ).then( function () {
				row.remove();
				note.bump( -1 );
				toast( 'S&N Workbench', 'Applied to "' + ( candidate.post_title || candidate.post_id ) + '".' );
			} ).catch( function ( err ) {
				status.textContent = abilityError( err );
				busy( false );
			} );
		} );

		dismissBt.addEventListener( 'click', function () {
			busy( true );
			status.textContent = 'Dismissing…';
			window.sntAbilityRun( 'dismiss-candidate', {
				surface:           pane.surface,
				post_id:           candidate.post_id,
				block_fingerprint: candidate.block_fingerprint,
				candidate_type:    String( candidate[ pane.typeKey ] || '' ),
			} ).then( function () {
				row.remove();
				note.bump( -1 );
			} ).catch( function ( err ) {
				status.textContent = abilityError( err );
				busy( false );
			} );
		} );

		return row;
	}

	function loadPane( pane, body ) {
		var list    = body.querySelector( '[data-role="' + pane.roles.list + '"]' );
		var countEl = body.querySelector( '[data-role="' + pane.roles.count + '"]' );
		var noteEl  = body.querySelector( '[data-role="' + pane.roles.note + '"]' );
		if ( ! list || ! countEl || ! noteEl ) {
			return;
		}

		var remaining = 0;
		var note = {
			bump: function ( d ) {
				remaining = Math.max( 0, remaining + d );
				countEl.textContent = remaining + ' pending';
				if ( 0 === remaining ) {
					noteEl.textContent = 'Queue clear.';
				}
			},
		};

		list.textContent   = '';
		noteEl.textContent = 'Scanning…';
		countEl.textContent = '—';

		if ( typeof window.sntAbilityRun !== 'function' ) {
			noteEl.textContent = 'The abilities client is unavailable.';
			return;
		}
		window.sntAbilityRun( pane.scan, {} ).then( function ( res ) {
			var candidates = ( res && res.candidates ) || [];
			remaining = candidates.length;
			countEl.textContent = remaining + ' pending';
			noteEl.textContent = remaining ? '' : 'Queue clear.';
			candidates.forEach( function ( c ) {
				list.appendChild( renderRow( pane, body, c, note ) );
			} );
		} ).catch( function ( err ) {
			noteEl.textContent = abilityError( err );
		} );
	}

	window.desktopModeNativeWindows = window.desktopModeNativeWindows || {};
	window.desktopModeNativeWindows[ 'sn-workbench' ] = function ( body ) {
		PANES.forEach( function ( pane ) {
			loadPane( pane, body );
			var refresh = body.querySelector( '[data-role="' + pane.roles.refresh + '"]' );
			if ( refresh ) {
				refresh.addEventListener( 'click', function () { loadPane( pane, body ); } );
			}
		} );
		// No timers to tear down — queues load on open and on demand.
		return function () {};
	};
} )();
