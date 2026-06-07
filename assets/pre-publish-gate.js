/**
 * Signal & Noise Tools — pre-publish mistake gate (v4.11.0).
 *
 * Registers a PluginPrePublishPanel (from @wordpress/editor) that lists
 * *advisory* warnings about easy-to-miss mistakes before a post/page is
 * published. Advisory only — it never calls lockPostSaving, so the author
 * can publish regardless. 100% client-side; makes ZERO AI / network calls.
 *
 * Checks (computed reactively via wp.data.useSelect on core/editor):
 *   - noindex left ON          → post & page
 *   - empty SN meta description → post & page
 *   - zero tags                → post only
 *
 * No JSX (classic-script IIFE, matching assets/command-palette.js): every
 * node is built with wp.element.createElement.
 *
 * Verified against WordPress/gutenberg trunk (2026-06-07):
 *   - packages/editor/src/components/index.js exports PluginPrePublishPanel.
 *   - plugin-pre-publish-panel/index.js props: { children, title, initialOpen, ... }.
 *   - store/selectors.js: getCurrentPostType(); getEditedPostAttribute('meta')
 *     merges saved + edits; getEditedPostAttribute('tags') is an array of
 *     term ids (empty array when none).
 *
 * @since plugin v4.11.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp ) {
		return;
	}
	var wp = window.wp;

	// Editor-only surface: bail cleanly outside the block editor (the script
	// is only enqueued on post.php / post-new.php, but guard anyway).
	if ( ! wp.plugins || ! wp.editor || ! wp.element || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = ( wp.i18n && wp.i18n.__ ) || function( s ) { return s; };
	var PluginPrePublishPanel = wp.editor.PluginPrePublishPanel;
	if ( ! PluginPrePublishPanel ) {
		return;
	}

	// Compute the advisory warning strings from an editor-store selector.
	// Returns a (possibly empty) array of plain strings. Takes the selected
	// `core/editor` store object so the caller can subscribe via useSelect.
	function computeWarnings( editor ) {
		if ( ! editor ) {
			return [];
		}

		var postType = editor.getCurrentPostType();
		var meta = editor.getEditedPostAttribute( 'meta' ) || {};
		var warnings = [];

		// noindex left ON — applies to posts AND pages.
		if ( meta._sn_noindex ) {
			warnings.push( __( 'Search-engine indexing is turned OFF (noindex). This page won’t appear in search results.', 'signal-noise-tools' ) );
		}

		// Empty SN meta description — applies to posts AND pages.
		var metaDesc = meta._sn_meta_description;
		if ( ! metaDesc || ! String( metaDesc ).trim() ) {
			warnings.push( __( 'No meta description set. Search engines will guess one from the body.', 'signal-noise-tools' ) );
		}

		// Zero tags — posts only (pages aren’t tagged).
		if ( 'post' === postType ) {
			var tags = editor.getEditedPostAttribute( 'tags' );
			var tagCount = Array.isArray( tags ) ? tags.length : 0;
			if ( tagCount === 0 ) {
				warnings.push( __( 'No tags assigned. Tags help readers discover related posts.', 'signal-noise-tools' ) );
			}
		}

		return warnings;
	}

	function render() {
		// useSelect SUBSCRIBES this component to core/editor so the advisory
		// list recomputes as the author edits meta/tags. A bare wp.data.select()
		// would run ONCE at editor mount and go stale: PluginArea only re-renders
		// plugins on plugin-registry changes, never on store changes (verified
		// vs gutenberg trunk packages/plugins/.../plugin-area). render() is
		// mounted by PluginArea as a function component, so this hook is valid.
		var warnings = wp.data.useSelect( function( select ) {
			return computeWarnings( select( 'core/editor' ) );
		}, [] );

		var body;
		if ( warnings.length === 0 ) {
			body = el(
				'p',
				{ style: { margin: 0 } },
				__( 'No issues spotted. Looks good to publish.', 'signal-noise-tools' )
			);
		} else {
			body = el(
				'ul',
				{ style: { margin: 0, paddingLeft: '1.2em', listStyle: 'disc' } },
				warnings.map( function( text, i ) {
					return el( 'li', { key: i, style: { marginBottom: '0.4em' } }, text );
				} )
			);
		}

		return el(
			PluginPrePublishPanel,
			{
				title: __( 'Signal & Noise checks', 'signal-noise-tools' ),
				initialOpen: true,
			},
			body
		);
	}

	wp.plugins.registerPlugin( 'snt-pre-publish-gate', { render: render } );
} )();
