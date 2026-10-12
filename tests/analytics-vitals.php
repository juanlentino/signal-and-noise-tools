<?php
/**
 * Field Core Web Vitals as a read (theme audit idea 4, 2026-10-12):
 * inc/analytics-vitals.php and its live half. p75 and the good / needs
 * improvement / poor split per device and top path; unknown is never 0;
 * thin cells say small_sample; a failed or capped read is no answer.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_AE_ROW_CAP', 50 );
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
$GLOBALS['__t'] = array();
function get_transient( $k ) {
	if ( SNT_ANALYTICS_VDAY_CACHE_KEY === $k ) { return array( 'hashes' => array(), 'ok' => true, 'truncated' => false ); }
	return $GLOBALS['__t'][ $k ] ?? false;
}
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__t'][ $k ] = $v; return true; }
$GLOBALS['__q'] = array();
function sn_analytics_query( $sql ) { $GLOBALS['__q'][] = $sql; return false !== strpos( $sql, 'blob2 IN' ) ? $GLOBALS['__path_rows'] : $GLOBALS['__dev_rows']; }
$GLOBALS['__top_calls'] = 0;
function sn_analytics_top_paths( $from, $to, $class, $limit ) { ++$GLOBALS['__top_calls']; return array( array( 'path' => '/notes/a/' ), array( 'path' => '/' ), array( 'path' => "/x') OR 1=1 --" ) ); }
$GLOBALS['__ae_err'] = null;
function sn_analytics_last_error() { return $GLOBALS['__ae_err']; }
function sn_analytics_canonical_path( $p ) { $t = rtrim( (string) $p, '/' ); return '' === $t ? '/' : $t; }

require_once __DIR__ . '/../inc/analytics-vitals.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Field Core Web Vitals read\n\n";

echo "Group: the SQL\n";
$sql = sn_analytics_vitals_sql( 'device', '2026-09-14', '2026-10-11', 'human', array(), 'sn_pageviews' );
ok( false !== strpos( $sql, "SELECT blob1 AS e, blob5 AS k, ceil(double7 / if(blob1 = 'vc', 1, 10)) * if(blob1 = 'vc', 1, 10) AS v, sum(_sample_interval) AS w" ), 'device: event, device, value, weight; LCP and INP grouped in 10 ms steps, CLS in steps of 0.001 (x1000 stored), rounded up' );
// The live 2026.10.8 failure: AE answered HTTP 422, "the 2nd and 3rd arguments
// to IF() function must have the same type but instead had Double and Integer".
// Every if() in the statement must have two branches of one type: integer literals.
preg_match_all( '/\bif\(([^()]*)\)/', $sql, $ifs );
ok( array() !== $ifs[1] && array() === array_filter( $ifs[1], static fn( $a ) => 1 !== preg_match( "/^blob1 = 'vc', \d+, \d+$/", $a ) ), 'every if() picks between two integer literals (AE refuses Double and Integer branches)' );
ok( false !== strpos( $sql, "blob1 IN ('vl','vi','vc')" ), 'one query for all three vitals' );
ok( false !== strpos( $sql, 'GROUP BY e, k, v' ) && 1 !== preg_match( '/GROUP BY[^\n]*\(/', $sql ), 'GROUP BY takes aliases only (AE refuses a function there)' );
ok( false !== strpos( $sql, "toDateTime('2026-09-14 00:00:00')" ) && false !== strpos( $sql, "toDateTime('2026-10-11 23:59:59')" ), 'explicit window bounds' );
$psql = sn_analytics_vitals_sql( 'path', '2026-09-14', '2026-10-11', 'human', array( '/notes/a', '/', "/x') OR 1=1 --" ), 'sn_pageviews' );
ok( false !== strpos( $psql, "blob2 IN ('/notes/a','/notes/a/','/')" ), 'paths: both spellings of each, the root once' );
ok( false === strpos( $psql, 'OR 1=1' ), 'a path outside the allowlisted characters never reaches the SQL' );
ok( false === sn_analytics_vitals_path_ok( "/notes/a\n" ) && true === sn_analytics_vitals_path_ok( '/notes/a' ), 'the path allowlist anchors at the very end (a trailing newline fails)' );
ok( '' === sn_analytics_vitals_sql( 'path', '2026-09-14', '2026-10-11', 'human', array(), 'sn_pageviews' ), 'no paths: nothing to ask' );

echo "\nGroup: a cell\n";
$cell = sn_analytics_vitals_cell( array( array( 'v' => 1000.0, 'w' => 10.0 ), array( 'v' => 3000.0, 'w' => 10.0 ), array( 'v' => 5000.0, 'w' => 20.0 ) ), 'lcp' );
ok( 40 === $cell['samples'] && 5000.0 === $cell['p75'], 'samples are the weights; p75 is the weighted quantile' );
ok( 25.0 === $cell['good'] && 25.0 === $cell['needs_improvement'] && 50.0 === $cell['poor'], 'bands: good at or under 2500, poor over 4000' );
ok( false === $cell['small_sample'], '40 measurements is not a small sample' );
$edge = sn_analytics_vitals_cell( array( array( 'v' => 2500.0, 'w' => 1.0 ), array( 'v' => 4000.0, 'w' => 1.0 ) ), 'lcp' );
ok( 50.0 === $edge['good'] && 0.0 === $edge['poor'] && true === $edge['small_sample'], 'thresholds are inclusive the way Google states them; 2 measurements is a small sample' );
$cls = sn_analytics_vitals_cell( array( array( 'v' => 80.0, 'w' => 3.0 ), array( 'v' => 300.0, 'w' => 1.0 ) ), 'cls' );
ok( 0.08 === $cls['p75'] && 25.0 === $cls['poor'], 'CLS is stored x1000 and read unitless' );
$none = sn_analytics_vitals_cell( array(), 'inp' );
ok( 0 === $none['samples'] && null === $none['p75'] && null === $none['good'] && null === $none['poor'], 'UNKNOWN IS NOT 0: no measurements is null figures' );

echo "\nGroup: the fold and the display rows\n";
$rows = array(
	array( 'e' => 'vl', 'k' => 'mobile', 'v' => '1800', 'w' => '40' ),
	array( 'e' => 'vi', 'k' => 'mobile', 'v' => '120', 'w' => '35' ),
	array( 'e' => 'vc', 'k' => 'mobile', 'v' => '20', 'w' => '40' ),
	array( 'e' => 'vl', 'k' => '', 'v' => '900', 'w' => '1' ),
	array( 'e' => 'sc', 'k' => 'mobile', 'v' => '50', 'w' => '9' ),
);
$f = sn_analytics_vitals_fold( $rows, array( 'mobile', 'desktop' ), static fn( $k ) => in_array( $k, array( 'mobile', 'desktop' ), true ) ? $k : 'unknown' );
ok( 40 === $f['mobile']['lcp']['samples'] && 35 === $f['mobile']['inp']['samples'], 'rows fold by event and device; other events are ignored' );
ok( 0 === $f['desktop']['lcp']['samples'] && null === $f['desktop']['lcp']['p75'], 'a device with no rows is present and not measured' );
ok( 1 === $f['unknown']['lcp']['samples'], 'an empty device reads unknown' );
$dr = sn_analytics_vitals_device_rows( array( 'devices' => $f ) );
ok( 'Mobile' === $dr[0]['device'] && '1.8 s, 100% good' === $dr[0]['lcp'], 'display: p75 then the good share' );
ok( 'not measured' === $dr[1]['lcp'], 'display: no measurements reads "not measured", never 0' );
ok( false !== strpos( $dr[2]['lcp'], '(small sample: 1)' ), 'display: a thin cell is labeled a small sample' );
ok( null === sn_analytics_vitals_device_rows( null ), 'display: a failed read is null, for the could-not-be-read sentence' );
$f2 = $f;
$f2['unknown'] = array_map( static fn( $c ) => sn_analytics_vitals_cell( array(), 'lcp' ), $f['unknown'] );
ok( 2 === count( sn_analytics_vitals_device_rows( array( 'devices' => $f2 ) ) ), 'display: an unknown device with nothing measured is left out' );

echo "\nGroup: the live read\n";
$GLOBALS['__dev_rows']  = $rows;
$GLOBALS['__path_rows'] = array(
	array( 'e' => 'vl', 'k' => '/notes/a/', 'v' => '2000', 'w' => '3' ),
	array( 'e' => 'vl', 'k' => '/notes/a', 'v' => '3000', 'w' => '1' ),
);
$v = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( 2 === count( $GLOBALS['__q'] ), 'two queries: by device, by path' );
ok( 41 === $v['site']['lcp']['samples'], 'site-wide is the devices merged' );
ok( '/notes/a' === $v['paths'][0]['path'] && 4 === $v['paths'][0]['lcp']['samples'], 'a path folds both spellings into its canonical one' );
ok( '/' === $v['paths'][1]['path'] && null === $v['paths'][1]['lcp']['p75'], 'a top path with no measurements is listed, not measured' );
ok( 2500.0 === $v['thresholds']['lcp']['good_max'] && 0.25 === $v['thresholds']['cls']['poor_over'] && 30 === $v['min_samples'], 'the payload names its thresholds and the small-sample floor' );
sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( 2 === count( $GLOBALS['__q'] ), 'cached: a second read asks nothing' );
ok( 2 === count( $v['paths'] ), 'a top path the SQL refuses is dropped from the list, not shown as not measured' );

$GLOBALS['__t'] = array();
$GLOBALS['__q'] = array();
$GLOBALS['__top_calls'] = 0;
$saved = $GLOBALS['__path_rows'];
$GLOBALS['__path_rows'] = null;
$d0 = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 0 );
ok( is_array( $d0 ) && 41 === $d0['site']['lcp']['samples'] && array() === $d0['paths'], 'devices-only (limit 0): a failing path read no longer blanks the device rows' );
ok( 1 === count( $GLOBALS['__q'] ) && 0 === $GLOBALS['__top_calls'], 'devices-only asks neither the top-paths table nor the path query' );
ok( null !== sn_analytics_vitals_device_rows( $d0 ), 'devices-only: the Engagement device table has rows' );
$GLOBALS['__path_rows'] = $saved;

$GLOBALS['__t'] = array();
$GLOBALS['wpdb'] = (object) array( 'last_error' => 'Table is marked as crashed' );
ok( null === sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 ), 'a top-paths read that failed in the database is no answer, not an empty list' );
ok( 0 === strpos( sn_analytics_vitals_failure(), 'the top-paths read' ) && false !== strpos( sn_analytics_vitals_failure(), 'marked as crashed' ), 'the reason names the top-paths leg and the database error' );
unset( $GLOBALS['wpdb'] );

$GLOBALS['__t'] = array();
$GLOBALS['__dev_rows'] = null;
$GLOBALS['__ae_err'] = array( 'url' => 'https://api.cloudflare.com/client/v4/accounts/SECRETACCT/analytics_engine/sql', 'code' => 422, 'message' => 'Input was invalid', 'when' => 0 );
ok( null === sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 ), 'a failed read is null, never zeros' );
$why = sn_analytics_vitals_failure();
ok( 0 === strpos( $why, 'the by-device read failed' ) && false !== strpos( $why, 'HTTP 422: Input was invalid' ), 'the reason names the by-device leg and what Analytics Engine said' );
ok( false === strpos( $why, 'SECRETACCT' ), 'the reason never carries the request URL (it holds the account id)' );
sn_analytics_vitals_failure( '' );
ok( null === sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 ) && $why === sn_analytics_vitals_failure(), 'a cached failure still says why, for its 5 minutes' );
$GLOBALS['__ae_err'] = null;
$GLOBALS['__t'] = array();
$GLOBALS['__dev_rows'] = array_fill( 0, 50, $rows[0] );
ok( null === sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 ), 'a read at the row cap is a partial distribution: null' );
ok( false !== strpos( sn_analytics_vitals_failure(), 'row cap' ), 'the reason says the row cap, not a failure' );

echo "\nGroup: the ability says which leg failed\n";
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function snt_metrics_args( $i ) { return array( array( 'from' => '2026-09-14', 'to' => '2026-10-11' ), 'human', 5 ); }
$GLOBALS['__t'] = array();
$GLOBALS['__dev_rows'] = null;
$GLOBALS['__ae_err'] = array( 'url' => 'u', 'code' => 422, 'message' => 'bad', 'when' => 0 );
$e = snt_ability_get_analytics_vitals( array() );
ok( $e instanceof WP_Error && 'The Core Web Vitals read failed: the by-device read failed (Analytics Engine said HTTP 422: bad).' === $e->m, 'the ability error names the leg and the reason' );

echo "\nGroup: the two Engagement twins say the same sentences\n";
$classic = (string) file_get_contents( __DIR__ . '/../inc/analytics-render-distribution.php' );
$kit     = (string) file_get_contents( __DIR__ . '/../apps/sn-analytics/parts/paint-kit-tables.php' );
$painter = (string) file_get_contents( __DIR__ . '/../apps/sn-analytics/parts/painters/view-engagement.php' );
$view    = (string) file_get_contents( __DIR__ . '/../inc/analytics-view-engagement.php' );
ok( 1 === preg_match( '/function snt_analytics_render_vitals_devices.*?sn_analytics_vitals_copy\(\)/s', $classic ) && 1 === preg_match( '/function vitals_device_table.*?sn_analytics_vitals_copy\(\)/s', $kit ), 'classic and S&N Analytics take their title, failure and note from one function' );
ok( false !== strpos( $view, 'snt_analytics_render_vitals_devices( sn_analytics_vitals_device_rows( sn_analytics_vitals( $from, $to, $class, 0 ) ) )' ) && false !== strpos( $painter, 'vitals_device_table( \\sn_analytics_vitals_device_rows( \\sn_analytics_vitals( $from, $to, $class, 0 ) ) )' ), 'both twins paint the same rows from the same devices-only read' );
$copy = sn_analytics_vitals_copy();
ok( false === strpos( implode( ' ', $copy ), "\u{2014}" ) && false !== strpos( $copy['note'], 'fewer than 30' ), 'the copy has no em dash and names the small-sample floor' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
