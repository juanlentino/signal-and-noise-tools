<?php
/**
 * Standalone test: scan_type "editorial_conventions" (inc/sn-scan-editorial-conventions.php)
 * and the sn-validate editorial_convention check (inc/sn-validate-checks-media.php),
 * over real block markup and the theme's real registry.
 *
 * Run: php tests/sn-scan-editorial-conventions.php
 *
 * @since 14.7.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function add_action() {}
function wp_register_ability() {}
$GLOBALS['__posts'] = array();
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_the_title( $p ) { return (string) $p->post_title; }
function get_permalink( $p ) { return 'https://x.test/notes/' . $p->post_name . '/'; }
function snt_corpus_fetch_posts( $status = 'any', $post_type = 'post' ) { return array_values( $GLOBALS['__posts'] ); }
function snt_corpus_content_hash( $c ) { return md5( $c ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }

require_once __DIR__ . '/lib/block-parser-fixture.php';
require_once __DIR__ . '/../inc/block-fingerprint-engine.php';
require_once __DIR__ . '/../inc/editorial-conventions-detect.php';
require_once __DIR__ . '/../inc/sn-scan-editorial-conventions.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Group 1: the scan refuses without the registry\n";
$r = snt_sn_scan_adapter_editorial_conventions( null );
ok( $r instanceof WP_Error && 'snt_registry_unavailable' === $r->code, 'no theme registry → WP_Error snt_registry_unavailable, never an empty (all-clean) candidate list' );

$theme = getenv( 'HOME' ) . '/Projects/signal-and-noise/inc/editorial-conventions.php';
require_once file_exists( $theme ) ? $theme : __DIR__ . '/fixtures/editorial-conventions-registry.php'; // parity pinned in tests/editorial-conventions-detect.php

echo "\nGroup 2: candidates over a small corpus\n";
$GLOBALS['__posts'] = array(
	11 => (object) array( 'ID' => 11, 'post_name' => 'a', 'post_title' => 'A', 'post_status' => 'publish', 'post_content' => "<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p><strong>Correction, May 1, 2026.</strong> x</p>\n<!-- /wp:paragraph -->" ),
	12 => (object) array( 'ID' => 12, 'post_name' => 'b', 'post_title' => 'B', 'post_status' => 'future', 'post_content' => "<!-- wp:paragraph -->\n<p><em>Lead.</em></p>\n<!-- /wp:paragraph -->\n\n<!-- wp:html -->\n<svg><text fill=\"#222\">x</text></svg>\n<!-- /wp:html -->" ),
	13 => (object) array( 'ID' => 13, 'post_name' => 'c', 'post_title' => 'C', 'post_status' => 'publish', 'post_content' => "<!-- wp:paragraph {\"className\":\"sn-lead\"} -->\n<p class=\"sn-lead\"><em>Fine.</em></p>\n<!-- /wp:paragraph -->" ),
);
$r = snt_sn_scan_adapter_editorial_conventions( null );
ok( is_array( $r ) && 3 === $r['posts_examined'] && false === $r['truncated'], 'three posts examined (scheduled included)' );
$keys = array_map( static function ( $c ) { return $c['target_identity'] . ':' . $c['evidence']['convention_id'] . ':' . $c['evidence']['fix']; }, $r['candidates'] );
ok( array( '11:correction:class', '12:lead:class', '12:svg-figure:form', '12:svg-figure:form' ) === $keys, 'four candidates, in post then document order; the clean post yields none (' . implode( ' ', $keys ) . ')' );
$c = $r['candidates'][0];
ok( '0/2' === $c['targets'][0]['block_path'] && 32 === strlen( $c['targets'][0]['block_fingerprint'] ) && $c['content_fingerprint'] === $c['targets'][0]['block_fingerprint'], 'a candidate carries block_path and the position-bound block fingerprint (as content_fingerprint too, for candidate_id)' );
ok( md5( $GLOBALS['__posts'][11]->post_content ) === $c['targets'][0]['content_hash'], '   ...and the LIVE content_hash, which is what block_replace binds on' );
ok( 'signal-noise/sn-apply' === $c['apply_hint']['tool'] && in_array( 'change.type:block_replace', $c['apply_hint']['required_args'], true ) && in_array( 'payload.block_path:targets[0].block_path', $c['apply_hint']['required_args'], true ) && false !== strpos( implode( ' ', $c['apply_hint']['required_args'] ), 'coalesces' ), 'a CLASS fix names sn-apply block_replace + block_path, and says the dry run should read coalesces' );
ok( false !== strpos( $c['evidence']['replacement'], 'sn-correction' ) && false !== strpos( $c['evidence']['message'], 'convention id: correction' ), '   ...with the replacement markup and a message naming the convention id' );
ok( null === $r['candidates'][2]['apply_hint'] && 'form' === $r['candidates'][2]['evidence']['fix'], 'a FORM fix (the SVG) carries apply_hint null: the author rewrites it' );
$scoped = snt_sn_scan_adapter_editorial_conventions( array( 12 ) );
ok( 1 === $scoped['posts_examined'] && 3 === count( $scoped['candidates'] ), 'scope narrows to the named post' );

echo "\nGroup 3: the same detector behind sn-validate's body check\n";
function snt_sn_validate_finding( $surface, $check, $severity, $message, $observed, $expected, array $evidence, $ci ) { return compact( 'surface', 'check', 'severity', 'message', 'observed', 'expected', 'evidence', 'ci' ); }
function sn_health_drift_time_patterns() { return array(); }
require_once __DIR__ . '/../inc/sn-validate-checks-media.php';
$f = snt_sn_validate_check_body( $GLOBALS['__posts'][11]->post_content, 11 );
$ec = array_values( array_filter( $f, static function ( $x ) { return 'editorial_convention' === $x['check']; } ) );
ok( 1 === count( $ec ) && 'warning' === $ec[0]['severity'] && 'correction' === $ec[0]['expected'] && 'core/paragraph at 0/2' === $ec[0]['observed'], 'sn-validate: the correction drift is ONE warning naming the convention id, never an error' );
ok( 'sn-site-facts{editorial_conventions}' === $ec[0]['evidence']['read'] && '' !== $ec[0]['evidence']['replacement'], '   ...pointing at the fact to read, with the replacement in evidence' );
$f = snt_sn_validate_check_body( $GLOBALS['__posts'][13]->post_content, 13 );
ok( array() === array_filter( $f, static function ( $x ) { return 'editorial_convention' === $x['check']; } ), 'the house form yields no convention finding' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
