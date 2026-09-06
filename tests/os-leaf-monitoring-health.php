<?php
/**
 * Native window leaf: Monitoring → Health (apps/sn-dashboard/parts/leaves/monitoring-health.php).
 *
 * The oracle is the classic leaf (`sn_health_render_admin_tab()`,
 * inc/health-checks-admin.php): the kit leaf must carry the same one action
 * (`health_scan`), no field names at all (the classic form has none besides
 * the shared nonce), the same hero numbers, the same finding/passing/skipped
 * readouts for a rich fixture, and none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-monitoring-health.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── The leaf's own reader: sn_health_last_scan(), fixture-controlled. ──
$GLOBALS['__health_scan'] = null;
function sn_health_last_scan() {
	return $GLOBALS['__health_scan'];
}

// ── Pure accessor + family/surface modules (no WordPress DB calls). ──
require SNT_PATH . 'inc/health-check-families.php';
require SNT_PATH . 'inc/health-check-surfaces.php';
require SNT_PATH . 'inc/health-summary.php';
require SNT_PATH . 'inc/admin-glance.php';

// ── The classic renderer + its section modules. ──
require SNT_PATH . 'inc/health-checks-admin.php';
require SNT_PATH . 'inc/health-render-findings.php';
require SNT_PATH . 'inc/health-render-passing.php';
require SNT_PATH . 'inc/health-render-reports.php';

// ── The kit leaf. ──
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-health.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++; echo "PASS: $m\n";
	} else {
		$fail++; echo "FAIL: $m\n";
	}
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/health'] ), 'the painter is registered under monitoring/health' );

// ── State 1: no scan yet. Hero shows the "no scan" card, the form offers
// "Run scan", and nothing below it renders (mirrors the classic early return).
$classic = snt_leaf_classic_html( 'sn_health_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'health' );
ok( '' !== $kit, 'the kit leaf paints with no scan' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( '_wpnonce', 'sn_action' ) === snt_leaf_names( $kit ), 'no scan: both forms carry only the nonce + sn_action (the run-scan form has no real fields)' );
ok( array( 'health_scan' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'no scan: the one action is health_scan, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no scan: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'no scan' ) && false !== strpos( $kit, 'Run scan' ), 'no scan: the hero reads "no scan" and the button reads "Run scan"' );
ok( false === strpos( $kit, 'Findings' ) && false === strpos( $kit, 'Also scanned' ), 'no scan: no section below the form renders (mirrors the classic early return)' );

// ── State 2: a rich scan. Two health-surfaced faults (one with a hidden-row
// remainder), one health-surfaced passing check, one skipped check, plus one
// worklist-surfaced and one integrity-surfaced check for the "elsewhere" index.
$findings_alpha = array();
for ( $i = 0; $i < 52; $i++ ) {
	$findings_alpha[] = array(
		'subject_label' => 'Post ' . $i,
		'subject_url'   => 'https://example.test/post-' . $i,
		'note'          => 'missing width',
		'edit_url'      => 'https://example.test/wp-admin/post.php?post=' . $i . '&action=edit',
	);
}
$GLOBALS['__health_scan'] = array(
	'scanned_at' => time() - 3600,
	'elapsed_ms' => 812,
	'checks'     => array(
		'test_alpha_check' => array(
			'label'     => 'Alpha check',
			'count'     => 52,
			'fix_hint'  => 'Add alt text to every image.',
			'findings'  => $findings_alpha,
		),
		'test_beta_check'  => array(
			'label'  => 'Beta check',
			'count'  => 1,
			'findings' => array(
				array( 'subject_label' => 'Malicious <b>title</b>', 'note' => '"><script>x</script>', 'edit_url' => '' ),
			),
		),
		'test_gamma_check' => array(
			'label' => 'Gamma check',
			'count' => 0,
		),
		'test_delta_check' => array(
			'label'   => 'Delta check',
			'count'   => 0,
			'skipped' => 'AI provider not configured',
			'fix_hint' => 'Configure an AI provider.',
		),
		'link_opportunities' => array(
			'label' => 'Link opportunities',
			'count' => 4,
		),
		'contrast_tokens'    => array(
			'label'  => 'Contrast tokens',
			'count'  => 0,
			'report' => array( 'coverage' => 'Every published palette pair.' ),
		),
	),
);

$classic = snt_leaf_classic_html( 'sn_health_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'health' );

ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( '_wpnonce', 'sn_action' ) === snt_leaf_names( $kit ), 'rich scan: still just the nonce + sn_action on either leaf' );
ok( array( 'health_scan' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'rich scan: still the one health_scan action' );
ok( array() === snt_leaf_classic_markers( $kit ), 'rich scan: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'Re-run scan' ), 'rich scan: the button relabels to "Re-run scan"' );
ok( false !== strpos( $kit, '53 findings' ), 'rich scan: the hero total is 53 findings (52 + 1, worklist-surfaced link_opportunities excluded)' );
ok( false !== strpos( $kit, '812ms' ), 'rich scan: the last-scan elapsed time is formatted (812ms)' );
ok( false !== strpos( $kit, 'Alpha check' ) && false !== strpos( $kit, '52 findings' ), 'rich scan: the Alpha check card shows its label and its 52-finding count' );
ok( false !== strpos( $kit, '+2 more findings' ), 'rich scan: the 52-row table caps at 50 and names the remainder' );
ok( false !== strpos( $kit, 'Gamma check' ), 'rich scan: the zero-finding, non-skipped Gamma check appears in the passing disclosure' );
ok( false !== strpos( $kit, 'could not run' ) && false !== strpos( $kit, 'Delta check' ) && false !== strpos( $kit, 'AI provider not configured' ), 'rich scan: the skipped Delta check names itself and its reason' );
ok( false !== strpos( $kit, 'Also scanned, shown elsewhere' ) && false !== strpos( $kit, 'Link opportunities (4)' ) && false !== strpos( $kit, 'Contrast tokens' ), 'rich scan: the elsewhere index names the worklist- and integrity-surfaced checks' );
ok( false === strpos( $kit, 'Findings') || ( false === strpos( $kit, 'Link opportunities') || strpos($kit, 'Link opportunities') > strpos($kit, 'Also scanned') ), 'rich scan: link_opportunities (worklist-surfaced) is not double-counted into the on-tab Findings section' );

// ── Escaping: a hostile subject/note never reaches the markup raw.
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit, '<b>title</b>' ), 'rich scan: a hostile finding subject/note is escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
