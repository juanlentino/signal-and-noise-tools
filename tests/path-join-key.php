<?php
/**
 * Phase 0 of the measurement weave: the cross-instrument path JOIN KEY.
 *
 * The proposal's instruction is the shape of this suite: "Pin the empty string,
 * a bare notes/foo, a full https://host/notes/foo/, a query string, an anchor,
 * a trailing double slash, and the homepage. NEGATIVE-CONTROL IT: feed a
 * deliberately mis-spelled path and watch the join count go red. A join test
 * that passes against an unfixed normalizer is worse than none."
 *
 * @since plugin v13.55.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/path-join-key.php';

echo "Group: the seven shapes the proposal names\n";
ok( '' === sn_path_join_key( '' ), 'the EMPTY string yields an EMPTY key — never "/", because folding unusable input onto the homepage inflates the busiest path with rows that named no page' );
ok( '/' === sn_path_join_key( '/' ), 'the homepage is "/" and stays "/"' );
ok( '/notes/foo' === sn_path_join_key( 'notes/foo' ), 'a BARE path gains its leading slash' );
ok( '/notes/foo' === sn_path_join_key( 'https://juanlentino.com/notes/foo/' ), 'a FULL URL reduces to its path' );
ok( '/notes/foo' === sn_path_join_key( '/notes/foo?utm_source=x&y=2' ), 'a QUERY STRING is dropped — it does not identify a page' );
ok( '/notes/foo' === sn_path_join_key( '/notes/foo#top' ), 'an ANCHOR is dropped' );
ok( '/notes/foo' === sn_path_join_key( '/notes/foo//' ), 'a TRAILING DOUBLE SLASH collapses' );

echo "\nGroup: the four live spellings now agree through this key\n";
// Measured 2026-09-01: these five inputs are where analytics/agent/redirects
// disagreed. Each disagreement was a silently dropped join row.
foreach ( array(
	'notes/foo',
	'https://juanlentino.com/notes/foo/',
	'/notes/foo?utm=x',
	'/notes/foo#top',
	'/notes/foo/',
) as $variant ) {
	ok( '/notes/foo' === sn_path_join_key( $variant ), "all spellings of the same page collapse to one key: \"$variant\"" );
}

echo "\nGroup: empty and homepage are DIFFERENT answers\n";
ok( sn_path_join_key( '' ) !== sn_path_join_key( '/' ), 'unjoinable ("") and the homepage ("/") are never the same key' );
ok( '' === sn_path_join_key( '   ' ), 'whitespace-only is unjoinable' );
ok( '' === sn_path_join_key( null ), 'a null is unjoinable rather than a PHP notice' );
ok( '/' === sn_path_join_key( '//' ), 'slashes alone ARE the homepage, not unjoinable' );
ok( '/' === sn_path_join_key( 'https://juanlentino.com' ), 'a bare host with no path is the homepage' );
ok( '/' === sn_path_join_key( 'https://juanlentino.com/' ), 'and so is a bare host with a root path' );

echo "\nGroup: shapes that must not be guessed at\n";
ok( '/notes/a b' === sn_path_join_key( '/notes/a b' ), 'a space is preserved — decoding is the caller\'s business, not the key\'s' );
ok( '/NOTES/Foo' === sn_path_join_key( '/NOTES/Foo' ), 'case is PRESERVED: paths are case-sensitive, and lowercasing would merge two real pages into one key' );
ok( '/notes/foo' === sn_path_join_key( '//juanlentino.com/notes/foo' ), 'a protocol-relative URL reduces to its path' );

echo "\nGroup: THE NEGATIVE CONTROL — a mis-spelled path must go red\n";
// "A join test that passes against an unfixed normalizer is worse than none."
$ae  = array( '/notes/foo/' => 120, '/notes/bar' => 40, '/' => 900 );
$gsc = array( 'https://juanlentino.com/notes/foo' => 12, '/notes/bar?utm=x' => 3, '/' => 88 );
$r   = sn_path_join( $ae, $gsc );
ok( 3 === count( $r['joined'] ), 'the join matches all three pages ACROSS four different spellings' );
ok( 120 === $r['joined']['/notes/foo']['left'] && 12 === $r['joined']['/notes/foo']['right'], 'and carries both sides of each pair' );

// Now mis-spell one, exactly as the proposal instructs.
$gsc_typo = array( 'https://juanlentino.com/notes/fooo' => 12, '/notes/bar?utm=x' => 3, '/' => 88 );
$r2 = sn_path_join( $ae, $gsc_typo );
ok( 2 === count( $r2['joined'] ), 'a MIS-SPELLED path drops the join count from 3 to 2 — the control fires' );
ok( in_array( '/notes/foo', $r2['left_only'], true ), 'and the unmatched side is NAMED, so a dropped row cannot pass as absence' );
ok( in_array( '/notes/fooo', $r2['right_only'], true ), 'from both directions' );

echo "\nGroup: unjoinable rows are counted, never folded onto the homepage\n";
$with_junk = array( '' => 5, '   ' => 6, '/notes/foo' => 7 );
$r3 = sn_path_join( $with_junk, array( '/notes/foo' => 1, '/' => 999 ) );
ok( 2 === $r3['left_unjoinable'], 'unjoinable rows are COUNTED' );
ok( 1 === count( $r3['joined'] ), 'and excluded from the join' );
ok( ! array_key_exists( '/', $r3['joined'] ), 'the homepage is NOT credited with them — that is the inflation this rule prevents' );

echo "\nGroup: the key is pure\n";
ok( sn_path_join_key( '/notes/foo' ) === sn_path_join_key( '/notes/foo' ), 'same input, same key' );
$src = (string) file_get_contents( __DIR__ . '/../inc/path-join-key.php' );
ok( false === strpos( $src, 'get_option' ) && false === strpos( $src, 'home_url' ), 'no site config is read — a key computed in a test is byte-identical to one computed in production' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
