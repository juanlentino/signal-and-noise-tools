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
// Reason-surfacing task: sn_analytics_funnels_kind_message() (required mid-file
// below, once inc/analytics-sessions.php loads) translates its reason text via __().
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

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

echo "\nTest 7: analytics_funnels static codes (S2 §3)\n";
fm_eq( array( 'success', 'Session funnels saved. The Sessions view reflects them on the next load.' ), sn_admin_flash_to_notice( 'analytics_funnels_saved' ), 'analytics_funnels_saved' );
fm_eq( array( 'error', 'Session funnels could not be saved — try again.' ), sn_admin_flash_to_notice( 'analytics_funnels_failed' ), 'analytics_funnels_failed' );

echo "\nTest 8: analytics_funnels_invalid prefix branch (S2 §3 + T2-review hardening)\n";
$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid_3' );
fm_eq( 'error', $note[0], 'single bad line -> error severity' );
fm_eq( true, false !== strpos( $note[1], 'Check line 3.' ), 'single bad line -> singular "line" + the number' );
fm_eq( false, false !== strpos( $note[1], 'lines' ), 'single bad line does NOT say "lines" (plural)' );

$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid_2-4' );
fm_eq( true, false !== strpos( $note[1], 'Check lines 2, 4.' ), 'multiple bad lines -> plural "lines" + comma-joined list' );

// Bare code (no trailing line-number suffix) still resolves, with no "Check line" clause.
$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid' );
fm_eq( 'error', $note[0], 'bare code (no line suffix) -> error severity' );
fm_eq( 'Funnels not saved — nothing changed.', $note[1], 'bare code -> no "Check line" clause appended' );

// Crafted-junk suffix: sn_handle_analytics_funnels_save() only ever emits digits
// joined by '-', but $flash reaches this resolver after sanitize_text_field()
// (inc/admin-page.php), which strips tags but not arbitrary characters — a
// hand-crafted ?sn_flash= URL param could still carry stray junk. Hardening pin:
// the resolver whitelists to [0-9\-] (exactly what the legit path emits — never
// a comma or space) and caps the suffix length itself.
$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid_<script>alert(1)</script>' );
fm_eq( true, false === strpos( $note[1], '<script>' ), 'crafted junk suffix: no raw "<script>" survives into the notice' );
fm_eq( true, false === strpos( $note[1], '<' ) && false === strpos( $note[1], '>' ) && false === strpos( $note[1], '(' ) && false === strpos( $note[1], ')' ),
	'crafted junk suffix: non-whitelist characters are stripped entirely' );
// T3-review rider (b): comma + space are OUTSIDE the whitelist too — the legit
// path implode('-')s bare digits, so '2, 4' collapses to the digits alone.
$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid_2, 4' );
fm_eq( true, false !== strpos( $note[1], 'Check line 24.' ), 'crafted comma/space suffix: stripped to bare digits (whitelist is [0-9-] only)' );
fm_eq( false, false !== strpos( $note[1], '2, 4' ), 'crafted comma/space suffix: the comma-joined form never renders' );

$flash_long = 'analytics_funnels_invalid_' . str_repeat( '9', 100 );
$note       = sn_admin_flash_to_notice( $flash_long );
fm_eq( false, false !== strpos( $note[1], str_repeat( '9', 100 ) ), 'crafted junk suffix: a 100-char digit run is NOT echoed in full (capped)' );
fm_eq( true, false !== strpos( $note[1], str_repeat( '9', 40 ) ), 'crafted junk suffix: exactly the first 40 chars of the digit run survive the cap' );
// BACK-COMPAT pin (reason-surfacing task): the whole of Test 8 above uses the
// OLD bare-line-number format ('analytics_funnels_invalid_3', '..._2-4', plus
// every crafted-junk variant) and every assertion is UNCHANGED by this task —
// the old format still renders the generic "Check line N." copy, exactly as
// before. This comment is the pin: Test 8 passing unmodified IS the back-compat
// guarantee for old-format codes a stale bookmark/browser-history entry might replay.

echo "\nTest 8b: analytics_funnels_invalid NEW pair-format codes ('<line>k<kindIndex>') degrade to the generic message when the shared kind-message source is not loaded on this page\n";
// This test file intentionally does NOT require inc/analytics-sessions.php up
// top — unlike the real bootstrap (signal-and-noise-tools.php), where it always
// loads before inc/admin-flash-messages.php. The function_exists guard at the
// render site exists for exactly this isolated-page scenario: never fatal,
// never partially render a reason it can't actually look up — just fall back
// to the same generic copy the bare code renders.
fm_eq( false, function_exists( 'sn_analytics_funnels_kind_message' ), 'sanity: the kind-message source is genuinely NOT loaded yet in this suite' );
$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid_2k4' );
fm_eq( 'error', $note[0] ?? null, 'pair-format code without the kind-message source -> still an error notice, not null/fatal' );
fm_eq( 'Funnels not saved — nothing changed.', $note[1] ?? null, 'pair-format code without the kind-message source -> degrades to the generic message' );

// ─── From here on, the shared kind-message source IS loaded (mirrors the real
// bootstrap load order: inc/analytics-sessions.php before inc/admin-flash-messages.php). ───
require_once __DIR__ . '/../inc/analytics-sessions.php';

echo "\nTest 8c: analytics_funnels_invalid pair-format codes render each kind's own reason text, single-sourced against the parser (SN_ANALYTICS_FUNNELS_ERR_KINDS)\n";
fm_eq( 6, count( SN_ANALYTICS_FUNNELS_ERR_KINDS ), 'sanity: six kinds' );
foreach ( SN_ANALYTICS_FUNNELS_ERR_KINDS as $idx => $kind ) {
	$line          = $idx + 1;
	$note          = sn_admin_flash_to_notice( 'analytics_funnels_invalid_' . $line . 'k' . $idx );
	$expect_reason = sn_analytics_funnels_kind_message( $kind );
	fm_eq( 'error', $note[0] ?? null, "pair-format '$kind' kind (index $idx) -> error severity" );
	fm_eq( true, false !== strpos( $note[1], 'Funnels not saved — nothing changed.' ), "pair-format '$kind' kind -> still opens with the summary line" );
	fm_eq( true, false !== strpos( $note[1], 'Line ' . $line . ': ' . $expect_reason ), "pair-format '$kind' kind -> renders 'Line $line: ' + its own single-sourced reason text" );
}

echo "\nTest 8d: multiple pair-format reasons render as one line each, in order\n";
$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid_2k1-7k4' );
fm_eq( true, false !== strpos( $note[1], 'Line 2: ' . sn_analytics_funnels_kind_message( 'name' ) ), 'first pair (name kind) renders its own line' );
fm_eq( true, false !== strpos( $note[1], 'Line 7: ' . sn_analytics_funnels_kind_message( 'few' ) ), 'second pair (few kind) renders its own line' );
fm_eq( true, strpos( $note[1], 'Line 2:' ) < strpos( $note[1], 'Line 7:' ), 'the two reason lines render in the code\'s own order' );

echo "\nTest 8e: quote/apostrophe characters inside the kind-message reasons survive the decode+render pipeline intact — no stray backslash, no corruption (cf. the update_option slash-asymmetry defect class)\n";
$note = sn_admin_flash_to_notice( 'analytics_funnels_invalid_9k3' ); // kind index 3 = 'step'
fm_eq( true, false !== strpos( $note[1], '":"' ), 'the step reason\'s literal ":" characters survive verbatim' );
fm_eq( false, false !== strpos( $note[1], '\\"' ), 'no stray backslash was introduced before a quote character' );
$note_many = sn_admin_flash_to_notice( 'analytics_funnels_invalid_4k5' ); // kind index 5 = 'many'
fm_eq( true, false !== strpos( $note_many[1], "wasn't saved" ), 'the many reason\'s apostrophe survives verbatim, unslashed' );
fm_eq( false, false !== strpos( $note_many[1], "\\" ), 'no backslash of any kind appears in the rendered notice' );

echo "\nTest 8f: hostile pair-format codes degrade to the generic message, NEVER a warning or a partial/garbage line — kind index out of range, line 0, garbage separators\n";
$hostile_codes = array(
	'analytics_funnels_invalid_2k9'     => 'kind index 9 does not exist (only 0-5)',
	'analytics_funnels_invalid_0k1'     => 'line 0 is out of the 1-9999 range',
	'analytics_funnels_invalid_2k4x7k1' => 'garbage separator instead of "-"',
	'analytics_funnels_invalid_2kk4'    => 'double "k"',
	'analytics_funnels_invalid_k4'      => 'missing line',
	'analytics_funnels_invalid_2k'      => 'missing kind index',
	'analytics_funnels_invalid_-2k4'    => 'leading dash produces an empty pair token',
	'analytics_funnels_invalid_2k4-'    => 'trailing dash produces an empty pair token',
);
foreach ( $hostile_codes as $code => $why ) {
	$note = sn_admin_flash_to_notice( $code );
	fm_eq( 'error', $note[0] ?? null, "hostile code ($why) -> still an error notice, never null/fatal" );
	fm_eq( 'Funnels not saved — nothing changed.', $note[1] ?? null, "hostile code ($why) -> degrades to the generic message, no partial line list" );
}

echo "\nTest 8g: decode-side pair cap — even a hostile code that packs MORE than five well-formed pairs into the 40-char budget only ever renders five reason lines\n";
$six_pairs = 'analytics_funnels_invalid_1k0-2k0-3k0-4k0-5k0-6k0';
$note      = sn_admin_flash_to_notice( $six_pairs );
fm_eq( 5, substr_count( (string) ( $note[1] ?? '' ), 'Line ' ), 'six well-formed pairs in the code -> only five "Line " reason lines render' );
fm_eq( false, false !== strpos( (string) ( $note[1] ?? '' ), 'Line 6:' ), 'the sixth pair is dropped, not rendered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
