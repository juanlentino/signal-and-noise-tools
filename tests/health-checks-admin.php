<?php
/**
 * Standalone fixture tests for inc/health-checks-admin.php.
 *
 * Covers the v6.39.2 "Suggest all" cost cap: the batch button's label must show
 * min(count, SNT_AI_SUGGEST_ALL_MAX) and carry the cap as a data attribute the
 * JS reads, so one click can fire at most SNT_AI_SUGGEST_ALL_MAX AI calls.
 *
 * @since plugin v6.39.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $f, $t = 0 ) { return '5 minutes'; } }

require_once __DIR__ . '/../inc/health-summary.php'; // finding-total + flagged-checks accessors the glance hero shares
require_once __DIR__ . '/../inc/health-checks-admin.php';

$pass = 0; $fail = 0;
function hca_true( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
function hca_eq( $e, $a, $msg ) { global $pass, $fail; if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n"; } }

echo "health-checks-admin suite — plugin v6.39.2\n";

echo "\nTest: SNT_AI_SUGGEST_ALL_MAX defined\n";
hca_true( defined( 'SNT_AI_SUGGEST_ALL_MAX' ), 'cap constant exists' );
hca_eq( 50, defined( 'SNT_AI_SUGGEST_ALL_MAX' ) ? SNT_AI_SUGGEST_ALL_MAX : null, 'cap is 50' );

echo "\nTest: snt_health_suggest_all_button_html caps the label + emits the cap\n";

// Below the cap — label shows the true count.
$html = snt_health_suggest_all_button_html( 10 );
hca_true( false !== strpos( $html, 'Suggest all 10' ), 'count 10 → "Suggest all 10"' );
hca_true( false !== strpos( $html, 'data-snt-suggest-all="1"' ), 'carries the suggest-all hook attribute' );
hca_true( false !== strpos( $html, 'data-snt-suggest-all-max="50"' ), 'carries the cap as a data attribute' );

// At the cap.
$html = snt_health_suggest_all_button_html( 50 );
hca_true( false !== strpos( $html, 'Suggest all 50' ), 'count 50 → "Suggest all 50"' );

// Above the cap — label is clamped to the cap, never the raw count.
$html = snt_health_suggest_all_button_html( 73 );
hca_true( false !== strpos( $html, 'Suggest all 50' ), 'count 73 → label clamped to "Suggest all 50"' );
hca_true( false === strpos( $html, 'Suggest all 73' ), 'raw over-cap count 73 is NOT shown' );

// A single finding.
$html = snt_health_suggest_all_button_html( 1 );
hca_true( false !== strpos( $html, 'Suggest all 1' ), 'count 1 → "Suggest all 1"' );

// ── snt_health_glance_cards(): the Health-tab hero. Characterization tests that
// pin its behavior so converging its inline finding-total onto the shared
// sn_health_finding_total() / sn_health_flagged_checks() accessors stays identical.
echo "\nTest: snt_health_glance_cards no-scan\n";
$ns = snt_health_glance_cards( null );
hca_eq( 1, count( $ns ), 'no-scan → one card' );
hca_eq( 'no scan', $ns[0]['value'], 'no-scan card value is "no scan"' );

echo "\nTest: snt_health_glance_cards with findings\n";
$scan = array(
	'scanned_at' => time() - 300,
	'elapsed_ms' => 900,
	'checks'     => array(
		'missing_alt'    => array( 'count' => 2 ),
		'broken_links'   => array( 'count' => 1 ),
		'external_links' => array( 'count' => 0 ),
		'stale_posts'    => array( 'count' => 0 ),
	),
);
$cards = snt_health_glance_cards( $scan );
hca_eq( '3 findings', $cards[0]['value'], 'Findings card sums all check counts (2+1=3)' );
hca_eq( 'warn', $cards[0]['pill']['kind'], 'Findings pill is warn when total>0' );
hca_true( false !== strpos( $cards[0]['meta_html'], 'across 4 check' ), 'Findings meta reports total check count' );
hca_eq( '2 / 4', $cards[1]['value'], 'Checks-passed card is passed/total (2 of 4)' );
hca_eq( 'warn', $cards[1]['pill']['kind'], 'Checks-passed pill is warn when not all clean' );
hca_true( false !== strpos( $cards[2]['value'], 'ago' ), 'Last-scan card shows a relative age' );

echo "\nTest: snt_health_glance_cards all clean\n";
$clean = array( 'scanned_at' => time() - 300, 'elapsed_ms' => 5, 'checks' => array(
	'missing_alt' => array( 'count' => 0 ), 'broken_links' => array( 'count' => 0 ) ) );
$cc = snt_health_glance_cards( $clean );
hca_eq( '0 findings', $cc[0]['value'], 'all-clean Findings value is "0 findings"' );
hca_eq( 'ok', $cc[0]['pill']['kind'], 'all-clean Findings pill is ok' );
hca_eq( '2 / 2', $cc[1]['value'], 'all-clean Checks-passed is 2 / 2' );
hca_eq( 'ok', $cc[1]['pill']['kind'], 'all-clean Checks-passed pill is ok' );

echo "\nTest: advisory tier in the hero (v8.0.4 — external rot re-tiered)\n";
// Owner decision 2026-07-02: third-party link rot must not flip the hero off
// "all clear". It surfaces as an advisory count in the Findings card meta;
// the findings card below stays fully visible and actionable.
$one = array( 'scanned_at' => time(), 'checks' => array( 'external_links' => array( 'count' => 1 ) ) );
$oc = snt_health_glance_cards( $one );
hca_eq( '0 findings', $oc[0]['value'], 'external-rot-only scan reads 0 findings' );
hca_eq( 'ok', $oc[0]['pill']['kind'], 'external-rot-only scan stays all-clear' );
hca_true( false !== strpos( $oc[0]['meta_html'], '1 advisory' ), 'hero meta surfaces the singular advisory' );

$adv = array( 'scanned_at' => time(), 'elapsed_ms' => 5, 'checks' => array(
	'missing_alt'    => array( 'count' => 0 ),
	'external_links' => array( 'count' => 3 ),
) );
$ac = snt_health_glance_cards( $adv );
hca_eq( '0 findings', $ac[0]['value'], 'advisories alone leave the finding count at 0' );
hca_true( false !== strpos( $ac[0]['meta_html'], '3 advisories' ), 'hero meta shows the plural advisory count' );
// Checks-passed must agree with the strip's RAW split (a check carrying
// advisories is not "passing"), while the pill keys off real findings.
hca_eq( '1 / 2', $ac[1]['value'], 'checks-passed ratio uses the raw count split (advisory check is not passing)' );
hca_eq( 'ok', $ac[1]['pill']['kind'], 'checks-passed pill stays ok when only advisories exist' );

$mixed = array( 'scanned_at' => time(), 'checks' => array(
	'missing_alt'    => array( 'count' => 2 ),
	'external_links' => array( 'count' => 1 ),
) );
$mc = snt_health_glance_cards( $mixed );
hca_eq( '2 findings', $mc[0]['value'], 'mixed scan counts only real findings' );
hca_eq( 'warn', $mc[1]['pill']['kind'], 'real findings still drive the review pill' );

// ── v8.0.1: elapsed humanizer — the live scan rendered "ran in 22206ms". ──
echo "\nTest: snt_health_format_elapsed boundaries\n";
hca_eq( '0ms', snt_health_format_elapsed( 0 ), '0 → 0ms' );
hca_eq( '412ms', snt_health_format_elapsed( 412 ), 'sub-second stays milliseconds' );
hca_eq( '999ms', snt_health_format_elapsed( 999 ), 'boundary: 999ms stays milliseconds' );
hca_eq( '1.0s', snt_health_format_elapsed( 1000 ), 'boundary: 1000ms reads as 1.0s' );
hca_eq( '22.2s', snt_health_format_elapsed( 22206 ), 'live-site case: 22206ms reads as 22.2s' );

echo "\nTest: hero last-scan meta uses the humanized elapsed\n";
hca_eq( 'ran in 900ms', $cards[2]['meta_html'], 'sub-second scan meta keeps the ms form' );
$slow = array( 'scanned_at' => time(), 'elapsed_ms' => 22206, 'checks' => array( 'a' => array( 'count' => 0 ) ) );
$sc = snt_health_glance_cards( $slow );
hca_eq( 'ran in 22.2s', $sc[2]['meta_html'], 'multi-second scan meta reads in seconds' );

echo "\nTest: unlinked_mentions suggest wiring (v7.4.0)\n";
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
$src = file_get_contents( __DIR__ . '/../inc/health-checks-admin.php' );
hca_true( false !== strpos( $src, "'unlinked_mentions'" ), 'unlinked_mentions joins $suggest_supported_checks' );
$cell = sn_health_render_suggest_cell( 'unlinked_mentions', array( 'subject_id' => 12, 'target_id' => 34 ) );
hca_true( false !== strpos( $cell, 'data-check="unlinked_mentions"' ), 'cell button carries the check key' );
hca_true( false !== strpos( $cell, 'data-post-id="12"' ) && false !== strpos( $cell, 'data-target-id="34"' ), 'cell button carries source + target ids' );
$js = file_get_contents( __DIR__ . '/../assets/health-suggest-actions.js' );
hca_true( false !== strpos( $js, 'unlinked_mentions:' ) && false !== strpos( $js, "'ai-link-suggest'" ) && false !== strpos( $js, "'ai-link-apply'" ), 'JS ABILITY_BY_CHECK routes unlinked_mentions to the link abilities' );
hca_true( false !== strpos( $js, "'link' === res.verdict" ), 'JS verdict renderer has a link branch' );

echo "\nTest: JS wiring for link_opportunities (v8.1.0)\n";
$js = file_get_contents( __DIR__ . '/../assets/health-suggest-actions.js' );
hca_true( false !== strpos( $js, 'link_opportunities:' ) && false !== strpos( $js, "'ai-pair-suggest'" ), 'JS ABILITY_BY_CHECK routes link_opportunities to ai-pair-suggest' );
hca_true( false !== strpos( $js, "'link_opportunities' === checkType" ), 'JS pair-input dispatch covers link_opportunities' );
hca_true( false !== strpos( $js, "'link' === res.verdict && ! res.anchor" ), 'JS verdict renderer has the advice-only branch (empty anchor never offers Apply)' );

echo "\nTest: link_opportunities suggest cell (v8.1.0)\n";
$cell = sn_health_render_suggest_cell( 'link_opportunities', array( 'subject_id' => 12, 'target_id' => 34 ) );
hca_true( false !== strpos( $cell, 'data-check="link_opportunities"' ), 'cell carries the check key' );
hca_true( false !== strpos( $cell, 'data-post-id="12"' ), 'cell carries the source id' );
hca_true( false !== strpos( $cell, 'data-target-id="34"' ), 'cell carries the target id' );
hca_true( false !== strpos( $cell, 'data-snt-suggest="1"' ), 'cell is a live Suggest button' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
