<?php
/**
 * S&N Dashboard — Security → Audit log, painted from the kit.
 *
 * The classic leaf (inc/audit-log-admin.php, `snt_audit_log_render_tab()`)
 * paints a two-column sn_admin_shell: MAIN carries the intro prose, a 4-card
 * glance hero, the 7-column counter timeline and the recent-logins log; RAIL
 * carries the LLA status card, the retention form and the prune-now form plus
 * export links. The window has no shell equivalent, so every reading is
 * painted in one linear column (see the report's `changed` note) — nothing
 * is dropped. Same readers, same two forms, same handlers.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The 4-card glance hero, from the classic leaf's own pure card builder
 * (`snt_audit_log_glance_cards()`, inc/audit-log-admin.php) — reused rather
 * than re-derived, so the trend/lockout logic cannot drift between the two
 * surfaces.
 *
 * @param array<string,mixed> $summary snt_audit_get_summary_impl() result.
 * @return string
 */
function audit_log_glance_html( array $summary ) {
	if ( ! function_exists( 'snt_audit_log_glance_cards' ) ) {
		return '';
	}
	$out = '';
	foreach ( snt_audit_log_glance_cards( $summary ) as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$pill      = isset( $card['pill'] ) && is_array( $card['pill'] ) ? $card['pill'] : null;
		$kind      = null !== $pill ? (string) ( $pill['kind'] ?? '' ) : '';
		$pill_text = null !== $pill ? (string) ( $pill['text'] ?? '' ) : '';
		$caption   = (string) ( $card['meta_html'] ?? '' );
		if ( '' !== $pill_text ) {
			$caption = '' !== $caption ? $caption . ' · ' . $pill_text : $pill_text;
		}
		$out .= \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), $caption, $kind );
	}
	return '<div class="snt-stats">' . $out . '</div>';
}

/**
 * The 30-day counter timeline as a kit table — same rows, same columns.
 *
 * @param array<int,array<string,mixed>> $counters snt_audit_get_counters_impl() result.
 * @return string
 */
function audit_log_counter_table_html( array $counters ) {
	$columns = array(
		array( 'key' => 'date', 'label' => __( 'Date', 'signal-and-noise-tools' ) ),
		array( 'key' => 'login_failed', 'label' => __( 'Failed', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'wp_login_404', 'label' => __( 'Login 404', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'wp_admin_unauth_404', 'label' => __( 'Admin 404', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'lockout_triggered', 'label' => __( 'Lockouts', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'password_reset', 'label' => __( 'Pwd reset', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'unique_ips_count', 'label' => __( 'Unique IPs', 'signal-and-noise-tools' ), 'align' => 'end' ),
	);
	return \snt_kit_section(
		__( 'Counter timeline (last 30 days)', 'signal-and-noise-tools' ),
		\snt_kit_table( $columns, $counters, array( 'empty' => __( 'No counter data yet.', 'signal-and-noise-tools' ) ) )
	);
}

/**
 * The recent successful-logins log: same AL1 display cap (newest-first,
 * SN_AUDIT_LOGIN_DISPLAY_MAX rows), the same "+N more" and store-cap notes,
 * folded into a disclosure the way the classic leaf folds it into <details>.
 *
 * @param array<int,array{ts:int,user:string,formatted:string}> $logins snt_audit_get_login_successes_impl() result.
 * @return string
 */
function audit_log_logins_html( array $logins ) {
	if ( empty( $logins ) ) {
		return \snt_kit_section(
			__( 'Recent successful logins (last 30 days)', 'signal-and-noise-tools' ),
			'<p class="snt-prose">' . \snt_kit_esc( __( 'No successful logins recorded in this window.', 'signal-and-noise-tools' ) ) . '</p>'
		);
	}

	$total  = count( $logins );
	$sorted = $logins;
	usort(
		$sorted,
		static function ( $a, $b ) {
			return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 );
		}
	);
	$cap     = defined( 'SN_AUDIT_LOGIN_DISPLAY_MAX' ) ? SN_AUDIT_LOGIN_DISPLAY_MAX : 50;
	$visible = array_slice( $sorted, 0, $cap );
	$hidden  = $total - count( $visible );

	$body  = \snt_kit_table(
		array(
			array( 'key' => 'formatted', 'label' => __( 'Timestamp', 'signal-and-noise-tools' ) ),
			array( 'key' => 'user', 'label' => __( 'User', 'signal-and-noise-tools' ) ),
		),
		$visible
	);
	if ( $hidden > 0 ) {
		$body .= '<p class="snt-prose">' . \snt_kit_esc(
			sprintf(
				/* translators: %s: number of logins not shown. */
				__( '+%s more logins — the list is capped, not complete. Newest first, so the tail is the oldest end.', 'signal-and-noise-tools' ),
				number_format_i18n( $hidden )
			)
		) . '</p>';
	}
	if ( defined( 'SN_AUDIT_LOGIN_SUCCESS_CAP' ) ) {
		$body .= '<p class="snt-prose">' . \snt_kit_esc(
			sprintf(
				/* translators: %s: the storage cap on retained login rows. */
				__( 'The store keeps at most %s successful logins, dropping the oldest first, so a long-enough gap is absence of a record rather than absence of a login.', 'signal-and-noise-tools' ),
				number_format_i18n( SN_AUDIT_LOGIN_SUCCESS_CAP )
			)
		) . '</p>';
	}

	$disclosure = \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => sprintf(
				/* translators: %s: the true number of successful logins in the window. */
				_n( '%s successful login', '%s successful logins', $total, 'signal-and-noise-tools' ),
				number_format_i18n( $total )
			),
			'hint'    => __( 'show the log', 'signal-and-noise-tools' ),
		),
		$body
	);
	return \snt_kit_section( __( 'Recent successful logins (last 30 days)', 'signal-and-noise-tools' ), $disclosure );
}

/**
 * The LLA status card: active lockouts + most recent, with a door to the
 * plugin's own settings screen (a foreign admin page, never a raw <a>).
 *
 * @param array{active_lockouts:int,most_recent_lockout_ts:int|null} $lla From snt_audit_get_summary_impl()['lla'].
 * @return string
 */
function audit_log_lla_html( array $lla ) {
	$recent = $lla['most_recent_lockout_ts']
		? wp_date( 'Y-m-d H:i:s', (int) $lla['most_recent_lockout_ts'] )
		: __( 'never', 'signal-and-noise-tools' );
	$body = '<p class="snt-prose">' . \snt_kit_esc( __( 'limit-login-attempts-reloaded', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_kv(
			array(
				array( 'label' => __( 'Active lockouts', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) $lla['active_lockouts'] ) ),
				array( 'label' => __( 'Most recent lockout', 'signal-and-noise-tools' ), 'value' => (string) $recent ),
			)
		)
		. \snt_kit_door( __( 'Manage in LLA', 'signal-and-noise-tools' ) . ' →', admin_url( 'admin.php?page=limit-login-attempts' ) );
	return \snt_kit_section( __( 'Audit status and maintenance', 'signal-and-noise-tools' ), $body );
}

/**
 * The retention-days form. Same field, same handler, same PRG pipeline.
 *
 * @return string
 */
function audit_log_retention_form_html() {
	$retention = (int) sn_setting( 'audit.retention_days', 90 );
	$field     = \snt_kit_field(
		'number',
		'audit_retention_days',
		__( 'Retention (days)', 'signal-and-noise-tools' ),
		$retention,
		array(
			'min'  => 7,
			'max'  => 365,
			'hint' => __( 'How long to keep counter buckets and login_success rows. Range 7-365. Daily cron prune enforces this.', 'signal-and-noise-tools' ),
		)
	);
	return \snt_kit_section(
		__( 'Retention', 'signal-and-noise-tools' ),
		\snt_kit_form( 'audit_save_retention', $field, array( 'submit' => __( 'Save retention', 'signal-and-noise-tools' ) ) )
	);
}

/**
 * The "Prune now" form (handled inline, no PRG — see the port map) plus the
 * CSV/JSON export links, which are nonce'd admin-post.php GETs, not a form.
 *
 * @return string
 */
function audit_log_maintenance_html() {
	$retention_days = (int) sn_setting( 'audit.retention_days', 90 );
	$form           = \snt_kit_form(
		'audit_prune_now',
		'<p class="snt-prose">' . \snt_kit_esc(
			sprintf(
				/* translators: %s: retention days. */
				__( 'Manually run the daily prune now. Drops counter buckets and login_success rows older than %s days, plus polls LLA for new lockouts.', 'signal-and-noise-tools' ),
				$retention_days
			)
		) . '</p>',
		array( 'submit' => __( 'Prune now', 'signal-and-noise-tools' ), 'pipeline' => 'inline' )
	);

	$export_json_url = wp_nonce_url( admin_url( 'admin-post.php?action=sn_audit_export&format=json' ), 'sn_audit_export', 'sn_audit_export_nonce' );
	$export_csv_url  = wp_nonce_url( admin_url( 'admin-post.php?action=sn_audit_export&format=csv' ), 'sn_audit_export', 'sn_audit_export_nonce' );
	$exports         = '<p class="snt-prose">' . \snt_kit_esc( __( 'Download the audit log (per-day counters + successful-login rows over the retention window). The export contains plaintext usernames.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<os-cluster gap="8">' . \snt_kit_door( __( 'Export JSON', 'signal-and-noise-tools' ), $export_json_url ) . \snt_kit_door( __( 'Export CSV', 'signal-and-noise-tools' ), $export_csv_url ) . '</os-cluster>';

	return \snt_kit_section( __( 'Maintenance', 'signal-and-noise-tools' ), $form . $exports );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_security_audit_log( array $ctx ) {
	if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
		return \snt_kit_empty( __( 'The audit log is not available.', 'signal-and-noise-tools' ) );
	}
	$state = $ctx['state'] ?? null;
	$post  = is_object( $state ) && method_exists( $state, 'get' ) ? (array) $state->get( 'post' ) : array();
	$out   = '';

	// Consume the posted values immediately, as the fallback frame does
	// (apps/sn-dashboard/parts/frame.php) — the state contract promises ONE
	// paint's $_POST, and the inline pipeline gives no other signal that this
	// paint already handled it. Without this a repaint (e.g. a refresh) would
	// re-run the destructive prune every time.
	if ( array() !== $post && is_object( $state ) && method_exists( $state, 'set' ) ) {
		$state->set( 'post', array() );
	}

	// The "Prune now" action is handled INLINE by the leaf itself, mirroring
	// the classic renderer's own pre-shell handling — the shared PRG never
	// sees `audit_prune_now` (see the port map).
	if ( isset( $post['sn_action'] ) && 'audit_prune_now' === $post['sn_action'] && function_exists( 'snt_audit_prune_impl' ) ) {
		$stats = snt_audit_prune_impl();
		$out  .= \snt_kit_notice(
			'ok',
			'<b>' . \snt_kit_esc( __( 'Prune complete.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc(
				sprintf(
					/* translators: 1: counter buckets dropped, 2: login rows dropped, 3: LLA delta. */
					__( '%1$d counter bucket(s) dropped, %2$d login row(s) dropped, LLA delta +%3$d.', 'signal-and-noise-tools' ),
					(int) $stats['counter_buckets_dropped'],
					(int) $stats['login_rows_dropped'],
					(int) $stats['lla_delta']
				)
			)
		);
	}

	$summary  = snt_audit_get_summary_impl();
	$counters = snt_audit_get_counters_impl( 30 );
	$logins   = snt_audit_get_login_successes_impl( 30 );

	$retention_intro = (int) sn_setting( 'audit.retention_days', 90 );
	$out .= '<p class="snt-prose">' . \snt_kit_esc(
		sprintf(
			/* translators: %d: retention days. */
			__( 'Captures login-related events (successful logins, failed attempts, our /wp-login.php and unauth /wp-admin reconnaissance 404s, password resets, LLA lockouts). %d-day retention. Hashed-IP unique-attacker count via ephemeral transient: no raw or hashed IPs are stored long-term.', 'signal-and-noise-tools' ),
			$retention_intro
		)
	) . '</p>';
	$out .= audit_log_glance_html( $summary );
	$out .= audit_log_counter_table_html( $counters );
	$out .= audit_log_logins_html( $logins );
	$out .= audit_log_lla_html( (array) $summary['lla'] );
	$out .= audit_log_retention_form_html();
	$out .= audit_log_maintenance_html();
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['security/audit-log'] = __NAMESPACE__ . '\\paint_security_audit_log';
		return $painters;
	}
);
