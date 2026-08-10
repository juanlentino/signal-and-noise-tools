<?php
/**
 * Tests: the vendor/purpose axes (v10.79.0).
 *
 * The load-bearing assertions here are the NEGATIVE ones: that `family` did not
 * move, that a *-User agent never lands in `train`, and that the two
 * attacker-influenced strings on this surface cannot carry markup.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// Minimal i18n/escaping stubs modelling the REAL transform, not identity: a
// stub that returns its input unchanged would certify an unescaped renderer.
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-api.php';
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-render-taxonomy.php';

echo "\nGroup: RULE 1 , the frozen family enum did not move\n";
$families = snt_mr_valid_families();
ok( 'openai' === $families[0] && 'other-bot' === $families[17], 'the original enum still starts and ends where it did' );
ok( 'unclassified-machine' === $families[18] && 19 === count( $families ), 'the additive value is appended, never inserted' );
ok( ! in_array( 'unclassified-machine', array_slice( $families, 0, 18 ), true ), 'the additive value never displaces a frozen one' );

echo "\nGroup: an older Worker degrades to the v10.0.0 shape, it does not fabricate\n";
$legacy = snt_mr_normalize_rows( array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '2026-08-01', 'hits' => 5 ) ) );
ok( 5 === $legacy[0]['hits'] && 'openai' === $legacy[0]['family'], 'legacy rows still normalize exactly as before' );
ok( '' === $legacy[0]['vendor'] && 'unknown' === $legacy[0]['purpose'], 'absent taxonomy fields land on empty/unknown' );
ok( true === snt_mr_taxonomy_absent( $legacy ), 'and the absence is DETECTABLE, not silently zero' );
$modern = snt_mr_normalize_rows( array( array( 'family' => 'other-bot', 'surface' => 'llms', 'day' => '2026-08-01', 'hits' => 3, 'vendor' => 'anthropic', 'purpose' => 'search', 'taxonomy_version' => '1.0.0' ) ) );
ok( false === snt_mr_taxonomy_absent( $modern ), 'a taxonomy-bearing response is not reported as absent' );

echo "\nGroup: purpose is never derived from family\n";
ok( 'other-bot' === $modern[0]['family'], 'Claude-SearchBot keeps its frozen other-bot family' );
ok( 'anthropic' === $modern[0]['vendor'] && 'search' === $modern[0]['purpose'], 'while carrying its true vendor and purpose' );

echo "\nGroup: hostile worker values fail INTO the enum\n";
$hostile = snt_mr_normalize_rows( array( array(
	'family'  => '<script>x</script>',
	'surface' => '../../etc/passwd',
	'day'     => 'not-a-day',
	'hits'    => -99,
	'vendor'  => '<img src=x onerror=alert(1)>',
	'purpose' => 'train"; DROP TABLE',
	'taxonomy_version' => '1.0.0<script>',
) ) );
ok( 'other-bot' === $hostile[0]['family'] && 'html' === $hostile[0]['surface'], 'unknown family/surface coerce to the enum' );
ok( 'unknown' === $hostile[0]['purpose'], 'an unknown purpose coerces to unknown, never passes through' );
ok( '' === $hostile[0]['day'] && 0 === $hostile[0]['hits'], 'malformed day and negative hits are neutralised' );
ok( false === strpos( $hostile[0]['vendor'], '<' ) && false === strpos( $hostile[0]['vendor'], '>' ), 'vendor cannot carry markup' );
ok( '1.0.0' === $hostile[0]['taxonomy_version'], 'taxonomy_version is stripped to digits and dots' );

echo "\nGroup: the sampled user agent is sanitized, then escaped again at the sink\n";
$ua = snt_mr_normalize_ua_sample( 'Mozilla/5.0 <script>alert("xss")</script> \'; DROP' );
ok( false === strpos( $ua, '<' ) && false === strpos( $ua, '"' ) && false === strpos( $ua, "'" ), 'markup and quotes never survive normalization' );
ok( 96 >= strlen( snt_mr_normalize_ua_sample( str_repeat( 'A', 500 ) ) ), 'a long UA cannot dominate the column' );
$html = snt_mr_render_unknown_agents( array( array( 'user_agent' => 'Mozilla/5.0 <script>alert(1)</script>', 'hits' => 7 ) ) );
ok( false === strpos( $html, '<script>' ), 'the renderer emits no script tag' );
ok( false !== strpos( $html, 'Mozilla/5.0' ), 'while still showing the reviewable part of the string' );
ok( false !== strpos( $html, 'at most' ), 'and the cap is reported so truncation is never silent' );

echo "\nGroup: RULE 2 , the empty case is honest\n";
$empty = snt_mr_render_unknown_agents( array() );
ok( false !== stripos( $empty, 'matched the taxonomy' ), 'nothing to review says so, rather than rendering an empty table' );

echo "\nGroup: first-party monitoring is excluded from readership, and declared\n";
$rows = snt_mr_normalize_rows( array(
	array( 'family' => 'uptime', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 6403, 'vendor' => 'betterstack', 'purpose' => 'ops', 'taxonomy_version' => '1.0.0', 'first_party' => '1' ),
	array( 'family' => 'openai', 'surface' => 'llms', 'day' => '2026-08-01', 'hits' => 40, 'vendor' => 'openai', 'purpose' => 'train', 'taxonomy_version' => '1.0.0' ),
) );
$totals = snt_mr_purpose_totals( $rows );
ok( 6403 === $totals['first_party'], 'the first-party total is counted separately' );
ok( ! isset( $totals['purposes']['ops'] ) && 40 === $totals['purposes']['train'], 'and excluded from the purpose totals' );
$ptable = snt_mr_render_purpose_table( $rows, 30 );
ok( false !== strpos( $ptable, '6,403' ) && false !== stripos( $ptable, 'own uptime monitoring' ), 'the exclusion is disclosed on the page, not hidden' );

echo "\nGroup: never-measured is not measured-zero\n";
$note = snt_mr_render_purpose_table( $legacy, 30 );
ok( false !== stripos( $note, 'predates' ) && false !== stripos( $note, 'not a measured zero' ), 'an older sensor renders a stated absence, not a table of zeroes' );

echo "\nGroup: vendor x purpose keeps one vendor on several rows\n";
$multi = snt_mr_normalize_rows( array(
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 30, 'vendor' => 'openai', 'purpose' => 'train', 'taxonomy_version' => '1.0.0' ),
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 20, 'vendor' => 'openai', 'purpose' => 'search', 'taxonomy_version' => '1.0.0' ),
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 10, 'vendor' => 'openai', 'purpose' => 'user', 'taxonomy_version' => '1.0.0' ),
) );
$vp = snt_mr_render_vendor_purpose_table( $multi );
ok( 3 === substr_count( $vp, '<tr><td class="column-primary"' ), 'one vendor across three purposes is three rows, not one' );
ok( false !== strpos( $vp, 'train' ) && false !== strpos( $vp, 'search' ) && false !== strpos( $vp, 'user' ), 'and each purpose is named' );

echo "\nGroup: the purpose vocabulary is closed\n";
$purposes = snt_mr_valid_purposes();
ok( 12 === count( $purposes ), 'exactly twelve purposes' );
ok( in_array( 'train', $purposes, true ) && ! in_array( 'training', $purposes, true ), 'the value is train, not a near-miss synonym' );
ok( array( 'train', 'retrieval' ) === snt_mr_ai_purposes(), 'the AI-consumption set is train + retrieval, and does not silently include user or search' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
