<?php
/**
 * Signal & Noise Tools: account and site setting changes in the audit log.
 *
 * 2026-10-18: nothing recorded who made an account, granted a role, changed
 * an account's email, opened registration, changed the default role or the
 * site admin email, or activated, deactivated or deleted a plugin or switched
 * the theme. These rows ride the existing `sn_audit_log_v1` blob as a bounded
 * list, shaped like `login_success`: capped at SN_AUDIT_CHANGES_CAP (oldest
 * dropped) and pruned by the retention window. Each row is a time, a kind, the
 * signed-in username that made it, the account or item it touched, and a
 * short detail (role names, a theme name). No IP address, no email address,
 * no password. A row that grants administrator access is marked `high`.
 *
 * The hooks live in inc/audit-log-changes-hooks.php (accounts) and
 * inc/audit-log-changes-site.php (settings, plugins, theme).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AUDIT_CHANGES_CAP = 200;

require_once __DIR__ . '/audit-log-changes-hooks.php';
require_once __DIR__ . '/audit-log-changes-site.php';

/**
 * Append one change. Observes only: it never blocks or alters the change.
 *
 * @param string $kind    One of the snt_audit_change_label() kinds.
 * @param string $subject The account login, plugin file, theme name or setting.
 * @param string $detail  Role names or a short qualifier; never an address.
 * @param bool   $high    True when the change grants administrator access.
 * @param ?string $actor  Who made it, when the caller knows. Null asks
 *                        wp_get_current_user(), which a caller inside
 *                        determine_current_user must never do (it recurses).
 */
function snt_audit_record_change_impl( $kind, $subject, $detail = '', $high = false, $actor = null ) {
	if ( null === $actor ) {
		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$actor = is_object( $user ) && ! empty( $user->ID ) ? (string) $user->user_login : '';
	}
	$blob = snt_audit_get_blob();
	$rows = isset( $blob['changes'] ) && is_array( $blob['changes'] ) ? $blob['changes'] : array();

	$rows[] = array(
		'ts'      => time(),
		'kind'    => (string) $kind,
		'actor'   => (string) $actor,
		'subject' => (string) $subject,
		'detail'  => (string) $detail,
		'high'    => (bool) $high,
	);
	$blob['changes'] = array_slice( $rows, -SN_AUDIT_CHANGES_CAP );
	update_option( SN_AUDIT_OPTION, $blob, true );
}

/**
 * Is this row a change? A refused app-password sign-in rides the same list
 * (inc/audit-log-app-passwords.php) but changed nothing; every count of
 * "changes" (the admin finding on both twins, the digest) goes through here.
 *
 * @param array $row A changes row.
 * @return bool
 */
function snt_audit_is_change_row( $row ) {
	return is_array( $row ) && 'app_password_failed' !== ( $row['kind'] ?? '' );
}

/**
 * The last N days of changes, newest first.
 *
 * @param int $days Trailing days, clamped to the retention window.
 * @return array<int,array{ts:int,kind:string,actor:string,subject:string,detail:string,high:bool,formatted:string}>
 */
function snt_audit_get_changes_impl( $days = 30 ) {
	$days   = max( 1, min( (int) sn_setting( 'audit.retention_days', 90 ), (int) $days ) );
	$cutoff = time() - $days * DAY_IN_SECONDS;
	$out    = array();
	foreach ( (array) ( snt_audit_get_blob()['changes'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) || (int) ( $row['ts'] ?? 0 ) < $cutoff ) {
			continue;
		}
		$out[] = array(
			'ts'        => (int) $row['ts'],
			'kind'      => (string) ( $row['kind'] ?? '' ),
			'actor'     => (string) ( $row['actor'] ?? '' ),
			'subject'   => (string) ( $row['subject'] ?? '' ),
			'detail'    => (string) ( $row['detail'] ?? '' ),
			'high'      => ! empty( $row['high'] ),
			'formatted' => wp_date( 'Y-m-d H:i:s', (int) $row['ts'] ),
		);
	}
	usort(
		$out,
		static function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		}
	);
	return $out;
}

/**
 * Drop changes older than the cutoff. Used by snt_audit_prune_impl().
 *
 * @param array $blob   The audit blob.
 * @param int   $cutoff Unix time; older rows go.
 * @return array{0:array,1:int} The pruned blob and how many rows were dropped.
 */
function snt_audit_prune_changes( array $blob, $cutoff ) {
	$rows = isset( $blob['changes'] ) && is_array( $blob['changes'] ) ? $blob['changes'] : array();
	$kept = array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $cutoff ) {
				return is_array( $row ) && (int) ( $row['ts'] ?? 0 ) >= $cutoff;
			}
		)
	);
	$blob['changes'] = $kept;
	return array( $blob, count( $rows ) - count( $kept ) );
}

/**
 * A change kind in words, the same on both twins, the export and the digest.
 *
 * @param string $kind Stored kind.
 * @return string
 */
function snt_audit_change_label( $kind ) {
	$labels = array(
		'user_created'       => __( 'Account created', 'signal-and-noise-tools' ),
		'role_set'           => __( 'Role changed', 'signal-and-noise-tools' ),
		'role_added'         => __( 'Role added', 'signal-and-noise-tools' ),
		'role_removed'       => __( 'Role removed', 'signal-and-noise-tools' ),
		'email_changed'      => __( 'Account email changed', 'signal-and-noise-tools' ),
		'registration_on'    => __( 'Anyone can register: turned on', 'signal-and-noise-tools' ),
		'registration_off'   => __( 'Anyone can register: turned off', 'signal-and-noise-tools' ),
		'default_role'       => __( 'Default role for new accounts changed', 'signal-and-noise-tools' ),
		'admin_email'        => __( 'Site admin email changed', 'signal-and-noise-tools' ),
		'plugin_activated'   => __( 'Plugin activated', 'signal-and-noise-tools' ),
		'plugin_deactivated' => __( 'Plugin deactivated', 'signal-and-noise-tools' ),
		'plugin_deleted'     => __( 'Plugin deleted', 'signal-and-noise-tools' ),
		'theme_switched'     => __( 'Theme switched', 'signal-and-noise-tools' ),
		'app_password_failed'  => __( 'App password sign-in refused (first of this reason today)', 'signal-and-noise-tools' ),
		'app_password_created' => __( 'App password created', 'signal-and-noise-tools' ),
		'app_password_updated' => __( 'App password renamed', 'signal-and-noise-tools' ),
		'app_password_revoked' => __( 'App password revoked', 'signal-and-noise-tools' ),
	);
	return $labels[ $kind ] ?? (string) $kind;
}
