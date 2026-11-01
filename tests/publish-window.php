<?php
/**
 * Publish windows: inc/publish-window*.php and the dossier's "First hour"
 * (inc/note-dossier-publish-window.php), plus posts-signals' optional
 * `publish_window`.
 *
 * Pins: `_sn_published_at` is written on the first publish (scheduled and
 * manual), never on an update, kept on a republish, reset when the date
 * moves past it; the window is half-open (a hit at exactly the end is
 * after); window + after equals the total, lane by lane; only the note's
 * own path is read; the cache is bounded; a closed window is read once;
 * the tool defaults are unchanged.
 *
 * Run: php tests/publish-window.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['__hooks'] = array(); $GLOBALS['__opt'] = array(); $GLOBALS['__meta'] = array(); $GLOBALS['__q'] = array(); $GLOBALS['__answer'] = null; $GLOBALS['__setting'] = 60;
function add_action( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $h, $cb, $p ); }
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) {}
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
$GLOBALS['__capok'] = true; $GLOBALS['__trunc'] = false;
function get_transient( $k ) { return SNT_ANALYTICS_VDAY_CACHE_KEY === $k ? array( 'hashes' => array(), 'ok' => $GLOBALS['__capok'], 'truncated' => false ) : false; }
function sn_analytics_last_result_truncated( $set = null ) { return $GLOBALS['__trunc']; }
function set_transient( $k, $v, $e = 0 ) { return true; }
function dbDelta( $sql ) { return array(); }
function sn_analytics_query( $sql ) { $GLOBALS['__q'][] = $sql; return is_callable( $GLOBALS['__answer'] ) ? ( $GLOBALS['__answer'] )( $sql ) : $GLOBALS['__answer']; }
function sn_owner_agents() { return array( 'browser' ); }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['__meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['__meta'][ $id ][ $k ] = $v; return true; }
function sn_setting( $path, $d = null ) { return 'analytics.publish_window_minutes' === $path ? $GLOBALS['__setting'] : $d; }
function sn_analytics_post_path( $id ) { return array( 7 => '/notes/launch/', 8 => "/notes/x'y/" )[ $id ] ?? ''; }
function get_post_type( $id ) { return in_array( (int) $id, array( 7, 8 ), true ) ? 'post' : 'page'; }
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
class WP_Error { public $code; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; } }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public $post_date_gmt = ''; public function __construct( array $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
function get_post( $id ) { return in_array( (int) $id, array( 7, 9 ), true ) ? new WP_Post( array( 'ID' => (int) $id ) ) : null; }

require __DIR__ . '/lib/lanes-fake-wpdb.php';
require __DIR__ . '/../inc/analytics-human-rule.php';
require __DIR__ . '/../inc/analytics-bot-signals.php';
require __DIR__ . '/../inc/analytics-rollup.php';
require __DIR__ . '/../inc/analytics-lanes.php';
require __DIR__ . '/../inc/publish-window.php';
require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-publish-window.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
$post = static fn( $id, $date, $status = 'publish', $type = 'post' ) => new WP_Post( array( 'ID' => $id, 'post_date_gmt' => $date, 'post_status' => $status, 'post_type' => $type ) );
$meta = static fn( $id ) => $GLOBALS['__meta'][ $id ][ SN_PUBLISH_WINDOW_META ] ?? null;

echo "Group: _sn_published_at, written once at the real go-live\n";
ok( in_array( array( 'transition_post_status', 'sn_publish_window_on_transition', 10 ), $GLOBALS['__hooks'], true ) && in_array( array( 'post_updated', 'sn_publish_window_on_update', 10 ), $GLOBALS['__hooks'], true ), 'hooked on the status transition (wp_publish_post() fires it, save_post it does not) and on post_updated' );
$t0 = time();
sn_publish_window_on_transition( 'publish', 'draft', $post( 1, gmdate( 'Y-m-d H:i:s', $t0 ) ) );
ok( is_int( $meta( 1 ) ) && abs( $meta( 1 ) - $t0 ) <= 1, 'manual: draft to publish stamps time()' );
sn_publish_window_on_transition( 'publish', 'future', $post( 2, gmdate( 'Y-m-d H:i:s', $t0 - 600 ) ) );
ok( abs( $meta( 2 ) - $t0 ) <= 1, 'scheduled: future to publish stamps when WP-Cron ran it (here 10 minutes late), not the scheduled date' );
$GLOBALS['__meta'][3][ SN_PUBLISH_WINDOW_META ] = 1000;
sn_publish_window_on_transition( 'publish', 'publish', $post( 3, gmdate( 'Y-m-d H:i:s', $t0 ) ) );
ok( 1000 === $meta( 3 ), 'an update of a published note is not a transition: the stamp is untouched' );
sn_publish_window_on_update( 3, $post( 3, '2020-01-01 00:00:00' ), $post( 3, '2020-01-01 00:00:00' ) );
ok( 1000 === $meta( 3 ), '   ...and post_updated with the same date leaves it' );
$GLOBALS['__meta'][4][ SN_PUBLISH_WINDOW_META ] = $t0 - 86400;
sn_publish_window_on_transition( 'draft', 'publish', $post( 4, gmdate( 'Y-m-d H:i:s', $t0 - 86400 ), 'draft' ) );
sn_publish_window_on_transition( 'publish', 'draft', $post( 4, gmdate( 'Y-m-d H:i:s', $t0 - 86400 ) ) );
ok( $t0 - 86400 === $meta( 4 ), 'a republish with the date unchanged keeps the old window' );
sn_publish_window_on_transition( 'publish', 'future', $post( 4, gmdate( 'Y-m-d H:i:s', $t0 - 5 ) ) );
ok( abs( $meta( 4 ) - $t0 ) <= 1, 'a republish whose date was moved past the stamp is a relaunch: reset to time()' );
$GLOBALS['__meta'][5][ SN_PUBLISH_WINDOW_META ] = 5000;
sn_publish_window_on_update( 5, $post( 5, gmdate( 'Y-m-d H:i:s', 9000 ) ), $post( 5, gmdate( 'Y-m-d H:i:s', 4000 ) ) );
ok( 9000 === $meta( 5 ), 'a published note\'s date edited in place past the stamp: reset to the new date' );
sn_publish_window_on_update( 5, $post( 5, gmdate( 'Y-m-d H:i:s', 100 ) ), $post( 5, gmdate( 'Y-m-d H:i:s', 9000 ) ) );
ok( 9000 === $meta( 5 ), '   ...and moved earlier, kept' );
sn_publish_window_on_transition( 'publish', 'draft', $post( 6, gmdate( 'Y-m-d H:i:s', $t0 ), 'publish', 'page' ) );
ok( null === $meta( 6 ), 'a page is not a note: no stamp' );

echo "\nGroup: the window is half-open\n";
$p = 1792000000;
ok( null === sn_publish_window_part( $p - 1, $p, 60 ) && 'window' === sn_publish_window_part( $p, $p, 60 ), 'before go-live is nothing; go-live itself is in the window' );
ok( 'window' === sn_publish_window_part( $p + 3599, $p, 60 ) && 'after' === sn_publish_window_part( $p + 3600, $p, 60 ), 'a hit at exactly published_at + length is AFTER; one second earlier is in' );
$scope = sn_publish_window_scope( '/notes/launch/', $p, $p + 3600 );
ok( false !== strpos( $scope['where'], "timestamp >= toDateTime('" . gmdate( 'Y-m-d H:i:s', $p ) . "')" ) && "if(timestamp < toDateTime('" . gmdate( 'Y-m-d H:i:s', $p + 3600 ) . "'), 'window', 'after')" === $scope['part'], 'the SQL twin: >= the start, < the end is window, else after' );

echo "\nGroup: only that note's path is read\n";
ok( false !== strpos( $scope['where'], "blob2 IN ('/notes/launch/','/notes/launch')" ) && 1 === substr_count( $scope['where'], 'blob2' ), 'the read names the note\'s path and its slashless twin, nothing else' );
$sql = sn_lanes_public_sql( 'sn_pageviews_v2', '', 'UTC', $scope );
ok( false !== strpos( $sql, "blob2 IN ('/notes/launch/','/notes/launch')" ) && false !== strpos( $sql, ' AS part' ) && false !== strpos( $sql, 'GROUP BY day, vid, class, feed, part' ) && false === strpos( $sql, 'toStartOfInterval(now()' ), 'the lanes read takes the scope in place of the trailing days, and groups by part' );
ok( false !== strpos( sn_lanes_owner_sql( 'UTC', $scope ), "blob2 IN ('/notes/launch/','/notes/launch')" ) && false !== strpos( sn_lanes_owner_sql( 'UTC', $scope ), 'agent, part' ), 'the owner read too' );
ok( false === strpos( sn_lanes_public_sql( 'sn_pageviews_v2', '', 'UTC' ), 'part' ) && false === strpos( sn_lanes_owner_sql( 'UTC' ), 'part' ), 'with no scope the nightly reads are unchanged' );
ok( null === sn_publish_window_scope( "/notes/x'y/", $p, $p + 60 ) && '' === sn_publish_window_sources_sql( 'd', "/a') OR (1=1", $p, $p + 60 ), 'a path that is not a plain URL path is never interpolated' );
ok( false !== strpos( sn_publish_window_sources_sql( 'd', '/notes/x/', $p, $p + 60 ), sn_analytics_referrer_value_sql( 'blob3' ) . ' AS host' ), 'the note window\'s sources relabel an internal click, so Direct is not inflated (2026-11-01)' );

echo "\nGroup: window + after = total, lane by lane\n";
$H   = static fn( $c ) => str_repeat( $c, 16 );
$pub = array(
	array( 'day' => '2026-10-16', 'vid' => $H( 'a' ), 'class' => 'human', 'feed' => 0, 'views' => '3', 'part' => 'window' ),
	array( 'day' => '2026-10-16', 'vid' => $H( 'a' ), 'class' => 'human', 'feed' => 0, 'views' => '2', 'part' => 'after' ),
	array( 'day' => '2026-10-16', 'vid' => $H( 'b' ), 'class' => 'bot', 'feed' => 0, 'views' => '5', 'part' => 'window' ),
	array( 'day' => '2026-10-16', 'vid' => $H( 'c' ), 'class' => 'suspect', 'feed' => 0, 'views' => '1', 'part' => 'after' ),
	array( 'day' => '2026-10-17', 'vid' => $H( 'd' ), 'class' => 'human', 'feed' => 0, 'views' => '4', 'part' => 'after' ),
);
$own = array( array( 'day' => '2026-10-16', 'vid' => $H( 'e' ), 'identity' => 1, 'agent' => 0, 'views' => '6', 'part' => 'window' ), array( 'day' => '2026-10-16', 'vid' => $H( 'e' ), 'identity' => 1, 'agent' => 0, 'views' => '1', 'part' => 'after' ) );
$split = sn_publish_window_collapse( sn_lanes_beacon_derive( $pub, $own, array() ) );
$strip = static fn( array $rows ) => array_map( static function ( $r ) { unset( $r['part'] ); return $r; }, $rows );
$total = array();
foreach ( sn_lanes_beacon_derive( $strip( $pub ), $strip( $own ), array() ) as $day => $rows ) {
	foreach ( $rows as $r ) { $total[ $r['lane'] ] = ( $total[ $r['lane'] ] ?? 0 ) + $r['hits']; }
}
$sum = array();
foreach ( array( 'window', 'after' ) as $part ) {
	foreach ( $split[ $part ] as $lane => $c ) { $sum[ $lane ] = ( $sum[ $lane ] ?? 0 ) + $c['hits']; }
}
ksort( $total ); ksort( $sum );
ok( $total === $sum && array( 'human' => 9, 'owner' => 7, 'suspected_automation' => 6 ) === $total, 'per lane, window + after is the total the nightly derivation gives the same rows' );
ok( 3 === $split['window']['human']['hits'] && 6 === $split['after']['human']['hits'] && 6 === $split['window']['owner']['hits'], 'and each part keeps its own figures' );

echo "\nGroup: the report, read on demand, a closed window read once\n";
$GLOBALS['__answer'] = static function ( $sql ) use ( $pub ) {
	if ( false !== strpos( $sql, ' AS host, ' ) ) { return array( array( 'host' => 'news.ycombinator.com', 'readers' => 2 ) ); }
	return false !== strpos( $sql, SN_LANES_OWNER_DATASET ) ? array() : $pub;
};
ok( 'none' === sn_publish_window_report( 99 )['state'] && array() === $GLOBALS['__q'], 'no stamp: "none", and nothing is read' );
$GLOBALS['__meta'][7][ SN_PUBLISH_WINDOW_META ] = $t0 - 600;
$r = sn_publish_window_report( 7, $t0 );
ok( 'open' === $r['state'] && 50 === $r['minutes_left'] && 3 === $r['window']['human']['hits'] && ! isset( $GLOBALS['__opt'][ SN_PUBLISH_WINDOW_CACHE ] ), 'open: minutes left, the live split, nothing cached' );
$GLOBALS['__q'] = array();
$r = sn_publish_window_report( 7, $t0 + 3600 );
ok( 'closed' === $r['state'] && false === $r['cached'] && 2 === count( $GLOBALS['__q'] ) && isset( $GLOBALS['__opt'][ SN_PUBLISH_WINDOW_CACHE ][7] ) && false === $GLOBALS['__autoload'][ SN_PUBLISH_WINDOW_CACHE ], 'closed: read once (lanes and sources) and cached in a non-autoloaded option' );
$GLOBALS['__q'] = array();
$r = sn_publish_window_report( 7, $t0 + 7200 );
ok( true === $r['cached'] && 1 === count( $GLOBALS['__q'] ) && false !== strpos( $GLOBALS['__q'][0], "timestamp >= toDateTime('" . gmdate( 'Y-m-d H:i:s', $t0 - 600 + 3600 ) . "')" ), 'then only the after-window half is read again, from the window end' );
$GLOBALS['__setting'] = 30;
$GLOBALS['__q'] = array();
ok( false === sn_publish_window_report( 7, $t0 + 7200 )['cached'] && 2 === count( $GLOBALS['__q'] ), 'a changed length re-reads the window' );
$GLOBALS['__setting'] = 60;
$GLOBALS['__answer'] = null;
ok( 'unread' === sn_publish_window_report( 7, $t0 + 7200 )['state'], 'a failed read is "unread", never zeros, even with the window cached' );
$GLOBALS['__opt'] = array();
ok( 'unread' === sn_publish_window_report( 7, $t0 + 7200 )['state'], '   ...with no cache to fall back on, too' );

// Only the lanes read comes back truncated; the sources read after it is whole.
$GLOBALS['__answer'] = static function ( $sql ) { $GLOBALS['__trunc'] = false !== strpos( $sql, ' AS part' ); return array(); };
$r = sn_publish_window_report( 7, $t0 + 7200 );
ok( 'unread' === $r['state'] && 'truncated' === $r['why'] && null === $r['window'], 'a truncated answer is "unread" (truncated), never a short count' );
$GLOBALS['__trunc'] = false; $GLOBALS['__capok'] = false;
$r = sn_publish_window_report( 7, $t0 + 7200 );
ok( 'unread' === $r['state'] && 'overcap_unread' === $r['why'], 'an unread over-cap list is "unread" (overcap_unread): humans are never counted without the cap' );
$b = sn_note_dossier_publish_window( 7 );
ok( 'warning' === $b[0]['tone'] && false !== strpos( $b[0]['meta'], 'over-cap' ), '   ...and the dossier says why' );
$GLOBALS['__capok'] = true;

echo "\nGroup: the cache is bounded\n";
$now   = 1800000000;
$cache = array();
for ( $i = 1; $i <= 260; $i++ ) { $cache[ $i ] = array( 'published_at' => $now - $i * 3600 ); }
$cache[999] = array( 'published_at' => $now - 91 * 86400 );
$cache = sn_publish_window_cache_put( $cache, 0, array(), $now );
ok( ! isset( $cache[999] ) && SN_PUBLISH_WINDOW_CACHE_MAX === count( $cache ) && isset( $cache[1] ) && ! isset( $cache[260] ), 'older than Analytics Engine\'s 90 days goes, then the oldest past the cap; the newest stay' );

echo "\nGroup: the dossier's First hour leads with the human readers after it\n";
$GLOBALS['__opt'] = array();
$GLOBALS['__answer'] = static function ( $sql ) use ( $pub ) { return false !== strpos( $sql, ' AS host, ' ) ? array() : ( false !== strpos( $sql, SN_LANES_OWNER_DATASET ) ? array() : $pub ); };
$GLOBALS['__meta'][7][ SN_PUBLISH_WINDOW_META ] = time() - 600;
$b = sn_note_dossier_publish_window( 7 );
ok( 'First hour' === $b[0]['heading'] && 'Human after the window' === $b[0]['tiles'][0]['label'] && 'window open, 50 min left' === $b[1 - 1]['tiles'][1]['note'], 'the first tile is the after-window human figure; an open window says how long is left' );
ok( 'Page views by lane' === $b[1]['heading'] && 'table' === $b[1]['kind'], 'then the window and after, lane by lane' );
unset( $GLOBALS['__meta'][9] );
$b = sn_note_dossier_publish_window( 9 );
ok( 'status' === $b[0]['kind'] && 'Published before publish windows existed.' === $b[0]['text'], 'a note with no stamp says it was published before publish windows existed' );

echo "\nGroup: tool defaults are unchanged\n";
function sn_analytics_posts_signals() { return array( 'rows' => array(), 'counts' => array( 'n' => 0 ), 'strip' => array() ); }
require __DIR__ . '/../inc/abilities-posts-signals.php';
foreach ( array( 'null' => null, 'empty' => array() ) as $k => $in ) {
	ok( array( 'ok', 'rows', 'counts', 'strip', 'note' ) === array_keys( snt_ability_posts_signals( $in ) ), "posts-signals, input $k: exactly the keys it always had" );
}
$with = snt_ability_posts_signals( array( 'publish_window' => 7 ) );
ok( 7 === $with['publish_window']['post_id'] && isset( $with['publish_window']['window'], $with['publish_window']['after'] ), 'publish_window adds one note\'s split' );
ok( snt_ability_posts_signals( array( 'publish_window' => 9 ) ) instanceof WP_Error && snt_ability_posts_signals( array( 'publish_window' => 'x' ) ) instanceof WP_Error, '   ...and refuses anything that is not a note' );

echo "\nGroup: the length setting\n";
function sn_setting_update( $path, $v ) { if ( 'analytics.publish_window_minutes' === $path ) { $GLOBALS['__setting'] = $v; } return true; }
require __DIR__ . '/../inc/publish-window-settings.php';
$GLOBALS['__setting'] = 60;
ok( 'analytics_publish_window_unchanged' === sn_handle_analytics_publish_window_save( array( 'sn_publish_window_minutes' => '60' ) ), 'the same length is unchanged' );
ok( 'analytics_publish_window_saved' === sn_handle_analytics_publish_window_save( array( 'sn_publish_window_minutes' => '9999' ) ) && 1440 === $GLOBALS['__setting'], 'a length is clamped to 5 to 1440 and saved' );
ok( 'analytics_publish_window_saved' === sn_handle_analytics_publish_window_save( array( 'sn_publish_window_minutes' => array( 'x' ) ) ) && 60 === $GLOBALS['__setting'], 'a crafted array falls back to the default' );
ok( 30 === sn_publish_window_clamp( '30' ) && 5 === sn_publish_window_clamp( 1 ) && 60 === sn_publish_window_clamp( 'x' ), 'the clamp' );
$root = dirname( __DIR__ );
ok( false !== strpos( (string) file_get_contents( "$root/apps/sn-dashboard/parts/leaves/monitoring-analytics-setup.php" ), 'sn_publish_window_setup_fold()' ) && false !== strpos( (string) file_get_contents( "$root/inc/analytics-admin-settings-parts.php" ), "'snt_analytics_render_publish_window'" ), 'a native Setup fold and its classic twin, one handler' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
