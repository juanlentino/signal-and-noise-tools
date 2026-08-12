<?php
/**
 * Signal & Noise Tools — [sn_public_stats], the public stats page.
 *
 * The roadmap's Analytics planned row made real: "the site's aggregate
 * numbers published for readers, reusing the existing rollups read-only —
 * no new collection." This module READS sn_analytics_class_totals() and
 * sn_analytics_daily_range() (inc/analytics-rollup.php) over the last 30
 * COMPLETE UTC days (ending yesterday — today is a partial day and would
 * undercount; the read layer's UTC-"today" lesson applied) and renders:
 *
 *   - three stat tiles: human views, human visits, automated views
 *     filtered (suspect + bot classes, shown so the human numbers are
 *     believable rather than merely flattering);
 *   - the most-read pages (top human paths aggregated across the window,
 *     admin/login paths dropped by the same predicate ingestion uses);
 *   - a method note: first-party, cookieless, aggregates only, and the
 *     visits = reader-days honesty line (visits can exceed views per
 *     path-day structurally — a visit is one reader-day, site-wide).
 *
 * Zero and null are different answers (the family invariant): an empty
 * rollup window renders "not measured yet", NEVER a wall of zeros. The
 * assembled payload is transient-cached for an hour; the sentinel for
 * "assembled, found nothing" is a marker array so a cache hit on
 * no-data is distinguishable from a cache miss.
 *
 * Light-only brutalist register, same as every public maturity surface;
 * stylesheet enqueued at shortcode render only.
 *
 * @package SignalNoiseTools
 * @since 10.65.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// _v2: the payload gained the 'daily' series (charts that speak). The key
// carries the shape version so an hour-old pre-series payload can never be
// served into a render that expects the series (the narration _v2 pattern).
const SN_PUBLIC_STATS_CACHE_KEY = 'sn_public_stats_v2';
const SN_PUBLIC_STATS_CACHE_TTL = HOUR_IN_SECONDS;
const SN_PUBLIC_STATS_DAYS      = 30;
const SN_PUBLIC_STATS_TOP_N     = 8;

/**
 * The window: the last 30 COMPLETE UTC days, ending yesterday.
 *
 * @return array{0:string,1:string} [from, to], YYYY-MM-DD inclusive.
 */
function sn_public_stats_window() {
	$to   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	$from = gmdate( 'Y-m-d', time() - ( SN_PUBLIC_STATS_DAYS * DAY_IN_SECONDS ) );
	return array( $from, $to );
}

/**
 * Assemble the public payload from rollup reads — PURE, so the honesty
 * rules are testable without a database. Returns null when the window
 * holds no measurements at all: never-measured is not zero.
 *
 * @param array<string,array{views:int,visits:int}> $class_totals sn_analytics_class_totals() shape.
 * @param array<int,array<string,mixed>>            $human_rows   sn_analytics_daily_range() shape (class 'human').
 * @param array{0:string,1:string}|null             $window       [from, to] YYYY-MM-DD; null derives the live window.
 * @return array{views:int,visits:int,automated_views:int,top:array<string,int>,days:int,daily:array<string,int>}|null
 */
function sn_public_stats_assemble( $class_totals, $human_rows, $window = null ) {
	$class_totals = is_array( $class_totals ) ? $class_totals : array();
	$human_rows   = is_array( $human_rows ) ? $human_rows : array();
	if ( array() === $class_totals && array() === $human_rows ) {
		return null;
	}

	$human     = isset( $class_totals['human'] ) ? $class_totals['human'] : array( 'views' => 0, 'visits' => 0 );
	$automated = 0;
	foreach ( array( 'suspect', 'bot' ) as $class ) {
		$automated += isset( $class_totals[ $class ]['views'] ) ? (int) $class_totals[ $class ]['views'] : 0;
	}

	// Charts that speak: the daily series is zero-filled across EVERY day of
	// the window first, then rows land on their day. Inside a MEASURED window
	// a day with no rows is a real zero — the exact inverse of the module's
	// never-measured-is-not-zero rule one level up, and both are honest for
	// the same reason: the record says which question was actually asked.
	if ( ! is_array( $window ) || 2 !== count( $window ) ) {
		$window = sn_public_stats_window();
	}
	$daily  = array();
	$cursor = strtotime( (string) $window[0] . ' UTC' );
	$end    = strtotime( (string) $window[1] . ' UTC' );
	while ( false !== $cursor && false !== $end && $cursor <= $end ) {
		$daily[ gmdate( 'Y-m-d', $cursor ) ] = 0;
		$cursor += DAY_IN_SECONDS;
	}

	$by_path = array();
	foreach ( $human_rows as $row ) {
		$path = isset( $row['path'] ) ? (string) $row['path'] : '';
		if ( '' === $path || sn_analytics_is_excluded_path( $path ) ) {
			continue;
		}
		// The series and the top list share one population: the same rows,
		// after the same exclusion. A day outside the window cannot enter —
		// the zero-filled keys ARE the window, so a stray row has no slot.
		$day = isset( $row['day'] ) ? (string) $row['day'] : '';
		if ( array_key_exists( $day, $daily ) ) {
			$daily[ $day ] += (int) ( $row['views'] ?? 0 );
		}
		// v10.65.1: rollup paths are stored as requested, so "/notes" and
		// "/notes/" arrive as separate rows and would rank as two half-sized
		// entries (measured live: the notes archive displayed split 36+21
		// instead of ranking #2 at 57). Normalize to the site's canonical
		// trailing-slash form before aggregating.
		$path = '/' === $path ? '/' : rtrim( $path, '/' ) . '/';
		$by_path[ $path ] = ( $by_path[ $path ] ?? 0 ) + (int) ( $row['views'] ?? 0 );
	}
	arsort( $by_path );

	return array(
		'views'           => (int) ( $human['views'] ?? 0 ),
		'visits'          => (int) ( $human['visits'] ?? 0 ),
		'automated_views' => $automated,
		'top'             => array_slice( $by_path, 0, SN_PUBLIC_STATS_TOP_N, true ),
		'days'            => SN_PUBLIC_STATS_DAYS,
		'daily'           => $daily,
	);
}

/**
 * A day label for public prose and the twin table: "Aug 3". date_i18n when
 * WordPress provides it; the fixture-safe gmdate form otherwise.
 *
 * @param string $day YYYY-MM-DD.
 * @return string
 */
function sn_public_stats_day_label( $day ) {
	$ts = strtotime( (string) $day . ' UTC' );
	if ( false === $ts ) {
		return (string) $day;
	}
	return function_exists( 'date_i18n' ) ? date_i18n( 'M j', $ts ) : gmdate( 'M j', $ts );
}

/**
 * The one-paragraph rhythm summary — PURE and deterministic, computed from
 * the series alone. This is the half of "charts that speak" a screen reader
 * hears first; the twin table is the other half. No model is ever consulted:
 * a public reader surface states facts the code can defend, in a sentence
 * that renders identically for every reader on every run.
 *
 * Ties resolve to the EARLIEST day (both busiest and quietest) so two runs
 * over unchanged data agree to the byte. An all-zero series returns '' — the
 * tiles already state the totals, and a rhythm section narrating silence
 * would be filler wearing accessibility clothes.
 *
 * @param array<string,int> $daily Date => views, in window order.
 * @return string Plain text; the render escapes at its sink.
 */
function sn_public_stats_rhythm_sentence( $daily ) {
	$daily = is_array( $daily ) ? array_map( 'intval', $daily ) : array();
	$total = array_sum( $daily );
	if ( array() === $daily || $total <= 0 ) {
		return '';
	}

	$busiest_day  = null;
	$busiest_v    = -1;
	$quietest_day = null;
	$quietest_v   = PHP_INT_MAX;
	foreach ( $daily as $day => $views ) {
		if ( $views > $busiest_v ) {
			$busiest_day = $day;
			$busiest_v   = $views;
		}
		if ( $views < $quietest_v ) {
			$quietest_day = $day;
			$quietest_v   = $views;
		}
	}

	$half   = (int) floor( count( $daily ) / 2 );
	$values = array_values( $daily );
	$first  = array_sum( array_slice( $values, 0, $half ) );
	$second = array_sum( array_slice( $values, $half ) );

	return sprintf(
		/* translators: 1: total views. 2: number of days. 3: busiest day (e.g. "Aug 3"). 4: its views. 5: quietest day. 6: its views. 7: first-half views. 8: second-half views. */
		__( 'Across these %2$d days, readers viewed pages %1$s times. The busiest day was %3$s with %4$s views; the quietest was %5$s with %6$s. The first half of the window carried %7$s views, the second half %8$s.', 'signal-and-noise-tools' ),
		number_format_i18n( $total ),
		count( $daily ),
		sn_public_stats_day_label( (string) $busiest_day ),
		number_format_i18n( $busiest_v ),
		sn_public_stats_day_label( (string) $quietest_day ),
		number_format_i18n( $quietest_v ),
		number_format_i18n( $first ),
		number_format_i18n( $second )
	);
}

/**
 * The rhythm section: prose + decorative SVG bars + the table twin. Returns
 * '' when the series is absent (a stale pre-series payload) or all-zero (the
 * tiles already carry the number; a chart of silence is noise).
 *
 * The SVG is aria-hidden BECAUSE the section speaks twice without it: the
 * paragraph states the shape, the details-folded table IS the chart, row for
 * row, navigable by a screen reader's own table commands. The picture is the
 * garnish, never the meal.
 *
 * @param array $data The assembled payload.
 * @return string
 */
function sn_public_stats_rhythm_html( $data ) {
	$daily = isset( $data['daily'] ) && is_array( $data['daily'] ) ? $data['daily'] : array();
	$sent  = sn_public_stats_rhythm_sentence( $daily );
	if ( '' === $sent ) {
		return '';
	}

	$max = max( $daily );
	$n   = count( $daily );

	$out  = '<h3>' . esc_html__( 'Reading rhythm', 'signal-and-noise-tools' ) . '</h3>';
	$out .= '<p class="sn-public-stats__rhythm-summary">' . esc_html( $sent ) . '</p>';

	// One bar per day, integer geometry. A non-zero day never rounds to
	// nothing: the floor of 1 unit keeps a 1-view day visible.
	$bar_w  = 8;
	$gap    = 2;
	$chart_h = 56;
	$width  = $n * ( $bar_w + $gap ) - $gap;
	$out   .= '<svg class="sn-public-stats__chart" viewBox="0 0 ' . (int) $width . ' ' . (int) $chart_h . '" preserveAspectRatio="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">';
	$x      = 0;
	foreach ( $daily as $views ) {
		$h    = $max > 0 ? (int) round( $views / $max * $chart_h ) : 0;
		$h    = ( $views > 0 && $h < 1 ) ? 1 : $h;
		$out .= '<rect x="' . (int) $x . '" y="' . (int) ( $chart_h - $h ) . '" width="' . (int) $bar_w . '" height="' . (int) $h . '"/>';
		$x   += $bar_w + $gap;
	}
	$out .= '</svg>';

	$days_keys = array_keys( $daily );
	$out .= '<details class="sn-public-stats__twin"><summary>' . esc_html__( 'The same numbers as a table', 'signal-and-noise-tools' ) . '</summary>';
	$out .= '<table><caption>' . esc_html( sprintf(
		/* translators: 1: first day of the window (e.g. "Jul 13"). 2: last day (e.g. "Aug 11"). */
		__( 'Daily human pageviews, %1$s to %2$s', 'signal-and-noise-tools' ),
		sn_public_stats_day_label( (string) $days_keys[0] ),
		sn_public_stats_day_label( (string) $days_keys[ $n - 1 ] )
	) ) . '</caption>';
	$out .= '<thead><tr><th scope="col">' . esc_html__( 'Day', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $daily as $day => $views ) {
		$out .= '<tr><td>' . esc_html( sn_public_stats_day_label( (string) $day ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $views ) ) . '</td></tr>';
	}
	return $out . '</tbody></table></details>';
}

/**
 * Read + assemble + cache. The no-data sentinel array('none' => true)
 * keeps a cached "nothing measured" distinguishable from a cache miss —
 * array_key_exists territory, never ?? (the zero-vs-null lesson).
 *
 * @return array|null Assembled payload, or null when nothing is measured.
 */
function sn_public_stats_data() {
	$cached = get_transient( SN_PUBLIC_STATS_CACHE_KEY );
	if ( is_array( $cached ) ) {
		return array_key_exists( 'none', $cached ) ? null : $cached;
	}

	list( $from, $to ) = sn_public_stats_window();
	$assembled = sn_public_stats_assemble(
		function_exists( 'sn_analytics_class_totals' ) ? sn_analytics_class_totals( $from, $to ) : array(),
		function_exists( 'sn_analytics_daily_range' ) ? sn_analytics_daily_range( $from, $to, 'human' ) : array(),
		array( $from, $to )
	);

	set_transient( SN_PUBLIC_STATS_CACHE_KEY, null === $assembled ? array( 'none' => true ) : $assembled, SN_PUBLIC_STATS_CACHE_TTL );
	return $assembled;
}

/**
 * A path's public label: the published page's own title when the path
 * resolves to one, the path itself otherwise — never a dead guess.
 *
 * @param string $path Site-relative path from the rollup row.
 * @return string
 */
function sn_public_stats_path_label( $path ) {
	$path = (string) $path;
	if ( '/' === $path ) {
		return __( 'Home', 'signal-and-noise-tools' );
	}
	if ( function_exists( 'url_to_postid' ) && function_exists( 'get_the_title' ) ) {
		$post_id = url_to_postid( home_url( $path ) );
		if ( $post_id > 0 ) {
			$title = (string) get_the_title( $post_id );
			if ( '' !== $title ) {
				return $title;
			}
		}
	}
	return $path;
}

/**
 * Render the stats block. Escaped HTML throughout; numbers via
 * number_format_i18n so thousands read like prose.
 *
 * @return string
 */
function sn_public_stats_html() {
	$data = sn_public_stats_data();

	$out = '<div class="sn-public-stats">';

	if ( null === $data ) {
		// Never-measured is an answer, not an error — and never a zero.
		$out .= '<p class="sn-public-stats__none">' . esc_html__( 'Not measured yet. These counters are honest: no data reads as unknown, never as zero.', 'signal-and-noise-tools' ) . '</p></div>';
		return $out;
	}

	/* translators: %d: number of complete days in the measurement window. */
	$window_label = sprintf( esc_html__( 'Last %d days', 'signal-and-noise-tools' ), (int) $data['days'] );

	$tiles = array(
		array( $data['views'], __( 'Views', 'signal-and-noise-tools' ), __( 'human pageviews', 'signal-and-noise-tools' ), '' ),
		array( $data['visits'], __( 'Visits', 'signal-and-noise-tools' ), __( 'reader-days — the same reader tomorrow counts again', 'signal-and-noise-tools' ), '' ),
		array( $data['automated_views'], __( 'Automated', 'signal-and-noise-tools' ), __( 'crawler and bot views, filtered OUT of the numbers to the left', 'signal-and-noise-tools' ), ' sn-public-stats__tile--dim' ),
	);

	$out .= '<p class="sn-public-stats__window">' . $window_label . '</p><div class="sn-public-stats__tiles">';
	foreach ( $tiles as $tile ) {
		$out .= '<div class="sn-public-stats__tile' . esc_attr( $tile[3] ) . '">'
			. '<span class="sn-public-stats__stat">' . esc_html( number_format_i18n( (int) $tile[0] ) ) . '</span>'
			. '<span class="sn-public-stats__label">' . esc_html( $tile[1] ) . '</span>'
			. '<span class="sn-public-stats__sub">' . esc_html( $tile[2] ) . '</span>'
			. '</div>';
	}
	$out .= '</div>';

	$out .= sn_public_stats_rhythm_html( $data );

	if ( array() !== $data['top'] ) {
		$out .= '<h3>' . esc_html__( 'Most read', 'signal-and-noise-tools' ) . '</h3><ol class="sn-public-stats__top">';
		foreach ( $data['top'] as $path => $views ) {
			$out .= '<li><a href="' . esc_url( home_url( (string) $path ) ) . '">' . esc_html( sn_public_stats_path_label( (string) $path ) ) . '</a>'
				. '<span class="sn-public-stats__views">' . esc_html( number_format_i18n( (int) $views ) ) . '</span></li>';
		}
		$out .= '</ol>';
	}

	$out .= '<p class="sn-public-stats__method">' . esc_html__( 'First-party, cookieless measurement at the edge. Aggregates only — the site can see that a page was read, never who read it. No consent banner because there is nothing to consent to.', 'signal-and-noise-tools' ) . '</p>';

	return $out . '</div>';
}

/** Enqueue the front stylesheet; shortcode-render time only. */
function sn_public_stats_enqueue() {
	wp_enqueue_style(
		'sn-public-stats-front',
		plugins_url( 'assets/public-stats-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_public_stats] — returns (never echoes), read-only over rollups.
 *
 * @param array|string $atts Shortcode attributes (unused; reserved).
 * @return string
 */
function sn_public_stats_shortcode( $atts = array() ) {
	sn_public_stats_enqueue();
	return sn_public_stats_html();
}
add_shortcode( 'sn_public_stats', 'sn_public_stats_shortcode' );
