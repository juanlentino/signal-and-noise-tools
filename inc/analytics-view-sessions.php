<?php
/**
 * Signal & Noise Tools — Analytics view: Sessions (cookieless within-day) (v8.8.0).
 *
 * Renders within-day session quality, engaged-read, transitions, and funnels
 * from the session engine (inc/analytics-sessions.php). Labeled "Sessions"
 * since v9.65.0 (slug stays 'visits'): the tab counts within-day sessions that
 * reset at UTC midnight — never a cross-day identity, and a DIFFERENT unit
 * from the shared Overview headline's pageview-gated visitor-day "Visits".
 *
 * @package SignalNoiseTools
 * @since 8.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';

/**
 * Render the visit-quality summary + transitions + funnels panels.
 *
 * @param array $metrics From sn_session_metrics().
 * @param array $paths   From sn_session_paths().
 * @param array $funnels List of array{title:string,report:array} (from sn_funnel_report()).
 * @param bool  $capped  Whether the raw row cap was hit.
 * @param array $attribution From sn_goal_attribution(): array{entry,conversions} rows.
 * @param array|null|false $trend_rows From sn_session_rollup_read(): typed
 *                    per-day rows (v9.65.0 trend panel), null when that read
 *                    FAILED, or false (default) when the caller didn't fetch —
 *                    legacy callers render byte-identically.
 */
function snt_analytics_render_summary_panels( $metrics, $paths, $funnels, $capped, $attribution = array(), $trend_rows = false ) {
	if ( (int) $metrics['visits'] < 1 ) {
		snt_an_note_empty( __( 'Session quality', 'signal-and-noise-tools' ), __( 'No sessions in this range yet.', 'signal-and-noise-tools' ) );
	} else {
		// v9.65.0 units fix: this tab counts within-day SESSIONS (live session
		// engine) — a different unit from the shared Overview headline's
		// "Visits" (pageview-gated visitor-DAYS, durable rollup). Same word on
		// one dashboard undid the honest-naming goal; the heading + one-line
		// unit note below say which unit this is. Labels only — no metric changed.
		snt_an_panel_open( __( 'Session quality', 'signal-and-noise-tools' ), array( 'header_meta' => 'within-day sessions · reset at UTC midnight: a different unit from the Overview headline&#8217;s visitor-day Visits' ) );
		snt_an_annotation( sn_annotation_visit_quality( $metrics ) ); // v9.5.0 read: high/low engaged-read range
		// Cohesive with the Overview KPI strip — now literally shares snt_an_kpi_row.
		// No period-over-period delta here yet, so the delta slot carries a muted
		// descriptor (matches the strip's three-line card rhythm).
		$cards = array(
			array( 'l' => __( 'Sessions', 'signal-and-noise-tools' ),        'n' => number_format_i18n( (int) $metrics['visits'] ),                'sub' => __( 'with a pageview', 'signal-and-noise-tools' ),    'promoted' => true ),
			array( 'l' => __( 'Bounce rate', 'signal-and-noise-tools' ),     'n' => number_format_i18n( $metrics['bounce_rate'] * 100, 1 ) . '%',  'sub' => __( 'single-page sessions', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Pages / session', 'signal-and-noise-tools' ), 'n' => number_format_i18n( $metrics['pages_per_visit'], 2 ),          'sub' => __( 'mean', 'signal-and-noise-tools' ),               'promoted' => true ),
			array( 'l' => __( 'Median duration', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $metrics['median_duration'] ) . 's', 'sub' => __( 'per session', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Engaged reads', 'signal-and-noise-tools' ),   'n' => number_format_i18n( $metrics['engaged_rate'] * 100, 1 ) . '%', 'sub' => __( 'scroll + dwell', 'signal-and-noise-tools' ) ),
		);
		snt_an_kpi_row( $cards );
		if ( $capped ) {
			echo '<p class="sn-an-empty">' . esc_html__( 'Results capped for this window: narrow the date range for exact figures.', 'signal-and-noise-tools' ) . '</p>';
		}
		snt_an_panel_close();
	}

	// Long-term session-quality trend from the durable nightly rollup
	// (wp_sn_session_daily) — false = legacy caller / module absent, skip.
	if ( false !== $trend_rows ) {
		snt_analytics_render_session_trend( $trend_rows );
	}

	// Transitions — the table helper owns its own panel chrome + empty-state, so
	// build the rows and hand off directly (matching every sibling view). An
	// empty transition set collapses into the empty fold, no hollow panel.
	$rows = array();
	foreach ( (array) $paths as $p ) {
		$rows[] = array(
			'value'  => $p['from'] . ' → ' . $p['to'],
			'views'  => (int) $p['count'],
			'visits' => (int) $p['count'],
		);
	}
	snt_analytics_render_dim_table( __( 'Top paths', 'signal-and-noise-tools' ), $rows, '' );

	// Funnels as completion bars. The distribution helper owns its own panel +
	// empty-note keyed on the funnel title, so an all-zero funnel (nobody
	// reached step 1) collapses into the empty fold under its OWN name rather
	// than emitting a hollow titled panel + a mislabeled "Reached step" note.
	foreach ( (array) $funnels as $f ) {
		$bars = array();
		foreach ( (array) $f['report'] as $step ) {
			$bars[] = array(
				'label' => $step['label'],
				'views' => (int) $step['reached'],
			);
		}
		snt_analytics_render_distribution( $f['title'], $bars, '', true );
	}

	// Conversion sources — where the visits that converted first landed. Single-metric
	// bars (entry page → converting visits); the distribution helper owns its own empty
	// fold, so a window with no contact conversions collapses under this title rather
	// than emitting a hollow panel. Wide labels because entry paths run long.
	$attr_rows = array();
	foreach ( (array) $attribution as $a ) {
		$attr_rows[] = array( 'label' => (string) ( $a['entry'] ?? '' ), 'views' => (int) ( $a['conversions'] ?? 0 ) );
	}
	// v9.5.0 read: one entry page dominates conversions. The distribution helper
	// owns its own chrome, so the read renders just above that panel.
	snt_an_annotation( sn_annotation_conversions( $attribution ) );
	snt_analytics_render_distribution( __( 'Contact conversions by entry page', 'signal-and-noise-tools' ), $attr_rows, '', true );

	snt_an_flush_empty_fold();
}

/**
 * Long-term session-quality trend panel (v9.65.0) — the first reader of the
 * durable wp_sn_session_daily rollup (written nightly since v8.8.0, read by
 * nothing until now). Three sparklines (bounce %, pages/session, median
 * duration) over the rolled-up days in the selected window, via the shared
 * snt_an_trend_svg primitive — no new JS, no new CSS.
 *
 * Honest states, one per input shape (never a fabricated flat line):
 *   null      → the table could not be read — fold with a "could not be read"
 *               why (a failed read is NOT an empty window);
 *   []        → the nightly rollup has written nothing in this window — fold;
 *   1 row     → a trend needs two points — fold says so, not "no data";
 *   >=2 rows  → the panel. snt_an_trend_svg positions points by ARRAY INDEX,
 *               so a missing day leaves no positional hole — gaps DO compress
 *               on the line itself. What keeps them inferable is the framing:
 *               the axis endpoints span the FIRST..LAST rolled-up day and the
 *               unit note states how many days actually rolled up, so a count
 *               short of the axis span betrays skipped nights.
 *
 * Unit is explicit (sessions ≠ visitor-days — the Part 3 contract): these are
 * within-day sessions from the session engine's nightly snapshot, a different
 * unit from the Overview headline's visitor-day Visits.
 *
 * @since 9.65.0
 * @param array|null $rows sn_session_rollup_read() output (or null on failure).
 */
function snt_analytics_render_session_trend( $rows ) {
	$title = __( 'Session quality trend', 'signal-and-noise-tools' );
	if ( ! is_array( $rows ) ) {
		snt_an_note_empty( $title, __( 'The durable session rollup table could not be read: this is a read failure, not an empty window.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( 0 === count( $rows ) ) {
		snt_an_note_empty( $title, __( 'The nightly session rollup has no rolled-up days in this window yet: it writes one row per day and class after each UTC day closes.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( count( $rows ) < 2 ) {
		snt_an_note_empty( $title, __( 'Only one rolled-up day in this window: the trend needs at least two.', 'signal-and-noise-tools' ) );
		return;
	}

	$rows   = array_values( $rows );
	$last   = $rows[ count( $rows ) - 1 ];
	$bounce = array_map( static function ( $r ) { return (float) $r['bounce_pct']; }, $rows );
	$ppv    = array_map( static function ( $r ) { return (float) $r['ppv']; }, $rows );
	$dur    = array_map( static function ( $r ) { return (float) $r['median_dur']; }, $rows );

	snt_an_panel_open( $title, array( 'header_meta' => 'sessions · nightly rollup' ) );
	// The explicit unit line (Part 3): sessions, not the headline's visitor-days.
	echo '<p class="sn-an-empty">' . esc_html( sprintf(
		/* translators: %d: number of days the nightly rollup has written in this window */
		__( 'Counts within-day sessions (reset at UTC midnight) across %d rolled-up days: a different unit from the Overview headline\'s visitor-day Visits.', 'signal-and-noise-tools' ),
		count( $rows )
	) ) . '</p>';
	snt_an_trend_svg( $bounce, array(
		'head'      => __( 'Bounce rate', 'signal-and-noise-tools' ),
		/* translators: %s: bounce percentage of the most recent rolled-up day */
		'meta'      => sprintf( __( 'latest %s%%', 'signal-and-noise-tools' ), number_format_i18n( (float) $last['bounce_pct'], 1 ) ),
		'id_suffix' => 'SessBounce',
	) );
	snt_an_trend_svg( $ppv, array(
		'head'      => __( 'Pages / session', 'signal-and-noise-tools' ),
		/* translators: %s: pages-per-session of the most recent rolled-up day */
		'meta'      => sprintf( __( 'latest %s', 'signal-and-noise-tools' ), number_format_i18n( (float) $last['ppv'], 2 ) ),
		'id_suffix' => 'SessPpv',
	) );
	snt_an_trend_svg( $dur, array(
		'head'      => __( 'Median duration', 'signal-and-noise-tools' ),
		/* translators: %s: median session duration (seconds) of the most recent rolled-up day */
		'meta'      => sprintf( __( 'latest %ss', 'signal-and-noise-tools' ), number_format_i18n( (int) $last['median_dur'] ) ),
		'id_suffix' => 'SessDur',
		'axis'      => array( (string) $rows[0]['day'], (string) $last['day'] ),
	) );
	snt_an_panel_close();
}

/**
 * View entry point — dispatched from snt_analytics_render_dashboard().
 *
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class.
 */
function snt_analytics_render_view_sessions( $from, $to, $class ) {
	$data = sn_analytics_fetch_session_events( $from, $to, $class );
	if ( empty( $data['configured'] ) ) {
		snt_an_gate( __( 'Sessions', 'signal-and-noise-tools' ), __( 'Session analytics need live Analytics Engine data for this window.', 'signal-and-noise-tools' ) );
		return;
	}
	// A "visit" requires >= 1 pageview: server events (srv:1 / RSS ce) and orphan
	// scroll/timing beacons are not visits (they live in the Events view). Without
	// this, an RSS feed reader polling hourly gap-splits into dozens of phantom
	// pageview-less "visits" and wrecks bounce / pages-per-visit / duration.
	$visits  = sn_pageview_visits( $data['summaries'] );
	$metrics = sn_session_metrics( $visits );
	$paths   = sn_session_paths( $visits, 15 );
	$funnels = array();
	foreach ( sn_analytics_session_funnels() as $def ) {
		$funnels[] = array(
			'title'  => $def['title'],
			'report' => sn_funnel_report( $visits, $def['steps'] ),
		);
	}
	// Conversion attribution for the contact-* goal family (theme v10.28.0 emits
	// contact-<alias> per /contact email link). Computed from the same visits — no
	// extra query. Overridable prefix stays internal; the panel is contact-scoped.
	$attribution = sn_goal_attribution( $visits, 'contact-' );
	// Durable long-term trend (v9.65.0): the read half of the nightly
	// wp_sn_session_daily rollup. function_exists is defence in depth for a
	// half-wired install; production loads the rollup module unconditionally.
	$trend_rows = function_exists( 'sn_session_rollup_read' )
		? sn_session_rollup_read( $from, $to, $class )
		: false;
	snt_analytics_render_summary_panels( $metrics, $paths, $funnels, ! empty( $data['capped'] ), $attribution, $trend_rows );
}
