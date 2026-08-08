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

const SN_PUBLIC_STATS_CACHE_KEY = 'sn_public_stats_v1';
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
 * @return array{views:int,visits:int,automated_views:int,top:array<string,int>,days:int}|null
 */
function sn_public_stats_assemble( $class_totals, $human_rows ) {
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

	$by_path = array();
	foreach ( $human_rows as $row ) {
		$path = isset( $row['path'] ) ? (string) $row['path'] : '';
		if ( '' === $path || sn_analytics_is_excluded_path( $path ) ) {
			continue;
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
	);
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
		function_exists( 'sn_analytics_daily_range' ) ? sn_analytics_daily_range( $from, $to, 'human' ) : array()
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
