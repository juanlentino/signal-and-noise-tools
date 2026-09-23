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
		array(
			'id'        => 'notes_drift_reread',
			'label'     => '/notes position drift',
			'why'       => 'Its first reading cleared the 5.0-drift and 10-impression floors by a hair; an average position over eleven impressions is noise-adjacent. Still drifting with more impressions behind it is a finding. CONFOUNDED 2026-09-09: the page changed materially mid-window (theme v12.19.0-v12.20.3 added the pillar rail to the hero, moved the corpus stamp out, changed the heading outline, and moved the first note twice). A reading on 2026-09-11 CANNOT separate continued drift from the effect of those changes. Treat a worse position as unattributable rather than as a finding, and re-baseline from the first full week after 2026-09-10 before drawing any conclusion.',
			'read'      => 'sn-status{search_drift}',
			'date_only' => false,
			'due'       => '2026-09-11',
			'ripe'      => 'snt_watch_ripe_notes_drift',
		),
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
			'read'      => 'Connections › MCP connect (adapter_active)',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_mcp_adapter',
		),
		// 16.8.0 — the General-save guard (16.7.2) is a workaround for WordPress/ai#1048.
		array(
			'id'        => 'general_save_guard_ai_1048',
			'label'     => 'retire the General-save guard (WordPress/ai#1048)',
			'why'       => 'The AI plugin (1.2.0+) calls register_initial_settings() on wp_abilities_api_init, which puts admin_email into the General allowed group during an admin save; options.php then saves it as NULL and Core rejects it. inc/general-save-guard.php drops the name from the group. Ripe when admin_email stops appearing in the group after the abilities registry has initialised, which is upstream having shipped (or the plugin gone): verify a General save is clean without the guard, then remove the guard and this watch.',
			'read'      => 'https://github.com/WordPress/ai/issues/1048',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_general_save_guard',
		),
		array(
			'id'        => 'wave4_telemetry',
			'label'     => 'wave-4 tool retirement read',
			'why'       => 'Retire the absorbed single-purpose tools only on a collapsed read — usage evidence, never a date. The date is only when the window is wide enough to look.',
			'read'      => 'sn-site-facts{tool_telemetry}',
			'date_only' => true,
			'due'       => '2026-09-25',
			'ripe'      => '',
		),
	);
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
 * Ripe when the re-read date has passed AND /notes is still drifting.
 *
 * BOTH halves, deliberately. The date alone would surface it on the 11th even
 * if the drift had reverted — which is the answer, and not one that needs
 * anybody's attention.
 *
 * @param array $watch The watch row.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_notes_drift( $watch, $now ) {
	$due = (string) ( $watch['due'] ?? '' );
	if ( '' !== $due && (int) $now < strtotime( $due . ' 00:00:00 UTC' ) ) {
		return array( 'ripe' => false, 'note' => 'not due until ' . $due );
	}
	if ( ! function_exists( 'snt_gsc_position_drift' ) ) {
		return array( 'ripe' => false, 'note' => 'drift reader unavailable' );
	}
	$drift = snt_gsc_position_drift();
	if ( ! is_array( $drift ) ) {
		// null = the history cannot answer. Not ripe, and NOT "no drift".
		return array( 'ripe' => false, 'note' => 'drift history cannot answer yet' );
	}
	if ( ! isset( $drift['/notes'] ) ) {
		return array( 'ripe' => false, 'note' => '/notes no longer drifting — it was sample noise' );
	}
	$d = $drift['/notes'];
	return array(
		'ripe' => true,
		'note' => sprintf(
			'still drifting: %.1f to %.1f over %d impressions',
			(float) ( $d['from'] ?? 0 ),
			(float) ( $d['to'] ?? 0 ),
			(int) ( $d['impressions'] ?? 0 )
		),
	);
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
 * Ripe once `admin_email` no longer lands in the "general" allowed group
 * after the Abilities registry has initialised (WordPress/ai#1048 shipped,
 * or the AI plugin is gone). Reads the same global options.php reads.
 *
 * @since 16.8.0
 * @param array $watch The watch row.
 * @param int   $now   Unix time (unused).
 * @param array|null $state Injected for tests: {abilities_init:bool, general:string[]}; null reads the globals.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_general_save_guard( $watch, $now, $state = null ) {
	unset( $watch, $now );
	if ( null === $state ) {
		if ( function_exists( 'wp_get_abilities' ) && ! did_action( 'wp_abilities_api_init' ) ) {
			wp_get_abilities(); // initialise the registry the way an admin request would
		}
		$state = array(
			'abilities_init' => (bool) did_action( 'wp_abilities_api_init' ),
			'general'        => (array) ( $GLOBALS['new_allowed_options']['general'] ?? array() ),
		);
	}
	if ( empty( $state['abilities_init'] ) ) {
		return array( 'ripe' => false, 'note' => 'the abilities registry has not initialised in this request; cannot tell' );
	}
	return in_array( 'admin_email', (array) $state['general'], true )
		? array( 'ripe' => false, 'note' => 'admin_email still lands in the General group; the guard is still needed' )
		: array( 'ripe' => true, 'note' => 'admin_email no longer lands in the General group: verify a save without the guard, then remove inc/general-save-guard.php and this watch' );
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
