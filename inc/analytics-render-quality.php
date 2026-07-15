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
		snt_an_note_empty( __( 'Traffic quality', 'signal-and-noise-tools' ), __( 'No traffic recorded in this range yet.', 'signal-and-noise-tools' ) );
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
	echo '<span class="sn-an-q-key sn-an-q--human"></span> ' . esc_html__( 'Human', 'signal-and-noise-tools' ) . ' ' . esc_html( number_format_i18n( $human ) );
	echo ' · <span class="sn-an-q-key sn-an-q--suspect"></span> ' . esc_html__( 'Suspect', 'signal-and-noise-tools' ) . ' ' . esc_html( number_format_i18n( $suspect ) );
	echo ' · <span class="sn-an-q-key sn-an-q--bot"></span> ' . esc_html__( 'Bot', 'signal-and-noise-tools' ) . ' ' . esc_html( number_format_i18n( $bot ) );
	echo '</p>';

	$nets = ( isset( $bb['top_bot_networks'] ) && is_array( $bb['top_bot_networks'] ) ) ? $bb['top_bot_networks'] : array();
	if ( ! empty( $nets ) ) {
		echo '<h4 class="sn-an-subh">' . esc_html__( 'Top bot networks', 'signal-and-noise-tools' ) . '</h4><table class="sn-an-table wp-list-table widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Network', 'signal-and-noise-tools' ) . '</th><th scope="col" class="num">' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
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
 * (durable — no AE). Routes through the shared trend-SVG primitive (D5 §3); the
 * fold-on-empty stays here (it needs the caller's own diagnostic string). id_suffix
 * 'Bot' (-> 'snSparkFillBot') de-collides the gradient id from the Overview header
 * trend's 'snSparkFill' — the two DO co-render on this same Quality-tab page (the
 * header trend renders above the tab body on every shared-chrome view), so a bare
 * id would silently steal the wrong gradient (first-id-wins under duplicate SVG ids).
 *
 * @param array $rows [{day:string, bot_pct:int, total:int, bot:int}]
 */
function snt_analytics_render_bot_trend( $rows ) {
	if ( empty( $rows ) ) {
		snt_an_note_empty( __( 'Bot share over time', 'signal-and-noise-tools' ), __( 'No traffic recorded in this range yet.', 'signal-and-noise-tools' ) );
		return;
	}
	// scale-to-peak so a typically-low rate is readable; absolute peak is labelled.
	// $peak stays UNCLAMPED (parity with the pre-D5 copy) while each plotted point is
	// clamped to [0,100] — the primitive derives its own scale from this series.
	$peak   = 0;
	$series = array();
	foreach ( $rows as $r ) {
		$series[] = max( 0, min( 100, (int) ( $r['bot_pct'] ?? 0 ) ) );
		$peak     = max( $peak, (int) ( $r['bot_pct'] ?? 0 ) );
	}
	// A single day smooths to a bare moveto (invisible) inside the primitive too —
	// pad to a flat two-point line (parity with the pre-D5 copy's px/py padding).
	if ( count( $series ) < 2 ) {
		$series = array( $series[0], $series[0] );
	}
	$first = (string) ( $rows[0]['day'] ?? '' );
	$last  = (string) ( end( $rows )['day'] ?? '' );
	$meta  = sprintf( /* translators: %s peak bot percentage */ __( 'peak %s%% bot', 'signal-and-noise-tools' ), number_format_i18n( (int) $peak ) );

	snt_an_panel_open( __( 'Bot share over time', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside inside-flush' ) );
	snt_an_trend_svg(
		$series,
		array(
			'stroke'     => '#d63638',
			'head'       => __( 'Bot share', 'signal-and-noise-tools' ),
			'meta'       => $meta,
			'axis'       => array( $first, $last ),
			'aria_label' => __( 'Bot share trend', 'signal-and-noise-tools' ),
			'wrap_class' => 'sn-an-bot-trend',
			'svg_class'  => 'sn-an-bot-spark',
			'id_suffix'  => 'Bot',
		)
	);
	snt_an_panel_close();
}
