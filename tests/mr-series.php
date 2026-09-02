<?php
/**
 * Standalone fixture tests for the machine-reader daily series.
 *
 * The properties under test are ZERO-FILL (a silent crawler must produce zeros,
 * not a shorter series) and PRESENCE (eligibility counts days, never rows).
 *
 * @since 13.76.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

require __DIR__ . '/../inc/mr-series.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $m\n"; } else { $fail++; echo "FAIL  $m\n"; } }

$FROM = '2026-08-03';
$TO   = '2026-09-01'; // 30 days inclusive.

/** Rows for $family on the first $days days of the window, $hits each, one surface. */
function rows_for( $family, $days, $hits = 5, $surface = 'html', $from = '2026-08-03' ) {
	$out = array();
	for ( $i = 0; $i < $days; $i++ ) {
		$out[] = array(
			'family'  => $family,
			'surface' => $surface,
			'day'     => gmdate( 'Y-m-d', strtotime( $from . ' 00:00:00 UTC' ) + $i * DAY_IN_SECONDS ),
			'hits'    => $hits,
		);
	}
	return $out;
}

// ── the window ───────────────────────────────────────────────────────────────
ok( 30 === count( snt_mr_day_range( $FROM, $TO ) ), 'the window is 30 days inclusive' );
ok( array() === snt_mr_day_range( 'nonsense', $TO ), 'a malformed bound yields NO window, never a guessed one' );
ok( array() === snt_mr_day_range( $TO, $FROM ), 'an inverted range yields no window' );
ok( '2026-08-03' === snt_mr_day_range( $FROM, $TO )[0], 'ordered oldest first' );

// ── ZERO-FILL ────────────────────────────────────────────────────────────────
$quiet = rows_for( 'openai', 10, 7 ); // present the first 10 days, then silent.
$s     = snt_mr_daily_series( $quiet, 'openai', $FROM, $TO );
ok( 30 === count( $s ), 'a crawler that STOPS still yields a full-length series' );
ok( 7 === $s[0]['views'] && 7 === $s[9]['views'], 'present days carry their hits' );
ok( 0 === $s[10]['views'] && 0 === $s[29]['views'], 'silent days are REAL ZEROS, not gaps' );
$views = array_map( static function ( $r ) { return $r['views']; }, $s );
ok( 70 === array_sum( $views ), 'the fill adds nothing to the total' );

// Without zero-fill the tail would simply be absent — pin that the series is
// long enough for a statistic to SEE the silence.
ok( count( array_filter( $views, static function ( $v ) { return 0 === $v; } ) ) === 20, 'twenty zero days are visible to the statistics' );

// A family with no rows at all is all zeros, not an empty array.
$none = snt_mr_daily_series( $quiet, 'anthropic', $FROM, $TO );
ok( 30 === count( $none ) && 0 === array_sum( array_column( $none, 'views' ) ), 'an absent family is thirty zeros' );

// Days outside the window are ignored, never folded into an edge bucket.
$outside = rows_for( 'seo', 3, 9, 'html', '2026-07-01' );
ok( 0 === array_sum( array_column( snt_mr_daily_series( $outside, 'seo', $FROM, $TO ), 'views' ) ), 'rows outside the window are dropped, not clamped into it' );

// ── PRESENCE counts DAYS, never ROWS ─────────────────────────────────────────
$multi = array();
foreach ( array( 'html', 'robots', 'sitemap', 'feed', 'asset', 'rights' ) as $surface ) {
	$multi = array_merge( $multi, rows_for( 'search', 3, 2, $surface ) );
}
ok( 18 === count( $multi ), 'fixture: six surfaces x three days = eighteen ROWS' );
ok( 3 === snt_mr_family_days( $multi, $FROM, $TO )['search'], 'presence is THREE days — breadth of surfaces is not presence' );

// A zero-hit row is not presence.
$zero = array( array( 'family' => 'feed', 'surface' => 'html', 'day' => '2026-08-05', 'hits' => 0 ) );
ok( ! isset( snt_mr_family_days( $zero, $FROM, $TO )['feed'] ), 'a zero-hit row does not make a family present' );

// ── ELIGIBILITY, against the measured live distribution ──────────────────────
// Days present, 2026-09-02: uptime/other-bot/search/seo/openai 31 (clamped to
// the 30-day window here), unclassified-machine 23, anthropic 24, perplexity 14,
// feed 11, google-ai 10, amazon-ai 9, commoncrawl 2.
$live = array();
foreach ( array(
	'uptime' => 30, 'other-bot' => 30, 'search' => 30, 'seo' => 30, 'openai' => 30,
	'anthropic' => 24, 'unclassified-machine' => 23,
	'perplexity' => 14, 'feed' => 11, 'google-ai' => 10, 'amazon-ai' => 9, 'commoncrawl' => 2,
) as $fam => $d ) {
	$live = array_merge( $live, rows_for( $fam, $d, 11 ) );
}
$eligible = snt_mr_eligible_families( $live, $FROM, $TO );
sort( $eligible );
$expected = array( 'anthropic', 'openai', 'other-bot', 'search', 'seo', 'unclassified-machine', 'uptime' );
ok( $expected === $eligible, 'the measured distribution admits exactly the seven persistent families' );
ok( ! in_array( 'perplexity', $eligible, true ) && ! in_array( 'amazon-ai', $eligible, true ), 'and excludes the sporadic ones' );
ok( 20 === SN_MR_SERIES_MIN_DAYS, 'the floor sits in the empty region of the bimodal distribution (14 | 23)' );

// The floor lands in a GAP: nothing in the live data sits between 14 and 23, so
// the threshold is robust to being a few days wrong in either direction.
$loose = snt_mr_eligible_families( $live, $FROM, $TO, 15 );
$tight = snt_mr_eligible_families( $live, $FROM, $TO, 22 );
sort( $loose ); sort( $tight );
ok( $loose === $expected && $tight === $expected, 'any floor from 15 to 22 selects the same seven — the gap is real' );

// NEGATIVE CONTROL: the floor must actually exclude something, or it is decoration.
ok( count( $eligible ) < count( snt_mr_family_days( $live, $FROM, $TO ) ), 'the floor excludes families (' . count( $eligible ) . ' of ' . count( snt_mr_family_days( $live, $FROM, $TO ) ) . ')' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
