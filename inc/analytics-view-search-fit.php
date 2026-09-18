<?php
/**
 * Signal & Noise Tools — the query-fit band of S&N Analytics › Search.
 *
 * Third band, after Google and Bing: what Jev reads about the queries
 * Google already sends to each note (inc/jev-query-fit.php). Two tables in
 * one panel: the gaps (a query with impressions the note does not answer)
 * and the stray traffic (clicks on a query the note answers at level zero).
 *
 * @since 16.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One fit table inside the open panel. */
function snt_analytics_render_fit_table( $title, array $rows, $empty ) {
	if ( empty( $rows ) ) {
		echo '<h3 class="sn-an-subhead">' . esc_html( $title ) . '</h3><p class="description">' . esc_html( $empty ) . '</p>';
		return;
	}
	echo '<h3 class="sn-an-subhead">' . esc_html( $title ) . '</h3>';
	snt_an_clamp_open( count( $rows ), 10 );
	echo '<div class="snt-scroll-table"><table class="widefat striped"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Query', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Note', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Impressions', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Clicks', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Fit (of 2)', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $r['query'] ) . '</code></td>';
		echo '<td><a href="' . esc_url( get_edit_post_link( (int) $r['id'] ) ?: '' ) . '">' . esc_html( (string) $r['note'] ) . '</a></td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $r['impressions'] ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $r['clicks'] ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (float) $r['score'], 2 ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
	snt_an_clamp_close( count( $rows ), 10 );
}

/** The band. Silent when the module is not loaded. */
function snt_analytics_render_search_fit() {
	if ( ! function_exists( 'sn_jev_fit_data' ) ) {
		return;
	}
	$title = __( 'Query fit (Jev)', 'signal-and-noise-tools' );
	if ( ! sn_jev_fit_is_ready() ) {
		snt_an_panel_open( $title );
		echo '<p>' . esc_html__( 'Needs a TypeSafe key and a Search Console property, both under Connections › Credentials.', 'signal-and-noise-tools' ) . '</p>';
		snt_an_panel_close();
		return;
	}
	$data = sn_jev_fit_data();
	if ( null === $data || empty( $data['synced_at'] ) ) {
		snt_an_panel_open( $title );
		echo '<p>' . esc_html( ! empty( $data['last_error'] ) ? (string) $data['last_error'] : __( 'The first weekly pass has not run yet. AI › Agent tools › jev-fit-now runs it now.', 'signal-and-noise-tools' ) ) . '</p>';
		snt_an_panel_close();
		return;
	}
	$r = sn_jev_fit_readings( $data );
	snt_an_panel_open( $title );
	printf(
		'<p>%s</p>',
		esc_html( sprintf(
			/* translators: 1: notes judged, 2: queries judged, 3: how long ago. */
			__( '%1$d notes, %2$d queries, judged %3$s ago. A query with impressions the note does not answer is the next note; clicks on a query answered at level zero are a title chasing the wrong search.', 'signal-and-noise-tools' ),
			count( (array) $data['notes'] ),
			array_sum( array_map( static function ( $n ) { return count( (array) ( $n['rows'] ?? array() ) ); }, (array) $data['notes'] ) ),
			human_time_diff( (int) $data['synced_at'], time() )
		) )
	);
	if ( ! empty( $data['last_error'] ) ) {
		echo '<p class="description">' . esc_html( (string) $data['last_error'] ) . '</p>';
	}
	snt_analytics_render_fit_table( __( 'Gaps: seen, not answered', 'signal-and-noise-tools' ), $r['gaps'], __( 'Every query with impressions is answered at least in passing.', 'signal-and-noise-tools' ) );
	snt_analytics_render_fit_table( __( 'Stray traffic: clicked, not answered', 'signal-and-noise-tools' ), $r['stray'], __( 'No clicks land on a query the note does not answer.', 'signal-and-noise-tools' ) );
	snt_an_panel_close();
}
