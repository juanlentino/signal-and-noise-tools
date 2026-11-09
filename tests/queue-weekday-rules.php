<?php
/**
 * The SN Queue's weekday rules (2026-11-09, queue calendar phase 2, item 4):
 * the governing rule, the off-day warnings, their place among the cadence
 * warnings, suggested slots kept to the rule, the save path and the seed.
 * The fixture is the live queue read 2026-11-09 (28 notes, Nov 13, 2026 to
 * Mar 4, 2027; from Jan 4 every note on a Monday or Thursday).
 *
 * Run: php tests/queue-weekday-rules.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__o'] = array(); $GLOBALS['__a'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__o'] ) ? $GLOBALS['__o'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__o'][ $k ] = $v; return true; }
function add_option( $k, $v, $d = '', $a = null ) { if ( array_key_exists( $k, $GLOBALS['__o'] ) ) { return false; } $GLOBALS['__o'][ $k ] = $v; return true; }
function add_action( $t, $cb = null ) { $GLOBALS['__a'][ $t ][] = $cb; return true; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
require __DIR__ . '/../inc/abilities-content-queue.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$NY   = wp_timezone();
$at   = static fn( $s ) => ( new DateTimeImmutable( $s, $NY ) )->getTimestamp();
$day  = static fn( $s ) => new DateTimeImmutable( $s, $NY );
$NOW  = $at( '2026-11-09 14:00' );
$LIVE = array_map( $at, array( '2026-11-13 10:47', '2026-11-17 11:37', '2026-11-21 14:22', '2026-11-25 13:18', '2026-11-29 15:10', '2026-12-03 11:26', '2026-12-07 16:40', '2026-12-11 15:41', '2026-12-15 15:17', '2026-12-19 12:07', '2027-01-04 10:52', '2027-01-07 14:06', '2027-01-11 11:23', '2027-01-14 15:37', '2027-01-18 10:14', '2027-01-21 13:48', '2027-01-25 09:41', '2027-01-28 16:02', '2027-02-01 11:29', '2027-02-04 14:55', '2027-02-08 10:07', '2027-02-11 13:19', '2027-02-15 09:58', '2027-02-18 15:24', '2027-02-22 11:46', '2027-02-25 14:33', '2027-03-01 10:21', '2027-03-04 16:11' ) );
$OWNER = array( '2027-01-04||14' );
$HOLS  = array( '2026-12-20|2027-01-03' );

echo "The governing rule\n";
ok( array( 'from' => '2027-01-04', 'to' => '', 'days' => array( 1, 4 ) ) === sn_content_queue_weekday_rule_for( '2027-01-11', $OWNER ), 'a day after an open-ended rule starts is governed by it' );
ok( null === sn_content_queue_weekday_rule_for( '2026-12-19', $OWNER ), 'a day before the rule starts is free' );
$feb = array( '2027-01-04||14', '2027-02-01|2027-02-28|1' );
ok( ! sn_content_queue_weekday_allows( $day( '2027-02-04' ), $feb ) && sn_content_queue_weekday_allows( $day( '2027-02-08' ), $feb ), 'where two rules cover a day, the later start wins (Feb: Mondays only)' );
ok( sn_content_queue_weekday_allows( $day( '2027-03-04' ), $feb ), 'a bounded rule ends on its last day; the open rule governs again' );
ok( null === sn_content_queue_weekday_rule_for( '2027-01-11', array( 'junk', '2027-01-04|x|14', '2027-01-04||8', '2027-01-04||' ) ), 'a malformed rule governs nothing' );
ok( sn_content_queue_weekday_allows( $day( '2027-01-12' ), array() ) && ! sn_content_queue_weekday_allows( $day( '2027-01-12' ), $OWNER ), 'no rule allows every day; the owner rule leaves Tuesday out' );

echo "\nOff days\n";
ok( array() === sn_content_queue_offdays( $LIVE, $NOW, $NY, $OWNER ), 'the live queue keeps the owner rule: no off day' );
$moved     = $LIVE;
$moved[11] = $at( '2027-01-12 14:06' ); // Thu Jan 7 moved to Tue Jan 12.
$off       = sn_content_queue_offdays( $moved, $NOW, $NY, $OWNER );
ok( 1 === count( $off ) && 'offday' === $off[0]['kind'] && '2027-01-12' === $off[0]['from'] && 1 === $off[0]['count'], 'a note moved to a Tuesday is one off day' );
ok( 'Off day: a note on Tue, Jan 12, 2027 (from Jan 4, 2027, notes go out on Mon and Thu)' === ( $off[0]['text'] ?? '' ), 'the line names the day and the rule in words, its word first' );
$two = sn_content_queue_offdays( array( $at( '2027-01-16 09:00' ), $at( '2027-01-16 17:00' ) ), $NOW, $NY, $OWNER );
ok( 1 === count( $two ) && 'Off day: 2 notes on Sat, Jan 16, 2027 (from Jan 4, 2027, notes go out on Mon and Thu)' === $two[0]['text'], 'two notes on one off day are one line' );
ok( array() === sn_content_queue_offdays( array( $at( '2027-01-11 22:00' ) ), $NOW, $NY, $OWNER ), 'the day is read in the site timezone (Mon 10 PM in New York is Tue in UTC)' );
ok( array() === sn_content_queue_offdays( $moved, $NOW, $NY, array() ), 'no rule, no off day' );

echo "\nAmong the cadence warnings\n";
ok( array() === sn_content_queue_cadence( $LIVE, null, $NOW, $NY, $HOLS, $OWNER ), 'the live queue, holidays planned, owner rule: nothing to say' );
$cad = sn_content_queue_cadence( $moved, null, $NOW, $NY, $HOLS, $OWNER );
ok( array( 'cluster', 'offday' ) === array_column( $cad, 'kind' ) && array( '2027-01-11', '2027-01-12' ) === array_column( $cad, 'from' ), 'an off day sits in date order beside the cluster it makes' );
ok( array( 'cluster' ) === array_column( sn_content_queue_cadence( $moved, null, $NOW, $NY, $HOLS ), 'kind' ), 'without rules the warnings are exactly what they were' );

echo "\nSuggested slots keep to the rule\n";
$draft = array( array( 'id' => 1, 'title' => 'A', 'edit_url' => '' ), array( 'id' => 2, 'title' => 'B', 'edit_url' => '' ), array( 'id' => 3, 'title' => 'C', 'edit_url' => '' ) );
$s     = sn_content_queue_slots( $draft, $LIVE, null, $NOW, $NY, $HOLS, $OWNER );
ok( array( '2027-03-08', '2027-03-11', '2027-03-15' ) === array_column( $s, 'day' ), 'three drafts after Thu Mar 4 take Mon, Thu, Mon: the rhythm the rule sets' );
ok( false !== strpos( $s[1]['reason'], 'moved to the nearest day the weekday rule allows' ), 'a slot pulled back to the allowed day says why' );
$free = sn_content_queue_slots( $draft, $LIVE, null, $NOW, $NY, $HOLS );
ok( array( '2027-03-08', '2027-03-12', '2027-03-16' ) === array_column( $free, 'day' ), 'without rules the slots are exactly what they were' );
$gap = sn_content_queue_slots( array( $draft[0] ), array( $at( '2027-02-01 10:00' ), $at( '2027-02-15 10:00' ) ), null, $NOW, $NY, array(), $OWNER );
ok( '2027-02-08' === $gap[0]['day'], 'a gap split already on an allowed day stays there' );
// Mon Feb 1 to Sat Feb 13: the even cut is Sun Feb 7, left out; the nearest allowed day is Mon Feb 8.
$cut = sn_content_queue_slots( array( $draft[0] ), array( $at( '2027-02-01 10:00' ), $at( '2027-02-13 10:00' ) ), null, $NOW, $NY, array(), $OWNER );
ok( '2027-02-08' === $cut[0]['day'], 'a gap split whose even cut falls on a left-out day takes the nearest allowed one' );
$walk = sn_content_queue_slots( array( $draft[0] ), $LIVE, null, $NOW, $NY, array_merge( $HOLS, array( '2027-03-08|2027-03-08' ) ), $OWNER );
ok( '2027-03-11' === $walk[0]['day'], 'the walk past a planned day skips the days the rule leaves out (Tue and Wed) to Thu' );

echo "\nSaving\n";
ok( 'queue_weekday_saved' === sn_content_queue_weekday_save( '2027-01-04', '', array( 4, 1, 4 ) ) && array( '2027-01-04||14' ) === sn_content_queue_weekday_rules(), 'a rule is stored as from|to|days, days sorted and unique' );
ok( 'queue_weekday_saved' === sn_content_queue_weekday_save( '2027-01-04', '2027-06-30', array( 1 ) ) && array( '2027-01-04|2027-06-30|1' ) === sn_content_queue_weekday_rules(), 'the same start replaces its rule' );
foreach ( array( array( '2027-02-30', '', array( 1 ) ), array( '2027-01-04', '2027-01-01', array( 1 ) ), array( "2027-01-04\n", '', array( 1 ) ), array( '2027-05-05', '', array() ) ) as $bad ) {
	ok( 'queue_weekday_invalid' === sn_content_queue_weekday_save( ...$bad ) && array( '2027-01-04|2027-06-30|1' ) === sn_content_queue_weekday_rules(), 'refused, nothing changed: ' . json_encode( $bad ) );
}
ok( 'queue_weekday_removed' === sn_handle_queue_weekday_save( array( 'from' => '2027-01-04', 'to' => '' ) ) && array() === sn_content_queue_weekday_rules(), 'the form with no weekday checked removes the rule that starts that day' );
ok( 'queue_weekday_saved' === sn_handle_queue_weekday_save( array( 'from' => ' 2027-01-04 ', 'to' => '', 'wd_1' => '1', 'wd_4' => '1', 'wd_9' => '1' ) ) && array( '2027-01-04||14' ) === sn_content_queue_weekday_rules(), 'the form reads wd_1 to wd_7 only' );
for ( $i = 1; $i <= 25; $i++ ) {
	sn_content_queue_weekday_save( sprintf( '2028-01-%02d', $i ), '', array( 1 ) );
}
ok( SN_CONTENT_QUEUE_WEEKDAY_MAX === count( sn_content_queue_weekday_rules() ), 'at most 20 rules are kept' );

echo "\nThe seed\n";
$GLOBALS['__o'] = array();
foreach ( $GLOBALS['__a']['admin_init'] as $cb ) { $cb(); }
ok( array( '2027-01-04||14' ) === sn_content_queue_weekday_rules(), 'the owner rule is seeded once when nothing is stored' );
$GLOBALS['__o'][ SN_CONTENT_QUEUE_WEEKDAY_OPT ] = array();
foreach ( $GLOBALS['__a']['admin_init'] as $cb ) { $cb(); }
ok( array() === sn_content_queue_weekday_rules(), 'a list the owner emptied stays empty' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
