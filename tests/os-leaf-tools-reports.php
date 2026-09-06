<?php
/**
 * Native window leaf: Tools → Reports
 * (apps/sn-dashboard/parts/leaves/tools-reports.php).
 *
 * The oracle is the classic leaf (inc/admin-render-sections.php
 * `sn_admin_render_health_reports_section()`, delegating through
 * inc/health-render-reports.php + inc/health-render-contrast.php +
 * inc/health-render-motion.php): same three states (no scan / no reports /
 * reports), same numbers, same per-report detail, none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-tools-reports.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers — stubbed rather than requiring inc/health-checks.php
// (which pulls in a long chain of per-check modules): these three are pure
// data accessors, and the fixtures below already pre-filter to the
// 'integrity' surface, so `sn_health_checks_for_surface()` need not
// re-derive the surface map.
$GLOBALS['__scan'] = null;
function sn_health_last_scan() {
	return $GLOBALS['__scan'];
}
function sn_health_checks_for_surface( $scan, $surface ) {
	unset( $surface );
	return is_array( $scan ) ? (array) ( $scan['checks'] ?? array() ) : array();
}
function sn_health_check_has_report( $check ) {
	return is_array( $check ) && ! empty( $check['report'] ) && is_array( $check['report'] );
}

// health-render-contrast.php reads this constant from inc/health-contrast-usage.php,
// which is not required here (it belongs to the scan producer, not the
// renderer) — defined directly to match the classic value.
if ( ! defined( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS' ) ) {
	define( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS', 25 );
}

// The link-isolation report's per-row "Edit" link — stubbed here (not by the
// harness) since only this leaf's report view reads it; a fixed, recognisable
// URL lets the assertions below tell "the door painted" from "nothing painted".
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}

require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/health-render-reports.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-reports.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "PASS: $m\n";
	} else {
		$fail++;
		echo "FAIL: $m\n";
	}
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/reports'] ), 'the painter is registered under tools/reports' );

// ── State 1: no scan yet.
$GLOBALS['__scan'] = null;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'No scan yet' ) && false !== strpos( $kit, 'No scan yet' ), 'no-scan state: both leaves say to run one from Measurement → Health' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no-scan state: no wp-admin markup' );

// ── State 2: a scan with no report-carrying checks.
$GLOBALS['__scan'] = array(
	'checks' => array(
		'missing_alt' => array( 'label' => 'Missing alt text', 'count' => 3 ),
	),
);
$classic = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit     = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'The last scan produced no reports' ) && false !== strpos( $kit, 'The last scan produced no reports' ), 'no-reports state: both leaves say the scan produced no reports' );

// ── State 3: a rich scan — one of each built-in report, plus an unknown key.
$rich_scan = array(
	'checks' => array(
		'contrast_tokens' => array(
			'label'  => 'Contrast tokens',
			'report' => array(
				'coverage'        => 'Reads theme.json palette tokens and stylesheet declarations.',
				'would_fail_body' => 1,
				'tokens'          => array(
					'ink'   => '#111111',
					'paper' => '#ffffff',
				),
				'thresholds'      => array(
					'aa_body'  => 4.5,
					'aa_large' => 3.0,
				),
				'pairs'           => array(
					array(
						'pair'     => 'ink/paper',
						'ratio'    => 12.5,
						'aa_body'  => true,
						'aa_large' => true,
					),
					array(
						'pair'     => 'ink/muted',
						'ratio'    => 2.1,
						'aa_body'  => false,
						'aa_large' => false,
					),
				),
				'usage'           => array(
					'scanned'     => 3,
					'pairings'    => 10,
					'palettes'    => array( 'default' ),
					'failures'    => array(
						array(
							'selector' => '.snt-badge',
							'source'   => 'assets/admin.css',
							'pair'     => 'ink/muted',
							'ratio'    => 2.1,
							'palette'  => 'default',
							'literal'  => true,
							'anchored' => true,
						),
					),
					'conditional' => array(
						array(
							'selector' => '.snt-provenance--muted',
							'pair'     => 'ink/asphalt',
							'ratio'    => 3.66,
							'palette'  => 'default',
						),
					),
				),
			),
		),
		'link_isolation'  => array(
			'label'  => 'Link isolation',
			'report' => array(
				'coverage'       => 'Reads inbound links across every published note.',
				'posts_scanned'  => 40,
				'isolated_total' => 1,
				'isolated'       => array(
					array(
						'post_id'        => 5,
						'title'          => 'Orphan note',
						'slug'           => 'orphan-note',
						'outbound_count' => 0,
					),
				),
				'truncated'      => false,
			),
		),
		'motion_scan'     => array(
			'label'  => 'Motion coverage',
			'report' => array(
				'coverage'     => 'Reads declared motion in stylesheets.',
				'scanned'      => 2,
				'motion_total' => 5,
				'gated'        => 3,
				'neutralized'  => 1,
				'uncovered'    => array(
					array(
						'sheet'    => 'assets/admin.css',
						'selector' => '.snt-spin',
						'kind'     => 'keyframe',
					),
				),
			),
		),
		'unknown_report'  => array(
			'label'  => 'Unknown report',
			'report' => array( 'coverage' => 'Some future coverage.' ),
		),
	),
);
$GLOBALS['__scan'] = $rich_scan;
$classic           = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit               = snt_leaf_paint( 'tools', 'reports' );

ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'no field names (this leaf offers no forms), matching the classic leaf' );
ok( array() === snt_leaf_actions( $classic ) && array() === snt_leaf_actions( $kit ), 'no sn_action values (this leaf offers no forms), matching the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'reports state: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

ok( false !== strpos( $kit, 'Contrast tokens' ) && false !== strpos( $kit, 'Link isolation' ) && false !== strpos( $kit, 'Motion coverage' ) && false !== strpos( $kit, 'Unknown report' ), 'all four report cards are painted, including the unknown-key one' );
ok( false !== strpos( $kit, 'Reads theme.json palette tokens and stylesheet declarations.' ) && false !== strpos( $kit, 'Reads inbound links across every published note.' ) && false !== strpos( $kit, 'Reads declared motion in stylesheets.' ), 'every coverage sentence is printed' );
ok( false !== strpos( $kit, 'This report has no detail view yet' ), 'the unknown report key falls back to the degrading message, as the classic registry does' );

// Contrast: usage tier + arithmetic tier.
ok( false !== strpos( $kit, '1 of 10 declared pairings fall below body-text AA, across 3 stylesheets and 1 palette(s).' ), 'contrast usage-tier headline matches the classic numbers' );
ok( false !== strpos( $kit, 'Scored under: default.' ), 'contrast usage-tier palette line is printed' );
ok( false !== strpos( $kit, '1 placement-dependent pairing(s)' ), 'the conditional (placement-dependent) tier count matches' );
ok( false !== strpos( $kit, '1 of 2 token pairs would fall below 4.5:1 (body-text AA) if rendered together, across 2 palette tokens.' ), 'contrast arithmetic-tier headline matches the classic numbers' );
ok( false !== strpos( $kit, 'ink/paper' ) && false !== strpos( $kit, 'Would pass 4.5:1' ) && false !== strpos( $kit, 'Would fail 4.5:1' ), 'the arithmetic pair table carries hedged would-pass/would-fail verdicts per threshold, matching the classic wording (never bare Pass/Fail)' );
ok( false !== strpos( $kit, 'ink #111111' ) && false !== strpos( $kit, 'paper #ffffff' ), 'the token legend names every token and its hex' );

// Link isolation.
ok( false !== strpos( $kit, '1 of 40 published notes have no inbound link from any other note.' ), 'link-isolation headline matches the classic numbers' );
ok( false !== strpos( $kit, 'Orphan note' ) && false !== strpos( $kit, 'orphan-note' ) && false !== strpos( $kit, '0 (both ways)' ), 'the isolated note row carries its title, slug, and the both-ways marker' );
ok( false !== strpos( $kit, 'post=5&amp;action=edit' ) && false !== strpos( $kit, 'Edit' ), 'the isolated note row carries a live Edit link (get_edit_post_link), matching the classic Action column' );

// Motion.
ok( false !== strpos( $kit, '1 of 5 declared motions have no reduced-motion counterpart — 3 gated behind no-preference, 1 neutralized under reduce, across 2 stylesheets.' ), 'motion headline matches the classic numbers' );
ok( false !== strpos( $kit, '.snt-spin' ) && false !== strpos( $kit, 'keyframe' ), 'the uncovered-motion row carries its selector and kind' );

ok( false !== strpos( $kit, '<os-disclosure' ) && false !== strpos( $kit, '<os-table' ), 'tiers fold behind os-disclosure and rows paint through os-table (never a raw <table>)' );

// ── Escaping: a hostile field value never reaches the markup raw.
$GLOBALS['__scan']['checks']['link_isolation']['report']['isolated'][0]['title'] = '"><script>x</script>';
$GLOBALS['__scan']['checks']['contrast_tokens']['report']['coverage']           = '<img src=x onerror=alert(1)>';
$kit = snt_leaf_paint( 'tools', 'reports' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile isolated-note title is escaped' );
ok( false === strpos( $kit, '<img src=x' ) && false !== strpos( $kit, '&lt;img' ), 'a hostile coverage sentence is escaped' );

// ── State 4: each report's degenerate branch, checked against the classic
// renderer as the oracle so a deleted branch cannot stay green (the mental
// break test: cutting any one of these paints the wrong counterpart string).

// link_isolation: nothing isolated.
$degenerate                                                  = $rich_scan;
$degenerate['checks']['link_isolation']['report']['isolated']       = array();
$degenerate['checks']['link_isolation']['report']['isolated_total'] = 0;
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'Every published note is reachable from at least one other note.' ) && false !== strpos( $kit, 'Every published note is reachable from at least one other note.' ), 'link_isolation with zero isolated rows: both leaves say every note is reachable' );

// link_isolation: truncated remainder line.
$degenerate                                                          = $rich_scan;
$degenerate['checks']['link_isolation']['report']['isolated_total']  = 9;
$degenerate['checks']['link_isolation']['report']['truncated']       = true;
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'Showing 1 of 9 isolated notes' ) && false !== strpos( $kit, 'Showing 1 of 9 isolated notes' ), 'link_isolation truncated: both leaves print the capped-not-complete remainder line' );

// contrast_tokens usage tier: no stylesheets readable.
$degenerate                                             = $rich_scan;
$degenerate['checks']['contrast_tokens']['report']['usage']['scanned'] = 0;
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'No stylesheets were readable' ) && false !== strpos( $kit, 'No stylesheets were readable' ), 'contrast usage tier with scanned=0: both leaves say nothing was scored' );

// contrast_tokens arithmetic tier: no palette tokens/pairs readable.
$degenerate                                     = $rich_scan;
$degenerate['checks']['contrast_tokens']['report']['pairs'] = array();
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'No theme palette tokens were readable' ) && false !== strpos( $kit, 'No theme palette tokens were readable' ), 'contrast arithmetic tier with empty pairs: both leaves say no tokens were readable' );

// motion_scan: no stylesheets scanned.
$degenerate                                        = $rich_scan;
$degenerate['checks']['motion_scan']['report']['scanned'] = 0;
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'No front stylesheets were readable' ) && false !== strpos( $kit, 'No front stylesheets were readable' ), 'motion_scan with scanned=0: both leaves say no motion was scanned' );

// motion_scan: fully covered (zero uncovered).
$degenerate                                          = $rich_scan;
$degenerate['checks']['motion_scan']['report']['uncovered'] = array();
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'Every declared motion has a reduced-motion counterpart' ) && false !== strpos( $kit, 'Every declared motion has a reduced-motion counterpart' ), 'motion_scan with zero uncovered: both leaves say every declared motion is covered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
