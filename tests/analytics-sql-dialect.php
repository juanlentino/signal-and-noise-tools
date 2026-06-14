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
 * while allowing the documented count(DISTINCT index1).
 */
function dialect_bad_count_distinct( $code ) {
	return preg_match( '/\bcount\s*\(\s*DISTINCT\s+(?![a-z_][a-z0-9_]*\s*\))/i', $code ) === 1;
}

$dir   = dirname( __DIR__ ) . '/inc';
// v5.4.0: analytics-buckets.php joins the scanned set — this list is HARDCODED
// (NOT an auto-glob of inc/analytics-*.php), so every new AE SQL builder file
// must be added here or its dialect conformance ships unguarded.
$files = array( 'analytics-api.php', 'analytics-realtime.php', 'analytics-rollup.php', 'analytics-dims.php', 'analytics-buckets.php', 'analytics-percentiles.php', 'analytics-drilldown.php' );

echo "Group: AE SQL dialect — no count() with arguments\n";
foreach ( $files as $f ) {
	$code = dialect_code_only( file_get_contents( "$dir/$f" ) );
	dq( ! dialect_bad_count_arg( $code ), "$f: no count(<arg>) — AE count() takes 0 args (count(*)/count(col) rejected)" );
	dq( ! dialect_bad_count_distinct( $code ), "$f: no count(DISTINCT <expr>) — AE accepts DISTINCT of a bare column only" );
}

echo "\nGroup: AE SQL dialect — expected valid forms present\n";
$api = dialect_code_only( file_get_contents( "$dir/analytics-api.php" ) );
dq( preg_match( '/SELECT\s+count\(\)\s+AS\s+n/i', $api ) === 1, 'probe: SELECT count() AS n (0-arg row count)' );

$rt = dialect_code_only( file_get_contents( "$dir/analytics-realtime.php" ) );
dq( strpos( $rt, 'count(DISTINCT index1)' ) !== false, 'realtime: count(DISTINCT index1) (plain column)' );

$rollup = dialect_code_only( file_get_contents( "$dir/analytics-rollup.php" ) );
dq( strpos( $rollup, 'count(DISTINCT index1)' ) !== false, 'rollup: count(DISTINCT index1) (plain column)' );

$dims = dialect_code_only( file_get_contents( "$dir/analytics-dims.php" ) );
dq( strpos( $dims, 'count(DISTINCT index1)' ) !== false, 'dims: count(DISTINCT index1) (plain column)' );
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
dq( strpos( $drill, 'count(DISTINCT index1)' ) !== false, 'drilldown: visits via count(DISTINCT bare column)' );
dq( preg_match( '/\bLIMIT\b/i', $drill ) === 0, 'drilldown: no LIMIT against AE (PHP-slices instead)' );
dq( strpos( $drill, "{\$col} = '" ) !== false, 'drilldown: parameterised parent-col equality filter' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
