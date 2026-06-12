<?php
/**
 * Signal & Noise — Analytics tab partials. Native wp-admin markup; every
 * dynamic value is escaped at the point of output (no PHPCS EscapeOutput
 * exclusion needed). See inc/analytics-admin.php for the orchestrator.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format a millisecond duration as "Nm SSs" / "Ns".
 *
 * @param float $ms Milliseconds.
 * @return string
 */
function snt_analytics_fmt_time( $ms ) {
	$secs = (int) round( (float) $ms / 1000 );
	if ( $secs < 60 ) {
		return $secs . 's';
	}
	$m = (int) floor( $secs / 60 );
	$s = $secs % 60;
	return $m . 'm ' . str_pad( (string) $s, 2, '0', STR_PAD_LEFT ) . 's';
}

/**
 * Range picker + class segmented control (GET links preserving the route).
 *
 * @param int    $range Active window.
 * @param string $class Active class.
 */
function snt_analytics_render_controls( $range, $class ) {
	// Context-aware base: preserve the CURRENT route so the controls work wherever
	// this view is hooked. v5.3.0 moved the analytics dashboard onto the Dashboard
	// tab; deriving the base from the request (vs. a hardcoded Monitoring path)
	// keeps the 7/30/90 + class links on whatever page is rendering them.
	$base = remove_query_arg( array( 'sn_range', 'sn_class' ), add_query_arg( array() ) );
	if ( '' === (string) $base ) {
		$base = admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' );
	}
	echo '<div class="sn-an-controls">';

	echo '<span class="sn-an-seg">';
	foreach ( SN_ANALYTICS_RANGES as $r ) {
		$url    = add_query_arg( array( 'sn_range' => $r, 'sn_class' => $class ), $base );
		$active = ( (int) $r === (int) $range ) ? 'is-active' : '';
		echo '<a class="' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $r . 'd' ) . '</a>';
	}
	echo '</span>';

	$labels = array( 'human' => 'Human', 'suspect' => 'Suspect', 'bot' => 'Bot' );
	echo '<span class="sn-an-seg">';
	foreach ( $labels as $key => $label ) {
		$url    = add_query_arg( array( 'sn_range' => $range, 'sn_class' => $key ), $base );
		$active = ( $key === $class ) ? 'is-active' : '';
		echo '<a class="' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</span>';

	echo '</div>';
}

/**
 * "Showing <class> traffic · N automated filtered (X bot · Y suspect)".
 *
 * @param array  $class_totals { class => {views,visits} }
 * @param string $class        Active class.
 */
function snt_analytics_render_separation( $class_totals, $class ) {
	$bot     = (int) ( $class_totals['bot']['views'] ?? 0 );
	$suspect = (int) ( $class_totals['suspect']['views'] ?? 0 );
	$auto    = $bot + $suspect;
	echo '<p class="sn-an-sep">Showing <strong>' . esc_html( $class ) . '</strong> traffic';
	if ( $auto > 0 ) {
		echo ' · ' . esc_html( number_format_i18n( $auto ) ) . ' automated filtered ('
			. esc_html( number_format_i18n( $bot ) ) . ' bot · '
			. esc_html( number_format_i18n( $suspect ) ) . ' suspect)';
	}
	echo '</p>';
}

/**
 * Bar strip of per-day views (heights relative to the series max).
 *
 * @param array $series [{day,views,visits}] ascending.
 */
function snt_analytics_render_trend( $series ) {
	if ( empty( $series ) ) {
		return;
	}
	$max = 1;
	foreach ( $series as $row ) {
		$max = max( $max, (int) $row['views'] );
	}
	echo '<div class="sn-an-trend" role="img" aria-label="' . esc_attr__( 'Daily views trend', 'signal-and-noise-tools' ) . '">';
	foreach ( $series as $row ) {
		$pct = (int) round( ( (int) $row['views'] / $max ) * 100 );
		echo '<span class="bar" style="height:' . esc_attr( max( 2, $pct ) ) . '%" title="'
			. esc_attr( $row['day'] . ': ' . number_format_i18n( (int) $row['views'] ) . ' views' ) . '"></span>';
	}
	echo '</div>';
}

/**
 * The 5 stat cards: Now, Views, Visits, Avg scroll, Avg time.
 *
 * @param int|null $now    Realtime visitor count.
 * @param array    $totals {views,visits,scroll_avg,time_avg}
 */
function snt_analytics_render_cards( $now, $totals ) {
	$cards = array(
		array( 'l' => 'Now',        'n' => ( null === $now ? '—' : number_format_i18n( (int) $now ) ), 'title' => '' ),
		array( 'l' => 'Views',      'n' => number_format_i18n( (int) ( $totals['views'] ?? 0 ) ), 'title' => '' ),
		array(
			'l' => 'Visits',
			'n' => number_format_i18n( (int) ( $totals['visits'] ?? 0 ) ),
			// Page-weighted sum: a visitor viewing N pages in a session counts N times because the
			// rollup is keyed per-path. "Now" is always truly distinct (realtime query).
			'title' => "Page-weighted: a visitor viewing N pages counts N times. 'Now' shows true distinct visitors.",
		),
		array( 'l' => 'Avg scroll', 'n' => (int) round( (float) ( $totals['scroll_avg'] ?? 0 ) ) . '%', 'title' => '' ),
		array( 'l' => 'Avg time',   'n' => snt_analytics_fmt_time( (float) ( $totals['time_avg'] ?? 0 ) ), 'title' => '' ),
	);
	echo '<div class="sn-an-cards">';
	foreach ( $cards as $c ) {
		if ( '' !== $c['title'] ) {
			echo '<div class="sn-an-card" title="' . esc_attr( $c['title'] ) . '">';
		} else {
			echo '<div class="sn-an-card">';
		}
		echo '<div class="n">' . esc_html( $c['n'] ) . '</div><div class="l">' . esc_html( $c['l'] ) . '</div></div>';
	}
	echo '</div>';
}

/**
 * Top-pages panel (path + views + visits + scroll + time).
 *
 * @param array $paths [{path,views,visits,scroll_avg,time_avg}]
 */
function snt_analytics_render_paths_table( $paths ) {
	echo '<div class="sn-an-panel"><h3>Top pages</h3>';
	if ( empty( $paths ) ) {
		echo '<p class="sn-an-empty">No page views in this range.</p></div>';
		return;
	}
	echo '<table class="sn-an-table"><thead><tr><th>Path</th><th class="num">Views</th><th class="num">Visits</th><th class="num">Scroll</th><th class="num">Time</th></tr></thead><tbody>';
	foreach ( $paths as $r ) {
		echo '<tr><td>' . esc_html( (string) $r['path'] ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td>'
			. '<td class="num">' . esc_html( (int) round( (float) $r['scroll_avg'] ) . '%' ) . '</td>'
			. '<td class="num">' . esc_html( snt_analytics_fmt_time( (float) $r['time_avg'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * A dimension breakdown panel (value + views + visits).
 *
 * @param string $title
 * @param array  $rows  [{value,views,visits}]
 * @param string $empty Empty-state copy.
 */
function snt_analytics_render_dim_table( $title, $rows, $empty ) {
	echo '<div class="sn-an-panel"><h3>' . esc_html( $title ) . '</h3>';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty">' . esc_html( $empty ) . '</p></div>';
		return;
	}
	echo '<table class="sn-an-table"><thead><tr><th>' . esc_html( $title ) . '</th><th class="num">Views</th><th class="num">Visits</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td>' . esc_html( (string) $r['value'] ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Settings form + Cloudflare Worker setup console. Doubles as the unconfigured
 * empty-state and lives inside a <details> once data is flowing. Read creds are
 * option-backed with wp-config-constant precedence (a locked field when the
 * constant is set). The Worker deploy itself is a manual CF step — documented,
 * not automated.
 */
function snt_analytics_render_settings() {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN;
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID;
	$acct_opt     = (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' );
	$token_opt    = (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' );
	$configured   = (bool) ( function_exists( 'sn_analytics_config' ) && sn_analytics_config() );

	echo '<form method="post" class="sn-an-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3>Credentials</h3>';
	echo '<p class="sn-an-settings-help">Read-only Cloudflare credentials the dashboard uses to query Analytics Engine. A wp-config constant (<code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code>) overrides these and locks the field.</p>';

	// Account ID.
	echo '<p><label for="sn_cf_account_id"><strong>Account ID</strong></label><br>';
	if ( $acct_locked ) {
		echo '<input type="text" id="sn_cf_account_id" value="(set in wp-config)" disabled class="regular-text">';
		echo '<br><span class="sn-an-empty">Locked by the <code>SN_CF_ACCOUNT_ID</code> constant.</span>';
	} else {
		echo '<input type="text" id="sn_cf_account_id" name="sn_cf_account_id" value="' . esc_attr( $acct_opt ) . '" class="regular-text" placeholder="32-char Cloudflare account ID">';
	}
	echo '</p>';

	// Read token (masked).
	echo '<p><label for="sn_cf_analytics_token"><strong>Account Analytics Read token</strong></label><br>';
	if ( $token_locked ) {
		echo '<input type="text" id="sn_cf_analytics_token" value="••••" disabled class="regular-text">';
		echo '<br><span class="sn-an-empty">Locked by the <code>SN_CF_ANALYTICS_TOKEN</code> constant.</span>';
	} else {
		echo '<input type="text" id="sn_cf_analytics_token" name="sn_cf_analytics_token" value="' . esc_attr( sn_mask_secret( $token_opt ) ) . '" class="regular-text" placeholder="Paste a fresh token; type \'clear\' to remove">';
	}
	echo '</p>';

	if ( ! ( $token_locked && $acct_locked ) ) {
		echo '<p><button type="submit" name="sn_action" value="analytics_save" class="button button-primary">Save</button> ';
		echo '<button type="submit" name="sn_action" value="analytics_test" class="button"' . ( $configured ? '' : ' disabled' ) . '>Test connection</button></p>';
	}
	echo '</form>';

	snt_analytics_render_worker_setup();
}

/**
 * Read-only Cloudflare Worker setup reference. The plugin can't run wrangler;
 * this shows the exact steps so the Cloudflare side is copy-paste, not guesswork.
 */
function snt_analytics_render_worker_setup() {
	echo '<details class="sn-an-worker"><summary>Cloudflare Worker setup (manual, one-time)</summary>';
	echo '<ol class="sn-an-steps">';
	echo '<li><strong>Read token</strong> (for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with <code>Account · Analytics · Read</code>. The Account ID is in the dashboard URL: <code>dash.cloudflare.com/&lt;account_id&gt;</code>.</li>';
	echo '<li><strong>Deploy the edge Worker + its secrets</strong> (from the analytics-worker repo — this can\'t be done from WordPress):<pre class="sn-an-pre">wrangler secret put SN_PX_TOKEN' . "\n" . 'wrangler secret put SN_PX_SALT_SEED' . "\n" . 'wrangler deploy</pre></li>';
	echo '<li><strong>Theme beacon</strong>: set <code>SN_BEACON_TOKEN</code> in <code>wp-config.php</code> to the SAME value as the Worker\'s <code>SN_PX_TOKEN</code> so the front-end beacon is accepted.</li>';
	echo '<li>Hit <strong>Test connection</strong> above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.</li>';
	echo '</ol></details>';
}
