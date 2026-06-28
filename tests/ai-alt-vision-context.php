<?php
/**
 * Vision-context tests for alt-text Suggest (v6.48.0).
 *
 * Locks the v6.48.0 vision behavior: the shared resolver snt_ai_alt_resolve_image_file()
 * builds the ABSOLUTE downscaled local path (NOT the relative 'path' key that
 * image_get_intermediate_size() returns — the core trap), falls back through sizes
 * then to the original, normalizes 'image/jpg'; and both alt impls pass the resolved
 * image + the 'alt-text' feature tag to the generate seam. The inline-<img> impl maps
 * its URL to a local attachment (else stays text-only — never hands a URL to the
 * provider).
 *
 * Uses REAL temp files so is_readable() (a PHP builtin, unstubbable) answers honestly.
 *
 * @since plugin v6.48.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ── Temp image fixtures (original + a 'large' variant in the same dir) ──
$tmp_dir    = sys_get_temp_dir() . '/snt_vision_' . getmypid();
@mkdir( $tmp_dir, 0777, true );
$orig_path  = $tmp_dir . '/photo.jpg';
$large_path = $tmp_dir . '/photo-1024x576.jpg';
file_put_contents( $orig_path, 'ORIGINAL-BYTES' );
file_put_contents( $large_path, 'LARGE-BYTES' );

// ── Configurable WP stubs (tests set the globals) ──
$GLOBALS['__attached_file'] = array();   // id => absolute original path | false
$GLOBALS['__intermediate']  = array();   // "id:size" => array | false
$GLOBALS['__mime']          = array();   // id => mime | false
$GLOBALS['__url_to_postid'] = 0;         // attachment_url_to_postid() return
$GLOBALS['__posts']         = array();   // id => post object
$GLOBALS['__gen_calls']     = array();   // recorded snt_ai_generate_with_constraints args

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) { return $GLOBALS['__attached_file'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'image_get_intermediate_size' ) ) {
	function image_get_intermediate_size( $id, $size ) { return $GLOBALS['__intermediate'][ (int) $id . ':' . $size ] ?? false; }
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $id ) { return $GLOBALS['__mime'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	function attachment_url_to_postid( $url ) { return (int) $GLOBALS['__url_to_postid']; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $p ) { return basename( (string) $p ); }
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return 'https://example.test/thumb.jpg'; }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( 'snt_ai_alt_image_max_bytes' === $tag && isset( $GLOBALS['__alt_img_cap'] ) ) {
			return $GLOBALS['__alt_img_cap'];
		}
		return $value;
	}
}
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $s ) { return $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }

// The AI gate: available.
if ( ! function_exists( 'snt_ai_require_text_generation' ) ) {
	function snt_ai_require_text_generation() { return null; }
}
// The generate seam: record args, return a canned suggestion (NOT the marker).
if ( ! function_exists( 'snt_ai_generate_with_constraints' ) ) {
	function snt_ai_generate_with_constraints( $prompt, $system, $max = 256, $feature = 'generic', $image_path = '', $image_mime = '' ) {
		$GLOBALS['__gen_calls'][] = array(
			'prompt'  => $prompt,
			'feature' => $feature,
			'image'   => $image_path,
			'mime'    => $image_mime,
		);
		return 'A studio mixing console with faders raised.';
	}
}

// Minimal $wpdb for the referencing-post lookup in the attachment impl.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public $posts = 'wp_posts';
		public function prepare( $q, ...$a ) { return $q; }
		public function esc_like( $s ) { return $s; }
		public function get_var( $q ) { return ''; }
	};
}

require_once __DIR__ . '/../inc/ai-alt-text-suggest.php';
require_once __DIR__ . '/../inc/ai-alt-inline-suggest.php';

// ── Harness ──
$pass = 0; $fail = 0;
function vc_eq( $e, $a, $m ) { global $pass, $fail; if ( $e === $a ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n  expected: " . var_export( $e, true ) . "\n  actual:   " . var_export( $a, true ) . "\n"; } }
function vc_true( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Vision alt-text — resolver + impl wiring\n\n";

// ─── Resolver: happy path builds ABSOLUTE path from dirname(original)+basename ───
$GLOBALS['__attached_file'][100] = $orig_path;
$GLOBALS['__intermediate']['100:large'] = array(
	'file' => 'photo-1024x576.jpg',
	'path' => 'WRONG/RELATIVE/photo-1024x576.jpg', // the trap: relative; must be ignored
	'width' => 1024, 'height' => 576,
);
$GLOBALS['__mime'][100] = 'image/jpeg';
$r = snt_ai_alt_resolve_image_file( 100 );
vc_eq( $large_path, $r['path'], 'resolver builds ABSOLUTE path = dirname(original)+basename(file), NOT the relative "path" key' );
vc_eq( 'image/jpeg', $r['mime'], 'resolver returns the mime' );

// ─── Resolver: size fallback to the original when no intermediate exists ───
$GLOBALS['__intermediate'] = array(); // none for any size
$r = snt_ai_alt_resolve_image_file( 100 );
vc_eq( $orig_path, $r['path'], 'resolver falls back to the original when no sized variant exists' );

// ─── Resolver: legacy image/jpg normalized to image/jpeg ───
$GLOBALS['__mime'][100] = 'image/jpg';
$r = snt_ai_alt_resolve_image_file( 100 );
vc_eq( 'image/jpeg', $r['mime'], 'resolver normalizes legacy image/jpg to image/jpeg' );

// ─── Resolver: id <= 0 → empty ───
$r0 = snt_ai_alt_resolve_image_file( 0 );
vc_eq( '', $r0['path'], 'resolver returns empty path for id <= 0' );
vc_eq( '', $r0['mime'], 'resolver returns empty mime for id <= 0' );

// ─── Resolver: broken original (no file on disk) → empty ───
$GLOBALS['__attached_file'][101] = '/no/such/dir/missing.jpg';
$GLOBALS['__mime'][101] = 'image/png';
$rb = snt_ai_alt_resolve_image_file( 101 );
vc_eq( '', $rb['path'], 'resolver returns empty when the original is not readable on disk' );

// ─── Resolver: NON-IMAGE media (e.g. a PDF) → empty (never inline a non-image) ───
$GLOBALS['__attached_file'][103] = $orig_path;       // a readable file on disk...
$GLOBALS['__mime'][103] = 'application/pdf';          // ...but NOT an image
$rp = snt_ai_alt_resolve_image_file( 103 );
vc_eq( '', $rp['path'], 'resolver refuses a non-image attachment (PDF) — never base64-inlines it to the vision model' );
vc_eq( '', $rp['mime'], 'resolver returns empty mime for a non-image attachment' );

// ─── Resolver: OVERSIZED image → empty (degrade to text-only, avoid the OOM fatal) ───
$GLOBALS['__attached_file'][100] = $orig_path;       // readable, image/jpeg
$GLOBALS['__intermediate']['100:large'] = array( 'file' => 'photo-1024x576.jpg' );
$GLOBALS['__mime'][100] = 'image/jpeg';
$GLOBALS['__alt_img_cap'] = 3;                        // 3-byte cap; the temp file is larger
$ro = snt_ai_alt_resolve_image_file( 100 );
vc_eq( '', $ro['path'], 'resolver skips an image over the size cap (degrades to text-only; guards against the OOM fatal)' );
unset( $GLOBALS['__alt_img_cap'] );

// ─── Attachment impl: passes feature=alt-text + resolved image to the seam ───
$GLOBALS['__attached_file'][100] = $orig_path;
$GLOBALS['__intermediate']['100:large'] = array( 'file' => 'photo-1024x576.jpg', 'path' => 'rel', 'width' => 1024, 'height' => 576 );
$GLOBALS['__mime'][100] = 'image/jpeg';
$GLOBALS['__posts'][100] = (object) array(
	'ID' => 100, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg',
	'post_title' => 'Mixing', 'post_excerpt' => '', 'guid' => 'https://example.test/photo.jpg', 'post_parent' => 0,
);
$GLOBALS['__gen_calls'] = array();
$res = snt_ai_alt_suggest_impl( 100 );
vc_true( is_array( $res ) && ! empty( $res['ok'] ), 'attachment impl returns a suggestion (not an error)' );
$last = end( $GLOBALS['__gen_calls'] );
vc_eq( 'alt-text', $last['feature'] ?? null, 'attachment impl tags the seam call feature=alt-text (routes to Gemini)' );
vc_eq( $large_path, $last['image'] ?? null, 'attachment impl passes the resolved downscaled image path to the seam' );
vc_eq( 'image/jpeg', $last['mime'] ?? null, 'attachment impl passes the image mime to the seam' );

// ─── Inline impl: URL that resolves to a local attachment → image attached ───
$GLOBALS['__posts'][200] = (object) array(
	'ID' => 200, 'post_type' => 'post',
	'post_content' => 'Intro text. <img src="https://example.test/photo.jpg" /> more text here for context.',
	'post_title' => 'A post', 'post_excerpt' => '',
);
$GLOBALS['__url_to_postid'] = 100; // resolves to the local attachment
$GLOBALS['__gen_calls'] = array();
$ri = snt_ai_alt_inline_suggest_impl( 200, 'https://example.test/photo.jpg' );
$li = end( $GLOBALS['__gen_calls'] );
vc_eq( 'alt-text', $li['feature'] ?? null, 'inline impl tags the seam call feature=alt-text' );
vc_eq( $large_path, $li['image'] ?? null, 'inline impl attaches the local image when the URL resolves to an attachment' );

// ─── Inline impl: EXTERNAL/unresolvable URL → text-only (no image), still alt-text ───
$GLOBALS['__url_to_postid'] = 0; // external URL, no attachment
$GLOBALS['__gen_calls'] = array();
$re = snt_ai_alt_inline_suggest_impl( 200, 'https://example.test/photo.jpg' );
$le = end( $GLOBALS['__gen_calls'] );
vc_eq( '', $le['image'] ?? 'MISSING', 'inline impl stays TEXT-ONLY for an external/unresolvable URL (never hands a URL to the provider)' );
vc_eq( 'alt-text', $le['feature'] ?? null, 'inline impl still routes to Gemini (feature=alt-text) even without an image' );

// ── Cleanup ──
@unlink( $orig_path );
@unlink( $large_path );
@rmdir( $tmp_dir );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
