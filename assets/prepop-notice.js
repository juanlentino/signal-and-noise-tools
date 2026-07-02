/**
 * Signal & Noise Tools — prepop "auto-generated" notice dismiss handler.
 *
 * The notice itself is rendered server-side (inc/post-settings.php) inside
 * the SN meta box. This only wires the [Dismiss] button: POST the post id
 * to the dismiss route (wp.apiFetch injects the _wpnonce) and remove the
 * notice from the DOM. v4.8.0.
 */
( function () {
	'use strict';

	// v6.55.0: dismiss dispatches via the signal-noise/prepop-dismiss ability.
	// v7.7.2: through the shared runner (annotation-derived verb); the
	// localized restPath override is gone with the hardcoded path.

	function getPostId( el ) {
		var notice = el.closest( '.sn-prepop-notice' );
		if ( notice && notice.getAttribute( 'data-post' ) ) {
			return parseInt( notice.getAttribute( 'data-post' ), 10 );
		}
		if ( window.wp && window.wp.data && window.wp.data.select( 'core/editor' ) ) {
			return window.wp.data.select( 'core/editor' ).getCurrentPostId();
		}
		return 0;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.sn-prepop-dismiss' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var postId = getPostId( btn );
		var notice = btn.closest( '.sn-prepop-notice' );
		if ( notice ) {
			notice.style.display = 'none';
		}
		if ( postId && window.sntAbilityRun ) {
			window.sntAbilityRun( 'prepop-dismiss', { post_id: postId } ).catch( function () {
				if ( notice ) {
					notice.style.display = '';
				}
			} );
		}
	} );
} )();
