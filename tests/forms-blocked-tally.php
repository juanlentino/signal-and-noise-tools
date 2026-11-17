<?php
/**
 * The contact form's blocked-attempt tally (inc/forms-blocked-tally.php):
 * each verdict reason maps to one whitelisted guard, a day keeps a count per
 * guard, days past 90 are dropped, and the late filter counts only spam and
 * returns the verdict untouched. Counts only: nothing from the submission.
 * Run: php tests/forms-blocked-tally.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/tmp/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; $c ? ++$pass : ++$fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; }
$GLOBALS['o'] = array(); $GLOBALS['f'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['o'][ $k ] = $v; return true; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['f'][ $h ] = array( $cb, $p ); }
function wp_date( $f ) { return '2026-11-17'; }
require __DIR__ . '/../inc/forms-blocked-tally.php';

echo "Guard map\n";
$map = array( 'honeypot' => 'honeypot', 'too_fast' => 'time_trap', 'rate_limit' => 'rate_limit', 'blocklist' => 'rule', 'snt:lure,pitch' => 'rule', 'challenge' => 'challenge', 'akismet' => 'akismet', 'Someone <a@b.c>' => 'other', '' => 'other' );
foreach ( $map as $reason => $guard ) {
	ok( $guard === snt_fs_tally_guard( $reason ), "'{$reason}' counts under {$guard}" );
}

echo "Tally\n";
$t = snt_fs_tally_add( array(), 'too_fast', '2026-11-17' );
$t = snt_fs_tally_add( $t, 'too_fast', '2026-11-17' );
$t = snt_fs_tally_add( $t, 'snt:bot_name', '2026-11-17' );
ok( array( '2026-11-17' => array( 'time_trap' => 2, 'rule' => 1 ) ) === $t, 'counts per guard per day' );
$old = array( '2026-08-19' => array( 'honeypot' => 1 ), '2026-08-20' => array( 'honeypot' => 4 ) );
$t2  = snt_fs_tally_add( $old, 'honeypot', '2026-11-17' );
ok( ! isset( $t2['2026-08-19'] ) && isset( $t2['2026-08-20'] ), 'kept 90 days: the 91st day back is dropped, the 90th kept' );
ok( $old === snt_fs_tally_add( $old, 'honeypot', 'not-a-day' ), 'a malformed day changes nothing' );
$t3 = snt_fs_tally_add( array(), 'mailto:x@y.z', '2026-11-17' );
ok( array( 'other' ) === array_keys( $t3['2026-11-17'] ), 'a raw reason never becomes a key' );
$rows = snt_fs_tally_rows( $t2 );
ok( '2026-11-17' === $rows[0]['day'] && 1 === $rows[0]['honeypot'] && 0 === $rows[0]['akismet'] && 8 === count( $rows[0] ), 'rows: newest first, every guard present, zero filled' );

echo "Filter\n";
list( $cb, $prio ) = $GLOBALS['f']['alltfo_spam_verdict'];
ok( $prio > 10, 'runs after the content rules (priority 10)' );
$GLOBALS['o'] = array();
$clean = array( 'spam' => false, 'reason' => '' );
ok( $clean === $cb( $clean ) && array() === $GLOBALS['o'], 'a clean verdict is returned untouched and counts nothing' );
$spam = array( 'spam' => true, 'reason' => 'rate_limit' );
ok( $spam === $cb( $spam ), 'a spam verdict is returned untouched' );
ok( 1 === $GLOBALS['o'][ SNT_FS_TALLY_OPT ]['2026-11-17']['rate_limit'], 'a spam verdict adds one under its guard on the site day' );
ok( 'x' === $cb( 'x' ), 'a non-array verdict passes through' );

echo "\n{$pass} passed, {$fail} failed.\n";
exit( $fail ? 1 : 0 );
