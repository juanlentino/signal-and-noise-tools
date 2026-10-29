<?php
/**
 * Standalone test: a surfaces write reports what it replaced (2026-10-29).
 *
 * Both write modes used to answer `before: null`, so a published description
 * survived only in whatever transcript previewed it. Pins: the write reads
 * `before` ahead of the write, publish and revision report the same diff the
 * dry run previews, and the diff names only the fields the payload names.
 *
 * Run: php tests/sn-apply-surfaces-diff.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__posts'] = array( 7 => (object) array( 'ID' => 7, 'post_excerpt' => 'old excerpt', 'post_title' => 'T' ) );
$GLOBALS['__meta']  = array();
$GLOBALS['__calls'] = array();
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['__meta'][ (int) $id ][ $key ] ?? ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $code; public function __construct( $code = '' ) { $this->code = $code; } }
// The publish write overwrites the stored values, so a `before` read after it would show the new ones.
function snt_ability_update_post_surfaces( $input ) {
	$GLOBALS['__calls'][] = 'publish';
	foreach ( snt_surface_meta_keys() as $field => $key ) {
		if ( array_key_exists( $field, $input ) ) { $GLOBALS['__meta'][ (int) $input['post_id'] ][ $key ] = (string) $input[ $field ]; }
	}
	if ( array_key_exists( 'excerpt', $input ) ) { $GLOBALS['__posts'][ (int) $input['post_id'] ]->post_excerpt = (string) $input['excerpt']; }
	return array( 'ok' => true, 'card_regenerated' => false );
}
function snt_sn_apply_stage_revision( $post_id, $fields ) { $GLOBALS['__calls'][] = 'revision'; return array( 'revision_id' => 99 ); }
function snt_sn_apply_stage_meta( $post_id, $key, $value, $fp ) { $GLOBALS['__calls'][] = 'meta:' . $key; return array( 'queued' => true ); }

require __DIR__ . '/../inc/sn-apply/executors-diff-parts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function reset_post() {
	$GLOBALS['__posts'][7]->post_excerpt = 'old excerpt';
	$GLOBALS['__meta'] = array( 7 => array( '_sn_meta_description' => 'old description', '_sn_focus_keyword' => 'old keyword' ) );
}

$payload = array( 'meta_description' => 'new description', 'focus_keyword' => 'verified signature' );
$want    = array(
	'before'         => array( 'meta_description' => 'old description', 'focus_keyword' => 'old keyword' ),
	'after'          => array( 'meta_description' => 'new description', 'focus_keyword' => 'verified signature' ),
	'blocks_touched' => 0,
);

echo "Publish\n";
reset_post();
$preview = snt_sn_apply_dry_run_diff( 'surfaces', array( 'post_id' => 7 ), array( 'payload' => $payload ), array() );
ok( $want === $preview, 'the dry run previews the stored values beside the new ones' );
$r = snt_sn_apply_write_surfaces( 7, $payload, 'publish', 'fp' );
ok( $want === $r['diff'], 'the publish write reports the values it replaced, read before the write' );
ok( $preview === $r['diff'], 'and the same diff its dry run showed' );
ok( 'new description' === $GLOBALS['__meta'][7]['_sn_meta_description'], 'the write itself still lands' );

echo "\nRevision\n";
reset_post();
$GLOBALS['__calls'] = array();
$r = snt_sn_apply_write_surfaces( 7, $payload + array( 'excerpt' => 'new excerpt' ), 'revision', 'fp' );
ok( 'old excerpt' === $r['diff']['before']['excerpt'] && 'new excerpt' === $r['diff']['after']['excerpt'], 'revision mode reports the excerpt it stages over' );
ok( 'old description' === $r['diff']['before']['meta_description'], 'and the stored meta it queues over' );
ok( 99 === $r['revision_id'] && array( 'revision', 'meta:_sn_meta_description', 'meta:_sn_focus_keyword' ) === $GLOBALS['__calls'], 'the excerpt is staged as a revision and each named meta field is queued once' );

echo "\nScope\n";
reset_post();
$r = snt_sn_apply_write_surfaces( 7, array( 'seo_title' => 'A title' ), 'publish', 'fp' );
ok( array( 'seo_title' => '' ) === $r['diff']['before'], 'only the fields the payload names; an unset field was empty' );
$GLOBALS['__meta'] = array();
$r = snt_sn_apply_write_surfaces( 7, array( 'nonsense' => 'x' ), 'publish', 'fp' );
ok( array() === $r['diff']['before'] && array() === $r['diff']['after'], 'a key the write never stores is not reported as written' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
