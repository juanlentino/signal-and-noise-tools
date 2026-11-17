<?php
/**
 * Tests: the sessions read and the Sessions tab carry agent pairing and the
 * sampled page-view figure (owner brief 2026-11-17). `paired` on each session
 * and `paired_by_day` beside them (inc/analytics-pairing.php); `pageviews_stored`
 * and `pageviews_est` on each session, read "about N, from M stored" on the
 * tab when Analytics Engine sampled the visitor-day.
 *
 * Run: php tests/analytics-sessions-paired.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function apply_filters( $t, $v ) { return $v; }
function __( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function sn_setting( $p, $d = null ) { return $d; }
function get_option( $k, $d = false ) { return $d; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function add_action( $h, $cb = null ) {}
function add_filter() {}
class WP_Error { public $message; public function __construct( $c = '', $m = '', $d = null ) { $this->message = $m; } public function get_error_message() { return $this->message; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function snt_analytics_is_ymd( $s ) { return is_string( $s ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ); }
function snt_arows_real_paths() { return array( '/' => true, '/notes/x' => true, '/about' => true ); }
// Analytics Engine: session rows for the session read, bot times for the pairing read.
function sn_analytics_query( $sql ) {
	$GLOBALS['q'][] = $sql;
	if ( false !== strpos( $sql, 'GROUP BY ts' ) ) { return $GLOBALS['bots']; }
	return false !== strpos( $sql, ' AS ref, ' ) ? $GLOBALS['ae'] : array();
}
function snt_an_kv_table( $title, $rows, $cols, $opts = array() ) { $GLOBALS['table'] = array( 'rows' => $rows, 'cols' => $cols, 'opts' => $opts ); }
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
require $root . '/inc/analytics-realtime.php';

$now = strtotime( '2026-11-17 18:00:00 UTC' ); // 13:00 in New York.
sn_analytics_clock( $now );
$ev = static fn( $vid, $ts, $type, $path, $more = array() ) => array_merge( array( 'vid' => $vid, 'ts' => $ts, 'ev' => $type, 'path' => $path, 'ref' => '', 'ce' => '', 'scroll' => 0, 'dwell' => 0, 'device' => 'desktop', 'country' => 'AM', 'region' => 'Yerevan', 'city' => 'Yerevan', 'si' => 1 ), $more );
$yer = strtotime( '2026-11-17 14:06:52 UTC' ); // 09:06:52 ET.
$GLOBALS['ae'] = array(
	// Yerevan, (direct), 09:06:52 to 09:17:02; its visitor-day was sampled: two kept page views stand for six.
	$ev( 'v-yer', $yer, 'pv', '/notes/x/', array( 'si' => 3 ) ),
	$ev( 'v-yer', $yer + 300, 'pv', '/about/', array( 'si' => 3 ) ),
	$ev( 'v-yer', $yer + 610, 'tm', '/about/', array( 'dwell' => 9000, 'si' => 3 ) ),
	// A referred session at the same time, and a (direct) one an hour later: neither paired.
	$ev( 'v-ref', $yer + 100, 'pv', '/about/', array( 'ref' => 'www.linkedin.com' ) ),
	$ev( 'v-late', $yer + 3600, 'pv', '/' ),
);
$GLOBALS['bots'] = array( array( 'ts' => strtotime( '2026-11-17 14:09:20 UTC' ), 'n' => 1 ) ); // Ashburn, 09:09:20 ET.

echo "\nGroup: the sessions read\n";
$all = snt_ability_analytics_sessions( array() );
$by  = array_column( $all['sessions'], null, 'entry' );
ok( true === $by['/notes/x']['paired'], 'the Yerevan (direct) session with an Ashburn read inside it is paired' );
ok( false === $by['/about']['paired'] && false === $by['/']['paired'], 'a referred session at the same time is not; a (direct) one an hour later is not' );
ok( array( array( 'day' => '2026-11-17', 'sessions' => 3, 'direct' => 2, 'paired' => 1 ) ) === $all['paired_by_day'], 'paired_by_day counts the window\'s human sessions per site day' );
$one = snt_ability_analytics_sessions( array( 'per_page' => 1 ) );
ok( array( array( 'day' => '2026-11-17', 'sessions' => 3, 'direct' => 2, 'paired' => 1 ) ) === $one['paired_by_day'], 'paired_by_day covers every session in the window, not only the page' );
ok( 2 === $by['/notes/x']['pageviews'] && 2 === $by['/notes/x']['pageviews_stored'] && 6 === $by['/notes/x']['pageviews_est'], 'a sampled session: pageviews stays the stored rows, pageviews_est the views they stand for' );
ok( 1 === $by['/']['pageviews_stored'] && 1 === $by['/']['pageviews_est'], 'an unsampled session: the two agree' );
$session_sql = (string) current( array_filter( $GLOBALS['q'], static fn( $q ) => false !== strpos( $q, ' AS ref, ' ) ) );
ok( false !== strpos( $session_sql, '_sample_interval AS si' ), 'the session read selects the sample interval' );
$schema = snt_analytics_sessions_output_schema();
ok( array_keys( $schema['properties']['sessions']['items']['properties'] ) === array_keys( $by['/notes/x'] ) && array_keys( $schema['properties'] ) === array_keys( $all ), 'every field is in the schema, in order' );
ok( array( 'boolean', 'null' ) === $schema['properties']['sessions']['items']['properties']['paired']['type'], 'paired may be null: not read, never false' );
$GLOBALS['bots'] = null;
$down = snt_ability_analytics_sessions( array() );
ok( null === $down['sessions'][0]['paired'] && null === $down['paired_by_day'], 'a failed pairing read: paired and paired_by_day are null' );
$GLOBALS['bots'] = array( array( 'ts' => strtotime( '2026-11-17 14:09:20 UTC' ), 'n' => 1 ) );
$bot = snt_ability_analytics_sessions( array( 'class' => 'bot' ) );
ok( null === $bot['sessions'][0]['paired'] && null === $bot['paired_by_day'], 'other classes: null' );
$desc = snt_analytics_sessions_description();
ok( false !== strpos( $desc, 'joined by time only, never by visitor code, place or network' ) && false !== strpos( $desc, 'about pageviews_est, from pageviews_stored stored' ), 'the description says how pairing joins and how to read an estimate' );

echo "\nGroup: the Sessions tab\n";
snt_analytics_render_session_list( '2026-11-17', '2026-11-17', 'human' );
$yrow = current( array_filter( $GLOBALS['table']['rows'], static fn( $r ) => 0 === strpos( $r[1], '/notes/x' ) ) );
ok( 'about 6, from 2 stored' === $yrow[2], 'a sampled session\'s page views read "about N, from M stored"' );
ok( '(direct) · near an automated hit' === $yrow[4], 'a paired session carries the marker in words beside its source' );
ok( 'Page views' === $GLOBALS['table']['cols'][2]['label'], 'the column is named' );
$plain = current( array_filter( $GLOBALS['table']['rows'], static fn( $r ) => 0 === strpos( $r[1], '/ ' ) || '/' === $r[1] ) );
ok( '1' === $plain[2] && '(direct)' === $plain[4], 'an unsampled, unpaired session: the plain count and no marker' );
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'views_today' => 31, 'views_today_stored' => 20 );
ok( false !== strpos( snt_analytics_session_list_sampled_meta( '2026-11-17' ), 'page views about 31, from 20 stored' ), 'the sampled-today note uses the same wording' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
