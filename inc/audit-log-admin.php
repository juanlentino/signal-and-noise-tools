<?php
/**
 * Signal & Noise Tools — Audit Log admin sub-tab renderer.
 *
 * Renders the Security → Audit log sub-tab. Layout matches the Dashboard
 * tab pattern (4-card hero grid + tables below).
 *
 * Wired by inc/admin-page.php's security tab dispatch arm:
 *     elseif ( 'audit-log' === $active_sub ) {
 *         sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' );
 *     }
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

	$retention_intro = (int) sn_setting( 'audit.retention_days', 90 );
	echo '<p class="sn-prose">Captures login-related events (successful logins, failed attempts, our /wp-login.php and unauth /wp-admin reconnaissance 404s, password resets, LLA lockouts). ' . esc_html( $retention_intro ) . '-day retention. Hashed-IP unique-attacker count via ephemeral transient — no raw or hashed IPs are stored long-term.</p>';

	// 1. Hero stat-cards.
	snt_audit_log_render_hero( $summary );

	// 2. Counter timeline table.
	snt_audit_log_render_counter_table( $counters );

	// 3. Recent successful logins.
	snt_audit_log_render_logins_table( $logins );

	// 4. LLA summary card (deep-link to LLA settings).
	snt_audit_log_render_lla_card( $summary['lla'] );

	// 5. Retention setting (v4.2.0).
	snt_audit_log_render_retention_form();

	// 6. Maintenance — Prune now button.
	snt_audit_log_render_prune_form();
}

/**
 * Render the 4-card hero grid.
 */
function snt_audit_log_render_hero( $summary ) {
	$delta_class = '';
	$delta_sign  = '';
	if ( $summary['last_7d_vs_prior']['pct_delta'] > 0 ) {
		$delta_class = 'sn-trend-up';
		$delta_sign  = '+';
	} elseif ( $summary['last_7d_vs_prior']['pct_delta'] < 0 ) {
		$delta_class = 'sn-trend-down';
	}

	echo '<div class="sn-audit-state-grid">';

	// Card 1: Last 24h.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">Last 24h</span>';
	echo '<span class="sn-audit-card-value">' . (int) $summary['last_24h']['all_total'] . '</span>';
	echo '<span class="sn-audit-card-sub">' . (int) $summary['last_24h']['failed_total'] . ' failed · ' . (int) $summary['last_24h']['recon_total'] . ' recon</span>';
	echo '</div>';

	// Card 2: 7d vs prior 7d.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">Last 7d trend</span>';
	echo '<span class="sn-audit-card-value ' . esc_attr( $delta_class ) . '">' . esc_html( $delta_sign . $summary['last_7d_vs_prior']['pct_delta'] . '%' ) . '</span>';
	echo '<span class="sn-audit-card-sub">' . (int) $summary['last_7d_vs_prior']['current'] . ' vs ' . (int) $summary['last_7d_vs_prior']['prior'] . '</span>';
	echo '</div>';

	// Card 3: Unique attackers 24h.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">Unique IPs (24h)</span>';
	echo '<span class="sn-audit-card-value">' . (int) $summary['unique_attackers_24h'] . '</span>';
	echo '<span class="sn-audit-card-sub">hashed, not stored</span>';
	echo '</div>';

	// Card 4: LLA status.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">LLA status</span>';
	echo '<span class="sn-audit-card-value">' . (int) $summary['lla']['active_lockouts'] . '</span>';
	echo '<span class="sn-audit-card-sub">active lockouts</span>';
	echo '</div>';

	echo '</div>';
}

/**
 * Render the day-bucketed counter timeline table.
 */
function snt_audit_log_render_counter_table( $counters ) {
	echo '<h2 class="sn-fieldset-h">Counter timeline (last 30 days)</h2>';
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
	echo '</div>';
}

/**
 * Render the recent successful logins table.
 */
function snt_audit_log_render_logins_table( $logins ) {
	echo '<h2 class="sn-fieldset-h">Recent successful logins (last 30 days)</h2>';
	if ( empty( $logins ) ) {
		echo '<p class="sn-prose">No successful logins recorded in this window.</p>';
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
	echo '</div>';
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
}

/**
 * Render the retention-days form on the Audit log tab. Mirrors the
 * RSS tracker retention pattern (inc/rss-plausible-tracker.php:493).
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
