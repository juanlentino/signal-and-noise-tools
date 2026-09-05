<?php
/**
 * Standalone test: a health check that packs LITERALLY EMPTY findings must say
 * whether it ran.
 *
 * WHY THIS EXISTS. `sn_health_pack_check( $label, $findings, $fix_hint, $skipped )`
 * grew `$skipped` in v11.33.0 because zero findings meant both "nothing wrong"
 * and "could not run". v13.97.4 (#1039) fixed seven calls that put the reason
 * in `$fix_hint`, which the tally never reads. A silent-failure audit the next
 * day found fourteen more, plus a check that never used the helper at all
 * (#1042). The pattern recurs because a three-argument call with `array()` is
 * syntactically complete: nothing forces the author to decide which of the
 * two states they are reporting.
 *
 * THE RULE. Every call whose findings argument is the literal `array()` or `[]`
 * must pass a FOURTH argument: `null` when the check ran and found nothing, a
 * reason when it did not run. The decision is made at the call site, in code,
 * where a reviewer can see it. Calls that pass a variable are exempt -- the
 * list may be empty at runtime, but the author did not KNOW it would be.
 *
 * Token-parsed (token_get_all), not regexed: multi-line calls, nested parens
 * and commas inside the hint string are all handled. The function DEFINITION
 * and any test stub are excluded by looking for a preceding T_FUNCTION.
 *
 * NEGATIVE CONTROL. The scanner runs over an in-memory fixture with one
 * offending call and one compliant call and must flag exactly the former.
 * It was also run against main before #1042 landed: 21 sites.
 *
 * @since 13.97.5
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function ef_ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; }
}

/**
 * @return array<int,array{line:int,args:int,findings:string}> every pack_check CALL with literal-empty findings and < 4 args.
 */
function ef_offenders( $src ) {
	$toks = token_get_all( $src );
	$n    = count( $toks );
	$out  = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $toks[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || 'sn_health_pack_check' !== $t[1] ) {
			continue;
		}
		// Skip the definition (and any stub): `function sn_health_pack_check`.
		$p = $i - 1;
		while ( $p >= 0 && is_array( $toks[ $p ] ) && T_WHITESPACE === $toks[ $p ][0] ) { $p--; }
		if ( $p >= 0 && is_array( $toks[ $p ] ) && T_FUNCTION === $toks[ $p ][0] ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $n && '(' !== $toks[ $j ] ) { $j++; }
		if ( $j >= $n ) { continue; }
		$depth = 0;
		$args  = array( '' );
		for ( $k = $j; $k < $n; $k++ ) {
			$s = is_array( $toks[ $k ] ) ? $toks[ $k ][1] : $toks[ $k ];
			if ( '(' === $s || '[' === $s || '{' === $s ) { $depth++; if ( $k === $j ) { continue; } }
			if ( ')' === $s || ']' === $s || '}' === $s ) { $depth--; if ( 0 === $depth ) { break; } }
			if ( 1 === $depth && ',' === $s ) { $args[] = ''; continue; }
			$args[ count( $args ) - 1 ] .= $s;
		}
		$args     = array_map( function ( $a ) { return trim( preg_replace( '/\s+/', ' ', $a ) ); }, $args );
		$findings = $args[1] ?? '';
		if ( in_array( $findings, array( 'array()', '[]' ), true ) && count( $args ) < 4 ) {
			$out[] = array( 'line' => $t[2], 'args' => count( $args ), 'findings' => $findings );
		}
	}
	return $out;
}

echo "health-pack-check-empty-findings-say-why\n\nGroup: negative control -- the scanner can go red, and only on the right shape\n";
$fixture = "<?php\nfunction sn_health_pack_check( \$l, \$f, \$h = '', \$s = null ) { return array(); }\n"
	. "function a() { return sn_health_pack_check( 'A', array(), 'a hint, with a comma' ); }\n"           // offender
	. "function b() { return sn_health_pack_check( 'B', array(), '', null ); }\n"                         // compliant pass
	. "function c() { return sn_health_pack_check( 'C', array(), \$hint, 'did not run' ); }\n"           // compliant skip
	. "function d() { return sn_health_pack_check( 'D', \$findings, \$hint ); }\n"                        // variable: exempt
	. "function e() { return sn_health_pack_check(\n\t'E',\n\tarray(),\n\tsprintf( 'x %s', f( 1, 2 ) )\n); }\n"; // multi-line offender
$off = ef_offenders( $fixture );
ef_ok( 2 === count( $off ), 'fixture: exactly the two offenders are flagged (got ' . count( $off ) . ')' );
ef_ok( 3 === ( $off[0]['line'] ?? 0 ) && 7 === ( $off[1]['line'] ?? 0 ), 'fixture: flagged at lines 3 and 7 -- the definition, the compliant calls and the variable-findings call are not' );

echo "\nGroup: every health module\n";
$files = array_merge( glob( dirname( __DIR__ ) . '/inc/health-*.php' ), glob( dirname( __DIR__ ) . '/inc/provenance-integrity.php' ) );
sort( $files );
ef_ok( count( $files ) >= 30, 'population: ' . count( $files ) . ' health modules scanned (a small number here would mean the glob broke)' );
$calls = 0;
$bad   = array();
foreach ( $files as $f ) {
	$src    = (string) file_get_contents( $f );
	$calls += max( 0, substr_count( $src, 'sn_health_pack_check(' ) - substr_count( $src, 'function sn_health_pack_check(' ) );
	foreach ( ef_offenders( $src ) as $o ) {
		$bad[] = basename( $f ) . ':' . $o['line'] . ' (' . $o['args'] . ' args)';
	}
}
ef_ok( $calls >= 60, "population: $calls pack_check call sites (v13.97.5: 70+)" );
ef_ok( array() === $bad, 'every call with literal-empty findings states its fourth argument' . ( $bad ? " -- offenders:\n      " . implode( "\n      ", $bad ) : '' ) );

echo "\nGroup: the check that never used the helper (#1042)\n";
$alt = (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-check-missing-alt.php' );
ef_ok( false !== strpos( $alt, 'return sn_health_pack_check(' ), 'missing_alt returns through sn_health_pack_check()' );
ef_ok( false === strpos( $alt, "'fix_hint' =>" ), 'missing_alt no longer builds its envelope by hand' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
