<?php
/**
 * Signal & Noise Tools — Content-Health scan summary accessors.
 *
 * Pure, side-effect-free projections over a cached sn_health_last_scan() array,
 * shared by every surface that summarizes the scan so they can never disagree on
 * "what is off":
 *   - the Dashboard tab first-glance Health card + attention strip
 *     (inc/admin-tab-dashboard.php),
 *   - the "S&N Health" home dashboard widget (inc/site-health-widget.php),
 *   - the Health-tab hero (inc/health-checks-admin.php),
 *   - the get-health-scan ability (inc/abilities-health.php).
 *
 * TIERS (v8.0.4). Most checks are FAULT checks: a check's `count` is a count of
 * this site's problems, and it feeds the finding/flagged alarm calculus. The
 * ADVISORY tier (sn_health_advisory_checks) is for counts that are real and
 * actionable but not this site's defect — external link rot is third-party
 * weather (a remote 500 self-clears when the host recovers), so it must not
 * flip the site off "all clear". Advisory checks are EXCLUDED from
 * finding_total/flagged_checks and surfaced separately via advisory_total;
 * the Health tab still renders their findings card in full (the tab's
 * with-findings split stays raw count>0, deliberately).
 *
 * @package SignalNoiseTools
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advisory-tier check keys (owner re-tier 2026-07-02): surfaced, never alarming.
 *
 * @return string[]
 * @since 8.0.4
 */
function sn_health_advisory_checks() {
	return array( 'external_links', 'link_opportunities' );
}

/**
 * Total findings across every NON-advisory check in a scan.
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return int Sum of every fault-tier check's count (0 when there is no scan).
 */
function sn_health_finding_total( $scan ) {
	$total = 0;
	if ( ! is_array( $scan ) ) {
		return $total;
	}
	$advisory = sn_health_advisory_checks();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( in_array( (string) $key, $advisory, true ) ) {
			continue;
		}
		$total += (int) ( $check['count'] ?? 0 );
	}
	return $total;
}

/**
 * Total findings across advisory-tier checks only (the "N advisories" figure).
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return int
 * @since 8.0.4
 */
function sn_health_advisory_total( $scan ) {
	$total = 0;
	if ( ! is_array( $scan ) ) {
		return $total;
	}
	$advisory = sn_health_advisory_checks();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( in_array( (string) $key, $advisory, true ) ) {
			$total += (int) ( $check['count'] ?? 0 );
		}
	}
	return $total;
}

/**
 * Total number of checks in a scan (regardless of findings). Lets a surface show
 * a reassuring "M checks passed" (all-clear) or "F of M checks flagged" without
 * re-deriving the denominator inline. Single source of truth, like its siblings.
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return int Count of every check the scan ran (0 when there is no scan).
 * @since 7.1.0
 */
function sn_health_check_total( $scan ) {
	if ( ! is_array( $scan ) ) {
		return 0;
	}
	return count( (array) ( $scan['checks'] ?? array() ) );
}

/**
 * The NON-advisory checks that have findings, ranked by count (descending).
 *
 * Equal counts keep their scan (definition) order — PHP 8's sort is stable.
 * Advisory-tier checks never appear here (they must not drive attention
 * strips / review pills); their counts live in sn_health_advisory_total().
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return array<string,array> key => check envelope, count>0 only, count-desc.
 */
function sn_health_flagged_checks( $scan ) {
	if ( ! is_array( $scan ) ) {
		return array();
	}
	$advisory = sn_health_advisory_checks();
	$flagged  = array();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( in_array( (string) $key, $advisory, true ) ) {
			continue;
		}
		if ( (int) ( $check['count'] ?? 0 ) > 0 ) {
			$flagged[ $key ] = $check;
		}
	}
	uasort( $flagged, static function ( $a, $b ) {
		return (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
	} );
	return $flagged;
}

/**
 * Humanize a scan-elapsed value: sub-second stays milliseconds ("412ms"),
 * one second and up reads as seconds with one decimal ("22.2s"). Shared by
 * the Health hero, the Insights rail status box, and the weekly-digest meta
 * (relocated here from inc/health-checks-admin.php in v8.0.4 when Insights
 * adopted it — the v8.0.1 fix had only covered the Health hero).
 *
 * @param int $ms Elapsed milliseconds.
 * @return string
 * @since 8.0.1
 */
function snt_health_format_elapsed( $ms ) {
	$ms = (int) $ms;
	if ( $ms >= 1000 ) {
		return sprintf( '%.1fs', $ms / 1000 );
	}
	return $ms . 'ms';
}
