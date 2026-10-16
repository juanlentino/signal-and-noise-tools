<?php
/**
 * Tests for the rolling live snapshots (inc/analytics-live-slots*.php) and
 * `signal-noise/live-now`'s `hours_back` (inc/abilities-live-now.php).
 *
 * A completed slot is stored with its class split, pages and sources, read
 * back for 24 hours and gone after; the partial slot never is; a refresh
 * inside the same 5 minutes reads nothing; a failed read stores nothing; no
 * identifier from the rows reaches the store; live-now with no argument is
 * exactly the live payload; `hours_back` adds the slots; the past-slot grid
 * links each slot and paints the chosen one.
 *
 * Run: php tests/analytics-live-slots.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );

function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {}
$GLOBALS['t'] = array(); $GLOBALS['o'] = array(); $GLOBALS['q'] = array(); $GLOBALS['answer'] = null;
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['o'][ $k ] = $v; $GLOBALS['autoload'][ $k ] = $a; return true; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function sn_analytics_config() { return array( 'account_id' => 'a' ); }
function sn_analytics_query( $sql ) { $GLOBALS['q'][] = $sql; return is_callable( $GLOBALS['answer'] ) ? ( $GLOBALS['answer'] )( $sql ) : $GLOBALS['answer']; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function url_to_postid( $u ) { return '/notes/alpha/' === substr( $u, 14 ) ? 1 : 0; }
function get_post_status( $id ) { return 'publish'; }
function post_password_required( $id ) { return false; }
function get_post_field( $f, $id ) { return ''; }
function get_post_type( $id ) { return 'post'; }
function sn_post_settings_get_noindex( $id ) { return false; }
function get_the_title( $id ) { return 'Alpha'; }
function get_permalink( $id ) { return 'https://x.test/notes/alpha/'; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function snt_analytics_page_url( $args = array() ) { return 'https://x.test/wp-admin/admin.php?page=sn-analytics&' . http_build_query( $args ); }
class WP_Error { public $code; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; } }
$root = dirname( __DIR__ );
require $root . '/inc/analytics-realtime.php';
require $root . '/inc/analytics-live-pages.php';
require $root . '/inc/analytics-live-hour.php';
require $root . '/inc/analytics-sources.php';
require $root . '/inc/analytics-live-sources.php';
require $root . '/inc/analytics-live-slots.php';
require $root . '/inc/analytics-live-slots-admin.php';
require $root . '/inc/analytics-live-admin.php';
require $root . '/inc/analytics-live.php';
require $root . '/inc/abilities-live-now.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// The over-cap list is warm, so no read here is the cap's own.
$GLOBALS['t'][ SNT_ANALYTICS_VDAY_CACHE_KEY ] = array( 'hashes' => array(), 'ok' => true, 'truncated' => false );
$now  = time();
$cur  = intdiv( $now, 300 ) * 300;
$hour = array();
for ( $i = 11; $i >= 0; $i-- ) { $hour[] = array( 't' => $cur - $i * 300, 'readers' => 0 ); }
$hour[10]['readers'] = 2; // the last completed slot
$hour[11]['readers'] = 9; // the current, partial slot
$last = $cur - 300;
// Rows as Analytics Engine answers them, with identifiers a careless store would keep.
$rows = array(
	'class'  => array( array( 'slot' => (string) $last, 'class' => 'human', 'readers' => 2, 'index1' => 'vid-SECRET-1' ), array( 'slot' => (string) $last, 'class' => 'bot', 'readers' => 7 ), array( 'slot' => (string) $cur, 'class' => 'human', 'readers' => 9 ) ),
	'path'   => array( array( 'slot' => (string) $last, 'path' => '/notes/alpha/?utm=SECRET-QS', 'readers' => 2, 'ua' => 'Mozilla SECRET-UA' ), array( 'slot' => (string) $last, 'path' => '/draft-SECRET/', 'readers' => 4 ) ),
	'host'   => array( array( 'slot' => (string) $last, 'host' => 'www.google.com', 'readers' => 1, 'ip' => '203.0.113.9' ), array( 'slot' => (string) $last, 'host' => '', 'readers' => 1 ) ),
);
$respond = static function ( $sql ) use ( $rows ) {
	foreach ( array( 'AS class' => 'class', 'blob2 AS path' => 'path', 'blob3 AS host' => 'host' ) as $needle => $k ) {
		if ( false !== strpos( $sql, $needle ) ) { return $rows[ $k ]; }
	}
	return array();
};

echo "Group: the queries, per 5-minute slot over the last hour\n";
foreach ( array( 'classes' => sn_analytics_live_slot_classes_sql(), 'pages' => sn_analytics_live_slot_pages_sql(), 'sources' => sn_analytics_live_slot_sources_sql() ) as $k => $sql ) {
	ok( false !== strpos( $sql, "toStartOfInterval(timestamp, INTERVAL '5' MINUTE)) AS slot" ) && false !== strpos( $sql, "INTERVAL '60' MINUTE" ) && false !== strpos( $sql, 'GROUP BY slot,' ), "$k: grouped by the slot alias over the last 60 minutes" );
}
ok( false === strpos( sn_analytics_live_slot_classes_sql(), "blob7 = 'bot') AND" ) && false !== strpos( sn_analytics_live_slot_classes_sql(), 'AS class' ), 'classes: every class, as Reading now counts them' );
ok( false !== strpos( sn_analytics_live_slot_pages_sql(), "blob2 ILIKE '/wp-admin'" ) && false !== strpos( sn_analytics_live_slot_sources_sql(), "blob1 = 'pv'" ), 'pages and sources keep their live rules: admin paths out, sources from page views' );

echo "\nGroup: a completed slot is stored, the partial one never\n";
$GLOBALS['answer'] = $respond;
ok( true === sn_analytics_live_slots_step( $hour ) && 3 === count( $GLOBALS['q'] ), 'a new completed slot: three reads, one write' );
$store = $GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ];
ok( false === $GLOBALS['autoload'][ SN_ANALYTICS_LIVE_SLOTS_OPT ], 'the option is not autoloaded' );
ok( 11 === count( $store ) && ! isset( $store[ $cur ] ), 'the eleven completed slots are stored; the current one is not' );
ok( array( 'human' => 2, 'suspect' => 0, 'bot' => 7 ) === $store[ $last ]['classes'] && 2 === $store[ $last ]['readers'], 'a slot keeps its class split and the hour\'s own readers' );
ok( array( array( 'label' => 'Alpha', 'url' => 'https://x.test/notes/alpha/', 'readers' => 2 ) ) === $store[ $last ]['pages'], 'pages: the live panel\'s rules (query folded, a path that is not a public page dropped)' );
ok( array( 'Direct', 'Google' ) === array_column( $store[ $last ]['sources'], 'label' ), 'sources: the dashboard\'s source names' );
ok( array( 'human' => 0, 'suspect' => 0, 'bot' => 0 ) === $store[ $cur - 600 ]['classes'] && array() === $store[ $cur - 600 ]['pages'], 'a quiet completed slot is a measured zero, not missing' );
$GLOBALS['q'] = array();
ok( false === sn_analytics_live_slots_step( $hour ) && array() === $GLOBALS['q'], 'a refresh inside the same 5 minutes reads nothing' );

echo "\nGroup: no identifier reaches the store\n";
$blob = serialize( $store );
foreach ( array( 'SECRET', '203.0.113.9', 'index1', 'Mozilla', 'utm=' ) as $needle ) {
	ok( false === strpos( $blob, $needle ), "the stored slots never contain \"$needle\"" );
}
ok( array( 'classes', 'pages', 'readers', 'sources' ) === ( static function ( $k ) { sort( $k ); return $k; } )( array_keys( $store[ $last ] ) ), 'a slot holds exactly readers, classes, pages and sources' );

echo "\nGroup: a failed read stores nothing\n";
$GLOBALS['o'] = array(); $GLOBALS['q'] = array();
$GLOBALS['answer'] = static function ( $sql ) use ( $respond ) { return false !== strpos( $sql, 'blob3 AS host' ) ? null : $respond( $sql ); };
ok( false === sn_analytics_live_slots_step( $hour ) && ! isset( $GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ] ), 'one failed read: no write, the next refresh tries again' );
ok( false === sn_analytics_live_slots_step( null ), 'an hour that was not read: nothing to store' );

echo "\nGroup: kept 24 hours, then gone\n";
$GLOBALS['answer'] = $respond;
$GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ] = array( $cur - 86400 - 600 => array( 'readers' => 5 ), $cur - 86400 + 600 => array( 'readers' => 4 ) );
sn_analytics_live_slots_step( $hour );
$store = $GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ];
ok( ! isset( $store[ $cur - 86400 - 600 ] ) && isset( $store[ $cur - 86400 + 600 ] ), 'a write prunes what is older than a day and keeps the rest' );
ok( 4 === sn_analytics_live_slots( 24 )[0]['readers'], 'a slot under a day old is readable' );
$aged = sn_analytics_live_slots_window( $store, 24, $cur + 86400 );
ok( array() === array_filter( $aged, static fn( $s ) => $s['t'] < $cur ), 'a day later every one of them is gone on read, even before a write prunes it' );
ok( 1 === count( sn_analytics_live_slots_window( array( $cur - 7300 => array(), $cur - 3500 => array() ), 1, $cur ) ), 'hours_back 1 is the last hour only' );

echo "\nGroup: a slot is stored once, and expires at rest\n";
$GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ] = array( $last => array( 'readers' => 2, 'classes' => array( 'human' => 2, 'suspect' => 0, 'bot' => 99 ), 'pages' => array(), 'sources' => array() ) );
sn_analytics_live_slots_step( $hour );
ok( 99 === $GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ][ $last ]['classes']['bot'] && isset( $GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ][ $cur - 600 ] ), 'a slot already stored is never overwritten by a later read; the new ones are added' );
$GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ] = array( time() - 86400 - 300 => array( 'readers' => 1 ), time() - 600 => array( 'readers' => 3 ) );
$read = sn_analytics_live_slots( 24 );
ok( 1 === count( $read ) && 1 === count( $GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ] ) && 3 === reset( $GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ] )['readers'], 'a read deletes a slot past the day from the option, not only hides it, even with no refresh since' );
sn_analytics_live_slots_step( $hour );

echo "\nGroup: live-now with no argument is exactly the live answer\n";
$GLOBALS['t'][ SN_ANALYTICS_REALTIME_KEY ] = array( 'counts' => array( 'human' => 3, 'bot' => 1 ), 'views_today' => 4, 'fetched' => 99, 'pages' => array(), 'hour' => $hour, 'sources' => array(), 'surge' => null );
$plain = sn_analytics_live_payload( true );
foreach ( array( 'null' => null, 'empty' => array() ) as $k => $in ) {
	ok( json_encode( $plain ) === json_encode( snt_ability_live_now( $in ) ), "input $k: byte-identical to the live payload, no slots key" );
}
$with = snt_ability_live_now( array( 'hours_back' => 24 ) );
ok( isset( $with['slots'] ) && 11 <= count( $with['slots'] ) && array( 't', 'readers', 'classes', 'pages', 'sources' ) === array_keys( $with['slots'][ count( $with['slots'] ) - 1 ] ), 'hours_back adds the stored slots, each { t, readers, classes, pages, sources }' );
$rest = $with;
unset( $rest['slots'] );
ok( json_encode( $plain ) === json_encode( $rest ), '   ...and everything else is the live answer, unchanged' );
$one = snt_ability_live_now( array( 'hours_back' => '1' ) );
ok( is_array( $one ) && is_array( $one['slots'] ) && array() === array_filter( $one['slots'], static fn( $s ) => $s['t'] < time() - 3600 ), 'hours_back as the string a GET delivers is accepted, and 1 is the last hour' );
foreach ( array( 0, 25, 2.5, 'x' ) as $bad ) {
	ok( snt_ability_live_now( array( 'hours_back' => $bad ) ) instanceof WP_Error, 'hours_back ' . var_export( $bad, true ) . ' is refused' );
}
$src = (string) file_get_contents( $root . '/inc/abilities-live-now.php' );
ok( 1 === preg_match( "/'hours_back' => array\( 'type' => 'integer', 'minimum' => 1, 'maximum' => 24/", $src ) && false === strpos( $src, "'required'" ), 'the input schema adds one optional property, hours_back, 1 to 24' );

echo "\nGroup: the past 24 hours under Right now\n";
$get  = array( 'sn_view' => 'overview', 'sn_range' => '30', 'sn_class' => 'bot', 'sn_slot' => (string) $last );
$html = sn_analytics_live_slots_html( $get );
ok( false !== strpos( $html, 'sn_slot=' . $last ) && false !== strpos( $html, 'sn_range=30' ) && false !== strpos( $html, 'sn_class=bot' ), 'each stored slot is a link naming its slot and carrying the view params' );
ok( 1 === substr_count( $html, 'aria-current="true"' ) && false !== strpos( $html, 'Human 2 · Suspect 0 · Bot 7' ) && false !== strpos( $html, '>Alpha</a>' ), 'the chosen slot paints its class split, pages and sources' );
ok( false === strpos( $html, '<script' ) && false === strpos( $html, ' on' . 'click' ), 'no script: a link is the whole control' );
ok( false !== strpos( sn_analytics_live_slots_html( array( 'sn_slot' => (string) ( $cur - 86400 * 3 ) ) ), 'not stored' ), 'a slot past the day says so' );
ok( 0 === sn_analytics_live_slot_param( '1791489901' ) && 0 === sn_analytics_live_slot_param( 'x' ) && 1791489900 === sn_analytics_live_slot_param( '1791489900' ), 'sn_slot is a slot start on a 5-minute boundary, or nothing' );
$GLOBALS['o'] = array();
ok( false !== strpos( sn_analytics_live_slots_html( array() ), 'No slots stored yet' ), 'an empty store says so' );
ok( false !== strpos( sn_analytics_live_admin_html( $get ), 'sn-live-admin__past' ), 'the admin Right now block carries the grid' );
ok( false !== strpos( (string) file_get_contents( $root . '/apps/sn-analytics/parts/painters/view-overview.php' ), "sn_analytics_live_admin_html( (array) ( \$ctx['get']" ), 'the native Overview hands the block its window state' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/analytics-realtime.php' ), 'sn_analytics_live_slots_step( $hour )' ), 'the realtime refresh runs the step on the hour it read' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
