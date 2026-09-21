/**
 * Signal & Noise Tools: AI excerpt action.
 *
 * Enqueued on post.php / post-new.php via inc/ai-excerpt.php (only when
 * snt_ai_is_available() returns true). Registers a standalone "Generate
 * excerpt with AI" action on window.sntPostSettingsActions; the Signal &
 * Noise document panel (assets/post-settings-panel.js, #1608) paints it as
 * its last row.
 *
 * On run:
 *   1. the ability generates a 50-75 word excerpt
 *   2. the result is written to WP's native excerpt via
 *      wp.data.dispatch('core/editor').editPost({ excerpt: result }),
 *      which works whether or not the Excerpt panel is open because it
 *      goes through the data layer instead of the DOM.
 *
 * @since plugin v2.4.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp ) {
		return;
	}
	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function( s ) { return s; };

	function writeExcerpt( text ) {
		var editor = window.wp.data && window.wp.data.dispatch( 'core/editor' );
		if ( ! editor || 'function' !== typeof editor.editPost ) {
			return false;
		}
		editor.editPost( { excerpt: text } );
		return true;
	}

	window.sntPostSettingsActions = window.sntPostSettingsActions || [];
	window.sntPostSettingsActions.push( {
		id: 'ai-excerpt',
		title: __( 'AI helpers', 'signal-noise-tools' ),
		help: __( 'Generates a 50-75 word excerpt from the post content and writes it to the WordPress Excerpt field (Summary panel, Excerpt).', 'signal-noise-tools' ),
		label: __( 'Generate excerpt with AI', 'signal-noise-tools' ),
		run: function( postId ) {
			// v7.7.2: via the shared runner. ai-generate-excerpt is annotated
			// readonly (it returns text; the editor writes the field), so the
			// controller requires GET; the old hardcoded POST 405'd.
			return window.sntAbilityRun( 'ai-generate-excerpt', { post_id: postId } )
				.then( function( res ) {
					if ( ! res || ! res.excerpt ) {
						throw new Error( __( 'AI returned no excerpt.', 'signal-noise-tools' ) );
					}
					if ( ! writeExcerpt( res.excerpt ) ) {
						throw new Error( __( 'Could not write to excerpt field.', 'signal-noise-tools' ) );
					}
					return {
						note: __( 'Generated', 'signal-noise-tools' ) + ' · ' + res.words + ' ' + __( 'words', 'signal-noise-tools' ) + ' · ' + __( 'written to Excerpt panel', 'signal-noise-tools' )
					};
				} );
		}
	} );
} )();
