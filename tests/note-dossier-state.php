<?php
/**
 * Standalone test: inc/note-dossier-state.php — the last edge verdict for
 * one note from the site-wide probe log, coverage, sitemap membership, and
 * the scheduled fragments that target it.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_CF_PROBE_LOG_OPT', 'sn_cf_purge_probe_log' );
define( 'SN_CF_PROBE_ALGO', 2 );
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function current_time( $t, $gmt = 0 ) { return 1788600000; }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array( 7 => new WP_Post( array( 'ID' => 7 ) ), 8 => new WP_Post( array( 'ID' => 8, 'post_status' => 'draft' ) ) );

function sn_cf_is_configured() { return $GLOBALS['__cf'] ?? true; }
function snt_cf_freshness_headline( $r ) { return 'fresh' === $r ? 'Edge fresh' : 'Edge served a stale render'; }
function snt_cf_freshness_phrase( $r, $t, $now ) { return 'verified 2 hours ago'; }
function snt_gsc_coverage_data() { return $GLOBALS['__cov']; }
function snt_gsc_coverage_for_path( $p ) { return $GLOBALS['__cov_row']; }
function sn_post_settings_get_noindex( $id ) { return $GLOBALS['__noindex'] ?? false; }
function sn_post_settings_get_canonical_url( $id ) { return $GLOBALS['__canon'] ?? ''; }
function sn_schedule_all() { return $GLOBALS['__rows'] ?? array(); }
function sn_schedule_is_open( $f, $u, $now ) { return true; }
function sn_admin_schedule_fmt_gmt( $g ) { return '' === (string) $g || null === $g ? '' : '2026-09-10 09:00'; }
function snt_desktop_admin_url( $slug, $sub = '' ) { return 'https://example.test/wp-admin/admin.php?page=sn-theme-options&slug=' . $slug . '&sub=' . $sub; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-state.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function by_heading( $blocks, $h ) { foreach ( $blocks as $b ) { if ( $b['heading'] === $h ) { return $b; } } return null; }
echo "note dossier -- operating state\n\n";

$GLOBALS['__opt'] = array( 'sn_cf_purge_probe_log' => array(
	array( 'time' => 1788599000, 'post_id' => 9, 'url' => 'https://example.test/notes/bar/', 'result' => 'stale', 'escalated' => true, 'algo' => 2 ),
	array( 'time' => 1788598000, 'post_id' => 7, 'url' => 'https://example.test/notes/foo/', 'result' => 'fresh', 'algo' => 2 ),
	array( 'time' => 1788500000, 'post_id' => 7, 'url' => 'https://example.test/notes/foo/', 'result' => 'stale', 'escalated' => true, 'algo' => 1 ),
	array( 'time' => 1788400000, 'post_id' => 7, 'url' => 'https://example.test/notes/foo/', 'result' => 'stale', 'escalated' => true, 'algo' => 2 ),
	array( 'time' => 1788300000, 'post_id' => 10, 'url' => 'https://example.test/notes/ten/', 'result' => 'stale', 'escalated' => true, 'algo' => 1 ),
) );
$GLOBALS['__cov']     = array( 'complete' => true, 'entries' => array() );
$GLOBALS['__cov_row'] = array( 'indexed' => true, 'coverage_state' => 'Submitted and indexed', 'last_crawl_time' => '2026-09-01T10:00:00Z' );
$GLOBALS['__rows']    = array(
	array( 'id' => '3', 'target_type' => 'fragment', 'target_ref' => '7', 'starts_at' => '2026-09-10 09:00:00', 'ends_at' => null, 'status' => 'queued' ),
	array( 'id' => '4', 'target_type' => 'fragment', 'target_ref' => '9', 'starts_at' => null, 'ends_at' => null, 'status' => 'active' ),
);

$p = sn_note_dossier_last_probe( 7 );
ok( 'fresh' === $p['result'] && 1788598000 === $p['time'] && false === $p['escalated'], 'the newest current-detector row for THIS post wins; another post\'s newer row and a retired-detector row are skipped' );
ok( null === sn_note_dossier_last_probe( 8 ), 'no row for a post is null' );
ok( null === sn_note_dossier_last_probe( 10 ), 'a post whose ONLY row came from a retired detector (algo 1) reads as no verdict, never as that verdict' );

$b = sn_note_dossier_state( 7 );
$edge = by_heading( $b, 'Edge' );
ok( 'success' === $edge['tone'] && 'Edge fresh' === $edge['text'] && 'verified 2 hours ago' === $edge['meta'] && false !== strpos( $edge['door']['url'], 'sub=cloudflare' ), 'the edge verdict uses the house headline and phrase and opens the Cloudflare leaf' );
$cov = by_heading( $b, 'Search index' );
ok( 'success' === $cov['tone'] && 'Indexed' === $cov['text'] && false !== strpos( $cov['meta'], 'Submitted and indexed' ) && false !== strpos( $cov['meta'], '2026-09-01' ) && false !== strpos( $cov['door']['url'], 'search-console' ), 'coverage: indexed, Google\'s own wording, the last crawl, a door to the Search Console leaf' );
$map = by_heading( $b, 'Sitemap' );
ok( 'success' === $map['tone'] && 'Nothing excludes it from the sitemap' === $map['text'] && false !== strpos( $map['meta'], 'derivation' ) && 'the sitemap rules' === $map['source'], 'a published, indexable note with no canonical elsewhere: the three rules, named as a derivation, sourced' );
$sch = by_heading( $b, 'Scheduled fragments' );
ok( 'table' === $sch['kind'] && 1 === count( $sch['rows'] ) && '2026-09-10 09:00 → never' === $sch['rows'][0]['window'] && 'queued' === $sch['rows'][0]['status']['text'] && 'visible' === $sch['rows'][0]['now'] && false !== strpos( $sch['door']['url'], 'scheduled-content' ), 'only the rows that target this post, with window, status and whether it is open now; the door opens Connections → Scheduled' );

echo "\nthe other states\n";
$GLOBALS['__opt']['sn_cf_purge_probe_log'][1]['result'] = 'stale';
$GLOBALS['__opt']['sn_cf_purge_probe_log'][1]['escalated'] = true;
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Edge' )['tone'] && false !== strpos( by_heading( $b, 'Edge' )['meta'], 'zone purge' ), 'a stale verdict is a warning and names the forced zone purge' );
$GLOBALS['__opt']['sn_cf_purge_probe_log'] = array();
$b = sn_note_dossier_state( 7 );
ok( 'neutral' === by_heading( $b, 'Edge' )['tone'] && false !== strpos( by_heading( $b, 'Edge' )['text'], 'No probe in the last 20' ), 'no row: no probe among the last twenty site-wide, never "fresh"' );
$GLOBALS['__cf'] = false;
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Edge' )['text'], 'not configured' ), 'Cloudflare unconfigured is its own sentence' );
$GLOBALS['__cf'] = true;
$GLOBALS['__cov_row'] = array( 'indexed' => false, 'coverage_state' => 'Crawled - currently not indexed', 'last_crawl_time' => '' );
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Search index' )['tone'] && 'Not indexed' === by_heading( $b, 'Search index' )['text'] && false !== strpos( by_heading( $b, 'Search index' )['meta'], 'Crawled' ), 'not indexed carries Google\'s reason verbatim' );
$GLOBALS['__cov_row'] = array( 'error' => 'no_index_status', 'message' => 'quota' );
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Search index' )['tone'] && false !== strpos( by_heading( $b, 'Search index' )['text'], 'Inspection failed' ) && false !== strpos( by_heading( $b, 'Search index' )['meta'], 'quota' ), 'an error entry is read as an error, never as unknown' );
$GLOBALS['__cov_row'] = null;
$GLOBALS['__cov']['complete'] = false;
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Search index' )['text'], 'Not yet inspected' ), 'no entry while a run is partial is "not yet", not "never"' );
$GLOBALS['__cov'] = null;
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Search index' )['text'], 'never run' ), 'no coverage map: the inspection never ran' );
$GLOBALS['__noindex'] = true;
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Sitemap' )['tone'] && false !== strpos( by_heading( $b, 'Sitemap' )['meta'], 'noindex' ), 'a noindex note is out of the sitemap, and the reason is named' );
$GLOBALS['__noindex'] = false;
$GLOBALS['__canon'] = 'https://elsewhere.example/x';
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Sitemap' )['meta'], 'canonical' ), 'a canonical elsewhere keeps it out, and says so' );
$GLOBALS['__canon'] = '';
$GLOBALS['__rows'] = array();
$b = sn_note_dossier_state( 7 );
ok( 'status' === by_heading( $b, 'Scheduled fragments' )['kind'] && 'neutral' === by_heading( $b, 'Scheduled fragments' )['tone'], 'no fragments: a neutral line, not an empty table' );
ok( array() === sn_note_dossier_state( 8 ), 'a draft has no operating state' );
// The LIVE site runs The SEO Framework. Declared inside a block so it binds only here, after the assertions above.
if ( true ) { function the_seo_framework() { return true; } }
$b = sn_note_dossier_state( 7 );
ok( 'neutral' === by_heading( $b, 'Sitemap' )['tone'] && false !== strpos( by_heading( $b, 'Sitemap' )['meta'], 'does not read its per-post exclusions' ), 'with TSF active (dormant on the live site since v2.0.0) membership is a stated gap' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
