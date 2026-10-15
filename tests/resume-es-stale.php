<?php
/**
 * Tests: the stale-CV alert (inc/resume-es-stale.php). The English lines the
 * Spanish was translated from are the baseline; the alert lists what changed.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $a ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return esc_html( $s ); }
function sanitize_email( $s ) { return (string) $s; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function sn_health_pack_check( $label, $findings, $fix = '', $skipped = null ) { return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix, 'skipped' => $skipped ); }

require_once __DIR__ . '/../inc/resume-page.php';
$GLOBALS['__options'][ SN_RESUME_DOC_OPTION ] = sn_resume_doc_normalize( sn_resume_seed_doc() );
$GLOBALS['__doc'] = &$GLOBALS['__options'][ SN_RESUME_DOC_OPTION ];
require_once __DIR__ . '/../inc/resume-es-stale.php';

echo "\nTest: lines\n";
$lines = sn_resume_lines( $GLOBALS['__doc'] );
ok( count( $lines ) > 40, 'a résumé flattens into its translatable lines (' . count( $lines ) . ')' );
ok( ! array_filter( $lines, static fn( $l ) => false !== strpos( $l, '<' ) ), 'tags are stripped' );
ok( ! in_array( (string) ( $GLOBALS['__doc']['hero']['linkedin'] ?? 'x' ), $lines, true ), 'contact details are not lines: /cv/ takes them as they are' );

echo "\nTest: the alert\n";
ok( array( 'changed' => array(), 'removed' => array() ) === sn_resume_es_stale(), 'first run: the baseline is captured from the live English, nothing is stale' );
ok( '' === sn_resume_es_stale_html( sn_resume_es_stale() ), 'current: no notice' );
ok( 0 === sn_health_check_resume_es_stale()['count'], 'current: the Health check passes' );

$GLOBALS['__doc']['experience'][0]['roles'][0]['bullets'][0] = 'Run a 60+ client practice across the Americas.';
$GLOBALS['__doc']['skills'][] = array( 'category' => 'Languages', 'items' => 'English, Spanish, Portuguese' );
$stale = sn_resume_es_stale();
ok( in_array( 'Run a 60+ client practice across the Americas.', $stale['changed'], true ), 'an edited bullet is listed as changed' );
ok( in_array( 'English, Spanish, Portuguese', $stale['changed'], true ), 'a new line is listed' );
ok( 1 <= count( $stale['removed'] ), 'the old wording of the edited bullet is listed as removed' );
$html = sn_resume_es_stale_html( $stale );
ok( false !== strpos( $html, 'The Spanish CV (/cv/) is out of date.' ) && false !== strpos( $html, '<li>Run a 60+ client practice across the Americas.</li>' ), 'the notice names the changed lines' );
$check = sn_health_check_resume_es_stale();
ok( $check['count'] === count( $stale['changed'] ) + count( $stale['removed'] ) && 'resume_line' === $check['findings'][0]['subject_type'], 'the Health check has one finding per line' );

$GLOBALS['__options'][ SN_RESUME_ES_SOURCE_OPT ]['es_hash'] = 'an older translation';
ok( array( 'changed' => array(), 'removed' => array() ) === sn_resume_es_stale(), 'a new Spanish file re-captures the baseline: the alert clears on its own' );

$GLOBALS['__doc']['skills'][0]['items'] = '<script>x</script>alert';
$html = sn_resume_es_stale_html( sn_resume_es_stale() );
ok( false === strpos( $html, '<script>' ), 'lines are escaped in the notice' );

echo "\nTest: registered\n";
$root = __DIR__ . '/..';
ok( false !== strpos( (string) file_get_contents( "$root/inc/health-checks.php" ), "'resume_es_stale'      => sn_health_check_resume_es_stale()" ), 'the Health scan runs it' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/admin-forms/resume-page.php" ), 'sn_resume_es_stale_html( sn_resume_es_stale() )' ) && false !== strpos( (string) file_get_contents( "$root/apps/sn-dashboard/parts/leaves/content-resume-ledger.php" ), 'resume_es_stale_notice()' ), 'both Résumé screens show the notice' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
