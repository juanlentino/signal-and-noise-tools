<?php
/**
 * Standalone fixture tests for the v8.1.0 link_opportunities Health check
 * (inc/health-link-opportunities.php): zero-AI semantic-pair candidates from
 * shared tags + lexical TF-IDF overlap, advisory tier, unlinked_mentions
 * dedupe (title-mention pairs stay with that check).
 *
 * Run: php tests/health-link-opportunities.php
 * @since plugin v8.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return 'https://x.test/?p=' . (int) $id; } }
// v8.4.1: map-backed option stubs — judged-noise reads the DURABLE verdict
// store now, not flush-volatile transients.
$GLOBALS['__options'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_theme_mod' ) ) { function get_theme_mod( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) { function wp_get_attachment_metadata( $id ) { return array(); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $s ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://x.test' . $p; } }

// Tag stub: post_id => term_id[] (the check asks for fields => ids).
$GLOBALS['__tags'] = array();
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $id, $tax = 'post_tag', $args = array() ) { return $GLOBALS['__tags'][ (int) $id ] ?? array(); }
}

// Transient stub (v8.1.2): the scan consults cached pair judgments.
$GLOBALS['__transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; } }

// wpdb fake: returns the configured rows for the pairs scan.
class SnPairsWpdb {
	public $posts = 'wp_posts';
	public $rows  = array();
	public function get_results( $sql, $output = null ) { return $this->rows; }
}
$GLOBALS['wpdb'] = new SnPairsWpdb();

require_once __DIR__ . '/../inc/health-checks.php';            // pack_check + contains_note_link + mention_target_eligible
require_once __DIR__ . '/../inc/health-summary.php';           // advisory tier list
require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php';  // locate + fingerprint (nomination validation)
require_once __DIR__ . '/../inc/ai-link-suggest.php';          // inside-anchor guard + anchor max const
require_once __DIR__ . '/../inc/ai-pair-suggest.php';          // snt_ai_pair_nomination_contract (v8.1.2 scan suppression)
require_once __DIR__ . '/../inc/health-link-opportunities.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

function mk_row( $id, $title, $name, $content, $modified = '' ) {
	return array( 'ID' => $id, 'post_title' => $title, 'post_name' => $name, 'post_content' => $content, 'post_modified_gmt' => $modified );
}

// Distinctive-prose builders. Four terms (compression, sidechain, saturation,
// headroom) appear ONLY in the two audio posts => df=2 of N => positive idf =>
// both posts carry them in their top terms => 4 shared >= the 3-term floor.
$audio_prose_a = '<p>Notes on compression and sidechain moves. Compression with saturation for headroom. Sidechain saturation keeps headroom honest. Compression again.</p>';
$audio_prose_b = '<p>Deep dive: compression basics, sidechain routing, saturation stages, headroom budgets. Compression sidechain saturation headroom throughout.</p>';
$coffee_prose  = '<p>Grinder settings, bloom timing, pourover ratios, kettle temperature. Grinder bloom pourover kettle. Grinder pourover.</p>';
$generic_prose = '<p>Some thoughts about things that happened. Words about various matters with nothing shared.</p>';

echo "link-opportunities suite - plugin v8.1.0\n";

// ── Scenario 1: lexical pair (no shared tag) nominates; generic does not ──
echo "\nTest: lexical-overlap pair nominates (the discovery win)\n";
$GLOBALS['wpdb']->rows = array(
	mk_row( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud', $audio_prose_a ), // newest
	mk_row( 2, 'Console Craft', 'console-craft', $audio_prose_b ),
	mk_row( 3, 'Coffee Brewing Notes', 'coffee-brewing', $coffee_prose ),
	mk_row( 4, 'Sundry Observations', 'sundry', $generic_prose ),            // oldest
);
$GLOBALS['__tags'] = array();
$check = sn_health_check_link_opportunities();
ok( 1 === (int) $check['count'], 'exactly one candidate pair (the two audio notes)' );
$f = $check['findings'][0] ?? array();
ok( 1 === ( $f['subject_id'] ?? 0 ), 'subject is the NEWER note (rows are date-DESC)' );
ok( 2 === ( $f['target_id'] ?? 0 ), 'target is the older note' );
ok( false !== strpos( (string) ( $f['note'] ?? '' ), 'Console Craft' ), 'note names the target' );
ok( isset( $f['edit_url'] ) && false !== strpos( $f['edit_url'], 'post=1' ), 'edit link points at the source' );

echo "\nTest: shared-tag pair nominates even without lexical overlap\n";
$GLOBALS['__tags'] = array( 1 => array( 10 ), 3 => array( 10 ) ); // audio A + coffee share a tag
$check = sn_health_check_link_opportunities();
ok( 2 === (int) $check['count'], 'tag pair joins the lexical pair (two candidates)' );
$pairs = array();
foreach ( $check['findings'] as $ff ) { $pairs[] = $ff['subject_id'] . '>' . $ff['target_id']; }
ok( in_array( '1>3', $pairs, true ), 'tag-sibling pair (1,3) nominated with newer note as subject' );

echo "\nTest: already-linked pairs are skipped (either direction)\n";
$GLOBALS['wpdb']->rows = array(
	mk_row( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud', $audio_prose_a . '<p><a href="/notes/console-craft">see also</a></p>' ),
	mk_row( 2, 'Console Craft', 'console-craft', $audio_prose_b ),
);
$GLOBALS['__tags'] = array();
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'source already links target: skipped' );
$GLOBALS['wpdb']->rows = array(
	mk_row( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud', $audio_prose_a ),
	mk_row( 2, 'Console Craft', 'console-craft', $audio_prose_b . '<p><a href="/notes/mixing-vocals-loud">reply</a></p>' ),
);
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'target already links source (reverse): connected pair skipped' );

echo "\nTest: title-mention pairs stay with unlinked_mentions (dedupe)\n";
$GLOBALS['wpdb']->rows = array(
	mk_row( 5, 'Fresh Note', 'fresh-note', $audio_prose_a . '<p>As I said in Console Craft earlier.</p>' ),
	mk_row( 2, 'Console Craft', 'console-craft', $audio_prose_b ),
);
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'eligible title mention in source prose: pair skipped (unlinked_mentions territory)' );

echo "\nTest: short (ineligible) title mention does NOT trigger the dedupe skip\n";
// "Console Craft" is 13 chars 2 words = eligible. Use an ineligible-title
// target: unlinked_mentions would never flag it, so we must still nominate.
// Corpus padded with the unrelated posts: in a 2-doc corpus every shared
// term has df=total => idf=0 => the lexical signal is (deliberately) zero.
$GLOBALS['wpdb']->rows = array(
	mk_row( 5, 'Fresh Note', 'fresh-note', $audio_prose_a . '<p>More on Craft soon.</p>' ),
	mk_row( 6, 'Craft', 'craft', $audio_prose_b ),
	mk_row( 3, 'Coffee Brewing Notes', 'coffee-brewing', $coffee_prose ),
	mk_row( 4, 'Sundry Observations', 'sundry', $generic_prose ),
);
$check = sn_health_check_link_opportunities();
ok( 1 === (int) $check['count'], 'ineligible-title target still nominates (no coverage gap between the two checks)' );

echo "\nTest: per-source cap\n";
$GLOBALS['wpdb']->rows = array(
	mk_row( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud', $audio_prose_a ),
	mk_row( 21, 'Older One', 'older-one', $coffee_prose ),
	mk_row( 22, 'Older Two', 'older-two', $generic_prose ),
	mk_row( 23, 'Older Three', 'older-three', '<p>Entirely different words here, unrelated content follows.</p>' ),
	mk_row( 24, 'Older Four', 'older-four', '<p>Another unrelated body of text with no overlap.</p>' ),
);
$GLOBALS['__tags'] = array( 1 => array( 10 ), 21 => array( 10 ), 22 => array( 10 ), 23 => array( 10 ), 24 => array( 10 ) );
$check = sn_health_check_link_opportunities();
$from_one = 0;
foreach ( $check['findings'] as $ff ) { if ( 1 === $ff['subject_id'] ) { $from_one++; } }
ok( SN_HEALTH_PAIRS_MAX_PER_SOURCE === $from_one, 'source capped at SN_HEALTH_PAIRS_MAX_PER_SOURCE pairs' );

echo "\nTest: advisory tier registration\n";
ok( in_array( 'link_opportunities', sn_health_advisory_checks(), true ), 'link_opportunities is advisory-tier' );

echo "\nTest: empty / single-post corpus packs 0\n";
$GLOBALS['wpdb']->rows = array();
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'] && 'Link opportunities' === $check['label'], 'empty corpus: zero findings, label intact' );
$GLOBALS['wpdb']->rows = array( mk_row( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud', $audio_prose_a ) );
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'single post: zero findings' );

echo "\nTest: v8.1.2 — cached non-actionable judgments suppress pairs at scan (owner noise rule)\n";
function pair_key( $sid, $tid, $smod, $tmod ) { return 'sn_pair_verdict_' . md5( $sid . '|' . $tid . '|' . $smod . '|' . $tmod ); }
$GLOBALS['wpdb']->rows = array(
	mk_row( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud', $audio_prose_a, '2026-07-01 10:00:00' ),
	mk_row( 2, 'Console Craft', 'console-craft', $audio_prose_b, '2026-07-01 11:00:00' ),
	mk_row( 3, 'Coffee Brewing Notes', 'coffee-brewing', $coffee_prose, '2026-07-01 12:00:00' ),
	mk_row( 4, 'Sundry Observations', 'sundry', $generic_prose, '2026-07-01 13:00:00' ),
);
// v8.4.3: one option row PER verdict (the key IS the option name).
function seed_pair_verdict( $key, $entry ) { $GLOBALS['__options'] = array( $key => $entry ); }
$GLOBALS['__tags'] = array();
seed_pair_verdict( pair_key( 1, 2, '2026-07-01 10:00:00', '2026-07-01 11:00:00' ), array( 'verdict' => 'skip', 'reason' => 'r', 'anchor' => '' ) );
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'stored skip verdict suppresses the pair' );
// v8.4.1 regression (the persistent-entries bug): the verdict survives a
// transient flush, and a verdict parked in the OLD transient location is
// invisible to the scan.
$GLOBALS['__transients'] = array();
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'stored verdict SURVIVES a transient flush — judged pairs stay gone' );
$GLOBALS['__options'] = array();
$GLOBALS['__transients'] = array( pair_key( 1, 2, '2026-07-01 10:00:00', '2026-07-01 11:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r', 'anchor' => '' ) );
$check = sn_health_check_link_opportunities();
ok( 1 === (int) $check['count'], 'old transient location is IGNORED (store is the only memory)' );
$GLOBALS['__transients'] = array();
seed_pair_verdict( pair_key( 1, 2, '2026-07-01 10:00:00', '2026-07-01 11:00:00' ), array( 'verdict' => 'unsure', 'reason' => 'r', 'anchor' => '' ) );
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'stored unsure verdict suppresses the pair' );
seed_pair_verdict( pair_key( 1, 2, '2026-07-01 10:00:00', '2026-07-01 11:00:00' ), array( 'verdict' => 'link', 'reason' => 'r', 'anchor' => 'compression' ) );
$check = sn_health_check_link_opportunities();
ok( 1 === (int) $check['count'], 'stored link verdict with a valid nomination KEEPS the pair' );
seed_pair_verdict( pair_key( 1, 2, '2026-07-01 10:00:00', '2026-07-01 11:00:00' ), array( 'verdict' => 'link', 'reason' => 'r', 'anchor' => 'phrase appearing nowhere' ) );
$check = sn_health_check_link_opportunities();
ok( 0 === (int) $check['count'], 'stored link verdict whose nomination no longer validates suppresses (advice-only = noise)' );
seed_pair_verdict( pair_key( 1, 2, '2026-07-01 09:59:59', '2026-07-01 11:00:00' ), array( 'verdict' => 'skip', 'reason' => 'r', 'anchor' => '' ) );
$check = sn_health_check_link_opportunities();
ok( 1 === (int) $check['count'], 'stale-stamp judgment does NOT suppress (content changed => re-nominate)' );
$GLOBALS['__options'] = array();

echo "\nTest: v8.4.4 — judged pairs CONSUME cap slots (one Suggest All pass converges)\n";
// The owner-reported treadmill: suppression ran BEFORE the per-source cap,
// so judging the rendered top-3 freed their slots and every re-scan
// PROMOTED the next-ranked unjudged candidates — Suggest All → Re-run →
// Suggest All → … never converged. A judged pair must now occupy its cap
// slot (render nothing), so the cap means "top-N SCORED candidates" and
// one judging pass reaches quiet. Distinct shared-tag counts pin the
// ranking: 31 (4 tags, score 12) > 32 (3, 9) > 33 (2, 6) > 34 (1, 3).
$GLOBALS['wpdb']->rows = array(
	mk_row( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud', $audio_prose_a, '2026-07-01 10:00:00' ),
	mk_row( 31, 'Older One', 'older-one', $coffee_prose, '2026-07-01 01:00:00' ),
	mk_row( 32, 'Older Two', 'older-two', $generic_prose, '2026-07-01 02:00:00' ),
	mk_row( 33, 'Older Three', 'older-three', '<p>Entirely different words here, unrelated content follows.</p>', '2026-07-01 03:00:00' ),
	mk_row( 34, 'Older Four', 'older-four', '<p>Another unrelated body of text with no overlap.</p>', '2026-07-01 04:00:00' ),
);
$GLOBALS['__tags'] = array(
	1  => array( 10, 11, 12, 13 ),
	31 => array( 10, 11, 12, 13 ),
	32 => array( 10, 11, 12 ),
	33 => array( 10, 11 ),
	34 => array( 10 ),
);
function subject_one_targets( $check ) {
	$t = array();
	foreach ( $check['findings'] as $ff ) { if ( 1 === $ff['subject_id'] ) { $t[] = (int) $ff['target_id']; } }
	return $t;
}
// Baseline: unjudged, the cap keeps the 3 strongest (31, 32, 33); 34 is out.
$GLOBALS['__options'] = array();
$targets = subject_one_targets( sn_health_check_link_opportunities() );
sort( $targets );
ok( array( 31, 32, 33 ) === $targets, 'baseline: top-3 scored candidates render, weakest capped out' );
// Judge ALL THREE rendered pairs as skip: the re-scan must go QUIET for
// this source — no promotion of the never-rendered candidate 34.
$GLOBALS['__options'] = array(
	pair_key( 1, 31, '2026-07-01 10:00:00', '2026-07-01 01:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r', 'anchor' => '' ),
	pair_key( 1, 32, '2026-07-01 10:00:00', '2026-07-01 02:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r', 'anchor' => '' ),
	pair_key( 1, 33, '2026-07-01 10:00:00', '2026-07-01 03:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r', 'anchor' => '' ),
);
$targets = subject_one_targets( sn_health_check_link_opportunities() );
ok( array() === $targets, 'all top-3 judged: source goes quiet in ONE pass (no promotion)' );
// Judge only the strongest: its slot stays consumed (hidden), the other two
// rendered pairs remain, and candidate 34 still does NOT promote.
$GLOBALS['__options'] = array(
	pair_key( 1, 31, '2026-07-01 10:00:00', '2026-07-01 01:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r', 'anchor' => '' ),
);
$targets = subject_one_targets( sn_health_check_link_opportunities() );
sort( $targets );
ok( array( 32, 33 ) === $targets, 'partial judging: judged slot stays consumed, unjudged candidate does not backfill' );
$GLOBALS['__options'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
