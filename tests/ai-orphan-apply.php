<?php
/**
 * TOCTOU re-check in snt_ai_orphan_apply_impl() (v10.28.1).
 *
 * The apply impl force-deletes via wp_delete_attachment($id, true) after
 * checking only capability + existence. Between the orphan SCAN (which flagged
 * the attachment) and the APPLY click, the attachment can become referenced
 * again — set as a featured image, inserted into a post body, made the site
 * logo. Pre-fix, apply deleted it anyway.
 *
 * This pins the apply-time re-check: sn_health_attachment_is_referenced_now()
 * (same signals as the scan, rebuilt live) must run BEFORE the delete; a
 * now-referenced attachment returns WP_Error snt_orphan_no_longer (409) and
 * wp_delete_attachment() is NEVER called. Harness copied from
 * tests/health-orphan-detection.php (the known-good sibling for this wpdb
 * substring-corpus stub).
 *
 * @since plugin v10.28.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

// ── Configurable corpus (what the substring search "finds") ──
$GLOBALS['__post_bodies'] = array(); // post_content strings
$GLOBALS['__meta_values'] = array(); // meta_value strings
$GLOBALS['__att_meta']    = array(); // id => wp_get_attachment_metadata() return
$GLOBALS['__featured']    = array(); // _thumbnail_id meta_values (strings, as get_col returns)
$GLOBALS['__theme_mods']  = array(); // theme_mod name => value
$GLOBALS['__options']     = array(); // option name => value

// Substring-corpus $wpdb: a LIKE %needle% query returns 1 iff `needle` is a
// substring of any string in the relevant corpus (post bodies vs meta values).
class SnOrphanApplyWpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	private $last_sql  = '';
	private $last_args = array();
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$this->last_sql  = $sql;
		$this->last_args = $args;
		return $sql;
	}
	public function esc_like( $s ) { return $s; }
	public function get_var( $sql ) {
		$needle = isset( $this->last_args[0] ) ? trim( (string) $this->last_args[0], '%' ) : '';
		if ( '' === $needle ) { return 0; }
		$corpus = ( false !== strpos( $this->last_sql, 'postmeta' ) )
			? $GLOBALS['__meta_values']
			: $GLOBALS['__post_bodies'];
		foreach ( $corpus as $hay ) {
			if ( false !== strpos( (string) $hay, $needle ) ) { return 1; }
		}
		return 0;
	}
	public function get_results( $sql ) { return array(); }
	public function get_col( $sql )     { return $GLOBALS['__featured']; }
}
$GLOBALS['wpdb'] = new SnOrphanApplyWpdb();

// ── WP stubs ──
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $id ) { return $GLOBALS['__att_meta'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'get_theme_mod' ) ) {
	function get_theme_mod( $name, $default = false ) { return $GLOBALS['__theme_mods'][ $name ] ?? $default; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return $GLOBALS['__options'][ $name ] ?? $default; }
}

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// ── Apply-impl stubs ──
$GLOBALS['__posts']              = array(); // id => post array (cast to object by get_post)
$GLOBALS['__can_delete']         = true;
$GLOBALS['__delete_calls']       = array(); // recorded (id, force) tuples
$GLOBALS['__delete_result']      = null;    // null => default (WP_Post-ish object); false models failure
$GLOBALS['__deleted_transients'] = array();

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $id = 0 ) { return ! empty( $GLOBALS['__can_delete'] ); }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = (int) $id;
		return isset( $GLOBALS['__posts'][ $id ] ) ? (object) $GLOBALS['__posts'][ $id ] : null;
	}
}
// Real WP 7.0 shape: returns WP_Post on success, false on failure. Never an array.
if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( $id, $force = false ) {
		$GLOBALS['__delete_calls'][] = array( (int) $id, (bool) $force );
		if ( null !== $GLOBALS['__delete_result'] ) { return $GLOBALS['__delete_result']; }
		return (object) array( 'ID' => (int) $id );
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) { $GLOBALS['__deleted_transients'][] = $key; return true; }
}

require_once __DIR__ . '/../inc/health-checks.php';
require_once __DIR__ . '/../inc/ai-orphan-suggest.php';

$pass = 0; $fail = 0;
function oa_true( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// Fresh orphan-candidate attachment fixture + clean recorders.
function oa_reset() {
	$GLOBALS['__post_bodies'] = array( 'unrelated content, no images' );
	$GLOBALS['__meta_values'] = array( 'some_setting=value' );
	$GLOBALS['__att_meta']    = array( 200 => array( 'file' => '2024/01/orphan.jpg', 'sizes' => array() ) );
	$GLOBALS['__featured']    = array();
	$GLOBALS['__theme_mods']  = array();
	$GLOBALS['__options']     = array();
	$GLOBALS['__posts']       = array(
		200 => array(
			'ID'             => 200,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/jpeg',
			'guid'           => 'https://example.test/wp-content/uploads/2024/01/orphan.jpg',
		),
	);
	$GLOBALS['__can_delete']         = true;
	$GLOBALS['__delete_calls']       = array();
	$GLOBALS['__delete_result']      = null;
	$GLOBALS['__deleted_transients'] = array();
}

echo "AI orphan apply — TOCTOU re-check at delete time (v10.28.1)\n\n";

// 1. TOCTOU: basename appeared in a post body after the scan → 409, NO delete.
oa_reset();
$GLOBALS['__post_bodies'] = array( '<img src="https://example.test/wp-content/uploads/2024/01/orphan.jpg">' );
$res = snt_ai_orphan_apply_impl( 200 );
oa_true( is_wp_error( $res ), 'body-referenced attachment: apply returns WP_Error' );
oa_true( is_wp_error( $res ) && 'snt_orphan_no_longer' === $res->get_error_code(), 'body-referenced: error code snt_orphan_no_longer' );
oa_true( is_wp_error( $res ) && 409 === ( $res->get_error_data()['status'] ?? 0 ), 'body-referenced: status 409' );
oa_true( 0 === count( $GLOBALS['__delete_calls'] ), 'body-referenced: wp_delete_attachment was NEVER called' );
oa_true( array() === $GLOBALS['__deleted_transients'], 'body-referenced: verdict cache untouched (finding stays reviewable)' );

// 2. TOCTOU: became a featured image after the scan → 409, NO delete.
oa_reset();
$GLOBALS['__featured'] = array( '200' ); // get_col returns strings; parity with the scan's intval flip
$res = snt_ai_orphan_apply_impl( 200 );
oa_true( is_wp_error( $res ) && 'snt_orphan_no_longer' === $res->get_error_code(), 'featured-image race: snt_orphan_no_longer' );
oa_true( 0 === count( $GLOBALS['__delete_calls'] ), 'featured-image race: wp_delete_attachment was NEVER called' );

// 3. TOCTOU: became the site logo (chrome, lives in theme_mods) → 409, NO delete.
oa_reset();
$GLOBALS['__theme_mods'] = array( 'custom_logo' => 200 );
$res = snt_ai_orphan_apply_impl( 200 );
oa_true( is_wp_error( $res ) && 'snt_orphan_no_longer' === $res->get_error_code(), 'site-logo race: snt_orphan_no_longer' );
oa_true( 0 === count( $GLOBALS['__delete_calls'] ), 'site-logo race: wp_delete_attachment was NEVER called' );

// 4. Still genuinely orphaned → delete proceeds exactly once, force=true, cache cleared.
oa_reset();
$res = snt_ai_orphan_apply_impl( 200 );
oa_true( is_array( $res ) && true === ( $res['ok'] ?? false ) && true === ( $res['deleted'] ?? false ), 'still-orphan: ok=true deleted=true' );
oa_true( array( array( 200, true ) ) === $GLOBALS['__delete_calls'], 'still-orphan: wp_delete_attachment(200, true) called exactly once' );
oa_true( in_array( 'sn_orphan_verdict_200', $GLOBALS['__deleted_transients'], true ), 'still-orphan: verdict transient cleared' );

// 5. Pre-existing gates unchanged: capability failure → 403, nothing touched.
oa_reset();
$GLOBALS['__can_delete'] = false;
$res = snt_ai_orphan_apply_impl( 200 );
oa_true( is_wp_error( $res ) && 'snt_ai_capability' === $res->get_error_code(), 'no capability: snt_ai_capability (403 gate unchanged)' );
oa_true( 0 === count( $GLOBALS['__delete_calls'] ), 'no capability: no delete attempted' );

// 6. Pre-existing gates unchanged: attachment already gone → 422.
oa_reset();
$GLOBALS['__posts'] = array();
$res = snt_ai_orphan_apply_impl( 200 );
oa_true( is_wp_error( $res ) && 'snt_ai_not_attachment' === $res->get_error_code(), 'missing post: snt_ai_not_attachment (422 gate unchanged)' );

// 7. Failure shape preserved: wp_delete_attachment returns false → 500 (real WP failure shape).
oa_reset();
$GLOBALS['__delete_result'] = false;
$res = snt_ai_orphan_apply_impl( 200 );
oa_true( is_wp_error( $res ) && 'snt_ai_delete_failed' === $res->get_error_code(), 'delete failure (false): snt_ai_delete_failed unchanged' );

// 8. The live re-check helper itself: mirrors the scan's signals for one id.
oa_reset();
oa_true( function_exists( 'sn_health_attachment_is_referenced_now' ), 'sn_health_attachment_is_referenced_now() exists' );
if ( function_exists( 'sn_health_attachment_is_referenced_now' ) ) {
	oa_true( false === sn_health_attachment_is_referenced_now( 200, $GLOBALS['__posts'][200]['guid'] ), 'helper: genuine orphan reads false' );
	$GLOBALS['__options'] = array( 'site_icon' => 200 );
	oa_true( true === sn_health_attachment_is_referenced_now( 200, $GLOBALS['__posts'][200]['guid'] ), 'helper: site-icon reference reads true (options-backed chrome)' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
