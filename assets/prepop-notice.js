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

	// v6.55.0: dismiss dispatches via the signal-noise/prepop-dismiss ability
	// run-path (localized restPath, run-path fallback). The request wraps the
	// post id in { input } per the Abilities run contract.
	var cfg = window.sntPrepopNotice || {};
	var restPath = cfg.restPath || '/wp-abilities/v1/abilities/signal-noise/prepop-dismiss/run';

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
		if ( postId && window.wp && window.wp.apiFetch ) {
			window.wp.apiFetch( {
				path: restPath,
				method: 'POST',
				data: { input: { post_id: postId } },
			} ).catch( function () {
				if ( notice ) {
					notice.style.display = '';
				}
			} );
		}
	} );
} )();
