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
function get_option( $k, $d = false ) { return $GLOBALS['__cq_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__cq_opts'][ $k ] = $v; return true; }
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

require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-cadence.php';
require __DIR__ . '/../inc/abilities-content-queue.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$TZ  = new DateTimeZone( 'UTC' );
$NOW = gmmktime( 12, 0, 0, 9, 17, 2026 ); // Thu 2026-09-17 12:00Z

echo "abilities-content-queue — 15.8.0\n\nGroup A: the scheduled-time label (site timezone)\n";
ok( 'Today 9:38 PM' === sn_content_queue_when( $NOW + 9 * 3600 + 38 * 60, $NOW, $TZ ), 'later today → "Today 9:38 PM", the 12-hour clock the other cards use' );
ok( 'Tomorrow 9:38 AM' === sn_content_queue_when( gmmktime( 9, 38, 0, 9, 18, 2026 ), $NOW, $TZ ), 'tomorrow → "Tomorrow 9:38 AM"' );
ok( 'Sun 11:38 AM' === sn_content_queue_when( gmmktime( 11, 38, 0, 9, 20, 2026 ), $NOW, $TZ ), 'within the week → weekday + time' );
ok( 'Sep 26' === sn_content_queue_when( gmmktime( 11, 20, 0, 9, 26, 2026 ), $NOW, $TZ ), 'beyond the week, same year → "Mon D"' );
ok( 'Dec 27' === sn_content_queue_when( gmmktime( 18, 26, 0, 12, 27, 2026 ), $NOW, $TZ ), 'the end of the real queue → "Dec 27"' );
ok( 'Jan 3, 2027' === sn_content_queue_when( gmmktime( 10, 0, 0, 1, 3, 2027 ), $NOW, $TZ ), 'next year carries the year' );
ok( 'Overdue' === sn_content_queue_when( $NOW - 60, $NOW, $TZ ), 'a scheduled time in the past is "Overdue" (cron has not fired)' );
// The timezone is the site's, not UTC: 23:30Z on the 17th is 01:30 on the 18th in Madrid (CEST).
$madrid = new DateTimeZone( 'Europe/Madrid' );
ok( 'Tomorrow 1:30 AM' === sn_content_queue_when( gmmktime( 23, 30, 0, 9, 17, 2026 ), $NOW, $madrid ), 'the day boundary is the site timezone\'s, not UTC\'s' );

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
ok( array( 'next', 'scheduled_total', 'runs_to', 'published', 'drafts', 'gap', 'scheduled', 'today', 'week_start', 'planned', 'draft_list' ) === array_keys( $out ), 'the payload carries exactly the eleven keys (the six it had, and the calendar\'s five)' );
ok( null === $out['drafts'], 'drafts is null when the caller could not count them, never a guessed 0' );
ok( 7 === sn_content_queue_shape( $future, 26, $published, $NOW, $TZ, 7 )['drafts'] && 0 === sn_content_queue_shape( $future, 26, $published, $NOW, $TZ, 0 )['drafts'], 'a counted draft total passes through, a real 0 included' );
ok( 4 === count( $out['next'] ), 'next is capped at four (the headline plus three)' );
ok( 'Note in 1 days' === $out['next'][0]['title'] && 'Tomorrow 12:00 PM' === $out['next'][0]['when'], 'the first row is the soonest, labelled' );
ok( 26 === $out['scheduled_total'], 'the total is the site-wide count, not the row count' );
ok( is_array( $out['runs_to'] ) && 'Dec 27' === $out['runs_to']['label'], 'runs_to is the LAST future row, the furthest scheduled (101 days → Dec 27)' );
ok( 3 === count( $out['published'] ), 'published is capped at three' );
ok( '(untitled)' === $out['published'][1]['title'], 'an empty title paints as (untitled), never a blank row' );
ok( '' === $out['published'][1]['edit_url'], 'a missing link stays empty (the widget paints plain text)' );
ok( '1 day ago' === $out['published'][0]['when'] && '7 days ago' === $out['published'][2]['when'], 'published rows carry their age' );
ok( '2026-09-18T12:00:00+00:00' === $out['next'][0]['date'], 'date is ISO 8601 UTC for anyone who wants to do their own arithmetic' );


echo "\nGroup C1: the calendar's data (2026-10-12)\n";
// The sketch plotted only `next` (four notes) and left real notes off the grid.
ok( 6 === count( $out['scheduled'] ) && array_column( $future, 'title' ) === array_column( $out['scheduled'], 'title' ), 'scheduled carries EVERY scheduled note, not the four of next' );
ok( '2026-12-27' === $out['scheduled'][5]['day'] && '12:00 PM' === $out['scheduled'][5]['time'], 'each row carries its day (Y-m-d) and time in the site timezone' );
ok( '2026-09-18' === $out['next'][0]['day'], 'the next rows carry the day too, so the card can tint the next-up day' );
ok( '2026-09-17' === $out['today'] && '2026-09-14' === $out['week_start'], 'today and the Monday of this week (Thu Sep 17 → Mon Sep 14)' );
$sun = sn_content_queue_shape( array(), 0, array(), gmmktime( 12, 0, 0, 9, 20, 2026 ), $TZ );
ok( '2026-09-14' === $sun['week_start'], 'on a Sunday the week still starts the Monday before (Sun Sep 20 → Mon Sep 14), never the next Monday' );
$mon = sn_content_queue_shape( array(), 0, array(), gmmktime( 0, 30, 0, 9, 21, 2026 ), $TZ );
ok( '2026-09-21' === $mon['week_start'], 'on a Monday the week starts that day' );
// 22:30Z on Sun Sep 20 is 00:30 Mon Sep 21 in Madrid: the site's week has turned.
$mad = sn_content_queue_shape( array( array( 'id' => 1, 'title' => 'Late', 'ts' => gmmktime( 22, 30, 0, 9, 20, 2026 ) ) ), 1, array(), gmmktime( 22, 30, 0, 9, 20, 2026 ), new DateTimeZone( 'Europe/Madrid' ) );
ok( '2026-09-21' === $mad['today'] && '2026-09-21' === $mad['week_start'] && '2026-09-21' === $mad['scheduled'][0]['day'] && '12:30 AM' === $mad['scheduled'][0]['time'], 'today, the week and each day are the site timezone\'s, not UTC\'s' );
$pl = sn_content_queue_shape( array(), 0, array(), $NOW, $TZ, null, array( '2026-12-20|2027-01-03' ) );
ok( array( array( 'from' => '2026-12-20', 'to' => '2027-01-03' ) ) === $pl['planned'], 'the planned gaps ride as ranges for the calendar\'s quiet mark' );
$dl = sn_content_queue_shape( array(), 0, array(), $NOW, $TZ, 2, array(), array( array( 'id' => 8, 'title' => 'A draft', 'ts' => $NOW - 60, 'edit_url' => 'https://x.test/e/8' ), array( 'id' => 9, 'title' => '', 'ts' => 0 ), 'junk' ) );
ok( array( array( 'id' => 8, 'title' => 'A draft', 'edit_url' => 'https://x.test/e/8' ), array( 'id' => 9, 'title' => '(untitled)', 'edit_url' => '' ) ) === $dl['draft_list'], 'drafts ride by title and edit link only (no date, no body); an empty title is (untitled); junk is dropped' );

$empty = sn_content_queue_shape( array(), 0, array(), $NOW, $TZ );
ok( array() === $empty['scheduled'] && array() === $empty['planned'] && array() === $empty['draft_list'], 'an empty site has empty calendar lists' );
ok( array() === $empty['next'] && null === $empty['runs_to'] && 0 === $empty['scheduled_total'] && array() === $empty['published'], 'an empty site shapes to empty lists and a null runs_to' );
$junk = sn_content_queue_shape( 'nope', -3, array( 'x', null, array( 'title' => 'only a title' ) ), $NOW, $TZ );
ok( array() === $junk['next'] && 0 === $junk['scheduled_total'], 'non-array rows and a negative total are tolerated, not fatal' );
ok( 1 === count( $junk['published'] ) && 0 === $junk['published'][0]['id'], 'a partial row survives with defaults; junk rows are dropped' );

echo "\nGroup C2: the gap, by the publishing-cadence flag's own threshold\n";
// History: a note every 3 days with a day of jitter (std > 0), newest first.
$hist = array();
foreach ( array( 0, 3, 7, 10, 12, 15, 19, 22, 24, 27, 31, 34, 36, 39, 43, 46, 48, 51, 55, 58 ) as $d ) {
	$hist[] = $NOW - ( $d + 1 ) * 86400;
}
$dev = snt_ml_cadence_deviation( $hist, $NOW );
ok( is_array( $dev ) && $dev['std'] > 0, 'the fixture history has a spread, so the cadence flag can speak' );
$threshold = $dev['ewma'] + SNT_ML_CADENCE_Z_FLAG * $dev['std'];
ok( $threshold > 4 * 86400 && $threshold < 10 * 86400, 'its late threshold sits between a normal interval and the hole (' . round( $threshold / 86400, 1 ) . ' days)' );
// Schedule (UTC 10:00): Sep 18, 21, 24, a hole, Oct 9, Oct 12, then a second hole to Nov 30.
$at    = static function ( $m, $d ) { return gmmktime( 10, 0, 0, $m, $d, 2026 ); };
$holey = array( $at( 9, 18 ), $at( 9, 21 ), $at( 9, 24 ), $at( 10, 9 ), $at( 10, 12 ), $at( 11, 30 ) );
$gap   = sn_content_queue_gap( $holey, $hist, $NOW, $TZ );
ok( is_array( $gap ) && '2026-09-25' === $gap['from'] && '2026-10-08' === $gap['to'], 'the FIRST hole is reported as the whole empty days between the two notes' );
ok( is_array( $gap ) && 'Sep 25' === $gap['from_label'] && 'Oct 8' === $gap['to_label'], 'the gap labels are calendar days, formatted like the card\'s dates' );
$as_rows = static function ( $list ) { return array_map( static function ( $t ) { return array( 'id' => 1, 'title' => 't', 'ts' => $t ); }, $list ); };
$shaped  = sn_content_queue_shape( $as_rows( array_reverse( $holey ) ), 6, $as_rows( $hist ), $NOW, $TZ, 2 );
ok( is_array( $shaped['gap'] ) && '2026-09-25' === $shaped['gap']['from'], 'the shaper carries the gap from its own rows, in any order' );
// 2026-10-10: a gap marked planned (the holidays) is skipped, and the next one shows.
$holey2 = array_merge( $holey, array( $at( 12, 20 ) ) );
$g1 = sn_content_queue_gap( $holey2, $hist, $NOW, $TZ, array( '2026-09-25|2026-10-08' ) );
ok( is_array( $g1 ) && '2026-10-13' === $g1['from'], 'a planned gap is skipped: the next gap in the schedule shows instead' );
ok( null === sn_content_queue_gap( $holey, $hist, $NOW, $TZ, array( '2026-09-25|2026-10-08', '2026-10-13|2026-11-29' ) ), 'every gap planned: no line' );
$sp = sn_content_queue_shape( $as_rows( $holey ), 6, $as_rows( $hist ), $NOW, $TZ, 2, array( '2026-09-25|2026-10-08' ) );
ok( is_array( $sp['gap'] ) && '2026-09-25' !== $sp['gap']['from'], 'the shaper passes the planned gaps through' );
$GLOBALS['__cq_opts'] = array();
ok( false === sn_content_queue_mark_planned( '2026-10-08', '2026-09-25' ) && false === sn_content_queue_mark_planned( 'x', '2026-09-25' ) && false === sn_content_queue_mark_planned( '2026-99-99', '2026-99-99' ) && false === sn_content_queue_mark_planned( "2026-10-08\n", '2026-10-09' ) && array() === sn_content_queue_planned_gaps(), 'a reversed, malformed, impossible or newline-suffixed range is refused and nothing is stored' );
ok( true === sn_content_queue_mark_planned( '2026-12-20', '2027-01-03' ) && array( '2026-12-20|2027-01-03' ) === sn_content_queue_planned_gaps(), 'a planned gap is stored as from|to' );
$steady = array( $at( 9, 18 ), $at( 9, 21 ), $at( 9, 24 ), $at( 9, 27 ), $at( 9, 30 ) );
ok( null === sn_content_queue_gap( $steady, $hist, $NOW, $TZ ), 'a schedule at the site\'s cadence has no gap' );
ok( null === sn_content_queue_shape( $as_rows( $steady ), 5, $as_rows( $hist ), $NOW, $TZ, 2 )['gap'], 'and the shaper says null for it' );
ok( null === sn_content_queue_gap( $holey, array_slice( $hist, 0, 4 ), $NOW, $TZ ), 'thin history (under five notes) is an honest unknown: no gap line' );
$metronome = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$metronome[] = $NOW - $i * 3 * 86400;
}
ok( null === sn_content_queue_gap( $holey, $metronome, $NOW, $TZ ), 'a zero-spread history is unquantifiable, as in the cadence flag: no gap line' );
ok( null === sn_content_queue_gap( array( $at( 9, 18 ) ), $hist, $NOW, $TZ ) && null === sn_content_queue_gap( array(), $hist, $NOW, $TZ ), 'one note or none has no pair to measure' );
// The day boundary is the site's: 23:30Z on Sep 24 is 01:30 Sep 25 in Madrid, so the hole starts a day later.
$late = array( $at( 9, 18 ), $at( 9, 21 ), gmmktime( 23, 30, 0, 9, 24, 2026 ), $at( 10, 9 ) );
$mgap = sn_content_queue_gap( $late, $hist, $NOW, new DateTimeZone( 'Europe/Madrid' ) );
ok( is_array( $mgap ) && '2026-09-26' === $mgap['from'], 'the empty days are counted in the site timezone' );

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
	'future:ASC'   => array( $mk( 11, 'Soon', $now + 3600 ), $mk( 12, 'Next', $now + 2 * 86400 ), $mk( 13, 'Third', $now + 4 * 86400 ), $mk( 14, 'Fourth', $now + 8 * 86400 ), $mk( 99, 'Last', $now + 101 * 86400 ) ),
	'publish:DESC' => array( $mk( 5, 'Fresh', $now - 7200 ), $mk( 4, 'Older', $now - 4 * 86400 ), $mk( 3, 'Oldest', $now - 10 * 86400 ) ),
	'draft:DESC'   => array( $mk( 21, 'Idea', $now - 600 ) ),
);
$GLOBALS['__cq_counts'] = (object) array( 'future' => 26, 'publish' => 60, 'draft' => 4 );
$res = snt_ability_content_queue( null );
// 23.8.0: the whole queue serves next, runs_to, the gap walk and (2026-10-12)
// the calendar; the published read is the cadence flag's history depth; the
// third read lists the drafts by title.
ok( 3 === count( $GLOBALS['__cq_queries'] ), 'three reads: the whole queue asc, the published cadence history, the newest drafts' );
$q = $GLOBALS['__cq_queries'];
ok( 'future' === $q[0]['post_status'] && 'ASC' === $q[0]['order'] && -1 === $q[0]['numberposts'], 'the first read is the whole scheduled queue, soonest first' );
ok( 'publish' === $q[1]['post_status'] && 'DESC' === $q[1]['order'] && SNT_ML_CADENCE_PUBLISH_DEPTH === $q[1]['numberposts'], 'the second read is the cadence flag\'s publish depth' );
ok( 'draft' === $q[2]['post_status'] && 'modified' === $q[2]['orderby'] && 3 === $q[2]['numberposts'] && false === $q[2]['update_post_meta_cache'], 'the third read is the three newest-edited drafts, with no meta cache primed' );
ok( array( 'Idea' ) === array_column( $res['draft_list'], 'title' ) && 'https://x.test/wp-admin/post.php?post=21&action=edit' === $res['draft_list'][0]['edit_url'], 'the drafts list carries each title and its wp-admin edit link' );
ok( 5 === count( $res['scheduled'] ) && 'Last' === $res['scheduled'][4]['title'], 'the calendar gets the whole queue from the same read (five here, not four)' );
ok( array( 'Soon', 'Next', 'Third', 'Fourth' ) === array_column( $res['next'], 'title' ), 'next carries the four soonest in order (the furthest row does not leak into it)' );
ok( 99 !== ( $res['next'][3]['id'] ?? 0 ) && 26 === $res['scheduled_total'], 'the total rides wp_count_posts (26), not the row count' );
ok( 4 === $res['drafts'], 'drafts rides wp_count_posts()->draft' );
ok( is_array( $res['runs_to'] ) && $res['runs_to']['date'] === gmdate( 'c', $now + 101 * 86400 ), 'runs_to is the furthest scheduled post, the last of the asc read' );
ok( 'https://x.test/wp-admin/post.php?post=11&action=edit' === $res['next'][0]['edit_url'], 'rows carry the edit link the shell opens as a native window' );
ok( array( 'Fresh', 'Older', 'Oldest' ) === array_column( $res['published'], 'title' ) && '2 hours ago' === $res['published'][0]['when'], 'published rows in order with their age' );
ok( null === $res['gap'], 'three published notes are too little history to call a gap: null, not a guess' );
$props = $ab['output_schema']['properties'] ?? array();
ok( array( 'integer', 'null' ) === ( $props['drafts']['type'] ?? null ) && array( 'object', 'null' ) === ( $props['gap']['type'] ?? null ), 'the output schema declares drafts and gap, both nullable' );
ok( isset( $props['scheduled'], $props['today'], $props['week_start'], $props['planned'], $props['draft_list'] ) && isset( $props['scheduled']['items']['properties']['day'], $props['scheduled']['items']['properties']['time'] ), 'the output schema declares the calendar\'s keys and the rows\' day and time' );

$GLOBALS['__cq_posts'] = array( 'publish:DESC' => array( 'not a post', (object) array( 'ID' => 7 ) ) );
$GLOBALS['__cq_counts'] = (object) array( 'future' => 0 );
$res = snt_ability_content_queue( array() );
ok( array() === $res['next'] && null === $res['runs_to'] && 0 === $res['scheduled_total'] && array() === $res['published'], 'an empty queue answers empty, never an error; a row that is not a WP_Post is dropped' );
$GLOBALS['__cq_counts'] = null;
$res = snt_ability_content_queue( null );
ok( 0 === $res['scheduled_total'], 'a wp_count_posts that returns nothing falls back to the row count, not a fatal' );
ok( null === $res['drafts'], 'and drafts is null then, never a fabricated 0' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
