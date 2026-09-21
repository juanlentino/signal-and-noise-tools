/**
 * Signal & Noise Tools: AI meta description action.
 *
 * Enqueued on post.php / post-new.php via inc/ai-meta-description.php
 * (only when snt_ai_is_available() returns true). Registers a "Generate
 * with AI" action on window.sntPostSettingsActions; the Signal & Noise
 * document panel (assets/post-settings-panel.js, #1608) paints it under
 * the Meta description field and writes the result into the post entity,
 * so the pre-publish gate sees the suggestion before it is saved.
 *
 * Before #1608 this file polled the DOM for the meta box textarea and
 * appended a button; the panel owns the field now, so there is nothing to
 * poll for.
 *
 * @since plugin v1.16.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp ) {
		return;
	}
	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function( s ) { return s; };

	window.sntPostSettingsActions = window.sntPostSettingsActions || [];
	window.sntPostSettingsActions.push( {
		id: 'ai-meta-description',
		field: '_sn_meta_description',
		label: __( 'Generate with AI', 'signal-noise-tools' ),
		run: function( postId ) {
			// v7.7.2: via the shared runner (annotation-derived verb).
			return window.sntAbilityRun( 'ai-generate-meta-description', { post_id: postId } )
				.then( function( res ) {
					if ( ! res || ! res.description ) {
						throw new Error( __( 'AI returned no description.', 'signal-noise-tools' ) );
					}
					return {
						value: res.description,
						note: __( 'Generated', 'signal-noise-tools' ) + ' · ' + res.length + ' ' + __( 'chars', 'signal-noise-tools' )
					};
				} );
		}
	} );
} )();
