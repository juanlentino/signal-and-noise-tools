<?php
/**
 * Tests: direct to contact, nothing sent (inc/analytics-contact-direct.php),
 * observe only. A (direct) human session that enters on a contact page, or
 * reaches one second after an entry view under 10 seconds, with no contact
 * form entry in its time, is `contact_direct`. Owner brief 2026-11-17, with
 * its Niigata, Yerevan and Vilnius cases.
 *
 * Run: php tests/analytics-contact-direct.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ALLTFO_ENTRY_TYPE', 'alltfo_entry' );
define( 'ALLTFO_STATUS_UNREAD', 'alltfo-unread' );
define( 'ALLTFO_STATUS_READ', 'alltfo-read' );
define( 'ALLTFO_STATUS_SPAM', 'alltfo-spam' );
define( 'ALLTFO_STATUS_PARTIAL', 'alltfo-partial' );
define( 'ALLTFO_STATUS_ARCHIVED', 'alltfo-archived' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function apply_filters( $t, $v ) { return $v; }
function __( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function sn_setting( $p, $d = null ) { return $d; }
function get_option( $k, $d = false ) { return $d; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function add_action( $h, $cb = null ) {}
function add_filter() {}
class WP_Error { public $message; public function __construct( $c = '', $m = '', $d = null ) { $this->message = $m; } public function get_error_message() { return $this->message; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function snt_analytics_is_ymd( $s ) { return is_string( $s ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ); }
function snt_arows_real_paths() { return array( '/' => true, '/contact' => true, '/about' => true ); }
// Form entries: AllTerrain Forms posts, answered from $GLOBALS['entries'] inside the asked window.
function get_posts( $args ) {
	$GLOBALS['posts_args'][] = $args;
	if ( null === $GLOBALS['entries'] ) { return null; }
	$lo = strtotime( $args['date_query'][0]['after'] . ' UTC' );
	$hi = strtotime( $args['date_query'][0]['before'] . ' UTC' );
	return array_values( array_filter( array_map( static fn( $t ) => (object) array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $t ) ), $GLOBALS['entries'] ), static fn( $p ) => strtotime( $p->post_date_gmt . ' UTC' ) >= $lo && strtotime( $p->post_date_gmt . ' UTC' ) <= $hi ) );
}
function sn_analytics_query( $sql ) {
	if ( false !== strpos( $sql, 'GROUP BY ts' ) ) { return $GLOBALS['bots']; }
	return false !== strpos( $sql, ' AS ref, ' ) ? $GLOBALS['ae'] : array();
}
function snt_an_kv_table( $title, $rows, $cols, $opts = array() ) { $GLOBALS['table'] = array( 'rows' => $rows, 'opts' => $opts ); }
function snt_an_note_empty( $title, $why = '' ) {}
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
$root = dirname( __DIR__ );
require $root . '/inc/analytics-sessions.php';
require $root . '/inc/analytics-derive.php';
require $root . '/inc/analytics-sources.php';
require $root . '/inc/link-codes.php';
require $root . '/inc/analytics-rows.php';
require $root . '/inc/analytics-rows-events.php';
require $root . '/inc/abilities-permission-helpers.php';
require $root . '/inc/analytics-sessions-list.php';
require $root . '/inc/abilities-analytics-sessions.php';
require $root . '/inc/analytics-view-sessions-list.php';

echo "\nGroup: the candidate\n";
$v = static fn( ...$pairs ) => $pairs;
ok( true === sn_analytics_contact_direct_candidate( $v( array( '/', 0 ), array( '/contact/personal', 3 ) ), '(direct)' ), 'Niigata: / for 3 seconds, then /contact/personal' );
ok( true === sn_analytics_contact_direct_candidate( $v( array( '/contact', 0 ) ), '(direct)' ) && true === sn_analytics_contact_direct_candidate( $v( array( '/contact/', 0 ) ), '(direct)' ), 'entry on /contact' );
ok( false === sn_analytics_contact_direct_candidate( $v( array( '/', 0 ), array( '/contact', 10 ) ), '(direct)' ), 'an entry view of 10 seconds is not under 10' );
ok( true === sn_analytics_contact_direct_candidate( $v( array( '/', 0 ), array( '/contact/personal', 25 ) ), '(direct)', 3139 ), 'Niigata as read live: 3.1 s measured on /, the next page view 25 s later' );
ok( false === sn_analytics_contact_direct_candidate( $v( array( '/', 0 ), array( '/contact', 3 ) ), '(direct)', 12000 ), 'a measured 12 s on the entry page is not straight on, whatever the gap' );
ok( false === sn_analytics_contact_direct_candidate( $v( array( '/', 0 ), array( '/about', 2 ), array( '/contact', 4 ) ), '(direct)' ), 'contact as the third page is not direct to contact' );
ok( false === sn_analytics_contact_direct_candidate( $v( array( '/contact', 0 ) ), 'LinkedIn' ) && false === sn_analytics_contact_direct_candidate( $v( array( '/contact', 0 ) ), '(internal)' ), 'a referrer present: never' );
ok( false === sn_analytics_contact_direct_candidate( $v( array( '/contacts-old', 0 ) ), '(direct)' ), 'only /contact and what is under it' );

echo "\nGroup: the owner's sessions, end to end\n";
$now = strtotime( '2026-11-17 17:00:00 UTC' ); // 12:00 in New York.
sn_analytics_clock( $now );
$ev  = static fn( $vid, $ts, $type, $path, $more = array() ) => array_merge( array( 'vid' => $vid, 'ts' => $ts, 'ev' => $type, 'path' => $path, 'ref' => '', 'ce' => '', 'scroll' => 0, 'dwell' => 0, 'device' => 'desktop', 'country' => 'XX', 'region' => '', 'city' => '', 'si' => 1 ), $more );
$yer = strtotime( '2026-11-17 14:06:52 UTC' ); // 09:06:52 ET
$vil = strtotime( '2026-11-17 15:07:00 UTC' ); // 10:07 ET
$ref = strtotime( '2026-11-17 15:30:00 UTC' );
$snt = strtotime( '2026-11-17 16:00:00 UTC' );
$GLOBALS['ae'] = array(
	$ev( 'yer', $yer, 'pv', '/contact/' ), $ev( 'yer', $yer + 610, 'tm', '/contact/', array( 'dwell' => 9000 ) ), // to 09:17:02
	$ev( 'vil', $vil, 'pv', '/contact/' ), $ev( 'vil', $vil + 40, 'sc', '/contact/', array( 'scroll' => 50 ) ),
	$ev( 'ref', $ref, 'pv', '/contact/', array( 'ref' => 'www.linkedin.com' ) ),     // a referrer
	$ev( 'snt', $snt, 'pv', '/contact/' ), $ev( 'snt', $snt + 90, 'tm', '/contact/', array( 'dwell' => 80000 ) ), // sent the form
);
$GLOBALS['bots']    = array( array( 'ts' => strtotime( '2026-11-17 14:09:20 UTC' ), 'n' => 1 ) ); // Ashburn, 09:09:20 ET
$GLOBALS['entries'] = array( $snt + 100 ); // an entry 10 seconds after the last beacon
$all = snt_ability_analytics_sessions( array() );
$by  = array();
foreach ( $all['sessions'] as $s ) { $by[ substr( $s['start'], 11, 5 ) ] = $s; }
ok( true === $by['09:06']['contact_direct'] && true === $by['09:06']['paired'], 'Yerevan, entry /contact, 09:06:52 to 09:17:02 ET: direct to contact, and paired with the Ashburn read' );
ok( true === $by['10:07']['contact_direct'] && false === $by['10:07']['paired'], 'Vilnius, entry /contact at 10:07 ET, no automated hit near: contact_direct true, paired false' );
ok( false === $by['10:30']['contact_direct'], 'a referrer present: false' );
ok( false === $by['11:00']['contact_direct'], 'a form entry in the session\'s time: something was sent, false' );
$q = end( $GLOBALS['posts_args'] );
ok( 'alltfo_entry' === $q['post_type'] && array( 'alltfo-unread', 'alltfo-read', 'alltfo-spam', 'alltfo-archived' ) === $q['post_status'] && 'post_date_gmt' === $q['date_query'][0]['column'], 'entries in the inbox, read, spam or archived (a partial save was not sent), by their time only' );
ok( 2 === $all['flags_by_day'][0]['contact_direct'], 'flags_by_day counts them per day' );

echo "\nGroup: Niigata, across the midnight\n";
sn_analytics_clock( strtotime( '2026-11-17 05:00:00 UTC' ) );
$nii = strtotime( '2026-11-17 03:45:43 UTC' ); // 22:45:43 ET on the 16th
$GLOBALS['ae'] = array( $ev( 'nii', $nii, 'pv', '/' ), $ev( 'nii', $nii + 3, 'pv', '/contact/personal/' ), $ev( 'nii', $nii + 287, 'tm', '/contact/personal/', array( 'dwell' => 5000 ) ) );
$GLOBALS['bots'] = array( array( 'ts' => strtotime( '2026-11-17 03:50:44 UTC' ), 'n' => 1 ) );
$n = snt_ability_analytics_sessions( array( 'from' => '2026-11-16' ) )['sessions'][0];
ok( true === $n['contact_direct'] && true === $n['paired'], 'Niigata: / for 3 seconds then /contact/personal, nothing sent: direct to contact, and paired' );

echo "\nGroup: not read, and other classes\n";
sn_analytics_clock( $now );
$GLOBALS['ae'] = array( $ev( 'vil', $vil, 'pv', '/contact/' ), $ev( 'vil', $vil + 40, 'sc', '/contact/' ), $ev( 'x', $vil + 50, 'pv', '/about/' ) );
$GLOBALS['entries'] = null;
$down = snt_ability_analytics_sessions( array() );
$dby  = array_column( $down['sessions'], 'contact_direct', 'entry' );
ok( null === $dby['/contact'] && false === $dby['/about'], 'entries not read: a candidate is null, a non-candidate stays false' );
ok( null === $down['flags_by_day'][0]['contact_direct'], 'and its per-day count is null, never 0' );
ok( null === snt_ability_analytics_sessions( array( 'class' => 'suspect' ) )['sessions'][0]['contact_direct'], 'other classes: null' );

echo "\nGroup: the Sessions tab\n";
$GLOBALS['entries'] = array();
snt_analytics_render_session_list( '2026-11-17', '2026-11-17', 'human' );
$cells = array(); foreach ( $GLOBALS['table']['rows'] as $r ) { $cells[ strtok( $r[1], ' ' ) ] = $r[4]; }
ok( '(direct) · direct to contact, nothing sent' === $cells['/contact'] && '(direct)' === $cells['/about'], 'the marker, in words, beside the source' );
$GLOBALS['entries'] = null;
snt_analytics_render_session_list( '2026-11-17', '2026-11-17', 'human' );
ok( false !== strpos( $GLOBALS['table']['opts']['header_meta'], 'contact form entries not read' ), 'when entries were not read, the header says so' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
