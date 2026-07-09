<?php
/**
 * Tests for snt_ai_post_signal() — the content-or-synthesized signal the
 * meta-description + excerpt AI generators consume so contentless template
 * Pages get a usable prompt instead of a 422. (plugin v9.3.0)
 *
 * snt_ai_extract_post_text() is declared UNGUARDED in inc/ai-bootstrap.php, so
 * we cannot stub it (redeclare fatal). We require the real bootstrap and drive
 * content through get_post()->post_content, mirroring tests/ai-concise-param.php.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// Test-controlled state.
$GLOBALS['__content']      = '';
$GLOBALS['__title']        = 'About';
$GLOBALS['__name']         = 'about';
$GLOBALS['__post_missing'] = false;
$GLOBALS['__desc']         = ''; // sn_seo_singular_description filter yield

// ─── WP primitives the real extractor + signal helper touch ───
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id = null ) {
		if ( $GLOBALS['__post_missing'] ) { return null; }
		$p = new stdClass();
		$p->ID           = 9;
		$p->post_content = $GLOBALS['__content'];
		$p->post_title   = $GLOBALS['__title'];
		$p->post_name    = $GLOBALS['__name'];
		return $p;
	}
}
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $s ) { return $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num = 55, $more = null ) {
		$text = trim( (string) $text );
		if ( '' === $text ) { return ''; }
		$w = preg_split( '/\s+/', $text );
		return implode( ' ', array_slice( $w, 0, $num ) );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, $post = null ) {
		return ( 'sn_seo_singular_description' === $tag ) ? $GLOBALS['__desc'] : $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }

require_once __DIR__ . '/../inc/ai-bootstrap.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

echo "Group: normal post (has content)\n";
$GLOBALS['__content'] = 'Real body text here.';
ok( 'Real body text here.' === snt_ai_post_signal( 5 ), 'returns post_content verbatim when non-empty' );

echo "\nGroup: contentless Page (title + fallback)\n";
$GLOBALS['__content'] = '';
$GLOBALS['__title']   = 'About';
$GLOBALS['__name']    = 'about';
$GLOBALS['__desc']    = 'The story behind the studio.';
$sig = snt_ai_post_signal( 9 );
ok( false !== strpos( $sig, 'TITLE: About' ), 'synthesized signal leads with the title' );
ok( false !== strpos( $sig, 'The story behind the studio.' ), 'folds in theme fallback description' );
ok( false !== strpos( $sig, 'about' ), 'includes the slug for extra context' );

echo "\nGroup: contentless Page, no theme fallback\n";
$GLOBALS['__desc'] = '';
$sig = snt_ai_post_signal( 9 );
ok( false !== strpos( $sig, 'TITLE: About' ), 'still produces a title-only signal' );
ok( '' !== $sig, 'signal is non-empty from title alone' );

echo "\nGroup: missing post\n";
$GLOBALS['__content']      = '';
$GLOBALS['__post_missing'] = true;
ok( '' === snt_ai_post_signal( 404 ), 'returns empty string when the post is gone' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
