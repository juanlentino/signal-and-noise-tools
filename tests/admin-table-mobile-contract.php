<?php
/**
 * Tests: no table claims core's list-table responsive contract without meeting it.
 *
 * WordPress core, wp-admin/css/list-tables.css, at max-width 782px:
 *
 *     .wp-list-table tr { display: flex; flex-wrap: wrap; }
 *     .wp-list-table td.column-primary,
 *     .wp-list-table th.column-primary { flex: 1 1 0; }
 *     .wp-list-table tr td:nth-child(n+3) { flex: 0 1 100%; }
 *
 * Every row becomes a FLEX CONTAINER whose sizing depends on `column-primary`
 * and `check-column`. A table that wears `wp-list-table` without emitting those
 * gets the flex layout and none of the sizing, and the result on a phone is the
 * header painting on top of the first cell — "Path" over "/notes" renders as
 * `Paothes`. Observed 2026-09-04 in the OpenStation PWA, which made phone-width
 * wp-admin routine rather than rare.
 *
 * Eight of our sixteen tables were in that state. They are not list tables — no
 * bulk actions, no check column, no row actions — so they keep `widefat striped`
 * (the visual) and drop `wp-list-table` (the contract).
 *
 * Run: php tests/admin-table-mobile-contract.php
 * @since 13.96.4
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/lib/inc-population.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * Source with comments blanked, LINE NUMBERS PRESERVED.
 *
 * Load-bearing: six of the fourteen first-pass hits were docblocks describing
 * ".wp-list-table chrome". Counting prose as markup would have had us edit
 * comments, and the two counting methods only reconciled once comments were
 * stripped.
 */
function atmc_code( $raw ) {
	$out = '';
	foreach ( token_get_all( $raw ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$out .= str_repeat( "\n", substr_count( $t[1], "\n" ) );
			continue;
		}
		$out .= is_array( $t ) ? $t[1] : $t;
	}
	return $out;
}

echo "admin-table-mobile-contract — plugin v13.96.4\n\nGroup 1: the scan is not vacuous\n";

$files = snt_test_inc_files();
ok( count( $files ) > 400, 'inc/ is walked at any depth (' . count( $files ) . ' files)' );

$probe = atmc_code( "<?php\n/* a comment about wp-list-table chrome */\n\$x = 1;\n" );
ok( false === strpos( $probe, 'wp-list-table' ), 'CONTROL: a wp-list-table mentioned only in a COMMENT is not markup' );
$probe2 = atmc_code( "<?php echo '<table class=\"wp-list-table\">';" );
ok( false !== strpos( $probe2, 'wp-list-table' ), 'CONTROL: one in CODE survives stripping' );

echo "\nGroup 2: every wp-list-table meets the contract\n";
$violations = array();
$compliant  = 0;
$seen       = 0;
foreach ( $files as $file ) {
	$raw = (string) file_get_contents( $file );
	if ( false === strpos( $raw, 'wp-list-table' ) ) { continue; }
	$lines = explode( "\n", atmc_code( $raw ) );
	foreach ( $lines as $n => $line ) {
		if ( false === strpos( $line, 'wp-list-table' ) ) { continue; }
		$seen++;
		// The header row is emitted close to the opener in every table here.
		$ctx = implode( "\n", array_slice( $lines, $n, 60 ) );
		if ( false !== strpos( $ctx, 'column-primary' ) ) { $compliant++; continue; }
		$violations[] = str_replace( dirname( __DIR__ ) . '/', '', $file ) . ':' . ( $n + 1 );
	}
}
ok( $seen > 0, 'the scan found wp-list-table markup to check (' . $seen . ' occurrence(s))' );
ok( array() === $violations,
	'no table wears wp-list-table without column-primary' . ( $violations ? " —\n    " . implode( "\n    ", $violations ) : ' (' . $compliant . ' compliant)' ) );

echo "\nGroup 3: the fixed tables kept their looks\n";
// widefat/striped is the visual; wp-list-table is the responsive contract. The
// eight tables that dropped the contract must not have lost the styling too.
foreach ( array(
	'inc/analytics-panels.php', 'inc/analytics-render-quality.php', 'inc/cloudflare-purge.php',
	'inc/provenance-admin.php', 'inc/schedule-admin.php', 'inc/tag-consolidation-admin.php',
) as $rel ) {
	$src = (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	ok( false !== strpos( $src, 'widefat striped' ), basename( $rel ) . ' still uses widefat striped' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
