<?php
/**
 * Signal & Noise Tools — watches: the things that come due later.
 *
 * WHY THIS IS NOT A ROUTINE. Owner, 2026-09-04: *"Can we make the future dated
 * things durable like a routine or something?"* A routine fires on a clock
 * whether or not it has anything to say, and a daily message that usually says
 * "nothing yet" trains its reader to stop opening it. That is the same failure
 * as a diagnostic that moves when you press a button: the signal stops being
 * about the subject.
 *
 * So a watch is SILENT until it is ripe, and it ripens on a STATE wherever a
 * state exists — a date only where nothing can be measured. The difference
 * matters: "check the shape ledger on Sept 10" is a reminder someone has to
 * honour, while "the shape ledger reports settled" is a fact the site can
 * notice on its own. The same distinction replaced a scheduled reminder with
 * the shape ledger in the first place (v13.84.0), and replaced "check the drift
 * watch tomorrow" with a health check (v13.89.0).
 *
 * A DATE ALONE IS THE WEAK FORM, kept only where there is nothing to test: a
 * re-read of a number that will not announce itself. Those carry `date_only`
 * so a reader can see which kind it is looking at.
 *
 * @package SignalNoiseTools
 * @since   13.90.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 17.8.1: the adapter's first release as an installable plugin.
if ( ! defined( 'SNT_MCP_ADAPTER_MIN' ) ) {
	define( 'SNT_MCP_ADAPTER_MIN', '0.7.0' );
}

/**
 * Every registered watch.
 *
 * Each row: id, label, why (what acting on it means), read (where to look),
 * date_only (bool), due (Y-m-d or ''), and `ripe` — a callable ( $watch, $now )
 * returning array{ripe:bool,note:string}. A callable that cannot answer returns
 * ripe:false with a note saying so; an unreadable watch is never ripe, on the
 * same rule the rest of this codebase keeps — absence of evidence is not a
 * finding.
 *
 * @return array<int,array<string,mixed>>
 */
function snt_watches() {
	return array(
		array(
			'id'        => 'reader_anomalies_twin',
			'label'     => 'reader-anomalies remote twin',
			'why'       => 'A twin freezes its origin payload shape byte-identically; shipping one before the shape settles costs a contract bump plus a worker release to undo.',
			'read'      => 'sn-status{shape_stability}',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_shape_settled',
		),
		// notes_drift_reread retired 2026-10-11: search_drift measured /notes with no
		// drift a month past its re-read date. It was sample noise; that is the answer.
		array(
			'id'        => 'ipv6_build_ranges',
			'label'     => 'login-guard IPv6 ranges',
			'why'       => 'A pre-committed criterion decides this, not a judgement call: build the ranges only when the gauge says build_ranges. Acting earlier means writing ranges against a window that has not earned them.',
			'read'      => 'sn-status{ipv6_criterion}',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_ipv6_criterion',
		),
		// search_coverage_reread (due 2026-09-14) retired 2026-09-14: answered by
		// the second coverage reading (6 not indexed, all queued for a GSC
		// request) and carried daily by the Search Attention reader since 14.7.0.
		// 16.6.1 — two watches from the WordPress 7.2 roadmap read (2026-09-18).
		array(
			'id'        => 'connector_key_wipe_65551',
			'label'     => 'Core connector wipes a key it cannot validate (Trac #65551)',
			'why'       => 'Since 16.5.2 the TypeSafe key lives in Core\'s connector, and Core\'s settings save deletes a stored key whenever validation is not strictly true, INCLUDING null for "the provider did not answer". A Connectors-screen save during a TypeSafe blip erases the key with no notice. The fix is on the 7.2 list, unmerged. Until the ticket closes: do not re-save Connectors while TypeSafe is down. Ripe when WordPress reports 7.2 or later, which is when to verify the fix landed and retire this.',
			'read'      => 'https://core.trac.wordpress.org/ticket/65551',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_wp_72',
		),
		array(
			'id'        => 'mcp_adapter_read_door',
			'label'     => 'retire the MCP read door into the core adapter',
			'why'       => 'The plugin hand-rolls its MCP transport (inc/mcp/). WordPress/mcp-adapter ships as an installable plugin from 0.7.0 (0.6.1 was a Composer library), and may later move into core. The abilities, the allowlists as policy, the rw audit and the telemetry are ours and stay; the JSON-RPC routing and version negotiation become duplicate. Ripe when the adapter is active on this site at 0.7.0 or later, read from McpAdapter::VERSION so a plugin, a bundle and core all count the same. Then: register the abilities with it, verify its door serves the same calls, retire /mcp (read) first, /mcp-rw only once its per-door hardening matches mcp-rw-guard. The watch firing is not the verification.',
			'read'      => 'AI › MCP Clients (the Adapter card)',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_mcp_adapter',
		),
		// #1625: the keyring's storage half moves to Core's Secrets API when it exists.
		array(
			'id'        => 'secrets_api_keyring_storage',
			'label'     => 'keyring storage onto the Secrets API (#1625)',
			'why'       => 'Seventeen keyring rows are plain options and two live inside sn_settings. WordPress 7.2 proposes a Secrets API (wp_set_secret, encrypted at rest, hidden from options.php and /wp/v2/settings). Ripe when wp_set_secret exists on this site (7.2 Beta 1, 20 to 22 October 2026, or a trunk build). Then: sn_keyring_stored() and sn_keyring_write() go through it behind function_exists, one upgrade import per option-backed row flagged for rotation; the registry, the probes, Verify all and the site-secret derivation stay ours. Issued rows do NOT become Core connectors (one paste surface).',
			'read'      => 'Connections › Credentials; https://github.com/juanlentino/signal-and-noise-tools/issues/1625',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_secrets_api',
		),
		// Beacon bot signals: two weeks observe-only before any signal may subtract.
		array(
			'id'        => 'bot_signals_validation',
			'label'     => 'beacon bot signals: validate, then decide',
			'why'       => 'The five beacon signals are observe-only. Ripe when 14 days carry signal rows and the two known-human cohorts (relay readers, intent visitors) each have 20 visitor-days; stored bots, over-cap visitor-days and hosting suspects are reported at whatever count they reach (over-cap ran 2 in 92 days). Then: drop any signal that fires on relay readers or intent visitors, or does not fire on the other three, and bring the survivors and a threshold to the owner before anything subtracts.',
			'read'      => 'sn-status{bot_signals}',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_bot_signals',
		),
		// 20.3.0: monthly rights evidence posts itself once its review window passes.
		array(
			'id'        => 'rights_evidence_review',
			'label'     => 'rights evidence: a month waits to post, or a rule held one',
			'why'       => 'A composed month posts on its own to the public, append-only ledger once its 72-hour review window passes, unless held: this is the time to View its payloads and Hold it, or Post now. A month a rule held (a compose or ledger-walk error, a train count that moved more than 3x, a worker refusal) never posts until the owner reads the reason and lifts it.',
			'read'      => 'Measurement › Machine Readers › Rights evidence; signal-noise/rights-evidence{in_review,hold_reasons}',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_rights_evidence',
		),
		// 20.6.0: the vendored Dompdf carries our Cpdf patch until upstream ships it.
		array(
			'id'        => 'dompdf_bump',
			'label'     => 'Dompdf release newer than the vendored one',
			'why'       => 'The PDF engine is vendored with a Cpdf patch (20.3.2) that upstream confirmed for 3.1.7 (dompdf/dompdf#3771). A newer release is the time to bump lib/pdf and drop the patch if the fix shipped; tests/resume-pdf.php pins the bytes either way.',
			'read'      => 'Content › Resume Page › Resume PDF (the engine line)',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_dompdf',
		),
		// 20.8.0: the agent-readable twin of the alert email.
		array(
			'id'        => 'alerts_fired',
			'label'     => 'an alert fired: a page spiked or broke',
			'why'       => 'The hourly alert run mails the owner once per alert per day when a path or the whole site passes its spike line, or a front-end page shows 3 or more stored 5xx in a day. Ripe for 24 hours after a mail, while an alert fired and its mail did not leave, or while a stored read failed and the run could not evaluate. The note names what fired and when the run last evaluated.',
			'read'      => 'S&N Analytics (the day\'s pages and sources); signal-noise/edge-errors-summary (5xx)',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_alerts',
		),
		// 20.8.0: a first publish is pushed to the Internet Archive.
		array(
			'id'        => 'archive_push_failed',
			'label'     => 'Internet Archive push failed',
			'why'       => 'A note\'s first publish asks Save Page Now for a capture, with one retry. A 200 is recorded as requested, not captured: the crawl runs later and is not polled. Ripe for a week after a push failed and was not since accepted for that same note, whatever was pushed afterwards: read the status and reason, check the two archive.org keys in wp-config, and save the page by hand at web.archive.org/save if the capture matters.',
			'read'      => 'post meta _sn_archive_push; option sn_archive_push_last',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_archive_push',
		),
		// 22.3.0: analytics 2.0 stops the legacy write; the verdict freezes.
		array(
			'id'        => 'analytics_v2_frozen_bad',
			'label'     => 'analytics 2.0: the legacy write stopped on a bad verdict',
			'why'       => 'Analytics worker 2.0.0 stops writing the legacy dataset and the daily comparison ends there: a good verdict freezes and reads stay on the second generation. If the verdict was NOT ok when the stop was seen, every read stays on the legacy dataset, which receives nothing new, so every live figure stalls. Ripe in that state; the fix is to restore the legacy write (roll the worker back) until seven green verdicts are in.',
			'read'      => 'signal-noise/analytics-dual-write (verdict); option sn_analytics_v2_verified',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_analytics_v2_freeze',
		),
		// 2026-10-18: three security watches; they observe, LLAR, Two Factor and Cloudflare enforce.
		array(
			'id'        => 'app_passwords',
			'label'     => 'an application password is new or stale',
			'why'       => 'Application passwords sign in to the REST API without the login address, Two Factor or a passkey. Ripe when one was created this week (was it you?) or has gone unused for 90 days (revoke it). The note names user and app, never the secret.',
			'read'      => 'your WordPress profile page, Application Passwords section',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_app_passwords',
		),
		array(
			'id'        => 'admin_2fa',
			'label'     => 'an administrator without a passkey or authenticator app',
			'why'       => 'Reads Two Factor, where the WebAuthn provider adds passkeys. Ripe when Two Factor is inactive or an administrator has nothing stronger than email or backup codes.',
			'read'      => 'your WordPress profile page, Two-Factor Options section',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_admin_2fa',
		),
		array(
			'id'        => 'guard_routes',
			'label'     => 'the login guard routes drifted',
			'why'       => 'Once a day the plugin reads the Cloudflare routes. Ripe when the routes for the login address no longer point at the guard, a route for a retired address is left over, Cloudflare cannot be read, or the check has not run for two days. Counts only, never patterns. Saving the Login URL again restores and prunes them.',
			'read'      => 'option sn_security_guard_routes_last; signal-noise/get-cron-history sn_security_guard_routes_check',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_guard_routes',
		),
		// 2026-10-18: the login address gets out only if the owner hands it out.
		array(
			'id'        => 'login_door_leak',
			'label'     => 'the login address is published somewhere',
			'why'       => 'Once a day the site reads what a stranger reads (home, the newest note, a 404, feeds, the REST index, sitemaps, robots.txt), bodies and headers, with no cookies. Ripe when any of them contains the login address (the note names where, never the address), or when the check has not run for two days. Fix what prints it, then change the Login URL: one save moves the guard too.',
			'read'      => 'option sn_login_door_leak_last; signal-noise/get-cron-history sn_login_door_leak_check',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_login_door_leak',
		),
		// 22.1.0: /stats reads machine figures from an hourly snapshot.
		array(
			'id'        => 'public_stats_machines',
			'label'     => '/stats machine figures not refreshing',
			'why'       => 'The public /stats page never calls the edge sensor; an hourly event stores the machine reads for the report window, and the Humans and machines section is left out while no snapshot of this window exists. Ripe while the last refresh stored nothing (the note says why) or none has run in three hours.',
			'read'      => 'option sn_public_stats_machines_last; signal-noise/get-cron-history sn_public_stats_machines_refresh',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_public_stats_machines',
		),
		array(
			'id'        => 'wave4_telemetry',
			'label'     => 'wave-4 tool retirement read',
			'why'       => 'Retire the absorbed single-purpose tools only on a collapsed read — usage evidence, never a date. The date is only when the window is wide enough to look.',
			'read'      => 'sn-site-facts{tool_telemetry}',
			'date_only' => true,
			// 2026-09-27 read: four retired (19.3.0), eight still in use;
			// re-read once a month has run with the scheduled reads moved.
			'due'       => '2026-10-25',
			'ripe'      => '',
		),
	);
}

/**
 * Ripe when the stored bot-signals reading covers 14 days and the relay and
 * intent cohorts each have SNT_BOT_SIGNAL_MIN_N visitor-days. Reads the nightly record, never AE.
 *
 * @param array    $watch  The watch row.
 * @param int      $now    Unix time (unused).
 * @param array|null|false $stored Test seam; false reads the option.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_bot_signals( $watch, $now, $stored = false ) {
	unset( $watch, $now );
	$r = false === $stored ? ( function_exists( 'sn_bot_signals_stored' ) ? sn_bot_signals_stored() : null ) : $stored;
	if ( ! is_array( $r ) ) {
		return array( 'ripe' => false, 'note' => 'not measured yet' );
	}
	$min  = defined( 'SNT_BOT_SIGNAL_MIN_N' ) ? SNT_BOT_SIGNAL_MIN_N : 20;
	$days = (int) ( $r['days_present'] ?? 0 );
	// Only the two known-human cohorts gate: over-cap ran 2 visitor-days in 92,
	// so a floor on every cohort would never ripen. The rest are reported as-is.
	$c    = (array) ( $r['cohorts'] ?? array() );
	$thin = array();
	foreach ( array( 'relay', 'intent' ) as $k ) {
		if ( (int) ( $c[ $k ]['n'] ?? 0 ) < $min ) {
			$thin[] = $k . ' ' . (int) ( $c[ $k ]['n'] ?? 0 );
		}
	}
	$rest = array();
	foreach ( array_diff( array_keys( $c ), array( 'relay', 'intent' ) ) as $k ) {
		$rest[] = $k . ' ' . (int) ( $c[ $k ]['n'] ?? 0 );
	}
	$others = $rest ? '; others: ' . implode( ', ', $rest ) : '';
	if ( $days < 14 || array() !== $thin ) {
		return array( 'ripe' => false, 'note' => $days . ' of 14 days' . ( $thin ? '; under ' . $min . ': ' . implode( ', ', $thin ) : '' ) . $others );
	}
	return array( 'ripe' => true, 'note' => $days . ' days, relay and intent at ' . $min . '+ visitor-days' . $others );
}

/**
 * Ripe when the shape ledger says the subject has settled.
 *
 * @param array $watch The watch row.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_shape_settled( $watch, $now ) {
	unset( $watch );
	if ( ! function_exists( 'sn_shape_stability' ) ) {
		return array( 'ripe' => false, 'note' => 'shape ledger unavailable' );
	}
	$v     = sn_shape_stability( 'reader-anomalies', (int) $now );
	$state = (string) ( $v['state'] ?? 'unknown' );
	if ( 'settled' !== $state ) {
		return array( 'ripe' => false, 'note' => (string) ( $v['reason'] ?? $state ) );
	}
	return array( 'ripe' => true, 'note' => (string) ( $v['reason'] ?? 'settled' ) );
}

/**
 * Ripe when the pre-committed IPv6 criterion says to build.
 *
 * The gauge already names its own decision, which is the whole reason this is a
 * state watch and not a date: `build_ranges` is the only value that means act.
 * Every `withhold_*` is a live, correct answer — an unfinished window is not a
 * failure and must never surface as one.
 *
 * @param array $watch The watch row.
 * @param int   $now   Unix time (unused; the gauge carries its own window).
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_ipv6_criterion( $watch, $now ) {
	unset( $watch, $now );
	// The STORED reading, never the live gauge: that one runs an uncached
	// analytics query, and a watch is read by the brief and by
	// sn-status{watches}, which must stay cheap. inc/ipv6-criterion-store.php.
	if ( ! function_exists( 'snt_ipv6_criterion_stored' ) ) {
		return array( 'ripe' => false, 'note' => 'IPv6 criterion store unavailable' );
	}
	$v = snt_ipv6_criterion_stored();
	if ( ! is_array( $v ) ) {
		// Nothing stored is NOT "the criterion says no" — it means nothing has
		// measured it yet, and this stays quiet rather than claiming either way.
		return array( 'ripe' => false, 'note' => 'not measured yet' );
	}
	$decision = (string) ( $v['decision'] ?? '' );
	if ( 'build_ranges' !== $decision ) {
		return array( 'ripe' => false, 'note' => (string) ( $v['reason'] ?? $decision ) );
	}
	// Carry the gauge's OWN reason, not a restatement: it names the share, the
	// window and the observations that crossed, which is what the build needs.
	return array( 'ripe' => true, 'note' => (string) ( $v['reason'] ?? 'criterion met' ) );
}

/**
 * The watches that are ripe right now.
 *
 * @param int|null   $now  Unix time; null uses time(). Injectable for fixtures.
 * @param array|null $rows Watch rows; null uses snt_watches(). Injectable so the
 *                         malformed-row guards are reachable in a fixture.
 * @return array<int,array<string,mixed>> Ripe rows, each with its note.
 */
function snt_watches_ripe( $now = null, $rows = null ) {
	$now  = ( null === $now ) ? time() : (int) $now;
	$ripe = array();

	// $rows is injectable so the malformed-row guards below are REACHABLE in a
	// fixture. Against the real registry they are unreachable by construction —
	// every row is well-formed — which would leave them untested and free to
	// rot into passing everything.
	$rows = ( null === $rows ) ? snt_watches() : (array) $rows;

	foreach ( $rows as $watch ) {
		$due = (string) ( $watch['due'] ?? '' );

		// A date-only watch ripens on its date and stays ripe: there is nothing
		// to test, so nothing can un-ripen it. It leaves the list when someone
		// acts and removes the row.
		if ( ! empty( $watch['date_only'] ) ) {
			if ( '' === $due || $now < strtotime( $due . ' 00:00:00 UTC' ) ) {
				continue;
			}
			$ripe[] = array_merge( $watch, array( 'note' => 'due ' . $due ) );
			continue;
		}

		$cb = (string) ( $watch['ripe'] ?? '' );
		if ( '' === $cb || ! function_exists( $cb ) ) {
			continue; // An untestable watch is never ripe, never a finding.
		}
		// $now is THREADED, never re-read from time() inside a callback: a
		// callback reaching for the clock ignores the injected value and the
		// fixture silently tests today instead of the date under test.
		$verdict = call_user_func( $cb, $watch, $now );
		if ( ! is_array( $verdict ) || empty( $verdict['ripe'] ) ) {
			continue;
		}
		$ripe[] = array_merge( $watch, array( 'note' => (string) ( $verdict['note'] ?? '' ) ) );
	}

	return $ripe;
}

/**
 * Ripe once the running WordPress is 7.2 or later. PURE given the version.
 *
 * @since 16.6.1
 * @param array $watch The watch row.
 * @param int   $now   Unix time (unused).
 * @param string|null $version Injected for tests; null reads the global.
 * @return array{ripe:bool,note:string}
 */
/**
 * #1625: ripe once Core's Secrets API exists on this site. Takes the answer as
 * a parameter for tests; never reads the clock.
 *
 * @param array     $watch  The watch row (unused).
 * @param int       $now    Unix time (unused: a state, not a date).
 * @param bool|null $exists Whether wp_set_secret exists; null reads the site.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_secrets_api( $watch, $now, $exists = null ) {
	unset( $watch, $now );
	if ( null === $exists ) {
		$exists = function_exists( 'wp_set_secret' );
	}
	return $exists
		? array( 'ripe' => true, 'note' => 'wp_set_secret exists: move keyring storage onto the Secrets API (#1625)' )
		: array( 'ripe' => false, 'note' => 'no Secrets API on this site yet' );
}

function snt_watch_ripe_wp_72( $watch, $now, $version = null ) {
	unset( $watch, $now );
	if ( null === $version ) {
		$version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
	}
	if ( '' === $version ) {
		return array( 'ripe' => false, 'note' => 'WordPress version unreadable' );
	}
	return version_compare( $version, '7.2', '>=' )
		? array( 'ripe' => true, 'note' => 'WordPress ' . $version . ': verify Trac #65551 landed, then retire this watch' )
		: array( 'ripe' => false, 'note' => 'WordPress ' . $version . ', before 7.2' );
}

/**
 * Ripe once WordPress/mcp-adapter is active here at SNT_MCP_ADAPTER_MIN or later.
 *
 * 17.8.1: keyed on the version, not on the class alone. The 0.6.x library
 * could be bundled but was never the thing to port onto; 0.7.0 is the first
 * release as a plugin. A loaded 0.6.x says so in the note and stays quiet.
 *
 * @since 16.6.1
 * @param array       $watch   The watch row.
 * @param int         $now     Unix time (unused).
 * @param string|null $version Injected for tests; null reads McpAdapter::VERSION ('' when absent).
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_mcp_adapter( $watch, $now, $version = null ) {
	unset( $watch, $now );
	if ( null === $version ) {
		// The class constant, not the plugin header: it answers the same
		// whether the adapter came as a plugin, a Composer bundle, or core.
		$version = class_exists( 'WP\\MCP\\Core\\McpAdapter' ) && defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' )
			? (string) constant( 'WP\\MCP\\Core\\McpAdapter::VERSION' )
			: '';
	}
	if ( '' === (string) $version ) {
		return array( 'ripe' => false, 'note' => 'no adapter on this site' );
	}
	if ( version_compare( (string) $version, SNT_MCP_ADAPTER_MIN, '<' ) ) {
		return array( 'ripe' => false, 'note' => sprintf( 'adapter %s is loaded; the port waits on %s, the first plugin release', $version, SNT_MCP_ADAPTER_MIN ) );
	}
	return array( 'ripe' => true, 'note' => sprintf( 'adapter %s is active: register the abilities with it, verify its door, then retire the read door', $version ) );
}

/**
 * Ripe while a rights-evidence month is composed, unposted and not held (the
 * note carries what is left of its review window), or held by a rule (the
 * note carries the reason). An owner's own hold, with no reason, is quiet.
 *
 * @param array      $watch The watch row.
 * @param int        $now   Unix time.
 * @param array|null $state Injected for tests: {pending: month => review_until, reasons: month => string[]}; null reads the module.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_rights_evidence( $watch, $now, $state = null ) {
	unset( $watch );
	if ( null === $state ) {
		if ( ! function_exists( 'sn_rights_evidence_pending' ) ) {
			return array( 'ripe' => false, 'note' => 'rights evidence module not loaded' );
		}
		$state = array(
			'pending' => sn_rights_evidence_pending(),
			'reasons' => array_intersect_key( sn_rights_evidence_hold_reasons(), array_flip( sn_rights_evidence_held( false ) ) ),
		);
	}
	$parts = array();
	foreach ( (array) ( $state['pending'] ?? array() ) as $ym => $until ) {
		$left    = (int) $until - (int) $now;
		$parts[] = $ym . ( $left > 0 ? sprintf( ' posts in %d h unless held', (int) ceil( $left / 3600 ) ) : ' is past its window and posts at the next pass' );
	}
	foreach ( (array) ( $state['reasons'] ?? array() ) as $ym => $reasons ) {
		$parts[] = $ym . ' held: ' . implode( '; ', (array) $reasons );
	}
	return $parts
		? array( 'ripe' => true, 'note' => implode( ' | ', $parts ) )
		: array( 'ripe' => false, 'note' => 'no month waiting, none held by a rule' );
}

// 2026-10-10: the General-save guard and its watch are retired (WordPress/ai#1048
// fixed upstream); the watch's stored wp-admin reading goes with them. A missing
// non-autoloaded option is cached as missing, so this costs nothing once gone.
if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_init', static function () {
		if ( false !== get_option( 'snt_watch_general_save_seen', false ) ) {
			delete_option( 'snt_watch_general_save_seen' );
		}
	} );
}
