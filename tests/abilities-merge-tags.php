<?php
/**
 * Standalone tests for signal-noise/merge-tags's execute callback
 * (inc/abilities-content.php: snt_ability_merge_tags()).
 *
 * #1194: an unknown/typo'd source slug in `from_slugs` was silently dropped
 * — get_term_by() simply skipped it — and the merge proceeded over whatever
 * slugs DID resolve, reporting `ok:true, "Merged."` as if every requested
 * slug had been folded in. With `from_slugs: ['ai-generated-msuic' (typo),
 * 'ai-generated-music']` only the second merges and the caller has no way
 * to tell a partial merge happened.
 *
 * @since plugin v13.109.22
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $tag, $cb, $p = 10, $a = 1 ) { return true; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( $s ); } }

// Term store double: slug => term_id, post_tag taxonomy only.
$GLOBALS['__terms'] = array(
	'ai-generated-music' => 501,
	'human-made'         => 502,
);
function get_term_by( $field, $value, $taxonomy = '' ) {
	if ( 'slug' !== $field || 'post_tag' !== $taxonomy ) {
		return false;
	}
	if ( ! isset( $GLOBALS['__terms'][ $value ] ) ) {
		return false;
	}
	return (object) array( 'term_id' => $GLOBALS['__terms'][ $value ] );
}

$GLOBALS['__merge_calls'] = array();
function sn_tag_merge( $from_ids, $into_id ) {
	$GLOBALS['__merge_calls'][] = array( 'from' => $from_ids, 'into' => $into_id );
	return array( 'posts_moved' => 3, 'into_slug' => 'human-made' );
}

require __DIR__ . '/../inc/abilities-content.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "merge-tags execute callback\n\n";

// A typo among the from_slugs must REFUSE, not silently merge the ones that
// resolved and report success.
$GLOBALS['__merge_calls'] = array();
$r = snt_ability_merge_tags( array(
	'from_slugs' => array( 'ai-generated-msuic', 'ai-generated-music' ), // first is a typo
	'into_slug'  => 'human-made',
) );
ok( is_array( $r ) && false === $r['ok'], 'a typo among from_slugs refuses (ok:false), never a silent partial merge' );
ok( false !== strpos( $r['message'], 'ai-generated-msuic' ), 'the refusal NAMES the unknown slug (' . $r['message'] . ')' );
ok( array() === $GLOBALS['__merge_calls'], 'sn_tag_merge is never called when a source slug is unknown' );

// All slugs resolving still merges normally.
$GLOBALS['__merge_calls'] = array();
$r = snt_ability_merge_tags( array(
	'from_slugs' => array( 'ai-generated-music' ),
	'into_slug'  => 'human-made',
) );
ok( true === $r['ok'] && 3 === $r['posts_moved'], 'a fully-resolved merge still succeeds' );
ok( 1 === count( $GLOBALS['__merge_calls'] ) && array( 501 ) === $GLOBALS['__merge_calls'][0]['from'], 'sn_tag_merge receives only the resolved id' );

// An unknown into_slug keeps its existing refusal behavior.
$r = snt_ability_merge_tags( array( 'from_slugs' => array( 'ai-generated-music' ), 'into_slug' => 'does-not-exist' ) );
ok( false === $r['ok'], 'an unknown into_slug still refuses (pre-existing behavior)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
