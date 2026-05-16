<?php
/**
 * Signal & Noise — Plausible dashboard widget set.
 *
 * Registers four discrete dashboard widgets, each surfacing one slice of
 * Plausible Stats API data:
 *
 *   - sn_plausible_snapshot — 7-day aggregate (visitors, pageviews,
 *                              bounce rate, average visit duration)
 *   - sn_plausible_realtime — visitors right now (30-sec cache, distinct
 *                              from the 5-min batched cache the others use)
 *   - sn_plausible_pages    — top 7 pages, last 7 days
 *   - sn_plausible_sources  — top 7 referrers, last 7 days
 *
 * Why four widgets instead of one big panel: WP dashboard widgets are
 * draggable + per-user hideable via Screen Options, so admins can
 * arrange / hide them independently. The shared cache in
 * inc/plausible-api.php means four widgets cost one batched API fetch
 * every 5 min, not four.
 *
 * @package SignalNoise
 * @since 7.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', function() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'sn_plausible_snapshot', 'Plausible — Last 7 days',     'sn_pl_widget_snapshot' );
	wp_add_dashboard_widget( 'sn_plausible_realtime', 'Plausible — Right now',       'sn_pl_widget_realtime' );
	wp_add_dashboard_widget( 'sn_plausible_pages',    'Plausible — Top pages (7d)',  'sn_pl_widget_pages' );
	wp_add_dashboard_widget( 'sn_plausible_sources',  'Plausible — Top sources (7d)', 'sn_pl_widget_sources' );
} );

/**
 * Inline CSS, printed once per pageload (the first widget that renders
 * triggers it; subsequent calls are no-ops via the static guard).
 */
function sn_pl_styles() {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	?>
	<style>
	/* WP admin native styling — no theme fonts, WP palette only.
	   #1d2327 primary text · #646970 muted · #2271b1 link · #f0f0f1 hairline · #d63638 error. */
	.sn-pl-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 18px;margin:0;}
	.sn-pl-stat-n{font-size:1.6rem;font-weight:600;color:#1d2327;line-height:1.1;}
	.sn-pl-stat-l{font-size:0.85em;color:#646970;margin-top:2px;}
	.sn-pl-big{font-size:2.5rem;font-weight:600;color:#1d2327;text-align:center;line-height:1;padding:8px 0 4px;}
	.sn-pl-big-l{font-size:0.85em;color:#646970;text-align:center;}
	.sn-pl-list{list-style:none;margin:0;padding:0;font-size:0.875em;}
	.sn-pl-list li{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f1;gap:10px;}
	.sn-pl-list li:last-child{border-bottom:0;}
	.sn-pl-list .k{color:#1d2327;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
	.sn-pl-list .v{color:#646970;flex-shrink:0;font-variant-numeric:tabular-nums;}
	.sn-pl-foot{margin:12px 0 0;font-size:0.85em;color:#646970;}
	.sn-pl-empty{color:#646970;font-size:0.875em;font-style:italic;margin:0;}
	.sn-pl-err{color:#d63638;font-size:0.9em;margin:0;}
	</style>
	<?php
}

/**
 * Common preamble: ensure styles are printed and resolve the batched
 * dataset (or print an error and return null).
 */
function sn_pl_preamble() {
	sn_pl_styles();
	$data = sn_plausible_dashboard_data();
	if ( ! $data ) {
		echo '<p class="sn-pl-err">Plausible not configured. Set domain in <em>Settings → Plausible Analytics</em>, then create a Stats API key (<em>Plausible → Settings → API Keys</em>) and add to <code>wp-config.php</code>:<br><code style="display:inline-block;margin-top:4px;font-size:0.95em;">define( \'SN_PLAUSIBLE_STATS_TOKEN\', \'plnt_…\' );</code></p>';
		return null;
	}
	return $data;
}

function sn_pl_widget_snapshot() {
	$data = sn_pl_preamble();
	if ( ! $data ) {
		return;
	}
	$a = $data['aggregate'];
	echo '<div class="sn-pl-grid">';
	sn_pl_stat( 'Visitors',  $a['visitors']['value']  ?? null );
	sn_pl_stat( 'Pageviews', $a['pageviews']['value'] ?? null );
	sn_pl_stat( 'Bounce',    isset( $a['bounce_rate']['value'] ) ? $a['bounce_rate']['value'] . '%' : null );
	sn_pl_stat( 'Avg time',  isset( $a['visit_duration']['value'] ) ? sn_pl_duration( $a['visit_duration']['value'] ) : null );
	echo '</div>';
	sn_pl_footer( $data, '7d' );
	// Diagnostic only on the snapshot widget — one place is enough; the
	// other three panels are downstream of the same API + cache.
	sn_pl_render_diagnostic();
}

/**
 * Render the most recent API error inline, gated to admins only. When
 * the snapshot widget shows "—" everywhere, this is what tells the
 * maintainer whether they're looking at a bad URL, a bad token, a
 * scope mismatch, or a network blip.
 */
function sn_pl_render_diagnostic() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'sn_plausible_last_error' ) ) {
		return;
	}
	$err = sn_plausible_last_error();
	if ( ! $err ) {
		return;
	}
	$code_label = $err['code'] > 0 ? ( 'HTTP ' . (int) $err['code'] ) : 'Network error';
	echo '<div style="margin-top:12px;padding:8px 10px;background:#fcf0f1;border-left:3px solid #d63638;font-size:0.8em;line-height:1.5;color:#1d2327;">';
	echo '<strong>API call failed.</strong> ' . esc_html( $code_label ) . ' from <code style="font-size:0.95em;word-break:break-all;">' . esc_html( $err['url'] ) . '</code>';
	if ( ! empty( $err['message'] ) ) {
		echo '<br><span style="color:#646970;">' . esc_html( $err['message'] ) . '</span>';
	}
	echo '</div>';
}

function sn_pl_widget_realtime() {
	sn_pl_styles();
	$cfg = sn_plausible_config();
	if ( ! $cfg ) {
		echo '<p class="sn-pl-err">Plausible not configured. Set domain in <em>Settings → Plausible Analytics</em>, then create a Stats API key (<em>Plausible → Settings → API Keys</em>) and add to <code>wp-config.php</code>:<br><code style="display:inline-block;margin-top:4px;font-size:0.95em;">define( \'SN_PLAUSIBLE_STATS_TOKEN\', \'plnt_…\' );</code></p>';
		return;
	}
	$n = sn_plausible_realtime();
	echo '<div class="sn-pl-big-l">Visitors right now</div>';
	echo '<div class="sn-pl-big">' . esc_html( null === $n ? '—' : number_format_i18n( (int) $n ) ) . '</div>';
	$dash = admin_url( 'index.php?page=plausible_analytics_statistics' );
	echo '<p class="sn-pl-foot">Last 5 min · refreshes every 30 s · <a href="' . esc_url( $dash ) . '">Open dashboard →</a></p>';
}

function sn_pl_widget_pages() {
	$data = sn_pl_preamble();
	if ( ! $data ) {
		return;
	}
	sn_pl_breakdown( $data['pages'], 'page', 'No traffic in the last 7 days.' );
	sn_pl_footer( $data, '7d' );
}

function sn_pl_widget_sources() {
	$data = sn_pl_preamble();
	if ( ! $data ) {
		return;
	}
	sn_pl_breakdown( $data['sources'], 'source', 'No referrers tracked in the last 7 days.', 'Direct / None' );
	sn_pl_footer( $data, '7d' );
}

function sn_pl_breakdown( $rows, $key, $empty_msg, $blank_label = '' ) {
	if ( empty( $rows ) ) {
		echo '<p class="sn-pl-empty">' . esc_html( $empty_msg ) . '</p>';
		return;
	}
	echo '<ul class="sn-pl-list">';
	foreach ( $rows as $row ) {
		$k = (string) ( $row[ $key ] ?? '' );
		$v = (int)    ( $row['visitors'] ?? 0 );
		if ( '' === $k && '' !== $blank_label ) {
			$k = $blank_label;
		}
		echo '<li><span class="k">' . esc_html( $k ) . '</span><span class="v">' . esc_html( number_format_i18n( $v ) ) . '</span></li>';
	}
	echo '</ul>';
}

function sn_pl_stat( $label, $value ) {
	$display = ( null === $value || '' === $value )
		? '—'
		: ( is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value );
	echo '<div class="sn-pl-stat"><div class="sn-pl-stat-n">' . esc_html( $display ) . '</div><div class="sn-pl-stat-l">' . esc_html( $label ) . '</div></div>';
}

function sn_pl_duration( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	$m = (int) floor( $seconds / 60 );
	$s = $seconds % 60;
	return $m . 'm ' . str_pad( (string) $s, 2, '0', STR_PAD_LEFT ) . 's';
}

function sn_pl_footer( $data, $period_label ) {
	// Internal admin link — the Plausible plugin's embedded stats page.
	// User is already authenticated in /wp-admin/, so no target=_blank
	// (it's an in-app navigation, not a hop to plausible.io).
	$dash    = admin_url( 'index.php?page=plausible_analytics_statistics' );
	$fetched = (int) ( $data['fetched'] ?? 0 );
	// fetched=0 means the SWR background refresh hasn't landed yet
	// (first-ever pageview after install / cache flush). Distinguish
	// this from real cached data so the footer doesn't print
	// "cached 56 years ago" off a 1970-epoch timestamp.
	$status  = $fetched > 0
		? 'cached ' . esc_html( human_time_diff( $fetched, time() ) ) . ' ago'
		: '<em>refreshing in background — reload in a moment</em>';
	echo '<p class="sn-pl-foot">' . esc_html( $period_label ) . ' · ' . $status . ' · <a href="' . esc_url( $dash ) . '">Open dashboard →</a></p>';
}
