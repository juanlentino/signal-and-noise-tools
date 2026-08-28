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
		'digest_saved'              => array( 'success', 'Security digest settings saved.' ),
		'digest_test_sent'          => array( 'success', 'Test digest sent to the admin email address.' ),
		'digest_test_failed'        => array( 'error', 'Test digest failed to send: check the mail configuration (see the error note on the Login defense panel).' ),
		'now_saved'                 => array( 'success', 'Now page saved: the live /now renders this content with today\'s date.' ),
		'now_cleared'               => array( 'info', 'Now page override cleared. /now reverts to the theme\'s built-in content.' ),
		'now_unchanged'             => array( 'info', 'No changes to save.' ),
		'now_unparseable'           => array( 'error', 'Nothing saved: the content parses to zero sections. Start each section with "## Label" and give it at least one item line.' ),
		'now_failed'                => array( 'error', 'Now page save failed: the editor module is unavailable.' ),
		'now_resynced'              => array( 'success', 'No content changes: the live /now page was re-rendered with the current engine anyway.' ),
		'uses_resynced'             => array( 'success', 'No content changes: the live /about/uses page was re-rendered with the current engine anyway.' ),
		'resume_saved'              => array( 'success', 'Resume saved: the live /resume page has been regenerated.' ),
		'resume_unchanged'          => array( 'info', 'No changes to save.' ),
		'resume_resynced'           => array( 'success', 'No content changes: the live /resume page was re-rendered with the current engine anyway.' ),
		'resume_refused'            => array( 'error', 'Nothing saved: the resume needs at least one experience entry (with an organization) or one publication (with a title). The live page is unchanged.' ),
		'resume_failed'             => array( 'error', 'Resume save failed: the editor module is unavailable.' ),
		'uses_saved'                => array( 'success', 'Uses page saved: the live /about/uses renders this content.' ),
		'uses_cleared'              => array( 'info', 'Uses page override cleared. /about/uses reverts to the theme\'s built-in list.' ),
		'uses_unchanged'            => array( 'info', 'No changes to save.' ),
		'uses_unparseable'          => array( 'error', 'Nothing saved: the content parses to zero groups. Start each group with "## Label" and give it at least one item line.' ),
		'uses_failed'               => array( 'error', 'Uses page save failed: the editor module is unavailable.' ),
		'identity_unchanged'        => array( 'info', 'No changes to save.' ),
		'tag_merge_ok'              => array( 'success', 'Tags merged.' ),
		'tag_merge_error'           => array( 'error', 'Tag merge failed: one or more tags were no longer valid.' ),
		'tag_ai_suggested'          => array( 'success', 'AI tag suggestions ready: review and apply below.' ),
		'tag_ai_unavailable'        => array( 'error', 'No AI provider is configured (Settings > Connectors).' ),
		'tag_ai_none'               => array( 'info', 'No tag suggestions (every Note is tagged, or the AI returned none).' ),
		'tag_ai_applied'            => array( 'success', 'Tags applied.' ),
		'tag_pruned'                => array( 'success', 'Unused tags deleted.' ),
		'tag_prune_error'           => array( 'error', 'Could not delete the selected tags.' ),
		'login_empty'               => array( 'error', 'Login slug cannot be empty.' ),
		'login_failed'              => array( 'error', 'Login slug save failed.' ),
		'cf_saved'                  => array( 'success', 'Cloudflare settings saved.' ),
		'cf_purged_ok'              => array( 'success', 'Cloudflare zone purge dispatched.' ),
		'cf_purged_unconfigured'    => array( 'warning', 'Cloudflare not configured: set the API token and zone ID first.' ),
		'purged'                    => array( 'success', 'All caches purged.' ),
		'wh_updated'                => array( 'success', 'Webhook updated.' ),
		'wh_deleted'                => array( 'success', 'Webhook deleted. Pending retries (if any) will drop on next dispatch.' ),
		'wh_invalid'                => array( 'error', 'Could not add webhook: name and valid URL are required.' ),
		'wh_not_found'              => array( 'error', 'Webhook not found.' ),
		// v8.10.0 Redirects arc.
		'redirect_added'            => array( 'success', 'Redirect saved.' ),
		'redirect_updated'          => array( 'success', 'Redirect updated.' ),
		'redirect_deleted'          => array( 'success', 'Redirect deleted: it stops resolving immediately.' ),
		'redirect_invalid'          => array( 'error', 'Could not save the redirect: a source path and a different target are both required.' ),
		'redirect_404_deleted'      => array( 'success', 'Removed from the 404 log.' ),
		'redirect_404_cleared'      => array( 'success', '404 log cleared.' ),
		// v10.47.0: the probe-only bulk dismiss.
		'redirect_404_probes_cleared' => array( 'success', 'Automated probes dismissed. Genuinely broken paths were kept.' ),
		'redirect_404_probes_none'    => array( 'info', 'No automated probes in the log.' ),
		'insights_scanned'          => array( 'success', 'Insights scan complete. Open questions below (or none, if nothing cleared the bar).' ),
		// v7.0.1: the genuine "no AI provider configured" case — the ONLY failure
		// that earns the configure-AI copy. Every other scan failure resolves via
		// the 'insights_failed' live-data branch below, which surfaces the REAL error.
		'insights_ai_unavailable'   => array( 'error', 'Insights scan failed: no AI provider is configured. Enable AI under Settings → AI, then add a provider and key under Settings → Connectors.' ),
		'insights_dismissed'        => array( 'success', 'Question dismissed.' ),
		'insights_snoozed'          => array( 'success', 'Question snoozed for 30 days.' ),
		'insights_done'             => array( 'success', 'Question marked as done.' ),
		'insights_settings_saved'   => array( 'success', 'Insights settings saved.' ),
		'health_scanned'            => array( 'success', 'Scan complete: findings below.' ),
		// v8.0.1: findings-aware split — the static copy above promised "findings
		// below" even over a 0-findings screen. The scan handler counts the fresh
		// result and emits the clean code when nothing was flagged.
		'health_scanned_clean'      => array( 'success', 'Scan complete: all checks passing.' ),
		'pattern_adoption_scanned'  => array( 'success', 'Scan complete.' ),
		'block_migrations_scanned'  => array( 'success', 'Block migration scan complete.' ),
		'audit_retention_saved'     => array( 'success', 'Audit retention saved.' ),
		'audit_retention_unchanged' => array( 'info', 'Audit retention unchanged.' ),
		'morning_brief_saved'       => array( 'success', 'Morning operations brief settings saved.' ),
		'morning_brief_test_sent'   => array( 'success', 'Test morning operations brief sent.' ),
		'morning_brief_test_failed' => array( 'error', 'Test morning operations brief could not be sent.' ),
		'config_drift_acknowledged' => array( 'success', 'Current settings acknowledged as the configuration baseline.' ),
		'scheduled_reads_saved'      => array( 'success', 'Scheduled read-only runs settings saved.' ),
		'scheduled_reads_ran'        => array( 'success', 'Read-only run completed; see the outcome line below.' ),
		'scheduled_reads_run_failed' => array( 'error', 'The read-only run could not execute (MCP door unavailable).' ),
		'monitoring_saved'          => array( 'success', 'Uptime monitoring settings saved.' ),
		'monitoring_url_not_https'  => array( 'error', 'Heartbeat URL must start with <code>https://</code>: the setting was cleared. Re-enter a secure URL.' ),
		'perf_saved'                => array( 'success', 'Performance settings saved.' ),
		'analytics_exclude_saved'   => array( 'success', 'Visit-exclusion settings saved.' ),
		'analytics_exclude_unchanged' => array( 'info', 'No changes to save.' ),
		'analytics_tuning_saved'     => array( 'success', 'Engine tuning saved. Signals recompute on the next dashboard load.' ),
		'analytics_tuning_unchanged' => array( 'info', 'Engine tuning unchanged.' ),
		// S2 §3 (v9.42.0 arc): owner-defined session funnels.
		'analytics_funnels_saved'    => array( 'success', 'Session funnels saved. The Sessions view reflects them on the next load.' ),
		'analytics_funnels_failed'   => array( 'error', 'Session funnels could not be saved: try again.' ),
		// v7.2.2: dropped the "or check that an AI provider is configured" clause —
		// the handler stores the real WP_Error and the box below shows it; blaming
		// AI config for a drafting failure is the same misdirection the insights
		// v7.0.1 fix removed.
		'theme_saved'               => array( 'success', 'Front-end settings saved.' ),
		'theme_unchanged'           => array( 'info', 'No front-end settings changed.' ),
		// v10.46.0: the AI tab's own save (models + monthly budget), split out of
		// save_theme so each form reports on what it actually changed.
		'ai_settings_saved'         => array( 'success', 'AI settings saved.' ),
		'ai_settings_unchanged'     => array( 'info', 'No AI settings changed.' ),
		// v10.46.0: the collector endpoint's own save, moved here from Content → RSS.
		'analytics_collector_saved'     => array( 'success', 'Collector endpoint saved. Beacons post there from the next request.' ),
		'analytics_collector_unchanged' => array( 'info', 'Collector endpoint unchanged.' ),
		'analytics_collector_invalid'   => array( 'error', 'That is not a valid collector URL &mdash; it needs an <code>http</code> or <code>https</code> scheme and a host. The previous endpoint was kept.' ),
		'analytics_collector_failed'    => array( 'error', 'Collector endpoint could not be saved &mdash; the RSS tracker module is not loaded.' ),
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
		'analytics_locked'            => array( 'error', 'Analytics credentials are locked by the <code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code> constants in wp-config.php: remove them to edit here.' ),
		'analytics_test_unconfigured' => array( 'error', 'Analytics not configured: set the account ID and read token first.' ),
		'schedule_fired'              => array( 'success', 'Scheduled-content boundary fired. The row was advanced and its URLs purged.' ),
		'schedule_repurged'           => array( 'success', 'Scheduled-content URLs re-purged from Cloudflare.' ),
		'schedule_swap_fired'         => array( 'success', 'Version swap fired &mdash; the old version hid, the new one revealed, one edge purge dispatched.' ),
		'schedule_invalid'            => array( 'error', 'That scheduled-content row was not found.' ),
		// R9 (v9.51.0, lane SEC-C): MCP write-door credential binding.
		'mcp_rw_bound'                => array( 'success', 'Write-door credential bound. <code>/mcp-rw</code> now accepts calls authenticated with that Application Password.' ),
		'mcp_rw_unbound'              => array( 'info', 'Write-door credential unbound &mdash; every call to <code>/mcp-rw</code> is denied until you bind one again.' ),
		'mcp_rw_bind_invalid'         => array( 'error', 'Could not bind that Application Password &mdash; it doesn&rsquo;t belong to your account, or no longer exists.' ),
		// v9.85.0 (Session 3): Machine Readers sensor settings.
		'machine_readers_saved'       => array( 'success', 'Machine Readers sensor settings saved. The panels read with the new credentials on the next load.' ),
		'gsc_credential_saved'        => array( 'success', 'Search Console credential saved. The private key is never displayed; the identity card above shows which key is stored.' ),
		'gsc_credential_cleared'      => array( 'success', 'Search Console credential removed.' ),
		'gsc_credential_unchanged'    => array( 'info', 'Search Console credential unchanged.' ),
		'gsc_credential_not_json'     => array( 'error', 'That is not valid JSON. Paste the whole downloaded key file, including the outer braces — the stored credential was not changed.' ),
		'gsc_credential_not_service_account' => array( 'error', 'That JSON is not a service-account key. An OAuth client JSON looks similar and comes from the same screen, but cannot be used here — the stored credential was not changed.' ),
		'gsc_credential_rejected'     => array( 'error', 'The pasted credential was rejected: a required field is missing or the private key is not a PEM block. Re-download the key from Google Cloud and paste it whole — the stored credential was not changed.' ),
		'gsc_test_ok'                 => array( 'success', 'Connection works. The properties this service account can read are listed below.' ),
		'gsc_test_no_properties'      => array( 'warning', 'The credential works, but this service account has been granted NO Search Console properties. Add its client_email in Search Console → Settings → Users and permissions.' ),
		'gsc_test_failed'             => array( 'error', 'The connection test failed. The reason is shown below the credential.' ),
		'gsc_test_not_configured'     => array( 'error', 'There is no credential to test yet.' ),
		'ml_embed_compare_ok'         => array( 'success', 'Comparison complete. The result is below — divergence is the number item 8 turns on.' ),
		'ml_embed_compare_failed'     => array( 'error', 'The comparison could not run. The reason is shown below.' ),
		'gsc_property_saved'          => array( 'success', 'Search Console property selected. Run a sync to pull the first window.' ),
		'gsc_property_unchanged'      => array( 'info', 'No property was chosen.' ),
		'gsc_property_unknown'        => array( 'error', 'That property is not one this service account can read. Run Test connection to see the list it actually has.' ),
		'gsc_sync_ok'                 => array( 'success', 'Search Console data synced. It appears in Analytics → Search and beside Top pages.' ),
		'gsc_sync_failed'             => array( 'error', 'The sync failed. The reason is shown below the credential.' ),
	);
}

/**
 * Decode a NEW-format 'analytics_funnels_invalid_<line>k<kind>[-…]' suffix
 * (reason-surfacing task) — already whitelisted to [0-9k-] and length-capped
 * by the caller — into a list of {line,text} reason lines, or null when the
 * suffix cannot be trusted at all: ANY malformed/out-of-range pair, or the
 * shared kind-message source (inc/analytics-sessions.php) not being loaded on
 * this page, degrades the WHOLE notice to the generic message rather than
 * mixing good and garbage lines — a partial decode of a hostile code is worse
 * than no detail.
 *
 * Range clamp (hostile-input hardening — a crafted ?sn_flash=… URL is
 * untrusted even after the character whitelist): a pair's line must be
 * 1-9999 (the parser's own maxima many times over — no real save ever
 * produces a line number outside this) and its kind index must be a valid
 * position in SN_ANALYTICS_FUNNELS_ERR_KINDS (0-5, six kinds); anything else
 * means the code was hand-crafted, never something
 * sn_handle_analytics_funnels_save() emitted, so it degrades rather than
 * risking an undefined-index warning or a garbage-mapped reason.
 *
 * Decode-side SOURCE cap (mirrors the encode-side cap in
 * sn_analytics_funnels_error_flash_code(), inc/admin-post-actions/analytics.php): only
 * the first FIVE pairs are ever rendered, even if a hostile code packs more
 * well-formed pairs into the 40-char budget.
 *
 * @since (reason-surfacing task)
 * @param string $suffix Whitelisted, length-capped suffix (already stripped of the 'analytics_funnels_invalid_' prefix).
 * @return array<int,array{line:int,text:string}>|null
 */
function sn_analytics_funnels_decode_pairs( $suffix ) {
	if ( ! defined( 'SN_ANALYTICS_FUNNELS_ERR_KINDS' ) || ! function_exists( 'sn_analytics_funnels_kind_message' ) ) {
		return null; // inc/analytics-sessions.php not loaded on this page — degrade, never fatal.
	}
	$kinds = SN_ANALYTICS_FUNNELS_ERR_KINDS;
	$out   = array();
	foreach ( array_slice( explode( '-', $suffix ), 0, 5 ) as $token ) {
		if ( 1 !== preg_match( '/^(\d{1,4})k([0-5])$/', $token, $m ) ) {
			return null;
		}
		$line = (int) $m[1];
		$kind = $kinds[ (int) $m[2] ] ?? null;
		if ( $line < 1 || $line > 9999 || null === $kind ) {
			return null;
		}
		$text = sn_analytics_funnels_kind_message( $kind );
		if ( '' === $text ) {
			return null;
		}
		$out[] = array(
			'line' => $line,
			'text' => $text,
		);
	}
	return $out ? $out : null;
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
	if ( 'login_saved' === $flash ) {
		$slug_now  = sn_setting( 'login.slug', 'sn-login' );
		$login_url = home_url( '/' . $slug_now );
		return array( 'success', 'Login slug saved. New URL: <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a>' );
	}
	if ( 'analytics_test_ok' === $flash ) {
		return array( 'success', '&#10003; Analytics API reachable: credentials valid.' );
	}
	if ( 'analytics_test_err' === $flash ) {
		$err    = function_exists( 'sn_analytics_last_error' ) ? sn_analytics_last_error() : null;
		$detail = $err ? 'HTTP ' . (int) $err['code'] . ' &middot; <code>' . esc_html( substr( (string) $err['message'], 0, 200 ) ) . '</code>' : 'no diagnostic recorded';
		return array( 'error', '&#10005; Analytics API call failed &mdash; ' . $detail );
	}
	// v7.0.1: surface the REAL insights-scan error (code + message) recorded by
	// sn_handle_insights_run(). The old blanket "check that an AI provider is
	// configured" copy fired for EVERY failure — parse errors, transport timeouts,
	// empty responses — even when AI was configured + billing (the weekly digest,
	// same transport, worked). The genuine no-provider case is handled by the
	// static 'insights_ai_unavailable' code above; this branch is everything else.
	if ( 'insights_failed' === $flash ) {
		$err = function_exists( 'snt_insights_last_error' ) ? snt_insights_last_error() : null;
		if ( is_array( $err ) && ! empty( $err['message'] ) ) {
			$detail = esc_html( substr( (string) $err['message'], 0, 300 ) );
			if ( ! empty( $err['code'] ) ) {
				$detail .= ' (<code>' . esc_html( (string) $err['code'] ) . '</code>)';
			}
			$notice = 'Insights scan failed: ' . $detail . ' Your AI provider is configured and working (the weekly digest uses the same one), so this is an insights-specific failure, not a setup problem.';
			// v7.1.0: when the model's raw output was captured (a parse failure), show
			// a bounded snippet so the exact defect is visible without log-diving.
			// v7.1.1: widened 200 → 400 chars — a 200-char cut hid WHERE a truncated
			// array actually ended, which is the diagnostic that matters most.
			if ( ! empty( $err['raw'] ) ) {
				$notice .= ' The model returned: <code>' . esc_html( substr( (string) $err['raw'], 0, 400 ) ) . '</code>';
			}
			// v13.20.4: show the call's TOKEN ACCOUNTING beside its text. A short
			// reply next to an exhausted output budget is a budget failure wearing
			// a parse failure's error code, and that is unreadable from the text
			// alone — which is exactly how the live `[ {` case looked like a JSON
			// bug for as long as nobody opened the request logs.
			if ( isset( $err['budget'] ) && is_array( $err['budget'] ) ) {
				$b    = $err['budget'];
				$max  = isset( $b['max_tokens'] ) ? (int) $b['max_tokens'] : 0;
				$done = ( isset( $b['completion'] ) && null !== $b['completion'] ) ? (int) $b['completion'] : null;
				$ch   = isset( $b['chars'] ) ? (int) $b['chars'] : 0;

				if ( null === $done ) {
					$notice .= ' No token record was found for this call, so the output budget cannot be checked here — see Settings &rarr; AI &rarr; AI Request Logs.';
				} elseif ( $max > 0 && $done >= (int) floor( $max * 0.95 ) ) {
					$notice .= sprintf(
						' <strong>This is a budget failure, not a JSON one:</strong> the call generated its entire %1$s-token output budget and returned only %2$s characters of text. Output tokens that never reach the text are billed all the same — extended thinking counts against this budget. Raise <code>SN_INSIGHTS_MAX_TOKENS</code> (currently %1$s, hard-clamped at 4096), or turn thinking off for this call.',
						esc_html( number_format_i18n( $max ) ),
						esc_html( number_format_i18n( $ch ) )
					);
				} else {
					$notice .= sprintf(
						' For context: the call generated %1$s of its %2$s budgeted output tokens and returned %3$s characters — the budget was not the limit here.',
						esc_html( number_format_i18n( $done ) ),
						esc_html( number_format_i18n( $max ) ),
						esc_html( number_format_i18n( $ch ) )
					);
				}
			}
			return array( 'error', $notice );
		}
		return array( 'error', 'Insights scan failed, but no diagnostic was recorded. Re-run the scan; if it recurs, check the PHP error log.' );
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
	// S2 §3 (v9.42.0 arc); pair-encoded reasons added (reason-surfacing task):
	// sn_handle_analytics_funnels_save() (inc/admin-post-actions/analytics.php) encodes
	// bad line(s) straight into the flash code, so nothing was saved AND the
	// notice can point at exactly which line(s) to fix, no transient plumbing
	// required. Two formats decode here:
	//   - NEW: '<line>k<kindIndex>[-<line>k<kindIndex>…]' (e.g. '2k4-7k1') —
	//     carries which of the six SN_ANALYTICS_FUNNELS_ERR_KINDS fired on each
	//     line, so the notice can show the OWNER-FACING reason, not just "check
	//     line N".
	//   - LEGACY (back-compat, pre-reason-surfacing): a bare '-'-joined line
	//     list (e.g. '2-4') with no 'k' — renders the old generic "Check line
	//     N." copy, unchanged, so a stale bookmark/browser-history replay of an
	//     old redirect URL still resolves to something sensible.
	if ( 0 === strpos( $flash, 'analytics_funnels_invalid' ) ) {
		$suffix = trim( substr( $flash, strlen( 'analytics_funnels_invalid' ) ), '_' );
		// T2/T3-review hardening: $flash already passed through sanitize_text_field()
		// upstream (inc/admin-page.php / inc/analytics-dashboard-page.php), but that
		// strips tags, not arbitrary characters — a hand-crafted ?sn_flash=…_<junk>
		// suffix could still carry stray quotes/unicode/overlong runs into this
		// notice. On the legitimate path the suffix is ONLY EVER digits joined by
		// '-' (legacy) or by '-' with a single 'k' inside each pair (current), so
		// whitelist to EXACTLY that charset and cap the length before it reaches
		// the UI. Worst-case NEW-format length: 5 pairs of "9999k5" (6 chars) +
		// 4 '-' separators = 34 chars — safely inside the pre-existing 40-char cap.
		$suffix = substr( preg_replace( '/[^0-9k\-]/', '', $suffix ), 0, 40 );

		if ( false !== strpos( $suffix, 'k' ) ) {
			$pairs = sn_analytics_funnels_decode_pairs( $suffix );
			if ( null !== $pairs ) {
				$lines = array();
				foreach ( $pairs as $pair ) {
					$lines[] = esc_html( 'Line ' . $pair['line'] . ': ' . $pair['text'] );
				}
				return array( 'error', 'Funnels not saved: nothing changed.' . ( $lines ? ( '<br>' . implode( '<br>', $lines ) ) : '' ) );
			}
			// Malformed/hostile pair code (garbage token, out-of-range kind/line,
			// or the shared kind-message source not loaded on this page) —
			// degrade to the generic message rather than guess at partial detail.
			return array( 'error', 'Funnels not saved: nothing changed.' );
		}

		// Legacy bare-line format (back-compat, unchanged from pre-reason-surfacing).
		$detail = '' !== $suffix ? ( ' Check line' . ( false !== strpos( $suffix, '-' ) ? 's ' : ' ' ) . str_replace( '-', ', ', $suffix ) . '.' ) : '';
		return array( 'error', 'Funnels not saved: nothing changed.' . $detail );
	}

	// Id-prefixed codes — static message; the id is consumed elsewhere
	// (sn_theme_options_page massages $_GET['new_id'] for the Webhooks row highlight).
	if ( 0 === strpos( $flash, 'wh_added_' ) ) {
		return array( 'success', 'Webhook added. Copy the signing secret below: it will not be shown again.' );
	}
	if ( 0 === strpos( $flash, 'wh_rotated_' ) ) {
		return array( 'success', 'Webhook updated. <strong>Signing secret was rotated</strong>: copy the new value below before navigating away.' );
	}

	return null;
}
