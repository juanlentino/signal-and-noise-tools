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
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
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
require $root . '/inc/analytics-live-heat.php';
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

echo "\nGroup: the past 24 hours as a compact heat grid\n";
ok( 0 === sn_analytics_live_heat_step( 0, 10 ) && 1 === sn_analytics_live_heat_step( 1, 10 ) && 1 === sn_analytics_live_heat_step( 3, 10 ) && 3 === sn_analytics_live_heat_step( 10, 10 ), 'steps against a max of 10: 0 is empty, 1 and 3 the first step, 10 the top' );
ok( 2 === sn_analytics_live_heat_step( 4, 10 ) && 3 === sn_analytics_live_heat_step( 7, 10 ) && 1 === sn_analytics_live_heat_step( 1, 1 ) && 2 === sn_analytics_live_heat_step( 2, 2 ), '   ...the scale is the window\'s own max, with no more steps than readers' );
ok( '1-3 · 4-6 · 7-10' === implode( ' · ', array_column( sn_analytics_live_heat_steps( 10 ), 'label' ) ) && '1 · 2' === implode( ' · ', array_column( sn_analytics_live_heat_steps( 2 ), 'label' ) ), 'the legend names the real steps' );
$now0  = intdiv( time(), 3600 ) * 3600 + 1800 + 60; // 30 minutes and a bit into an hour
$cur0  = intdiv( $now0, 300 ) * 300;
$seed  = array();
for ( $t = intdiv( $now0, 3600 ) * 3600 - 23 * 3600; $t < $cur0; $t += 300 ) { $seed[ intdiv( $t, 300 ) * 300 ] = array( 'readers' => 0, 'classes' => array( 'human' => 0, 'suspect' => 0, 'bot' => 0 ), 'pages' => array(), 'sources' => array() ); }
$busy = $cur0 - 3 * 3600;
$seed[ $busy ]['readers'] = 8; $seed[ $busy - 300 ]['readers'] = 2; $seed[ $busy + 300 ]['readers'] = 8; $seed[ $cur0 - 600 ]['readers'] = 1;
unset( $seed[ $cur0 - 7200 ] ); // a gap: never recorded
$GLOBALS['o'][ SN_ANALYTICS_LIVE_SLOTS_OPT ] = $seed;
$heat = sn_analytics_live_slots_html( array( 'sn_view' => 'overview', 'sn_slot' => (string) $busy ), $now0 );
ok( 288 === preg_match_all( '/class="sn-live-admin__cell[ "]/', $heat ), '24 hours of 12 slots: 288 cells' );
ok( 1 === preg_match_all( '/class="sn-live-admin__cell is-missing"/', $heat ) && 6 <= preg_match_all( '/class="sn-live-admin__cell is-future"/', $heat ), 'a slot never recorded and a slot still to come are their own states, not a zero' );
$t8 = gmdate( 'H:i', $busy ); $t8e = gmdate( 'H:i', $busy + 300 );
ok( false !== strpos( $heat, 'aria-label="' . $t8 . ' to ' . $t8e . ', 8 human readers"' ) && false !== strpos( $heat, 'aria-label="' . gmdate( 'H:i', $cur0 - 600 ) . ' to ' . gmdate( 'H:i', $cur0 - 300 ) . ', 1 human reader"' ), 'a cell\'s accessible name is its time range and its count' );
ok( false !== strpos( $heat, 'data-hint="' . $t8 . ' to ' . $t8e . ', 8 human readers"' ) && 0 === preg_match( '/<a [^>]*\stitle=/', $heat ), 'hover and focus show the same text as a hint, with no native title' );
ok( 0 === preg_match( '/class="sn-live-admin__cell[^"]*"[^>]*>[^<]/', $heat ), 'no number is painted inside a cell' );
ok( false !== strpos( $heat, 'is-step-3' ) && false !== strpos( $heat, 'is-step-0' ), 'the color step is a class on the cell' );
ok( false !== strpos( $heat, 'sn-live-admin__legend' ) && false !== strpos( $heat, '>0<' ) && false !== strpos( $heat, '>1-2<' ) && false !== strpos( $heat, '>3-5<' ) && false !== strpos( $heat, '>6-8<' ) && false !== strpos( $heat, 'not recorded' ), 'the legend shows 0, the real steps and the unrecorded state' );
ok( false !== strpos( $heat, '>now<' ) && false !== strpos( $heat, '>' . gmdate( 'H', $now0 - 6 * 3600 ) . ':00<' ), 'the hour axis marks every 6 hours and now' );
ok( false !== strpos( $heat, 'Busiest 5-minute slot: ' . $t8 . ', 8 human readers; 4 non-empty slots in 24 hours' ), 'a summary names the busiest slot (the earliest on a tie) and the non-empty count' );
ok( 1 === preg_match( '/aria-describedby="([^"]+)"/', $heat, $m ) && false !== strpos( $heat, 'id="' . $m[1] . '"' ), '   ...and the grid is described by it' );
ok( 1 === substr_count( $heat, 'aria-current="true"' ) && 1 === preg_match( '/<a class="sn-live-admin__cell[^"]*is-on[^"]*" href="[^"]*sn_slot=' . $busy . '/', $heat ) && false !== strpos( $heat, $t8 . ' to ' . $t8e . '</h4>' ), 'choosing a slot still links to it, marks it and paints its detail' );
ok( false === strpos( $heat, '<script' ) && false === strpos( $heat, 'style="' ), 'no script and no inline style' );
ok( false !== strpos( sn_analytics_live_admin_html( array( 'sn_slot' => (string) $busy ) ), 'sn-live-admin__heat' ) && false !== strpos( (string) file_get_contents( $root . '/inc/analytics-view-overview-panels-parts.php' ), 'echo sn_analytics_live_admin_html()' ), 'both twins paint the same grid: the classic page and the native Overview call the one block' );
$css = (string) file_get_contents( $root . '/assets/analytics/live-admin.css' );
ok( 0 === preg_match( '/overflow(-x|-y)?\s*:\s*(auto|scroll)/', $css ), 'the grid never scrolls: no scrolling overflow in the stylesheet' );
ok( 1 === preg_match( '/\.sn-live-admin__heat\{[^}]*max-height:\s*1[0-4]\dpx/', $css ) && 1 === preg_match( '/\.sn-live-admin__heat\{[^}]*grid-auto-columns:\s*minmax\(0,/', $css ), '   ...its height is capped near 140px and its columns shrink to fit the panel' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
