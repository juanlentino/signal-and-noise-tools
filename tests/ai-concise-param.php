<?php
/**
 * Tests the `concise` param threading + the meta-description truncation guard.
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// ─── i18n + WP_Error minimal stubs ───
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $msg; public $data;
		public function __construct( $code = '', $msg = '', $data = array() ) { $this->code = $code; $this->msg = $msg; $this->data = $data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

// ─── AI builder recorder (mirrors tests/ai-bootstrap.php) ───
$GLOBALS['__test_ai_builder_recorded_calls'] = array();
$GLOBALS['__test_ai_builder_supports_text']  = true;
$GLOBALS['__test_ai_builder_generate_returns'] = 'A generated sentence.';
// v6.29.0: the wrapper now calls generate_text_result(). A null TokenUsage
// here exercises the "provider didn't populate usage → recorder no-ops"
// path, so this concise-param fixture needs no get_option stubs.
class CpAiResult {
	private $t;
	public function __construct( $t ) { $this->t = (string) $t; }
	public function toText() { return $this->t; }
	public function getTokenUsage() { return null; }
}
class TestAiBuilder {
	public function __call( $name, $args ) {
		$GLOBALS['__test_ai_builder_recorded_calls'][] = array( 'name' => $name, 'args' => $args );
		if ( 'is_supported_for_text_generation' === $name ) { return (bool) $GLOBALS['__test_ai_builder_supports_text']; }
		if ( 'generate_text' === $name ) { return $GLOBALS['__test_ai_builder_generate_returns']; }
		if ( 'generate_text_result' === $name ) { return new CpAiResult( $GLOBALS['__test_ai_builder_generate_returns'] ); }
		return $this;
	}
}
if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	function wp_ai_client_prompt( $p = null ) { return new TestAiBuilder(); }
}
function cp_reset_calls() { $GLOBALS['__test_ai_builder_recorded_calls'] = array(); }
function cp_recorded( $method ) {
	foreach ( $GLOBALS['__test_ai_builder_recorded_calls'] as $c ) {
		if ( $c['name'] === $method ) { return $c['args']; }
	}
	return null;
}

// ─── WP primitives so the REAL snt_ai_extract_post_text() (declared,
// unguarded, in inc/ai-bootstrap.php) returns canned 300-word content and
// the impls reach the generator. We do NOT stub the extractor itself — the
// bootstrap declares it without a function_exists guard, so a stub here
// would fatal on redeclare. ──
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { $p = new stdClass(); $p->post_content = str_repeat( 'word ', 300 ); $p->post_title = 'Title'; return $p; }
}
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $s ) { return $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $text, $num = 55, $more = null ) { $w = preg_split( '/\s+/', trim( $text ) ); return implode( ' ', array_slice( $w, 0, $num ) ); } }
// apply_filters passthrough for snt_ai_model_preference used by the generator.
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }


// v10.24.0: snt_word_count() is a real runtime dependency (pure module).
require_once __DIR__ . '/../inc/word-count.php';

require_once __DIR__ . '/../inc/ai-bootstrap.php';
require_once __DIR__ . '/../inc/ai-meta-description.php';
require_once __DIR__ . '/../inc/ai-excerpt.php';

$pass = 0; $fail = 0;
function cp_true( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
function cp_eq( $e, $a, $msg ) { global $pass, $fail; if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; } }

// ── Meta description: non-concise uses the original system + 150 tokens ──
cp_reset_calls();
snt_ai_meta_desc_impl( 1, false );
cp_eq( array( SNT_AI_META_DESC_SYSTEM ), cp_recorded( 'using_system_instruction' ), 'meta-desc non-concise → original system instruction' );
cp_eq( array( SNT_AI_META_DESC_MAX_TOKENS ), cp_recorded( 'using_max_tokens' ), 'meta-desc non-concise → 150 max tokens' );

// ── Meta description: concise uses the concise system + 80 tokens ──
cp_reset_calls();
snt_ai_meta_desc_impl( 1, true );
cp_eq( array( SNT_AI_META_DESC_SYSTEM_CONCISE ), cp_recorded( 'using_system_instruction' ), 'meta-desc concise → concise system instruction' );
cp_eq( array( SNT_AI_META_DESC_MAX_TOKENS_CONCISE ), cp_recorded( 'using_max_tokens' ), 'meta-desc concise → reduced max tokens' );

// ── Excerpt: concise differs from non-concise ──
cp_reset_calls();
snt_ai_excerpt_impl( 1, false );
cp_eq( array( SNT_AI_EXCERPT_SYSTEM ), cp_recorded( 'using_system_instruction' ), 'excerpt non-concise → original system instruction' );
cp_reset_calls();
snt_ai_excerpt_impl( 1, true );
cp_eq( array( SNT_AI_EXCERPT_SYSTEM_CONCISE ), cp_recorded( 'using_system_instruction' ), 'excerpt concise → concise system instruction' );
cp_eq( array( SNT_AI_EXCERPT_MAX_TOKENS_CONCISE ), cp_recorded( 'using_max_tokens' ), 'excerpt concise → reduced max tokens' );

// ── Truncation guard ──
$long = str_repeat( 'alpha ', 60 ); // 360 chars
$cut  = snt_ai_truncate_meta_description( $long, 155 );
cp_true( strlen( $cut ) <= 155, 'truncation caps a 360-char string to <= 155' );
cp_true( substr( $cut, -1 ) !== ' ', 'truncation does not leave a trailing space' );
$short = 'A concise description.';
cp_eq( $short, snt_ai_truncate_meta_description( $short, 155 ), 'truncation leaves a short string unchanged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
