<?php
/**
 * Unit tests for the shared admin flash-message registry
 * (inc/admin-flash-messages.php).
 *
 * Guards the v4.5.3 collapse of the two duplicate flash ladders into one data
 * source + resolver. Covers all three message shapes: exact-match static
 * codes, count/id-prefixed codes, and live-data codes.
 *
 * Run: php tests/admin-flash-messages.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$GLOBALS['__settings']  = array( 'login.slug' => 'secret-door' );
$GLOBALS['__transient'] = false;

function sn_setting( $path, $default = null ) { return $GLOBALS['__settings'][ $path ] ?? $default; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function esc_url( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function get_transient( $k ) { return $GLOBALS['__transient']; }
function number_format_i18n( $n ) { return (string) $n; }

// v7.0.1: the 'insights_failed' live-data notice reads the real stored scan
// error via snt_insights_last_error() (defined in inc/insights.php, not loaded
// here). Stub it so the resolver can surface the actual code + message.
$GLOBALS['__insights_err'] = null;
function snt_insights_last_error() { return $GLOBALS['__insights_err']; }

require_once __DIR__ . '/../inc/admin-flash-messages.php';

$pass = 0; $fail = 0;
function fm_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}

echo "\nTest 1: exact-match static codes\n";
fm_eq( array( 'success', 'Identity settings saved.' ), sn_admin_flash_to_notice( 'identity_saved' ), 'identity_saved' );
fm_eq( array( 'info', 'No changes to save.' ), sn_admin_flash_to_notice( 'identity_unchanged' ), 'identity_unchanged' );
fm_eq( array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' ), sn_admin_flash_to_notice( 'cf_purged_unconfigured' ), 'cf_purged_unconfigured keeps warning severity' );
fm_eq( array( 'success', 'Block migration scan complete.' ), sn_admin_flash_to_notice( 'block_migrations_scanned' ), 'block_migrations_scanned' );
fm_eq( array( 'error', 'Heartbeat URL must start with <code>https://</code> — the setting was cleared. Re-enter a secure URL.' ), sn_admin_flash_to_notice( 'monitoring_url_not_https' ), 'monitoring_url_not_https → error (Fix C; provider-neutral copy since v8.1.6)' );
fm_eq( array( 'success', 'IndexNow settings saved. Changed URLs are submitted to search engines automatically.' ), sn_admin_flash_to_notice( 'indexnow_saved' ), 'indexnow_saved (v5.1.0)' );
fm_eq( 'error', sn_admin_flash_to_notice( 'indexnow_disabled' )[0], 'indexnow_disabled → error severity (backfill-while-off must surface feedback)' );
fm_eq( array( 'success', 'Recent content queued for IndexNow submission.' ), sn_admin_flash_to_notice( 'indexnow_pinged' ), 'indexnow_pinged (v5.1.0)' );
fm_eq( 'success', sn_admin_flash_to_notice( 'indexnow_key_regenerated' )[0], 'indexnow_key_regenerated → success severity' );

echo "\nTest 2: count-prefixed codes parse the trailing int\n";
fm_eq( array( 'success', '12 database override(s) cleared. Site is reading from theme files.' ), sn_admin_flash_to_notice( 'cleared_12' ), 'cleared_12' );
fm_eq( array( 'success', 'Full reset: 3 override(s) cleared + all caches purged.' ), sn_admin_flash_to_notice( 'reset_3' ), 'reset_3' );
fm_eq( array( 'success', '7 post(s) cleaned. Reading-time cache rebuilt.' ), sn_admin_flash_to_notice( 'rt_applied_7' ), 'rt_applied_7' );

echo "\nTest 3: id-prefixed codes resolve to static message\n";
fm_eq( array( 'success', 'Webhook added. Copy the signing secret below — it will not be shown again.' ), sn_admin_flash_to_notice( 'wh_added_abc123' ), 'wh_added_<id>' );
$rotated = sn_admin_flash_to_notice( 'wh_rotated_abc123' );
fm_eq( 'success', $rotated[0], 'wh_rotated_<id> severity' );
fm_eq( true, false !== strpos( $rotated[1], 'Signing secret was rotated' ), 'wh_rotated_<id> message body' );

echo "\nTest 4: live-data codes compute from state\n";
$login = sn_admin_flash_to_notice( 'login_saved' );
fm_eq( 'success', $login[0], 'login_saved severity' );
fm_eq( true, false !== strpos( $login[1], 'https://example.test/secret-door' ), 'login_saved embeds current slug URL' );

echo "\nTest 5: unknown code returns null (renders no notice)\n";
fm_eq( null, sn_admin_flash_to_notice( 'totally_unknown_code' ), 'unknown → null' );
fm_eq( null, sn_admin_flash_to_notice( '' ), 'empty → null' );

echo "\nTest 5a: insights_failed surfaces the REAL error, not a blanket 'configure AI' (v7.0.1)\n";
// The bug: a downstream, insights-specific failure (parse error, transport
// timeout, empty response) rendered the misleading "Check that an AI provider is
// configured under Settings → Connectors." even though AI was configured + billing.
$GLOBALS['__insights_err'] = array( 'code' => 'snt_insights_invalid_json', 'message' => 'AI response was not valid JSON.', 'at' => 123 );
$note = sn_admin_flash_to_notice( 'insights_failed' );
fm_eq( 'error', $note[0], 'insights_failed → error severity' );
fm_eq( true, false !== strpos( $note[1], 'AI response was not valid JSON.' ), 'surfaces the REAL error message' );
fm_eq( true, false !== strpos( $note[1], 'snt_insights_invalid_json' ), 'surfaces the REAL error code' );
fm_eq( true, false !== stripos( $note[1], 'insights-specific' ), 'reframes as an insights-specific failure (AI is working)' );
fm_eq( false, false !== strpos( $note[1], 'Check that an AI provider is configured' ), 'does NOT show the old misleading configure-AI copy' );
// v7.1.0: when the stored error carries the model's raw output, surface it (the
// definitive diagnostic — shows whether it was prose, a trailing comma, etc.).
$GLOBALS['__insights_err'] = array( 'code' => 'snt_insights_invalid_json', 'message' => 'AI response was not valid JSON.', 'raw' => 'Here are some open questions: [ malformed', 'at' => 123 );
$note = sn_admin_flash_to_notice( 'insights_failed' );
fm_eq( true, false !== stripos( $note[1], 'model returned' ), 'surfaces a "model returned" preamble for the raw output' );
fm_eq( true, false !== strpos( $note[1], 'Here are some open questions' ), 'surfaces the raw model output snippet' );
// No raw → no "model returned" clause (the notice stays clean).
$GLOBALS['__insights_err'] = array( 'code' => 'snt_ai_empty_response', 'message' => 'AI returned an empty response.', 'raw' => '', 'at' => 123 );
$note = sn_admin_flash_to_notice( 'insights_failed' );
fm_eq( false, false !== stripos( $note[1], 'model returned' ), 'no raw → no "model returned" clause' );
// No diagnostic recorded → still an error notice, with a recover-and-retry hint.
$GLOBALS['__insights_err'] = null;
$note = sn_admin_flash_to_notice( 'insights_failed' );
fm_eq( 'error', $note[0], 'insights_failed with no stored error → still an error notice' );
fm_eq( true, false !== stripos( $note[1], 'no diagnostic' ), 'no-diagnostic branch explains the empty state' );

echo "\nTest 5b: insights_ai_unavailable keeps the configure-AI copy (the one correct case)\n";
$note = sn_admin_flash_to_notice( 'insights_ai_unavailable' );
fm_eq( 'error', $note[0], 'insights_ai_unavailable → error severity' );
fm_eq( true, false !== stripos( $note[1], 'Connectors' ), 'genuine ai-unavailable still points at Settings → Connectors' );

echo "\nTest 6: coordination guard — every exact code the dispatcher emits resolves\n";
$emitted = array(
	'identity_saved','identity_unchanged','login_empty','login_failed','cf_saved','cf_purged_ok','cf_purged_unconfigured',
	'purged','wh_updated','wh_deleted','wh_invalid','wh_not_found','insights_scanned','insights_failed','insights_ai_unavailable',
	'insights_dismissed','insights_snoozed','insights_done','insights_settings_saved','health_scanned','health_scanned_clean',
	'pattern_adoption_scanned','block_migrations_scanned','audit_retention_saved','audit_retention_unchanged',
);
foreach ( $emitted as $code ) {
	fm_eq( true, null !== sn_admin_flash_to_notice( $code ), "resolver covers '$code'" );
}

echo "\nTest 6b: health scan flash is findings-aware (v8.0.1)\n";
// The static 'health_scanned' copy said "findings below" even over a
// 0-findings screen with nothing below it. The clean variant must exist
// and say so; the dirty variant keeps pointing at the findings.
$note = sn_admin_flash_to_notice( 'health_scanned_clean' );
fm_eq( 'success', $note[0] ?? null, 'health_scanned_clean → success severity' );
fm_eq( true, false !== stripos( (string) ( $note[1] ?? '' ), 'all checks passing' ), 'clean copy says all checks passing' );
fm_eq( false, false !== stripos( (string) ( $note[1] ?? '' ), 'findings below' ), 'clean copy does NOT promise findings below' );
$note = sn_admin_flash_to_notice( 'health_scanned' );
fm_eq( true, false !== stripos( (string) ( $note[1] ?? '' ), 'findings below' ), 'dirty copy still points at the findings' );

// v9.5.0 (R2): the weekly-digest flash codes (narration_generated / _settings_saved
// / _ai_unavailable, and the dynamic narration_failed branch) retired with the surface.
$static = sn_admin_flash_messages();
fm_eq( false, isset( $static['narration_ai_unavailable'] ), 'R2: narration_ai_unavailable static code removed' );
fm_eq( false, isset( $static['narration_settings_saved'] ), 'R2: narration_settings_saved static code removed' );
fm_eq( false, isset( $static['narration_generated'] ), 'R2: narration_generated static code removed' );
// release_notes_failed no longer blames AI config (its detail box shows the real error).
fm_eq( false, false !== strpos( $static['release_notes_failed'][1], 'AI provider is configured' ), 'release_notes_failed copy no longer blames AI config' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
