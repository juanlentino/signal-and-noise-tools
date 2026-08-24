<?php
/**
 * Tests: Machine Readers pure renderers (Session 3 lane 2).
 * SCAFFOLD-RED: written against the shells on purpose; lane 2 turns it green.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }

require __DIR__ . '/../inc/machine-readers-render.php';

$rows = array(
	array( 'family' => 'openai',    'surface' => 'llms',   'day' => '2026-07-27', 'hits' => 12 ),
	array( 'family' => 'openai',    'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 3 ),
	array( 'family' => 'anthropic', 'surface' => 'html',   'day' => '2026-07-28', 'hits' => 5 ),
	array( 'family' => 'uptime',    'surface' => 'html',   'day' => '2026-07-28', 'hits' => 40 ),
);

echo "Group: static compliance map pins\n";
$ai = snt_mr_ai_training_families();
ok( in_array( 'openai', $ai, true ) && in_array( 'anthropic', $ai, true ), 'AI-training class includes openai + anthropic' );
ok( ! in_array( 'search', $ai, true ) && ! in_array( 'uptime', $ai, true ), 'search + uptime are NOT AI-training class' );

echo "\nGroup: family table\n";
$html = snt_mr_render_family_table( $rows, 30 );
ok( '' !== $html && false !== strpos( $html, 'openai' ), 'renders and names families' );
ok( false !== strpos( $html, '15' ) || false !== strpos( $html, '15' ), 'openai hits aggregate across surfaces (12+3=15)' );
$hostile = snt_mr_render_family_table( array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '<img src=x>', 'hits' => 1 ) ), 7 );
ok( false === strpos( $hostile, '<img' ), 'defense in depth: even normalized-shape fields render escaped' );

echo "\nGroup: surface table\n";
$html = snt_mr_render_surface_table( $rows );
ok( '' !== $html && false !== strpos( $html, 'llms' ) && false !== strpos( $html, 'rights' ), 'renders surface classes' );

echo "\nGroup: compliance read: observed-vs-declared, never verified\n";
$html = snt_mr_render_compliance( $rows );
ok( '' !== $html && false !== strpos( $html, 'openai' ), 'AI-training families appear' );
ok( false !== stripos( $html, 'observed' ) && false !== stripos( $html, 'declared' ), 'labeled observed-vs-declared' );
ok( false === stripos( $html, 'verified' ), 'never claims verified identity (UAs are self-reported)' );
ok( false === strpos( $html, 'uptime' ), 'non-AI families stay out of the compliance table' );
// v10.2.2 UI pass: the caption is a heading-length title; the paragraph-length
// observation-not-proof disclaimer moves to a helper line under the table.
preg_match( '/<caption>(.*?)<\/caption>/s', $html, $sn_cap );
ok( isset( $sn_cap[1] ) && false === stripos( $sn_cap[1], 'self-reported' ), 'caption is a title, not the paragraph-length disclaimer' );
ok( false !== stripos( $html, 'self-reported' ) && false !== strpos( $html, 'sn-field-helper' ), 'the self-reported disclaimer still renders, as the house helper line' );

echo "\nGroup: sensor card, deployed version vs the contract minimum\n";
$html = snt_mr_render_sensor_card( array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T17:12:22Z' ) );
ok( '' !== $html && false !== strpos( $html, '1.4.0' ), 'card shows the deployed version' );
ok( false === stripos( $html, 'outdated' ), 'contract-satisfying version raises no warning' );
$html = snt_mr_render_sensor_card( array( 'version' => '1.3.0', 'deployed_at' => '2026-07-23T23:01:27Z' ) );
ok( false !== stripos( $html, 'outdated' ) && false !== strpos( $html, '1.4.0' ), 'below-minimum version warns and names the required 1.4.0' );
$html = snt_mr_render_sensor_card( null );
ok( '' !== $html && false === stripos( $html, 'outdated' ), 'null info renders the quiet dash card, not a warning or a fatal' );

echo "\nGroup: v9.86.0 — summary stat strip\n";
$html = snt_mr_render_summary_chips( $rows, 30 );
ok( '' !== $html && false !== strpos( $html, '60' ), 'total machine reads stated (12+3+5+40=60)' );
ok( false !== strpos( $html, 'uptime' ), 'top family named (uptime, 40)' );
ok( false !== strpos( $html, '20' ), 'AI-training reads counted (openai 15 + anthropic 5)' );
$hostile = snt_mr_render_summary_chips( array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '<svg onload=x>', 'hits' => 2 ) ), 7 );
ok( false === strpos( $hostile, '<svg' ), 'chips escape like every other sink' );
ok( '' !== snt_mr_render_summary_chips( array(), 30 ), 'empty rows still render a strip, not a fatal' );

echo "\nGroup: v10.2.2 — the strip carries the feed half of the audience (fourth chip)\n";
$html = snt_mr_render_summary_chips( $rows, 30, 946 );
ok( false !== strpos( $html, '946' ), 'feed-fetches chip renders the passed 30d total' );
ok( false !== stripos( $html, 'fetch' ), 'labeled as fetches, not reads (the two acts are never conflated)' );
ok( false === strpos( $html, '1,006' ), 'never summed with crawler reads (60 + 946 must not appear anywhere)' );
$html = snt_mr_render_summary_chips( $rows, 30 );
ok( false === stripos( $html, 'fetch' ), 'null feed total omits the chip entirely (three-chip strip unchanged)' );

echo "\nGroup: v10.1.1 — the sensor block copies the Analytics idiom\n";
$pills = snt_mr_sensor_pills(
	array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T18:07:56Z' ),
	array( 'last_check_ok' => '1', 'last_check_drift' => '' ),
	array( 'ok' => true )
);
ok( is_array( $pills ) && count( $pills ) >= 3, 'a pill per pipeline stage, like Analytics' );
foreach ( $pills as $p ) {
	ok( in_array( $p[0], array( 'ok', 'warn', 'unknown' ), true ), 'pill state is the Analytics vocabulary: ' . $p[0] );
}
$html = snt_mr_render_sensor_status( $pills );
ok( false !== strpos( $html, 'sn-an-pipeline-pills' ), 'uses the Analytics pill container class' );
ok( false !== strpos( $html, 'sn-an-pill sn-an-pill--ok' ), 'uses the Analytics pill classes, not a bespoke one' );
ok( false !== strpos( $html, 'sn-an-pill-mark' ), 'carries the Analytics check mark span' );
ok( false === strpos( $html, 'sn-mr-panel' ), 'the invented v10.0.1 panel class is gone' );

$warn = snt_mr_sensor_pills( null, null, array( 'ok' => false, 'error' => 'not_configured' ) );
$states = array();
foreach ( $warn as $p ) { $states[] = $p[0]; }
ok( in_array( 'warn', $states, true ), 'an unconfigured sensor shows a warn pill' );
$whtml = snt_mr_render_sensor_status( $warn );
ok( false !== strpos( $whtml, 'sn-an-pipeline-warn' ), 'and its explanation renders in the Analytics warn line, below the pills' );

echo "\nGroup: reachable-but-unversioned is a warn, not unreachable\n";
$unreported = snt_mr_sensor_pills(
	array( 'version' => '', 'reachable' => true, 'deployed_at' => '2026-08-13T01:49:33Z' ),
	null,
	array( 'ok' => true )
);
ok( 'warn' === ( $unreported[0][0] ?? '' ), 'reachable + empty version → warn' );
ok( 'Sensor reachable, version unreported' === ( $unreported[0][1] ?? '' ), 'label names reachable-but-unreported' );
ok( 'Sensor unreachable' !== ( $unreported[0][1] ?? '' ), 'label is NOT Sensor unreachable' );
$dead = snt_mr_sensor_pills( null, null, array( 'ok' => true ) );
ok( 'unknown' === ( $dead[0][0] ?? '' ) && 'Sensor unreachable' === ( $dead[0][1] ?? '' ), 'null info is still exactly Sensor unreachable' );

echo "\nGroup: v10.2.1 R4 — the feed-fetcher column (rss-feed-tracker stays the source)\n";
$feed = snt_mr_render_feed_table( array( 'most_recent' => '2026-07-28 12:00:00', 'windows' => array( 7 => array( 'total' => 42, 'uniques' => 9 ), 30 => array( 'total' => 130, 'uniques' => 21 ) ) ) );
ok( false !== strpos( $feed, 'sn-an-table' ), 'uses the central table class, like the surface table beside it' );
ok( false !== strpos( $feed, '130' ) && false !== strpos( $feed, '21' ), 'renders the tracker window rows' );
ok( false !== stripos( $feed, 'fetch' ), 'names them fetches, not reads or visits (a feed fetcher is not a crawler read)' );
$hostile = snt_mr_render_feed_table( array( 'most_recent' => '<img src=x>', 'windows' => array( 7 => array( 'total' => 1, 'uniques' => 1 ) ) ) );
ok( false === strpos( $hostile, '<img' ), 'tracker values are escaped at the sink' );
ok( false !== strpos( snt_mr_render_feed_table( array() ), 'sn-an-empty' ), 'no tracker data renders the central empty state, not a fabricated zero' );

// ── v12.26.0: the identity row (signed_agent + markdown_requested) ──────────
//
// Both metrics have been normalized and tested since v12.16.0 / v12.24.0 and
// rendered NOWHERE. This is their door.
$rows_unmeasured = array(
	array( 'family' => 'openai', 'hits' => 900, 'signed_agent' => 'unmeasured', 'markdown_requested' => false ),
	array( 'family' => 'anthropic', 'hits' => 100, 'signed_agent' => 'unmeasured', 'markdown_requested' => true ),
);
$rows_measured = array(
	array( 'family' => 'openai', 'hits' => 60, 'signed_agent' => 'valid', 'markdown_requested' => true ),
	array( 'family' => 'openai', 'hits' => 30, 'signed_agent' => 'unsigned', 'markdown_requested' => false ),
	array( 'family' => 'perplexity', 'hits' => 10, 'signed_agent' => 'invalid', 'markdown_requested' => false ),
);

// THE LOAD-BEARING CASE. An hour after the sensor ships, every historical row
// reads 'unmeasured'. Rendering "0 verified" there would be a FALSE ZERO — it
// asserts a measurement that was never taken. Never-measured and measured-zero
// are different answers and must not render the same.
$t_un = snt_mr_identity_totals( $rows_unmeasured );
ok( 0 === $t_un['measured'], 'an all-unmeasured window reports zero MEASURED reads' );
ok( 1000 === $t_un['total'], 'while still counting the reads themselves' );
$html_un = snt_mr_render_identity_row( $rows_unmeasured, 30 );
ok( false !== strpos( $html_un, 'not yet measured' ), 'the unmeasured window says so in words' );
ok( false === strpos( $html_un, '>0<' ), 'and never paints a bare 0 that would read as "none verified"' );

// Markdown adoption is measurable even when signatures are not — different
// sensor, different vintage. It must not be suppressed by the guard above.
ok( false !== strpos( $html_un, '100' ), 'markdown adoption still renders in an unmeasured-signature window' );

// With real signature data, the split is reported.
$t_m = snt_mr_identity_totals( $rows_measured );
ok( 100 === $t_m['measured'], 'measured counts every row that carries a real state' );
ok( 60 === $t_m['valid'], 'valid hits are summed' );
ok( 10 === $t_m['invalid'], 'invalid hits are summed separately — not folded into unsigned' );
ok( 30 === $t_m['unsigned'], 'unsigned is its own bucket' );
$html_m = snt_mr_render_identity_row( $rows_measured, 30 );
ok( false !== strpos( $html_m, '60' ), 'the verified count is rendered' );
ok( false === strpos( $html_m, 'not yet measured' ), 'and the unmeasured wording is gone once there is data' );

// Escaping: family/state values reach the sink through esc_html like every
// other fragment on this page.
$evil = array( array( 'family' => '<script>', 'hits' => 5, 'signed_agent' => '<img>', 'markdown_requested' => false ) );
ok( false === strpos( snt_mr_render_identity_row( $evil, 30 ), '<script>' ), 'raw markup never reaches the output' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
