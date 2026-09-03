<?php
/**
 * The test suites' own shape.
 *
 * tests/run.sh already catches two false greens: a suite that emits NO summary
 * line (crash, fatal, silent skip) and one that emits "0 passed, 0 failed"
 * (ran, asserted nothing). Both are runtime checks on output.
 *
 * Neither catches the third shape, which shipped twice on 2026-09-02: assertions
 * placed AFTER the summary line. The suite runs, prints a perfectly healthy
 * count, and silently never executes the block below the `exit()`. In
 * tests/analytics-view-search.php that was FOURTEEN assertions sitting past the
 * exit; the count stayed at 20 and the only tell was that it had not MOVED.
 *
 * A count that does not move is not a signal anyone watches. This makes it a
 * failure instead.
 *
 * KNOWN LIMIT, stated so nobody over-trusts this: the other vacuity modes from
 * that day are SEMANTIC and not statically detectable — a harness that stubs
 * nothing so `function_exists`-guarded code is inert, a fixture too tight to
 * exercise the branch, `(float) null === 0.0` erasing the distinction under
 * test. Those were caught by mutation, and only mutation catches them.
 *
 * @since 13.83.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS  $label\n";
	} else {
		++$fail;
		echo "FAIL  $label\n";
	}
}

/**
 * Assertions that can never run, because they sit below the summary line.
 *
 * Keyed on the LAST summary echo rather than on `exit(`: an `exit` inside the
 * SECURITY guard at the top of every suite is both unconditional-looking and
 * perfectly reachable-past, so keying on it would flag all 537.
 *
 * @param string $src A suite's source.
 * @return int[] 1-indexed lines of unreachable ok() calls.
 */
function sn_unreachable_assertions( $src ) {
	$lines = explode( "\n", $src );
	$sum   = array();
	foreach ( $lines as $i => $l ) {
		if ( false !== strpos( $l, 'echo' ) && preg_match( '/passed.*failed/', $l ) ) {
			$sum[] = $i;
		}
	}
	if ( array() === $sum ) {
		return array(); // No summary at all — run.sh already fails that, loudly.
	}
	$last = (int) end( $sum );
	$out  = array();
	foreach ( array_slice( $lines, $last + 1 ) as $i => $l ) {
		if ( preg_match( '/^\s*ok\(/', $l ) ) {
			$out[] = $last + 2 + $i;
		}
	}
	return $out;
}

// ── NEGATIVE CONTROL, first: prove the scanner detects a planted violation ───
// A guard that has never fired is indistinguishable from one that cannot.
$sn_bad = "<?php\nok( true, 'a' );\necho \"\\n\$pass passed, \$fail failed\\n\";\nexit( 0 );\nok( true, 'unreachable' );\n";
$sn_hit = sn_unreachable_assertions( $sn_bad );
ok( array( 5 ) === $sn_hit, 'the scanner FINDS an assertion planted below the summary (line ' . implode( ',', $sn_hit ) . ')' );

$sn_good = "<?php\nok( true, 'a' );\nok( true, 'b' );\necho \"\\n\$pass passed, \$fail failed\\n\";\nexit( 0 );\n";
ok( array() === sn_unreachable_assertions( $sn_good ), 'and does NOT flag a well-formed suite' );

// The SECURITY guard's exit at the top of every suite must not trip it.
$sn_sec = "<?php\nif ( PHP_SAPI !== 'cli' ) { exit; }\nok( true, 'a' );\necho \"\\n\$pass passed, \$fail failed\\n\";\n";
ok( array() === sn_unreachable_assertions( $sn_sec ), 'the top-of-file security exit does not make every assertion unreachable' );

// ── The property, across the real suites ────────────────────────────────────
$sn_files = glob( __DIR__ . '/*.php' );
sort( $sn_files );
$sn_scanned = 0;
$sn_bad_files = array();
foreach ( $sn_files as $sn_f ) {
	if ( basename( $sn_f ) === basename( __FILE__ ) ) {
		continue; // this file's synthetic fixtures are strings, not real shape
	}
	$sn_src = (string) file_get_contents( $sn_f );
	if ( false === strpos( $sn_src, 'passed' ) ) {
		continue;
	}
	++$sn_scanned;
	$sn_hits = sn_unreachable_assertions( $sn_src );
	if ( array() !== $sn_hits ) {
		$sn_bad_files[] = basename( $sn_f ) . ' (lines ' . implode( ', ', array_slice( $sn_hits, 0, 3 ) ) . ')';
	}
}

// VACUITY GUARD: a scan that reads nothing passes everything.
ok( $sn_scanned >= 500, 'scanned a plausible number of suites (' . $sn_scanned . ')' );
ok( array() === $sn_bad_files, 'no suite has assertions below its summary line' . ( $sn_bad_files ? ' — ' . implode( '; ', $sn_bad_files ) : '' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
