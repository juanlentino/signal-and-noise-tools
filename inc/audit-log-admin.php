<?php
/**
 * Signal & Noise Tools — Audit Log admin sub-tab renderer.
 *
 * Renders the Security → Audit log sub-tab. Layout matches the Dashboard
 * tab pattern (4-card hero grid + tables below).
 *
 * Wired by the tab registry, not a hand-written dispatch arm: the 'audit-log'
 * leaf in inc/admin-tabs-data.php names 'snt_audit_log_render_tab' as its
 * `render` callback and inc/admin-dispatch.php calls it. (The if/elseif arm
 * quoted here previously was replaced by the registry in v6.17.x.)
 *
 * @package SignalNoiseTools
 * @since 3.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main render entrypoint for the Audit log sub-tab.
 */
function snt_audit_log_render_tab() {
	// Handle "Prune now" POST first so the redirect happens before output.
	if ( isset( $_POST['sn_action'] ) && 'audit_prune_now' === $_POST['sn_action'] ) {
		check_admin_referer( 'sn_theme_options_nonce' );
		if ( current_user_can( 'manage_options' ) ) {
			$stats = snt_audit_prune_impl();
			echo '<div class="notice notice-success is-dismissible"><p><strong>Prune complete.</strong> ' .
				esc_html( sprintf(
					'%d counter bucket(s) dropped, %d login row(s) dropped, LLA delta +%d.',
					$stats['counter_buckets_dropped'],
					$stats['login_rows_dropped'],
					$stats['lla_delta']
				) ) .
				'</p></div>';
		}
	}

	$summary  = snt_audit_get_summary_impl();
	$counters = snt_audit_get_counters_impl( 30 );
	$logins   = snt_audit_get_login_successes_impl( 30 );

	// v7.1.0: two-column sn_admin_shell, matching the other leaves (Insights /
	// Health). Wide data (glance hero + the 7-column counter timeline + the
	// logins table) belongs in the MAIN column; the passive readouts and config
	// (LLA status, retention, maintenance + export) move to the narrower RAIL.
	// Contract: no early return between shell_open() and shell_close().
	sn_admin_shell_open();

	// ── MAIN: intro + at-a-glance + the wide data tables ──
	$retention_intro = (int) sn_setting( 'audit.retention_days', 90 );
	echo '<p class="sn-prose">Captures login-related events (successful logins, failed attempts, our /wp-login.php and unauth /wp-admin reconnaissance 404s, password resets, LLA lockouts). ' . esc_html( $retention_intro ) . '-day retention. Hashed-IP unique-attacker count via ephemeral transient: no raw or hashed IPs are stored long-term.</p>';
	snt_audit_log_render_hero( $summary );
	snt_audit_log_render_counter_table( $counters );
	snt_audit_log_render_logins_table( $logins );

	// ── RAIL: passive readouts + config ──
	sn_admin_shell_rail( 'Audit status and maintenance' );
	snt_audit_log_render_lla_card( $summary['lla'] );
	snt_audit_log_render_retention_form();
	snt_audit_log_render_prune_form();

	sn_admin_shell_close();
}

/**
 * Build the 4 first-glance hero cards for sn_admin_glance_grid(). Pure — takes
 * the summary, returns the card array. Mirrors snt_cron_glance_cards(). Rising
 * 7-day attack volume reads as 'warn' (bad), falling/steady as 'ok'.
 *
 * @param array $summary snt_audit_get_summary_impl() result.
 * @return array<int,array<string,mixed>>
 */
function snt_audit_log_glance_cards( $summary ) {
	$pct   = (int) $summary['last_7d_vs_prior']['pct_delta'];
	$cur   = (int) $summary['last_7d_vs_prior']['current'];
	$prior = (int) $summary['last_7d_vs_prior']['prior'];
	$locks = (int) $summary['lla']['active_lockouts'];

	if ( $pct > 0 ) {
		$trend_pill = array( 'kind' => 'warn', 'text' => 'rising' );
	} elseif ( $pct < 0 ) {
		$trend_pill = array( 'kind' => 'ok', 'text' => 'easing' );
	} else {
		$trend_pill = array( 'kind' => 'ok', 'text' => 'steady' );
	}

	return array(
		array(
			'label'     => 'Last 24h',
			'value'     => number_format_i18n( (int) $summary['last_24h']['all_total'] ),
			'meta_html' => esc_html( (int) $summary['last_24h']['failed_total'] . ' failed · ' . (int) $summary['last_24h']['recon_total'] . ' recon' ),
		),
		array(
			'label'     => 'Last 7d trend',
			'value'     => ( $pct > 0 ? '+' : '' ) . $pct . '%',
			'meta_html' => esc_html( $cur . ' vs ' . $prior . ' prior' ),
			'pill'      => $trend_pill,
		),
		array(
			'label'     => 'Unique IPs (24h)',
			'value'     => number_format_i18n( (int) $summary['unique_attackers_24h'] ),
			'meta_html' => 'hashed, not stored',
		),
		array(
			'label'     => 'LLA status',
			'value'     => number_format_i18n( $locks ),
			'meta_html' => 'active lockouts',
			'pill'      => $locks > 0 ? array( 'kind' => 'warn', 'text' => 'locked' ) : array( 'kind' => 'ok', 'text' => 'clear' ),
		),
	);
}

/**
 * Render the 4-card first-glance hero via the shared token-driven glance grid
 * (v6.47.0 — converged off the bespoke .sn-audit-card vocabulary onto
 * sn_admin_glance_grid, matching Dashboard / Cron / Health / Tags).
 */
function snt_audit_log_render_hero( $summary ) {
	if ( ! function_exists( 'sn_admin_glance_grid' ) ) {
		return;
	}
	echo '<section aria-label="Audit log at a glance">';
	sn_admin_glance_grid( snt_audit_log_glance_cards( $summary ) );
	echo '</section>';
}

/**
 * Render the day-bucketed counter timeline table.
 */
function snt_audit_log_render_counter_table( $counters ) {
	// v8.0.2: system card + wide modifier (the 7-column timeline earned the
	// leaf's 'wide' flag). The old analytics-dashboard postbox mirror (D8) was a
	// cross-surface reference; in-page consistency wins. The .snt-scroll-table
	// wrapper stays nested so the 30-row timeline keeps its sticky-header scroll.
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">Counter timeline (last 30 days)</h2>';
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat sn-audit-timeline">';
	echo '<thead><tr>';
	echo '<th scope="col">Date</th>';
	echo '<th scope="col">Failed</th>';
	echo '<th scope="col">Login 404</th>';
	echo '<th scope="col">Admin 404</th>';
	echo '<th scope="col">Lockouts</th>';
	echo '<th scope="col">Pwd reset</th>';
	echo '<th scope="col">Unique IPs</th>';
	echo '</tr></thead>';
	echo '<tbody>';
	foreach ( $counters as $row ) {
		$row_total = (int) $row['login_failed'] + (int) $row['wp_login_404'] + (int) $row['wp_admin_unauth_404'] + (int) $row['lockout_triggered'] + (int) $row['password_reset'];
		$row_class = $row_total > 0 ? '' : ' sn-audit-row-empty';
		echo '<tr class="' . esc_attr( trim( $row_class ) ) . '">';
		echo '<td>' . esc_html( $row['date'] ) . '</td>';
		echo '<td>' . (int) $row['login_failed'] . '</td>';
		echo '<td>' . (int) $row['wp_login_404'] . '</td>';
		echo '<td>' . (int) $row['wp_admin_unauth_404'] . '</td>';
		echo '<td>' . (int) $row['lockout_triggered'] . '</td>';
		echo '<td>' . (int) $row['password_reset'] . '</td>';
		echo '<td>' . (int) $row['unique_ips_count'] . '</td>';
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
	echo '</div>';   // .snt-scroll-table
	echo '</div>';   // .sn-fieldset
}

/**
 * Render the recent successful logins table.
 */
function snt_audit_log_render_logins_table( $logins ) {
	// v8.0.2: system card (was a postbox mirroring the analytics dashboard). The
	// empty state renders inside the card via the plugin's own .sn-prose — the
	// old .sn-an-empty classes live in analytics-admin.css, a stylesheet this
	// tab never owned.
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">Recent successful logins (last 30 days)</h2>';
	if ( empty( $logins ) ) {
		echo '<p class="sn-prose">No successful logins recorded in this window.</p>';
		echo '</div>';  // .sn-fieldset
		return;
	}
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat sn-audit-logins">';
	echo '<thead><tr><th scope="col">Timestamp</th><th scope="col">User</th></tr></thead>';
	echo '<tbody>';
	foreach ( $logins as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( $row['formatted'] ) . '</code></td>';
		echo '<td>' . esc_html( $row['user'] ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
	echo '</div>';   // .snt-scroll-table
	echo '</div>';   // .sn-fieldset
}

/**
 * Render the small LLA summary card with a deep-link to LLA settings.
 */
function snt_audit_log_render_lla_card( $lla ) {
	$recent = $lla['most_recent_lockout_ts']
		? wp_date( 'Y-m-d H:i:s', (int) $lla['most_recent_lockout_ts'] )
		: 'never';
	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">limit-login-attempts-reloaded</p>';
	echo '<p>Active lockouts: <strong>' . (int) $lla['active_lockouts'] . '</strong>. Most recent lockout: <code>' . esc_html( $recent ) . '</code>.</p>';
	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=limit-login-attempts' ) ) . '" class="button button-secondary">Manage in LLA →</a></p>';
	echo '</div>';
}

/**
 * Render the "Prune now" form.
 */
function snt_audit_log_render_prune_form() {
	// v6.47.0: own a .sn-fieldset card so the block doesn't float bare now the
	// leaf is 'wide' (bare .sn-section). Mirrors the retention form's chrome.
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Maintenance</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="audit_prune_now">';
	$retention_days = (int) sn_setting( 'audit.retention_days', 90 );
	echo '<p class="sn-prose">Manually run the daily prune now. Drops counter buckets and login_success rows older than ' . esc_html( $retention_days ) . ' days, plus polls LLA for new lockouts.</p>';
	echo '<p><button type="submit" class="button">Prune now</button></p>';
	echo '</form>';

	// v4.10.0: download the audit log (counters + login successes) as CSV/JSON.
	// Nonce-protected admin-post.php GET links — NOT a form POST, so they never
	// clobber the PRG save handler. The payload includes plaintext usernames.
	$export_json_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=sn_audit_export&format=json' ),
		'sn_audit_export',
		'sn_audit_export_nonce'
	);
	$export_csv_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=sn_audit_export&format=csv' ),
		'sn_audit_export',
		'sn_audit_export_nonce'
	);
	echo '<p class="sn-prose">Download the audit log (per-day counters + successful-login rows over the retention window). The export contains plaintext usernames.</p>';
	echo '<p>';
	echo '<a class="button" href="' . esc_url( $export_json_url ) . '">Export JSON</a> ';
	echo '<a class="button" href="' . esc_url( $export_csv_url ) . '">Export CSV</a>';
	echo '</p>';
	echo '</div>'; // .sn-fieldset
}

/**
 * Render the retention-days form on the Audit log tab. Mirrors the
 * RSS tracker retention pattern (inc/rss-feed-tracker.php:493).
 *
 * @since 4.2.0
 */
function snt_audit_log_render_retention_form() {
	$retention = (int) sn_setting( 'audit.retention_days', 90 );

	echo '<form method="post" class="sn-fieldset">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="audit_save_retention">';
	echo '<h2 class="sn-fieldset-h">Retention</h2>';
	echo '<label class="sn-field-label" for="sn_audit_retention">Retention (days)</label>';
	echo '<input type="number" id="sn_audit_retention" name="audit_retention_days" class="small-text" min="7" max="365" value="' . esc_attr( (string) $retention ) . '">';
	echo '<p class="sn-field-helper">How long to keep counter buckets and <code>login_success</code> rows. Range 7–365. Daily cron prune enforces this.</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save retention</button></div>';
	echo '</form>';
}

/**
 * Enqueue audit-log.css on any SN admin page.
 *
 * v4.5.2: previously guarded on 'toplevel_page_sn-theme-options' + a tab/sub
 * query refinement. That missed the 'Security' submenu deep-link
 * (?page=sn-security), whose hook_suffix is 'signal-noise_page_sn-security'
 * and which carries no tab/sub args — so the audit hero grid rendered UNSTYLED
 * when reached via the sidebar. Mirror the canonical guard used by
 * cron-dashboard-admin.php (D-11): load on any registered SN page. The
 * stylesheet is tiny and scoped to .sn-audit-* selectors, so loading it on
 * sibling SN tabs is harmless.
 */
add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_hooks' ) || ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	wp_enqueue_style(
		'snt-audit-log',
		plugins_url( 'assets/audit-log.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
} );
