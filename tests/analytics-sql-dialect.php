<?php
/**
 * Guard: Cloudflare Analytics Engine SQL dialect conformance.
 *
 * AE's count() takes ZERO arguments — count(*) / count(<arg>) return
 * HTTP 422 "COUNT() function must have 0 arguments". Unique counts use
 * count(DISTINCT <column>) on a BARE column; count(DISTINCT <expression>)
 * (e.g. count(DISTINCT if(...))) is undocumented and was rejected live in
 * v5.2.0. Shape-asserting unit tests run against stubbed transports, so they
 * never execute the SQL and cannot catch a dialect violation — this static
 * guard scans every analytics AE SQL builder source so the regression class
 * fails CI. Added v5.3.0 after the live 422 incident.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

// The SQL inc/analytics-vid.php's SN_AE_VID builds, read from that file so the
// guard and the helper cannot drift apart.
preg_match( "/const SN_AE_VID = '([^']+)';/", (string) file_get_contents( dirname( __DIR__ ) . '/inc/analytics-vid.php' ), $snt_vid );
define( 'SN_AE_VID_SQL', $snt_vid[1] ?? 'SN_AE_VID missing' );

$pass = 0;
$fail = 0;
function dq( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $label\n";
	} else {
		++$fail;
		echo "FAIL: $label\n";
	}
}

/** Strip block + line comments so docblock prose ("use count(*)…") doesn't trip the guard. */
function dialect_code_only( $src ) {
	$src = preg_replace( '!/\*.*?\*/!s', '', (string) $src );
	$src = preg_replace( '!//.*$!m', '', $src );
	// Spell the visitor-day helper (inc/analytics-vid.php) out as the SQL it builds,
	// so the guard judges the statement AE actually receives.
	$src = str_replace( array( "' . SN_AE_VID . '", '" . SN_AE_VID . "' ), SN_AE_VID_SQL, (string) $src );
	return (string) $src;
}

/**
 * AE-invalid: count(<arg>) carrying a non-empty, non-DISTINCT argument — count(*),
 * count( * ), count(col). Whitespace-tolerant. Excludes PHP's count($var) (the
 * lookahead rejects a leading `$`) since these modules use PHP count() on arrays
 * too — only the SQL-literal count(<col>/*) forms are the dialect hazard.
 */
function dialect_bad_count_arg( $code ) {
	// \s*+ is POSSESSIVE: without it the engine backtracks the whitespace so the
	// lookahead lands on a space (not the `$` of a PHP count($var)) and wrongly fires.
	return preg_match( '/\bcount\s*\(\s*+(?!\)|DISTINCT\b|\$)[^)]+\)/i', $code ) === 1;
}

/**
 * AE-invalid: count(DISTINCT <X>) where X is anything but a single bare column
 * identifier — catches count(DISTINCT if(...)), count(DISTINCT toX(...)), etc.,
 * while allowing the documented count(DISTINCT index1) and the visitor-day prefix.
 */
function dialect_bad_count_distinct( $code ) {
	// The ONE allowed expression is the visitor-day prefix, SN_AE_VID exactly:
	// count(DISTINCT substring(index1, 1, 16)) returned 200 on the live dataset on
	// 2026-11-17 (owner's probe via sn_analytics_query on sn_pageviews_v2, equal to
	// count(DISTINCT index1)). Every other count(DISTINCT <expr>) stays rejected.
	return preg_match( '/\bcount\s*\(\s*DISTINCT\s+(?!' . preg_quote( SN_AE_VID_SQL, '/' ) . '\s*\))(?![a-z_][a-z0-9_]*\s*\))/i', $code ) === 1;
}

echo "Group: the count(DISTINCT) rule allows exactly the visitor-day prefix\n";
dq( ! dialect_bad_count_distinct( 'count(DISTINCT index1)' ), 'count(DISTINCT index1) allowed (bare column)' );
dq( ! dialect_bad_count_distinct( 'count(DISTINCT substring(index1, 1, 16))' ), 'count(DISTINCT substring(index1, 1, 16)) allowed (probed live 2026-11-17)' );
dq( dialect_bad_count_distinct( 'count(DISTINCT substring(index1, 1, 8))' ), 'count(DISTINCT substring(index1, 1, 8)) rejected (not the probed expression)' );
dq( dialect_bad_count_distinct( "count(DISTINCT if(blob1 = 'pv', index1, NULL))" ), 'count(DISTINCT if(...)) still rejected' );
echo "\n";

$dir   = dirname( __DIR__ ) . '/inc';
// v5.4.0: analytics-buckets.php joins the scanned set — this list is HARDCODED
// (NOT an auto-glob of inc/analytics-*.php), so every new AE SQL builder file
// must be added here or its dialect conformance ships unguarded.
$files = array( 'analytics-api.php', 'analytics-realtime.php', 'analytics-rollup.php', 'analytics-dims.php', 'analytics-buckets.php', 'analytics-percentiles.php', 'analytics-drilldown.php', 'analytics-pageroles.php', 'analytics-events-rollup.php', 'analytics-rows-events.php', 'analytics-sessions-reports-parts.php', 'analytics-live-readers.php', 'link-codes-read.php', 'analytics-live-sources.php', 'analytics-live-pages.php', 'analytics-live-slots-parts.php', 'analytics-live-hour.php', 'analytics-live-scroll.php', 'analytics-sessions-list.php', 'analytics-rows-sessions.php', 'analytics-pairing.php', 'analytics-live-open-tab.php', 'analytics-sampled-days.php', 'analytics-sampling-since.php' );

echo "Group: AE SQL dialect — no count() with arguments\n";
// analytics-rollup.php is read with its four parts files (split 2026-10-14);
// the absence pins carry a non-empty floor so a missed read cannot pass them.
require_once __DIR__ . '/lib/inc-population.php';
foreach ( $files as $f ) {
	$code = dialect_code_only( 'analytics-rollup.php' === $f ? snt_test_analytics_rollup_src() : (string) file_get_contents( "$dir/$f" ) );
	dq( '' !== $code && ! dialect_bad_count_arg( $code ), "$f: no count(<arg>) — AE count() takes 0 args (count(*)/count(col) rejected)" );
	dq( '' !== $code && ! dialect_bad_count_distinct( $code ), "$f: no count(DISTINCT <expr>) — AE accepts DISTINCT of a bare column only" );
}

echo "\nGroup: AE SQL dialect — expected valid forms present\n";
$api = dialect_code_only( file_get_contents( "$dir/analytics-api.php" ) );
dq( preg_match( '/SELECT\s+count\(\)\s+AS\s+n/i', $api ) === 1, 'probe: SELECT count() AS n (0-arg row count)' );

$rt = dialect_code_only( file_get_contents( "$dir/analytics-realtime.php" ) );
dq( strpos( $rt, 'count(DISTINCT ' . SN_AE_VID_SQL . ')' ) !== false, 'realtime: count(DISTINCT <visitor-day prefix>)' );

$rollup = dialect_code_only( snt_test_analytics_rollup_src() );
dq( strpos( $rollup, 'count(DISTINCT ' . SN_AE_VID_SQL . ')' ) !== false, 'rollup: count(DISTINCT <visitor-day prefix>)' );

$dims = dialect_code_only( file_get_contents( "$dir/analytics-dims.php" ) );
dq( strpos( $dims, 'count(DISTINCT ' . SN_AE_VID_SQL . ')' ) !== false, 'dims: count(DISTINCT <visitor-day prefix>)' );
dq( strpos( $dims, "WHERE blob1 = 'pv'" ) !== false, 'dims: pv-filtered window enables plain sum()/count(DISTINCT col)' );

echo "\nGroup: derived buckets use only the proven primitives (no toHour/quantile)\n";
$buckets = dialect_code_only( file_get_contents( "$dir/analytics-buckets.php" ) );
dq( strpos( $buckets, "formatDateTime(timestamp, '%H')" ) !== false, 'buckets: hour-of-day via formatDateTime %H (the proven primitive)' );
dq( strpos( $buckets, 'toHour(' ) === false && strpos( $buckets, 'toDayOfWeek(' ) === false, 'buckets: avoids the unvalidated toHour()/toDayOfWeek()' );
dq( strpos( $buckets, 'quantile' ) === false, 'buckets: distributions via sum(if()) — no unvalidated quantile*()' );
dq( strpos( $buckets, 'sum(if(' ) !== false, 'buckets: distribution bands use the documented sum(if()) form' );

echo "\nGroup: percentiles use the AE-whitelisted weighted quantile form\n";
$pctl = dialect_code_only( file_get_contents( "$dir/analytics-percentiles.php" ) );
dq( strpos( $pctl, 'quantileExactWeighted(' ) !== false, 'percentiles: uses quantileExactWeighted (AE-whitelisted)' );
// Each of the three levels must be the PARAMETRIC, VALUE-FIRST weighted form
// quantileExactWeighted(q)(<col>, _sample_interval). Asserting per-level (not just
// "one parametric call exists") catches a regression that flips a single column to
// the flat/arg-reversed quantileExactWeighted(_sample_interval, <col>) — the exact
// form that 422s. (Mutation-falsified: reversing any one column trips this.)
foreach ( array( '0.5', '0.75', '0.9' ) as $q ) {
	dq( preg_match( '/quantileExactWeighted\(' . preg_quote( $q, '/' ) . '\)\([^,)]+, _sample_interval\)/', $pctl ) === 1, "percentiles: $q is parametric value-first quantileExactWeighted($q)(col, _sample_interval)" );
}
dq( preg_match( '/quantileExactWeighted\s*\(\s*_sample_interval/', $pctl ) === 0, 'percentiles: weight is NEVER the first arg (no flat/arg-reversed form)' );
dq( strpos( $pctl, '_sample_interval' ) !== false, 'percentiles: weighted by _sample_interval (sampling-correct)' );
dq( strpos( $pctl, 'quantileWeighted(' ) === false, 'percentiles: not the flat quantileWeighted alias' );
dq( strpos( $pctl, 'toDateTime(' ) !== false, 'percentiles: explicit date-bounded window (not trailing INTERVAL)' );

echo "\nGroup: drill-down uses proven primitives, no LIMIT\n";
$drill = dialect_code_only( file_get_contents( "$dir/analytics-drilldown.php" ) );
dq( strpos( $drill, 'sum(_sample_interval)' ) !== false, 'drilldown: sample-corrected views' );
dq( strpos( $drill, 'count(DISTINCT ' . SN_AE_VID_SQL . ')' ) !== false, 'drilldown: visits via count(DISTINCT bare column)' );
dq( preg_match( '/\bLIMIT\b/i', $drill ) === 0, 'drilldown: no LIMIT against AE (PHP-slices instead)' );
dq( strpos( $drill, '{$col} IN (' ) !== false, 'drilldown: parent-col IN-set filter (proven shape; a brand drills its member hosts)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
