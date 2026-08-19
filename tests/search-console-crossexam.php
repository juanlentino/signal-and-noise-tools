<?php
/**
 * Tests: the Search Console × crawler-ledger cross-exam.
 *
 * Run: php tests/search-console-crossexam.php
 *
 * The load-bearing property is that a sensor which DID NOT ANSWER is never
 * reported as "zero crawler hits" — that would manufacture the exact
 * disagreement this check exists to find.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function __( $s, $d = null ) { return $s; }

$GLOBALS['__gsc'] = null;
$GLOBALS['__mr']  = array( 'ok' => false, 'error' => 'unavailable' );
function snt_gsc_data() { return $GLOBALS['__gsc']; }
function snt_mr_summary_payload( $days ) { return $GLOBALS['__mr']; }

require __DIR__ . '/../inc/search-console-crossexam.php';

function gsc( $impressions ) {
	return array(
		'synced_at' => 1000, 'property' => 'sc-domain:x.test',
		'window' => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
		'pages' => array( '/a' => array( 'clicks' => 0, 'impressions' => $impressions, 'ctr' => 0.0, 'position' => 9.0 ) ),
		'queries' => array(),
	);
}
function ledger( $rows ) { return array( 'ok' => true, 'rows' => $rows ); }

echo "Group: a sensor that did not answer is NOT zero\n";
$GLOBALS['__gsc'] = gsc( 500 );
$GLOBALS['__mr']  = array( 'ok' => false, 'error' => 'not_configured' );
$x = snt_gsc_crossexam();
ok( empty( $x['ok'] ), 'an unanswered ledger yields ok:false, not a verdict' );
ok( 'ledger_not_configured' === $x['reason'], 'and the reason names the sensor failure' );
ok( ! isset( $x['verdict'] ), 'NO verdict is produced — reporting "0 crawler hits" here would manufacture a false disagreement' );

$GLOBALS['__gsc'] = null;
$GLOBALS['__mr']  = ledger( array() );
ok( 'no_gsc' === snt_gsc_crossexam()['reason'], 'no synced GSC window -> no_gsc, not a verdict either' );

echo "\nGroup: each verdict names a DIFFERENT problem\n";
$GLOBALS['__gsc'] = gsc( 500 );
$GLOBALS['__mr']  = ledger( array( array( 'family' => 'openai', 'surface' => 'html', 'hits' => 900 ) ) );
$x = snt_gsc_crossexam();
ok( 'gsc_without_crawler' === $x['verdict'], 'impressions but no SEARCH-family fetches -> gsc_without_crawler (AI crawlers do not count)' );
ok( false !== strpos( snt_gsc_crossexam_reading( $x ), 'edge' ), 'and the reading offers the edge-cache explanation, i.e. the LEDGER is blind' );

$GLOBALS['__mr'] = ledger( array( array( 'family' => 'seo', 'surface' => 'html', 'hits' => 400 ) ) );
ok( 'gsc_without_crawler' === snt_gsc_crossexam()['verdict'], "and 'seo' does not count either — Ahrefs fetching says nothing about Google" );

$GLOBALS['__gsc'] = gsc( 0 );
$GLOBALS['__mr']  = ledger( array( array( 'family' => 'search', 'surface' => 'html', 'hits' => 300 ) ) );
$x = snt_gsc_crossexam();
ok( 'crawler_without_gsc' === $x['verdict'], 'fetches but no impressions -> crawler_without_gsc' );
ok( false !== strpos( snt_gsc_crossexam_reading( $x ), 'ranking or indexing' ), 'and reads as a RANKING problem — the opposite diagnosis' );

$GLOBALS['__gsc'] = gsc( 500 );
$x = snt_gsc_crossexam();
ok( 'agree' === $x['verdict'], 'both present -> agree' );
ok( false !== strpos( snt_gsc_crossexam_reading( $x ), 'magnitude' ), 'and says it is magnitude agreement, not equality — the windows are offset' );

$GLOBALS['__gsc'] = gsc( 0 );
$GLOBALS['__mr']  = ledger( array() );
$x = snt_gsc_crossexam();
ok( 'both_quiet' === $x['verdict'], 'neither -> both_quiet' );
ok( false !== strpos( snt_gsc_crossexam_reading( $x ), 'nothing is confirmed' ), 'and refuses to read silence as health' );

echo "\nGroup: surfaces are counted within the search family only\n";
$GLOBALS['__gsc'] = gsc( 100 );
$GLOBALS['__mr']  = ledger( array(
	array( 'family' => 'search', 'surface' => 'robots',  'hits' => 5 ),
	array( 'family' => 'search', 'surface' => 'sitemap', 'hits' => 3 ),
	array( 'family' => 'search', 'surface' => 'html',    'hits' => 40 ),
	array( 'family' => 'openai', 'surface' => 'robots',  'hits' => 99 ),
) );
$x = snt_gsc_crossexam();
ok( 48 === $x['ledger']['search_hits'], 'search-family hits total 5+3+40, excluding the AI crawler' );
ok( 5 === $x['ledger']['robots_hits'], "robots.txt hits count only the search family's 5, not openai's 99" );
ok( 3 === $x['ledger']['sitemap_hits'], 'sitemap hits likewise' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
