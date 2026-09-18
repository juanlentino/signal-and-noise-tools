<?php
/**
 * Signal & Noise Tools — the Bing panel of S&N Analytics › Search.
 *
 * Painted after Google's tables from the record the daily Bing sync stores
 * (inc/bing-webmaster.php). Three states, each naming its one next action:
 * no key (Connections › Credentials), a key with nothing synced yet, a
 * synced window with its KPIs and top queries. Same table renderer as the
 * Google tables, so the two readings sit in the same shape.
 *
 * @since 16.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The panel. Silent when the module is not loaded.
 *
 * @since 16.2.0
 */
function snt_analytics_render_search_bing() {
	if ( ! function_exists( 'sn_bing_data' ) || ! function_exists( 'snt_gsc_render_metrics_table' ) ) {
		return;
	}
	// The band paints AFTER the view's empty fold has flushed, so its own
	// empty states are visible panels, never collected notes (a note
	// collected here would leak into the next view; see the flush contract).
	if ( ! sn_bing_is_ready() ) {
		snt_an_panel_open( __( 'Bing', 'signal-and-noise-tools' ) );
		echo '<p>' . esc_html__( 'No Bing Webmaster API key yet. Mint one at Bing Webmaster Tools › Settings › API access and paste it under Connections › Credentials.', 'signal-and-noise-tools' ) . '</p>';
		snt_an_panel_close();
		return;
	}
	$data = sn_bing_data();
	if ( null === $data || empty( $data['totals'] ) ) {
		$why = __( 'The key is stored; the first daily sync has not run yet.', 'signal-and-noise-tools' );
		if ( ! empty( $data['last_error'] ) ) {
			/* translators: %s: the last sync error. */
			$why = sprintf( __( 'The last sync failed: %s', 'signal-and-noise-tools' ), (string) $data['last_error'] );
		}
		snt_an_panel_open( __( 'Bing', 'signal-and-noise-tools' ) );
		echo '<p>' . esc_html( $why ) . '</p>';
		snt_an_panel_close();
		return;
	}

	$t   = (array) $data['totals'];
	$age = human_time_diff( (int) $data['synced_at'], time() );
	snt_an_panel_open( __( 'Bing', 'signal-and-noise-tools' ) );
	echo '<p><strong>' . esc_html( (string) ( $data['site'] ?? '' ) ) . '</strong> · ';
	printf(
		/* translators: 1: start date, 2: end date. */
		esc_html__( 'Bing\'s window: %1$s to %2$s', 'signal-and-noise-tools' ),
		esc_html( (string) $data['window']['start'] ),
		esc_html( (string) $data['window']['end'] )
	);
	echo ' · ';
	/* translators: %s: human-readable duration. */
	printf( esc_html__( 'synced %s ago', 'signal-and-noise-tools' ), esc_html( $age ) );
	echo '</p>';
	if ( ! empty( $data['last_error'] ) ) {
		echo '<p class="description">' . esc_html( (string) $data['last_error'] ) . '</p>';
	}
	if ( function_exists( 'snt_an_kpi_row' ) ) {
		$days = (int) ( $t['days'] ?? 0 );
		snt_an_kpi_row( array(
			/* translators: %d: days counted. */
			array( 'l' => __( 'Impressions', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $t['impressions'] ), 'sub' => sprintf( __( '%d days', 'signal-and-noise-tools' ), $days ) ),
			/* translators: %d: days counted. */
			array( 'l' => __( 'Clicks', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $t['clicks'] ), 'sub' => sprintf( __( '%d days', 'signal-and-noise-tools' ), $days ) ),
			array( 'l' => __( 'CTR', 'signal-and-noise-tools' ), 'n' => (int) $t['impressions'] > 0 ? number_format_i18n( 100 * (int) $t['clicks'] / (int) $t['impressions'], 1 ) . '%' : '—', 'sub' => __( 'clicks over impressions', 'signal-and-noise-tools' ) ),
		) );
	}
	echo '<p class="description">' . esc_html__( 'Bing counts impressions and clicks across web, chat, news, images and video. Traffic updates daily, query positions weekly; the window ends on the newest day Bing reports. Copilot and the engines that read Bing\'s index sit behind these numbers, which is why they are here beside Google\'s.', 'signal-and-noise-tools' ) . '</p>';
	// 16.2.3: the queries live INSIDE the Bing panel (one band, one panel),
	// clamped at ten like every other table in this view.
	snt_gsc_render_metrics_table(
		__( 'Top Bing queries', 'signal-and-noise-tools' ),
		__( 'Query', 'signal-and-noise-tools' ),
		array_slice( (array) $data['queries'], 0, 25 ),
		__( 'No queries in this window.', 'signal-and-noise-tools' ),
		true
	);
	snt_an_panel_close();
}
