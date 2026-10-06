<?php
/**
 * Tests for inc/health-contrast-rendered.php — the contrast report's rendered
 * tier (the theme's live contrast.yml, read from the public GitHub API).
 * Run: php tests/health-contrast-rendered.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
function __( $s, $d = null ) { return (string) $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
require dirname( __DIR__ ) . '/inc/health-contrast-rendered.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$run = static function ( $conclusion ) {
	return array( 'workflow_runs' => array( array( 'conclusion' => $conclusion, 'updated_at' => '2026-10-07T06:44:10Z', 'html_url' => 'https://github.com/juanlentino/signal-and-noise/actions/runs/1' ) ) );
};
$summary = array( 'annotation_level' => 'notice', 'title' => 'contrast-summary', 'message' => '{"pages":40,"checked":1300,"links":95}' );

echo "Group: verdicts\n";
$v = snt_contrast_rendered_evaluate( $run( 'success' ), array( $summary ) );
ok( 'ok' === $v['state'] && 40 === $v['pages'] && 1300 === $v['checked'] && 95 === $v['links'] && '2026-10-07' === $v['at'], 'a green run reads ok, with its page, pair and link counts and its date' );
$v = snt_contrast_rendered_evaluate( $run( 'failure' ), array( $summary, array( 'annotation_level' => 'failure' ), array( 'annotation_level' => 'failure' ), array( 'annotation_level' => 'warning' ) ) );
ok( 'red' === $v['state'] && 2 === $v['failures'], 'a red run counts failure annotations only (a warning is not a failure)' );
$many = array( array( 'annotation_level' => 'notice', 'title' => 'contrast-summary', 'message' => '{"pages":40,"checked":1300,"links":95,"failures":37}' ) );
for ( $i = 0; $i < 10; $i++ ) { $many[] = array( 'annotation_level' => 'failure' ); }
ok( 37 === snt_contrast_rendered_evaluate( $run( 'failure' ), $many )['failures'], 'the total comes from the summary, not the ten-per-step annotation cap' );
ok( 'unknown' === snt_contrast_rendered_evaluate( $run( 'failure' ), null )['state'], 'a red run whose annotations could not be read is unknown, never 0 failures' );
ok( 'none' === snt_contrast_rendered_evaluate( array( 'workflow_runs' => array() ), null )['state'], 'no completed run yet: none, not ok' );
ok( 'unknown' === snt_contrast_rendered_evaluate( null, null )['state'], 'an unreadable API: unknown, never ok' );
ok( 'unknown' === snt_contrast_rendered_evaluate( $run( 'cancelled' ), null )['state'], 'a cancelled run is no verdict' );

echo "\nGroup: the line\n";
$h = snt_contrast_rendered_html( snt_contrast_rendered_evaluate( $run( 'success' ), array( $summary ) ), 'snt-hint' );
ok( false !== strpos( $h, 'on 40 pages (1300 pairs, 95 links)' ) && false !== strpos( $h, '<a href="https://github.com/juanlentino/signal-and-noise/actions/runs/1">2026-10-07</a>' ), 'ok names its scope and links the run by its date' );
$h = snt_contrast_rendered_html( snt_contrast_rendered_evaluate( $run( 'failure' ), array( array( 'annotation_level' => 'failure' ) ) ), 'snt-hint' );
ok( false !== strpos( $h, '>1 failure</a>' ), 'red links its failure count to the run that names each (an amber line links to its fix)' );
$h = snt_contrast_rendered_html( snt_contrast_rendered_evaluate( null, null ), 'snt-hint' );
ok( false !== strpos( $h, 'unknown right now' ) && false === strpos( $h, 'every text pair' ), 'unknown is said as unknown, never as a pass' );

echo "\nGroup: fetch guard\n";
ok( null === snt_contrast_rendered_get( 'https://example.com/x' ), 'only api.github.com URLs are fetched (a URL read from a response cannot point elsewhere)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
