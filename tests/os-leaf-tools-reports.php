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

// #1599: the three Info-panel facts the leaf paints as a box. The JS-package
// reader over a stubbed script registry (the shape tests/script-package-origin.php
// uses), the override count stubbed (it lives in the classic Dashboard tab),
// and the footprint module real, its scan pointed at a fixture directory.
if ( ! function_exists( 'content_url' ) ) { function content_url( $p = '' ) { return 'https://example.test/wp-content' . $p; } }
$GLOBALS['__registered'] = array();
function wp_scripts() {
	$o             = new stdClass();
	$o->registered = array();
	foreach ( (array) $GLOBALS['__registered'] as $handle => $src ) {
		$dep                      = new stdClass();
		$dep->src                 = $src;
		$o->registered[ $handle ] = $dep;
	}
	return $o;
}
$GLOBALS['__overrides'] = 0;
function snt_dashboard_override_count() { return $GLOBALS['__overrides']; }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return time(); } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
require SNT_PATH . 'inc/script-package-origin.php';
require SNT_PATH . 'inc/plugin-footprint.php';
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

// 2026-10-11 (owner rule: no collapsible sections): every paint this suite
// makes goes through here, so every state it paints is pinned fold-free.
function reports_paint() {
	static $n = 0;
	$kit   = snt_leaf_paint( 'tools', 'reports' );
	$folds = snt_leaf_folds( $kit );
	ok( array() === $folds, 'paint #' . ( ++$n ) . ' is fold-free' . ( $folds ? ': ' . implode( ',', $folds ) : '' ) );
	return $kit;
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/reports'] ), 'the painter is registered under tools/reports' );

// ── State 1: no scan yet.
$GLOBALS['__scan'] = null;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = reports_paint();
ok( false !== strpos( $classic, 'No scan yet' ) && false !== strpos( $kit, 'No scan yet' ), 'no-scan state: both leaves say to run one from Measurement → Health' );
// 2026-10-12: "Measurement → Health" is a way there on both surfaces (Trust
// checks already linked the same words), and the sentence carries no em dash.
ok( 1 === preg_match( '/<os-button[^>]*data-snt-tab="monitoring"[^>]*data-snt-sub="health"[^>]*>Measurement → Health<\/os-button>/', $kit ), 'no-scan state: the kit names Health as a go button to that leaf' );
ok( 1 === preg_match( '/<a href="[^"]*tab=monitoring&(amp;)?sub=health"[^>]*>Measurement → Health<\/a>/', $classic ), 'no-scan state: the classic page links the same words' );
ok( false === strpos( $kit, '—' ) && false === strpos( $classic, '—' ), 'no-scan state: no em dash on either surface' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no-scan state: no wp-admin markup' );

// ── State 2: a scan with no report-carrying checks.
$GLOBALS['__scan'] = array(
	'checks' => array(
		'missing_alt' => array( 'label' => 'Missing alt text', 'count' => 3 ),
	),
);
$classic = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit     = reports_paint();
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
							'source'   => 'assets/provenance.css',
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
$kit               = reports_paint();

ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'no field names (this leaf offers no forms), matching the classic leaf' );
ok( array() === snt_leaf_actions( $classic ) && array() === snt_leaf_actions( $kit ), 'no sn_action values (this leaf offers no forms), matching the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'reports state: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

ok( false !== strpos( $kit, 'Contrast tokens' ) && false !== strpos( $kit, 'Link isolation' ) && false !== strpos( $kit, 'Motion coverage' ) && false !== strpos( $kit, 'Unknown report' ), 'all four report cards are painted, including the unknown-key one' );
// 2026-10-12: a known report's coverage sentence is looked up by key at paint
// time; a stored copy froze "the usage tier below", "Colours" and em dashes
// into every scan. An unknown key keeps its own stored sentence.
ok( false !== strpos( $kit, 'Arithmetic tier: every theme-token pair scored as if rendered together.' ) && false !== strpos( $kit, 'Declared tier: every animation and transition' ) && false !== strpos( $kit, 'Reads inbound links across every published note.' ) && false !== strpos( $kit, 'Some future coverage.' ), 'every coverage sentence is printed: the current one for a known report, the stored one otherwise' );
ok( false === strpos( $kit, 'Reads theme.json palette tokens and stylesheet declarations.' ) && false === strpos( $kit, 'Reads declared motion in stylesheets.' ), '...and a known report\'s stored sentence is never painted' );
$__cl_rich = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
ok( false !== strpos( $__cl_rich, 'Arithmetic tier: every theme-token pair scored as if rendered together.' ) && false === strpos( $__cl_rich, 'Reads theme.json palette tokens' ), 'the classic page reads the same sentence by key' );
ok( false === strpos( $kit, 'contrast-render-scan' ) && false === strpos( $__cl_rich, 'contrast-render-scan' ) && false === strpos( $__cl_rich, 'colours' ) && false !== strpos( $kit, 'a scan that renders the pages in a real browser' ), 'the usage limits name the method in plain words on both surfaces, never the repo path, and say "colors"' );
ok( false === strpos( $kit, '—' ) && false === strpos( $kit, '&#8212;' ), 'no em dash anywhere in the rich kit leaf' );
ok( false === strpos( $__cl_rich, ' — ' ), 'no em dash in the classic reports either' );
ok( false !== strpos( $kit, 'This report has no detail view yet' ), 'the unknown report key falls back to the degrading message, as the classic registry does' );

// Contrast: usage tier + arithmetic tier.
ok( false !== strpos( $kit, '1 of 10 declared pairings fall below body-text AA, across 3 stylesheets and 1 palette(s).' ), 'contrast usage-tier headline matches the classic numbers' );
ok( false !== strpos( $kit, 'Scored under: default.' ), 'contrast usage-tier palette line is printed' );
// 2026-10-11: the conditional tier's count rode its fold heading; it is the
// tier's finding sentence now, with the worst ratio the rows carry.
ok( false !== strpos( $kit, '1 pairing passes on the page background and would fail on another surface the design system paints; the worst would read 3.66:1.' ), 'the conditional (placement-dependent) tier count is the finding sentence, with the worst ratio' );
ok( false !== strpos( $kit, '1 of 2 token pairs would fall below 4.5:1 (body-text AA) if rendered together, across 2 palette tokens.' ), 'contrast arithmetic-tier headline matches the classic numbers' );
ok( false !== strpos( $kit, 'ink/paper' ) && false !== strpos( $kit, 'Would pass 4.5:1' ) && false !== strpos( $kit, 'Would fail 4.5:1' ), 'the arithmetic pair table carries hedged would-pass/would-fail verdicts per threshold, matching the classic wording (never bare Pass/Fail)' );
ok( false !== strpos( $kit, 'ink #111111' ) && false !== strpos( $kit, 'paper #ffffff' ), 'the token legend names every token and its hex' );

// Link isolation.
ok( false !== strpos( $kit, '1 of 40 published notes have no inbound link from any other note.' ), 'link-isolation headline matches the classic numbers' );
ok( false !== strpos( $kit, 'Orphan note' ) && false !== strpos( $kit, 'orphan-note' ) && false !== strpos( $kit, '0 (both ways)' ), 'the isolated note row carries its title, slug, and the both-ways marker' );
ok( false !== strpos( $kit, 'post=5&amp;action=edit' ) && false !== strpos( $kit, 'Edit' ), 'the isolated note row carries a live Edit link (get_edit_post_link), matching the classic Action column' );

// Motion.
ok( false !== strpos( $classic, '1 of 5 declared motions have no reduced-motion counterpart' ) && false !== strpos( $kit, '1 of 5 declared motions have no reduced-motion counterpart: 3 gated behind no-preference, 1 neutralized under reduce, across 2 stylesheets.' ), 'motion headline matches the classic numbers' );
ok( false !== strpos( $kit, '.snt-spin' ) && false !== strpos( $kit, 'keyframe' ), 'the uncovered-motion row carries its selector and kind' );

// 2026-10-11 (owner rule: no collapsible sections). Each tier that sat in a
// fold is an open section with a short heading, in the classic order (usage
// leads, the hedged arithmetic tier last), and every figure the folds held is
// still painted: the counts, the rows, the hedge.
ok( false === strpos( $kit, '<os-disclosure' ) && false !== strpos( $kit, '<os-table' ), 'no tier folds; rows paint through os-table (never a raw <table>)' );
$at_card  = strpos( $kit, 'heading="Contrast tokens"' );
$at_usage = strpos( $kit, 'heading="Usage tier"' );
$at_cond  = strpos( $kit, 'heading="Placement-dependent pairings"' );
$at_arith = strpos( $kit, 'heading="Arithmetic tier"' );
ok( false !== $at_card && false !== $at_usage && false !== $at_cond && false !== $at_arith && $at_card < $at_usage && $at_usage < $at_cond && $at_cond < $at_arith, 'the contrast report reads card, usage tier, placement-dependent pairings, arithmetic tier, each an open section' );
ok( 0 === preg_match( '/\sheading="[^"]{61,}"/', $kit ), 'no heading is a sentence (every heading is 60 characters or fewer; the fold headings ran to 120)' );
$reports_tables = snt_leaf_tables( $kit );
$reports_rows   = static function ( $first_key, $value ) use ( $reports_tables ) {
	foreach ( $reports_tables as $t ) {
		foreach ( $t['data'] as $row ) {
			if ( isset( $row[ $first_key ] ) && $value === $row[ $first_key ] ) {
				return $row;
			}
		}
	}
	return null;
};
$cond_row = $reports_rows( 'selector', '.snt-provenance--muted (assets/provenance.css)' );
ok( null !== $cond_row && '3.66:1' === $cond_row['ratio'] && 'ink/asphalt' === $cond_row['pair'], 'a placement-dependent row names its stylesheet (the classic\'s source line), pairing and ratio' );
$fail_row = $reports_rows( 'selector', '.snt-badge (assets/admin.css)' );
ok( null !== $fail_row && '2.10:1' === $fail_row['ratio'] && false !== strpos( $fail_row['pair'], 'hardcoded' ), 'the failing-pairing row keeps its source, ratio and hardcoded marker' );
$motion_row = $reports_rows( 'sheet', 'assets/admin.css' );
ok( null !== $motion_row && '.snt-spin' === $motion_row['selector'] && 'keyframe' === $motion_row['kind'], 'the uncovered-motion row is a ledger row: sheet, selector, kind' );
ok( false !== strpos( $kit, 'moves only when a token changes' ), 'the arithmetic tier still says it is a palette property that moves only when a token changes' );
ok( false === strpos( $kit, 'snt-ledger-more' ), 'under every cap: no "+N more" line is painted' );
// The reading: the contrast card leads with one tile per tier. Only the usage
// tier's failing count may carry a warning; the placement-dependent and the
// arithmetic counts are conditional and hedged, so they paint no swatch.
ok( false !== strpos( $kit, '<os-stat value="1" label="Failing pairings" caption="of 10 declared, below body-text AA" swatch data-tone="warning">' ), 'contrast tile: 1 failing pairing of 10 declared, warning swatch' );
ok( false !== strpos( $kit, '<os-stat value="1" label="Placement-dependent" caption="pass on the page, would fail elsewhere">' ), 'contrast tile: 1 placement-dependent pairing, no swatch' );
ok( false !== strpos( $kit, '<os-stat value="1" label="Would fail if paired" caption="of 2 token pairs, a palette property">' ), 'contrast tile: the hedged arithmetic count, 1 of 2 token pairs, no swatch' );

// ── Escaping: a hostile field value never reaches the markup raw.
$GLOBALS['__scan']['checks']['link_isolation']['report']['isolated'][0]['title'] = '"><script>x</script>';
$GLOBALS['__scan']['checks']['link_isolation']['report']['coverage']            = '<img src=x onerror=alert(1)>'; // a stored sentence still paints for this key
$kit = reports_paint();
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
$kit                = reports_paint();
ok( false !== strpos( $classic, 'Every published note is reachable from at least one other note.' ) && false !== strpos( $kit, 'Every published note is reachable from at least one other note.' ), 'link_isolation with zero isolated rows: both leaves say every note is reachable' );

// 2026-10-22: the indexing report paints on both surfaces from one payload.
$ix_scan = array( 'checks' => array( 'indexing' => array(
	'label'  => 'Indexing',
	'report' => array(
		'request_list'    => array( array( 'url' => 'https://example.test/notes/a/', 'path' => '/notes/a', 'coverage_state' => 'Crawled - currently not indexed', 'days' => 12, 'core' => false, 'inbound_indexed' => 1, 'inspect_url' => 'https://search.google.com/search-console/inspect?resource_id=x&id=y' ) ),
		'request_total'   => 4,
		'stuck'           => array( array( 'url' => 'https://example.test/about/', 'path' => '/about', 'coverage_state' => 'Discovered - currently not indexed', 'days' => 40, 'core' => true, 'inbound_indexed' => null, 'inspect_url' => 'https://search.google.com/search-console/inspect?resource_id=x&id=z' ) ),
		'stuck_total'     => 1,
		'publish_check'   => array( 'checked' => 9, 'inbound_judged' => true, 'failures' => array( array( 'path' => '/notes/b', 'url' => 'https://example.test/notes/b/', 'post_type' => 'post', 'inbound_indexed' => 0, 'reasons' => array( 'inbound', 'canonical' ) ) ) ),
		'generated_at'    => 1792000000,
		'coverage_run_at' => 1791900000,
	),
) ) );
$GLOBALS['__scan'] = $ix_scan;
$classic = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit     = reports_paint();
foreach ( array( '4 URLs to request (not indexed after 7 days), 1 stuck past 30 days, 1 publish-check warnings.', '/notes/a', 'Crawled - currently not indexed', 'search-console/inspect?resource_id=x&', 'Stuck past 30 days', '/about', 'not in the link graph', 'Publish check: 1 of 9 notes and core pages need a look', 'fewer than 2 links from indexed pages (0); its canonical points elsewhere', 'Stored tier: the weekly URL Inspection map' ) as $needle ) {
	ok( false !== strpos( $classic, $needle ) && false !== strpos( $kit, $needle ), 'indexing: both surfaces print "' . $needle . '"' );
}
ok( false !== strpos( $kit, 'Showing 1 of 4' ) && false === strpos( $kit, 'This report has no detail view yet' ), 'indexing: the native leaf has its own view and states the cap' );
ok( false !== strpos( $classic, 'aria-label="Inspect /notes/a in Search Console (opens a new tab)"' ) && false !== strpos( $classic, '<caption>' ), 'indexing: the classic table has a caption and the Inspect button says what it inspects' );
ok( false === strpos( $kit, '—' ) && false === strpos( $classic, ' — ' ), 'indexing: no em dash on either surface' );
$ix_scan['checks']['indexing']['report']['coverage_run_at'] = null;
$GLOBALS['__scan'] = $ix_scan;
ok( false !== strpos( snt_leaf_classic_html( 'sn_admin_render_health_reports_section' ), 'has not stored a map yet' ) && false !== strpos( reports_paint(), 'has not stored a map yet' ), 'indexing: before the first inspection run both say so instead of a zero' );

// link_isolation: truncated remainder line.
$degenerate                                                          = $rich_scan;
$degenerate['checks']['link_isolation']['report']['isolated_total']  = 9;
$degenerate['checks']['link_isolation']['report']['truncated']       = true;
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = reports_paint();
ok( false !== strpos( $classic, 'Showing 1 of 9 isolated notes' ) && false !== strpos( $kit, 'Showing 1 of 9 isolated notes' ), 'link_isolation truncated: both leaves print the capped-not-complete remainder line' );

// contrast_tokens usage tier: no stylesheets readable.
$degenerate                                             = $rich_scan;
$degenerate['checks']['contrast_tokens']['report']['usage']['scanned'] = 0;
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = reports_paint();
ok( false !== strpos( $classic, 'No stylesheets were readable' ) && false !== strpos( $kit, 'No stylesheets were readable' ), 'contrast usage tier with scanned=0: both leaves say nothing was scored' );
ok( false !== strpos( $kit, '<os-stat value="not measured" label="Failing pairings"' ) && false === strpos( $kit, '<os-stat value="0" label="Failing pairings"' ), 'contrast usage tier with scanned=0: the failing tile reads "not measured", never 0' );

// contrast_tokens arithmetic tier: no palette tokens/pairs readable.
$degenerate                                     = $rich_scan;
$degenerate['checks']['contrast_tokens']['report']['pairs'] = array();
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = reports_paint();
ok( false !== strpos( $classic, 'No theme palette tokens were readable' ) && false !== strpos( $kit, 'No theme palette tokens were readable' ), 'contrast arithmetic tier with empty pairs: both leaves say no tokens were readable' );

// motion_scan: no stylesheets scanned.
$degenerate                                        = $rich_scan;
$degenerate['checks']['motion_scan']['report']['scanned'] = 0;
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = reports_paint();
ok( false !== strpos( $classic, 'No front stylesheets were readable' ) && false !== strpos( $kit, 'No front stylesheets were readable' ), 'motion_scan with scanned=0: both leaves say no motion was scanned' );

// motion_scan: fully covered (zero uncovered).
$degenerate                                          = $rich_scan;
$degenerate['checks']['motion_scan']['report']['uncovered'] = array();
$GLOBALS['__scan'] = $degenerate;
$classic            = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit                = reports_paint();
ok( false !== strpos( $classic, 'Every declared motion has a reduced-motion counterpart' ) && false !== strpos( $kit, 'Every declared motion has a reduced-motion counterpart' ), 'motion_scan with zero uncovered: both leaves say every declared motion is covered' );

// ── #1599: the "Plugin environment" box under the reports in every state:
// the three facts the Site Health Info panels carried and no leaf painted,
// from the same readers (snt_script_package_override_summary(),
// snt_dashboard_override_count(), sn_footprint_scan() and the shared
// sn_footprint_janitor_line()).
function reports_leaf_facts_box( $html ) {
	$at = strpos( $html, 'heading="Plugin environment"' );
	if ( false === $at ) { return ''; }
	$start = strrpos( substr( $html, 0, $at ), '<os-section' );
	$end   = strpos( $html, '</os-section>', $at );
	return substr( $html, $start, $end - $start );
}
$GLOBALS['__registered'] = array(
	'wp-components' => 'https://example.test/wp-content/plugins/gutenberg/build/components/index.js',
	'wp-element'    => 'https://example.test/wp-includes/js/dist/element.js',
);
$GLOBALS['__overrides']  = 3;
$GLOBALS['__options']['snt_janitor_log'] = array( 'version' => '9.9.9', 'freed_bytes' => 123456, 'errors_total' => 0, 'errors' => array(), 'time' => time() );
// (a) The rich scan: the box sits under the report cards, one row per fact.
$GLOBALS['__scan'] = $rich_scan;
$kit = reports_paint();
$box = reports_leaf_facts_box( $kit );
ok( '' !== $box && strrpos( $kit, 'class="snt-cols"' ) < strpos( $kit, 'heading="Plugin environment"' ), 'the environment box paints under the last report row' );
ok( false !== strpos( $box, '<os-fact label="WordPress JS packages served by"><span class="snt-kv__v">plugin:gutenberg' ) && false !== strpos( $box, 'plugin:gutenberg, 1 handle (wp-components)' ), 'the JS-package row names the plugin that serves the wp-* handles, as the Info panel does' );
ok( false !== strpos( $box, '<os-fact label="Database template/navigation overrides"><span class="snt-kv__v">3</span></os-fact>' ), 'the override-count row carries the count' );
ok( (bool) preg_match( '#<os-fact label="Total plugin-directory size"><span class="snt-kv__v">[0-9.,]+ (B|KB|MB|GB)#', $box ), 'the footprint total is a formatted size' );
ok( false !== strpos( $box, '<os-fact label="Last janitor sweep"><span class="snt-kv__v">freed 121 KB on v9.9.9</span></os-fact>' ), 'the sweep row is the Info panel\'s sentence from the same option' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup with the box painted' );
// (b) The footprint rows over a fixture: one legacy leftover counted and warn toned, the total summed.
$fixture = sys_get_temp_dir() . '/snt-reports-facts-' . getmypid();
@mkdir( $fixture . '/docs', 0777, true );
@mkdir( $fixture . '/inc', 0777, true );
file_put_contents( $fixture . '/docs/a.md', str_repeat( 'x', 2048 ) );
file_put_contents( $fixture . '/inc/b.php', str_repeat( 'y', 1024 ) );
$rows = function_exists( '\SignalNoise\OpenStationHost\Dashboard\Leaves\tools_reports_footprint_rows' ) ? \SignalNoise\OpenStationHost\Dashboard\Leaves\tools_reports_footprint_rows( $fixture ) : array();
ok( 3 === count( $rows ) && '3.0 KB' === $rows[0]['value'] && 'Legacy deploy leftovers' === $rows[1]['label'] && '1' === $rows[1]['value'] && 'warn' === $rows[1]['tone'], 'over a fixture: 3.0 KB total, the one manifest name (docs) counted as a legacy leftover and warn toned' );
unlink( $fixture . '/docs/a.md' ); unlink( $fixture . '/inc/b.php' ); rmdir( $fixture . '/docs' ); rmdir( $fixture . '/inc' ); rmdir( $fixture );
// (c) No sweep stored: no sweep row, the box still paints; and before any scan the box is there under the sentence.
unset( $GLOBALS['__options']['snt_janitor_log'] );
$GLOBALS['__scan'] = null;
$kit = reports_paint();
$box = reports_leaf_facts_box( $kit );
ok( '' !== $box && false === strpos( $box, 'Last janitor sweep' ) && strpos( $kit, 'No scan yet' ) < strpos( $kit, 'heading="Plugin environment"' ), 'no scan and no sweep: the box paints under the no-scan sentence without a sweep row' );

// ── 2026-10-11: the caps are counted out loud, never silent. At the cap the
// "+N more" line is absent; one row past it, it counts the one. 2026-10-12:
// the kit caps every report ledger at 10 rows (sorted worst-first; about 120
// open rows live before); the classic keeps its 25 inside its folds.
$edge = $rich_scan;
$row  = $rich_scan['checks']['contrast_tokens']['report']['usage']['conditional'][0];
$edge['checks']['contrast_tokens']['report']['usage']['conditional'] = array_fill( 0, 10, $row );
$GLOBALS['__scan'] = $edge;
$kit     = reports_paint();
ok( false === strpos( $kit, 'more placement-dependent pairings' ), 'kit, placement-dependent at its cap (10): no "+N more" line' );
$edge['checks']['contrast_tokens']['report']['usage']['conditional'] = array_fill( 0, 11, $row );
$GLOBALS['__scan'] = $edge;
$kit     = reports_paint();
ok( false !== strpos( $kit, '+1 more placement-dependent pairings, sorted worst-first; the tail is the safe end.' ), 'kit, one past its cap (11): "+1 more" says the list is cut' );
$edge['checks']['contrast_tokens']['report']['usage']['conditional'] = array_fill( 0, 25, $row );
$GLOBALS['__scan'] = $edge;
$classic = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
ok( false === strpos( $classic, 'more placement-dependent pairings' ), 'classic, placement-dependent at its cap (25): no "+N more" line' );
$edge['checks']['contrast_tokens']['report']['usage']['conditional'] = array_fill( 0, 26, $row );
$GLOBALS['__scan'] = $edge;
$classic = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
ok( false !== strpos( $classic, '+1 more placement-dependent pairings, sorted worst-first; the tail is the safe end.' ) && false !== strpos( $classic, 'assets/provenance.css' ), 'classic twin, one past its cap: the same "+1 more" line, and each row names its stylesheet' );

// ── Live-shaped preview: the volumes the live site carries (451
// placement-dependent pairings, ~40 failing pairings over 3 palettes, ~300
// token pairs of which ~60 would fail, ~70 uncovered motion declarations), so
// the owner judges the leaf at its real size. Written only when
// SNT_LEAF_PREVIEW_DIR is set.
$palettes = array( 'root', 'dark', 'sepia' );
$failures = array();
for ( $i = 0; $i < 40; $i++ ) {
	$failures[] = array( 'selector' => '.wp-block-sn-' . $i, 'source' => 0 === $i % 2 ? 'theme/style.css' : 'assets/front.css', 'pair' => 'muted on paper', 'ratio' => round( 1.8 + $i * 0.06, 2 ), 'palette' => $palettes[ $i % 3 ], 'literal' => 0 === $i % 7, 'anchored' => 0 !== $i % 5 );
}
$conditional = array();
for ( $i = 0; $i < 451; $i++ ) {
	$conditional[] = array( 'selector' => '.sn-chip-' . ( $i % 90 ), 'source' => 'assets/provenance.css', 'pair' => 'muted on asphalt', 'ratio' => round( 2.1 + $i * 0.005, 2 ), 'palette' => $palettes[ $i % 3 ] );
}
$tokens = array();
for ( $i = 0; $i < 25; $i++ ) {
	$tokens[ 'tone-' . $i ] = sprintf( '#%06x', $i * 655321 % 16777215 );
}
$pairs = array();
for ( $i = 0; $i < 300; $i++ ) {
	$ratio   = round( 1.2 + $i * 0.055, 2 );
	$pairs[] = array( 'pair' => 'tone-' . ( $i % 25 ) . '/tone-' . ( ( $i * 7 ) % 25 ), 'ratio' => $ratio, 'aa_body' => $ratio >= 4.5, 'aa_large' => $ratio >= 3.0 );
}
$uncovered = array();
for ( $i = 0; $i < 70; $i++ ) {
	$uncovered[] = array( 'sheet' => 0 === $i % 3 ? 'theme/style.css' : 'assets/front.css', 'selector' => '.sn-anim-' . $i, 'kind' => 0 === $i % 2 ? 'transition' : 'keyframe' );
}
$live = $rich_scan;
unset( $live['checks']['unknown_report'] );
$live['checks']['contrast_tokens']['label']                     = 'Contrast (token arithmetic, report only)';
$live['checks']['contrast_tokens']['report']['tokens']          = $tokens;
$live['checks']['contrast_tokens']['report']['pairs']           = $pairs;
$live['checks']['contrast_tokens']['report']['would_fail_body'] = 38; // a stale cache, as live on 2026.10.3
$live['checks']['contrast_tokens']['report']['usage']           = array( 'scanned' => 14, 'pairings' => 1268, 'palettes' => $palettes, 'failures' => $failures, 'conditional' => $conditional );
$live['checks']['motion_scan']['label']                         = 'Motion asks first (reduced-motion, report only)';
$live['checks']['motion_scan']['report']                        = array( 'coverage' => 'Reads declared motion in the front stylesheets.', 'scanned' => 14, 'motion_total' => 212, 'gated' => 118, 'neutralized' => 24, 'uncovered' => $uncovered );
$GLOBALS['__scan'] = $live;
$kit = reports_paint();
// 2026-10-12, live on 2026.10.3: the stored would_fail_body (a pre-fix cache
// summed every palette) printed "38 of 21". The sentence counts the pairs it
// is printed against, whatever the cache says (here the cache says 38).
$__fail = count( array_filter( $pairs, static fn( $r ) => empty( $r['aa_body'] ) ) );
ok( false !== strpos( $kit, $__fail . ' of ' . count( $pairs ) . ' token pairs would fall below' ) && false === strpos( $kit, '38 of ' . count( $pairs ) . ' token pairs' ) && $__fail <= count( $pairs ), 'the arithmetic count is read from the printed pairs (' . $__fail . ' of ' . count( $pairs ) . '), never the stored figure (38)' );
ok( 1 === preg_match( '/<os-stat value="' . $__fail . '" label="Would fail if paired" caption="of ' . count( $pairs ) . ' token pairs/', $kit ), 'the arithmetic tile counts the printed pairs too, not the stored figure' );
ok( false !== strpos( $kit, '451 pairings pass on the page background' ) && false !== strpos( $kit, '+441 more placement-dependent pairings' ), 'live-shaped: 451 placement-dependent pairings, 10 shown, "+441 more"' );
ok( false !== strpos( $kit, '40 of 1,268 declared pairings fall below body-text AA' ) && false !== strpos( $kit, '+30 more failing pairings' ), 'live-shaped: 40 failing pairings, 10 shown, "+30 more"' );
ok( false !== strpos( $kit, '60 of 300 token pairs would fall below 4.5:1' ) && false !== strpos( $kit, '+290 more pairs, every one of them at ' . number_format( (float) $pairs[9]['ratio'], 2 ) . ':1 or better' ), 'live-shaped: 300 token pairs, 10 shown, "+290 more", the floor named from the tenth row' );
ok( false !== strpos( $kit, '70 of 212 declared motions' ) && false !== strpos( $kit, '+60 more uncovered declarations' ), 'live-shaped: 70 uncovered declarations, 10 shown, "+60 more"' );
$__rows = 0;
foreach ( snt_leaf_tables( $kit ) as $__t ) {
	$__rows += count( (array) ( $__t['data'] ?? array() ) );
}
ok( $__rows <= 45, 'live-shaped: the whole leaf opens at most about 40 rows (it was about 120): ' . $__rows );
snt_leaf_preview( 'tools-reports', $kit );

// 2026-10-12, measured live (653px and 620px): the placement-dependent and
// arithmetic tiers share a row; usage and the card keep the full width.
$__rk = snt_leaf_paint( 'tools', 'reports' );
$__pl = strpos( $__rk, 'heading="Placement-dependent pairings"' );
ok( false !== $__pl && strrpos( substr( $__rk, 0, $__pl ), rtrim( snt_leaf_row(), '>' ) ) > strrpos( substr( $__rk, 0, $__pl ), 'heading="Usage tier"' ) && strpos( $__rk, 'heading="Arithmetic tier"' ) > $__pl, 'the placement-dependent and arithmetic tiers share one row, after the usage tier' );

// 2026-10-12, measured live (290px and 218px): when the motion report closes
// the list, it and the Plugin environment box share a row.
$__mo = strpos( $__rk, 'heading="Motion asks first' );
$__pe = strpos( $__rk, 'heading="Plugin environment"' );
ok( false !== $__mo && false !== $__pe && $__mo < $__pe && 1 === preg_match( '/<os-grid[^>]*>\s*<os-section[^>]*heading="Motion asks first[^"]*".*heading="Plugin environment"/s', substr( $__rk, (int) strrpos( substr( $__rk, 0, $__mo ), '<os-grid' ) ) ), 'the motion report and the Plugin environment box share one row' );
// 2026-10-12 live (1,194px leaf): the painted cards of a pair were 304 and
// 160px. Each paired row carries snt-reports-pair, whose rule makes the
// section host a column and its body part fill the row.
ok( 1 === preg_match( '/<os-grid[^>]*class="snt-reports-pair"[^>]*>\s*<os-section[^>]*heading="Motion asks first/', $__rk ) && 1 === preg_match( '/<os-grid[^>]*class="snt-reports-pair"[^>]*>\s*<os-section[^>]*heading="Placement-dependent pairings"/', $__rk ), 'both paired rows carry the fill class' );
$__css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
ok( 1 === preg_match( '/os-grid\.snt-reports-pair > os-section \{\s*display: flex;\s*flex-direction: column;/', $__css ) && 1 === preg_match( '/os-grid\.snt-reports-pair > os-section::part\( body \) \{\s*flex: 1 1 auto;/', $__css ), 'and the stylesheet stretches each painted card to the row' );
// 2026-10-12 live: "served (active style)" wrapped to three lines per row, so
// ten rows ran 662px into the 440px scroll box. One palette is said once in
// the sentence, the column goes, and a capped report table never scrolls.
$GLOBALS['__scan'] = $live;
foreach ( $GLOBALS['__scan']['checks']['contrast_tokens']['report']['usage']['conditional'] as $__i => $__r ) {
	$GLOBALS['__scan']['checks']['contrast_tokens']['report']['usage']['conditional'][ $__i ]['palette'] = 'served (active style)';
}
$__one = snt_leaf_paint( 'tools', 'reports' );
$__pd  = null;
foreach ( snt_leaf_tables( $__one ) as $__t ) {
	if ( in_array( 'Would be', array_column( (array) $__t['columns'], 'label' ), true ) ) {
		$__pd = $__t;
	}
}
ok( is_array( $__pd ) && ! in_array( 'Palette', array_column( $__pd['columns'], 'label' ), true ) && false !== strpos( $__one, 'Each is scored under the served (active style) palette.' ), 'one palette: the placement-dependent table drops its Palette column and the sentence names the palette once' );
$__multi = null;
foreach ( snt_leaf_tables( $__rk ) as $__t ) {
	if ( in_array( 'Would be', array_column( (array) $__t['columns'], 'label' ), true ) ) {
		$__multi = $__t;
	}
}
ok( is_array( $__multi ) && in_array( 'Palette', array_column( $__multi['columns'], 'label' ), true ), 'rows across several palettes keep the Palette column' );
// The usage box: the column goes when the failures share one palette; the
// sentence naming it only when several were scanned (with one, the box's
// "Scored under:" line already said it).
$__u = $live;
$__u['checks']['contrast_tokens']['report']['usage']['palettes'] = array( 'default' );
foreach ( $__u['checks']['contrast_tokens']['report']['usage']['failures'] as $__i => $__r ) {
	$__u['checks']['contrast_tokens']['report']['usage']['failures'][ $__i ]['palette'] = 'default';
}
$GLOBALS['__scan'] = $__u;
$__usage1 = snt_leaf_paint( 'tools', 'reports' );
$__ut     = null;
foreach ( snt_leaf_tables( $__usage1 ) as $__t ) {
	if ( in_array( 'Pairing', array_column( (array) $__t['columns'], 'label' ), true ) ) {
		$__ut = $__t;
	}
}
ok( is_array( $__ut ) && ! in_array( 'Palette', array_column( $__ut['columns'], 'label' ), true ), 'usage box, one palette scanned: the failures table drops its Palette column' );
ok( 1 === substr_count( $__usage1, 'Scored under: default.' ) && false === strpos( $__usage1, 'Every failing pairing is scored under' ), '...and names the palette once, in "Scored under:", not again over the table' );
$__u['checks']['contrast_tokens']['report']['usage']['palettes'] = array( 'default', 'dark', 'sepia' );
$GLOBALS['__scan'] = $__u;
$__usage3 = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $__usage3, 'Every failing pairing is scored under the default palette.' ), 'three palettes scanned, every failure under one: the sentence names which, since "Scored under:" lists three' );
$GLOBALS['__scan'] = $live;
ok( 4 === substr_count( $__one, '<os-table class="snt-table-full"' ), 'every capped report table shows all its rows, no inner scroller (usage, placement, arithmetic, motion)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
