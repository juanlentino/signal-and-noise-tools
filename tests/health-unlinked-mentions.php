<?php
/**
 * Standalone fixture tests for the v7.4.0 unlinked-mentions Health check
 * (sn_health_mention_target_eligible / sn_health_contains_note_link /
 * sn_health_check_unlinked_mentions in inc/health-checks.php).
 *
 * Zero-AI at scan time: published notes whose prose mentions another note's
 * title without linking to /notes/<post_name>. Findings are per
 * (source, target) pair, capped at 5 pairs per source.
 *
 * Run: php tests/health-unlinked-mentions.php
 * @since plugin v7.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return 'https://x.test/?p=' . (int) $id; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://x.test' . $p; } }
// v8.4.1: map-backed option stubs — judged-noise reads the DURABLE verdict
// store now, not flush-volatile transients.
$GLOBALS['__options'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_theme_mod' ) ) { function get_theme_mod( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) { function wp_get_attachment_metadata( $id ) { return array(); } }
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $s ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }

// wpdb fake: returns the configured rows for the mentions scan.
$GLOBALS['__scan_rows'] = array();
class SnMentionsWpdb {
	public $posts = 'wp_posts';
	public function get_results( $sql, $out = null ) { return $GLOBALS['__scan_rows']; }
	public function get_var( $sql ) { return 0; }
	public function get_row( $sql, $out = null ) { return null; }
	public function query( $sql ) { return 0; }
	public function prepare( $sql, ...$args ) { return $sql; }
}
$GLOBALS['wpdb'] = new SnMentionsWpdb();

// Transient stub (v8.1.2): the scan consults cached mention judgments.
$GLOBALS['__transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; } }

require_once __DIR__ . '/../inc/health-checks.php';
require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php'; // locate (v8.1.2 structural suppression)
require_once __DIR__ . '/../inc/ai-link-suggest.php';         // inside-anchor guard (v8.1.2 structural suppression)

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

function mk_row( $id, $title, $name, $content, $modified = '' ) {
	return array( 'ID' => $id, 'post_title' => $title, 'post_name' => $name, 'post_content' => $content, 'post_modified_gmt' => $modified );
}

// ── target eligibility ──
echo "\nTest: sn_health_mention_target_eligible\n";
ok( true === sn_health_mention_target_eligible( 'Honesty has to be the cheap option' ), 'long multi-word title eligible' );
ok( false === sn_health_mention_target_eligible( 'Now' ), 'short title (<12 chars) skipped' );
ok( false === sn_health_mention_target_eligible( 'Antidisestablishment' ), 'one-word title skipped (needs >= 2 words)' );
ok( false === sn_health_mention_target_eligible( 'The Craft' ), 'under-12-chars two-worder skipped' );

// ── boundary-aware link containment ──
echo "\nTest: sn_health_contains_note_link\n";
ok( true === sn_health_contains_note_link( '<a href="/notes/craft/">x</a>', 'craft' ), 'relative /notes/slug/ counts as linked' );
ok( true === sn_health_contains_note_link( '<a href="https://x.test/notes/craft">x</a>', 'craft' ), 'absolute link, quote-terminated, counts' );
ok( false === sn_health_contains_note_link( '<a href="/notes/craft-two/">x</a>', 'craft' ), 'prefix collision /notes/craft-two does NOT count as a link to craft' );
ok( false === sn_health_contains_note_link( 'no links here', 'craft' ), 'no link → false' );
ok( false === sn_health_contains_note_link( 'anything', '' ), 'empty slug → false' );

// ── the check ──
echo "\nTest: sn_health_check_unlinked_mentions\n";
$target  = mk_row( 2, 'Honesty has to be the cheap option', 'cheap-option', '<p>target body</p>' );

// 1) mention without link → one finding with the full suggest contract fields.
$GLOBALS['__scan_rows'] = array(
	mk_row( 1, 'Source note title here', 'source-a', '<p>I wrote that honesty has to be the cheap option in practice.</p>' ),
	$target,
);
$check = sn_health_check_unlinked_mentions();
ok( 1 === $check['count'], 'mention without link flags exactly one pair' );
$f = $check['findings'][0] ?? array();
ok( 1 === ( $f['subject_id'] ?? 0 ), 'finding subject is the SOURCE post' );
ok( 2 === ( $f['target_id'] ?? 0 ), 'finding carries target_id' );
ok( 'honesty has to be the cheap option' === ( $f['mention'] ?? '' ), 'mention preserves the prose casing (not the title casing)' );
ok( '' !== ( $f['context_snippet'] ?? '' ) && false !== stripos( (string) $f['context_snippet'], 'cheap option' ), 'context snippet wraps the mention' );
ok( false !== strpos( (string) ( $f['note'] ?? '' ), '/notes/cheap-option' ), 'note names the missing link target' );
ok( 'Unlinked mentions' === $check['label'], 'check self-labels' );

// 2) mention WITH the link → clean.
$GLOBALS['__scan_rows'] = array(
	mk_row( 1, 'Source note title here', 'source-a', '<p>See <a href="/notes/cheap-option/">honesty has to be the cheap option</a>.</p>' ),
	$target,
);
ok( 0 === sn_health_check_unlinked_mentions()['count'], 'linked mention is clean' );

// 3) prefix-collision link does NOT suppress the finding.
$GLOBALS['__scan_rows'] = array(
	mk_row( 1, 'Source note title here', 'source-a', '<p>honesty has to be the cheap option — and see <a href="/notes/cheap-option-extended/">the sequel</a>.</p>' ),
	$target,
);
ok( 1 === sn_health_check_unlinked_mentions()['count'], 'a link to /notes/cheap-option-extended does not mask the missing cheap-option link' );

// 4) mention only inside markup/attributes → clean (tag-stripped matching).
$GLOBALS['__scan_rows'] = array(
	mk_row( 1, 'Source note title here', 'source-a', '<p>Unrelated prose.</p><img alt="" src="/img/honesty has to be the cheap option.png">' ),
	$target,
);
ok( 0 === sn_health_check_unlinked_mentions()['count'], 'title appearing only inside a tag attribute does not flag' );

// 5) self-mention excluded; short/one-word targets skipped.
$GLOBALS['__scan_rows'] = array(
	mk_row( 2, 'Honesty has to be the cheap option', 'cheap-option', '<p>Honesty has to be the cheap option, I keep saying.</p>' ),
	mk_row( 3, 'Now', 'now-note', '<p>x</p>' ),
	mk_row( 1, 'Source note title here', 'source-a', '<p>Now is mentioned but the target title is too short.</p>' ),
);
ok( 0 === sn_health_check_unlinked_mentions()['count'], 'self-mention + short-title targets produce no findings' );

// 6) cap: 6 eligible unlinked targets → 5 findings for that source.
$rows = array( mk_row( 1, 'Source note title here', 'source-a',
	'<p>alpha beta gamma one. alpha beta gamma two. alpha beta gamma three. alpha beta gamma four. alpha beta gamma five. alpha beta gamma six.</p>' ) );
for ( $i = 1; $i <= 6; $i++ ) {
	$word = array( '', 'one', 'two', 'three', 'four', 'five', 'six' )[ $i ];
	$rows[] = mk_row( 10 + $i, 'alpha beta gamma ' . $word, 'abg-' . $word, '<p>t</p>' );
}
$GLOBALS['__scan_rows'] = $rows;
$check = sn_health_check_unlinked_mentions();
ok( 5 === $check['count'], 'per-source pair cap of 5 honored (6 eligible targets)' );

// 7) empty corpus packs 0, never throws.
$GLOBALS['__scan_rows'] = array();
$check = sn_health_check_unlinked_mentions();
ok( 0 === $check['count'] && is_array( $check['findings'] ), 'empty corpus packs 0' );

echo "\nTest: v8.1.2 — cached non-actionable judgments suppress mention pairs at scan\n";
function link_key( $sid, $tid, $smod ) { return 'sn_link_verdict_' . md5( $sid . '|' . $tid . '|' . $smod ); }
$GLOBALS['__scan_rows'] = array(
	mk_row( 1, 'Source note', 'source-a', '<p>I said honesty has to be the cheap option and meant it.</p>', '2026-07-01 10:00:00' ),
	mk_row( 2, 'Honesty has to be the cheap option', 'cheap-option', '<p>target</p>', '2026-07-01 11:00:00' ),
);
// v8.4.3: one option row PER verdict (the key IS the option name).
function seed_link_verdict( $key, $entry ) { $GLOBALS['__options'] = array( $key => $entry ); }
$GLOBALS['__options'] = array();
$GLOBALS['__transients'] = array();
$check = sn_health_check_unlinked_mentions();
ok( 1 === (int) $check['count'], 'unjudged mention pair still nominates (baseline)' );
seed_link_verdict( link_key( 1, 2, '2026-07-01 10:00:00' ), array( 'verdict' => 'skip', 'reason' => 'r' ) );
$check = sn_health_check_unlinked_mentions();
ok( 0 === (int) $check['count'], 'stored skip verdict suppresses the mention pair' );
// v8.4.1 regression (the persistent-entries bug): a verdict parked in the
// OLD transient location must be invisible to the scan, and a stored
// verdict must survive a transient flush.
$GLOBALS['__transients'] = array( link_key( 1, 2, '2026-07-01 10:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r' ) );
$check = sn_health_check_unlinked_mentions();
ok( 0 === (int) $check['count'], 'suppression reads the store (transient copy irrelevant)' );
$GLOBALS['__transients'] = array();
$check = sn_health_check_unlinked_mentions();
ok( 0 === (int) $check['count'], 'stored verdict SURVIVES a transient flush — judged pairs stay gone' );
seed_link_verdict( link_key( 1, 2, '2026-07-01 10:00:00' ), array( 'verdict' => 'unsure', 'reason' => 'r' ) );
$check = sn_health_check_unlinked_mentions();
ok( 0 === (int) $check['count'], 'stored unsure verdict suppresses the mention pair' );
seed_link_verdict( link_key( 1, 2, '2026-07-01 10:00:00' ), array( 'verdict' => 'link', 'reason' => 'r' ) );
$check = sn_health_check_unlinked_mentions();
ok( 1 === (int) $check['count'], 'stored link verdict keeps the mention pair' );
seed_link_verdict( link_key( 1, 2, '2026-07-01 09:59:59' ), array( 'verdict' => 'skip', 'reason' => 'r' ) );
$check = sn_health_check_unlinked_mentions();
ok( 1 === (int) $check['count'], 'stale-stamp judgment does NOT suppress (content changed => re-nominate)' );
$GLOBALS['__options'] = array();

echo "\nTest: v8.1.2 — structurally un-applyable mentions never nominate\n";
$GLOBALS['__transients'] = array();
$GLOBALS['__scan_rows'] = array(
	mk_row( 1, 'Source note', 'source-a', '<p>See <a href="/notes/other">honesty has to be the cheap option</a> here.</p>', '2026-07-01 10:00:00' ),
	mk_row( 2, 'Honesty has to be the cheap option', 'cheap-option', '<p>target</p>', '2026-07-01 11:00:00' ),
);
$check = sn_health_check_unlinked_mentions();
ok( 0 === (int) $check['count'], 'mention inside an existing <a> never nominates (advice-only is noise, owner rule)' );

echo "\nTest: v8.4.4 — judged mentions CONSUME cap slots (one Suggest All pass converges)\n";
// Same treadmill as link_opportunities: judged suppression ran before the
// per-source cap, so judging the rendered 5 freed their slots and the next
// scan PROMOTED the 6th eligible target. A judged mention must occupy its
// slot so the source goes quiet after one judging pass.
$rows = array( mk_row( 1, 'Source note title here', 'source-a',
	'<p>alpha beta gamma one. alpha beta gamma two. alpha beta gamma three. alpha beta gamma four. alpha beta gamma five. alpha beta gamma six.</p>',
	'2026-07-01 10:00:00' ) );
for ( $i = 1; $i <= 6; $i++ ) {
	$word = array( '', 'one', 'two', 'three', 'four', 'five', 'six' )[ $i ];
	$rows[] = mk_row( 10 + $i, 'alpha beta gamma ' . $word, 'abg-' . $word, '<p>t</p>', '2026-07-01 0' . $i . ':00:00' );
}
$GLOBALS['__scan_rows'] = $rows;
$GLOBALS['__options'] = array();
$check = sn_health_check_unlinked_mentions();
ok( 5 === (int) $check['count'], 'baseline: first 5 eligible mentions render, 6th capped out' );
// Judge ALL FIVE rendered pairs as skip: the re-scan must go quiet — no
// promotion of the never-rendered 6th target into the freed slots.
$seed = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$seed[ link_key( 1, 10 + $i, '2026-07-01 10:00:00' ) ] = array( 'verdict' => 'skip', 'reason' => 'r' );
}
$GLOBALS['__options'] = $seed;
$check = sn_health_check_unlinked_mentions();
ok( 0 === (int) $check['count'], 'all 5 judged: source goes quiet in ONE pass (no promotion of the 6th)' );
// Partial judging: 2 of 5 judged → the 3 unjudged rendered pairs remain,
// judged slots stay consumed, the 6th still never promotes.
$seed = array(
	link_key( 1, 11, '2026-07-01 10:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r' ),
	link_key( 1, 12, '2026-07-01 10:00:00' ) => array( 'verdict' => 'skip', 'reason' => 'r' ),
);
$GLOBALS['__options'] = $seed;
$check = sn_health_check_unlinked_mentions();
$targets = array();
foreach ( $check['findings'] as $ff ) { $targets[] = (int) $ff['target_id']; }
sort( $targets );
ok( array( 13, 14, 15 ) === $targets, 'partial judging: judged slots stay consumed, 6th target does not backfill' );
$GLOBALS['__options'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
