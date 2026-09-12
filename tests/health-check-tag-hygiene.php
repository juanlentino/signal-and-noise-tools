<?php
/**
 * Tests: inc/health-check-tag-hygiene.php — the tag-hygiene advisory check.
 *
 * Drives the REAL sn_health_check_tag_hygiene() against a stubbed taxonomy.
 * Properties: (1) a described, in-use tag produces NO finding; (2) an
 * undescribed in-use tag reports type=undescribed; (3) a zero-post tag
 * reports ONCE as type=unused even when also undescribed (prune beats
 * describe); (4) a WP_Error taxonomy read is a SKIP, not a zero — absence of
 * the sensor is not a clean bill; (5) the envelope is pack_check-shaped with
 * skipped=null when the check ran.
 *
 * Run: php tests/health-check-tag-hygiene.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

class WP_Error {
	public $code;
	public function __construct( $c = '' ) { $this->code = $c; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['__terms_result'] = array();
function get_terms( $args ) { return $GLOBALS['__terms_result']; }
// #1178: term->count is PUBLISH-ONLY. "Unused" is decided on term_relationships
// across every status; the stub maps term_id => object ids.
$GLOBALS['__rels'] = array();
function get_objects_in_term( $term_id, $tax ) { return $GLOBALS['__rels'][ (int) $term_id ] ?? array(); }

// The real envelope helper, restated per its contract (requiring the full
// health-checks.php here drags in the whole scan layer; the admin-registry
// suite pins the real one's shape).
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
		'skipped'  => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null,
	);
}

require_once __DIR__ . '/../inc/health-check-tag-hygiene.php';

echo "health check: tag hygiene\n\n";

echo "Group: the three tag states\n";
$GLOBALS['__terms_result'] = array(
	(object) array( 'term_id' => 1, 'name' => 'Provenance', 'count' => 35, 'description' => 'A written sentence.' ),
	(object) array( 'term_id' => 2, 'name' => 'Authorship', 'count' => 13, 'description' => '' ),
	(object) array( 'term_id' => 3, 'name' => 'Typo Tagg', 'count' => 0, 'description' => '' ),
);
$GLOBALS['__rels'] = array( 1 => range( 101, 135 ), 2 => range( 201, 213 ), 3 => array() );
$r = sn_health_check_tag_hygiene();
ok( null === $r['skipped'], 'check ran (skipped is null)' );
ok( 2 === $r['count'], 'described+used tag is clean; the other two report (got ' . $r['count'] . ')' );
$types = array();
foreach ( $r['findings'] as $f ) { $types[ $f['name'] ] = $f['type']; }
ok( 'undescribed' === ( $types['Authorship'] ?? '' ), 'in-use empty-description tag reports as undescribed' );
ok( 'unused' === ( $types['Typo Tagg'] ?? '' ), 'zero-post tag reports as unused' );
$typo_rows = 0;
foreach ( $r['findings'] as $f ) { if ( 'Typo Tagg' === $f['name'] ) { $typo_rows++; } }
ok( 1 === $typo_rows, 'a zero-post undescribed tag reports ONCE (prune beats describe)' );

echo "\nGroup: #1178 -- count is publish-only; a tag held by scheduled/draft notes is NOT unused\n";
$GLOBALS['__terms_result'] = array(
	(object) array( 'term_id' => 4, 'name' => 'Scheduled Only', 'count' => 0, 'description' => '' ),
);
$GLOBALS['__rels'] = array( 4 => array( 401, 402 ) ); // two scheduled notes: count 0, relationships 2
$r = sn_health_check_tag_hygiene();
ok( 1 === $r['count'] && 'undescribed' === $r['findings'][0]['type'], 'count 0 + relationships > 0 reports as undescribed, never unused' );
ok( 2 === $r['findings'][0]['posts'], '...and posts reports the relationship count (2), not the publish-only 0' );
$GLOBALS['__rels'] = array( 4 => array() );
$r = sn_health_check_tag_hygiene();
ok( 'unused' === $r['findings'][0]['type'], 'count 0 + zero relationships is unused' );

echo "\nGroup: whitespace is not a description\n";
$GLOBALS['__rels'] = array( 5 => range( 1, 6 ) );
$GLOBALS['__terms_result'] = array(
	(object) array( 'term_id' => 5, 'name' => 'Standards', 'count' => 6, 'description' => "  \n " ),
);
$r = sn_health_check_tag_hygiene();
ok( 1 === $r['count'] && 'undescribed' === $r['findings'][0]['type'], 'whitespace-only description counts as undescribed' );

echo "\nGroup: a failed read is a SKIP, never a clean zero\n";
$GLOBALS['__terms_result'] = new WP_Error( 'invalid_taxonomy' );
$r = sn_health_check_tag_hygiene();
ok( is_string( $r['skipped'] ) && '' !== $r['skipped'], 'WP_Error taxonomy read reports skipped, with a reason' );
ok( 0 === $r['count'], 'and carries zero findings rather than inventing any' );

echo "\nGroup: empty vocabulary is a real, clean answer\n";
$GLOBALS['__terms_result'] = array();
$r = sn_health_check_tag_hygiene();
ok( 0 === $r['count'] && null === $r['skipped'], 'no tags at all → ran, zero findings' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
