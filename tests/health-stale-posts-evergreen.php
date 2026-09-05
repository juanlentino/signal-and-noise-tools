<?php
/**
 * Standalone fixture tests for the stale-posts TIER SPLIT (v11.11.9).
 *
 * `_sn_evergreen` was an EXEMPTION: a flagged post was removed from Check 4's
 * query entirely, so ticking the box made the row disappear rather than explain
 * it. The flag could therefore hide genuine staleness — and once the clock moved
 * to provenance (v11.11.8), that became the difference between "the author says
 * this is timeless" and "nobody has touched the prose in two years", two facts
 * that can both be true at once.
 *
 * Now the flag PARTITIONS rather than filters:
 *   stale_posts            — fault tier, counts toward the defect total
 *   stale_posts_evergreen  — advisory tier, reported but never counted
 *
 * Run: php tests/health-stale-posts-evergreen.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
const SN_HEALTH_STALE_MONTHS = 12;
const SN_PROV_LAST_COMMIT_META = '_sn_prov_last_commit_gmt';
const ARRAY_A = 'ARRAY_A'; // wpdb output constant, defined by WP core at runtime

function get_permalink( $id ) { return 'https://x.test/?p=' . (int) $id; }
function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}

// A $wpdb stub that records the SQL and replays fixture rows.
class SN_Test_WPDB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $rows = array();
	public $last_sql = '';
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) { $sql = preg_replace( '/%s/', (string) $a, $sql, 1 ); }
		return $sql;
	}
	public function get_results( $sql, $mode = null ) { $this->last_sql = $sql; return $this->rows; }
}
$GLOBALS['wpdb'] = new SN_Test_WPDB();

require_once __DIR__ . '/../inc/health-check-stale-posts.php';

$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }

function row( $id, $title, $modified, $prov = '', $evergreen = '' ) {
	return array( 'ID' => $id, 'post_title' => $title, 'post_modified_gmt' => $modified, 'prov_gmt' => $prov, 'evergreen' => $evergreen );
}

$GLOBALS['wpdb']->rows = array(
	row( 1, 'Rotting', '2024-01-01 00:00:00', '2024-01-01 00:00:00', '' ),
	row( 2, 'Declared timeless', '2023-05-05 00:00:00', '2023-05-05 00:00:00', '1' ),
	row( 3, 'Never committed', '2024-02-02 00:00:00', '', '' ),
	row( 4, 'Timeless, no commit', '2023-09-09 00:00:00', '', '1' ),
);
$scan = sn_health_stale_posts_scan();

echo "\nGroup: the flag partitions, it does not filter\n";
ok( 4 === count( $scan['findings'] ) + count( $scan['evergreen'] ), 'EVERY stale post is returned — the flag no longer removes rows from the query' );
ok( 2 === count( $scan['findings'] ), 'unflagged posts are findings' );
ok( 2 === count( $scan['evergreen'] ), 'flagged posts are advisories, still reported' );
ok( array( 1, 3 ) === array_map( function ( $f ) { return $f['subject_id']; }, $scan['findings'] ), 'the right posts land in the fault tier' );
ok( array( 2, 4 ) === array_map( function ( $f ) { return $f['subject_id']; }, $scan['evergreen'] ), 'the right posts land in the advisory tier' );

// THE REGRESSION THIS REPLACES. The old query carried
// `AND ID NOT IN (SELECT ... _sn_evergreen ...)`, which is exactly how a flag
// hides staleness. Its absence is the whole change, so assert it directly.
echo "\nGroup: the exemption is gone from the SQL\n";
$sql = $GLOBALS['wpdb']->last_sql;
ok( false === strpos( $sql, 'NOT IN' ), 'no NOT IN subquery — the flag cannot remove a row any more' );
ok( false !== strpos( $sql, "ev.meta_key = '_sn_evergreen'" ), 'the flag is SELECTED via LEFT JOIN, so it can label instead of filter' );
ok( false !== strpos( $sql, 'COALESCE( NULLIF( pm.meta_value' ), 'still filters on the provenance clock with the post_modified fallback (v11.11.8)' );
ok( 3 === substr_count( $sql, 'COALESCE( NULLIF( pm.meta_value' ), 'the clock appears in SELECT, WHERE and ORDER BY — or LIMIT truncates on a different ordering than it filtered by' );

echo "\nGroup: each tier says what it is\n";
$fault = sn_health_check_stale_posts( $scan );
$adv   = sn_health_check_stale_posts_evergreen( $scan );
ok( 2 === $fault['count'], 'fault tier counts 2' );
ok( 2 === $adv['count'], 'advisory tier counts 2 (its own count; the summary excludes it from the defect total)' );
ok( false !== strpos( $adv['label'], 'Evergreen' ), 'the advisory check names itself' );
ok( false !== stripos( $adv['fix_hint'], 'advisory' ), 'the advisory fix_hint says it is not counted' );
ok( false !== strpos( $scan['evergreen'][0]['note'], 'marked Evergreen' ), 'an advisory row explains WHY it is not a defect' );
ok( false !== strpos( $scan['evergreen'][0]['note'], 'provenance' ), 'and still names the clock that measured it' );
ok( false !== strpos( $scan['findings'][0]['note'], 'review for currency' ), 'a fault row still asks for action' );
ok( false !== strpos( $scan['findings'][1]['note'], 'no provenance commit' ), 'a row with no commit says so rather than implying provenance measured it' );

// Sharing one query is what keeps the tiers honest: two separate queries could
// disagree about which posts are stale, or run against different cutoffs.
echo "\nGroup: one query feeds both tiers\n";
$GLOBALS['wpdb']->last_sql = '';
sn_health_check_stale_posts( $scan );
sn_health_check_stale_posts_evergreen( $scan );
ok( '' === $GLOBALS['wpdb']->last_sql, 'passing the pre-computed scan runs NO further queries' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
