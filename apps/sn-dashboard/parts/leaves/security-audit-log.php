<?php
/**
 * S&N Dashboard — Security → Audit log, painted from the kit.
 *
 * The classic leaf (inc/audit-log-admin.php, `snt_audit_log_render_tab()`)
 * paints a two-column sn_admin_shell: MAIN carries the intro prose, a 4-card
 * glance hero, the 7-column counter timeline and the recent-logins log; RAIL
 * carries the guard lockouts card, the retention form and the prune-now form plus
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

require_once __DIR__ . '/security-audit-log-logins.php';
require_once __DIR__ . '/security-audit-log-changes.php';

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
	$out = array();
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
		$out[] = \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), $caption, $kind );
	}
	return \snt_kit_grid( $out, 160, 10 );
}

/**
 * The 30-day counter timeline as a kit table — same rows, same columns.
 *
 * @param array<int,array<string,mixed>> $counters snt_audit_get_counters_impl() result.
 * @return string
 */
function audit_log_counter_table_html( array $counters ) {
	foreach ( $counters as &$row ) {
		$row['mfa_detail'] = (int) ( $row['mfa_failed'] ?? 0 ) . ' / ' . (int) ( $row['mfa_throttled'] ?? 0 ) . ' / ' . (int) ( $row['mfa_other'] ?? 0 );
		unset( $row['lockout_triggered'] ); // retired 2026-10-18; old rows keep it in the export only.
	}
	unset( $row );
	$columns = array(
		array( 'key' => 'date', 'label' => __( 'Date', 'signal-and-noise-tools' ) ),
		array( 'key' => 'login_failed', 'label' => __( 'Failed', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'mfa_detail', 'label' => __( 'MFA: rejected / limited / other', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'wp_login_404', 'label' => __( 'Login 404', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'wp_admin_unauth_404', 'label' => __( 'Admin 404', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'password_reset', 'label' => __( 'Pwd reset', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'unique_ips_count', 'label' => __( 'Unique IPs', 'signal-and-noise-tools' ), 'align' => 'end' ),
	);
	return \snt_kit_section(
		__( 'Counter timeline (last 30 days)', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( __( 'MFA details are included in Failed, not additional events. Older failures are unclassified; a rejected factor does not by itself prove an attack.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_table( $columns, $counters, array( 'empty' => __( 'No counter data yet.', 'signal-and-noise-tools' ) ) )
	);
}

/**
 * The lockouts box: the login guard's last 7 days (2026-10-18: its own
 * lockouts and throttles replace the retired lockout plugin), the same rows
 * as the classic rail, and a go to Security › Login defense in this window.
 * In the row with Retention and Maintenance (2026-10-12 audit).
 *
 * @param array<string,mixed> $guard From snt_audit_get_summary_impl()['guard'].
 * @return string
 */
function audit_log_guard_html( array $guard ) {
	$body = \snt_kit_kv( function_exists( 'snt_audit_log_guard_rows' ) ? snt_audit_log_guard_rows( $guard ) : array() )
		. \snt_kit_go( __( 'Open Login defense', 'signal-and-noise-tools' ) . ' →', array( 'tab' => 'security', 'sub' => 'login-defense', 'current' => 'security' ) );
	return \snt_kit_section( __( 'Login lockouts (login guard)', 'signal-and-noise-tools' ), $body );
}

/**
 * The Setup card: the retention-days form as a fold whose closed summary is
 * the window (docs/kit-leaf-anatomy.md: a setting changed once in a while).
 * Same field, same handler, same PRG pipeline.
 *
 * @return string
 */
function audit_log_setup_html() {
	$retention = (int) sn_setting( 'audit.retention_days', 90 );
	$field     = \snt_kit_field(
		'number',
		'audit_retention_days',
		__( 'Retention (days)', 'signal-and-noise-tools' ),
		$retention,
		array(
			'min'  => 7,
			'max'  => 365,
			'hint' => __( 'How long to keep the daily counts, the successful-login records and the change records, 7 to 365 days. A daily cleanup deletes anything older.', 'signal-and-noise-tools' ),
		)
	);
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array(
			array(
				'title'   => __( 'Retention', 'signal-and-noise-tools' ),
				/* translators: %s: retention days. */
				'summary' => sprintf( _n( '%s day', '%s days', $retention, 'signal-and-noise-tools' ), number_format_i18n( $retention ) ),
				'body'    => \snt_kit_form( 'audit_save_retention', $field, array( 'submit' => __( 'Save retention', 'signal-and-noise-tools' ) ) ),
			),
		)
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
	// 2026-10-16: the lone Prune now is the kit's compact one-button form, its
	// sentence above it rather than inside a full-width form row.
	$form           = '<p class="snt-prose">' . \snt_kit_esc( snt_audit_prune_lead_text( $retention_days ) ) . '</p>'
		. \snt_kit_form(
			'audit_prune_now',
			'',
			array(
				'submit'   => __( 'Prune now', 'signal-and-noise-tools' ),
				'pipeline' => 'inline',
				// 2026-10-12 audit: it deletes rows, and asked nothing first on either twin.
				'confirm'  => function_exists( 'snt_audit_prune_confirm_text' ) ? snt_audit_prune_confirm_text( $retention_days ) : null,
				'danger'   => true,
				'inline'   => true,
			)
		);

	$export_json_url = wp_nonce_url( admin_url( 'admin-post.php?action=sn_audit_export&format=json' ), 'sn_audit_export', 'sn_audit_export_nonce' );
	$export_csv_url  = wp_nonce_url( admin_url( 'admin-post.php?action=sn_audit_export&format=csv' ), 'sn_audit_export', 'sn_audit_export_nonce' );
	$exports         = '<p class="snt-prose">' . \snt_kit_esc( __( 'Download the audit log (per-day counters, successful-login rows and account and site changes over the retention window). The export contains plaintext usernames.', 'signal-and-noise-tools' ) ) . '</p>'
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

	// Spend the one-paint post bag even when the audit readers are unavailable.
	// Otherwise a later repaint after they become available could execute a
	// destructive inline prune without a new submission.
	if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
		return \snt_kit_empty( __( 'The audit log is not available.', 'signal-and-noise-tools' ) );
	}

	// The "Prune now" action is handled INLINE by the leaf itself, mirroring
	// the classic renderer's own pre-shell handling — the shared PRG never
	// sees `audit_prune_now` (see the port map).
	if ( isset( $post['sn_action'] ) && 'audit_prune_now' === $post['sn_action'] && function_exists( 'snt_audit_prune_impl' ) ) {
		$stats = snt_audit_prune_impl();
		$out  .= \snt_kit_notice(
			'ok',
			'<b>' . \snt_kit_esc( __( 'Prune complete.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( snt_audit_prune_notice_text( $stats ) )
		);
	}

	$summary  = snt_audit_get_summary_impl();
	$counters = snt_audit_get_counters_impl( 30 );
	$logins   = snt_audit_get_login_successes_impl( 30 );

	$retention_intro = (int) sn_setting( 'audit.retention_days', 90 );
	$out .= '<p class="snt-prose">' . \snt_kit_esc( snt_audit_log_intro_text( $retention_intro ) ) . '</p>';
	$out .= audit_log_glance_html( $summary );
	$out .= audit_log_counter_table_html( $counters );
	// The counter timeline keeps the full width (eight columns), and so does
	// the open logins ledger (up to 50 rows). 2026-10-12 audit: the lockouts
	// box stood alone at full width with two rows in it; it shares one row
	// with the two controls, three short boxes of a height.
	$out .= audit_log_logins_html( $logins );
	$out .= audit_log_changes_html( function_exists( 'snt_audit_get_changes_impl' ) ? snt_audit_get_changes_impl( 30 ) : array() );
	$out .= \snt_kit_grid( array( audit_log_guard_html( (array) ( $summary['guard'] ?? array() ) ), audit_log_setup_html(), audit_log_maintenance_html() ), 260 );
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['security/audit-log'] = __NAMESPACE__ . '\\paint_security_audit_log';
		return $painters;
	}
);
