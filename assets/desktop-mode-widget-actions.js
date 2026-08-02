/**
 * Signal & Noise Tools — desktop-mode "Quick Actions" widget.
 *
 * Renders three buttons for the most-used maintenance operations.
 * Dispatches each via its ability run-path (v6.55.0; previously the
 * /signal-noise/v1/cmd/{action} REST endpoints) — already auth + capability
 * gated. Replaces the 3-click path of S&N → Dashboard tab → Maintenance
 * section with single-click access from the desktop.
 *
 * Actions: Purge All Caches | Clear DB Overrides | Full Reset
 *
 * Pattern matches assets/desktop-mode-widget.js (same DOM-built,
 * textContent-only, self-contained inline styles, wp.apiFetch for
 * REST + nonce handling).
 *
 * @since plugin v2.1.0
 */
( function() {
	'use strict';

	// v9.52.0 — MOUNT CONTRACT FIX. PHP-declared widgets mount via
	// desktop-mode's server-sync, which reads the callback off
	// window.desktopModeWidgets[ id ]. The previous
	// wp.desktop.registerWidget({id, render}) call used the client-side path
	// with a shape it rejects (requires id + label + description + icon +
	// mount, throws otherwise), so this widget never registered.
	if ( typeof window === 'undefined' ) {
		return;
	}

	window.desktopModeWidgets = window.desktopModeWidgets || {};

	var data    = window.snDesktopData || {};
	// v6.55.0: dispatch each maintenance action via its ability run-path.
	var CMD_ABILITY = {
		'purge-caches':    'purge-all-caches',
		'clear-overrides': 'clear-template-overrides',
		// v7.7.0: full-reset is deprecated (removal v8.0.0) — same behavior is
		// purge-all-caches with include_template_overrides (see CMD_INPUT).
		'full-reset':      'purge-all-caches',
	};
	var CMD_INPUT = {
		'full-reset': { include_template_overrides: true },
	};
	var TOAST_MS = 3500;

	function el( tag, opts ) {
		var node = document.createElement( tag );
		opts = opts || {};
		if ( opts.style ) { node.setAttribute( 'style', opts.style ); }
		if ( opts.className ) { node.className = opts.className; }
		if ( opts.text != null ) { node.textContent = opts.text; }
		if ( opts.title != null ) { node.title = opts.title; }
		return node;
	}

	function clearChildren( node ) {
		while ( node.firstChild ) { node.removeChild( node.firstChild ); }
	}

	/*
	 * v9.52.2 — PALETTE. The widget card is FIXED DARK GLASS, not a themeable
	 * surface: `.desktop-mode-widgets__card` is background rgba(20,20,22,.55)
	 * + backdrop-filter blur(18px) with `color: #fff`
	 * (desktop-mode/assets/css/desktop.css). Everything here is therefore
	 * light-on-dark, and text simply inherits the card's white rather than
	 * restating it.
	 *
	 * NOT using desktop-mode's --wpd-color-* tokens: first-party widget CSS
	 * consumes them, but v0.9.5 DEFINES them nowhere (no :root rule, no
	 * setProperty), so var(--wpd-color-text, …) always resolves to its
	 * fallback. That's why their own widget-starter.css falls back to
	 * near-black while widget-jazz-quote.css falls back to near-white — the
	 * two disagree because the variable never resolves. Style against the card
	 * that exists.
	 *
	 * v10.28.0 — RE-VERIFIED at desktop-mode v0.9.8, after DESKTOP THEMES
	 * (0.9.7) made most of the shell themeable. Both facts above still hold,
	 * and the theme system does NOT change the conclusion:
	 *
	 *   - The card background is STILL a literal — `rgba(20,20,22,0.55)`, not
	 *     a token. A theme can only lay a texture over it
	 *     (--desktop-mode-widget-image). The card cannot go light.
	 *   - --wpd-color-* is still assigned nowhere at v0.9.8.
	 *   - The card's `color` DID become themeable:
	 *     `var( --wpd-fg-on-accent, #fff )`. We still inherit it, because on a
	 *     permanently dark surface the on-accent role is the closest correct
	 *     token — but see the caveat below.
	 *
	 * DO NOT swap these literals for the --wpd-* body palette. Those tokens
	 * (--wpd-success-fg, --wpd-warning-fg, --wpd-danger, …) are themed to read
	 * against --wpd-surface, which IS themeable; ours must read against a card
	 * that is permanently dark. Under a light theme --wpd-success-fg is a dark
	 * green, and adopting it here would put dark green on dark glass. A fixed
	 * surface takes a fixed foreground; that is why these are literals and why
	 * they should stay literals.
	 *
	 * CAVEAT worth watching: a theme that sets --wpd-fg-on-accent to a DARK
	 * value turns inherited card text dark-on-dark. That is upstream's coupling
	 * (unthemeable card + themeable text) and it hits desktop-mode's own
	 * widgets identically, so we track it rather than diverging from the shell.
	 *
	 * TYPOGRAPHY, by contrast, IS ours to follow — see the font note in
	 * tests/desktop-mode-integration.php. No widget here declares a
	 * font-family, so --desktop-mode-font inherits through.
	 *
	 * Pre-v9.52.2 this widget was styled for a white admin page — opaque #fff
	 * buttons with #1d2327 text — which rendered as three glaring white slabs
	 * on the glass.
	 */
	var SURFACE      = 'rgba(255,255,255,0.06)';
	var SURFACE_HOVER = 'rgba(255,255,255,0.13)';
	var HAIRLINE     = 'rgba(255,255,255,0.14)';
	var OK_FG        = '#3fb950';
	var OK_BG        = 'rgba(63,185,80,0.14)';
	var OK_LINE      = 'rgba(63,185,80,0.32)';
	// Danger text is lightened from the #c9503f used for chart deltas: the
	// same hue is legible as a 1px line but muddy as 13px text on dark glass.
	var DANGER_FG    = '#ff9d94';
	var DANGER_BG    = 'rgba(201,80,63,0.16)';
	var DANGER_LINE  = 'rgba(201,80,63,0.45)';
	var DANGER_HOVER = 'rgba(201,80,63,0.22)';

	/** Hover feedback for an inline-styled button (no stylesheet here). */
	function hoverable( btn, restBg, hoverBg ) {
		btn.addEventListener( 'mouseenter', function() {
			if ( btn.dataset.snBusy === '1' ) { return; }
			btn.style.background = hoverBg;
		} );
		btn.addEventListener( 'mouseleave', function() {
			btn.style.background = restBg;
		} );
	}

	function toast( widget, message, success ) {
		var existing = widget.querySelector( '.sn-dm-toast' );
		if ( existing ) { existing.remove(); }

		var t = el( 'div', {
			className: 'sn-dm-toast',
			style:     'margin-top:10px;padding:8px 10px;border-radius:8px;font-size:11px;line-height:1.35;' +
				'background:' + ( success ? OK_BG : DANGER_BG ) + ';' +
				'color:' + ( success ? OK_FG : DANGER_FG ) + ';' +
				'border:1px solid ' + ( success ? OK_LINE : DANGER_LINE ) + ';',
			text:      message,
		} );
		widget.appendChild( t );

		window.setTimeout( function() {
			if ( t.parentNode ) { t.parentNode.removeChild( t ); }
		}, TOAST_MS );
	}

	function runAction( widget, button, action, busyLabel, defaultMessage ) {
		if ( ! window.sntAbilityRun ) {
			toast( widget, 'sntAbilityRun unavailable', false );
			return;
		}
		if ( button.dataset.snBusy === '1' ) { return; }

		var originalText = button.textContent;
		button.dataset.snBusy = '1';
		button.textContent    = busyLabel;
		button.style.opacity  = '0.55';

		// v7.7.2: annotation-derived verb via the shared runner (these
		// destructive+idempotent maintenance abilities require DELETE; the old
		// hardcoded POST 405'd).
		window.sntAbilityRun( CMD_ABILITY[ action ] || action, CMD_INPUT[ action ] )
			.then( function( res ) {
				var ok      = !! ( res && res.ok );
				var message = ( res && res.message ) ? res.message : defaultMessage;
				toast( widget, message, ok );
			} )
			.catch( function( err ) {
				toast( widget, ( err && err.message ) ? err.message : 'Action failed.', false );
			} )
			.finally( function() {
				button.textContent    = originalText;
				button.style.opacity  = '1';
				delete button.dataset.snBusy;
			} );
	}

	/**
	 * v9.52.0: mount( container, ctx ) → teardown. No timers here (the card is
	 * static buttons), so teardown just detaches what we painted.
	 */
	function mount( container, ctx ) {
		if ( ! container ) { return function() {}; }
		clearChildren( container );

		// color:inherit — the card already sets #fff; restating a colour here
		// is what made this widget assume a white page.
		var wrap = el( 'div', {
			style: 'padding:14px 16px;color:inherit;',
		} );

		// v9.52.4: no title row. Since v9.52.2's movable:true, desktop-mode
		// renders its own chrome header (grip + label + remove) above this body,
		// so painting "Quick actions" here put the card's name on screen twice.
		// The label registered in PHP is the single source of truth.
		var btnStyle = 'display:block;width:100%;margin:0 0 6px;padding:8px 10px;background:' + SURFACE +
			';color:inherit;border:1px solid ' + HAIRLINE +
			';border-radius:8px;font-size:13px;line-height:1.2;cursor:pointer;text-align:left;' +
			'transition:background 120ms ease,border-color 120ms ease;';
		var dangerStyle = btnStyle + 'color:' + DANGER_FG + ';border-color:' + DANGER_LINE + ';';

		var btnPurge = el( 'button', {
			text:  'Purge all caches',
			style: btnStyle,
			title: 'Object cache + Breeze + Varnish + Cloudflare',
		} );
		btnPurge.addEventListener( 'click', function() {
			runAction( wrap, btnPurge, 'purge-caches', 'Purging…', 'Caches purged.' );
		} );
		hoverable( btnPurge, SURFACE, SURFACE_HOVER );
		wrap.appendChild( btnPurge );

		var btnClear = el( 'button', {
			text:  'Clear DB overrides',
			style: btnStyle,
			title: 'Remove wp_template / wp_template_part / wp_navigation DB rows',
		} );
		btnClear.addEventListener( 'click', function() {
			runAction( wrap, btnClear, 'clear-overrides', 'Clearing…', 'Overrides cleared.' );
		} );
		hoverable( btnClear, SURFACE, SURFACE_HOVER );
		wrap.appendChild( btnClear );

		var btnReset = el( 'button', {
			text:  'Full reset',
			style: dangerStyle,
			title: 'Clear DB overrides AND purge every cache',
		} );
		btnReset.addEventListener( 'click', function() {
			runAction( wrap, btnReset, 'full-reset', 'Resetting…', 'Full reset complete.' );
		} );
		hoverable( btnReset, SURFACE, DANGER_HOVER );
		wrap.appendChild( btnReset );

		wrap.appendChild( el( 'p', {
			style: 'margin:8px 0 0;font-size:10px;opacity:.5;',
			text:  'Same actions as S&N → Dashboard → Maintenance',
		} ) );

		container.appendChild( wrap );

		return function teardown() {
			clearChildren( container );
		};
	}

	window.desktopModeWidgets['sn-quick-actions'] = mount;

} )();
