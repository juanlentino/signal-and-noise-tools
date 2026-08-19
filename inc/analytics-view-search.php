<?php
/**
 * Signal & Noise Tools — Analytics view: Search (R6b step 3).
 *
 * The half of the funnel first-party analytics structurally cannot see. The
 * collector learns a visit arrived from google.com and never learns WHY —
 * Google strips the query. Everything here comes from Search Console, and
 * everything here is about what happened BEFORE the click.
 *
 * WHY THIS VIEW IGNORES THE DASHBOARD'S RANGE CONTROL, and says so on screen:
 * the stored payload is one rolling window fetched on a schedule, not a
 * queryable history. Silently rendering it under a "Last 7 days" heading would
 * be the most expensive kind of wrong — a number that is right about a period
 * nobody asked for. The window it actually describes is printed in the header.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Format a 0..1 CTR as a percentage. Google sends a FRACTION; 0.032 is 3.2%. */
function snt_gsc_fmt_ctr( $ctr ) {
	return number_format_i18n( 100 * (float) $ctr, 1 ) . '%';
}

/** Average position, one decimal. LOWER IS BETTER — never sort this descending. */
function snt_gsc_fmt_position( $p ) {
	return number_format_i18n( (float) $p, 1 );
}

/**
 * Render one metrics table.
 *
 * @param string $title
 * @param string $key_label Column header for the dimension.
 * @param array  $rows      Each ['key','clicks','impressions','ctr','position'].
 * @param string $empty
 */
function snt_gsc_render_metrics_table( $title, $key_label, $rows, $empty ) {
	// The shared panel helper, not hand-rolled markup: it owns .postbox +
	// .sn-an-postbox and the header shape every other view already uses.
	if ( empty( $rows ) ) {
		snt_an_note_empty( $title, $empty );
		return;
	}
	snt_an_panel_open( $title, array( 'inside_class' => 'inside sn-an-table-inside' ) );
	echo '<div class="snt-scroll-table"><table class="widefat striped"><thead><tr>';
	echo '<th scope="col">' . esc_html( $key_label ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Clicks', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Impressions', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'CTR', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Avg position', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $row['key'] ) . '</code></td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $row['clicks'] ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $row['impressions'] ) ) . '</td>';
		echo '<td>' . esc_html( snt_gsc_fmt_ctr( $row['ctr'] ) ) . '</td>';
		echo '<td>' . esc_html( snt_gsc_fmt_position( $row['position'] ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
	snt_an_panel_close();
}

/**
 * A visible, actionable setup panel.
 *
 * Separate from the empty-fold collector on purpose: these states are not
 * "this panel had no rows in your range", they are "the pipeline is not
 * connected yet, and here is the one next step".
 *
 * @param string $message
 */
function snt_gsc_render_setup_notice( $message ) {
	snt_an_panel_open( __( 'Search', 'signal-and-noise-tools' ) );
	echo '<p>' . esc_html( $message ) . '</p>';
	echo '<p class="description">' . esc_html__( 'Search Console reports what happened BEFORE the click — impressions, position, and the queries first-party analytics can never see.', 'signal-and-noise-tools' ) . '</p>';
	snt_an_panel_close();
}

/**
 * The Search view body.
 *
 * @since 11.19.0
 */
function snt_analytics_render_view_search() {
	$data = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;

	// Empty states are SPECIFIC: each one names the single next action. "No data"
	// would be true for all four and useful for none.
	// SETUP states render a real panel, NOT snt_an_note_empty(): that helper
	// COLLECTS into a fold flushed at the end of a view, and its summary reads
	// "No data in this range yet" — wrong words for "you have not chosen a
	// property", and invisible entirely if the view returns before flushing.
	// Which is exactly what shipped in v11.19.0: three early returns queued a
	// note, returned, and rendered a blank tab.
	if ( ! function_exists( 'snt_gsc_credential_is_configured' ) || ! snt_gsc_credential_is_configured() ) {
		snt_gsc_render_setup_notice( __( 'No Search Console credential yet. Add one under Measurement → Search Console.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( '' === (string) sn_setting( 'search_console.property', '' ) ) {
		snt_gsc_render_setup_notice( __( 'The credential is stored, but no property is selected yet. Open Measurement → Search Console, run Test connection, then choose the property to read.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( null === $data ) {
		snt_gsc_render_setup_notice( __( 'A property is selected but nothing has synced yet. Open Measurement → Search Console and choose Sync now.', 'signal-and-noise-tools' ) );
		return;
	}

	// The header states the window and the property, because this view answers
	// for a period the range control above did not choose.
	$age = human_time_diff( (int) $data['synced_at'], time() );
	snt_an_panel_open( __( 'Window', 'signal-and-noise-tools' ) );
	echo '<p><strong>' . esc_html( (string) $data['property'] ) . '</strong> · ';
	printf(
		/* translators: 1: start date, 2: end date. */
		esc_html__( 'Google\'s window: %1$s to %2$s', 'signal-and-noise-tools' ),
		esc_html( (string) $data['window']['start'] ),
		esc_html( (string) $data['window']['end'] )
	);
	echo ' · ';
	/* translators: %s: human-readable duration. */
	printf( esc_html__( 'synced %s ago', 'signal-and-noise-tools' ), esc_html( $age ) );
	echo '</p>';
	echo '<p class="description">' . esc_html__( 'This view reports Google\'s own rolling window and does NOT follow the date range selected above — Search Console data is fetched on a schedule, not queried per range. It also ends a few days back on purpose: the most recent days are still being counted, and a fresh zero there is not a measurement.', 'signal-and-noise-tools' ) . '</p>';
	snt_an_panel_close();

	$queries = (array) $data['queries'];
	snt_gsc_render_metrics_table(
		__( 'Top search queries', 'signal-and-noise-tools' ),
		__( 'Query', 'signal-and-noise-tools' ),
		array_slice( $queries, 0, 25 ),
		__( 'No queries in this window.', 'signal-and-noise-tools' )
	);

	// Pages, as rows shaped like the query table so one renderer serves both.
	$pages = array();
	foreach ( (array) $data['pages'] as $path => $m ) {
		$pages[] = array_merge( array( 'key' => $path ), $m );
	}
	usort( $pages, static function ( $a, $b ) {
		return $b['impressions'] <=> $a['impressions'];
	} );
	snt_gsc_render_metrics_table(
		__( 'Pages by impressions', 'signal-and-noise-tools' ),
		__( 'Path', 'signal-and-noise-tools' ),
		array_slice( $pages, 0, 25 ),
		__( 'No pages in this window.', 'signal-and-noise-tools' )
	);

	// The one list with no first-party counterpart AND a clear action: Google
	// shows the page often and nobody clicks. Ranked by impressions, because a
	// page with 3 impressions and no clicks is noise, not an opportunity.
	$missed = array_values( array_filter( $pages, static function ( $r ) {
		return $r['impressions'] >= 50 && $r['clicks'] === 0;
	} ) );
	snt_gsc_render_metrics_table(
		__( 'Seen but never clicked', 'signal-and-noise-tools' ),
		__( 'Path', 'signal-and-noise-tools' ),
		array_slice( $missed, 0, 15 ),
		__( 'Every page with meaningful impressions has earned at least one click in this window.', 'signal-and-noise-tools' )
	);

	// The per-view contract: snt_an_note_empty() only COLLECTS, and every view
	// flushes its own fold. Omitting this renders nothing AND leaks these notes
	// into whichever view flushes next.
	snt_an_flush_empty_fold();
}
