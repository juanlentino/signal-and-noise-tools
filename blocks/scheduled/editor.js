( function ( blocks, element, blockEditor, components ) {
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
				)
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
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );
