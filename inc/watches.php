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
			'why'       => 'Its first reading cleared the 5.0-drift and 10-impression floors by a hair; an average position over eleven impressions is noise-adjacent. Still drifting with more impressions behind it is a finding.',
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
		array(
			'id'        => 'integrity_resweep_after_silent_write',
			'label'     => 'integrity re-sweep after the post_modified-silent write',
			'why'       => 'Nine posts had post_content rewritten by a direct DB query on 2026-09-03 without touching post_modified — deliberate, since a closing-tag repair should not make a note look edited. The cost is that the content hash is the ONLY evidence they changed, and the sweep that would notice samples a slice of the fleet per run. Ripe when every subject has been re-checked SINCE that write, so a clean reading means the whole fleet cleared it, not that the nine were never sampled.',
			'read'      => 'sn-status{provenance_integrity}',
			'date_only' => false,
			'due'       => '',
			'ripe'      => 'snt_watch_ripe_integrity_resweep',
		),
		array(
			'id'        => 'search_coverage_reread',
			'label'     => 'zero-impression notes',
			'why'       => 'Thirteen notes were not indexed and thirteen indexed-but-unasked-for. The editorial call needs a second reading, not a bigger sample.',
			'read'      => 'sn-status{search_coverage}',
			'date_only' => true,
			'due'       => '2026-09-14',
			'ripe'      => '',
		),
		array(
			'id'        => 'origin_503_recheck',
			'label'     => 'origin 503 count after cron left the request path',
			'why'       => 'The baseline was 10 origin-side 503s per 24h, measured BEFORE v13.97.2 stopped a visitor pageview from paying for a 10.6s-average cron run. If the count has not fallen, the remaining cause is the FPM pool size rather than the work removed from it — and a server resize would then be buying a bigger pool, not more RAM. Resizing before that reading is spending against an untested guess.',
			'read'      => 'Cloudways app traffic -> top_statuses, duration 1d, app 5349211 (async: poll operation_status). Compare against 10 per 24h.',
			// A weak-form watch, deliberately. The 503s are counted by the HOST;
			// WordPress cannot see them, so there is no state here for the site
			// to notice on its own — exactly the case this file reserves
			// `date_only` for. A week gives several full cron days after the fix.
			'date_only' => true,
			'due'       => '2026-09-12',
			'ripe'      => '',
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
 * The moment the post_modified-silent DB write landed.
 *
 * A constant, not an option: this is a fixed historical fact about one
 * 2026-09-03 maintenance query, and a settable value would let the bar move
 * under the watch it gates.
 */
const SNT_WATCH_SILENT_WRITE_AT = '2026-09-03T23:06:00Z';

/**
 * Ripe when the integrity sweep has re-checked the WHOLE fleet since the
 * silent write, so its verdict actually covers the nine rewritten posts.
 *
 * WHY FLEET-WIDE AND NOT NINE IDS. The nine were never recorded as ids
 * anywhere, and a watch that pinned a hand-copied list would answer a
 * question about that list rather than about the corpus. Coverage is the
 * honest bar: every subject re-checked after the cutoff. A partial sweep
 * reporting zero mismatches is NOT evidence — it is the sampling artifact
 * this watch exists to refuse.
 *
 * Reports hash_mismatch specifically. An outage leg (twin_unreachable,
 * ledger_unreachable) is a gap in evidence, never drift, and is named as
 * such rather than folded into a pass.
 *
 * @param array $watch The watch row.
 * @param int   $now   Unix timestamp.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_integrity_resweep( $watch, $now ) {
	if ( ! defined( 'SN_PROV_INTEGRITY_OPT' ) ) {
		return array( 'ripe' => false, 'note' => 'integrity store unavailable' );
	}
	$state = get_option( SN_PROV_INTEGRITY_OPT );
	if ( ! is_array( $state ) || empty( $state['notes'] ) || ! is_array( $state['notes'] ) ) {
		return array( 'ripe' => false, 'note' => 'no sweep has been recorded yet' );
	}

	$cutoff = strtotime( SNT_WATCH_SILENT_WRITE_AT );
	$rows   = $state['notes'];
	$total  = count( $rows );
	$fresh  = 0;
	$drift  = array();
	$outage = 0;

	foreach ( $rows as $pid => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$checked = strtotime( (string) ( $row['last_checked'] ?? '' ) );
		if ( ! $checked || $checked < $cutoff ) {
			continue;
		}
		$fresh++;
		$failures = ( isset( $row['failures'] ) && is_array( $row['failures'] ) ) ? $row['failures'] : array();
		if ( in_array( 'hash_mismatch', $failures, true ) ) {
			$drift[] = (int) $pid;
		} elseif ( array() !== $failures ) {
			$outage++;
		}
	}

	if ( $fresh < $total ) {
		return array(
			'ripe' => false,
			'note' => sprintf( 're-swept %d of %d subjects since the write — a zero here would be a sampling artifact, not a clean bill', $fresh, $total ),
		);
	}

	if ( array() !== $drift ) {
		return array(
			'ripe' => true,
			'note' => sprintf( 'fleet re-swept, and %d subject(s) report hash_mismatch: %s', count( $drift ), implode( ', ', $drift ) ),
		);
	}

	return array(
		'ripe' => true,
		'note' => sprintf( 'fleet re-swept since the write (%d subjects), no hash_mismatch%s', $total, $outage > 0 ? sprintf( ' — %d carry outage legs only, which is missing evidence rather than drift', $outage ) : '' ),
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
