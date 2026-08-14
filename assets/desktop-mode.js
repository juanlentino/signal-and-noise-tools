/**
 * Signal & Noise Tools — desktop-mode command bindings.
 *
 * Registered as the 'sn-desktop-mode' WP script handle in
 * inc/desktop-mode-integration.php and loaded by desktop-mode when any
 * SN command is invoked.
 *
 * Pattern: each PHP-registered command has a matching wp.desktop.
 * registerCommand({ slug, run }) call here that attaches the JS callback.
 * Maintenance commands hit REST via wp.apiFetch (auto _wpnonce);
 * Navigation commands set window.location.href; Info commands read
 * from window.snDesktopData (localized in PHP) and dispatch a toast.
 *
 * Gracefully no-ops if neither wp.desktop nor wp.os is available (defensive
 * — script shouldn't be loaded in that case, but better safe).
 *
 * @since plugin v1.15.0
 */
( function boot() {
	'use strict';

	// v10.43.0 — OpenStation rename compat (REJECT #11 MEDIUM fix):
	// SELF-SUFFICIENT, not order-dependent on the external
	// assets/desktop-mode-os-compat.js prelude having run first.
	// desktop-mode's own lazy command-sync loader injects this script's
	// <script src="..."> tag directly by URL (same gap as the widget
	// files' lazy loading — see docs/openstation-compat.md), so under a
	// post-#475 mid-session shell activation this file can run BEFORE the
	// external prelude ever does, leaving only window.wp.os set. Accept
	// EITHER name, then alias window.wp.desktop from window.wp.os locally
	// so every one of the 65 window.wp.desktop.* call sites below keeps
	// working unchanged regardless of which naming family (or ordering)
	// is live.
	//
	// v11.7.1 — ACCEPTING EITHER NAME WAS NOT ENOUGH: neither name exists yet.
	// Measured live on OpenStation v1.1.0 — the shell bundle that installs
	// window.wp.os (`desktop.min.js`) is loaded with `defer`; this file is not.
	// Deferred scripts execute after EVERY non-deferred script, so the shell
	// that appears earlier in the document (DOM index 56) runs LAST, after ours
	// (89). Both gates below then failed, this file returned, and all 22
	// commands were silently dead — no error, no symptom, until someone opened
	// Cmd+K and looked. wp_register_script dependency edges order the printed
	// MARKUP, not the EXECUTION: once a dependency defers and its dependent
	// does not, the edge is inverted at runtime and no amount of dependency
	// declaration fixes it.
	//
	// So a failed gate now SCHEDULES A RETRY instead of giving up. This IIFE is
	// named and simply re-invokes itself once the shell can exist; the body
	// below is untouched and unreachable until a gate actually passes, so a
	// retry cannot double-register.
	function retryLater() {
		if ( boot._scheduled || typeof window === 'undefined' ) {
			return;
		}
		boot._scheduled = true;
		// OpenStation installs an early `wp.os` shim carrying whenReady BEFORE
		// its full API merges onto that same object. If the shim is already
		// here, its own readiness signal beats guessing at document events.
		if ( window.wp && window.wp.os && typeof window.wp.os.whenReady === 'function' ) {
			window.wp.os.whenReady( boot );
			return;
		}
		if ( typeof document === 'undefined' || ! document.addEventListener ) {
			return;
		}
		if ( 'loading' === document.readyState ) {
			// Deferred scripts are all guaranteed to have executed by the time
			// DOMContentLoaded fires, which makes this the earliest moment a
			// deferred shell bundle is certain to have installed wp.os. This is
			// the load-strategy-independent hook: it holds whether upstream
			// ships the bundle deferred, async, or classic.
			document.addEventListener( 'DOMContentLoaded', boot );
		} else {
			// Already past parsing — the lazy mid-session injection path, where
			// the shell that injected us necessarily exists. One macrotask is
			// enough to clear an in-flight assignment.
			window.setTimeout( boot, 0 );
		}
	}

	if ( typeof window === 'undefined' || ! window.wp || ( ! window.wp.desktop && ! window.wp.os ) ) {
		retryLater();
		return;
	}
	window.wp.desktop = window.wp.desktop || window.wp.os;
	if ( typeof window.wp.desktop.registerCommand !== 'function' ) {
		retryLater();
		return;
	}

	var data = window.snDesktopData || {};
	var pages = data.pages || {};
	// v6.55.0: the /cmd/<action> maintenance route is retired in favour of the
	// per-command ability run-paths. Map each legacy action to its ability slug.
	// v7.7.2: transport via window.sntAbilityRun (annotation-derived verbs;
	// the old always-POST callRest 405'd every readonly/destructive+idempotent
	// ability once the run controller enforced verbs).
	var CMD_ABILITY = {
		// v7.7.0: force-check + full-reset migrated to their consolidated
		// replacements (the force-check-updates / full-reset abilities were
		// removed in v8.0.0) — the behavior difference rides in CMD_INPUT.
		// The left-hand keys are LOCAL command names, not ability slugs.
		'force-check':     'get-deploy-status',
		'purge-caches':    'purge-all-caches',
		'clear-overrides': 'clear-template-overrides',
		'full-reset':      'purge-all-caches',
	};
	// Per-command input for the consolidated abilities. POST body input is
	// JSON-decoded by the run controller (unlike GET ?input=), and callRest
	// always POSTs, so this is safe for every command here.
	var CMD_INPUT = {
		'force-check': { force_refresh: true },
		'full-reset':  { include_template_overrides: true },
	};

	/**
	 * Toast helper. desktop-mode exposes wp.desktop.notify() per the
	 * hooks reference; we fall back to wp.data dispatch or console if
	 * not available (defensive across shell versions).
	 */
	function toast( message, type ) {
		type = type || 'success';
		if ( window.wp.desktop.notify && typeof window.wp.desktop.notify === 'function' ) {
			window.wp.desktop.notify( { message: message, type: type } );
			return;
		}
		// Fallback: dispatch a WP notice via the standard data store.
		if ( window.wp.data && window.wp.data.dispatch && window.wp.data.dispatch( 'core/notices' ) ) {
			window.wp.data.dispatch( 'core/notices' ).createNotice( type, message, { isDismissible: true } );
			return;
		}
		// Last resort: console + native alert (avoid alert; just log).
		// eslint-disable-next-line no-console
		console.log( '[SN]', message );
	}

	/**
	 * REST helper using wp.apiFetch (auto _wpnonce header from
	 * wp-api-fetch dependency).
	 */
	function callRest( action ) {
		if ( ! window.sntAbilityRun ) {
			toast( 'sntAbilityRun unavailable — SN command cannot dispatch.', 'error' );
			return Promise.reject( new Error( 'no sntAbilityRun' ) );
		}
		return window.sntAbilityRun( CMD_ABILITY[ action ] || action, CMD_INPUT[ action ] );
	}

	/**
	 * Navigation helper. Inside desktop-mode, location nav within the
	 * window triggers the shell's iframe routing automatically — same
	 * behavior as clicking a wp-admin link.
	 */
	function navigate( url ) {
		if ( ! url ) {
			return;
		}
		// Defense-in-depth: every caller passes a server-localized admin_url()
		// value, but resolve + same-origin-check the target anyway, so a future
		// caller can't turn this into an open-redirect or a javascript: sink.
		try {
			var dest = new URL( url, window.location.origin );
			if ( dest.origin === window.location.origin ) {
				window.location.href = dest.href;
			}
		} catch ( e ) {
			// Malformed URL — refuse to navigate.
		}
	}

	/**
	 * Version-info toast helper. Reads from localized snDesktopData.
	 */
	function versionToast( pkg ) {
		var label = pkg === 'theme' ? 'Theme' : 'Plugin';
		var info = data[ pkg ] || {};
		var current = info.current || '—';
		var state = info.state || 'unknown';
		var msg = label + ': v' + current;
		if ( state === 'ok' ) {
			msg += ' (up to date)';
		} else if ( state === 'available' ) {
			msg += ' (v' + ( info.latest || '?' ) + ' available)';
		} else {
			msg += ' (state unknown)';
		}
		toast( msg, state === 'available' ? 'info' : 'success' );
	}

	/* ── COMMAND BINDINGS ──────────────────────────────────────────────── */

	// v2.5.5: aiCallable opt-in per command. Per desktop-mode docs/javascript-
	// reference.md, this flag harvests the command into wp.desktop.ai.ask()'s
	// tool registry. The built-in ⌘K AI Copilot overlay can then invoke it
	// by name when the user types a matching natural-language query.
	//
	// Conservative selection: opt in non-destructive commands (navigation,
	// info, idempotent transient clears). Skip the 3 destructive maintenance
	// commands (purge-caches, clear-overrides, full-reset) — typing them
	// explicitly via the palette IS the safety check. Quote from the
	// desktop-mode docs: "AI tool-calling is a paraphrasing channel, and
	// handing the model every registered command (including destructive
	// ones) would turn a typo into a catastrophe."

	// Maintenance.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-force-check',
		label: 'SN: Force-check updates',
		aiCallable: true, // v2.5.5: idempotent, clears transients only — safe.
		run: function() {
			callRest( 'force-check' )
				// v7.7.0: get-deploy-status returns { theme, plugin, last_deploy }
				// (no message field; + last_gha_run since v9.63.3) — build the
				// toast from the fresh states.
				.then( function( res ) {
					var detail = ( res && res.theme && res.plugin )
						? ' Theme ' + res.theme.current + ' (' + res.theme.state + '), plugin ' + res.plugin.current + ' (' + res.plugin.state + ').'
						: '';
					toast( 'Update check refreshed.' + detail );
				} )
				.catch( function( err ) { toast( 'Force-check failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-purge-caches',
		label: 'SN: Purge all caches',
		// v2.5.5: aiCallable INTENTIONALLY OMITTED — destructive. Manual ⌘K only.
		run: function() {
			callRest( 'purge-caches' )
				.then( function( res ) { toast( res.message || 'All caches purged.' ); } )
				.catch( function( err ) { toast( 'Purge failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-clear-overrides',
		label: 'SN: Clear template overrides',
		// v2.5.5: aiCallable INTENTIONALLY OMITTED — deletes DB rows. Manual only.
		run: function() {
			callRest( 'clear-overrides' )
				.then( function( res ) { toast( res.message || 'Overrides cleared.' ); } )
				.catch( function( err ) { toast( 'Clear overrides failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-full-reset',
		label: 'SN: Full reset',
		// v2.5.5: aiCallable INTENTIONALLY OMITTED — combines the two destructive
		// commands above; even bigger blast radius. Manual ⌘K only.
		run: function() {
			// v4.1.1 (U-01): sntConfirm replaces window.confirm (which is blocked
			// inside the desktop-mode portal iframe by the chrome-extension boundary).
			// Falls back to native confirm if snt-confirm.js didn't enqueue.
			var prompt = ( typeof window.sntConfirm === 'function' )
				? window.sntConfirm( {
					title:        'Run Full Reset?',
					message:      'This clears every template override AND purges every cache. There is no undo.',
					confirmLabel: 'Full Reset',
					danger:       true,
				} )
				: Promise.resolve( window.confirm( 'Run Full Reset?\n\nThis clears every template override AND purges every cache.' ) );
			prompt.then( function ( confirmed ) {
				if ( ! confirmed ) { return; }
				callRest( 'full-reset' )
					.then( function( res ) { toast( res.message || 'Full reset complete.' ); } )
					.catch( function( err ) { toast( 'Full reset failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
			} );
		},
	} );

	// Navigation. All aiCallable — pure navigation, no state change.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-dashboard', label: 'SN: Open Dashboard',    aiCallable: true, run: function() { navigate( pages.dashboard ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-identity', label: 'SN: Open Identity',     aiCallable: true, run: function() { navigate( pages.identity ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-login', label: 'SN: Open Login',        aiCallable: true, run: function() { navigate( pages.login ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-cloudflare', label: 'SN: Open Cloudflare',   aiCallable: true, run: function() { navigate( pages.cloudflare ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-rss', label: 'SN: Open RSS',          aiCallable: true, run: function() { navigate( pages.rss ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-nav-reading-time', label: 'SN: Open Reading Time', aiCallable: true, run: function() { navigate( pages.reading_time ); } } );

	// Info. Both aiCallable — read-only toast.
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-theme', label: 'SN: Theme version',  aiCallable: true, run: function() { versionToast( 'theme' ); } } );
	window.wp.desktop.registerCommand( { slug: 'sn-cmd-version-plugin', label: 'SN: Plugin version', aiCallable: true, run: function() { versionToast( 'plugin' ); } } );

	// Cron Dashboard (v3.0.0) — both aiCallable, read-only.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-cron-health',
		label: 'SN: Cron health overview',
		aiCallable: true,
		run: function() {
			var summary = data.cronSummary || {};
			toast(
				'Cron: ' + ( summary.total || 0 ) + ' events, ' +
				( summary.sn_count || 0 ) + ' SN-owned, ' +
				( summary.orphans || 0 ) + ' orphan' + ( summary.orphans === 1 ? '' : 's' ),
				'info'
			);
			navigate( pages.cron );
		}
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-cron-list',
		label: 'SN: Open Cron tab',
		aiCallable: true,
		run: function() {
			navigate( pages.cron );
		}
	} );

	// Insights (v3.6.0) — aiCallable, read-only summary toast + navigate.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-insights',
		label: 'SN: Open Insights tab',
		aiCallable: true,
		run: function() {
			var summary = data.insightsSummary || {};
			if ( summary.active_count !== undefined ) {
				toast(
					summary.active_count + ' active recommendation' +
					( summary.active_count === 1 ? '' : 's' ) +
					' from your last Insights scan.',
					'info'
				);
			}
			navigate( pages.insights );
		}
	} );

	// Audit log (v3.8.3) — both aiCallable, read-only fetch + toast.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-audit-summary',
		label: 'SN: Audit log summary',
		aiCallable: true,
		run: function() {
			if ( ! window.sntAbilityRun ) {
				toast( 'sntAbilityRun unavailable.', 'error' );
				return;
			}
			// v7.7.0/v8.0.0: get-audit-summary was removed — same payload rides
			// under get-audit-log's `summary` key. v7.7.2: GET via the runner.
			window.sntAbilityRun( 'get-audit-log', { view: 'summary' } )
				.then( function( res ) {
					var s = ( res && res.summary ) || { last_24h: {}, last_7d_vs_prior: {}, lla: {} };
					// v8.0.4: pct through a numeric fallback like every sibling
					// field — bare pct_delta rendered "undefined%" on a
					// degenerate payload (the v7.7.1 audit's noted fragility).
					var pct = Number( s.last_7d_vs_prior.pct_delta ) || 0;
					var msg = 'Last 24h: ' + ( s.last_24h.all_total || 0 ) + ' events (' +
						( s.last_24h.failed_total || 0 ) + ' failed, ' +
						( s.last_24h.recon_total || 0 ) + ' recon). ' +
						'7d trend: ' + ( pct >= 0 ? '+' : '' ) + pct + '%. ' +
						'Unique IPs (24h): ' + ( s.unique_attackers_24h || 0 ) + '. ' +
						'LLA lockouts: ' + ( s.lla.active_lockouts || 0 ) + '.';
					toast( msg, 'info' );
				} )
				.catch( function( err ) {
					toast( 'Audit summary failed: ' + ( err.message || 'unknown error' ), 'error' );
				} );
		}
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-audit-recent-logins',
		label: 'SN: Recent successful logins',
		aiCallable: true,
		run: function() {
			if ( ! window.sntAbilityRun ) {
				toast( 'sntAbilityRun unavailable.', 'error' );
				return;
			}
			// v7.7.0/v8.0.0: get-audit-login-successes was removed — the rows ride
			// under get-audit-log's `logins` key. v7.7.2: GET via the runner.
			window.sntAbilityRun( 'get-audit-log', { view: 'logins', days: 30 } )
				.then( function( res ) {
					var rows = ( res && res.logins ) || [];
					if ( ! rows || ! rows.length ) {
						toast( 'No successful logins in last 30 days.', 'info' );
						return;
					}
					var last10 = rows.slice( 0, 10 );
					var msg = 'Last ' + last10.length + ' logins: ' +
						last10.map( function( r ) { return r.formatted + ' (' + r.user + ')'; } ).join( '; ' );
					toast( msg, 'info' );
				} )
				.catch( function( err ) {
					toast( 'Recent logins failed: ' + ( err.message || 'unknown error' ), 'error' );
				} );
		}
	} );

	// v9.78.0: the mirror-map gap — every one-shot ability gets a ⌘K entry.
	// All five ride callRest (CMD_ABILITY falls through to the slug itself)
	// and toast the ability's own summary; deliberate manual actions, so
	// aiCallable is omitted throughout (the Copilot already has the abilities).

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-health-scan',
		label: 'SN: Run health scan',
		run: function() {
			callRest( 'run-health-scan' )
				.then( function( res ) {
					toast( res && res.ok
						? 'Health scan done: ' + res.flagged + ' flagged of ' + res.total + ' checks.'
						: 'Health scan could not run.', res && res.ok && 0 === res.flagged ? 'info' : undefined );
				} )
				.catch( function( err ) { toast( 'Health scan failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-insights-scan',
		label: 'SN: Run insights scan',
		run: function() {
			callRest( 'run-insights-scan' )
				.then( function() { toast( 'Insights scan complete.' ); } )
				.catch( function( err ) { toast( 'Insights scan failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-narration',
		label: 'SN: Run narration',
		run: function() {
			callRest( 'run-narration' )
				.then( function() { toast( 'Narration regenerated.' ); } )
				.catch( function( err ) { toast( 'Narration failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-prune-tags',
		label: 'SN: Prune unused tags',
		run: function() {
			callRest( 'prune-unused-tags' )
				.then( function( res ) {
					// Output contract: { ok, deleted: string[], count: int }.
					var n = res && 'number' === typeof res.count ? res.count : null;
					toast( null === n ? 'Unused tags pruned.' : n + ' unused tag' + ( 1 === n ? '' : 's' ) + ' pruned.' );
				} )
				.catch( function( err ) { toast( 'Tag prune failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-anchor-sweep',
		label: 'SN: Sweep anchors',
		run: function() {
			callRest( 'anchor-sweep' )
				.then( function( res ) {
					toast( res && res.ok
						? 'Anchor sweep: ' + res.upgraded + ' upgraded, ' + res.still_pending + ' still pending.'
						: 'Anchor sweep could not run (' + ( ( res && res.error ) || 'unknown' ) + ').' );
				} )
				.catch( function( err ) { toast( 'Anchor sweep failed: ' + ( err.message || 'unknown error' ), 'error' ); } );
		},
	} );

	/**
	 * Attention badge.
	 *
	 * desktop-mode exposes the SAME setBadge( id, count ) on three rails — dock
	 * (bottom), sideDock (Classic left rail) and icons (wallpaper tiles) — one id
	 * space, idempotent, 0 clears, >99 renders 99+. Stable since 0.6.0.
	 *
	 * Optional-chained on every rail because which rails exist depends on the
	 * user's layout — that is the docs' own example shape
	 * (docs/examples/dock-badge.md), not defensive noise.
	 *
	 * The count is already on the page via wp_localize_script — no fetch, no
	 * poll. It is accurate as of shell load, which is as fresh as the sources
	 * get: they are cached for an hour or a day anyway.
	 *
	 * total ARRIVES AS A STRING. wp_localize_script() string-casts every
	 * top-level scalar (wp-includes/class-wp-scripts.php::localize — verified
	 * verbatim @ WP 6.8.1), so PHP's int 2 lands here as "2":
	 *     var snDesktopAttention = {"total":"2","iconId":"sn-icon-dashboard"};
	 * An earlier draft guarded `typeof att.total !== 'number'`, which rejects "2"
	 * on EVERY load — setBadge would never have been called and the badge would
	 * never have rendered, with every PHP test green. Coerce with Number() and
	 * validate with Number.isFinite(): correct whether the transport hands us a
	 * string or a number, so it cannot rot if core ever stops casting.
	 */
	( function setAttentionBadge() {
		var att = window.snDesktopAttention;
		if ( ! att || ! att.iconId ) {
			return;
		}
		var total = Number( att.total );
		if ( ! Number.isFinite( total ) ) {
			return;
		}
		if ( ! window.wp || ! window.wp.desktop ) {
			return;
		}
		// 0 clears the badge — that is the API's contract, and a measured zero
		// SHOULD clear it.
		wp.desktop.dock?.setBadge?.( att.iconId, total );
		wp.desktop.sideDock?.setBadge?.( att.iconId, total );
		wp.desktop.icons?.setBadge?.( att.iconId, total );
	}() );

} )();
