<?php
/**
 * Standalone tests for the morning brief's trust checks line, against the
 * REAL readings (inc/health-summary.php, inc/integrity-trust-admin.php): the
 * brief reads the same snt_trust_reading() both Trust checks screens use, so
 * a stand-in here could only agree with itself.
 *
 * The incident: the brief said "no fault-tier issues" while Integrity › Trust
 * checks showed "Ledger CI: 1 finding". The health total counts the `health`
 * surface only; the four trust checks render on `integrity`.
 *
 * Run: php tests/morning-brief-trust.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
require __DIR__ . '/lib/mail-capture-stub.php';
require __DIR__ . '/../inc/health-check-surfaces.php';
require __DIR__ . '/../inc/health-summary.php';
require __DIR__ . '/../inc/integrity-trust-admin.php';
require __DIR__ . '/../inc/morning-brief-summary.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$clear = array( 'count' => 0, 'findings' => array() );
$scan  = array(
	'scanned_at' => 100,
	'checks'     => array(
		'provenance_integrity' => $clear,
		'ledger_ci'            => array( 'count' => 1, 'findings' => array( array( 'note' => 'verify.yml failed' ) ) ),
		'rights_signals'       => $clear,
		'rights_anchored'      => $clear,
	),
);

echo "\nTest: the trust checks line\n";
$finding = snt_morning_brief_trust_words( snt_morning_brief_trust( $scan ) );
ok( 'Ledger CI: 1 finding; the other 3 clear.' === $finding, 'a finding is named with its check: "' . $finding . '"' );
ok( 1 === snt_morning_brief_trust_findings( snt_morning_brief_trust( $scan ) ), 'and counts toward the subject\'s attention total' );
ok( 0 === sn_health_finding_total( $scan ), 'control: the health total really does not see it (why this line exists)' );

$scan['checks']['ledger_ci'] = $clear;
ok( 'all clear (4 of 4).' === snt_morning_brief_trust_words( snt_morning_brief_trust( $scan ) ), 'four clear readings read "all clear"' );
ok( 0 === snt_morning_brief_trust_findings( snt_morning_brief_trust( $scan ) ), 'all clear adds nothing to the subject' );

$skipped                         = $scan;
$skipped['checks']['rights_signals'] = array( 'count' => 0, 'skipped' => 'the edge did not answer' );
unset( $skipped['checks']['rights_anchored'] );
$w = snt_morning_brief_trust_words( snt_morning_brief_trust( $skipped ) );
ok( 'Rights signals: not measured; Rights anchoring: not read; the other 2 clear.' === $w, 'a skipped check is "not measured" and a missing one "not read", never clear: "' . $w . '"' );

ok( 0 === strpos( snt_morning_brief_trust_words( snt_morning_brief_trust( null ) ), 'not read' ), 'no stored scan: "not read", never "clear"' );
ok( null === snt_morning_brief_trust( 'garbage' ), 'a scan that is not an array is not read' );

echo "\nTest: the Trust checks screen link\n";
ok( 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=tools&sub=trust' === snt_morning_brief_screen_url( 'tools', 'trust' ), 'the Trust checks leaf is an absolute admin URL (classic page and S&N Home share it)' );
ok( 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=tools&sub=reports' === snt_morning_brief_watch_screen( array( 'id' => 'index_gap' ) )['url'], 'the index_gap watch points at Integrity › Reports' );

echo "\nTest: a watch's read splits for people and for agents\n";
$s = snt_morning_brief_read_split( 'Measurement › Machine Readers › Rights evidence; signal-noise/rights-evidence{in_review,hold_reasons}' );
ok( 'Measurement › Machine Readers › Rights evidence' === $s['human'] && 'signal-noise/rights-evidence{in_review,hold_reasons}' === $s['agents'], 'a tool slug leaves the human line' );
$s = snt_morning_brief_read_split( 'option sn_csp_hash_last; signal-noise/get-cron-history sn_csp_hash_check' );
ok( '' === $s['human'] && 2 === count( explode( '; ', $s['agents'] ) ), 'options and cron-history reads are for agents' );
$s = snt_morning_brief_read_split( 'Connections › Credentials; https://github.com/juanlentino/signal-and-noise-tools/issues/1625' );
ok( '' === $s['agents'] && false !== strpos( $s['human'], 'https://github.com/' ), 'a screen name and a public URL stay for people' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
