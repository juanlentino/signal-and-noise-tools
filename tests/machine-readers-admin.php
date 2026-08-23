<?php
/**
 * Tests: Machine Readers admin registration + settings save (Session 3 lane 4).
 * SCAFFOLD-RED: written against the shells on purpose; lane 4 turns it green.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__opts'] = array( 'sn_machine_readers_preview' => '1' );
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
// Storage-side URL sanitizer stub (lane 4): valid http(s) URL passes, else ''.
function esc_url_raw( $url ) { $url = trim( (string) $url ); return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }

// v10.2.2 composition group: enough WP surface to render the whole tab.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
// Real _n() shape: returns (never echoes) and selects on the count.
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function current_user_can( $cap ) { return true; }
function wp_nonce_field( $action ) { echo '<!-- nonce -->'; }
function sn_setting( $k, $d = '' ) { return $d; }
$GLOBALS['__mr_rows'] = array(
	array( 'family' => 'openai', 'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 3 ),
	array( 'family' => 'uptime', 'surface' => 'html',   'day' => '2026-07-28', 'hits' => 40 ),
);
$GLOBALS['__mr_feed'] = array( 'most_recent' => '2026-07-28 12:00:00', 'windows' => array( 7 => array( 'total' => 42, 'uniques' => 9 ), 30 => array( 'total' => 946, 'uniques' => 30 ) ) );
function snt_mr_fetch( $days ) { return array( 'ok' => true, 'rows' => $GLOBALS['__mr_rows'] ); }
function snt_mr_sensor_info() { return array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T18:07:56Z' ); }
function snt_mr_crawler_list_status() { return array( 'last_check_ok' => '1', 'last_check_drift' => '' ); }
function sn_rss_tracker_window_stats_multi( $windows ) { return $GLOBALS['__mr_feed']; }

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-render-taxonomy.php'; // v10.79.0: the tab renders purpose/vendor tables.
require __DIR__ . '/../inc/machine-readers-compose.php'; // v12.22.0: snt_mr_render_tab() delegates the arrangement to the pure composer.
require __DIR__ . '/../inc/machine-readers-admin.php';

echo "Group: registry callback (preview-flag gated, v9.67.0 Overview pattern)\n";
$tabs_in = array( 'analytics' => array( 'label' => 'Analytics' ), 'tools' => array( 'label' => 'Tools' ) );
$tabs = snt_mr_admin_register( $tabs_in );
ok( false !== strpos( json_encode( $tabs ), 'machine-readers' ), 'flag ON: registry gains the machine-readers entry' );
ok( isset( $tabs['analytics'], $tabs['tools'] ), 'existing entries preserved' );
// Integration pin: the registry's declared render entrypoint must actually be
// defined in this module, or the dispatcher's is_callable guard renders a
// SILENTLY blank tab (no fatal, no error) when the preview flag is on.
ok( 'snt_mr_render_tab' === ( $tabs['machine-readers']['render'] ?? '' ) && function_exists( 'snt_mr_render_tab' ), 'registry render entrypoint snt_mr_render_tab exists (no silently blank tab)' );
$GLOBALS['__opts']['sn_machine_readers_preview'] = '';
$tabs = snt_mr_admin_register( $tabs_in );
ok( false !== strpos( json_encode( $tabs ), 'machine-readers' ), 'v10.0.0 GA: the tab no longer depends on the preview option (retired; its row is deleted by the orphan migration)' );
$GLOBALS['__opts']['sn_machine_readers_preview'] = '1';

echo "\nGroup: settings save — subtree preservation (the 4x-bitten class) + write-only token\n";
$settings_in = array(
	'analytics'       => array( 'exclude_roles' => array( 'editor' ) ),
	'seo'             => array( 'copy' => 'x' ),
	'machine_readers' => array( 'worker_url' => 'https://old.example/mr', 'read_token' => 'old-secret' ),
);
$snapshot = json_encode( $settings_in );
$out = snt_mr_settings_save( array( 'worker_url' => 'https://juanlentino.com/_sn/rights-signals/machine-readers', 'read_token' => 'new-secret' ), $settings_in );
ok( ( $out['machine_readers']['worker_url'] ?? '' ) === 'https://juanlentino.com/_sn/rights-signals/machine-readers', 'worker_url saved under the machine_readers subtree' );
ok( ( $out['machine_readers']['read_token'] ?? '' ) === 'new-secret', 'token saved when provided' );
ok( ( $out['analytics']['exclude_roles'][0] ?? '' ) === 'editor' && ( $out['seo']['copy'] ?? '' ) === 'x', 'every foreign subtree preserved' );
ok( json_encode( $settings_in ) === $snapshot, 'input settings array not mutated (immutability)' );
$out2 = snt_mr_settings_save( array( 'worker_url' => 'https://juanlentino.com/_sn/rights-signals/machine-readers', 'read_token' => '' ), $settings_in );
ok( ( $out2['machine_readers']['read_token'] ?? '' ) === 'old-secret', 'blank token field keeps the stored token (write-only semantics)' );

echo "\nGroup: v10.2.3 — the tab IS the Analytics leaf silhouette (owner UAT, third pass)\n";
ob_start();
snt_mr_render_tab();
$tab = ob_get_clean();
// The Analytics leaf skeleton: one capped hero card, then ONE .sn-2up of two
// flat cards, everything inside a card. Exactly three fieldsets, no more.
ok( false !== strpos( $tab, 'sn-an-settings-leaf' ), 'whole tab wrapped in the Analytics leaf class' );
ok( false !== strpos( $tab, 'sn-fieldset sn-an-pipeline sn-mr-hero' ), 'sensor status is the capped Pipeline-status hero (no --wide divergence)' );
ok( false === strpos( $tab, 'sn-fieldset--wide' ), 'no width modifiers — the Analytics leaf has exactly one width system' );
ok( 1 === substr_count( $tab, 'sn-2up' ), 'exactly ONE two-column row, like Analytics' );
ok( 3 === preg_match_all( '/<div class="sn-fieldset[" ]/', $tab ), 'exactly three cards: hero + data column + sensor column (sn-fieldset-actions is not a card)' );
// Order: status first (like Pipeline status), then the data.
ok( strpos( $tab, 'sn-an-pipeline' ) < strpos( $tab, 'sn-kpi-row' ), 'Sensor status renders ABOVE the readership data, like Analytics' );
// Everything lives inside a card — no bare prose above the hero.
ok( false === strpos( $tab, 'sn-prose' ), 'no bare intro paragraph; the intro is the data card help line' );
// All four tables stack as sections INSIDE the left data card (one card, many
// sections — the Analytics right-column pattern).
// v12.22.0 RECOMPOSITION. The leaf is no longer "one data card of stacked
// sections". A right column exists only when the leaf has a second job, and this
// one has two — evidence (who read the rights) wide, reference + settings narrow
// and folded. See docs/proposals/admin-leaf-composition-2026-08-23.md.
//
// These assertions changed DELIBERATELY: they pinned the old arrangement, and
// the arrangement is what the release changes. The load-bearing content
// assertions below are unchanged in meaning — only re-anchored from the removed
// .sn-mr-data card to the whole tab, because presence was always the claim and
// the card was only ever where it happened to live.
ok( false !== strpos( $tab, 'sn-fieldset sn-mr-evidence' ), 'the wide column is the evidence card' );
ok( false !== strpos( $tab, 'sn-fieldset sn-mr-reference' ), 'the narrow column is the reference card' );
ok( strpos( $tab, 'sn-mr-evidence' ) < strpos( $tab, 'sn-mr-reference' ), 'evidence comes before reference — the primary job leads' );
// The numbers moved OUT of a column and into the hero, where they summarise the
// whole leaf rather than the top of one side (the owner's actual complaint).
ok( strpos( $tab, 'sn-mr-hero' ) < strpos( $tab, 'sn-kpi-row' )
	&& strpos( $tab, 'sn-kpi-row' ) < strpos( $tab, 'sn-2up' ), 'the KPI row sits in the hero, above the two columns' );
// Reference material is FOLDED — that is what keeps the two columns the same
// order of height, which is the whole fix.
// FIVE, not seven: this fixture is a legacy (pre-taxonomy) sensor, so
// vendor/purpose and AI-reconciliation render nothing at all, and snt_mr_fold()
// suppresses a fold whose body would be empty. A closed disclosure promising a
// table and opening on nothing is worse than no disclosure — so the count being
// lower than the number of sections is the contract, not a shortfall.
ok( 5 === substr_count( $tab, 'sn-mr-fold' ), 'the reference tables are folded, and empty sections produce no fold (' . substr_count( $tab, 'sn-mr-fold' ) . ')' );
ok( '' === snt_mr_fold( 'anything', '   ' ), 'snt_mr_fold() returns nothing for empty content' );

$sn_caps = array(

	'Reads per crawler family',
	'Reads per machine surface class',
	'Observed vs declared',
	'Feed fetches per window',
	'Unclassified user agents',
	'Rights-surface reads, in full',
);
$sn_missing = array();
foreach ( $sn_caps as $sn_cap ) {
	if ( false === strpos( $tab, $sn_cap ) ) { $sn_missing[] = $sn_cap; }
}
ok( empty( $sn_missing ), 'every expected table is still rendered somewhere in the leaf (missing: ' . ( $sn_missing ? implode( ', ', $sn_missing ) : 'none' ) . ')' );
// The load-bearing one: an older sensor must not render a purpose table full of
// zeroes. Never-measured and measured-zero are different answers.
ok( false !== stripos( $tab, 'not a measured zero' ), 'a pre-taxonomy sensor states the absence instead of fabricating purpose rows' );
// The sensor readout uses the Analytics Edge-worker treatment (native notice),
// not the invented gray bar.
ok( false !== strpos( $tab, 'notice notice-info notice-alt inline' ), 'Edge sensor readout is the Analytics notice-info treatment' );
ok( false === strpos( $tab, 'sn-an-worker-card' ), 'the invented gray worker-card class is gone' );
// The strip carries the feed chip from the same tracker read the table uses.
ok( 2 === substr_count( $tab, '946' ), 'the 30d feed total reaches the strip chip AND the feed table (one tracker read)' );

echo "\nGroup: v10.2.6 — zebra tables carry gutters from the CENTRAL sheet\n";
$sn_an_css = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-admin.css' );
$sn_mr_css = (string) file_get_contents( __DIR__ . '/../assets/machine-readers.css' );
ok( 1 === preg_match( '/\.sn-an-table\.widefat th,\s*\.sn-an-table\.widefat td \{ padding: 8px 12px; \}/', $sn_an_css ), 'central sheet gives striped sn-an-tables the dashboard cell gutters (the glued-text fix, plugin-wide)' );
ok( false !== strpos( $sn_an_css, '.sn-an-table.widefat td:last-child { padding-right: 12px; }' ), 'the trailing number column keeps a right gutter (not glued to the card border)' );
ok( false === strpos( $sn_mr_css, '6px 10px 6px 0' ), 'machine-readers.css no longer carries its own flush cell override (superseded by the central rule)' );
// MR4: a CLOSED fold is a section of this card, so it takes the caption
// rhythm. Without this the summary crowds the block above it — the shared
// .sn-disclosure margin is tuned for the Health tab's tighter stack.
ok( 1 === preg_match( '/\.sn-mr-reference \.sn-disclosure \{ margin-top: 20px; \}/', $sn_mr_css ), 'MR4: a closed fold takes the same 20px section rhythm the table captions take' );
ok( 1 === preg_match( '/\.sn-mr-reference \.sn-disclosure \.sn-an-table \{ margin-top: 8px; \}/', $sn_mr_css ), 'MR4: inside an open fold the table sits closer to its own summary (which is now the section heading)' );
// The rules are only reachable if the folds actually carry the shared class —
// pin the three renderers' class lists against the sheet they depend on.
$sn_mr_render_src = (string) file_get_contents( __DIR__ . '/../inc/machine-readers-render-taxonomy.php' );
foreach ( array( 'sn-mr-rights-log', 'sn-mr-unknown-log', 'sn-mr-vendor-purpose' ) as $sn_mr_fold ) {
	ok( false !== strpos( $sn_mr_render_src, '<details class="' . $sn_mr_fold . ' sn-disclosure"' ), "MR4: the {$sn_mr_fold} fold carries the shared .sn-disclosure class, so the caret and rhythm rules reach it" );
}
// Non-vacuity: the CSS file must actually parse as rules, not be truncated
// mid-comment (the v10.83.0 comment-delimiter lesson).
ok( substr_count( $sn_mr_css, '/*' ) === substr_count( $sn_mr_css, '*/' ), 'machine-readers.css comment delimiters balance (no orphaned block killing the rules below it)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
