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
 * @param int|string $range Active window (int days or 'all').
 * @param string     $class Active class.
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

	// Must stay in sync with SN_ANALYTICS_RANGES; the $r . 'd' fallback fires only for unlabelled entries.
	$range_labels = array( 7 => '7d', 30 => '30d', 90 => '90d', 365 => '1y' );
	echo '<span class="sn-an-seg">';
	foreach ( SN_ANALYTICS_RANGES as $r ) {
		$url    = add_query_arg( array( 'sn_range' => $r, 'sn_class' => $class ), $base );
		$active = ( (string) $r === (string) $range ) ? 'is-active' : '';
		$label  = isset( $range_labels[ $r ] ) ? $range_labels[ $r ] : ( $r . 'd' );
		echo '<a class="' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	$url_all    = add_query_arg( array( 'sn_range' => 'all', 'sn_class' => $class ), $base );
	$active_all = ( 'all' === (string) $range ) ? 'is-active' : '';
	echo '<a class="' . esc_attr( $active_all ) . '" href="' . esc_url( $url_all ) . '">' . esc_html__( 'All', 'signal-and-noise-tools' ) . '</a>';
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
 * Bar strip of per-day/per-week views (heights relative to the series max).
 *
 * @param array  $series      [{day,views,visits}] ascending.
 * @param string $granularity 'day' (default) or 'week' — controls the aria-label.
 */
function snt_analytics_render_trend( $series, $granularity = 'day' ) {
	if ( empty( $series ) ) {
		return;
	}
	$max = 1;
	foreach ( $series as $row ) {
		$max = max( $max, (int) $row['views'] );
	}
	$aria = ( 'week' === $granularity )
		? esc_attr__( 'Weekly views trend', 'signal-and-noise-tools' )
		: esc_attr__( 'Daily views trend', 'signal-and-noise-tools' );
	echo '<div class="sn-an-trend" role="img" aria-label="' . $aria . '">';
	foreach ( $series as $row ) {
		$pct = (int) round( ( (int) $row['views'] / $max ) * 100 );
		echo '<span class="bar" style="height:' . esc_attr( max( 2, $pct ) ) . '%" title="'
			. esc_attr( $row['day'] . ': ' . number_format_i18n( (int) $row['views'] ) . ' views' ) . '"></span>';
	}
	echo '</div>';
}

/**
 * Echo a period-over-period delta badge (▲/▼/■ + signed pct). pct null → "new"
 * (prev window was empty). No-op when no delta is supplied. Echoes (rather than
 * returns) so the escaping is at the point of output (phpcs-visible).
 *
 * @param array|null $delta {pct:?int, dir:string}
 */
function snt_analytics_render_delta_badge( $delta ) {
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	echo ' <span class="sn-an-delta sn-an-delta--' . esc_attr( $dir ) . '">' . esc_html( $arrow . ' ' . $text ) . '</span>';
}

/**
 * The 5 stat cards (6 when engaged rate is available): Now, Views, Visits,
 * Avg scroll, Avg time, and optionally Engaged. Views/Visits/Avg scroll/Avg
 * time carry a period-over-period delta badge when $deltas is given (keyed
 * views/visits/scroll_avg/time_avg). "Now" never gets one (it's instant).
 *
 * @param int|null   $now     Realtime visitor count.
 * @param array      $totals  {views,visits,scroll_avg,time_avg}
 * @param array      $deltas  {views,visits,scroll_avg,time_avg} => {pct,dir}
 * @param array{current:?int,previous?:?int,pct?:?int,dir?:string}|null $engaged Engaged-rate data,
 *                                                                                or null to omit the card.
 *                                                                                Card is also hidden when
 *                                                                                current is null (e.g.
 *                                                                                all-time range with no
 *                                                                                timed-session data).
 */
function snt_analytics_render_cards( $now, $totals, $deltas = array(), $engaged = null ) {
	$cards = array(
		array( 'l' => 'Now',        'n' => ( null === $now ? '—' : number_format_i18n( (int) $now ) ), 'title' => '', 'delta' => null ),
		array( 'l' => 'Views',      'n' => number_format_i18n( (int) ( $totals['views'] ?? 0 ) ), 'title' => '', 'delta' => $deltas['views'] ?? null ),
		array(
			'l' => 'Visits',
			'n' => number_format_i18n( (int) ( $totals['visits'] ?? 0 ) ),
			// Page-weighted sum: a visitor viewing N pages in a session counts N times because the
			// rollup is keyed per-path. "Now" is always truly distinct (realtime query).
			'title' => "Page-weighted: a visitor viewing N pages counts N times. 'Now' shows true distinct visitors.",
			'delta' => $deltas['visits'] ?? null,
		),
		array( 'l' => 'Avg scroll', 'n' => (int) round( (float) ( $totals['scroll_avg'] ?? 0 ) ) . '%', 'title' => '', 'delta' => $deltas['scroll_avg'] ?? null ),
		array( 'l' => 'Avg time',   'n' => snt_analytics_fmt_time( (float) ( $totals['time_avg'] ?? 0 ) ), 'title' => '', 'delta' => $deltas['time_avg'] ?? null ),
	);
	if ( is_array( $engaged ) && null !== ( $engaged['current'] ?? null ) ) {
		$cards[] = array(
			'l'     => 'Engaged',
			'n'     => (int) $engaged['current'] . '%',
			'title' => 'Share of timed pageviews lasting ≥10s.',
			'delta' => ( isset( $engaged['dir'] ) ? $engaged : null ),
		);
	}
	echo '<div class="sn-an-cards">';
	foreach ( $cards as $c ) {
		if ( '' !== $c['title'] ) {
			echo '<div class="sn-an-card" title="' . esc_attr( $c['title'] ) . '">';
		} else {
			echo '<div class="sn-an-card">';
		}
		echo '<div class="n">' . esc_html( $c['n'] ) . '</div>';
		echo '<div class="l">' . esc_html( $c['l'] );
		if ( ! empty( $c['delta'] ) ) {
			snt_analytics_render_delta_badge( $c['delta'] );
		}
		echo '</div></div>';
	}
	echo '</div>';
}

/**
 * Referrer-source category panel: Search / Social / Direct / Other as labelled
 * percentage bars (folded from the referrer dimension in inc/analytics-derived.php).
 *
 * @param array $cats [{category,label,views,visits}]
 */
function snt_analytics_render_referrer_categories( $cats ) {
	echo '<div class="sn-an-panel sn-an-refcats"><h3>Traffic sources</h3>';
	$total = 0;
	foreach ( (array) $cats as $c ) {
		$total += (int) ( $c['views'] ?? 0 );
	}
	if ( $total <= 0 ) {
		echo '<p class="sn-an-empty">No referrer data in this range yet.</p></div>';
		return;
	}
	echo '<div class="sn-an-refcats-bars">';
	foreach ( (array) $cats as $c ) {
		$v   = (int) ( $c['views'] ?? 0 );
		$pct = (int) round( $v / $total * 100 );
		echo '<div class="sn-an-refcat">';
		echo '<div class="sn-an-refcat-h"><span>' . esc_html( (string) ( $c['label'] ?? '' ) ) . '</span>'
			. '<span class="num">' . esc_html( number_format_i18n( $v ) . ' · ' . $pct . '%' ) . '</span></div>';
		echo '<div class="sn-an-refcat-bar"><span style="width:' . esc_attr( max( 1, $pct ) ) . '%"></span></div>';
		echo '</div>';
	}
	echo '</div></div>';
}

/**
 * Distribution panel (scroll-depth or time-on-page bands) as horizontal bars
 * scaled to the peak band. Bands come pre-ordered + zero-filled from
 * sn_analytics_distribution().
 *
 * @param string $title
 * @param array  $rows  [{label,views}]
 */
function snt_analytics_render_distribution( $title, $rows ) {
	echo '<div class="sn-an-panel sn-an-dist"><h3>' . esc_html( $title ) . '</h3>';
	$max = 0;
	foreach ( (array) $rows as $r ) {
		$max = max( $max, (int) ( $r['views'] ?? 0 ) );
	}
	if ( $max <= 0 ) {
		echo '<p class="sn-an-empty">No ' . esc_html( strtolower( $title ) ) . ' data in this range yet.</p></div>';
		return;
	}
	echo '<div class="sn-an-dist-bars">';
	foreach ( (array) $rows as $r ) {
		$v   = (int) ( $r['views'] ?? 0 );
		$pct = (int) round( $v / $max * 100 );
		echo '<div class="sn-an-dist-row">';
		echo '<span class="sn-an-dist-l">' . esc_html( (string) ( $r['label'] ?? '' ) ) . '</span>';
		echo '<span class="sn-an-dist-bar"><span style="width:' . esc_attr( max( 1, $pct ) ) . '%"></span></span>';
		echo '<span class="sn-an-dist-n num">' . esc_html( number_format_i18n( $v ) ) . '</span>';
		echo '</div>';
	}
	echo '</div></div>';
}

/**
 * Hour-of-day × day-of-week heatmap (CSS grid, cell alpha = intensity). The grid
 * + peak come from sn_analytics_hour_dow_grid(); UTC because AE timestamps are UTC.
 *
 * @param array $heatmap {grid:array<int,array<int,int>>, max:int}
 */
function snt_analytics_render_heatmap( $heatmap ) {
	$grid = ( isset( $heatmap['grid'] ) && is_array( $heatmap['grid'] ) ) ? $heatmap['grid'] : array();
	$max  = (int) ( $heatmap['max'] ?? 0 );
	echo '<div class="sn-an-panel sn-an-heatmap-panel"><h3>Activity by hour (UTC)</h3>';
	if ( $max <= 0 || empty( $grid ) ) {
		echo '<p class="sn-an-empty">No hourly data in this range yet.</p></div>';
		return;
	}
	$days = array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' );
	echo '<div class="sn-an-heatmap" role="img" aria-label="' . esc_attr__( 'Visits by hour of day and day of week', 'signal-and-noise-tools' ) . '">';
	foreach ( $days as $dow => $label ) {
		echo '<div class="sn-an-hm-row"><span class="sn-an-hm-day">' . esc_html( $label ) . '</span>';
		for ( $h = 0; $h < 24; $h++ ) {
			$v     = isset( $grid[ $dow ][ $h ] ) ? (int) $grid[ $dow ][ $h ] : 0;
			$hh    = str_pad( (string) $h, 2, '0', STR_PAD_LEFT );
			$title = $label . ' ' . $hh . ':00 · ' . number_format_i18n( $v ) . ' views';
			// $max > 0 is guaranteed here (the panel returns early on an empty grid).
			if ( $v > 0 ) {
				$alpha = max( 0.12, round( $v / $max, 2 ) );
				echo '<span class="sn-an-hm-cell" style="background:rgba(34,113,177,' . esc_attr( $alpha ) . ')" title="' . esc_attr( $title ) . '"></span>';
			} else {
				echo '<span class="sn-an-hm-cell" title="' . esc_attr( $title ) . '"></span>';
			}
		}
		echo '</div>';
	}
	echo '</div></div>';
}

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

	echo '<div class="sn-an-panel sn-an-botbreak"><h3>Traffic quality</h3>';
	if ( $total <= 0 ) {
		echo '<p class="sn-an-empty">No traffic recorded in this range yet.</p></div>';
		return;
	}
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
		echo '<h4 class="sn-an-subh">Top bot networks</h4><table class="sn-an-table"><tbody>';
		foreach ( $nets as $n ) {
			echo '<tr><td>' . esc_html( (string) ( $n['value'] ?? '' ) ) . '</td>'
				. '<td class="num">' . esc_html( number_format_i18n( (int) ( $n['views'] ?? 0 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
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

/**
 * "Pages losing readers" panel: pages with meaningful traffic but weak
 * engagement (low scroll AND low dwell). Data from sn_analytics_low_engagement_paths().
 *
 * @param array $rows [{path,views,scroll_avg,time_avg}]
 */
function snt_analytics_render_lowengage( $rows ) {
	echo '<div class="sn-an-panel"><h3>Pages losing readers</h3>';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty">No low-engagement pages in this range — readers are sticking around.</p></div>';
		return;
	}
	echo '<table class="sn-an-table"><thead><tr><th>Page</th><th class="num">Views</th><th class="num">Scroll</th><th class="num">Time</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td>' . esc_html( (string) $r['path'] ) . '</td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num">' . esc_html( (int) round( (float) $r['scroll_avg'] ) . '%' ) . '</td>'
			. '<td class="num">' . esc_html( snt_analytics_fmt_time( (float) $r['time_avg'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * One-time Plausible-history import panel (v6.0.0). A multipart form with one
 * optional file input per supported CSV export, plus a one-shot summary of the
 * last import (read from a short transient). Posts sn_action=analytics_import on
 * the page=sn-theme-options route, so the shared admin-post handler accepts it.
 */
function snt_analytics_render_import() {
	if ( ! function_exists( 'sn_analytics_import_types' ) ) {
		return;
	}

	echo '<details class="sn-an-worker"><summary>Import history from Plausible (one-time CSV)</summary>';
	echo '<p class="sn-an-settings-help">Retiring Plausible? In Plausible, export each report to CSV, then upload them here to back-fill the first-party dashboard. Import <strong>history from before the edge worker went live</strong> — days the worker already tracks are overwritten by the next data refresh, so avoid importing dates that overlap live data. Re-importing is safe (idempotent). Pages, sources, locations, devices, browsers, and operating systems map across; the hour heatmap, scroll/time distributions, and network/edge/protocol/TLS dimensions start fresh from the worker.</p>';

	// One-shot summary of the last import.
	$report = function_exists( 'get_transient' ) ? get_transient( 'sn_analytics_import_report' ) : false;
	if ( is_array( $report ) ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'sn_analytics_import_report' );
		}
		echo '<div class="notice notice-success notice-alt inline"><p><strong>Imported:</strong> '
			. esc_html( number_format_i18n( (int) ( $report['daily'] ?? 0 ) ) ) . ' page-day rows';
		if ( ! empty( $report['dims'] ) && is_array( $report['dims'] ) ) {
			$bits = array();
			foreach ( $report['dims'] as $dim => $n ) {
				$bits[] = (string) $dim . ': ' . number_format_i18n( (int) $n );
			}
			echo ' · ' . esc_html( implode( ' · ', $bits ) );
		}
		echo '.</p></div>';
	}

	echo '<form method="post" enctype="multipart/form-data" class="sn-an-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<table class="form-table" role="presentation"><tbody>';
	foreach ( sn_analytics_import_types() as $type => $label ) {
		$id = 'sn_import_' . $type;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" accept=".csv,text/csv"></td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><button type="submit" name="sn_action" value="analytics_import" class="button button-primary">Import CSV history</button> ';
	echo '<span class="sn-an-empty">All fields optional — upload whichever reports you have.</span></p>';
	echo '</form></details>';
}
