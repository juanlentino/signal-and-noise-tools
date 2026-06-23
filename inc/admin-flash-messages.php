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
		'tag_merge_ok'              => array( 'success', 'Tags merged.' ),
		'tag_merge_error'           => array( 'error', 'Tag merge failed — one or more tags were no longer valid.' ),
		'tag_ai_suggested'          => array( 'success', 'AI tag suggestions ready — review and apply below.' ),
		'tag_ai_unavailable'        => array( 'error', 'No AI provider is configured (Settings > Connectors).' ),
		'tag_ai_none'               => array( 'info', 'No tag suggestions (every Note is tagged, or the AI returned none).' ),
		'tag_ai_applied'            => array( 'success', 'Tags applied.' ),
		'tag_pruned'                => array( 'success', 'Unused tags deleted.' ),
		'tag_prune_error'           => array( 'error', 'Could not delete the selected tags.' ),
		'login_empty'               => array( 'error', 'Login slug cannot be empty.' ),
		'login_failed'              => array( 'error', 'Login slug save failed.' ),
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
		'narration_generated'       => array( 'success', 'Weekly digest generated.' ),
		'narration_failed'          => array( 'error', 'Could not generate the digest. Check that an AI provider is configured under Settings → Connectors.' ),
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
		'analytics_exclude_saved'   => array( 'success', 'Visit-exclusion settings saved.' ),
		'analytics_exclude_unchanged' => array( 'info', 'No changes to save.' ),
		'release_notes_drafted'     => array( 'success', 'Release notes drafted &mdash; copy them from the box below.' ),
		'release_notes_failed'      => array( 'error', 'Could not draft release notes. See the detail below, or check that an AI provider is configured under Settings &rarr; Connectors.' ),
		'theme_saved'               => array( 'success', 'Front-end settings saved.' ),
		'theme_unchanged'           => array( 'info', 'No front-end settings changed.' ),
		'music_saved'               => array( 'success', 'Music settings saved. Hit &ldquo;Sync now&rdquo; to refresh the discography with the new credentials.' ),
		'music_unchanged'           => array( 'info', 'No changes to save.' ),
		'music_synced'              => array( 'success', 'Discography synced from Muso.AI + Spotify. The <code>/music</code> timeline and schema now reflect the latest credits.' ),
		'music_sync_failed'         => array( 'error', 'Sync failed &mdash; the previous discography was kept (no blank page). See the status panel below for the error.' ),
		'music_featured_invalid'    => array( 'error', 'That doesn&rsquo;t look like a Spotify link. Paste a track, album, or playlist URL (e.g. <code>https://open.spotify.com/album/&hellip;</code>) or <code>spotify:</code> URI.' ),
		'indexnow_saved'            => array( 'success', 'IndexNow settings saved. Changed URLs are submitted to search engines automatically.' ),
		'indexnow_key_regenerated'  => array( 'success', 'IndexNow key regenerated &mdash; search engines re-verify on the next submission.' ),
		'indexnow_pinged'           => array( 'success', 'Recent content queued for IndexNow submission.' ),
		'indexnow_disabled'         => array( 'error', 'Enable IndexNow first, then run the backfill.' ),
		'analytics_saved'             => array( 'success', 'Analytics credentials updated. Dashboard data refreshes within ~15 minutes.' ),
		'analytics_unchanged'         => array( 'info', 'No changes to save.' ),
		'analytics_locked'            => array( 'error', 'Analytics credentials are locked by the <code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code> constants in wp-config.php — remove them to edit here.' ),
		'analytics_test_unconfigured' => array( 'error', 'Analytics not configured — set the account ID and read token first.' ),
		'analytics_imported'          => array( 'success', 'Plausible history imported — summary below.' ),
		'analytics_import_empty'      => array( 'warning', 'No CSV files were selected to import.' ),
		'analytics_import_err'        => array( 'error', 'Import failed — the importer is unavailable.' ),
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
	if ( 'analytics_test_ok' === $flash ) {
		return array( 'success', '&#10003; Analytics API reachable — credentials valid.' );
	}
	if ( 'analytics_test_err' === $flash ) {
		$err    = function_exists( 'sn_analytics_last_error' ) ? sn_analytics_last_error() : null;
		$detail = $err ? 'HTTP ' . (int) $err['code'] . ' &middot; <code>' . esc_html( substr( (string) $err['message'], 0, 200 ) ) . '</code>' : 'no diagnostic recorded';
		return array( 'error', '&#10005; Analytics API call failed &mdash; ' . $detail );
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
