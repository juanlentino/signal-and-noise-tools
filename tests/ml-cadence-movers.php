<?php
/**
 * Tests for inc/ml-cadence-movers.php: a quiet week explained, not only
 * flagged. When the views rhythm flag fires, the same rollups name the pages
 * and sources that moved most against their own trailing baseline.
 *
 * Pins: the top movers in order with their arithmetic (this week N vs usual
 * M, shortfall M minus N); "spread thin" when no single page dominates; the
 * SNT_MOVERS_FLOOR fold into "(withheld)" (its own constant since the owner's
 * analytics reads dropped their floor on 2026-10-23); no explanation and no extra read
 * when the watch does not fire; the health note carries the arithmetic.
 *
 * Run: php tests/ml-cadence-movers.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function __( $s, $d = null ) { return (string) $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? (string) $s : (string) $p; }
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
function apply_filters( $tag, $value ) { return $value; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'skipped' => $skipped );
}
function get_posts( $args ) { return array(); } // No publish flag in this suite.
class MV_Stub_wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_results( $sql, $output = null ) { return array(); } // No cron hooks.
}
$GLOBALS['wpdb'] = new MV_Stub_wpdb();

// The two rollup reads the explanation is allowed: the path rollup (the SAME
// read the watch makes) and the source breakdown. Each call is logged.
$GLOBALS['__rows']    = array();
$GLOBALS['__sources'] = array(); // week start Y-m-d => rows
$GLOBALS['__src_log'] = array();
function sn_analytics_daily_range( $from, $to, $class = 'human' ) { return $GLOBALS['__rows']; }
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) {
	$GLOBALS['__src_log'][] = array( $from, $to, $class );
	return $GLOBALS['__sources'][ $from ] ?? array();
}

require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-cadence.php';
require __DIR__ . '/../inc/health-check-ml-cadence.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// The watch's own calendar: now 2026-08-12 15:00 UTC, so the current
// complete week is 08-05..08-11 and history week w starts 7w days earlier.
$now  = strtotime( '2026-08-12 15:00:00 UTC' );
$cur0 = strtotime( '2026-08-05 00:00:00 UTC' );
$day  = static fn( $ts ) => gmdate( 'Y-m-d', $ts );

/**
 * Daily rows for one path over a week.
 */
function mv_week( $start, $path, $views_per_day, $visits_per_day ) {
	$out = array();
	for ( $d = 0; $d < 7; $d++ ) {
		$out[] = array( 'day' => gmdate( 'Y-m-d', $start + $d * DAY_IN_SECONDS ), 'path' => $path, 'views' => $views_per_day, 'visits' => $visits_per_day );
	}
	return $out;
}

// ── Fixture A: one page carries the drop ─────────────────────────────
// History (12 weeks): /a/ alternates 70 and 84 a week (usual 77), /b/ 35,
// /c/ 21, and two tiny pages at 2 views and 1 visit a week (under the floor).
// This week: /a/ 7, /b/ 28, /c/ 7, the tiny pages 0.
$rows = array();
for ( $w = 1; $w <= 12; $w++ ) {
	$s    = $cur0 - 7 * $w * DAY_IN_SECONDS;
	$rows = array_merge( $rows, mv_week( $s, '/a/', 0 === $w % 2 ? 10 : 12, 3 ), mv_week( $s, '/b/', 5, 2 ), mv_week( $s, '/c/', 3, 1 ) );
	$rows[] = array( 'day' => $day( $s ), 'path' => '/tiny-one/', 'views' => 2, 'visits' => 1 );
	$rows[] = array( 'day' => $day( $s ), 'path' => '/tiny-two/', 'views' => 2, 'visits' => 1 );
	$GLOBALS['__sources'][ $day( $s ) ] = array(
		array( 'value' => 'Google', 'views' => 40, 'visits' => 14 ),
		array( 'value' => '(direct)', 'views' => 30, 'visits' => 10 ),
		array( 'value' => 'Bluesky', 'views' => 20, 'visits' => 7 ),
		array( 'value' => 'tiny-referrer.example', 'views' => 2, 'visits' => 1 ),
	);
}
$rows = array_merge( $rows, mv_week( $cur0, '/a/', 1, 1 ), mv_week( $cur0, '/b/', 4, 2 ), mv_week( $cur0, '/c/', 1, 1 ) );
$GLOBALS['__sources'][ $day( $cur0 ) ] = array(
	array( 'value' => 'Google', 'views' => 5, 'visits' => 2 ),
	array( 'value' => '(direct)', 'views' => 10, 'visits' => 4 ),
	array( 'value' => 'Bluesky', 'views' => 18, 'visits' => 6 ),
);
$GLOBALS['__rows'] = $rows;

echo "Group: a fired flag carries the top movers, in order, with the arithmetic\n";
$env = snt_ml_cadence_flags( $now );
$vf  = $env['flags'][0] ?? array();
ok( 'views' === ( $vf['kind'] ?? '' ), 'the quiet week fires the views flag' );
$paths = $vf['movers']['paths'] ?? null;
ok( is_array( $paths ), 'the fired flag carries movers.paths' );
ok( array( '/a/', '/c/', '/b/' ) === array_column( (array) ( $paths['top'] ?? array() ), 'key' ), 'pages ranked by absolute shortfall: /a/ (70), /c/ (14), /b/ (7)' );
$a = $paths['top'][0] ?? array();
ok( 7 === ( $a['current'] ?? null ) && 77 === ( $a['usual'] ?? null ) && 70 === ( $a['shortfall'] ?? null ), '/a/: this week 7 vs usual 77 (median of 70 and 84 weeks), shortfall 70' );
$b = $paths['top'][2] ?? array();
ok( 28 === ( $b['current'] ?? null ) && 35 === ( $b['usual'] ?? null ) && 7 === ( $b['shortfall'] ?? null ), '/b/: this week 28 vs usual 35, shortfall 7' );
ok( false === ( $paths['spread'] ?? null ), 'one page carries 70 of the 95-view drop: not spread thin' );
$src = $vf['movers']['sources'] ?? null;
ok( array( 'Google', '(direct)', 'Bluesky' ) === array_column( (array) ( $src['top'] ?? array() ), 'key' ), 'sources ranked by shortfall: Google (35), (direct) (20), Bluesky (2)' );
$g = $src['top'][0] ?? array();
ok( 5 === ( $g['current'] ?? null ) && 40 === ( $g['usual'] ?? null ) && 35 === ( $g['shortfall'] ?? null ), 'Google: this week 5 vs usual 40, shortfall 35' );
ok( 13 === count( $GLOBALS['__src_log'] ) && 'human' === $GLOBALS['__src_log'][0][2], 'sources read once per week of the same baseline (12 history + current), human class' );
$check = sn_health_check_ml_cadence_for( $env );
$note  = (string) ( $check['findings'][0]['note'] ?? '' );
ok( false !== strpos( $note, '/a/ (this week 7 vs usual 77)' ) && false !== strpos( $note, 'Google (this week 5 vs usual 40)' ), 'the health note carries the arithmetic: ' . $note );

echo "\nGroup: the floor folds small surfaces into (withheld)\n";
ok( 3 === SNT_MOVERS_FLOOR, 'the movers noise floor stays at 3' );
$json = json_encode( $vf['movers'] ?? array() );
ok( isset( $vf['movers'] ) && false === strpos( (string) $json, 'tiny-one' ) && false === strpos( (string) $json, 'tiny-two' ) && false === strpos( (string) $json, 'tiny-referrer' ), 'a page or source under 3 usual visits is never named' );
ok( array( 'items' => 2, 'current' => 0, 'usual' => 4, 'shortfall' => 4 ) === ( $paths['withheld'] ?? null ), 'the two tiny pages fold into one (withheld) bucket: 2 items, this week 0 vs usual 4' );
ok( array( 'items' => 1, 'current' => 0, 'usual' => 2, 'shortfall' => 2 ) === ( $src['withheld'] ?? null ), 'the tiny referrer folds into (withheld) too' );
ok( false !== strpos( $note, '(withheld)' ), 'the note says what was withheld, never who' );

echo "\nGroup: spread thin when no single page dominates\n";
$rows = array();
for ( $w = 0; $w <= 12; $w++ ) {
	$s = $cur0 - 7 * $w * DAY_IN_SECONDS;
	for ( $p = 0; $p < 20; $p++ ) {
		$per  = 0 === $w ? 5 : ( 0 === $p && 0 === $w % 2 ? 12 : 10 );
		$rows = array_merge( $rows, mv_week( $s, '/p' . $p . '/', $per, 3 ) );
	}
}
$GLOBALS['__rows']    = $rows;
$GLOBALS['__sources'] = array();
$env    = snt_ml_cadence_flags( $now );
$spread = $env['flags'][0]['movers']['paths'] ?? array();
ok( true === ( $spread['spread'] ?? null ) && 20 === ( $spread['falling'] ?? null ), 'twenty pages each down 35 of a 700-view drop: spread thin across 20 pages' );
$note = (string) ( sn_health_check_ml_cadence_for( $env )['findings'][0]['note'] ?? '' );
ok( false !== strpos( $note, 'No single page explains it: the shortfall is spread across 20 pages.' ), 'and the note says so in words' );

echo "\nGroup: no explanation when the watch does not fire\n";
$rows = array();
for ( $w = 0; $w <= 12; $w++ ) {
	$rows = array_merge( $rows, mv_week( $cur0 - 7 * $w * DAY_IN_SECONDS, '/a/', 0 === $w % 2 ? 10 : 12, 3 ) );
}
$GLOBALS['__rows']    = $rows;
$GLOBALS['__src_log'] = array();
$env = snt_ml_cadence_flags( $now );
ok( function_exists( 'snt_ml_views_movers' ) && array( 'ok' => true, 'flags' => array(), 'watched_hooks' => 0, 'cron_skipped' => false, 'views_skipped' => false ) === $env, 'an ordinary week: the envelope is exactly the old one, no movers key anywhere' );
ok( function_exists( 'snt_ml_views_movers' ) && array() === $GLOBALS['__src_log'], 'and no source read is made: the explanation costs nothing until the flag fires' );
ok( '' === snt_ml_views_movers_sentence( null ), 'the sentence helper says nothing without movers' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
