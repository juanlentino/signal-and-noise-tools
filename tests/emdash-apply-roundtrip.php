<?php
/**
 * Round-trip test: scanner candidate → REAL apply path → expected content.
 *
 * This is the test that should have existed before emdash_replace shipped, and
 * whose absence put a broken string on a live page.
 *
 * What existed instead: tests/emdash-scan.php proved the scanner emits the right
 * candidates, and the sn-apply delegation sweep proved emdash_replace dispatches and
 * writes. Both passed. Neither asked the only question that matters — **does the text
 * that lands equal the text the scanner intended?** The scan suite even spliced a
 * candidate with substr_replace and asserted the result, but that spliced it the way I
 * IMAGINED the apply path works; it never touched the apply path.
 *
 * The bug: emdash_replace delegates to snt_ai_drift_apply_impl(), which does
 * `$replacement = trim( $replacement )`. Correct for drift_replace, whose replacements
 * are whole phrases. Wrong here, because every em-dash replacement carries MEANINGFUL
 * edge whitespace — ': ', '. ', ', ', ' (', ') '. `reach for &mdash; the studio` became
 * `reach for:the studio` on a published page.
 *
 * General rule this encodes: when a new change type DELEGATES to an existing impl, it
 * silently inherits every behaviour of that impl, including ones that are right there
 * and wrong here. Only an end-to-end assertion catches that.
 *
 * @since plugin v10.65.2
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok  - $m\n"; } else { ++$fail; echo "  FAIL - $m\n"; } }

// ── WP surface the apply impl touches ──────────────────────────────────────────
$GLOBALS['__posts']   = array();
$GLOBALS['__written'] = array();
function current_user_can( $cap, $id = null ) { return true; }
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) { return null; }
	return (object) array( 'ID' => $id, 'post_content' => $GLOBALS['__posts'][ $id ] );
}
function wp_update_post( $arr, $wp_error = false ) {
	$GLOBALS['__posts'][ (int) $arr['ID'] ]   = $arr['post_content'];
	$GLOBALS['__written'][ (int) $arr['ID'] ] = $arr['post_content'];
	return (int) $arr['ID'];
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function add_action() {} function add_filter() {} function wp_register_ability() {}
function register_rest_route() {}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

require_once __DIR__ . '/../inc/emdash-scan.php';
require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php';

/**
 * Scan → apply → return the resulting stored content.
 * Deliberately routes through the REAL impl, not a local substr_replace.
 */
function roundtrip( $post_id, $content, $which = 0 ) {
	$GLOBALS['__posts'][ $post_id ] = $content;
	$cands = array_values( array_filter( snt_emdash_scan_content( $content ), function ( $c ) { return 'prose' === $c['classification']; } ) );
	if ( ! isset( $cands[ $which ] ) ) { return array( null, null ); }
	$c  = $cands[ $which ];
	$fp = snt_ai_drift_fingerprint( $content, $c['phrase'], $c['position'] );
	$r  = snt_emdash_apply_impl( $post_id, $c['phrase'], $c['position'], $c['replacement'], $fp, $c['context_snippet'] );
	return array( $r, $GLOBALS['__posts'][ $post_id ] );
}

echo "Group: the exact sentence that broke on the live site\n";
$uses = '<p class="sn-uses-dek">The hardware and software I actually reach for &mdash; the studio, the instruments.</p>';
list( $res, $after ) = roundtrip( 501, $uses );
ok( ! is_wp_error( $res ), 'apply succeeded' );
ok( false !== strpos( $after, 'reach for: the studio' ), 'the SPACE survives: "reach for: the studio"' );
ok( false === strpos( $after, 'reach for:the studio' ), 'the trim-eaten form "reach for:the studio" is NOT produced' );
ok( false === strpos( $after, '&mdash;' ), 'the entity is gone from the content' );

echo "\nGroup: every replacement shape keeps its edge whitespace\n";
// trailing-space shapes
list( , $a1 ) = roundtrip( 502, '<p>The cap is reached &mdash; AI features pause.</p>' );
ok( false !== strpos( $a1, 'reached. AI features' ), 'capitalised continuation keeps the space after the period' );
list( , $a2 ) = roundtrip( 503, '<p>voice recordings and performance photos&mdash;exactly the linear costs.</p>' );
ok( false !== strpos( $a2, 'photos, exactly the' ), 'unspaced infix keeps the space after the comma' );
// LEADING-space shape: the paired open is " (" — a trim would eat the space BEFORE it
list( , $a3 ) = roundtrip( 504, '<p>the supply chain &mdash; code signing, SLSA provenance &mdash; were designed.</p>', 0 );
ok( false !== strpos( $a3, 'chain (code signing' ), 'paired OPEN keeps the space BEFORE the parenthesis (leading-whitespace case)' );
ok( false === strpos( $a3, 'chain(code' ), 'the trim-eaten form "chain(code" is NOT produced' );

echo "\nGroup: the gates the delegation still provides are intact\n";
$GLOBALS['__posts'][505] = '<p>A sentence &mdash; with a dash.</p>';
$c = array_values( array_filter( snt_emdash_scan_content( $GLOBALS['__posts'][505] ), function ( $x ) { return 'prose' === $x['classification']; } ) )[0];
$bad = snt_emdash_apply_impl( 505, $c['phrase'], $c['position'], $c['replacement'], 'deadbeefdeadbeefdeadbeefdeadbeef', $c['context_snippet'] );
ok( is_wp_error( $bad ), 'a wrong fingerprint is still refused' );
ok( '<p>A sentence &mdash; with a dash.</p>' === $GLOBALS['__posts'][505], 'and the post is left untouched when refused' );

$missing = snt_emdash_apply_impl( 999, ' &mdash; ', 5, ': ', 'x', '' );
ok( is_wp_error( $missing ), 'a missing post is refused' );

echo "\nGroup: drift_replace is UNCHANGED — it still trims, which is right for it\n";
$GLOBALS['__posts'][506] = '<p>Written recently, will drift.</p>';
$dp  = 'recently';
$dpos = strpos( $GLOBALS['__posts'][506], $dp );
$dfp = snt_ai_drift_fingerprint( $GLOBALS['__posts'][506], $dp, $dpos );
snt_ai_drift_apply_impl( 506, $dp, $dpos, '  in June 2026  ', $dfp, '' );
ok( false !== strpos( $GLOBALS['__posts'][506], 'Written in June 2026, will drift' ), 'drift_replace still trims its replacement (unchanged behaviour)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
