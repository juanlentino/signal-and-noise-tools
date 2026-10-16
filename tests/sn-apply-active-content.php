<?php
/**
 * sn_apply refuses agent writes that ADD active content: <script, <iframe,
 * <object, <embed, inline on*= handlers and javascript: URLs. Each write
 * path is exercised through its own validator, so the old code (which had
 * no such check on blocks or draft content) fails here.
 *
 * The detector stands on core (WP_HTML_Tag_Processor, wp_kses_allowed_html,
 * wp_kses_bad_protocol), loaded from the SNT_WP_HTML_API seam CI fetches. A
 * machine without it FAILS rather than skips: the suite would otherwise
 * assert nothing about the code that runs.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/sn-apply-active-content.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function snt_sn_validate_finding( $surface, $check, $severity, $message, $observed, $expected, array $evidence, $id ) {
	return array( 'surface' => $surface, 'check' => $check, 'severity' => $severity, 'message' => $message, 'observed' => $observed );
}
// Only reached if the active-content check let a payload through.
function parse_blocks( $s ) { return array( array( 'blockName' => 'core/paragraph', 'innerHTML' => $s, 'innerBlocks' => array() ) ); }
function serialize_blocks( $b ) { return $b[0]['innerHTML']; }
$GLOBALS['__inserted'] = 0;
function wp_insert_post( $a, $e = false ) { $GLOBALS['__inserted']++; return 501; }
function wp_slash( $v ) { return $v; }
function get_current_user_id() { return 1; }
function get_edit_post_link( $id, $c = '' ) { return 'edit'; }
// The drift family's helpers, reached only if the refusal let a member through.
function snt_ai_drift_normalize_replacement( $r, $p = false ) { return (string) $r; }
function snt_ai_drift_replacement_error( $r, $p = false ) { return true; }
function snt_ai_drift_locate_in_raw( $c, $p, $x = '' ) { $i = strpos( $c, $p ); return false === $i ? -1 : $i; }
function snt_ai_drift_fingerprint( $c, $p, $i ) { return 'not-f'; }
// The single sentence_replace path's reads.
function current_user_can( $cap, $id = 0 ) { return true; }
function get_post( $id ) { return $GLOBALS['__post'] ?? null; }
function snt_corpus_content_hash( $c ) { return md5( (string) $c ); }

function add_action( $h, $c, $p = 10, $a = 1 ) { return true; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { return true; }
require_once __DIR__ . '/lib/wp-core-kses.php'; // Core's HTML API and kses, off SNT_WP_HTML_API.
if ( ! SNT_KSES_IS_CORE ) {
	echo "FAIL: core's HTML API and kses.php are not on this machine. Set SNT_WP_HTML_API=<wp>/wp-includes/html-api with kses.php beside it.\n";
	echo "\nResult: 0 passed, 1 failed.\n";
	exit( 1 );
}

require_once __DIR__ . '/../inc/sn-apply/active-content.php';
require_once __DIR__ . '/../inc/sn-apply/sentence-replace.php';
require __DIR__ . '/../inc/sn-apply/block-edit.php';
require __DIR__ . '/../inc/sn-apply/batch-edits.php';
require __DIR__ . '/../inc/sn-apply/create-draft.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function code_of( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }

echo "sn_apply active-content refusal\n\n";

// ── The detector, construct by construct.
$cases = array(
	'<p>x</p><script>alert(1)</script>'                => '<script',
	'<p>x</p><SCRIPT src=//e.example/a.js></SCRIPT>'    => '<script',
	'<iframe src="https://e.example"></iframe>'         => '<iframe',
	'<object data="x.swf"></object>'                    => '<object',
	'<embed src="x.swf">'                               => '<embed',
	'<img src=x onerror=alert(1)>'                      => 'onerror=',
	'<svg/onload=alert(1)>'                             => 'onload=',
	'<a title="x>y" onclick=alert(1)>z</a>'          => 'onclick=',
	'<a href="javascript:alert(1)">x</a>'               => 'javascript:',
	'<a href="&#106;avascript:alert(1)">x</a>'          => 'javascript:',
	'<a href="java&#9;script:alert(1)">x</a>'           => 'javascript:',
	'<!-- wp:button {"url":"javascript:alert(1)"} /-->' => 'javascript:',
	// Built on core: cases the old regexes read differently or not at all.
	'<form action="javascript:alert(1)"><button>x</button></form>'       => 'javascript:',
	'<form action="https://e.example/collect"><button>x</button></form>' => '<form',
	'<button formaction="javascript:alert(1)">x</button>'                => 'javascript:',
	'<p ONCLICK="alert(1)">x</p>'                                        => 'onclick=',
	'<a href=" javascript:alert(1)">x</a>'                               => 'javascript:',
	'<a href="&#106avascript:alert(1)">x</a>'                            => 'javascript:',
	'<a href="vbscript:msgbox(1)">x</a>'                                 => 'vbscript:',
	'<a href="data:text/html,x">x</a>'                                   => 'data:',
	'<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>'  => 'javascript:',
	'<img src=x"y onerror=alert(1)>'                                     => 'onerror=',
	'<style>body{background:url(//e.example/t)}</style>'                 => '<style',
	'<link rel="stylesheet" href="https://e.example/x.css">'             => '<link',
	'<meta http-equiv="refresh" content="0;url=https://e.example">'      => '<meta',
	'<base href="https://e.example/">'                                   => '<base',
	'<noscript><p title="</noscript><img src=x onerror=alert(1)>"></p></noscript>' => '<noscript',
	'<math><title><img src=x onerror=alert(1)></title></math>'           => 'onerror=',
	'<math><xmp><img src=x onerror=alert(1)></xmp></math>'               => '<xmp',
	'<p>Fine.</p><img src=x onerror=alert(1)'                            => 'onerror=',
);
foreach ( $cases as $frag => $want ) {
	ok( in_array( $want, snt_sn_apply_active_constructs( $frag ), true ), "detects $want in: $frag" );
}
foreach ( array(
	'<!-- wp:paragraph --><p>Plain prose about JavaScript: the good parts, and one = two.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>The <a href="https://example.com/online">online</a> archive, <em>once</em> more.</p><!-- /wp:paragraph -->',
	'A sentence that says "one" and someone=fine.',
	'<!-- wp:image {"id":12,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.com/a.jpg" alt="An alt, with &quot;quotes&quot;" class="wp-image-12"/><figcaption class="wp-element-caption">A caption with <a href="/notes/?a=b:c#x">a relative link</a>.</figcaption></figure><!-- /wp:image -->',
	'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li><a href="mailto:a@example.com">mail</a> and <a href="#top">top</a></li><!-- /wp:list-item --></ul><!-- /wp:list -->',
	'<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Said <code>onclick=</code> in prose, and <strong>once</strong>.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
	'<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>a</td><td><em>b</em></td></tr></tbody></table></figure><!-- /wp:table -->',
) as $clean ) {
	ok( array() === snt_sn_apply_active_constructs( $clean ), "clean prose and markup pass: $clean" );
}
$both = snt_sn_apply_active_content_error( '<script>x</script><img src=x onerror=y>', 'payload.blocks' );
ok( 'snt_sn_apply_active_content' === code_of( $both ) && 422 === $both->data['status'], 'the refusal is the named 422 snt_sn_apply_active_content' );
ok( array( '<script', 'onerror=' ) === $both->data['constructs'] && false !== strpos( $both->get_error_message(), '<script, onerror=' ), '...and it lists every offending construct, in data and in words' );

// ── block_insert / block_replace.
$evil_block = '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';
foreach ( array( 'block_insert', 'block_replace' ) as $t ) {
	$r = snt_sn_apply_block_edit_payload_error( $t, array( 'blocks' => $evil_block, 'block_path' => '0/0' ) );
	ok( 'snt_sn_apply_active_content' === code_of( $r ), "$t refuses added blocks carrying <script by name" );
}
$r = snt_sn_apply_block_edit_payload_error( 'block_insert', array( 'blocks' => '<!-- wp:image --><img src="a.jpg" onerror="x()"/><!-- /wp:image -->', 'position' => 'end' ) );
ok( 'snt_sn_apply_active_content' === code_of( $r ) && in_array( 'onerror=', $r->data['constructs'], true ), 'block_insert at end refuses an inline handler' );
$r = snt_sn_apply_block_edit_payload_error( 'block_insert', array( 'blocks' => '<!-- wp:paragraph --><p>Fine.</p><!-- /wp:paragraph -->', 'position' => 'end' ) );
ok( 'snt_sn_apply_active_content' !== code_of( $r ), 'control: clean blocks are not refused for active content' );

// ── sentence_replace (the single path and the batch path share this validator).
$phrase = 'This is a phrase of twenty or more chars.';
ok( 'snt_sn_apply_active_content' === code_of( snt_sn_apply_sentence_pair_error( $phrase, 'See <script>alert(1)</script> now.' ) ), 'sentence_replace refuses <script by name, not only as generic HTML' );
ok( 'snt_sn_apply_active_content' === code_of( snt_sn_apply_sentence_pair_error( $phrase, 'Click "javascript:alert(1)" here.' ) ), 'sentence_replace refuses a quoted javascript: URL' );
ok( 'snt_sn_apply_invalid_replacement' === code_of( snt_sn_apply_sentence_pair_error( $phrase, 'A <b>bold</b> claim.' ) ), 'control: harmless HTML keeps its existing plain-prose refusal' );
ok( true === snt_sn_apply_sentence_pair_error( $phrase, 'JavaScript: the good parts, read once more.' ), 'control: prose naming JavaScript passes' );

// ── payload.edits batch members.
$content = 'Alpha beta gamma delta epsilon zeta eta theta iota kappa.';
$r = snt_sn_apply_plan_batch_edits( $content, 'sentence_replace', array( array( 'phrase' => 'Alpha beta gamma delta epsilon', 'replacement' => '<iframe src=x></iframe>' ) ) );
ok( 'snt_sn_apply_active_content' === code_of( $r ) && false !== strpos( $r->get_error_message(), 'edit 1' ), 'a sentence_replace batch member is refused by name, naming the edit' );
$r = snt_sn_apply_plan_batch_edits( $content, 'drift_replace', array( array( 'phrase' => 'beta', 'replacement' => '<embed src=x>', 'fingerprint' => 'f' ) ) );
ok( 'snt_sn_apply_active_content' === code_of( $r ), 'a drift_replace batch member is refused by name' );

// ── A plain-prose splice inside an attribute value cannot break out of it.
// No `<` in the replacement, so the fragment check passes it; the splice check
// reads the post before and after and refuses what the splice added.
$alt_post = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="A long alt text phrase that is twenty plus chars" title="Another long title phrase of twenty plus chars"/></figure><!-- /wp:image -->';
$GLOBALS['__post'] = (object) array( 'post_content' => $alt_post );
$GLOBALS['__written'] = null;
$capture = static function ( $id, $c ) { $GLOBALS['__written'] = $c; return $id; };
foreach ( array(
	array( 'A long alt text phrase that is twenty plus chars', '" onmouseover="alert(1)', 'onmouseover=' ),
	array( 'Another long title phrase of twenty plus chars', 'x" autofocus onfocus="a', 'onfocus=' ),
) as $breakout ) {
	list( $in, $with, $want ) = $breakout;
	$r = snt_sn_apply_sentence_replace_impl( 1, $in, $with, md5( $alt_post ), '', $capture );
	ok( 'snt_sn_apply_active_content' === code_of( $r ) && in_array( $want, $r->data['constructs'], true ) && null === $GLOBALS['__written'], "sentence_replace refuses a breakout spliced into an attribute ($with), writing nothing" );
	$r = snt_sn_apply_plan_batch_edits( $alt_post, 'sentence_replace', array( array( 'phrase' => $in, 'replacement' => $with ) ) );
	ok( 'snt_sn_apply_active_content' === code_of( $r ) && in_array( $want, $r->data['constructs'], true ), "a payload.edits sentence_replace member refuses the same breakout ($with)" );
	$r = snt_sn_apply_plan_batch_edits( $alt_post, 'drift_replace', array( array( 'phrase' => $in, 'replacement' => $with, 'fingerprint' => 'not-f' ) ) );
	ok( 'snt_sn_apply_active_content' === code_of( $r ) && in_array( $want, $r->data['constructs'], true ), "a drift_replace batch member refuses the same breakout ($with)" );
}
// Control: a handler the post ALREADY carries is not the agent's doing, so an
// unrelated sentence edit elsewhere in the same post still goes through.
$old_handler = '<!-- wp:html --><button onclick="toggle()">Menu</button><!-- /wp:html --><!-- wp:paragraph --><p>This sentence is long enough to be replaced safely.</p><!-- /wp:paragraph -->';
$GLOBALS['__post'] = (object) array( 'post_content' => $old_handler );
$r = snt_sn_apply_sentence_replace_impl( 1, 'This sentence is long enough to be replaced safely.', 'This sentence was replaced, and nothing else moved.', md5( $old_handler ), '', $capture );
ok( is_array( $r ) && false !== strpos( (string) $GLOBALS['__written'], 'nothing else moved' ), 'control: an onclick already in the post does not block an unrelated sentence edit' );
$r = snt_sn_apply_plan_batch_edits( $old_handler, 'sentence_replace', array( array( 'phrase' => 'This sentence is long enough to be replaced safely.', 'replacement' => 'Changed " onclick="x()' ) ) );
ok( 'snt_sn_apply_active_content' !== code_of( $r ), 'control: a quote in prose (outside any tag) adds no construct, even beside an existing onclick' );

// ── create_draft: gate 2 finding, and the write refuses alone.
$g = snt_sn_apply_gate2_create_draft( array( 'title' => 'T', 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><script>1</script>' ) );
$hit = array_values( array_filter( $g['findings'], static function ( $f ) { return 'active_content' === $f['check']; } ) );
ok( 1 === count( $hit ) && 'error' === $hit[0]['severity'] && '<script' === $hit[0]['observed'], 'create_draft gate 2 records an error finding naming <script' );
$w = snt_sn_apply_write_create_draft( array( 'title' => 'T', 'content' => '<p onclick="x()">x</p>' ) );
ok( 'snt_sn_apply_active_content' === code_of( $w ) && 0 === $GLOBALS['__inserted'], 'the create_draft write refuses on its own and inserts nothing' );
$w = snt_sn_apply_write_create_draft( array( 'title' => 'T', 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
ok( 1 === $GLOBALS['__inserted'], 'control: clean draft content is written' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
