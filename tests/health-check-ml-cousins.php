<?php
/**
 * Tests for inc/health-check-ml-cousins.php — the near-duplicate-cousins
 * health check (v10.20.0). The check is a thin adapter: snt_ml_cousin_pairs()
 * (the v10.16.0 scan, default threshold) → the standard sn_health_pack_check
 * envelope, so the count flows into the health tab, the desktop health
 * widget, and the attention badge with zero extra wiring. What this fixture
 * pins:
 *   - findings mapping: subject_label "A ↔ B", note carries the 4dp cosine
 *     + both statuses, edit_url targets pair member A;
 *   - count === pair_count; zero pairs → count 0 with empty findings
 *     (a clean scan is an ANSWER);
 *   - a malformed envelope (missing pairs) yields count 0 + empty findings,
 *     never a fabricated finding — and the kernel-absent branch is asserted
 *     via the envelope shape, not skipped.
 * Run: php tests/health-check-ml-cousins.php
 * @since plugin v10.20.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

// The check module calls the shared envelope helper from inc/health-checks.php;
// this stub is a verbatim copy of the real 5-line implementation.
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
	);
}
function __( $s, $d = null ) { return (string) $s; }
$GLOBALS['__scan_result'] = null;
function snt_ml_cousin_pairs( $threshold = 0.6 ) {
	$GLOBALS['__scan_calls'][] = $threshold;
	return $GLOBALS['__scan_result'];
}
function get_edit_post_link( $id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}

require __DIR__ . '/../inc/health-check-ml-cousins.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: pairs map to findings\n";
$GLOBALS['__scan_calls']  = array();
$GLOBALS['__scan_result'] = array(
	'ok'         => true,
	'pairs'      => array(
		array(
			'a'      => array( 'post_id' => 11, 'title' => 'The gate', 'slug' => 'the-gate', 'status' => 'publish' ),
			'b'      => array( 'post_id' => 12, 'title' => 'The gate, again', 'slug' => 'the-gate-again', 'status' => 'draft' ),
			'cosine' => 0.8412,
		),
		array(
			'a'      => array( 'post_id' => 13, 'title' => 'Alpha', 'slug' => 'alpha', 'status' => 'publish' ),
			'b'      => array( 'post_id' => 14, 'title' => 'Beta', 'slug' => 'beta', 'status' => 'publish' ),
			'cosine' => 0.61,
		),
	),
	'pair_count' => 2,
	'threshold'  => 0.6,
	'posts_scanned' => 34,
	'truncated'  => false,
	'scanned_at' => 1234,
);
$check = sn_health_check_ml_cousins();
ok( array( 0.6 ) === $GLOBALS['__scan_calls'], 'the scan runs once at the default threshold' );
ok( 2 === $check['count'] && 2 === count( $check['findings'] ), 'count === pair_count, one finding per pair' );
ok( 'Near-duplicate cousins' === $check['label'], 'label reads for the health tab' );
$f0 = $check['findings'][0];
ok( 'The gate ↔ The gate, again' === $f0['subject_label'], 'subject names BOTH members of the pair' );
ok( false !== strpos( (string) $f0['note'], '0.8412' ) && false !== strpos( (string) $f0['note'], 'publish' ) && false !== strpos( (string) $f0['note'], 'draft' ), 'note carries the 4dp cosine and both statuses' );
ok( 'https://example.test/wp-admin/post.php?post=11&action=edit' === $f0['edit_url'], 'edit_url targets pair member A' );
ok( '' !== (string) $check['fix_hint'], 'a fix hint is present' );

echo "\nGroup: honest empties\n";
$GLOBALS['__scan_result'] = array( 'ok' => true, 'pairs' => array(), 'pair_count' => 0, 'threshold' => 0.6, 'posts_scanned' => 34, 'truncated' => false, 'scanned_at' => 1234 );
$clean = sn_health_check_ml_cousins();
ok( 0 === $clean['count'] && array() === $clean['findings'], 'zero pairs → clean check (an empty scan is an ANSWER)' );

$GLOBALS['__scan_result'] = array( 'ok' => true ); // malformed: no pairs key
$mal = sn_health_check_ml_cousins();
ok( 0 === $mal['count'] && array() === $mal['findings'], 'a malformed envelope never fabricates a finding' );

$GLOBALS['__scan_result'] = array(
	'ok'    => true,
	'pairs' => array( array( 'cosine' => 0.9 ) ), // malformed row: no a/b
	'pair_count' => 1,
);
$malrow = sn_health_check_ml_cousins();
ok( 0 === $malrow['count'], 'a malformed ROW is skipped, never rendered half-empty' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
