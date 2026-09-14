<?php
/**
 * Standalone test: the Posts tab's data layer (inc/analytics-posts-signals.php).
 *
 * Every per-note signal is fed by a stub the test controls, so each row field
 * can be pinned in both its real and its ABSENT form. The three rules under
 * test: a gap is null with a reason (never zero), the three flags derive in
 * one place as true binaries, and machine reads never appear per row.
 *
 * Run: php tests/analytics-posts-signals.php
 *
 * @since 14.6.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
const SN_POSTS_LIFECYCLE_MAX = 200;
const SNT_ML_CORPUS_META_OPT = 'snt_ml_corpus_meta';
const SNT_ML_RELATED_META    = '_snt_ml_related';
const SNT_ML_TOP_N           = 10;

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// ── WordPress, flat ────────────────────────────────────────────────────
$NOW = 1789300000; // 2026-09-13 ~12:26Z
function current_time( $t, $gmt = 0 ) { return $GLOBALS['NOW']; }
$GLOBALS['__posts'] = array();
function get_posts( $a ) { return array_values( $GLOBALS['__posts'] ); }
function get_permalink( $p ) { return 'https://x.test/notes/' . ( is_object( $p ) ? $p->post_name : $GLOBALS['__posts'][ (int) $p ]->post_name ) . '/'; }
function get_post_time( $f, $gmt, $p ) { return (int) $p->pub; }
function get_post_modified_time( $f, $gmt, $p ) { return (int) $p->mod; }
function get_the_title( $p ) { return (string) $p->post_title; }
function get_post_status( $id ) { return 'publish'; }
$GLOBALS['__opts'] = array(); $GLOBALS['__meta'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['__meta'][ $id ][ $k ] ?? ''; }
function sn_path_join_key( $url ) { return rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' ); }
function sn_prov_is_note( $id ) { return true; }
function snt_corpus_word_count( $c ) { return str_word_count( (string) $c ); }

// ── The signal stores, each switchable ────────────────────────────────
$GLOBALS['__gsc'] = null; $GLOBALS['__gsc_capped'] = false;
function snt_gsc_data() { return $GLOBALS['__gsc']; }
function snt_gsc_metrics_for_path( $k ) { return $GLOBALS['__gsc']['pages'][ $k ] ?? null; }
function snt_gsc_window_totals() { return null === $GLOBALS['__gsc'] ? null : array( 'capped' => $GLOBALS['__gsc_capped'] ); }
$GLOBALS['__cov'] = null;
function snt_gsc_coverage_data() { return $GLOBALS['__cov']; }
$GLOBALS['__inbound'] = null;
function snt_ml_inbound_by_path() { return $GLOBALS['__inbound']; }
$GLOBALS['__chains'] = array();
function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ $id ] ?? array(); }
function sn_note_dossier_anchored_commit( array $chain ) { for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) { if ( 'confirmed' === ( $chain[ $i ]['status'] ?? '' ) && (int) ( $chain[ $i ]['version'] ?? 0 ) >= 1 ) { return $chain[ $i ]; } } return null; }
function sn_prov_key_id() { return 'key-2026'; }
function sn_prov_last_commit_gmt_from_chain( $chain ) { $n = ''; foreach ( (array) $chain as $e ) { $at = (string) ( $e['committed_at'] ?? '' ); if ( '' !== $at && ( '' === $n || strtotime( $at ) > strtotime( $n ) ) ) { $n = $at; } } return '' === $n ? '' : gmdate( 'Y-m-d H:i:s', strtotime( $n ) ); }
$GLOBALS['__related'] = array();
function snt_ml_related_for_post( $id, $limit ) { if ( ! is_array( get_option( SNT_ML_CORPUS_META_OPT, false ) ) ) { return null; } return $GLOBALS['__related'][ $id ] ?? array(); }
function sn_analytics_post_path( $id ) { return '/notes/' . $GLOBALS['__posts'][ $id ]->post_name; }
$GLOBALS['__lifetime'] = array();
function sn_analytics_path_lifetime( $path ) { return (int) ( $GLOBALS['__lifetime'][ $path ] ?? 0 ); }
$GLOBALS['__mr'] = null;
function snt_mr_snapshot() { return $GLOBALS['__mr']; }
function snt_mr_snapshot_total( $s ) { return is_array( $s ) ? (int) $s['total'] : null; }
function snt_mr_snapshot_is_stale( $s ) { return is_array( $s ) && ! empty( $s['stale'] ); }

require_once __DIR__ . '/../inc/analytics-posts-signals.php';

function note( $id, $slug, $title, $pub_days_ago, $mod_days_ago ) {
	$GLOBALS['__posts'][ $id ] = (object) array( 'ID' => $id, 'post_name' => $slug, 'post_title' => $title, 'post_content' => 'one two three four five', 'pub' => $GLOBALS['NOW'] - $pub_days_ago * DAY_IN_SECONDS, 'mod' => $GLOBALS['NOW'] - $mod_days_ago * DAY_IN_SECONDS );
}
function row_for( $id ) { return sn_analytics_posts_row( $GLOBALS['__posts'][ $id ], sn_analytics_posts_context() ); }

echo "Group 1: every signal absent → every field null WITH a reason, never zero\n";
note( 1, 'a', 'Alpha', 68, 3 );
$r = row_for( 1 );
foreach ( array( 'index', 'last_crawl', 'impressions', 'clicks', 'position', 'inbound', 'anchor', 'related' ) as $k ) {
	ok( null === $r[ $k ]['value'] && '' !== $r[ $k ]['why'], "$k: null + reason ({$r[$k]['why']})" );
}
ok( 68 === $r['age']['value'] && 5 === $r['words']['value'], 'age and words come from the post itself' );
ok( 0 === $r['views']['value'], 'views is the ONE raw count: an empty table is a real 0 (nothing to guess)' );
ok( array( 'not_indexed' => false, 'stale_crawl' => false, 'orphaned' => false ) === $r['flags'], 'no signal → no flag: a gap is not evidence of a fault' );
ok( ! array_key_exists( 'machine_reads', $r ), 'machine reads are NOT a row field' );

echo "\nGroup 2: Search Console\n";
$GLOBALS['__gsc'] = array( 'window' => array( 'start' => '2026-08-14', 'end' => '2026-09-10' ), 'pages' => array( '/notes/a' => array( 'impressions' => 187, 'clicks' => 0, 'position' => 4.04 ) ) );
note( 2, 'b', 'Beta', 10, 10 );
$a = row_for( 1 ); $b = row_for( 2 );
ok( 187 === $a['impressions']['value'] && 0 === $a['clicks']['value'] && 4.0 === $a['position']['value'], 'a page row → impressions, clicks (a real 0), position rounded' );
ok( null === $b['impressions']['value'] && 'not shown by Google in this window' === $b['impressions']['why'], 'no page row → gap "not shown", never 0 impressions' );
$GLOBALS['__gsc_capped'] = true;
ok( 'not among the rows the sync keeps' === row_for( 2 )['impressions']['why'], 'a capped sync says so: absence may be the cap, not Google' );
$GLOBALS['__gsc_capped'] = false;

echo "\nGroup 3: coverage and the two flags it feeds\n";
$GLOBALS['__cov'] = array( 'complete' => true, 'status' => array( 'finished_at' => $NOW - 5 * DAY_IN_SECONDS, 'inspected' => 2, 'errors' => 0 ), 'entries' => array(
	'/notes/a' => array( 'coverage_state' => 'Submitted and indexed', 'indexed' => true, 'last_crawl_time' => gmdate( 'c', $NOW - 36 * DAY_IN_SECONDS ) ),
	'/notes/b' => array( 'coverage_state' => 'Discovered - currently not indexed', 'indexed' => false, 'last_crawl_time' => '' ),
) );
$a = row_for( 1 ); $b = row_for( 2 );
ok( true === $a['index']['value'] && 'Submitted and indexed' === $a['coverage_state'], 'indexed, coverage_state verbatim' );
ok( true === $a['flags']['stale_crawl'], 'STALE CRAWL: crawled 36 days ago, edited 3 days ago' );
ok( false === $b['index']['value'] && true === $b['flags']['not_indexed'], 'NOT INDEXED: indexed === false' );
ok( null === $b['last_crawl']['value'] && 'never crawled' === $b['last_crawl']['why'] && false === $b['flags']['stale_crawl'], 'an empty last_crawl_time is a gap ("never crawled"), and a gap cannot be a stale crawl' );
// Boundary: a crawl AT the edit instant is not stale (it saw the edit); one
// second before is. And an edit with no modified stamp can never be stale.
$GLOBALS['__cov']['entries']['/notes/a']['last_crawl_time'] = gmdate( 'c', $GLOBALS['__posts'][1]->mod );
ok( false === row_for( 1 )['flags']['stale_crawl'], 'crawled at the exact edit instant → NOT stale' );
$GLOBALS['__cov']['entries']['/notes/a']['last_crawl_time'] = gmdate( 'c', $GLOBALS['__posts'][1]->mod - 1 );
ok( true === row_for( 1 )['flags']['stale_crawl'], 'crawled one second before the edit → stale' );
$GLOBALS['__cov']['entries']['/notes/a']['last_crawl_time'] = gmdate( 'c', $NOW - 36 * DAY_IN_SECONDS );
note( 3, 'c', 'Gamma', 2, 2 );
$c = row_for( 3 );
ok( null === $c['index']['value'] && 'not inspected in the last run' === $c['index']['why'] && false === $c['flags']['not_indexed'], 'a note missing from a COMPLETE run: "not inspected", and not flagged' );
$GLOBALS['__cov']['entries']['/notes/c'] = array( 'coverage_state' => '', 'indexed' => null, 'last_crawl_time' => '' );
ok( null === row_for( 3 )['index']['value'] && 'Google gave no coverage state' === row_for( 3 )['index']['why'], 'indexed:null in the store stays null: Google gave no state, and that is not a guess either way' );
$GLOBALS['__cov']['entries']['/notes/c'] = array( 'error' => 'quota', 'message' => 'Quota exceeded' );
ok( 'inspection failed' === row_for( 3 )['index']['why'], 'an inspection error is a gap, not a verdict' );

echo "\nGroup 4: inbound links and the orphan flag\n";
$GLOBALS['__inbound'] = array( '/notes/a' => array( 'inbound' => 10, 'linked_from' => array() ), '/notes/b' => array( 'inbound' => 1, 'linked_from' => array() ) );
$a = row_for( 1 ); $b = row_for( 2 ); $c = row_for( 3 );
ok( 10 === $a['inbound']['value'] && false === $a['flags']['orphaned'], '10 inbound → not orphaned' );
ok( 1 === $b['inbound']['value'] && true === $b['flags']['orphaned'], '1 inbound → ORPHANED (<= ' . SN_POSTS_ORPHAN_MAX_INBOUND . ')' );
ok( 0 === $c['inbound']['value'] && true === $c['flags']['orphaned'], 'a note absent from the graph has 0 inbound (the graph is complete) → orphaned' );
$GLOBALS['__inbound'] = null;
ok( null === row_for( 2 )['inbound']['value'] && false === row_for( 2 )['flags']['orphaned'], 'no graph → gap, and no orphan flag' );
$GLOBALS['__inbound'] = array( '/notes/a' => array( 'inbound' => 10, 'linked_from' => array() ), '/notes/b' => array( 'inbound' => 1, 'linked_from' => array() ) );

echo "\nGroup 5: provenance\n";
$GLOBALS['__chains'][1] = array( array( 'version' => 1, 'status' => 'confirmed', 'bitcoin_block' => 964812, 'pubkey_id' => 'key-2026' ) );
$GLOBALS['__chains'][2] = array( array( 'version' => 1, 'status' => 'pending' ) );
$GLOBALS['__chains'][3] = array( array( 'version' => 1, 'status' => 'confirmed', 'bitcoin_block' => 900000, 'pubkey_id' => 'key-old' ), array( 'version' => 2, 'status' => 'confirmed', 'bitcoin_block' => 965180 ) );
ok( array( 'version' => 1, 'block' => 964812, 'followed_key' => true ) === row_for( 1 )['anchor']['value'], 'anchored by the followed key' );
ok( 'signed, no confirmed anchor yet' === row_for( 2 )['anchor']['why'], 'pending only → gap with the reason' );
ok( 2 === row_for( 3 )['anchor']['value']['version'] && null === row_for( 3 )['anchor']['value']['followed_key'], 'the NEWEST confirmed commit; a missing pubkey_id is null, not "wrong key"' );
$GLOBALS['__chains'][4] = array();
note( 4, 'd', 'Delta', 1, 1 );
ok( 'unsigned' === row_for( 4 )['anchor']['why'], 'no chain → "unsigned"' );

echo "\nGroup 6: the ML kernel\n";
ok( 'kernel not built' === row_for( 1 )['related']['why'], 'no corpus meta → kernel not built' );
$GLOBALS['__opts'][ SNT_ML_CORPUS_META_OPT ] = array( 'built_at' => $NOW - 100 );
$GLOBALS['__meta'][1][ SNT_ML_RELATED_META ] = array( 'x' );
$GLOBALS['__related'][1] = array( array( 'post_id' => 2, 'score' => 0.8123 ), array( 'post_id' => 3, 'score' => 0.41 ) );
ok( array( 'count' => 2, 'top' => 0.81 ) === row_for( 1 )['related']['value'], 'indexed note → count + top score' );
ok( 'not in the kernel yet: built before this note' === row_for( 4 )['related']['why'], 'a note with no related meta → not in the kernel (a gap, not zero related)' );
$GLOBALS['__meta'][2][ SNT_ML_RELATED_META ] = array();
ok( array( 'count' => 0, 'top' => 0.0 ) === row_for( 2 )['related']['value'], 'indexed with no neighbours → a real 0' );

echo "\nGroup 7: views raw; the assembly, counts, strip\n";
$GLOBALS['__lifetime'] = array( '/notes/a' => 14 );
$GLOBALS['__mr'] = array( 'total' => 71627, 'days' => 30, 'stale' => false );
$all = sn_analytics_posts_signals();
ok( 4 === count( $all['rows'] ) && 14 === $all['rows'][0]['views']['value'], 'one row per published note, views raw' );
foreach ( $all['rows'] as $row ) { ok( ! isset( $row['decay'] ) && ! isset( $row['refresh_candidate'] ), 'no shape classification on the row (' . $row['title'] . ')' ); break; }
$c = $all['counts'];
ok( 4 === $c['total'] && 1 === $c['indexed'] && 1 === $c['with_impressions'] && 2 === $c['zero_inbound'] && 1 === $c['stale_crawl'] && 1 === $c['not_indexed'] && 2 === $c['not_inspected'], 'counts: total 4, indexed 1, with impressions 1, zero inbound 2, stale crawl 1, not indexed 1, not inspected 2 (' . json_encode( $c ) . ')' );
ok( 71627 === $all['strip']['machine_reads']['value'] && '2026-08-14' === $all['strip']['gsc_window']['start'] && 2 === $all['strip']['coverage_run']['inspected'], 'the strip carries machine reads (site-wide), the GSC window and the coverage run ONCE' );
$GLOBALS['__mr'] = null;
ok( 'no site-wide measurement yet' === sn_analytics_posts_signals()['strip']['machine_reads']['why'], 'no snapshot → the strip says so, no number' );

echo "\nGroup 7b: stale crawl reads the BODY change, not post_modified (v14.6.1)\n";
// Note 1: crawled 36 days ago, post_modified 3 days ago (Group 3 made that stale).
// Give it a chain whose newest commit is 40 days old: the body has not changed
// since the crawl, so the Sep-9-style bulk save is not a stale crawl.
$GLOBALS['__chains'][1] = array( array( 'version' => 1, 'status' => 'confirmed', 'bitcoin_block' => 964812, 'pubkey_id' => 'key-2026', 'committed_at' => gmdate( 'Y-m-d\TH:i:s\Z', $NOW - 40 * DAY_IN_SECONDS ) ) );
$r1 = row_for( 1 );
ok( false === $r1['flags']['stale_crawl'] && $r1['body_changed_ts'] === $NOW - 40 * DAY_IN_SECONDS, 'signed note, body unchanged since the crawl, post_modified bumped by a bulk save → NOT stale' );
$GLOBALS['__chains'][1][] = array( 'version' => 2, 'status' => 'pending', 'committed_at' => gmdate( 'Y-m-d\TH:i:s\Z', $NOW - 2 * DAY_IN_SECONDS ) );
ok( true === row_for( 1 )['flags']['stale_crawl'], 'a new commit after the crawl (even pending) → stale: the words moved' );
$GLOBALS['__chains'][1] = array( array( 'version' => 1, 'status' => 'confirmed', 'bitcoin_block' => 964812, 'pubkey_id' => 'key-2026' ) );
ok( true === row_for( 1 )['flags']['stale_crawl'] && row_for( 1 )['body_changed_ts'] === $GLOBALS['__posts'][1]->mod, 'a chain with no committed_at falls back to post_modified' );
$GLOBALS['__chains'][1] = array( array( 'version' => 1, 'status' => 'confirmed', 'bitcoin_block' => 964812, 'pubkey_id' => 'key-2026', 'committed_at' => gmdate( 'Y-m-d\TH:i:s\Z', $NOW - 40 * DAY_IN_SECONDS ) ) );

echo "\nGroup 8: severity order for the queue\n";
ok( sn_analytics_posts_severity( array( 'not_indexed' => true ) ) > sn_analytics_posts_severity( array( 'stale_crawl' => true, 'orphaned' => true ) ), 'not indexed outranks stale + orphaned together' );
ok( sn_analytics_posts_severity( array( 'stale_crawl' => true ) ) > sn_analytics_posts_severity( array( 'orphaned' => true ) ), 'stale crawl outranks orphaned' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
