<?php
/**
 * Signal & Noise Tools — GDPR personal-data exporter + eraser.
 *
 * Wires the plugin into WordPress's core privacy tooling (Tools → Export /
 * Erase Personal Data) so a user's persisted PII can be exported and erased
 * on request.
 *
 * SCOPE — what counts as personal data here:
 *   The ONLY persisted per-person PII the plugin stores is the plaintext
 *   login username on each successful-login row in the audit log option
 *   `sn_audit_log_v1` (SN_AUDIT_OPTION) → `login_success[] = { ts, user }`
 *   (see inc/audit-log.php::snt_audit_record_login_success_impl()).
 *
 *   Explicitly OUT of scope (no per-person PII, so nothing to export/erase):
 *   - Aggregate day-bucketed counters (login_failed / wp_login_404 / etc.) —
 *     pure tallies, not attributable to an individual.
 *   - The ephemeral unique-IP set: raw IPs are never stored; only salted
 *     one-way SHA-256 fragments in a 25h transient (SN_AUDIT_TRANSIENT_IPS),
 *     which age out on their own and cannot be reversed to an email/user.
 *
 * LIMITATION — orphaned rows for deleted users:
 *   Rows store the `user_login` string as-captured. WP's privacy requests key
 *   off an email address, which we resolve to a user via get_user_by('email').
 *   If the WP user account has since been deleted, the email no longer
 *   resolves, so those orphaned rows can't be matched by an export/erase
 *   request — they age out only via the 90-day retention prune
 *   (snt_audit_prune_impl). This is an accepted, documented limitation: the
 *   audit log intentionally preserves the username even after account
 *   deletion, and the rows carry no email to match against.
 *
 * @package SignalNoiseTools
 * @since 4.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ════════════════════════════════════════════════════════════════════════
 * Shared matcher.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Split an audit blob's login_success rows into those belonging to a given
 * user_login and those that don't.
 *
 * @param array  $blob       The SN_AUDIT_OPTION blob (or any array; tolerant of a missing login_success key).
 * @param string $user_login The username to match (exact, case-sensitive — usernames are stored verbatim).
 * @return array{0:array,1:array} [ $matched_rows, $remaining_rows ] (both re-indexed).
 */
function sn_privacy_match_login_rows( $blob, $user_login ) {
	$rows      = ( is_array( $blob ) && isset( $blob['login_success'] ) && is_array( $blob['login_success'] ) )
		? $blob['login_success']
		: array();
	$matched   = array();
	$remaining = array();

	foreach ( $rows as $row ) {
		if ( isset( $row['user'] ) && (string) $row['user'] === (string) $user_login ) {
			$matched[] = $row;
		} else {
			$remaining[] = $row;
		}
	}

	return array( array_values( $matched ), array_values( $remaining ) );
}

/* ════════════════════════════════════════════════════════════════════════
 * Exporter.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Register the plugin's personal-data exporter.
 *
 * @param array $exporters Existing registered exporters.
 * @return array
 */
function sn_privacy_register_exporter( $exporters ) {
	$exporters['signal-noise-tools'] = array(
		'exporter_friendly_name' => __( 'Signal & Noise Tools — login audit', 'signal-noise-tools' ),
		'callback'               => 'sn_privacy_export_login_audit',
	);
	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'sn_privacy_register_exporter' );

/**
 * Export the login-audit rows for the user with the given email.
 *
 * One export item per matched successful-login row. All rows fit on a single
 * page: the audit log caps login_success at SN_AUDIT_LOGIN_SUCCESS_CAP (500),
 * so `done` is always true.
 *
 * @param string $email Email address of the data subject.
 * @param int    $page  Page number (begins at 1). Unused — single page.
 * @return array{data:array,done:bool}
 */
function sn_privacy_export_login_audit( $email, $page = 1 ) {
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$blob = get_option( SN_AUDIT_OPTION, array() );
	list( $matched ) = sn_privacy_match_login_rows( $blob, $user->user_login );

	$data = array();
	foreach ( $matched as $i => $row ) {
		$ts   = isset( $row['ts'] ) ? (int) $row['ts'] : 0;
		$data[] = array(
			'group_id'    => 'sn-login-audit',
			'group_label' => __( 'Signal & Noise login audit', 'signal-noise-tools' ),
			'item_id'     => 'sn-login-audit-' . $i,
			'data'        => array(
				array(
					'name'  => __( 'Login timestamp', 'signal-noise-tools' ),
					'value' => wp_date( 'Y-m-d H:i:s', $ts ),
				),
				array(
					'name'  => __( 'Username', 'signal-noise-tools' ),
					'value' => isset( $row['user'] ) ? (string) $row['user'] : '',
				),
			),
		);
	}

	return array(
		'data' => $data,
		'done' => true,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * Eraser.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Register the plugin's personal-data eraser.
 *
 * @param array $erasers Existing registered erasers.
 * @return array
 */
function sn_privacy_register_eraser( $erasers ) {
	$erasers['signal-noise-tools'] = array(
		'eraser_friendly_name' => __( 'Signal & Noise Tools — login audit', 'signal-noise-tools' ),
		'callback'             => 'sn_privacy_erase_login_audit',
	);
	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'sn_privacy_register_eraser' );

/**
 * Erase the login-audit rows for the user with the given email.
 *
 * Removes every successful-login row whose stored username matches the
 * resolved user_login, persisting the trimmed blob (other subtrees — counters,
 * schema_version — left untouched).
 *
 * @param string $email Email address of the data subject.
 * @param int    $page  Page number (begins at 1). Unused — single page.
 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
 */
function sn_privacy_erase_login_audit( $email, $page = 1 ) {
	$messages = array();
	$user     = get_user_by( 'email', $email );

	if ( ! $user ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	$blob = get_option( SN_AUDIT_OPTION, array() );
	list( $matched, $remaining ) = sn_privacy_match_login_rows( $blob, $user->user_login );

	$removed = ! empty( $matched );
	if ( $removed ) {
		if ( ! is_array( $blob ) ) {
			$blob = array();
		}
		$blob['login_success'] = $remaining;
		update_option( SN_AUDIT_OPTION, $blob, true );
		$messages[] = sprintf(
			/* translators: %d: number of login-audit rows removed. */
			__( 'Removed %d Signal & Noise login-audit record(s).', 'signal-noise-tools' ),
			count( $matched )
		);
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => $messages,
		'done'           => true,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * Suggested Privacy Policy content.
 *
 * Surfaced in wp-admin under Settings → Privacy → "Privacy Policy Guide" /
 * the Suggested Privacy Policy Content postbox when editing the policy page.
 * Describes the privacy-relevant data this plugin actually handles so the
 * site owner can fold accurate copy into their published policy.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Register suggested Privacy Policy text describing the plugin's data handling.
 *
 * Guarded by function_exists so the plugin never fatals on a WP build where
 * wp_add_privacy_policy_content() is unavailable.
 *
 * The webhook sentence is included ONLY when at least one webhook is
 * configured — a site with no webhooks sends nothing to third parties, so the
 * copy stays accurate.
 */
function sn_register_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$retention = (int) sn_setting( 'audit.retention_days', 90 );

	$sentences = array();

	// (a) Login audit.
	$sentences[] = sprintf(
		/* translators: %s: audit-log retention in days. */
		__( 'This site keeps a security audit log of successful sign-ins, recording the timestamp and account username for up to %s days, after which entries are automatically deleted.', 'signal-noise-tools' ),
		esc_html( (string) $retention )
	);
	$sentences[] = __( 'It also keeps daily aggregate counts of failed sign-in attempts and not-found login/admin requests. These counts are not tied to any individual.', 'signal-noise-tools' );
	$sentences[] = __( 'To estimate unique sources of suspicious activity, IP addresses are converted to salted, one-way hashes that expire within 25 hours; raw IP addresses are never stored long-term and the hashes cannot be reversed.', 'signal-noise-tools' );

	// (b) Webhooks — only when at least one is configured.
	$webhooks = function_exists( 'sn_webhooks_all' ) ? sn_webhooks_all() : array();
	if ( ! empty( $webhooks ) ) {
		$sentences[] = __( 'When content is published, this site may send a signed webhook notification — including the post title, URL, author, and time — to the third-party endpoints you have configured.', 'signal-noise-tools' );
	}

	// (c) First-party analytics.
	$sentences[] = __( 'This site uses its own cookieless analytics to collect aggregate usage statistics. It stores no personal data and performs no cross-site tracking.', 'signal-noise-tools' );

	$content = implode( "\n\n", $sentences );

	wp_add_privacy_policy_content(
		'Signal & Noise Tools',
		wp_kses_post( wpautop( $content ) )
	);
}
add_action( 'admin_init', 'sn_register_privacy_policy_content' );
