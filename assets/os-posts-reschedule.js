/**
 * Signal & Noise: Reschedule as a bulk action in OpenStation's native Posts window.
 *
 * The classic posts list carries a "Reschedule (Signal & Noise)" bulk action
 * with a datetime-local field in its toolbar (inc/batch-schedule.php). The
 * native window has neither, so this registers one entry on the window's
 * `openstation.postsWindow.bulkActions` filter. The date is asked in an
 * `<os-modal>` holding a native datetime-local input (the shell's confirm is
 * yes/no only), then POSTed to `signal-noise/v1/openstation/reschedule`, the
 * same write the classic action runs. Cancel keeps the selection; a verdict
 * is a toast carrying the classic sentence, both halves.
 *
 * The filter has no mode argument and is resolved once per window, so the
 * button also paints in the Pages window; `run` refuses there and the server
 * would too (it skips anything that is not a post).
 *
 * The write goes through the shell's own fetch helper, which stamps the
 * REST nonce header at call time, refreshed on every heartbeat tick. A nonce
 * localized at page load would be the wrong one after the shell (a PWA on
 * the desktop or the phone) has sat open past the nonce window.
 *
 * At load this depends on wp.hooks only: the shell's loader runs nothing
 * else, so the shell API is touched inside click-time functions alone.
 */
( function () {
	'use strict';

	var hooks = window.wp && window.wp.hooks;
	if ( ! hooks || typeof hooks.addFilter !== 'function' ) {
		return;
	}

	var cfg = window.sntOsPosts || {};

	function toast( message ) {
		window.wp.os.showToast( { message: message, duration: 8000 } );
	}

	// One modal, one promise: the datetime-local value, or '' on any cancel.
	function askDate( count ) {
		return window.wp.os.loadComponents( [ 'os-modal' ] ).then( function () {
			return new Promise( function ( resolve ) {
				var modal = document.createElement( 'os-modal' );
				modal.setAttribute( 'size', 'sm' );
				modal.setAttribute( 'title', 'Reschedule ' + count + ( count === 1 ? ' post' : ' posts' ) );

				var label = document.createElement( 'label' );
				label.style.cssText = 'display:block;margin:0 0 6px;';
				// The value is read as SITE time, as the classic field is.
				label.textContent = 'New date and time (site time' + ( cfg.timezone ? ', ' + cfg.timezone : '' ) + ')';
				var input = document.createElement( 'input' );
				input.type = 'datetime-local';
				input.required = true;
				input.id = 'snt-reschedule-date';
				// Fill the modal body as a kit field would; the phone's picker is native.
				input.style.cssText = 'width:100%;box-sizing:border-box;';
				label.htmlFor = input.id;
				modal.appendChild( label );
				modal.appendChild( input );

				var cancel = document.createElement( 'os-button' );
				cancel.setAttribute( 'variant', 'ghost' );
				cancel.setAttribute( 'slot', 'footer' );
				cancel.textContent = 'Cancel';
				var go = document.createElement( 'os-button' );
				go.setAttribute( 'variant', 'primary' );
				go.setAttribute( 'slot', 'footer' );
				go.textContent = 'Reschedule';
				modal.appendChild( cancel );
				modal.appendChild( go );

				function done( value ) {
					modal.remove();
					resolve( value );
				}
				cancel.addEventListener( 'click', function () { done( '' ); } );
				modal.addEventListener( 'os-modal-cancel', function () { done( '' ); } );
				go.addEventListener( 'click', function () {
					if ( ! input.value ) {
						input.focus();
						return;
					}
					done( input.value );
				} );

				document.body.appendChild( modal );
				modal.setAttribute( 'open', '' );
			} );
		} );
	}

	function run( ids, ctx ) {
		// The window's root carries `desktop-mode-pages` in pages mode
		// (apps/posts/parts/app.ts, rootClass); an undocumented class, so the
		// server's own post-type guard is the contract and this is a courtesy.
		if ( ctx.body.querySelector( '.desktop-mode-pages' ) ) {
			toast( 'Reschedule is for posts only.' );
			return false;
		}
		return askDate( ids.length ).then( function ( date ) {
			if ( ! date ) {
				return false; // cancelled: the selection survives, nothing refreshes.
			}
			// The shell's fetch: same-origin REST, the nonce header the heartbeat
			// keeps fresh, none of our own.
			return window.wp.os.fetch( cfg.rescheduleEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { ids: ids, date: date } )
			} ).then( function ( res ) {
				return res.json().catch( function () { return {}; } ).then( function ( body ) {
					if ( ! res.ok ) {
						// A 403 is the session itself gone, or the capability:
						// say so plainly, never retry.
						toast( res.status === 403
							? 'Not allowed, or your session expired: reload the shell and try again.'
							: ( body.message || 'Nothing was rescheduled.' ) );
						return false;
					}
					toast( body.message );
					return undefined; // the window clears the selection and refreshes.
				} );
			} );
		} ).catch( function () {
			// A dropped request (offline, a reset, the os-modal bundle failing to
			// load): the shell's runner treats a rejection as done and would
			// clear the selection and refresh with no verdict. Say so, keep it.
			toast( 'Nothing was rescheduled: the request did not reach the site.' );
			return false;
		} );
	}

	var ACTION = {
		id: 'snt-reschedule',
		label: 'Reschedule…',
		icon: 'dashicons-calendar-alt',
		variant: 'secondary',
		run: run
	};

	if ( cfg.rescheduleEndpoint ) {
		hooks.addFilter(
			'openstation.postsWindow.bulkActions',
			'signal-noise/reschedule',
			function ( actions ) {
				if ( ! Array.isArray( actions ) ) {
					return actions;
				}
				if ( actions.some( function ( a ) { return a && a.id === 'snt-reschedule'; } ) ) {
					return actions;
				}
				return actions.concat( [ ACTION ] );
			}
		);
	}
} )();
