( function ( blocks, element, blockEditor, components, data ) {
	'use strict';

	// Buildless dynamic block: NO JSX, NO import, NO build step. Everything is
	// plain createElement against the window.wp.* globals, mirroring the sibling
	// theme's blocks/editor.js. The editorScript handle (signal-noise-scheduled
	// -editor) is registered MANUALLY in PHP with explicit deps; a file: path
	// would load with empty deps and throw 'wp is undefined' (no .asset.php in a
	// no-build repo).
	var el            = element.createElement;
	var Fragment      = element.Fragment;
	var useEffect     = element.useEffect;
	var useBlockProps = blockEditor.useBlockProps;
	var InnerBlocks   = blockEditor.InnerBlocks;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody       = components.PanelBody;
	var DateTimePicker  = components.DateTimePicker;
	var Button          = components.Button;

	// A stable id for the block instance so the save_post sync (Task 5) can
	// address this fragment's queue row idempotently. Prefer the platform uuid;
	// fall back to a timestamp + random string when crypto.randomUUID is absent.
	function makeScheduleId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return 'sn-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	// Human-readable echo of the configured window for the editor-only badge.
	// The editor ALWAYS shows the inner blocks and this badge; only the FRONT
	// END gates on the window, so the badge is the author's sole cue.
	function windowLabel( from, until ) {
		var f = ( from || '' ).trim();
		var u = ( until || '' ).trim();
		if ( '' === f && '' === u ) {
			return 'Always visible (no window set)';
		}
		return 'Scheduled · from ' + ( '' !== f ? f : 'always' ) + ' until ' + ( '' !== u ? u : 'forever' );
	}

	// ─── Version swap helpers (v8.0.0, scheduled-content Phase 3) ─────
	// A swap = two sn/scheduled siblings sharing a swapId: the 'hide' block
	// (current version, gates off at T via `until`) and the 'show' block (new
	// version, gates on at T via `from`). swapId/swapRole live ONLY in the
	// block JSON — the server derives pairs from the boundary equality the
	// controls below keep in lockstep.

	// Depth-first walk of the editor block tree for the partner clientId.
	function findSwapPartner( blockList, swapId, ownClientId ) {
		for ( var i = 0; i < blockList.length; i++ ) {
			var b = blockList[ i ];
			if (
				'signal-noise/scheduled' === b.name &&
				b.clientId !== ownClientId &&
				b.attributes && b.attributes.swapId === swapId
			) {
				return b;
			}
			if ( b.innerBlocks && b.innerBlocks.length ) {
				var hit = findSwapPartner( b.innerBlocks, swapId, ownClientId );
				if ( hit ) {
					return hit;
				}
			}
		}
		return null;
	}

	// One gesture: stamp THIS block as the current version (hides at T) and
	// insert its paired new-version container (reveals at T) right after it.
	function createVersionSwap( clientId, attributes, setAttributes ) {
		var sel      = data.select( 'core/block-editor' );
		var dispatch = data.dispatch( 'core/block-editor' );
		var swapId   = makeScheduleId();
		var swapAt   = ( attributes.until || '' ).trim();

		setAttributes( { swapId: swapId, swapRole: 'hide' } );

		var partner = blocks.createBlock( 'signal-noise/scheduled', {
			scheduleId: makeScheduleId(),
			swapId:     swapId,
			swapRole:   'show',
			from:       swapAt,
			until:      ''
		} );

		var rootId = sel.getBlockRootClientId( clientId );
		var index  = sel.getBlockIndex( clientId, rootId ) + 1;
		dispatch.insertBlock( partner, index, rootId || undefined );
	}

	// The single swap-instant control: writes THIS block's boundary AND the
	// partner's complementary boundary in one change, keeping the pair's
	// boundaries equal (the server-side pairing predicate).
	function setSwapInstant( value, props, partner ) {
		var v        = value || '';
		var dispatch = data.dispatch( 'core/block-editor' );
		var mineIsHide = 'hide' === props.attributes.swapRole;

		props.setAttributes( mineIsHide ? { until: v } : { from: v } );
		if ( partner ) {
			dispatch.updateBlockAttributes(
				partner.clientId,
				mineIsHide ? { from: v } : { until: v }
			);
		}
	}

	blocks.registerBlockType( 'signal-noise/scheduled', {
		edit: function ( props ) {
			var attributes   = props.attributes;
			var setAttributes = props.setAttributes;

			// Generate scheduleId ONCE on mount if it is still empty. The empty
			// dependency array runs this exactly once per editor session for a
			// fresh block; an already-stamped block is left untouched.
			useEffect( function () {
				if ( ! attributes.scheduleId ) {
					setAttributes( { scheduleId: makeScheduleId() } );
				}
			}, [] );

			var blockProps = useBlockProps( { className: 'sn-scheduled' } );

			// Version-swap pairing state (v8.0.0). The partner lookup runs on
			// each render — the tree is in-memory and posts hold few scheduled
			// blocks, so this stays cheap.
			var isSwap  = '' !== ( attributes.swapId || '' ) && '' !== ( attributes.swapRole || '' );
			var partner = null;
			if ( isSwap ) {
				partner = findSwapPartner(
					data.select( 'core/block-editor' ).getBlocks(),
					attributes.swapId,
					props.clientId
				);
			}
			var swapInstant = 'hide' === attributes.swapRole ? attributes.until : attributes.from;

			var swapPanel;
			if ( ! isSwap ) {
				swapPanel = el( PanelBody, { title: 'Version swap', initialOpen: false },
					el( 'p', null, 'Turn this container into a whole-page version swap: this content becomes the current version (hides at the swap time) and a paired container for the new version is inserted after it (reveals at the same instant).' ),
					el( Button, {
						variant: 'secondary',
						onClick: function () { createVersionSwap( props.clientId, attributes, setAttributes ); }
					}, 'Create version swap' )
				);
			} else {
				swapPanel = el( PanelBody, { title: 'Version swap', initialOpen: true },
					el( 'p', { className: 'sn-scheduled__badge' },
						'hide' === attributes.swapRole
							? 'Current version — hides at the swap time.'
							: 'New version — reveals at the swap time.'
					),
					partner
						? el( 'p', { style: { fontWeight: 600, margin: '12px 0 4px' } }, 'Swap at (UTC) — sets both containers' )
						: el( 'p', null, 'Paired container not found in this post (removed?). The swap time below edits this block only.' ),
					el( DateTimePicker, {
						currentDate: swapInstant || null,
						onChange: function ( value ) { setSwapInstant( value, props, partner ); },
						is12Hour: false
					} )
				);
			}

			var inspector = el( InspectorControls, null,
				el( PanelBody, { title: 'Schedule', initialOpen: true },
					el( 'p', { className: 'sn-scheduled__badge' }, windowLabel( attributes.from, attributes.until ) ),
					el( 'p', { style: { fontWeight: 600, margin: '12px 0 4px' } }, 'Reveal from (UTC)' ),
					el( DateTimePicker, {
						currentDate: attributes.from || null,
						onChange: function ( value ) { setAttributes( { from: value || '' } ); },
						is12Hour: false
					} ),
					el( 'p', { style: { fontWeight: 600, margin: '12px 0 4px' } }, 'Hide after (UTC)' ),
					el( DateTimePicker, {
						currentDate: attributes.until || null,
						onChange: function ( value ) { setAttributes( { until: value || '' } ); },
						is12Hour: false
					} )
				),
				swapPanel
			);

			// The editor always renders the inner blocks plus an inline badge so
			// the author can see the gated content and its window at a glance.
			var body = el( 'div', blockProps,
				el( 'p', { className: 'sn-scheduled__badge' }, windowLabel( attributes.from, attributes.until ) ),
				el( InnerBlocks )
			);

			return el( Fragment, null, inspector, body );
		},

		// Dynamic block: inc/schedule-block.php gates the front end on the date
		// window. save() MUST emit InnerBlocks.Content (NOT null) so the
		// hand-authored inner blocks are serialized into post_content and reach
		// the PHP render_callback as its $content argument. (This differs from the
		// theme's attribute-driven sidenote/pull-quote, which use save: null.)
		save: function () {
			return el( InnerBlocks.Content );
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.data );
