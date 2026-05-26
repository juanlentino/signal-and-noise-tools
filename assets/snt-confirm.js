/**
 * Signal & Noise Tools — shared confirm-dialog utility.
 *
 * v4.1.1 replacement for the 7 lingering `window.confirm()` / `onclick="return confirm(...)"`
 * call sites across the admin. The native confirm() dialog is blocked entirely
 * inside the desktop-mode portal iframe (chrome-extension boundary) — meaning
 * those destructive buttons were unusable in that context. This utility renders
 * an in-page modal that always works.
 *
 * Two usage patterns:
 *
 * 1) **Imperative (from JS):**
 *      window.sntConfirm({
 *          title:        'Run cron event now?',
 *          message:      'This will fire the wp_version_check event immediately.',
 *          confirmLabel: 'Run',
 *          danger:       false,
 *      }).then(function (confirmed) {
 *          if ( confirmed ) { doTheThing(); }
 *      });
 *
 * 2) **Declarative (from PHP — replaces `onclick="return confirm(...)"`):**
 *      <button data-snt-confirm="Delete this webhook?"
 *              data-snt-confirm-title="Delete webhook?"
 *              data-snt-confirm-label="Delete"
 *              data-snt-confirm-danger="1"
 *              type="submit" name="sn_action" value="delete">Delete</button>
 *
 *    The global click handler intercepts the click, shows the modal, and only
 *    proceeds with the original submit/click if the user confirms. The
 *    confirmation is non-blocking — the original click event is cancelled
 *    immediately; if confirmed, the button is re-clicked programmatically
 *    with a flag attribute set so the handler doesn't re-prompt.
 *
 * CSS-driven styling: v4.1.2 (U-03) moved all chrome from inline `setAttribute`
 * strings to class catalog in assets/admin.css. Shared shell rules (.snt-modal-*
 * + .snt-confirm-* backdrop/header/footer) are comma-grouped in the CSS so
 * either file's class names render identically.
 *
 * @since plugin v4.1.1
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	// Track an active modal so we don't stack them on rapid clicks.
	var activeBackdrop = null;
	// Marker attribute set on a `[data-snt-confirm]` button after the user
	// confirms — the global click handler reads this to short-circuit re-prompting.
	var BYPASS_ATTR = 'data-snt-confirm-bypass';

	/**
	 * Show a confirm modal. Returns a Promise that resolves to `true` (user
	 * confirmed) or `false` (cancelled / Esc / backdrop / × button).
	 *
	 * @param {Object}   opts
	 * @param {string}   opts.title         Modal title (h2).
	 * @param {string}   opts.message       Body text. Plain text — no HTML.
	 * @param {string}   [opts.confirmLabel='Confirm']  Primary button label.
	 * @param {string}   [opts.cancelLabel='Cancel']    Secondary button label.
	 * @param {boolean}  [opts.danger=false]            If true, primary button styled red.
	 * @param {Element}  [opts.originatingButton]       Where focus returns on close.
	 * @returns {Promise<boolean>}
	 */
	function sntConfirm( opts ) {
		opts = opts || {};
		return new Promise( function ( resolve ) {
			// Close any existing dialog before opening a new one.
			if ( activeBackdrop ) { closeActiveBackdrop(); }

			var backdrop = document.createElement( 'div' );
			backdrop.className = 'snt-confirm-backdrop';

			var box = document.createElement( 'div' );
			box.className = 'snt-confirm-box';
			box.setAttribute( 'role', 'dialog' );
			box.setAttribute( 'aria-modal', 'true' );

			var header = document.createElement( 'div' );
			header.className = 'snt-confirm-header';

			var titleEl = document.createElement( 'h2' );
			titleEl.className = 'snt-confirm-title';
			titleEl.textContent = String( opts.title || __( 'Are you sure?', 'signal-noise-tools' ) );
			titleEl.id = 'snt-confirm-title-' + Date.now() + '-' + Math.floor( Math.random() * 1e6 );
			box.setAttribute( 'aria-labelledby', titleEl.id );
			header.appendChild( titleEl );

			var closeBtn = document.createElement( 'button' );
			closeBtn.type = 'button';
			closeBtn.className = 'snt-confirm-close';
			closeBtn.textContent = '×';
			closeBtn.setAttribute( 'aria-label', __( 'Close', 'signal-noise-tools' ) );
			header.appendChild( closeBtn );

			box.appendChild( header );

			var body = document.createElement( 'div' );
			body.className = 'snt-confirm-body';
			body.textContent = String( opts.message || '' );
			box.appendChild( body );

			var footer = document.createElement( 'div' );
			footer.className = 'snt-confirm-footer';

			var cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'button';
			cancelBtn.textContent = String( opts.cancelLabel || __( 'Cancel', 'signal-noise-tools' ) );
			footer.appendChild( cancelBtn );

			var confirmBtn = document.createElement( 'button' );
			confirmBtn.type = 'button';
			confirmBtn.className = opts.danger ? 'button button-link-delete' : 'button button-primary';
			confirmBtn.textContent = String( opts.confirmLabel || __( 'Confirm', 'signal-noise-tools' ) );
			footer.appendChild( confirmBtn );

			box.appendChild( footer );
			backdrop.appendChild( box );
			document.body.appendChild( backdrop );

			var finished = false;
			var escapeHandler;
			var focusHandler;

			function finish( result ) {
				if ( finished ) { return; }
				finished = true;
				closeActiveBackdrop();
				if ( opts.originatingButton && typeof opts.originatingButton.focus === 'function' ) {
					opts.originatingButton.focus();
				}
				resolve( !! result );
			}

			cancelBtn.addEventListener( 'click', function () { finish( false ); } );
			closeBtn.addEventListener( 'click', function () { finish( false ); } );
			confirmBtn.addEventListener( 'click', function () { finish( true ); } );
			backdrop.addEventListener( 'click', function ( e ) {
				if ( e.target === backdrop ) { finish( false ); }
			} );

			escapeHandler = function ( e ) {
				if ( ! activeBackdrop ) { return; }
				if ( 'Escape' === e.key ) { e.preventDefault(); finish( false ); }
				else if ( 'Enter' === e.key && 'TEXTAREA' !== ( e.target && e.target.tagName ) ) {
					e.preventDefault();
					finish( true );
				}
			};
			focusHandler = function ( e ) {
				if ( ! activeBackdrop ) { return; }
				if ( ! box.contains( e.target ) ) { e.preventDefault(); confirmBtn.focus(); }
			};

			document.addEventListener( 'keydown', escapeHandler );
			document.addEventListener( 'focusin', focusHandler );

			activeBackdrop = {
				node:           backdrop,
				escapeHandler:  escapeHandler,
				focusHandler:   focusHandler,
			};

			confirmBtn.focus();
		} );
	}

	function closeActiveBackdrop() {
		if ( ! activeBackdrop ) { return; }
		document.removeEventListener( 'keydown', activeBackdrop.escapeHandler );
		document.removeEventListener( 'focusin', activeBackdrop.focusHandler );
		if ( activeBackdrop.node && activeBackdrop.node.parentNode ) {
			activeBackdrop.node.parentNode.removeChild( activeBackdrop.node );
		}
		activeBackdrop = null;
	}

	/**
	 * Global click handler for `[data-snt-confirm]` buttons.
	 *
	 * Reads:
	 *   data-snt-confirm        : (required) the message body
	 *   data-snt-confirm-title  : (optional) modal title; defaults to "Are you sure?"
	 *   data-snt-confirm-label  : (optional) confirm-button label; defaults to "Confirm"
	 *   data-snt-confirm-danger : (optional) "1" → red destructive button styling
	 *
	 * On confirm, the button's BYPASS_ATTR is set and the click is re-dispatched
	 * so the natural form-submit / parent click handler proceeds.
	 */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-snt-confirm]' );
		if ( ! btn ) { return; }
		if ( btn.hasAttribute( BYPASS_ATTR ) ) {
			// User already confirmed — let the click proceed naturally.
			return;
		}
		e.preventDefault();
		e.stopPropagation();

		sntConfirm( {
			title:             btn.getAttribute( 'data-snt-confirm-title' ) || __( 'Are you sure?', 'signal-noise-tools' ),
			message:           btn.getAttribute( 'data-snt-confirm' ) || '',
			confirmLabel:      btn.getAttribute( 'data-snt-confirm-label' ) || __( 'Confirm', 'signal-noise-tools' ),
			danger:            '1' === btn.getAttribute( 'data-snt-confirm-danger' ),
			originatingButton: btn,
		} ).then( function ( confirmed ) {
			if ( ! confirmed ) { return; }
			// Re-fire the click with the bypass flag so the natural handler runs.
			btn.setAttribute( BYPASS_ATTR, '1' );
			try {
				btn.click();
			} finally {
				// Clean up the bypass marker after the next event loop tick so any
				// re-render doesn't carry it forward.
				setTimeout( function () { btn.removeAttribute( BYPASS_ATTR ); }, 0 );
			}
		} );
	}, true ); // capture phase — intercept before form-submit handlers

	// Expose imperative API.
	window.sntConfirm = sntConfirm;
} )();
