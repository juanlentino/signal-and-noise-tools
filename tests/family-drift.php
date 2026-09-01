<?php
/**
 * Family-drift check — measurement weave Phase 5 (v13.62.0).
 *
 * Properties: (1) the worker enum parses from the REAL deployed source
 * (fixture captured at efc6463), all 18 rows including the multi-line
 * other-bot, and every plugin family but the documented exemption is in it;
 * (2) the diff is derived — a planted fake family reds mirror_parity AND
 * ours_unmatched (the negative control the plan demanded); (3) a respect flip
 * and a vocabulary change against the PIN surface as drift, never absorbed;
 * (4) FAIL-CLOSED: any failed fetch yields status:unavailable and keeps the
 * last good report, never an empty diff; (5) the verdict: mirror unequal is
 * CRITICAL, stale is recommended, good names the counts; (6) wiring: cron
 * hook owned + scheduled weekly, Site Health direct test, the ability reads
 * without fetching.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__hooks'] = array();
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $t, $c ); return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $t, $c ); return true; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
$GLOBALS['__sched'] = array();
function wp_next_scheduled( $h ) { return $GLOBALS['__sched'][ $h ] ?? false; }
function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['__sched'][ $h ] = array( $ts, $rec ); return true; }
function wp_http_validate_url( $u ) { return (bool) filter_var( $u, FILTER_VALIDATE_URL ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
class WP_Error { public $c; public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
// The network seam: url => body (string) | null (failure).
$GLOBALS['__net'] = array(); $GLOBALS['__fetched'] = array();
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__fetched'][] = $url; $b = $GLOBALS['__net'][ $url ] ?? null; return null === $b ? new WP_Error( 'x' ) : array( 'code' => 200, 'body' => $b ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function snt_mr_valid_families() { return $GLOBALS['__plugin_enum']; }
const SN_MR_DEFAULT_ENDPOINT = 'https://example.test/_sn/rights-signals/machine-readers';
function snt_ability_perm_manage_options() { return true; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }

require_once __DIR__ . '/../inc/family-drift.php';
require_once __DIR__ . '/../inc/abilities-family-drift.php';

echo "family drift — v13.62.0\n\n";
$FX  = __DIR__ . '/fixtures/family-drift';
$src = (string) file_get_contents( $FX . '/machine-readers.mjs' );
$PLUGIN = array( 'openai', 'anthropic', 'google-ai', 'perplexity', 'commoncrawl', 'bytedance', 'amazon-ai', 'apple-ai', 'meta-ai', 'mistral', 'cohere', 'allen-ai', 'diffbot', 'search', 'seo', 'feed', 'uptime', 'other-bot', 'unclassified-machine' );
$GLOBALS['__plugin_enum'] = $PLUGIN;

// ─── (1) the worker enum, from the real deployed source ───
ok( strlen( $src ) > 10000, 'vacuity: the captured worker source is real (' . strlen( $src ) . ' bytes)' );
$enum = sn_family_drift_parse_worker_enum( $src );
ok( is_array( $enum ) && 18 === count( $enum ), 'parses 18 rows, including the multi-line other-bot' );
ok( array_keys( $enum ) === array_values( array_diff( $PLUGIN, array( 'unclassified-machine' ) ) ), 'the parsed worker enum equals the plugin enum minus the documented exemption, IN ORDER' );
ok( 'openai' === sn_family_drift_classify( $enum, array( 'Mozilla/5.0 GPTBot/1.0' ) ) && 'other-bot' === sn_family_drift_classify( $enum, array( 'curl/8.0' ) ) && null === sn_family_drift_classify( $enum, array( 'Mozilla/5.0 (Macintosh)' ) ), 'the JS regexes run in PHP: GPTBot→openai, curl→other-bot, a browser→null' );
ok( null === sn_family_drift_parse_worker_enum( 'export const NOTHING = [];' ) && null === sn_family_drift_parse_worker_enum( '' ), 'no table → null, never [] (an empty enum would read as total drift)' );

// ─── (2) the diff, and the NEGATIVE CONTROL ───
$pin = sn_family_drift_pinned();
ok( is_array( $pin ) && isset( $pin['ua_patterns'], $pin['ai_agents'] ) && ! empty( $pin['sources']['crawler_user_agents']['commit'] ), 'vacuity: the vendored pin loads and records its upstream commits' );
$ua = json_decode( (string) file_get_contents( $FX . '/upstream-ua.json' ), true );
$ai = json_decode( (string) file_get_contents( $FX . '/upstream-ai.json' ), true );
$r  = sn_family_drift_compute( $PLUGIN, $enum, $ua, $ai, $pin );
ok( true === $r['mirror_parity']['ok'] && array() === $r['mirror_parity']['plugin_only'] && array() === $r['mirror_parity']['worker_only'] && true === $r['mirror_parity']['order_ok'], 'mirror_parity ok: sets and order agree; unclassified-machine exempt' );
ok( array( 'unclassified-machine' ) === $r['mirror_parity']['exempt'], 'the exemption is stated in the payload' );
$expected_unmatched = array_values( array_diff( array_keys( $enum ), array( 'openai', 'search', 'meta-ai', 'seo', 'other-bot' ) ) );
// upstream-ua.json carries GPTBot (openai), Googlebot (search), facebookexternalhit (→ meta-ai? no: meta-ai matches meta-external|facebookbot; facebookexternalhit hits neither → other-bot? 'facebookexternalhit' has no 'bot' token... → null), Nmap (null), AhrefsBot (seo).
$expected_unmatched = array_values( array_diff( array_keys( $enum ), array( 'openai', 'search', 'seo', 'other-bot' ) ) );
ok( $expected_unmatched === $r['ours_unmatched'], 'ours_unmatched: every family the tiny corpus does not exercise, other-bot excluded (' . count( $r['ours_unmatched'] ) . ')' );
ok( array() === $r['upstream_unmapped'], 'upstream_unmapped: tags with fewer than ' . SN_FAMILY_DRIFT_UNMAPPED_MIN . ' entries are not evidence' );
$ua5 = $ua; for ( $i = 0; $i < 6; $i++ ) { $ua5[] = array( 'pattern' => "scan$i", 'tags' => array( 'scanner' ), 'instances' => array( "Scanner$i/1.0" ) ); }
$r5 = sn_family_drift_compute( $PLUGIN, $enum, $ua5, $ai, $pin );
ok( array( 'scanner' => 7 ) === $r5['upstream_unmapped'], 'upstream_unmapped: a tag with 7 entries none of ours claims is listed with its count' );
ok( array( 'Nova Labs' => array( 'NovaCrawler' ) ) === $r['vendor_gap'], 'vendor_gap: the one operator whose agent no AI family matches (GPTBot→openai, ClaudeBot→anthropic claimed)' );

// NEGATIVE CONTROL: plant a fake family in the plugin enum.
$planted = array_merge( $PLUGIN, array( 'quantum-toaster' ) );
$rp = sn_family_drift_compute( $planted, $enum, $ua, $ai, $pin );
ok( false === $rp['mirror_parity']['ok'] && array( 'quantum-toaster' ) === $rp['mirror_parity']['plugin_only'], 'NEGATIVE CONTROL: a planted plugin-only family reds mirror_parity and is named' );
$enum_planted = $enum + array( 'quantum-toaster' => '~quantumtoaster~iu' );
$rq = sn_family_drift_compute( $PLUGIN, $enum_planted, $ua, $ai, $pin );
ok( array( 'quantum-toaster' ) === $rq['mirror_parity']['worker_only'] && in_array( 'quantum-toaster', $rq['ours_unmatched'], true ), 'NEGATIVE CONTROL: a worker-only family reds mirror_parity AND appears in ours_unmatched (it classifies nothing)' );
// order drift with equal sets
$enum_swapped = array_reverse( $enum, true );
$ro = sn_family_drift_compute( $PLUGIN, $enum_swapped, $ua, $ai, $pin );
ok( false === $ro['mirror_parity']['ok'] && false === $ro['mirror_parity']['order_ok'] && array() === $ro['mirror_parity']['plugin_only'], 'equal sets in a different ORDER is still not parity (order is the classifier precedence)' );

// ─── (3) drift against the PIN ───
$ai_flip = $ai; $ai_flip['GPTBot']['respect'] = 'No';
$pin_small = array( 'ua_patterns' => array( 'GPTBot' => array( 'ai-crawler' ) ), 'ai_agents' => array( 'GPTBot' => array( 'respect' => 'Yes' ), 'GoneBot' => array( 'respect' => 'Yes' ) ), 'sources' => array() );
$rf = sn_family_drift_compute( $PLUGIN, $enum, $ua, $ai_flip, $pin_small );
ok( array( 'GPTBot' => array( 'from' => 'Yes', 'to' => 'No' ) ) === $rf['respect_flips'], 'respect_flips: GPTBot Yes→No against the pin' );
ok( array( 'search-engine', 'social-preview', 'scanner', 'seo' ) === $rf['vocabulary']['tags_added'] && array() === $rf['vocabulary']['tags_removed'] && 2 === $rf['vocabulary']['agents_added'] && 1 === $rf['vocabulary']['agents_removed'], 'vocabulary: tags/agents added and removed vs the pin are surfaced, never absorbed' );

// ─── (4) FAIL-CLOSED run ───
$V   = 'https://example.test/_sn/rights-signals/version';
$RAW = sprintf( SN_FAMILY_DRIFT_WORKER_RAW, 'efc64634c12af53e7fc91ac692de1e8265cb14c1' );
ok( $V === sn_family_drift_worker_version_url(), 'the /version URL derives from the machine-readers endpoint' );
$GLOBALS['__net'] = array( $V => json_encode( array( 'source_commit' => 'efc64634c12af53e7fc91ac692de1e8265cb14c1', 'version' => '1.24.0' ) ), $RAW => $src, SN_FAMILY_DRIFT_UA_URL => json_encode( $ua ), SN_FAMILY_DRIFT_AI_URL => json_encode( $ai ) );
$run = sn_family_drift_run();
ok( 'ok' === $run['status'] && 'efc6463' === substr( $run['sources']['worker_commit'], 0, 7 ) && '1.24.0' === $run['sources']['worker_version'], 'a full run: status ok, the deployed commit + version recorded' );
ok( $GLOBALS['__fetched'] === array( $V, $RAW, SN_FAMILY_DRIFT_UA_URL, SN_FAMILY_DRIFT_AI_URL ), 'exactly four fetches, version first (the commit gates the source URL)' );
ok( $run === get_option( SN_FAMILY_DRIFT_OK_OPTION ) && $run === get_option( SN_FAMILY_DRIFT_LAST_OPTION ), 'stored as both last and last_ok' );
$GLOBALS['__net'][ SN_FAMILY_DRIFT_UA_URL ] = null; $GLOBALS['__fetched'] = array();
$bad = sn_family_drift_run();
ok( 'unavailable' === $bad['status'] && 'upstream_ua' === $bad['error'], 'FAIL-CLOSED: one failed fetch → unavailable, naming the source' );
ok( 'ok' === get_option( SN_FAMILY_DRIFT_OK_OPTION )['status'] && 'unavailable' === get_option( SN_FAMILY_DRIFT_LAST_OPTION )['status'], 'the last good report is KEPT beside the failed attempt' );
ok( ! array_key_exists( 'mirror_parity', $bad ), 'an unavailable record carries NO diff rows — never an empty diff' );
$GLOBALS['__net'][ SN_FAMILY_DRIFT_UA_URL ] = json_encode( $ua ); $GLOBALS['__net'][ $V ] = json_encode( array( 'source_commit' => 'not-a-sha' ) );
ok( 'worker_version' === sn_family_drift_run()['error'], 'a malformed source_commit is a failure, not a URL' );
$GLOBALS['__net'][ $V ] = json_encode( array( 'source_commit' => 'efc64634c12af53e7fc91ac692de1e8265cb14c1' ) ); $GLOBALS['__net'][ $RAW ] = 'export const NOTHING = [];';
ok( 'worker_source' === sn_family_drift_run()['error'], 'a source with no table is a failure' );

// ─── (5) the verdict ───
$NOW = 1_800_000_000;
$good_ok = array_merge( $run, array( 'computed_at' => $NOW - DAY_IN_SECONDS ) );
$h = sn_family_drift_health( array( 'last' => null, 'last_ok' => null ), $NOW );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], 'never run' ), 'never run → recommended' );
$h = sn_family_drift_health( array( 'last' => $bad, 'last_ok' => null ), $NOW );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], 'upstream_ua' ), 'never completed → recommended, naming the failed source' );
$h = sn_family_drift_health( array( 'last' => $good_ok, 'last_ok' => $good_ok ), $NOW );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], 'classif' ), 'fresh, parity ok, but families classify nothing in the tiny corpus → recommended (ours_unmatched)' );
$clean = $good_ok; $clean['ours_unmatched'] = array();
$h = sn_family_drift_health( array( 'last' => $clean, 'last_ok' => $clean ), $NOW );
ok( 'good' === $h['status'] && false !== strpos( $h['summary'], '18 families' ) && false !== strpos( $h['summary'], 'efc6463' ), 'good: names the family count and the deployed commit' );
$stale = $clean; $stale['computed_at'] = $NOW - SN_FAMILY_DRIFT_STALE_SECS - 1;
$h = sn_family_drift_health( array( 'last' => $bad, 'last_ok' => $stale ), $NOW );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], '14 days old' ) && false !== strpos( $h['summary'], 'upstream_ua' ), 'stale by one second past 14 days → recommended, and says the latest attempt failed' );
$split = $clean; $split['mirror_parity'] = $rp['mirror_parity'];
$h = sn_family_drift_health( array( 'last' => $split, 'last_ok' => $split ), $NOW );
ok( 'critical' === $h['status'] && false !== strpos( $h['summary'], 'quantum-toaster' ), 'MIRROR PARITY FAILED is CRITICAL — a RED, not a note — and names the family' );

// ─── (6) wiring ───
$tags = array_map( static fn( $h ) => $h[0], $GLOBALS['__hooks'] );
ok( in_array( 'site_status_tests', $tags, true ) && in_array( SN_FAMILY_DRIFT_HOOK, $tags, true ) && in_array( 'init', $tags, true ), 'hooks: Site Health test, the cron action, and the init scheduler' );
foreach ( $GLOBALS['__hooks'] as $hk ) { if ( 'init' === $hk[0] ) { $hk[1](); } }
ok( 'weekly' === ( $GLOBALS['__sched'][ SN_FAMILY_DRIFT_HOOK ][1] ?? '' ), 'init schedules the check WEEKLY, always-on (no opt-in gate)' );
$row = sn_family_drift_site_health_result();
ok( 'sn_family_drift' === $row['test'] && in_array( $row['status'], array( 'good', 'recommended', 'critical' ), true ), 'Site Health row reads the stored report' );
foreach ( $GLOBALS['__hooks'] as $hk ) { if ( 'wp_abilities_api_init' === $hk[0] ) { $hk[1](); } }
$reg = $GLOBALS['__registered']['signal-noise/family-drift'] ?? null;
ok( is_array( $reg ) && true === $reg['meta']['annotations']['readonly'] && 'snt_ability_perm_manage_options' === $reg['permission_callback'], 'the ability registers readonly + manage_options' );
$GLOBALS['__fetched'] = array();
$out = snt_ability_family_drift( null );
ok( true === $out['ok'] && is_array( $out['last'] ) && array() === $GLOBALS['__fetched'], 'the ability reads the stored report and performs NO fetch' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
