<?php
/**
 * Tests: inc/ai-tag-describe.php — the tag-description generator pair.
 *
 * Drives the REAL impls against stubbed WP + a captured AI helper.
 * Suggest: targets only undescribed in-use tags; named misses/described/
 * zero-post targets are skipped WITH REASON; the per-run cap holds; one
 * failed generation does not void the batch; the prompt carries the seed
 * few-shots and the tag's own titles; every call bills feature tag_describe.
 * Apply: writes only where empty (never clobbers), replays answer
 * skipped_nonempty, unknown tags 404, empty input 400.
 *
 * Run: php tests/ai-tag-describe.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function add_action( $h, $c, $p = 10, $a = 1 ) { return true; }

// Terms: name => object.
$GLOBALS['__terms'] = array();
function get_terms( $args ) { return array_values( $GLOBALS['__terms'] ); }
function get_term_by( $field, $value, $tax ) {
	if ( 'name' !== $field || 'post_tag' !== $tax ) { return false; }
	return $GLOBALS['__terms'][ $value ] ?? false;
}
$GLOBALS['__term_updates'] = array();
function wp_update_term( $id, $tax, $args ) {
	$GLOBALS['__term_updates'][] = array( 'id' => $id, 'args' => $args );
	return array( 'term_id' => $id );
}
function get_posts( $args ) {
	$id = (int) ( $args['tag_id'] ?? 0 );
	return $GLOBALS['__tag_posts'][ $id ] ?? array();
}

// AI helper doubles: gate passes; generator captures every call.
function snt_ai_require_text_generation() { return null; }
$GLOBALS['__gen_calls'] = array();
function snt_ai_generate_with_constraints( $prompt, $system, $max_tokens = 256, $feature = 'generic', $image_path = '', $image_mime = '' ) {
	$GLOBALS['__gen_calls'][] = compact( 'prompt', 'system', 'max_tokens', 'feature' );
	if ( false !== strpos( $prompt, 'Tag: Fail Me' ) ) {
		return new WP_Error( 'snt_ai_upstream', 'provider exploded' );
	}
	return '"A drafted sentence — with the house turn."';
}

require_once __DIR__ . '/../inc/tag-descriptions-seed.php'; // the REAL seed map = few-shot source
require_once __DIR__ . '/../inc/ai-tag-describe.php';

echo "ai-tag-describe pair\n\n";

echo "Group: suggest targets and skip reasons\n";
$GLOBALS['__terms'] = array(
	'Provenance'  => (object) array( 'term_id' => 1, 'name' => 'Provenance', 'count' => 35, 'description' => 'Already written.' ),
	'New Cluster' => (object) array( 'term_id' => 2, 'name' => 'New Cluster', 'count' => 4, 'description' => '' ),
	'Typo Tagg'   => (object) array( 'term_id' => 3, 'name' => 'Typo Tagg', 'count' => 0, 'description' => '' ),
);
$GLOBALS['__tag_posts'] = array( 2 => array( (object) array( 'post_title' => 'A note under the new cluster' ) ) );
$r = snt_ai_tag_describe_impl();
ok( true === $r['ok'], 'default scope runs' );
ok( 1 === count( $r['suggested'] ) && 'New Cluster' === $r['suggested'][0]['name'], 'only the undescribed in-use tag is drafted' );
ok( 'A drafted sentence — with the house turn.' === $r['suggested'][0]['description'], 'wrapping quotes stripped from the generation' );

$r = snt_ai_tag_describe_impl( array( 'Provenance', 'Typo Tagg', 'Ghost' ) );
$reasons = array();
foreach ( $r['skipped'] as $s ) { $reasons[ $s['name'] ] = $s['reason']; }
ok( 'already_described' === ( $reasons['Provenance'] ?? '' ), 'described tag refused: already_described' );
ok( 'unused_prune_instead' === ( $reasons['Typo Tagg'] ?? '' ), 'zero-post tag refused: unused_prune_instead (prune beats describe)' );
ok( 'not_found' === ( $reasons['Ghost'] ?? '' ), 'unknown name refused: not_found' );
ok( array() === $r['suggested'], 'nothing drafted when every target is refused' );

echo "\nGroup: the prompt and the bill\n";
$call = end( $GLOBALS['__gen_calls'] );
$GLOBALS['__gen_calls'] = array();
snt_ai_tag_describe_impl( array( 'New Cluster' ) );
$call = $GLOBALS['__gen_calls'][0];
$seed_map = sn_tag_description_seed_map();
ok( false !== strpos( $call['prompt'], $seed_map['Provenance'] ), 'prompt carries the owner-approved seed sentences as few-shots' );
ok( false !== strpos( $call['prompt'], 'Tag: New Cluster' ), 'prompt names the target tag' );
ok( false !== strpos( $call['prompt'], 'A note under the new cluster' ), 'prompt carries the tag\'s own post titles' );
ok( 'tag_describe' === $call['feature'], 'billed as feature tag_describe (the itemization row)' );

echo "\nGroup: one failure does not void the batch; the cap holds\n";
$GLOBALS['__terms'] = array(
	'Fail Me' => (object) array( 'term_id' => 10, 'name' => 'Fail Me', 'count' => 2, 'description' => '' ),
	'Works'   => (object) array( 'term_id' => 11, 'name' => 'Works', 'count' => 2, 'description' => '' ),
);
$GLOBALS['__tag_posts'] = array();
$r = snt_ai_tag_describe_impl();
ok( 1 === count( $r['suggested'] ) && 'Works' === $r['suggested'][0]['name'], 'the surviving draft is returned' );
$fail_reason = '';
foreach ( $r['skipped'] as $s ) { if ( 'Fail Me' === $s['name'] ) { $fail_reason = $s['reason']; } }
ok( 0 === strpos( $fail_reason, 'generation_failed' ), 'the failed tag reports generation_failed with the upstream message' );

$GLOBALS['__terms'] = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$GLOBALS['__terms'][ "Tag $i" ] = (object) array( 'term_id' => 100 + $i, 'name' => "Tag $i", 'count' => 1, 'description' => '' );
}
$GLOBALS['__gen_calls'] = array();
$r = snt_ai_tag_describe_impl();
ok( 10 === count( $r['suggested'] ) && 10 === count( $GLOBALS['__gen_calls'] ), 'per-run cap: 12 targets, exactly 10 AI calls' );
$capped = 0;
foreach ( $r['skipped'] as $s ) { if ( 'over_per_run_cap' === $s['reason'] ) { $capped++; } }
ok( 2 === $capped, 'the 2 over-cap targets report over_per_run_cap' );

echo "\nGroup: apply never clobbers\n";
$GLOBALS['__terms'] = array(
	'Empty One' => (object) array( 'term_id' => 20, 'name' => 'Empty One', 'count' => 3, 'description' => '' ),
	'Written'   => (object) array( 'term_id' => 21, 'name' => 'Written', 'count' => 3, 'description' => 'Owner prose.' ),
);
$GLOBALS['__term_updates'] = array();
$r = snt_ai_tag_describe_apply_impl( 'Empty One', 'The sentence.' );
ok( 'written' === $r['status'] && 1 === count( $GLOBALS['__term_updates'] ), 'empty description written' );
$r = snt_ai_tag_describe_apply_impl( 'Written', 'A clobber attempt.' );
ok( 'skipped_nonempty' === $r['status'] && 1 === count( $GLOBALS['__term_updates'] ), 'non-empty description untouched: skipped_nonempty, no write' );
$r = snt_ai_tag_describe_apply_impl( 'Ghost', 'Anything.' );
ok( is_wp_error( $r ) && 'snt_tag_describe_not_found' === $r->code, 'unknown tag is a 404 error' );
$r = snt_ai_tag_describe_apply_impl( '', '' );
ok( is_wp_error( $r ) && 'snt_tag_describe_bad_input' === $r->code, 'empty input is a 400 error' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
