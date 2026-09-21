/**
 * Signal & Noise Tools: AI OG card title action.
 *
 * Enqueued on post.php / post-new.php via inc/ai-og-card-title.php (only
 * when snt_ai_is_available() returns true). Registers a "Generate with AI"
 * action on window.sntPostSettingsActions; the Signal & Noise document
 * panel (assets/post-settings-panel.js, #1608) paints it under the OG card
 * title field.
 *
 * On run: the ability generates a 60-90 char title, writes _sn_og_card_title
 * AND re-runs sn_generate_og_card() so the baked PNG reflects it at once;
 * the panel then mirrors the title into the post entity so the editor's
 * copy matches what the server holds.
 *
 * @since plugin v2.4.0
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp ) {
		return;
	}
	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function( s ) { return s; };

	window.sntPostSettingsActions = window.sntPostSettingsActions || [];
	window.sntPostSettingsActions.push( {
		id: 'ai-og-card-title',
		field: '_sn_og_card_title',
		label: __( 'Generate with AI', 'signal-noise-tools' ),
		run: function( postId ) {
			// v7.7.2: via the shared runner (annotation-derived verb).
			return window.sntAbilityRun( 'ai-generate-og-card-title', { post_id: postId } )
				.then( function( res ) {
					if ( ! res || ! res.title ) {
						throw new Error( __( 'AI returned no title.', 'signal-noise-tools' ) );
					}
					var note = __( 'Generated', 'signal-noise-tools' ) + ' · ' + res.length + ' ' + __( 'chars', 'signal-noise-tools' );
					if ( res.card_regenerated ) {
						note += ' · ' + __( 'card refreshed', 'signal-noise-tools' );
					}
					return { value: res.title, note: note };
				} );
		}
	} );
} )();
