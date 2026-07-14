<?php
/**
 * Signal & Noise — Analytics traffic-quality panels: the stacked human/suspect/bot
 * bar with top bot networks, and the bot-share-over-time trend line. Native
 * wp-admin markup via the panel primitive; injected SVG coords are esc_attr'd.
 * Extracted from analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';        // panel chrome + empty-fold collector
require_once __DIR__ . '/analytics-render-helpers.php'; // snt_analytics_smooth_path (bot-share trend)

/**
 * Traffic-quality panel: a stacked human/suspect/bot bar + the top bot networks
 * (the new edge ASN dimension filtered to class='bot'). Data from
 * sn_analytics_bot_breakdown().
 *
 * @param array $bb {totals:{human,suspect,bot,total}, top_bot_networks:[{value,views,visits}]}
 */
function snt_analytics_render_bot_breakdown( $bb ) {
	$t       = ( isset( $bb['totals'] ) && is_array( $bb['totals'] ) ) ? $bb['totals'] : array();
	$human   = (int) ( $t['human'] ?? 0 );
	$suspect = (int) ( $t['suspect'] ?? 0 );
	$bot     = (int) ( $t['bot'] ?? 0 );
	$total   = (int) ( $t['total'] ?? ( $human + $suspect + $bot ) );

	if ( $total <= 0 ) {
		snt_an_note_empty( __( 'Traffic quality', 'signal-and-noise-tools' ), 'No traffic recorded in this range yet.' );
		return;
	}
	snt_an_panel_open( __( 'Traffic quality', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside inside-flush' ) );
	echo '<div class="sn-an-panel sn-an-botbreak">';
	echo '<div class="sn-an-quality-bar">';
	foreach ( array( 'human' => $human, 'suspect' => $suspect, 'bot' => $bot ) as $cls => $v ) {
		if ( $v <= 0 ) {
			continue;
		}
		$pct = round( $v / $total * 100, 1 );
		echo '<span class="sn-an-q sn-an-q--' . esc_attr( $cls ) . '" style="width:' . esc_attr( $pct ) . '%" '
			. 'title="' . esc_attr( ucfirst( $cls ) . ': ' . number_format_i18n( $v ) . ' (' . $pct . '%)' ) . '"></span>';
	}
	echo '</div>';
	echo '<p class="sn-an-q-legend">';
	echo '<span class="sn-an-q-key sn-an-q--human"></span> Human ' . esc_html( number_format_i18n( $human ) );
	echo ' · <span class="sn-an-q-key sn-an-q--suspect"></span> Suspect ' . esc_html( number_format_i18n( $suspect ) );
	echo ' · <span class="sn-an-q-key sn-an-q--bot"></span> Bot ' . esc_html( number_format_i18n( $bot ) );
	echo '</p>';

	$nets = ( isset( $bb['top_bot_networks'] ) && is_array( $bb['top_bot_networks'] ) ) ? $bb['top_bot_networks'] : array();
	if ( ! empty( $nets ) ) {
		echo '<h4 class="sn-an-subh">Top bot networks</h4><table class="sn-an-table wp-list-table widefat striped"><thead><tr><th scope="col">Network</th><th scope="col" class="num">Views</th></tr></thead><tbody>';
		foreach ( $nets as $n ) {
			echo '<tr><td>' . esc_html( (string) ( $n['value'] ?? '' ) ) . '</td>'
				. '<td class="num">' . esc_html( number_format_i18n( (int) ( $n['views'] ?? 0 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
	snt_an_panel_close();
}

/**
 * Bot-share trend panel: a smooth SVG line + gradient area of per-bucket bot% over
 * the window, scaled to the peak with the absolute peak labelled. Red accent to match
 * the bot segment of the Quality-tab stacked bar. Data from sn_analytics_class_series()
 * (durable — no AE). Mirrors snt_analytics_render_trend()'s SVG treatment via the
 * shared smooth-path helper; only one renders per page so a fixed gradient id is safe.
 *
 * @param array $rows [{day:string, bot_pct:int, total:int, bot:int}]
 */
function snt_analytics_render_bot_trend( $rows ) {
	if ( empty( $rows ) ) {
		snt_an_note_empty( __( 'Bot share over time', 'signal-and-noise-tools' ), 'No traffic recorded in this range yet.' );
		return;
	}
	$n    = count( $rows );
	$peak = 0;
	foreach ( $rows as $r ) {
		$peak = max( $peak, (int) ( $r['bot_pct'] ?? 0 ) );
	}
	$scale = max( 1, $peak ); // scale-to-peak so a typically-low rate is readable; absolute peak is labelled.
	$w     = 600.0;
	$top   = 8.0;
	$base  = 78.0;
	$step  = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px    = array();
	$py    = array();
	foreach ( array_values( $rows ) as $i => $r ) {
		$pct  = max( 0, min( 100, (int) ( $r['bot_pct'] ?? 0 ) ) );
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( $pct / $scale ) * ( $base - $top ), 2 );
	}
	// A single day smooths to a bare moveto (invisible); pad to a flat full-width line.
	if ( count( $px ) < 2 ) {
		$px = array( 0.0, $w );
		$py = array( $py[0], $py[0] );
	}
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $px[ count( $px ) - 1 ] . ',' . $base . ' Z';
	$first  = (string) ( $rows[0]['day'] ?? '' );
	$last   = (string) ( end( $rows )['day'] ?? '' );
	$meta   = sprintf( /* translators: %s peak bot percentage */ __( 'peak %s%% bot', 'signal-and-noise-tools' ), number_format_i18n( (int) $peak ) );

	snt_an_panel_open( __( 'Bot share over time', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside inside-flush' ) );
	echo '<div class="sn-an-bot-trend">';
	echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html__( 'Bot share', 'signal-and-noise-tools' ) . '</span><span class="sn-trend-meta">' . esc_html( $meta ) . '</span></div>';
	echo '<div class="sn-spark-wrap">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
	echo '<svg class="sn-an-bot-spark" viewBox="0 0 600 84" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Bot share trend', 'signal-and-noise-tools' ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG defs, no dynamic values.
	echo '<defs><linearGradient id="snBotTrendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#d63638" stop-opacity="0.16"/><stop offset="55%" stop-color="#d63638" stop-opacity="0.04"/><stop offset="100%" stop-color="#d63638" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#snBotTrendFill)" stroke="none"/>';
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#d63638" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	echo '<div class="sn-spark-axis"><span>' . esc_html( $first ) . '</span><span>' . esc_html( $last ) . '</span></div>';
	echo '</div>';
	snt_an_panel_close();
}
