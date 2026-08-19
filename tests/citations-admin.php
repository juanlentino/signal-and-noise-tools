<?php
/**
 * Standalone tests for the Citations readout — the three-way tally.
 * @since plugin v11.27.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '2 hours'; } }

require __DIR__ . '/../inc/citations-core.php';
require __DIR__ . '/../inc/citations-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "citation graph — readout — v11.27.0\n\n";

// ── the empty case: a measured zero, said as one ────────────────────────────
$zero = array_fill_keys( SN_CIT_TIERS, 0 );
$zero['never_checked'] = 0;
$s = sn_cit_summary_sentence( $zero );
ok( false !== stripos( $s, 'No citations recorded' ), 'an empty graph says so plainly' );
ok( false !== stripos( $s, 'measured zero' ), 'and states it is a MEASURED zero, not an unread inbox' );

// ── the tally names every bucket, always ────────────────────────────────────
$c = array( 'verified' => 2, 'unattributed' => 1, 'asserted' => 1, 'unverified' => 3, 'never_checked' => 3 );
$s = sn_cit_summary_sentence( $c );
ok( false !== strpos( $s, '7 claims' ), 'the total counts every tier, including the ones a fraction would hide' );
foreach ( SN_CIT_TIERS as $t ) {
	ok( false !== strpos( $s, $t ), "the readout names the $t tier explicitly" );
}
ok( false !== strpos( $s, '3 have never been checked' ), 'never-checked is called out separately, in its own sentence' );

// A tier at zero must still be PRINTED — a tier missing from a readout is
// indistinguishable from a tier nobody measured. This is the v11.13.0 lesson.
$c2 = array( 'verified' => 4, 'unattributed' => 0, 'asserted' => 0, 'unverified' => 0, 'never_checked' => 0 );
$s2 = sn_cit_summary_sentence( $c2 );
ok( false !== strpos( $s2, '0 asserted' ), 'a tier at zero is printed as 0, not omitted' );
ok( false !== strpos( $s2, '4 claims' ), 'the total still accounts for the whole table' );
ok( false === stripos( $s2, 'never been checked' ), 'with nothing unchecked, no unchecked sentence is added' );

// singular/plural
$c3 = array( 'verified' => 0, 'unattributed' => 0, 'asserted' => 0, 'unverified' => 1, 'never_checked' => 1 );
ok( false !== strpos( sn_cit_summary_sentence( $c3 ), '1 has never been checked' ), 'one unchecked row reads in the singular' );

// a missing key must not fatal or invent a number
$partial = array( 'verified' => 2 );
ok( false !== strpos( sn_cit_summary_sentence( $partial ), '0 asserted' ), 'an absent key reads as 0 rather than exploding' );

// ── never vs a date ─────────────────────────────────────────────────────────
ok( sn_cit_last_checked_label( null ) === 'never', 'NULL renders as "never"' );
ok( sn_cit_last_checked_label( '' ) === 'never', 'an empty value renders as "never" too' );
ok( sn_cit_last_checked_label( '2026-08-19 10:00:00' ) === '2 hours ago', 'a real timestamp renders as elapsed time' );
ok( sn_cit_last_checked_label( null ) !== sn_cit_last_checked_label( '2026-08-19 10:00:00' ), 'never and measured can never render the same' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
