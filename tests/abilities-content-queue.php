<?php
/**
 * Standalone fixture tests for inc/abilities-content-queue.php (15.8.0):
 * the signal-noise/content-queue readonly ability behind the SN Queue widget.
 *
 * Run: php tests/abilities-content-queue.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_VERSION', 'test' );

// ── WP stubs ────────────────────────────────────────────────────────────────
$GLOBALS['__cq_actions'] = array();
function add_action( $tag, $cb = null ) { $GLOBALS['__cq_actions'][ $tag ][] = $cb; return true; }
$GLOBALS['__cq_abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__cq_abilities'][ $slug ] = $args; return true; }
function current_user_can( $cap ) { return 'edit_posts' === $cap; }
$GLOBALS['__cq_posts'] = array(); // keyed "status:order"
$GLOBALS['__cq_queries'] = array();
function get_posts( $args ) {
	$GLOBALS['__cq_queries'][] = $args;
	return $GLOBALS['__cq_posts'][ $args['post_status'] . ':' . $args['order'] ] ?? array();
}
function get_the_title( $p ) { return $p->post_title; }
function get_edit_post_link( $p, $ctx = 'display' ) { return 'https://x.test/wp-admin/post.php?post=' . $p->ID . '&action=edit'; }
function get_post_time( $f, $gmt, $p ) { return 0; }
$GLOBALS['__cq_counts'] = (object) array( 'future' => 26, 'publish' => 60 );
function wp_count_posts( $t ) { return $GLOBALS['__cq_counts']; }
function wp_timezone() { return new DateTimeZone( 'Europe/Madrid' ); }
class WP_Post { public $ID; public $post_title; public $post_date_gmt; public $post_date; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }

require __DIR__ . '/../inc/abilities-content-queue.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$TZ  = new DateTimeZone( 'UTC' );
$NOW = gmmktime( 12, 0, 0, 9, 17, 2026 ); // Thu 2026-09-17 12:00Z

echo "abilities-content-queue — 15.8.0\n\nGroup A: the scheduled-time label (site timezone)\n";
ok( 'Today 21:38' === sn_content_queue_when( $NOW + 9 * 3600 + 38 * 60, $NOW, $TZ ), 'later today → "Today HH:MM"' );
ok( 'Tomorrow 09:38' === sn_content_queue_when( gmmktime( 9, 38, 0, 9, 18, 2026 ), $NOW, $TZ ), 'tomorrow → "Tomorrow HH:MM"' );
ok( 'Sun 11:38' === sn_content_queue_when( gmmktime( 11, 38, 0, 9, 20, 2026 ), $NOW, $TZ ), 'within the week → weekday + time' );
ok( 'Sep 26' === sn_content_queue_when( gmmktime( 11, 20, 0, 9, 26, 2026 ), $NOW, $TZ ), 'beyond the week, same year → "Mon D"' );
ok( 'Dec 27' === sn_content_queue_when( gmmktime( 18, 26, 0, 12, 27, 2026 ), $NOW, $TZ ), 'the end of the real queue → "Dec 27"' );
ok( 'Jan 3, 2027' === sn_content_queue_when( gmmktime( 10, 0, 0, 1, 3, 2027 ), $NOW, $TZ ), 'next year carries the year' );
ok( 'Overdue' === sn_content_queue_when( $NOW - 60, $NOW, $TZ ), 'a scheduled time in the past is "Overdue" (cron has not fired)' );
// The timezone is the site's, not UTC: 23:30Z on the 17th is 01:30 on the 18th in Madrid (CEST).
$madrid = new DateTimeZone( 'Europe/Madrid' );
ok( 'Tomorrow 01:30' === sn_content_queue_when( gmmktime( 23, 30, 0, 9, 17, 2026 ), $NOW, $madrid ), 'the day boundary is the site timezone\'s, not UTC\'s' );

echo "\nGroup B: the published age\n";
ok( '1 minute ago' === sn_content_queue_ago( $NOW - 30, $NOW ), 'under a minute floors at 1 minute' );
ok( '45 minutes ago' === sn_content_queue_ago( $NOW - 45 * 60, $NOW ), 'minutes' );
ok( '1 hour ago' === sn_content_queue_ago( $NOW - 3600, $NOW ), 'one hour, singular' );
ok( '5 hours ago' === sn_content_queue_ago( $NOW - 5 * 3600, $NOW ), 'hours' );
ok( '3 days ago' === sn_content_queue_ago( $NOW - 3 * 86400, $NOW ), 'days' );
ok( '1 minute ago' === sn_content_queue_ago( $NOW + 999, $NOW ), 'a future stamp (clock skew) never goes negative' );

echo "\nGroup C: the shaper is pure and defensive\n";
$future = array();
foreach ( array( 1, 3, 5, 9, 13, 101 ) as $i => $d ) {
	$future[] = array( 'id' => 100 + $i, 'title' => "Note in $d days", 'ts' => $NOW + $d * 86400, 'edit_url' => "https://x.test/e/$i" );
}
$published = array(
	array( 'id' => 1, 'title' => 'Yesterday', 'ts' => $NOW - 86400, 'edit_url' => 'https://x.test/e/1' ),
	array( 'id' => 2, 'title' => '', 'ts' => $NOW - 3 * 86400, 'edit_url' => '' ),
	array( 'id' => 3, 'title' => 'Older', 'ts' => $NOW - 7 * 86400, 'edit_url' => 'https://x.test/e/3' ),
	array( 'id' => 4, 'title' => 'Too old for the card', 'ts' => $NOW - 9 * 86400, 'edit_url' => 'https://x.test/e/4' ),
);
$out = sn_content_queue_shape( $future, 26, $published, $NOW, $TZ );
ok( array( 'next', 'scheduled_total', 'runs_to', 'published' ) === array_keys( $out ), 'the payload carries exactly the four keys' );
ok( 4 === count( $out['next'] ), 'next is capped at four (the headline plus three)' );
ok( 'Note in 1 days' === $out['next'][0]['title'] && 'Tomorrow 12:00' === $out['next'][0]['when'], 'the first row is the soonest, labelled' );
ok( 26 === $out['scheduled_total'], 'the total is the site-wide count, not the row count' );
ok( is_array( $out['runs_to'] ) && 'Dec 27' === $out['runs_to']['label'], 'runs_to is the LAST future row, the furthest scheduled (101 days → Dec 27)' );
ok( 3 === count( $out['published'] ), 'published is capped at three' );
ok( '(untitled)' === $out['published'][1]['title'], 'an empty title paints as (untitled), never a blank row' );
ok( '' === $out['published'][1]['edit_url'], 'a missing link stays empty (the widget paints plain text)' );
ok( '1 day ago' === $out['published'][0]['when'] && '7 days ago' === $out['published'][2]['when'], 'published rows carry their age' );
ok( '2026-09-18T12:00:00+00:00' === $out['next'][0]['date'], 'date is ISO 8601 UTC for anyone who wants to do their own arithmetic' );

$empty = sn_content_queue_shape( array(), 0, array(), $NOW, $TZ );
ok( array() === $empty['next'] && null === $empty['runs_to'] && 0 === $empty['scheduled_total'] && array() === $empty['published'], 'an empty site shapes to empty lists and a null runs_to' );
$junk = sn_content_queue_shape( 'nope', -3, array( 'x', null, array( 'title' => 'only a title' ) ), $NOW, $TZ );
ok( array() === $junk['next'] && 0 === $junk['scheduled_total'], 'non-array rows and a negative total are tolerated, not fatal' );
ok( 1 === count( $junk['published'] ) && 0 === $junk['published'][0]['id'], 'a partial row survives with defaults; junk rows are dropped' );

echo "\nGroup D: registration and the execute path\n";
foreach ( $GLOBALS['__cq_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$ab = $GLOBALS['__cq_abilities']['signal-noise/content-queue'] ?? null;
ok( is_array( $ab ), 'signal-noise/content-queue registers on wp_abilities_api_init' );
ok( 'content' === ( $ab['category'] ?? null ), 'category is content (a registered category)' );
ok( array( 'object', 'null' ) === ( $ab['input_schema']['type'] ?? null ), 'input schema types the [object,null] union (bodyless GET delivers null)' );
ok( true === ( $ab['meta']['annotations']['readonly'] ?? null ) && true === ( $ab['meta']['annotations']['idempotent'] ?? null ), 'annotated readonly + idempotent' );
ok( 'snt_ability_perm_edit_posts' === ( $ab['permission_callback'] ?? null ) && true === snt_ability_perm_edit_posts(), 'edit_posts is the gate: whoever sees the Posts screen sees the queue' );

$mk = static function ( $id, $title, $ts ) { return new WP_Post( array( 'ID' => $id, 'post_title' => $title, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ), 'post_date' => gmdate( 'Y-m-d H:i:s', $ts ) ) ); };
$now = time();
$GLOBALS['__cq_posts'] = array(
	'future:ASC'   => array( $mk( 11, 'Soon', $now + 3600 ), $mk( 12, 'Next', $now + 2 * 86400 ), $mk( 13, 'Third', $now + 4 * 86400 ), $mk( 14, 'Fourth', $now + 8 * 86400 ) ),
	'future:DESC'  => array( $mk( 99, 'Last', $now + 101 * 86400 ) ),
	'publish:DESC' => array( $mk( 5, 'Fresh', $now - 7200 ), $mk( 4, 'Older', $now - 4 * 86400 ), $mk( 3, 'Oldest', $now - 10 * 86400 ) ),
);
$res = snt_ability_content_queue( null );
ok( 3 === count( $GLOBALS['__cq_queries'] ), 'three reads: next four asc, furthest one desc, last three published' );
$q = $GLOBALS['__cq_queries'];
ok( 'future' === $q[0]['post_status'] && 'ASC' === $q[0]['order'] && SN_CONTENT_QUEUE_NEXT === $q[0]['numberposts'], 'the first read is the next four scheduled, soonest first' );
ok( 'future' === $q[1]['post_status'] && 'DESC' === $q[1]['order'] && 1 === $q[1]['numberposts'], 'the second read is the single furthest scheduled' );
ok( 'publish' === $q[2]['post_status'] && 'DESC' === $q[2]['order'] && SN_CONTENT_QUEUE_PUBLISHED === $q[2]['numberposts'], 'the third read is the last three published' );
ok( array( 'Soon', 'Next', 'Third', 'Fourth' ) === array_column( $res['next'], 'title' ), 'next carries the four soonest in order (the furthest row does not leak into it)' );
ok( 99 !== ( $res['next'][3]['id'] ?? 0 ) && 26 === $res['scheduled_total'], 'the total rides wp_count_posts (26), not the row count' );
ok( is_array( $res['runs_to'] ) && $res['runs_to']['date'] === gmdate( 'c', $now + 101 * 86400 ), 'runs_to is the furthest scheduled post, from the desc read' );
ok( 'https://x.test/wp-admin/post.php?post=11&action=edit' === $res['next'][0]['edit_url'], 'rows carry the edit link the shell opens as a native window' );
ok( array( 'Fresh', 'Older', 'Oldest' ) === array_column( $res['published'], 'title' ) && '2 hours ago' === $res['published'][0]['when'], 'published rows in order with their age' );

$GLOBALS['__cq_posts'] = array( 'publish:DESC' => array( 'not a post', (object) array( 'ID' => 7 ) ) );
$GLOBALS['__cq_counts'] = (object) array( 'future' => 0 );
$res = snt_ability_content_queue( array() );
ok( array() === $res['next'] && null === $res['runs_to'] && 0 === $res['scheduled_total'] && array() === $res['published'], 'an empty queue answers empty, never an error; a row that is not a WP_Post is dropped' );
$GLOBALS['__cq_counts'] = null;
$res = snt_ability_content_queue( null );
ok( 0 === $res['scheduled_total'], 'a wp_count_posts that returns nothing falls back to the row count, not a fatal' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
