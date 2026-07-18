<?php
/**
 * AE dialect probe — Phase A pre-flight P0.1 + P0.2 (plan: docs/analytics-integrity-plan.md).
 *
 * OWNER-RUN, DEV-ONLY. Never bundled (tools/ is export-ignored), never
 * registered by the plugin loader, excluded from the CI test sweep (which
 * globs tests/*.php only) and from PHPCS (tools/ exclude-pattern).
 *
 * Run ON LIVE via:  wp eval-file tools/ae-dialect-probe.php
 *
 * What it decides, over a 1-day window, all executed through the SAME AE
 * client the rollup uses (sn_analytics_query() in inc/analytics-api.php —
 * same config resolution, auth, timeout, redirect policy):
 *
 *   P0.1 PRIMARY     count(DISTINCT if(blob1 = 'pv', index1, NULL)) appended
 *                    to the existing rollup SELECT. This is the form the
 *                    dialect guard (tests/analytics-sql-dialect.php) bans in
 *                    inc/ because count(DISTINCT <expr>) was rejected live in
 *                    v5.2.0 — this probe re-tests it deliberately. NO ''
 *                    sentinel substitute: an empty string would count as a
 *                    distinct value and poison the count.
 *   P0.1 FALLBACK A  the existing visits query shape with AND blob1 = 'pv'
 *                    in WHERE and count(DISTINCT index1) — same dialect
 *                    surface already proven live.
 *   P0.2 WEIGHTED    sumIf(double1 * _sample_interval, blob1 = 'sc') (+ the
 *                    tm twin and the weighted event counts) alongside the
 *                    proven sumIf(_sample_interval, blob1 = 'pv'). If the
 *                    multiplication form is rejected, the sum(if(cond,
 *                    double * _sample_interval, 0)) fallback runs
 *                    automatically in the same invocation (one owner ask).
 *
 * It prints, per candidate: the generated SQL, the RAW AE JSON response
 * verbatim (captured via the http_api_debug hook — the client itself strips
 * the body down to `data`), PASS/FAIL, and a one-line verdict. AE errors are
 * printed verbatim, never swallowed. An EMPTY data array is a PASS — a quiet
 * day still proves the dialect parses; empty is an ANSWER.
 *
 * The pure SQL transforms below are unit-tested by tests/ae-dialect-probe.php
 * (which defines SN_AE_PROBE_TEST to skip the execution section). Every
 * transform fails LOUDLY (returns null) if the rollup builder's shape drifted
 * from the needles — never a silently-unchanged query, which would probe the
 * wrong candidate and false-PASS.
 *
 * @package SignalNoiseTools
 */

// SECURITY: Prevent web access. Dev tool, not a runtime module. Allow only
// CLI / WP-CLI invocations (mirrors tests/contracts-smoke.php).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

// ── Pure SQL transforms (no WP calls — unit-testable) ────────────────────────

/**
 * P0.1 PRIMARY: append the gated-distinct candidate to the rollup SELECT.
 *
 * @param string $base Output of sn_analytics_rollup_sql().
 * @return string|null Transformed SQL, or null when the base shape drifted.
 */
function sn_ae_probe_primary_sql( $base ) {
	$needle = 'AS time_avg FROM';
	if ( 1 !== substr_count( (string) $base, $needle ) ) {
		return null;
	}
	return str_replace(
		$needle,
		"AS time_avg, count(DISTINCT if(blob1 = 'pv', index1, NULL)) AS pageview_visits FROM",
		(string) $base
	);
}

/**
 * P0.1 FALLBACK A: the existing visits shape, WHERE-gated to pv rows.
 *
 * Three coupled edits, each verified against the live builder output:
 *   1. SELECT aggregates → count(DISTINCT index1) AS pageview_visits only
 *      (avgIf over the now-empty sc/tm set could emit non-JSON NaN).
 *   2. WHERE gains AND blob1 = 'pv'.
 *   3. ORDER BY re-aliased — AE resolves aliases only (alias-only ORDER BY
 *      gotcha), so the dropped `views` alias would 422.
 *
 * @param string $base Output of sn_analytics_rollup_sql().
 * @return string|null Transformed SQL, or null when the base shape drifted.
 */
function sn_ae_probe_fallback_a_sql( $base ) {
	$base   = (string) $base;
	$agg    = "sumIf(_sample_interval, blob1 = 'pv') AS views, count(DISTINCT index1) AS visits, avgIf(double1, blob1 = 'sc') AS scroll_avg, avgIf(double2, blob1 = 'tm') AS time_avg FROM";
	$group  = ' GROUP BY day, path, class';
	$order  = 'ORDER BY day DESC, views DESC';
	if ( 1 !== substr_count( $base, $agg ) || 1 !== substr_count( $base, $group ) || 1 !== substr_count( $base, $order ) ) {
		return null;
	}
	$sql = str_replace( $agg, 'count(DISTINCT index1) AS pageview_visits FROM', $base );
	$sql = str_replace( $group, " AND blob1 = 'pv'" . $group, $sql );
	$sql = str_replace( $order, 'ORDER BY day DESC, pageview_visits DESC', $sql );
	return $sql;
}

/**
 * P0.2 WEIGHTED: the four engagement-sum columns in the multiplication form,
 * appended beside the proven sumIf(_sample_interval, blob1 = 'pv').
 *
 * Event counts use sumIf(_sample_interval, cond) — the weighted count, NOT a
 * raw countIf (research §5: under sampling, counts are sum(_sample_interval)).
 *
 * @param string $base Output of sn_analytics_rollup_sql().
 * @return string|null Transformed SQL, or null when the base shape drifted.
 */
function sn_ae_probe_weighted_sql( $base ) {
	$needle = 'AS time_avg FROM';
	if ( 1 !== substr_count( (string) $base, $needle ) ) {
		return null;
	}
	$cols = 'AS time_avg, '
		. "sumIf(double1 * _sample_interval, blob1 = 'sc') AS scroll_sum, "
		. "sumIf(_sample_interval, blob1 = 'sc') AS scroll_events, "
		. "sumIf(double2 * _sample_interval, blob1 = 'tm') AS time_sum, "
		. "sumIf(_sample_interval, blob1 = 'tm') AS time_events FROM";
	return str_replace( $needle, $cols, (string) $base );
}

/**
 * P0.2 WEIGHTED FALLBACK: sum(if(cond, double * _sample_interval, 0)) for the
 * two multiplication sums — the form already proven live by the
 * analytics-buckets distribution bands. The event counts stay
 * sumIf(_sample_interval, cond): that form is live today (views), needs no
 * fallback.
 *
 * @param string $base Output of sn_analytics_rollup_sql().
 * @return string|null Transformed SQL, or null when the base shape drifted.
 */
function sn_ae_probe_weighted_fallback_sql( $base ) {
	$needle = 'AS time_avg FROM';
	if ( 1 !== substr_count( (string) $base, $needle ) ) {
		return null;
	}
	$cols = 'AS time_avg, '
		. "sum(if(blob1 = 'sc', double1 * _sample_interval, 0)) AS scroll_sum, "
		. "sumIf(_sample_interval, blob1 = 'sc') AS scroll_events, "
		. "sum(if(blob1 = 'tm', double2 * _sample_interval, 0)) AS time_sum, "
		. "sumIf(_sample_interval, blob1 = 'tm') AS time_events FROM";
	return str_replace( $needle, $cols, (string) $base );
}

// Unit tests load only the pure transforms above.
if ( defined( 'SN_AE_PROBE_TEST' ) ) {
	return;
}

// ── WP bootstrap (mirrors tests/contracts-smoke.php) ─────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( file_exists( $wp_load ) ) {
		require_once $wp_load;
	} else {
		die( "ABSPATH not set and wp-load.php not findable 4 dirs up. Run via `wp eval-file tools/ae-dialect-probe.php`.\n" );
	}
}

foreach ( array( 'sn_analytics_config', 'sn_analytics_query', 'sn_analytics_last_error', 'sn_analytics_rollup_sql', 'sn_analytics_site_tz_name' ) as $sn_ae_probe_fn ) {
	if ( ! function_exists( $sn_ae_probe_fn ) ) {
		die( "FATAL: {$sn_ae_probe_fn}() not defined — is the signal-and-noise-tools plugin active?\n" );
	}
}

if ( ! sn_analytics_config() ) {
	die( "FATAL: AE not configured (sn_analytics_config() is null) — set SN_CF_ANALYTICS_TOKEN / SN_CF_ACCOUNT_ID.\n" );
}

// ── Raw-response capture ──────────────────────────────────────────────────────
// sn_analytics_query() returns only the decoded `data` and truncates errors to
// a 240-char transient excerpt. The probe needs the RAW AE JSON verbatim, so we
// tap core's http_api_debug action fired for every WP_Http request — the query
// still travels through the real client, untouched.

$GLOBALS['sn_ae_probe_raw'] = null;

/**
 * Capture the raw HTTP exchange for AE SQL requests only.
 *
 * @param array|WP_Error $response    HTTP response or WP_Error.
 * @param string         $context     Always 'response'.
 * @param string         $class       Transport class name.
 * @param array          $parsed_args Request args.
 * @param string         $url         Request URL.
 */
function sn_ae_probe_capture_http( $response, $context, $class, $parsed_args, $url ) {
	if ( false === strpos( (string) $url, '/analytics_engine/sql' ) ) {
		return;
	}
	if ( is_wp_error( $response ) ) {
		$GLOBALS['sn_ae_probe_raw'] = 'WP_Error: ' . $response->get_error_message();
		return;
	}
	$GLOBALS['sn_ae_probe_raw'] = array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
}
add_action( 'http_api_debug', 'sn_ae_probe_capture_http', 10, 5 );

/**
 * Run one candidate through the real client; print SQL, raw response, PASS/FAIL.
 *
 * PASS = the client returned an array ( HTTP 200 + parseable JSON with `data`).
 * An empty data array still PASSes: a quiet day proves the dialect parses —
 * empty is an ANSWER. Failures print the raw body AND the client's captured
 * error verbatim, never a swallowed null.
 *
 * @param string      $label Candidate name.
 * @param string|null $sql   Transformed SQL (null = builder-shape drift).
 * @return bool
 */
function sn_ae_probe_run( $label, $sql ) {
	echo "=== {$label} ===\n";
	if ( null === $sql ) {
		echo "FAIL: {$label}\n";
		echo "  SQL transform returned null — sn_analytics_rollup_sql() shape drifted from the\n";
		echo "  probe's needles. Update tools/ae-dialect-probe.php (and tests/ae-dialect-probe.php).\n\n";
		return false;
	}
	echo "SQL: {$sql}\n";
	$GLOBALS['sn_ae_probe_raw'] = null;
	$rows = sn_analytics_query( $sql );

	if ( is_array( $GLOBALS['sn_ae_probe_raw'] ) ) {
		echo 'HTTP ' . $GLOBALS['sn_ae_probe_raw']['code'] . "\n";
		echo 'RAW: ' . $GLOBALS['sn_ae_probe_raw']['body'] . "\n";
	} elseif ( is_string( $GLOBALS['sn_ae_probe_raw'] ) ) {
		echo 'RAW: ' . $GLOBALS['sn_ae_probe_raw'] . "\n";
	} else {
		echo "RAW: (no HTTP exchange captured — the request never left the client)\n";
	}

	$ok = is_array( $rows );
	if ( ! $ok ) {
		$err = sn_analytics_last_error();
		if ( is_array( $err ) ) {
			echo 'CLIENT ERROR: HTTP ' . $err['code'] . ' — ' . $err['message'] . "\n";
		}
	} elseif ( array() === $rows ) {
		echo "NOTE: empty data — quiet window; the dialect PARSED (empty is an answer).\n";
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n\n";
	return $ok;
}

// ── Execute ───────────────────────────────────────────────────────────────────

echo "AE dialect probe — Phase A P0.1/P0.2 (1-day window)\n";
echo 'Site: ' . home_url() . "\n";
$sn_ae_probe_tz = sn_analytics_site_tz_name();
echo 'Zone: ' . ( '' !== $sn_ae_probe_tz ? $sn_ae_probe_tz : '(UTC path)' ) . "\n\n";

$sn_ae_probe_base = sn_analytics_rollup_sql( 1, $sn_ae_probe_tz );

$sn_ae_probe_primary_ok  = sn_ae_probe_run( 'P0.1 PRIMARY — count(DISTINCT if(blob1 = \'pv\', index1, NULL)) on the rollup SELECT', sn_ae_probe_primary_sql( $sn_ae_probe_base ) );
$sn_ae_probe_fallback_ok = sn_ae_probe_run( 'P0.1 FALLBACK A — WHERE … AND blob1 = \'pv\' + count(DISTINCT index1)', sn_ae_probe_fallback_a_sql( $sn_ae_probe_base ) );
$sn_ae_probe_weighted_ok = sn_ae_probe_run( 'P0.2 WEIGHTED — sumIf(double * _sample_interval, cond) forms', sn_ae_probe_weighted_sql( $sn_ae_probe_base ) );

$sn_ae_probe_weighted_fb_ok = false;
if ( ! $sn_ae_probe_weighted_ok ) {
	$sn_ae_probe_weighted_fb_ok = sn_ae_probe_run( 'P0.2 WEIGHTED FALLBACK — sum(if(cond, double * _sample_interval, 0))', sn_ae_probe_weighted_fallback_sql( $sn_ae_probe_base ) );
}

echo "=== VERDICT ===\n";
if ( $sn_ae_probe_primary_ok ) {
	echo "P0.1: use primary\n";
	echo "  NOTE: tests/analytics-sql-dialect.php bans count(DISTINCT <expr>) across inc/ —\n";
	echo "  adopting primary in inc/analytics-rollup.php requires relaxing that guard for the\n";
	echo "  probed form (it encodes the v5.2.0 live rejection this probe just re-tested).\n";
} elseif ( $sn_ae_probe_fallback_ok ) {
	echo "P0.1: use fallback A\n";
} else {
	echo "P0.1: ESCALATE — both AE candidates failed; last resort is Fallback B\n";
	echo "  (sn_pageview_visits(), inc/analytics-sessions.php — gated SESSIONS, a different\n";
	echo "  unit than gated visitor-days; the field description must say so).\n";
}
if ( $sn_ae_probe_weighted_ok ) {
	echo "P0.2: multiplication form OK — use sumIf(double * _sample_interval, cond)\n";
} elseif ( $sn_ae_probe_weighted_fb_ok ) {
	echo "P0.2: use the sum(if(cond, double * _sample_interval, 0)) fallback form\n";
} else {
	echo "P0.2: ESCALATE — neither weighted form parsed; engagement sums blocked.\n";
}
echo "\nRecord this output under \"P0 results\" in docs/analytics-integrity-plan.md.\n";

$sn_ae_probe_resolved = ( $sn_ae_probe_primary_ok || $sn_ae_probe_fallback_ok )
	&& ( $sn_ae_probe_weighted_ok || $sn_ae_probe_weighted_fb_ok );
exit( $sn_ae_probe_resolved ? 0 : 1 );
