<?php
/**
 * Signal & Noise — admin flash-message registry.
 *
 * Single source of truth for the ?sn_flash=… → admin-notice translation.
 * Before v4.5.4 this lived as a second if/elseif inside sn_theme_options_page(),
 * maintained ~40 lines away from the dispatcher that emits the codes — the two
 * had to be hand-kept in sync. Now the dispatcher emits a code and this module
 * owns the code → [severity, message] mapping. Three message shapes:
 *   1. exact-match static codes       → sn_admin_flash_messages()
 *   2. count/id-prefixed codes         → parsed in the resolver
 *   3. live-data codes (login/pl_test) → computed from current state
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static flash code → [ severity, message-html ] map (exact-match codes only).
 *
 * Messages may contain inline markup (<a>, <code>, <strong>, HTML entities) —
 * the renderer runs them through wp_kses_post, so do NOT escape here.
 *
 * @return array<string,array{0:string,1:string}>
 */
function sn_admin_flash_messages() {
	return array(
		'identity_saved'            => array( 'success', 'Identity settings saved.' ),
		'identity_unchanged'        => array( 'info', 'No changes to save.' ),
		'login_empty'               => array( 'error', 'Login slug cannot be empty.' ),
		'login_failed'              => array( 'error', 'Login slug save failed.' ),
		'pl_saved'                  => array( 'success', 'Stats API key saved. Caches purged — widgets refresh on next dashboard view.' ),
		'pl_cleared'                => array( 'success', 'Stats API key cleared. Caches purged.' ),
		'pl_unchanged'              => array( 'info', 'No changes to save.' ),
		'pl_locked'                 => array( 'error', 'Token is locked by the SN_PLAUSIBLE_STATS_TOKEN constant — remove the constant in wp-config.php to edit here.' ),
		'pl_test_unconfigured'      => array( 'error', 'Plausible not fully configured (missing domain or token).' ),
		'cf_saved'                  => array( 'success', 'Cloudflare settings saved.' ),
		'cf_purged_ok'              => array( 'success', 'Cloudflare zone purge dispatched.' ),
		'cf_purged_unconfigured'    => array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' ),
		'purged'                    => array( 'success', 'All caches purged.' ),
		'wh_updated'                => array( 'success', 'Webhook updated.' ),
		'wh_deleted'                => array( 'success', 'Webhook deleted. Pending retries (if any) will drop on next dispatch.' ),
		'wh_invalid'                => array( 'error', 'Could not add webhook — name and valid URL are required.' ),
		'wh_not_found'              => array( 'error', 'Webhook not found.' ),
		'insights_scanned'          => array( 'success', 'Insights scan complete — recommendations below.' ),
		'insights_failed'           => array( 'error', 'Insights scan failed. Check that an AI provider is configured under Settings → Connectors.' ),
		'insights_dismissed'        => array( 'success', 'Recommendation dismissed.' ),
		'insights_snoozed'          => array( 'success', 'Recommendation snoozed for 30 days.' ),
		'insights_done'             => array( 'success', 'Recommendation marked as done.' ),
		'insights_settings_saved'   => array( 'success', 'Insights settings saved.' ),
		'insights_draft_stale'      => array( 'error', 'That recommendation is no longer in the latest scan. Run a fresh scan and try again.' ),
		'insights_draft_failed'     => array( 'error', 'Could not create the draft. Check that the Notes content surfaces are seeded, then try again.' ),
		'health_scanned'            => array( 'success', 'Scan complete — findings below.' ),
		'pattern_adoption_scanned'  => array( 'success', 'Scan complete.' ),
		'block_migrations_scanned'  => array( 'success', 'Block migration scan complete.' ),
		'audit_retention_saved'     => array( 'success', 'Audit retention saved.' ),
		'audit_retention_unchanged' => array( 'info', 'Audit retention unchanged.' ),
		'monitoring_saved'          => array( 'success', 'Uptime monitoring settings saved.' ),
		'monitoring_url_not_https'  => array( 'error', 'Uptime Kuma push URL must start with <code>https://</code> — the setting was cleared. Re-enter a secure URL.' ),
		'perf_saved'                => array( 'success', 'Performance settings saved.' ),
		'release_notes_drafted'     => array( 'success', 'Release notes drafted &mdash; copy them from the box below.' ),
		'release_notes_failed'      => array( 'error', 'Could not draft release notes. See the detail below, or check that an AI provider is configured under Settings &rarr; Connectors.' ),
	);
}

/**
 * Resolve a flash code to a [ severity, message-html ] notice, or null when the
 * code is unknown (renders no notice — matches the old "no matching branch").
 *
 * @param string $flash The ?sn_flash=… value (already sanitized by the caller).
 * @return array{0:string,1:string}|null
 */
function sn_admin_flash_to_notice( $flash ) {
	$static = sn_admin_flash_messages();
	if ( isset( $static[ $flash ] ) ) {
		return $static[ $flash ];
	}

	// Live-data codes — message computed from current state at render time.
	// v4.11.0 (T5): the Insights "Create draft" success notice links to the
	// new draft's editor. The edit link (which carries a nonce) is stashed in
	// a per-user transient by sn_handle_insights_create_draft(); read it back
	// here, then clear it so the notice fires exactly once.
	if ( 'insights_draft_created' === $flash && function_exists( 'sn_insights_draft_result_key' ) ) {
		$stash = get_transient( sn_insights_draft_result_key() );
		delete_transient( sn_insights_draft_result_key() );
		$edit_link = ( is_array( $stash ) && ! empty( $stash['edit_link'] ) ) ? (string) $stash['edit_link'] : '';
		if ( '' !== $edit_link ) {
			return array(
				'success',
				'Draft created from this recommendation. <a href="' . esc_url( $edit_link ) . '">Edit it &rarr;</a>',
			);
		}
		// Draft was created but the edit link didn't survive (transient miss) —
		// still report success without the link rather than swallowing it.
		return array( 'success', 'Draft created from this recommendation. Find it under Posts &rarr; Drafts.' );
	}

	if ( 'login_saved' === $flash ) {
		$slug_now  = sn_setting( 'login.slug', 'sn-login' );
		$login_url = home_url( '/' . $slug_now );
		return array( 'success', 'Login slug saved. New URL: <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a>' );
	}
	if ( 'pl_test_ok' === $flash ) {
		$cached   = get_transient( SN_PLAUSIBLE_BATCH_KEY );
		$visitors = is_array( $cached ) && isset( $cached['data']['visitors']['value'] ) ? (int) $cached['data']['visitors']['value'] : 0;
		return array( 'success', '&#10003; API call succeeded — ' . number_format_i18n( $visitors ) . ' visitor(s) in last 7 days.' );
	}
	if ( 'pl_test_err' === $flash ) {
		$err    = sn_plausible_last_error();
		$detail = $err ? 'HTTP ' . (int) $err['code'] . ' &middot; <code>' . esc_html( substr( $err['message'], 0, 200 ) ) . '</code>' : 'no diagnostic recorded';
		return array( 'error', '&#10005; API call failed &mdash; ' . $detail );
	}

	// Count-prefixed codes — parse the trailing int into the message template.
	if ( 0 === strpos( $flash, 'rt_applied_' ) ) {
		$count = (int) substr( $flash, strlen( 'rt_applied_' ) );
		return array( 'success', sprintf( '%d post(s) cleaned. Reading-time cache rebuilt.', $count ) );
	}
	if ( 0 === strpos( $flash, 'cleared_' ) ) {
		$count = (int) substr( $flash, strlen( 'cleared_' ) );
		return array( 'success', $count . ' database override(s) cleared. Site is reading from theme files.' );
	}
	if ( 0 === strpos( $flash, 'reset_' ) ) {
		$count = (int) substr( $flash, strlen( 'reset_' ) );
		return array( 'success', 'Full reset: ' . $count . ' override(s) cleared + all caches purged.' );
	}

	// Id-prefixed codes — static message; the id is consumed elsewhere
	// (sn_theme_options_page massages $_GET['new_id'] for the Webhooks row highlight).
	if ( 0 === strpos( $flash, 'wh_added_' ) ) {
		return array( 'success', 'Webhook added. Copy the signing secret below — it will not be shown again.' );
	}
	if ( 0 === strpos( $flash, 'wh_rotated_' ) ) {
		return array( 'success', 'Webhook updated. <strong>Signing secret was rotated</strong> — copy the new value below before navigating away.' );
	}

	return null;
}
