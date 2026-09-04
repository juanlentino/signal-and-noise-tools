<?php
/**
 * Meta-guard: no suite may enumerate inc/ non-recursively (issue #987).
 *
 * Twelve guards globbed `inc/*.php`. That is the TOP of inc/ and nothing below
 * it, so 86 files in packages were invisible to every one of them - and none of
 * them said so. `fieldset-actions-inline.php` printed
 * "PASS: no banned inline styles in inc/*.php" with a live violation sitting in
 * inc/sn-apply/executors.php.
 *
 * Fixing those twelve does not stop a thirteenth being written. This suite is
 * the part that lasts: it makes the SHAPE of the mistake fail, not its
 * instances.
 *
 * Run: php tests/inc-population-guard.php
 * @since 13.95.3
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

require_once __DIR__ . '/lib/inc-population.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );

echo "inc-population-guard - plugin v13.95.3\n\nGroup 1: the helper's own population\n";

$walked = snt_test_inc_files();
$top    = (array) glob( $root . '/inc/*.php' );
$pkgs   = snt_test_inc_packages();

ok( count( $walked ) > count( $top ),
	'the walk reaches BEYOND the top level - ' . count( $walked ) . ' walked vs ' . count( $top ) . ' at top level' );
ok( count( $pkgs ) >= 1, 'inc/ actually has packages to reach (' . implode( ', ', $pkgs ) . ')' );

// Depth, not size. A count alone cannot tell a complete set from a truncated
// one - the original defect passed every size check it had.
$reached = array();
foreach ( $walked as $f ) {
	$rel = ltrim( str_replace( $root . '/inc', '', $f ), '/' );
	if ( false !== strpos( $rel, '/' ) ) { $reached[ strtok( $rel, '/' ) ] = true; }
}
ksort( $reached );
ok( array_keys( $reached ) === $pkgs,
	'EVERY package is represented in the walk - reached [' . implode( ', ', array_keys( $reached ) ) . '] vs present [' . implode( ', ', $pkgs ) . ']' );

// The basename filter must not quietly become a path filter.
$abilities = snt_test_inc_files( 'abilities-*.php' );
ok( count( $abilities ) > 0 && count( $abilities ) < count( $walked ),
	'the basename pattern still narrows (' . count( $abilities ) . ' of ' . count( $walked ) . ')' );

echo "\nGroup 2: no suite builds its own inc/ population\n";

/**
 * The invariant: inc/ is enumerated in ONE place, snt_test_inc_files().
 *
 * The weaker rule - "a glob must look recursive" - was tried first and it
 * cleared analytics-filter-reference-parity.php (which merged inc/*.php with
 * inc/*\/*.php, correct only to depth 2) while also flagging it, and it had no
 * opinion at all about no-literal-unicode-escapes.php, which merged inc/*.php
 * with a HAND-LISTED inc/admin-forms/*.php. That list was right when written
 * and rotted as five more packages appeared: 71 files left the guard's reach
 * and its own "scanning > 100 files" check never moved.
 *
 * Judging each expression's SHAPE cannot catch a stale list. Removing the
 * choice can: one helper, reviewed once, used everywhere.
 */
$offenders = array();
foreach ( (array) glob( __DIR__ . '/*.php' ) as $suite ) {
	if ( basename( $suite ) === basename( __FILE__ ) ) { continue; }
	$src = (string) file_get_contents( $suite );
	if ( ! preg_match_all( '#glob\(\s*([^;]+?)\s*\)#', $src, $m ) ) { continue; }
	foreach ( $m[1] as $expr ) {
		// Matched on the RAW expression: the pattern is usually built from a
		// variable ($inc_dir . '/*.php'), and stripping the variable deletes
		// the very substring that identifies it as an inc/ scan. That mistake
		// produced a 9-of-12 under-report while #987 was being investigated.
		if ( false === strpos( $expr, 'inc' ) ) { continue; }
		if ( ! preg_match_all( '#[\'"]([^\'"]*)[\'"]#', $expr, $lit ) ) { continue; }
		$pat = implode( '', $lit[1] );
		// The damage class is the BROAD sweep - `inc/*.php` and its variants -
		// which claims to be "inc/" and is only its top level. A glob naming a
		// specific package (inc/sn-apply/*.php) is a deliberate scope and is
		// left alone; so is a family prefix (inc/sn-apply-*.php), which names
		// what it wants.
		//
		// SAID PLAINLY: this line therefore does NOT catch a future raw
		// `inc/abilities-*.php`. Every ability registration currently sits at
		// the top of inc/, so such a glob is complete today; if abilities ever
		// move into a package, Group 1's package-coverage assertion is what
		// notices, not this one.
		if ( '*.php' !== basename( $pat ) ) { continue; }
		if ( 'inc' !== basename( dirname( $pat ) ) && '' !== trim( dirname( $pat ), '/.' ) ) { continue; }
		$offenders[] = basename( $suite ) . ':  glob( ' . trim( $expr ) . ' )';
	}
}

ok( empty( $offenders ),
	'inc/ is enumerated only via snt_test_inc_files()' . ( $offenders
		? " - these build their own:\n    " . implode( "\n    ", $offenders )
		: ' (' . count( glob( __DIR__ . '/*.php' ) ) . ' suites scanned)' ) );

// The scanner must be able to SEE an offender, or the line above is decoration.
$probe = "<?php \$x = glob( \$inc_dir . '/*.php' );";
$saw   = false;
if ( preg_match_all( '#glob\(\s*([^;]+?)\s*\)#', $probe, $pm ) ) {
	foreach ( $pm[1] as $expr ) {
		if ( false === strpos( $expr, 'inc' ) ) { continue; }
		preg_match_all( '#[\'"]([^\'"]*)[\'"]#', $expr, $pl );
		$pp = implode( '', $pl[1] );
		if ( false !== strpos( $pp, '*' ) && false !== strpos( $pp, '.php' ) ) { $saw = true; }
	}
}
ok( $saw, 'the scanner DETECTS a known-bad expression - without this, "no offenders" could mean "the scanner is blind"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
