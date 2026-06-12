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
$files = array( 'analytics-api.php', 'analytics-realtime.php', 'analytics-rollup.php', 'analytics-dims.php' );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
