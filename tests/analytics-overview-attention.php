<?php
/**
 * Tests for inc/analytics-overview-attention.php — the v9.69.0 attention
 * signal module (pure functions, require()'d directly — never stubbed).
 *
 * Contract under test (owner-approved v9.69.0 design):
 *  - A panel is NOTABLE only when its headline movement vs the PREVIOUS
 *    period clears BOTH a percentage bar AND an absolute floor — 1 view →
 *    2 views must NEVER flag (that is +100% on a base too small to mean
 *    anything).
 *  - Sentiment-aware: attention means "needs you", not "changed". Bounce
 *    WORSENING (rising) flags; bounce improving does not. Pages/session and
 *    median duration flag only when FALLING. Sessions and table views flag
 *    in BOTH directions (a surge may be bots or a hit; a collapse may be
 *    breakage — either needs a look).
 *  - Null discipline: a FAILED prior read = attention UNKNOWN (no chip, no
 *    strip claim — and no false "all calm" either); an EMPTY prior window =
 *    no comparison basis (indistinguishable from pre-feature history — a
 *    fabricated "surge vs nothing" would manufacture attention), never a flag.
 *  - The out-of-top bound: a key absent from the current top-N is only known
 *    to sit at-or-below the table's minimum visible views, so a collapse is
 *    claimed ONLY when even that upper bound proves the percentage bar.
 *  - Review r1 F1 (TOTAL collapse): an EMPTY current window against a prior
 *    that cleared the views floor is the STRONGEST collapse — it flags
 *    (strip-only at the caller; the folded panel offers no anchor surface).
 *  - Review r1 F4 (truncated-prior bound): a key absent from a DEPTH-CAPPED
 *    prior read is only known ≤ the read's minimum visible views — the surge
 *    is claimed against that bound, and the fact never fabricates a "0".
 *
 * Run: php tests/analytics-overview-attention.php
 * @since plugin v9.69.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

// ---- WP stubs (the tests/analytics-view-overview.php idiom) ---------------
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
// Real number_format_i18n honors $decimals — the fact pins below assert
// one-decimal bounce and two-decimal ppv, so the stub must too.
function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, (int) $decimals ); }

require_once __DIR__ . '/../inc/analytics-overview-attention.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); call_user_func( $cb ); return (string) ob_get_clean(); }

echo "Overview attention signals — v9.69.0\n\n";

echo "Group: threshold constants (value + rationale pins)\n";
ok( 25 === SN_OVERVIEW_ATTN_PCT, 'const: relative bar = 25% (a quarter-shift; anything less is week-to-week noise on a small site)' );
ok( 5 === SN_OVERVIEW_ATTN_VIEWS_FLOOR, 'const: table views floor = 5 (max(cur,prior) must reach it — 1→2 views is +100% on nothing)' );
ok( 10.0 === SN_OVERVIEW_ATTN_BOUNCE_PTS, 'const: bounce bar = 10 percentage POINTS (a relative bar on a ratio double-counts)' );
ok( 10 === SN_OVERVIEW_ATTN_MIN_SESSIONS, 'const: session floor = 10 (ratios over a handful of sessions swing wildly)' );

// ── Table signals ───────────────────────────────────────────────────────────
function rows_v( $pairs ) { $o = array(); foreach ( $pairs as $k => $v ) { $o[] = array( 'value' => $k, 'views' => $v, 'visits' => 1 ); } return $o; }

echo "\nGroup: table signal — null discipline (unknown ≠ no basis ≠ quiet)\n";
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 9 ) ), null, 'value', 5 );
ok( 'unknown' === $s['state'] && '' === $s['fact'], 'prior null (failed read) → UNKNOWN: no flag AND no all-calm claim' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 900 ) ), array(), 'value', 5 );
ok( 'none' === $s['state'], 'prior [] (empty window) → no basis, never a fabricated "surge vs nothing"' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 9, 'b' => 6 ) ), rows_v( array( 'a' => 9, 'b' => 6 ) ), 'value', 5 );
ok( 'none' === $s['state'], 'prior == current → quiet' );

echo "\nGroup: table signal — TOTAL collapse (review r1 F1: an empty current window is an ANSWER)\n";
$s = snt_analytics_attn_table_signal( array(), rows_v( array( 'Google' => 40 ) ), 'value', 5 );
ok( 'notable' === $s['state'] && 'views 40 → none recorded' === $s['fact'],
	'current [] vs a prior with real views: the STRONGEST collapse flags — an empty window is an answer (0), not a missing surface' );
$s = snt_analytics_attn_table_signal( array(), rows_v( array( 'a' => 3, 'b' => 3 ) ), 'value', 5 );
ok( 'notable' === $s['state'] && 'views 6 → none recorded' === $s['fact'],
	'collapse fact aggregates the whole prior window (6 total views → none recorded)' );
$s = snt_analytics_attn_table_signal( array(), rows_v( array( 'a' => 4 ) ), 'value', 5 );
ok( 'none' === $s['state'], 'a prior below the views floor (4 < 5) folds quietly — the 1→2 shield holds at zero too' );
$s = snt_analytics_attn_table_signal( array(), null, 'value', 5 );
ok( 'unknown' === $s['state'], 'current [] + FAILED prior read → unknown (guard order: the prior verdict resolves first — no collapse claim without a known prior)' );
$s = snt_analytics_attn_table_signal( array(), array(), 'value', 5 );
ok( 'none' === $s['state'], 'current [] + empty prior [] → none (two real zeros — nothing moved)' );

echo "\nGroup: table signal — BOTH bars must clear (the 1→2 shield)\n";
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 2 ) ), rows_v( array( 'a' => 1 ) ), 'value', 5 );
ok( 'none' === $s['state'], '1 → 2 views NEVER flags (+100% but max(cur,prior)=2 < floor 5)' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 12 ) ), rows_v( array( 'a' => 10 ) ), 'value', 5 );
ok( 'none' === $s['state'], '10 → 12 views never flags (floor met, +20% < the 25% bar)' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 10 ) ), rows_v( array( 'a' => 5 ) ), 'value', 5 );
ok( 'notable' === $s['state'], '5 → 10 views flags (+100%, max 10 ≥ 5)' );
ok( 'a: views 5 → 10' === $s['fact'], 'fact names the row and both figures (prior → current)' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 3 ) ), rows_v( array( 'a' => 12 ) ), 'value', 5 );
ok( 'notable' === $s['state'] && 'a: views 12 → 3' === $s['fact'], '12 → 3 flags (BOTH directions — a collapse needs you as much as a surge)' );

echo "\nGroup: table signal — new rows and the out-of-top bound\n";
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 7, 'b' => 5 ) ), rows_v( array( 'b' => 5 ) ), 'value', 5 );
ok( 'notable' === $s['state'] && 'a: views 0 → 7' === $s['fact'],
	'a row absent from a NON-EMPTY prior window deltas against 0 — 7 new views ≥ floor flags' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 4, 'b' => 9 ) ), rows_v( array( 'b' => 9 ) ), 'value', 5 );
ok( 'none' === $s['state'], 'a new row below the floor (4 < 5) stays quiet' );
$s = snt_analytics_attn_table_signal(
	rows_v( array( 'a' => 6, 'b' => 5 ) ),
	rows_v( array( 'a' => 6, 'b' => 5, 'z' => 40 ) ),
	'value', 5
);
ok( 'notable' === $s['state'] && 'z: 40 views → out of the top 5' === $s['fact'],
	'a prior row missing from the current top-N: even its upper bound (the table minimum, 5) proves a ≥25% drop → flags with the honest out-of-top fact' );
$s = snt_analytics_attn_table_signal(
	rows_v( array( 'a' => 20, 'b' => 18 ) ),
	rows_v( array( 'z' => 20, 'a' => 20, 'b' => 18 ) ),
	'value', 5
);
ok( 'none' === $s['state'],
	'a missing prior row whose provable drop is only 10% (20 → ≤18) stays quiet — the bound is sound, never an assumed collapse to 0' );

echo "\nGroup: table signal — the truncated-prior bound (review r1 F4: absence from a CAPPED read is not a 0)\n";
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 20 ) ), rows_v( array( 'x' => 9, 'y' => 8, 'z' => 7 ) ), 'value', 5, 3 );
ok( 'notable' === $s['state'] && 'a: below the prior top 3 → views 20' === $s['fact'],
	'a key absent from a DEPTH-CAPPED prior read is only known ≤ the read minimum (7): the surge is provable against that bound (+186% ≥ 25%) and the fact never fabricates a "0"' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 10, 'b' => 50 ) ), rows_v( array( 'b' => 50, 'c' => 9, 'd' => 9 ) ), 'value', 5, 3 );
ok( 'none' === $s['state'],
	'truncated prior: a rise NOT provable against the bound (10 vs ≤9 = +11% < 25%) stays quiet — never a fabricated "views 0 → 10"' );
$s = snt_analytics_attn_table_signal( rows_v( array( 'a' => 7, 'b' => 5 ) ), rows_v( array( 'b' => 5 ) ), 'value', 5, 50 );
ok( 'notable' === $s['state'] && 'a: views 0 → 7' === $s['fact'],
	'a prior read BELOW its depth cap was exhaustive — absence still reads as a real 0 (the honest "new" fact keeps its figures)' );

echo "\nGroup: table signal — driving fact = the largest absolute movement\n";
$s = snt_analytics_attn_table_signal(
	rows_v( array( 'a' => 10, 'b' => 40 ) ),
	rows_v( array( 'a' => 5, 'b' => 80 ) ),
	'value', 5
);
ok( 'b: views 80 → 40' === $s['fact'], 'two flagged rows: |Δ|=40 beats |Δ|=5 (absolute movement ranks — the floor already killed tiny bases)' );
$rows_p = array( array( 'path' => '/x/', 'views' => 9, 'visits' => 2 ) );
$s = snt_analytics_attn_table_signal( $rows_p, array( array( 'path' => '/x/', 'views' => 3, 'visits' => 1 ) ), 'path', 10 );
ok( 'notable' === $s['state'] && '/x/: views 3 → 9' === $s['fact'], 'pageroles rows match by path (key_field threaded)' );

// ── Session-quality signals ─────────────────────────────────────────────────
function kpis_of( $sess, $bounce, $ppv, $dur ) { return array( 'sessions' => $sess, 'bounce_pct' => $bounce, 'ppv' => $ppv, 'median_dur' => $dur, 'days' => 7 ); }

echo "\nGroup: session signal — null discipline\n";
$s = snt_analytics_attn_session_signal( kpis_of( 44, 61.4, 1.5, 65 ), null, true );
ok( 'unknown' === $s['state'], 'failed prior rollup read → UNKNOWN' );
$s = snt_analytics_attn_session_signal( null, kpis_of( 44, 61.4, 1.5, 65 ), false );
ok( 'none' === $s['state'], 'no current KPIs (folded/empty panel) → none' );
$s = snt_analytics_attn_session_signal( kpis_of( 400, 61.4, 1.5, 65 ), null, false );
ok( 'none' === $s['state'], 'empty prior window (aggregates to null) → no basis, never a fabricated surge' );

echo "\nGroup: session signal — TOTAL collapse (review r1 F1: the zero shape the view synthesizes)\n";
$zero_kpis = array( 'sessions' => 0, 'bounce_pct' => 0.0, 'ppv' => 0.0, 'median_dur' => 0 );
$s = snt_analytics_attn_session_signal( $zero_kpis, kpis_of( 40, 50.0, 1.5, 60 ), false );
ok( 'notable' === $s['state'] && 'sessions 40 → 0' === $s['fact'],
	'sessions 40 → 0 (an EMPTY current window is a real zero, not a missing surface) flags the total collapse' );
$s = snt_analytics_attn_session_signal( $zero_kpis, kpis_of( 8, 90.0, 1.0, 10 ), false );
ok( 'none' === $s['state'], 'a quiet prior (8 < the 10-session floor) keeps a zero week quiet — the tiny-site shield holds at zero too' );

echo "\nGroup: session signal — sessions volume (both directions, floored)\n";
$s = snt_analytics_attn_session_signal( kpis_of( 60, 50.0, 1.5, 60 ), kpis_of( 40, 50.0, 1.5, 60 ), false );
ok( 'notable' === $s['state'] && 'sessions 40 → 60' === $s['fact'], 'sessions +50% flags (a surge may be bots or a hit — needs a look)' );
$s = snt_analytics_attn_session_signal( kpis_of( 20, 50.0, 1.5, 60 ), kpis_of( 40, 50.0, 1.5, 60 ), false );
ok( 'notable' === $s['state'] && 'sessions 40 → 20' === $s['fact'], 'sessions -50% flags (a collapse may be breakage)' );
$s = snt_analytics_attn_session_signal( kpis_of( 2, 90.0, 1.0, 10 ), kpis_of( 8, 20.0, 3.0, 100 ), false );
ok( 'none' === $s['state'], 'tiny-site shield: max(2,8) sessions < 10 → nothing is judged, however wild the ratios swing' );
$s = snt_analytics_attn_session_signal( kpis_of( 44, 50.0, 1.5, 60 ), kpis_of( 40, 50.0, 1.5, 60 ), false );
ok( 'none' === $s['state'], 'sessions +10% stays quiet (below the 25% bar)' );

echo "\nGroup: session signal — bounce is sentiment-aware (worsening flags, improving never)\n";
$s = snt_analytics_attn_session_signal( kpis_of( 40, 61.4, 1.5, 60 ), kpis_of( 40, 45.0, 1.5, 60 ), false );
ok( 'notable' === $s['state'] && 'bounce 45.0% → 61.4%' === $s['fact'], 'bounce +16.4 points (worsening) flags with a point-anchored fact' );
$s = snt_analytics_attn_session_signal( kpis_of( 40, 54.9, 1.5, 60 ), kpis_of( 40, 45.0, 1.5, 60 ), false );
ok( 'none' === $s['state'], 'bounce +9.9 points stays quiet (below the 10-point bar)' );
$s = snt_analytics_attn_session_signal( kpis_of( 40, 55.0, 1.5, 60 ), kpis_of( 40, 45.0, 1.5, 60 ), false );
ok( 'notable' === $s['state'], 'bounce +10.0 points exactly clears the bar (≥, not >)' );
$s = snt_analytics_attn_session_signal( kpis_of( 40, 45.0, 1.5, 60 ), kpis_of( 40, 80.0, 1.5, 60 ), false );
ok( 'none' === $s['state'], 'bounce IMPROVING by 35 points never flags — attention means "needs you", not "changed"' );
$s = snt_analytics_attn_session_signal( kpis_of( 11, 90.0, 1.5, 60 ), kpis_of( 9, 45.0, 1.5, 60 ), false );
ok( 'none' === $s['state'], 'ratio gate: prior window carries only 9 sessions (< 10) → bounce is not judged (a ratio over a handful lies)' );

echo "\nGroup: session signal — ppv + median duration flag only when FALLING\n";
$s = snt_analytics_attn_session_signal( kpis_of( 40, 50.0, 1.4, 60 ), kpis_of( 40, 50.0, 2.0, 60 ), false );
ok( 'notable' === $s['state'] && 'pages/session 2.00 → 1.40' === $s['fact'], 'ppv -30% (falling engagement) flags' );
$s = snt_analytics_attn_session_signal( kpis_of( 40, 50.0, 3.0, 60 ), kpis_of( 40, 50.0, 2.0, 60 ), false );
ok( 'none' === $s['state'], 'ppv +50% (readers going deeper) is good news, not attention' );
$s = snt_analytics_attn_session_signal( kpis_of( 40, 50.0, 1.5, 60 ), kpis_of( 40, 50.0, 1.5, 100 ), false );
ok( 'notable' === $s['state'] && 'median duration 100s → 60s' === $s['fact'], 'median duration -40% flags' );
$s = snt_analytics_attn_session_signal( kpis_of( 40, 50.0, 1.5, 100 ), kpis_of( 40, 50.0, 1.5, 60 ), false );
ok( 'none' === $s['state'], 'median duration rising never flags' );

echo "\nGroup: session signal — volume outranks ratios in the driving fact\n";
$s = snt_analytics_attn_session_signal( kpis_of( 15, 80.0, 1.0, 20 ), kpis_of( 40, 45.0, 2.0, 100 ), false );
ok( 'notable' === $s['state'] && 'sessions 40 → 15' === $s['fact'],
	'sessions collapse + bounce worsening + ppv falling together → the volume fact leads (documented priority: sessions > bounce > ppv > duration)' );

echo "\nGroup: chip + strip renderers\n";
$chip = snt_analytics_attn_chip();
ok( '<span class="sn-an-attn-chip">▲ Notable</span>' === $chip, 'chip: amber NOTABLE marker (uppercase via CSS — the msgid stays translatable)' );
ok( '' === capture( function () { snt_analytics_attn_render_strip( array() ); } ), 'strip: no flags → no strip, not even an empty shell' );
$strip = capture( function () {
	snt_analytics_attn_render_strip( array(
		array( 'label' => 'Top sources', 'anchor' => 'sn-ov-sources', 'fact' => 'Google: views 40 → 11' ),
		array( 'label' => 'Devices', 'anchor' => 'sn-ov-devices', 'fact' => 'mobile: views 2 → 17' ),
	) );
} );
ok( 1 === substr_count( $strip, 'sn-an-attn-strip' ), 'strip: one wrapper' );
ok( strpos( $strip, 'Needs attention:' ) !== false, 'strip: the triage label leads' );
ok( strpos( $strip, '<a class="sn-an-attn-link" href="#sn-ov-sources">Top sources</a>' ) !== false, 'strip: in-page anchor link to the flagged panel' );
ok( strpos( $strip, '<span class="sn-an-attn-fact">Google: views 40 → 11</span>' ) !== false, 'strip: the driving fact rides beside its panel link' );
ok( strpos( $strip, '#sn-ov-devices' ) !== false && strpos( $strip, 'mobile: views 2 → 17' ) !== false, 'strip: second flagged panel present' );
ok( 1 === substr_count( $strip, '&middot;' ), 'strip: two items joined by one separator' );
$xss = capture( function () {
	snt_analytics_attn_render_strip( array(
		array( 'label' => '<script>x</script>', 'anchor' => 'sn-ov-sources', 'fact' => '<b>7</b> → "9"' ),
	) );
} );
ok( strpos( $xss, '<script>' ) === false && strpos( $xss, '<b>' ) === false, 'strip: labels + facts are escaped (no raw markup passes through)' );
$flat = capture( function () {
	snt_analytics_attn_render_strip( array(
		array( 'label' => 'Top sources', 'anchor' => '', 'fact' => 'views 36 → none recorded' ),
		array( 'label' => 'Devices', 'anchor' => 'sn-ov-devices', 'fact' => 'mobile: views 2 → 17' ),
	) );
} );
ok( strpos( $flat, '<span class="sn-an-attn-flag">Top sources</span>' ) !== false,
	'strip: an anchor-less item (a FOLDED panel — total collapse) renders as a plain flag, never a dead link (review r1 F1)' );
ok( strpos( $flat, 'href="#"' ) === false, 'strip: no empty-anchor href anywhere' );
ok( strpos( $flat, '<span class="sn-an-attn-fact">views 36 → none recorded</span>' ) !== false, 'strip: the collapse fact rides beside the plain flag' );
ok( strpos( $flat, '<a class="sn-an-attn-link" href="#sn-ov-devices">Devices</a>' ) !== false, 'strip: anchored items keep their links beside a flat one' );

echo "\nGroup: resolve helper (the view's one-line bridge)\n";
$r = snt_analytics_attn_resolve_table( rows_v( array( 'a' => 10 ) ), null, 'value', 5 );
ok( 'none' === $r['state'], 'resolve: no prior read attempted (attention gated off / panel folded) → none' );
$r = snt_analytics_attn_resolve_table( rows_v( array( 'a' => 10 ) ), array( 'rows' => array(), 'failed' => true ), 'value', 5 );
ok( 'unknown' === $r['state'], 'resolve: a guarded FAILED read → unknown (the guard shape unwrapped honestly)' );
$r = snt_analytics_attn_resolve_table( rows_v( array( 'a' => 10 ) ), array( 'rows' => rows_v( array( 'a' => 5 ) ), 'failed' => false ), 'value', 5 );
ok( 'notable' === $r['state'] && 'a: views 5 → 10' === $r['fact'], 'resolve: a guarded successful read passes its rows through' );
$r = snt_analytics_attn_resolve_table( rows_v( array( 'a' => 20 ) ), array( 'rows' => rows_v( array( 'x' => 9, 'y' => 8, 'z' => 7 ) ), 'failed' => false ), 'value', 5, 3 );
ok( 'notable' === $r['state'] && 'a: below the prior top 3 → views 20' === $r['fact'],
	'resolve: the prior depth cap threads through to the truncated-prior bound (review r1 F4)' );
$r = snt_analytics_attn_resolve_table( array(), array( 'rows' => rows_v( array( 'Google' => 40 ) ), 'failed' => false ), 'value', 5 );
ok( 'notable' === $r['state'] && 'views 40 → none recorded' === $r['fact'],
	'resolve: an empty CURRENT window still judges — the total collapse reaches the strip through the bridge (review r1 F1)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
