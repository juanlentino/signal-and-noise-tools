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
	// external_links stays ADVISORY, as it has since the 2026-07-02 re-tier.
	// v11.13.2 briefly moved it to the defect tier on the theory that link rot
	// is "wrong on the page today" — true, but it fails the second half of the
	// Health test: a defect must be able to REACH ZERO and stay there, and the
	// external web rots continuously and outside our control. An advisory that
	// can never resolve is precisely what this tier is for. What changed is
	// only WHERE it renders (the worklist surface), not what it is.
	return array( 'external_links', 'link_opportunities', 'stale_posts_evergreen' );
}

/**
 * Total findings across every NON-advisory check in a scan.
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return int Sum of every fault-tier check's count (0 when there is no scan).
 */
function sn_health_finding_total( $scan ) {
	$scan  = sn_health_scan_for_surface( $scan );
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
 * ADVISORY IS A TIER, NOT A SURFACE — and that distinction is what v11.17.0
 * fixes. The tier is enumerated by key (sn_health_advisory_checks()); the
 * surface is where a check RENDERS. Since v11.13.0 all three advisory-tier
 * keys render on the `worklist` surface, so a total scoped to `health` could
 * never see one: this returned a structural 0 while the ability's own schema
 * pointed callers here for advisory counts. Zero read as "measured, none
 * found" when the truth was "not measured on this surface"
 * (see the realtime zero-vs-null rule).
 *
 * The DEFAULT stays 'health' so every rendering caller is byte-identical: on
 * the Health tab advisories are deliberately not shown, which is a shipped
 * decision (v11.16.1), not an accident. Pass NULL to count the tier wherever
 * it lives — what an agent-facing readout means by "how many advisories".
 *
 * @param array|null  $scan    A sn_health_last_scan() array (or null / non-array).
 * @param string|null $surface Surface to narrow to, or NULL for every surface.
 * @return int
 * @since 8.0.4
 */
function sn_health_advisory_total( $scan, $surface = 'health' ) {
	$scan  = ( null === $surface ) ? $scan : sn_health_scan_for_surface( $scan, $surface );
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
 * True when a check carries a REPORT payload — the report-only tier (v10.83.0).
 *
 * A report-only check raises zero findings BY DESIGN and its whole output is
 * the `report` array (contrast_tokens is the first: an arithmetic pair table
 * plus the coverage sentence that says which tier it measured). The test is
 * STRUCTURAL, not a key allowlist, so the next report-only check gets a home
 * on the Health tab the moment it packs a report — the failure this tier was
 * introduced to fix was precisely that contrast_tokens had nowhere to render.
 *
 * @param mixed $check One check envelope from a scan.
 * @return bool
 * @since 10.83.0
 */
function sn_health_check_has_report( $check ) {
	return is_array( $check ) && ! empty( $check['report'] ) && is_array( $check['report'] );
}

/**
 * The report-only checks in a scan, scan order preserved.
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return array<string,array> key => check envelope.
 * @since 10.83.0
 */
function sn_health_report_checks( $scan ) {
	if ( ! is_array( $scan ) ) {
		return array();
	}
	$reports = array();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( sn_health_check_has_report( $check ) ) {
			$reports[ $key ] = $check;
		}
	}
	return $reports;
}

/**
 * The checks that genuinely PASSED: zero findings and no report payload.
 *
 * A report-only check has zero findings, so the raw `count === 0` split
 * counted it as passing and printed it as a green chip — the exact overclaim
 * inc/health-contrast-tokens.php warns about in its own docblock ("a clean
 * sweep here is not a clean site"). It is not a pass; it is a reading. Callers
 * pair this with sn_health_report_checks() and name the gap, rather than
 * shrinking the denominator: sn_health_check_total() still counts every check
 * the scan ran, so nothing downstream has to be re-derived.
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return array<string,array> key => check envelope.
 * @since 10.83.0
 */
function sn_health_passing_checks( $scan ) {
	$scan = sn_health_scan_for_surface( $scan );
	if ( ! is_array( $scan ) ) {
		return array();
	}
	$passing = array();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( sn_health_check_has_report( $check ) ) {
			continue;
		}
		// A check that could not run is not a passing check. This accessor feeds
		// the "Checks passed N / M" card, the WP dashboard widget's numerator,
		// AND the rendered list of passing checks — so leaving it here would
		// have PRINTED a check that never executed under "passing".
		if ( sn_health_check_is_skipped( $check ) ) {
			continue;
		}
		if ( 0 === (int) ( $check['count'] ?? 0 ) ) {
			$passing[ $key ] = $check;
		}
	}
	return $passing;
}

/**
 * Is this check reporting that it could not run?
 *
 * One predicate, so the ordering rule (a skip only counts as a skip when the
 * check produced no findings — evidence outranks absence) lives in exactly one
 * place rather than being restated at each call site.
 *
 * @since 11.33.0
 * @param array $check Packed check.
 * @return bool
 */
function sn_health_check_is_skipped( $check ) {
	if ( ! is_array( $check ) ) {
		return false;
	}
	// array_key_exists, not isset: `skipped => null` is the producer SAYING the
	// check ran, and a MISSING key is a scan cached before the field existed —
	// both mean "ran", but for different reasons worth keeping distinguishable.
	$reason = array_key_exists( 'skipped', $check ) ? $check['skipped'] : null;
	return is_string( $reason ) && '' !== $reason && 0 === (int) ( $check['count'] ?? 0 );
}

/**
 * The checks that could not run this scan, keyed as they are in the scan.
 *
 * A count alone tells a reader that something is missing without telling them
 * what to do about it, so each entry keeps its `skipped` reason.
 *
 * @since 11.33.0
 * @param array|null $scan A sn_health_last_scan() array.
 * @return array<string,array>
 */
function sn_health_skipped_checks( $scan ) {
	$scan = sn_health_scan_for_surface( $scan );
	if ( ! is_array( $scan ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( sn_health_check_is_skipped( $check ) ) {
			$out[ $key ] = $check;
		}
	}
	return $out;
}

/**
 * A scan narrowed to one surface, for any readout that speaks about "health".
 *
 * v11.15.0. Filtering was applied per-consumer, and per-consumer meant missed
 * consumers: after v11.13.0 moved measurements and trust checks off the Health
 * surface, the desktop widget was fixed but the S&N Dashboard card, the WP
 * Site Health widget and the MCP ability all still counted the whole envelope.
 * They kept reporting `ledger_ci` as a Health finding while the Health tab —
 * correctly — did not. Every surface that says "health" now narrows the same
 * way, through here.
 *
 * The scan itself is never mutated: callers get a copy, and the full envelope
 * stays available for anything that genuinely wants all 21 checks.
 *
 * @param array|null $scan
 * @param string     $surface
 * @return array|null
 */
function sn_health_scan_for_surface( $scan, $surface = 'health' ) {
	if ( ! is_array( $scan ) || ! function_exists( 'sn_health_checks_for_surface' ) ) {
		return $scan;
	}
	$scan['checks'] = sn_health_checks_for_surface( $scan, $surface );
	return $scan;
}

/**
 * WHY THE ACCESSORS BELOW NARROW THEMSELVES (v11.16.1).
 *
 * v11.13.0 moved measurements, trust checks and worklists off the Health
 * surface, and every readout that says "health" had to follow. That was done
 * caller by caller — and caller by caller is precisely how callers get missed.
 * It took three passes: the desktop widget, then the Dashboard card + Site
 * Health widget + MCP ability, and the Dashboard's own attention strip STILL
 * said "1 health finding" beside a card reading "0 findings · all clear". Two
 * readouts, one page, opposite answers. Five more consumers were still
 * unscoped: the attention strip, the desktop attention badge, the run-scan
 * ability, the morning brief, and the post-scan notice.
 *
 * So the scoping moved INTO the accessors. "How many findings" now means
 * "health findings" wherever it is asked, and a new consumer is correct
 * without having to know this history. Anything that genuinely wants the whole
 * envelope reads $scan['checks'] directly — sn_health_run_scan() still returns
 * all 21 checks and the MCP envelope is unchanged.
 *
 * Narrowing is idempotent, so a caller that already scoped (the Health tab)
 * loses nothing by passing a scoped scan in.
 */

/**
 * The COMPLETE partition of a scan's checks, in one place.
 *
 * WHY THIS EXISTS: the Health tab showed "17 / 21 passed · 2 report-only checks
 * not counted", which invites the reader to conclude 21-17-2 = two failures. In
 * fact only ONE was a finding; the other was `link_opportunities`, an ADVISORY —
 * a tier the same page describes as "surfaced, never alarming… a clean site can
 * carry them indefinitely". The fraction silently demoted an advisory into the
 * defect bucket, and that gap is most of the "Health feels unreliable" problem.
 *
 * v10.83.0 already fixed exactly this shape for the report-only tier by naming
 * it in a meta line. The bug was that the fix was specific to one tier instead
 * of general, so the NEXT tier to leave the numerator re-opened the hole —
 * which `stale_posts_evergreen` (v11.12.0) would have done the moment it
 * carried a row.
 *
 * The buckets are mutually exclusive and MUST sum to the total. That invariant
 * is asserted in tests/health-tally-partition.php: a future tier that forgets to
 * declare itself fails the suite instead of quietly vanishing from the tally.
 *
 * Precedence matches sn_health_passing_checks(): a report is a report first.
 *
 * @param array|null $scan
 * @return array{passed:int,findings:int,advisories:int,reports:int,total:int}
 */
function sn_health_check_partition( $scan ) {
	$out = array( 'passed' => 0, 'findings' => 0, 'advisories' => 0, 'reports' => 0, 'skipped' => 0, 'total' => 0 );
	if ( ! is_array( $scan ) ) {
		return $out;
	}
	// v11.16.2: scope, like every sibling accessor. This partition feeds
	// sn_health_passed_meta(), whose whole contract is that
	// `passed + (what it names) === total`. Once the displayed total narrowed to
	// the health surface, an unscoped partition named buckets that were not in
	// the denominator and the arithmetic stopped closing. Same argument v11.16.1
	// made for advisories: a relocated check is named by the tab's "Also
	// scanned, shown elsewhere" block, never by the hero that no longer counts it.
	$scan          = sn_health_scan_for_surface( $scan );
	$advisory_keys = sn_health_advisory_checks();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		++$out['total'];
		if ( sn_health_check_has_report( $check ) ) {
			++$out['reports'];
			continue;
		}
		// v11.33.0: a check that COULD NOT RUN is not a check that passed.
		// This is tested BEFORE the zero-count branch below, which is the whole
		// fix — a skipped check has zero findings, so it used to fall straight
		// into `passed` and the tab reported 7/7 while three of the seven had
		// not run. Ordered after the count test would restore the bug exactly.
		//
		// EVIDENCE OUTRANKS ABSENCE: a check that bailed out but had already
		// found something is filed under its findings, not here. Reporting a
		// live defect as "skipped" would discard it, which is worse than
		// over-reporting a partial scan.
		//
		// array_key_exists, not isset: `skipped => null` is the producer SAYING
		// the check ran, and isset() cannot tell that from a missing key.
		if ( sn_health_check_is_skipped( $check ) ) {
			++$out['skipped'];
			continue;
		}
		if ( 0 === (int) ( $check['count'] ?? 0 ) ) {
			// An advisory check with nothing to say has genuinely passed.
			++$out['passed'];
			continue;
		}
		if ( in_array( (string) $key, $advisory_keys, true ) ) {
			++$out['advisories'];
			continue;
		}
		++$out['findings'];
	}
	return $out;
}

/**
 * The one-line accounting for everything that is NOT in the passed numerator.
 *
 * Returns '' when every check passed — there is nothing to explain. Otherwise
 * it names each bucket, so `passed + (what this line lists) === total` always
 * closes on screen. Counts CHECKS, never items: `link_opportunities` is one
 * advisory-carrying check whatever its 18 rows say, and conflating the two is
 * how "18 advisories" started reading like eighteen missing checks.
 *
 * @param array|null $scan
 * @return string
 */
function sn_health_passed_meta( $scan ) {
	$p     = sn_health_check_partition( $scan );
	$parts = array();
	if ( $p['findings'] > 0 ) {
		$parts[] = sprintf( '%d with findings', $p['findings'] );
	}
	if ( $p['advisories'] > 0 ) {
		$parts[] = sprintf( '%d advisory-only', $p['advisories'] );
	}
	if ( $p['reports'] > 0 ) {
		$parts[] = sprintf( '%d report-only', $p['reports'] );
	}
	// Named, never silently dropped from the numerator. The reader has to be
	// able to reconcile the line: passed + everything named here === total.
	if ( $p['skipped'] > 0 ) {
		$parts[] = sprintf( '%d skipped', $p['skipped'] );
	}
	if ( ! $parts ) {
		return '';
	}
	return implode( ' · ', $parts ) . ' — not counted as passed';
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
	$scan = sn_health_scan_for_surface( $scan );
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
	$scan = sn_health_scan_for_surface( $scan );
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
