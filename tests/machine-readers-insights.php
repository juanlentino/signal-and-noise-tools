<?php
/**
 * Tests: Machine Readers crawler-family volume deltas as insight cards (R3).
 *
 * Pins the detector's THRESHOLDS, not just its plumbing: a real delta fires,
 * a tiny-volume delta stays quiet, and a missing window is silent rather than
 * inventing a comparison. Also pins the honest vocabulary (crawler "reads",
 * never "visits") and the escaping at the render sink.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) {
	// v10.2.0 (verifier finding): model the REAL esc_url protocol allowlist.
	// The old htmlspecialchars-only stub passed `javascript:` straight through,
	// so the fixture's hostile-URL row asserted nothing at the one sink it
	// claimed to cover.
	$s = (string) $s;
	return preg_match( '#^(https?:|mailto:|/)#i', $s ) ? htmlspecialchars( $s, ENT_QUOTES ) : '';
}
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }

// The render lane owns the one "reads per family" aggregator; load it so this
// fixture exercises the SAME path production takes, not a harness-only fallback.
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-insights.php';

/** One normalized row. */
function mk( $family, $day, $hits, $surface = 'html' ) {
	return array( 'family' => $family, 'surface' => $surface, 'day' => $day, 'hits' => (int) $hits );
}

/** Flatten a card to one searchable string, so wording can be asserted whole. */
function card_blob( $card ) {
	$out = '';
	foreach ( (array) $card as $k => $v ) {
		$out .= $k . ' ' . ( is_scalar( $v ) ? (string) $v : '' ) . ' ';
	}
	return $out;
}

echo "Group: documented thresholds are the ones the detector ships with\n";
ok( defined( 'SN_MR_DELTA_MIN_READS' ) && SN_MR_DELTA_MIN_READS >= 20, 'a minimum absolute volume floor exists (>= 20 reads)' );
ok( defined( 'SN_MR_DELTA_MIN_ABS' ) && SN_MR_DELTA_MIN_ABS >= 5, 'a minimum absolute CHANGE floor exists' );
ok( defined( 'SN_MR_DELTA_PCT' ) && SN_MR_DELTA_PCT >= 25, 'the relative margin is meaningful (>= 25%)' );
ok( defined( 'SN_MR_DELTA_CARD_MAX' ) && SN_MR_DELTA_CARD_MAX <= 5, 'the card list is capped' );

echo "\nGroup: window split (current vs prior, from one fetched row set)\n";
$mixed = array(
	mk( 'openai', '2026-07-28', 5 ),   // today, current
	mk( 'openai', '2026-06-29', 5 ),   // first day of a 30d current window
	mk( 'openai', '2026-06-28', 5 ),   // last day of the prior window
	mk( 'openai', '2026-05-30', 5 ),   // first day of the prior window
	mk( 'openai', '2026-05-29', 5 ),   // older than both windows
	mk( 'openai', '', 5 ),             // unplaceable day
	mk( 'openai', '2026-08-02', 5 ),   // future
);
$split = snt_mr_split_windows( $mixed, 30, '2026-07-28' );
ok( is_array( $split ) && isset( $split['current'], $split['prior'] ), 'split returns both windows' );
ok( 2 === count( (array) ( $split['current'] ?? null ) ), 'current window holds exactly the last 30 days' );
ok( 2 === count( (array) ( $split['prior'] ?? null ) ), 'prior window holds exactly the 30 days before that' );
$before = $mixed;
snt_mr_split_windows( $mixed, 30, '2026-07-28' );
ok( $before === $mixed, 'split never mutates the input rows' );

echo "\nGroup: a real delta fires\n";
$prior   = array( mk( 'openai', '2026-06-10', 60 ), mk( 'openai', '2026-06-20', 40 ) ); // 100
$current = array( mk( 'openai', '2026-07-10', 120 ), mk( 'openai', '2026-07-20', 100 ) ); // 220
$cards   = snt_mr_family_delta_cards( $current, $prior, 30 );
ok( 1 === count( $cards ), 'one card for the one family that moved' );
$c = $cards[0] ?? array();
ok( 'mr_delta_openai' === (string) ( $c['id'] ?? '' ), 'card id is namespaced per family' );
ok( false !== strpos( (string) ( $c['title'] ?? '' ), 'openai' ), 'the title names the family' );
ok( false !== strpos( (string) ( $c['title'] ?? '' ), '120' ), 'the title states the +120% change' );
ok( 220 === (int) ( $c['current'] ?? 0 ) && 100 === (int) ( $c['prior'] ?? 0 ), 'both window totals ride on the card' );
ok( 120 === (int) ( $c['delta'] ?? 0 ) && 'up' === (string) ( $c['direction'] ?? '' ), 'signed delta and direction are explicit' );
ok( '' !== (string) ( $c['action_url'] ?? '' ), 'the card deep-links somewhere actionable' );

echo "\nGroup: honest wording (crawler reads, never visits, never proven identity)\n";
$blob = strtolower( card_blob( $c ) );
ok( false !== strpos( $blob, 'read' ), 'the card talks about reads' );
ok( false === strpos( $blob, 'visit' ), 'the card never says visit/visitor' );
ok( false === strpos( $blob, 'verified' ), 'the card never claims verified identity' );
ok( false === strpos( $blob, 'traffic' ), 'the card never calls crawler reads traffic' );

echo "\nGroup: tiny volume does not shout\n";
$tiny = snt_mr_family_delta_cards(
	array( mk( 'openai', '2026-07-10', 3 ) ),
	array( mk( 'openai', '2026-06-10', 1 ) ),
	30
);
ok( array() === $tiny, '1 -> 3 reads is below the volume floor: silent' );
// Clears BOTH the absolute-change floor (15) and the relative margin (300%),
// so only the volume floor can keep it quiet. This is the assertion that
// actually pins SN_MR_DELTA_MIN_READS.
$low_volume = snt_mr_family_delta_cards(
	array( mk( 'openai', '2026-07-10', 20 ) ),
	array( mk( 'openai', '2026-06-10', 5 ) ),
	30
);
ok( array() === $low_volume, '5 -> 20 reads quadruples but stays under the volume floor: silent' );
$small_abs = snt_mr_family_delta_cards(
	array( mk( 'openai', '2026-07-10', 105 ) ),
	array( mk( 'openai', '2026-06-10', 100 ) ),
	30
);
ok( array() === $small_abs, '100 -> 105 is below the absolute-change floor: silent' );
// Clears BOTH the volume floor (28) and the relative margin (exactly 40%), so
// only the absolute-change floor can keep it quiet. Pins SN_MR_DELTA_MIN_ABS.
$eight_reads = snt_mr_family_delta_cards(
	array( mk( 'openai', '2026-07-10', 28 ) ),
	array( mk( 'openai', '2026-06-10', 20 ) ),
	30
);
ok( array() === $eight_reads, '20 -> 28 is a 40% move of only eight reads: silent' );
$small_pct = snt_mr_family_delta_cards(
	array( mk( 'openai', '2026-07-10', 115 ) ),
	array( mk( 'openai', '2026-06-10', 100 ) ),
	30
);
ok( array() === $small_pct, '100 -> 115 is below the relative margin: silent' );

echo "\nGroup: a missing window is silent, never a comparison against nothing\n";
$big = array( mk( 'openai', '2026-07-10', 500 ) );
ok( array() === snt_mr_family_delta_cards( $big, array(), 30 ), 'no prior window: silent' );
ok( array() === snt_mr_family_delta_cards( array(), $big, 30 ), 'no current window: silent' );
ok( array() === snt_mr_family_delta_cards( $big, array( mk( 'openai', '2026-06-10', 0 ) ), 30 ), 'a prior window of zero reads is missing data, not a baseline' );
$arrived = snt_mr_family_delta_cards(
	array( mk( 'openai', '2026-07-10', 500 ), mk( 'perplexity', '2026-07-10', 500 ) ),
	array( mk( 'openai', '2026-06-10', 100 ) ),
	30
);
ok( 1 === count( $arrived ), 'a family absent from the prior window yields no percentage claim' );
ok( 'mr_delta_openai' === (string) ( $arrived[0]['id'] ?? '' ), 'only the family present in BOTH windows is reported' );

echo "\nGroup: ranking and cap\n";
$cur_many = array(
	mk( 'openai', '2026-07-10', 400 ),
	mk( 'anthropic', '2026-07-10', 300 ),
	mk( 'google-ai', '2026-07-10', 200 ),
	mk( 'perplexity', '2026-07-10', 160 ),
);
$pri_many = array(
	mk( 'openai', '2026-06-10', 100 ),
	mk( 'anthropic', '2026-06-10', 100 ),
	mk( 'google-ai', '2026-06-10', 100 ),
	mk( 'perplexity', '2026-06-10', 100 ),
);
$many = snt_mr_family_delta_cards( $cur_many, $pri_many, 30 );
// Literal 3, not the constant: a fixture that reads the cap from the code under
// test cannot fail when the cap changes.
ok( 3 === count( $many ), 'four families qualify, three cards ship (the glance cap)' );
ok( 'mr_delta_openai' === (string) ( $many[0]['id'] ?? '' ), 'biggest absolute move ranks first' );
$before_cur = $cur_many; $before_pri = $pri_many;
snt_mr_family_delta_cards( $cur_many, $pri_many, 30 );
ok( $before_cur === $cur_many && $before_pri === $pri_many, 'the detector never mutates its input rows' );

echo "\nGroup: a fall is reported as honestly as a rise\n";
$fall = snt_mr_family_delta_cards(
	array( mk( 'commoncrawl', '2026-07-10', 40 ) ),
	array( mk( 'commoncrawl', '2026-06-10', 200 ) ),
	30
);
ok( 1 === count( $fall ) && 'down' === (string) ( $fall[0]['direction'] ?? '' ), 'a drop fires with direction down' );
ok( -160 === (int) ( $fall[0]['delta'] ?? 0 ), 'the signed delta is negative' );

echo "\nGroup: render sink escapes, empty is first-class\n";
ok( '' === snt_mr_render_delta_cards( array() ), 'no cards renders nothing at all' );
$html = snt_mr_render_delta_cards( $cards );
ok( false !== strpos( $html, 'openai' ), 'the rendered list names the family' );
$hostile = snt_mr_render_delta_cards( array( array(
	'id'           => 'mr_delta_x',
	'title'        => '<img src=x onerror=1>',
	'detail'       => '<script>alert(1)</script>',
	'action_url'   => 'javascript:alert(1)',
	'action_label' => '<svg onload=1>',
) ) );
ok( false === strpos( $hostile, '<img' ), 'title escaped at the sink' );
ok( false === strpos( $hostile, '<script' ), 'detail escaped at the sink' );
ok( false === strpos( $hostile, '<svg' ), 'action label escaped at the sink' );

echo "\nGroup: v10.2.0 — a partially observed prior window cannot fabricate a rise\n";
// Identical per-day rate on both sides; the prior window was simply observed
// on 5 of its 30 days (sensor deployed mid-window). The old guard passed this
// through as "up 500%".
$mr_cur = array();
for ( $d = 1; $d <= 30; $d++ ) {
	$mr_cur[] = array( 'family' => 'openai', 'surface' => 'llms', 'day' => sprintf( '2026-07-%02d', $d ), 'hits' => 20 );
}
$mr_pri = array();
for ( $d = 1; $d <= 5; $d++ ) {
	$mr_pri[] = array( 'family' => 'openai', 'surface' => 'llms', 'day' => sprintf( '2026-06-%02d', $d ), 'hits' => 20 );
}
ok( array() === snt_mr_family_delta_cards( $mr_cur, $mr_pri, 30 ), 'a prior window observed on 5 of 30 days emits NOTHING (it would have read "up 500%")' );

// The same shapes with comparable coverage still work, so the guard is not a
// blanket mute.
$mr_pri_full = array();
for ( $d = 1; $d <= 30; $d++ ) {
	$mr_pri_full[] = array( 'family' => 'openai', 'surface' => 'llms', 'day' => sprintf( '2026-06-%02d', $d ), 'hits' => 2 );
}
ok( array() !== snt_mr_family_delta_cards( $mr_cur, $mr_pri_full, 30 ), 'a comparably observed prior window still yields a card' );

echo "\nGroup: v10.2.0 — a family that STOPS is as reportable as one that rises\n";
$mr_c = array(); $mr_p = array();
for ( $d = 1; $d <= 30; $d++ ) {
	$mr_c[] = array( 'family' => 'anthropic', 'surface' => 'html', 'day' => sprintf( '2026-07-%02d', $d ), 'hits' => 10 );
	$mr_p[] = array( 'family' => 'anthropic', 'surface' => 'html', 'day' => sprintf( '2026-06-%02d', $d ), 'hits' => 10 );
	$mr_p[] = array( 'family' => 'openai', 'surface' => 'llms', 'day' => sprintf( '2026-06-%02d', $d ), 'hits' => 30 );
}
$mr_cards = snt_mr_family_delta_cards( $mr_c, $mr_p, 30 );
ok( false !== strpos( (string) json_encode( $mr_cards ), 'openai' ), 'a family that went to ZERO still cards (the largest fall used to be the one silence)' );

ok( false === strpos( snt_mr_render_delta_cards( array( array( 'title' => 't', 'detail' => 'd', 'action_label' => 'a', 'action_url' => 'javascript:alert(1)' ) ) ), 'javascript:' ), 'a hostile action_url is stripped at the render sink (the row that used to assert nothing)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
