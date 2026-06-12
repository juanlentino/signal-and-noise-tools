<?php
/**
 * Unit tests for the extracted admin POST action handlers
 * (inc/admin-post-actions.php) + the dispatch map (inc/admin-post-handler.php).
 *
 * Before v4.5.3 these lived inside a 270-line if/elseif in
 * sn_handle_admin_post() with ZERO unit coverage. Each handler is now a
 * standalone fn( array $post ): string returning a flash code, so flash +
 * side effects are assertable directly.
 *
 * Run: php tests/admin-post-actions.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$changed = ! array_key_exists( $name, $GLOBALS['__options'] ) || $GLOBALS['__options'][ $name ] !== $value;
	$GLOBALS['__options'][ $name ] = $value;
	return $changed; // mirror WP: returns false when the value is unchanged
}
function delete_option( $name ) { unset( $GLOBALS['__options'][ $name ] ); return true; }
function get_bloginfo( $what ) { return ''; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '~[^a-z0-9\-]+~i', '-', (string) $s ), '-' ) ); }
function esc_url_raw( $s ) { return $s; }
function wp_unslash( $v ) { return $v; }
function add_action( $hook, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }

// ─── Stubs for the Insights "Create draft" handler (T5) ──────────────
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
class PA_WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof PA_WP_Error; }
}
$GLOBALS['__transients'] = array();
function set_transient( $k, $v, $exp = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return isset( $GLOBALS['__transients'][ $k ] ) ? $GLOBALS['__transients'][ $k ] : false; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
function get_current_user_id() { return 9; }
function get_edit_post_link( $id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}
// Insights impl shims — drive hit/miss + insert success/error per test.
function snt_insights_find_rec( $rec_id ) {
	return isset( $GLOBALS['__test_recs'][ $rec_id ] ) ? $GLOBALS['__test_recs'][ $rec_id ] : null;
}
function snt_insights_create_draft_from_rec( $rec ) {
	if ( isset( $GLOBALS['__test_draft_error'] ) ) { return $GLOBALS['__test_draft_error']; }
	return isset( $GLOBALS['__test_draft_id'] ) ? (int) $GLOBALS['__test_draft_id'] : 0;
}
function snt_insights_mark_done( $rec_id ) { $GLOBALS['__test_marked_done'][] = $rec_id; }

// v4.13.0: real Music-Identity constants (SN_SPOTIFY_*_OPT, SN_MUSO_PROFILE_OPT)
// + the real sn_spotify_invalidate_token() the save handler calls — bind the
// handler tests to the ACTUAL constant values, never test-local copies.
require_once __DIR__ . '/../inc/muso-api.php';
require_once __DIR__ . '/../inc/spotify-api.php';

// v4.14.0: featured-release parser + constant (for sn_handle_music_save). The
// test guard suppresses the module's add_filter registration on require.
define( 'SN_MUSIC_FEATURED_TEST', true );
require_once __DIR__ . '/../inc/music-featured.php';

// Stub the sync orchestrator so sn_handle_music_sync() is testable without the
// Muso/Spotify network path (tests/discography-sync.php covers the real sync).
$GLOBALS['__music_sync_result'] = true;
$GLOBALS['__music_sync_calls']  = 0;
function sn_discography_run_sync() {
	$GLOBALS['__music_sync_calls']++;
	return $GLOBALS['__music_sync_result'];
}

require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin-post-actions.php';
require_once __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function pa_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function pa_reset_store() { $GLOBALS['__options'] = array(); sn_setting_reset_cache(); }

echo "\nTest: sn_handle_save_login()\n";
pa_reset_store();
pa_eq( 'login_empty', sn_handle_save_login( array() ), 'missing slug → login_empty' );
pa_eq( 'login_empty', sn_handle_save_login( array( 'login_slug' => '   ' ) ), 'blank slug → login_empty' );
pa_eq( 'login_saved', sn_handle_save_login( array( 'login_slug' => 'Secret Door' ) ), 'valid slug → login_saved' );
pa_eq( 'secret-door', sn_setting( 'login.slug' ), 'slug persisted + sanitized' );

echo "\nTest: sn_handle_audit_save_retention() clamps [7,365]\n";
pa_reset_store();
sn_handle_audit_save_retention( array( 'audit_retention_days' => 999 ) );
pa_eq( 365, sn_setting( 'audit.retention_days' ), '999 → 365 (max)' );
sn_handle_audit_save_retention( array( 'audit_retention_days' => 2 ) );
pa_eq( 7, sn_setting( 'audit.retention_days' ), '2 → 7 (min)' );
sn_handle_audit_save_retention( array( 'audit_retention_days' => 90 ) );
pa_eq( 90, sn_setting( 'audit.retention_days' ), '90 passes through' );
pa_eq( 'audit_retention_saved', sn_handle_audit_save_retention( array( 'audit_retention_days' => 45 ) ), 'changed → audit_retention_saved' );

echo "\nTest: sn_handle_save_identity()\n";
pa_reset_store();
pa_eq( 'identity_saved', sn_handle_save_identity( array( 'identity_site_name' => 'Acme' ) ), 'first save → identity_saved' );
pa_eq( 'identity_unchanged', sn_handle_save_identity( array( 'identity_site_name' => 'Acme' ) ), 'identical re-save → identity_unchanged' );

echo "\nTest: sn_handle_cf_save() unlocked — masked-skip does NOT clobber the token\n";
// MUST run before the constant-lock test below define()s SN_CLOUDFLARE_API_TOKEN /
// SN_CLOUDFLARE_ZONE_ID — once defined, cf_save's unlocked branch is locked out
// for the rest of this process (define() is irreversible).
define( 'SN_CF_TOKEN_OPT', 'sn_cf_token' );
define( 'SN_CF_ZONE_OPT', 'sn_cf_zone' );
pa_reset_store();
pa_eq( 'cf_saved', sn_handle_cf_save( array( 'sn_cf_token' => 'real-cf-token', 'sn_cf_zone' => 'zone-abc' ) ), 'fresh token + zone → cf_saved' );
pa_eq( 'real-cf-token', get_option( 'sn_cf_token' ), 'cf token persisted' );
pa_eq( 'zone-abc', get_option( 'sn_cf_zone' ), 'cf zone persisted' );
// Re-submit the MASKED token placeholder (••••XXXX). cf_save always returns
// 'cf_saved' (no changed-tracking), so the meaningful check is that the stored
// token is NOT overwritten with the literal bullets (the substr-byte bug).
sn_handle_cf_save( array( 'sn_cf_token' => '••••oken', 'sn_cf_zone' => 'zone-abc' ) );
pa_eq( 'real-cf-token', get_option( 'sn_cf_token' ), 'masked re-submit does NOT clobber the cf token' );
// 'clear' deletes the token option.
pa_eq( 'cf_saved', sn_handle_cf_save( array( 'sn_cf_token' => 'clear' ) ), "'clear' → cf_saved" );
pa_eq( false, array_key_exists( 'sn_cf_token', $GLOBALS['__options'] ), 'cf token deleted on clear' );

echo "\nTest: sn_handle_cf_save() honors constant locks\n";
define( 'SN_CLOUDFLARE_API_TOKEN', 'locked-tok' );
define( 'SN_CLOUDFLARE_ZONE_ID', 'locked-zone' );
pa_reset_store();
pa_eq( 'cf_saved', sn_handle_cf_save( array( 'sn_cf_token' => 'attempt', 'sn_cf_zone' => 'attempt' ) ), 'returns cf_saved' );
pa_eq( array(), $GLOBALS['__options'], 'no option written when both constants are defined (locked)' );

echo "\nTest: sn_handle_pl_save() branches\n";
define( 'SN_PLAUSIBLE_TOKEN_OPT', 'sn_pl_token' );
function sn_pl_admin_invalidate_caches() {}
$GLOBALS['__options'] = array( 'sn_pl_token' => 'old' );
pa_eq( 'pl_cleared', sn_handle_pl_save( array( 'sn_pl_token' => 'clear' ) ), "'clear' → pl_cleared" );
pa_eq( false, array_key_exists( 'sn_pl_token', $GLOBALS['__options'] ), 'token option deleted' );
pa_eq( 'pl_unchanged', sn_handle_pl_save( array( 'sn_pl_token' => '' ) ), 'empty → pl_unchanged' );
pa_eq( 'pl_saved', sn_handle_pl_save( array( 'sn_pl_token' => 'real-new-token' ) ), 'real token → pl_saved' );
pa_eq( 'real-new-token', get_option( 'sn_pl_token' ), 'token persisted' );
// Re-submit the MASKED placeholder (••••XXXX) → leave the stored token alone.
// (Before v4.13.1, the byte-truncating substr check persisted the literal bullets.)
pa_eq( 'pl_unchanged', sn_handle_pl_save( array( 'sn_pl_token' => '••••oken' ) ), 'masked placeholder → pl_unchanged' );
pa_eq( 'real-new-token', get_option( 'sn_pl_token' ), 'masked re-submit does NOT clobber the stored token' );

echo "\nTest: sn_handle_monitoring_save() enforces https (Fix C)\n";
pa_reset_store();
// http:// push URL → rejected, cleared, error flash.
pa_eq( 'monitoring_url_not_https', sn_handle_monitoring_save( array( 'uptime_kuma_enabled' => '1', 'uptime_kuma_push_url' => 'http://kuma.example/api/push/x' ) ), 'http url → monitoring_url_not_https' );
pa_eq( '', sn_setting( 'monitoring.uptime_kuma_push_url' ), 'rejected http url cleared (not persisted)' );
// https:// push URL → saved.
pa_reset_store();
pa_eq( 'monitoring_saved', sn_handle_monitoring_save( array( 'uptime_kuma_enabled' => '1', 'uptime_kuma_push_url' => 'https://kuma.example/api/push/x' ) ), 'https url → monitoring_saved' );
pa_eq( 'https://kuma.example/api/push/x', sn_setting( 'monitoring.uptime_kuma_push_url' ), 'https url persisted' );

echo "\nTest: sn_handle_insights_create_draft() — stale rec, success, insert error\n";
$GLOBALS['__transients']     = array();
$GLOBALS['__test_recs']      = array();
$GLOBALS['__test_marked_done'] = array();
unset( $GLOBALS['__test_draft_error'], $GLOBALS['__test_draft_id'] );

// Stale / unknown id → error flash, nothing inserted, nothing marked done.
pa_eq( 'insights_draft_stale', sn_handle_insights_create_draft( array( 'rec_id' => 'gone' ) ), 'unknown rec → insights_draft_stale' );
pa_eq( array(), $GLOBALS['__test_marked_done'], 'stale path does NOT mark anything done' );

// Success path → draft created, rec marked done, edit link stashed in transient.
$GLOBALS['__test_recs']['rec_ok'] = array( 'id' => 'rec_ok', 'type' => 'write_about', 'title' => 'T', 'rationale' => 'r' );
$GLOBALS['__test_draft_id']       = 777;
pa_eq( 'insights_draft_created', sn_handle_insights_create_draft( array( 'rec_id' => 'rec_ok' ) ), 'valid rec → insights_draft_created' );
pa_eq( true, in_array( 'rec_ok', $GLOBALS['__test_marked_done'], true ), 'rec marked done on success' );
$stash = get_transient( sn_insights_draft_result_key() );
pa_eq( true, is_array( $stash ), 'result transient stashed' );
pa_eq( 777, $stash['post_id'], 'transient carries the new draft id' );
pa_eq( true, false !== strpos( $stash['edit_link'], 'post=777' ), 'transient carries the edit link' );

// Insert error → failure flash, NOT marked done.
$GLOBALS['__test_marked_done'] = array();
$GLOBALS['__test_draft_error'] = new PA_WP_Error( 'db_insert_error', 'boom' );
pa_eq( 'insights_draft_failed', sn_handle_insights_create_draft( array( 'rec_id' => 'rec_ok' ) ), 'insert WP_Error → insights_draft_failed' );
pa_eq( array(), $GLOBALS['__test_marked_done'], 'failed insert does NOT mark done' );
unset( $GLOBALS['__test_draft_error'] );

echo "\nTest: sn_handle_music_save() — masked creds + Muso profile (T6)\n";
pa_reset_store();
// Fresh Spotify creds + Muso profile id → saved + persisted.
pa_eq( 'music_saved', sn_handle_music_save( array( 'sn_spotify_id' => 'real-id', 'sn_spotify_secret' => 'real-secret', 'sn_muso_profile' => 'pid-123' ) ), 'fresh creds → music_saved' );
pa_eq( 'real-id', get_option( SN_SPOTIFY_ID_OPT ), 'spotify client id persisted (non-autoloaded)' );
pa_eq( 'real-secret', get_option( SN_SPOTIFY_SECRET_OPT ), 'spotify client secret persisted' );
pa_eq( 'pid-123', get_option( SN_MUSO_PROFILE_OPT ), 'muso profile id persisted' );

// Re-submit the MASKED placeholder (••••XXXX) with the same profile → nothing
// changes, and the stored secret is NOT clobbered (the bug pl_save/cf_save have).
pa_eq( 'music_unchanged', sn_handle_music_save( array( 'sn_spotify_id' => '••••l-id', 'sn_spotify_secret' => '••••cret', 'sn_muso_profile' => 'pid-123' ) ), 'masked placeholders + same profile → music_unchanged' );
pa_eq( 'real-secret', get_option( SN_SPOTIFY_SECRET_OPT ), 'masked re-submit does NOT clobber the secret' );
pa_eq( 'real-id', get_option( SN_SPOTIFY_ID_OPT ), 'masked re-submit does NOT clobber the id' );

// 'clear' removes the option.
pa_eq( 'music_saved', sn_handle_music_save( array( 'sn_spotify_secret' => 'clear' ) ), "'clear' → music_saved" );
pa_eq( false, array_key_exists( SN_SPOTIFY_SECRET_OPT, $GLOBALS['__options'] ), 'secret option deleted on clear' );

// Changing the profile to a new value → music_saved.
pa_reset_store();
update_option( SN_MUSO_PROFILE_OPT, 'pid-old', false );
pa_eq( 'music_saved', sn_handle_music_save( array( 'sn_muso_profile' => 'pid-new' ) ), 'new profile id → music_saved' );
pa_eq( 'pid-new', get_option( SN_MUSO_PROFILE_OPT ), 'profile id updated' );
// Same profile re-submitted → music_unchanged.
pa_eq( 'music_unchanged', sn_handle_music_save( array( 'sn_muso_profile' => 'pid-new' ) ), 'identical profile, no cred change → music_unchanged' );

echo "\nTest: sn_handle_music_sync() drives the sync orchestrator (T6)\n";
$GLOBALS['__music_sync_calls']  = 0;
$GLOBALS['__music_sync_result'] = true;
pa_eq( 'music_synced', sn_handle_music_sync( array() ), 'successful sync → music_synced' );
pa_eq( 1, $GLOBALS['__music_sync_calls'], 'sync handler invokes sn_discography_run_sync once' );
$GLOBALS['__music_sync_result'] = false;
pa_eq( 'music_sync_failed', sn_handle_music_sync( array() ), 'failed sync (last-good kept) → music_sync_failed' );

echo "\nTest: sn_handle_music_save() — featured release (v4.14.0)\n";
pa_reset_store();
pa_eq( 'music_saved', sn_handle_music_save( array( 'sn_music_featured' => 'https://open.spotify.com/album/4m2880jivSbbyEGAKfITCa' ) ), 'valid featured URL → music_saved' );
$feat = get_option( SN_MUSIC_FEATURED_OPT );
pa_eq( 'album', is_array( $feat ) ? $feat['type'] : '', 'featured type parsed + stored' );
pa_eq( '4m2880jivSbbyEGAKfITCa', is_array( $feat ) ? $feat['id'] : '', 'featured id parsed + stored' );

// Invalid URL → error flash, nothing written.
pa_reset_store();
pa_eq( 'music_featured_invalid', sn_handle_music_save( array( 'sn_music_featured' => 'not a spotify link' ) ), 'invalid featured URL → music_featured_invalid' );
pa_eq( false, array_key_exists( SN_MUSIC_FEATURED_OPT, $GLOBALS['__options'] ), 'invalid featured: nothing stored' );

// 'clear' removes the featured option.
$GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'track', 'id' => '6MuumbyTsu4CLaniAN0lBW' );
pa_eq( 'music_saved', sn_handle_music_save( array( 'sn_music_featured' => 'clear' ) ), "'clear' featured → music_saved" );
pa_eq( false, array_key_exists( SN_MUSIC_FEATURED_OPT, $GLOBALS['__options'] ), 'featured cleared' );

// Re-submitting the same featured (the round-tripped open URL) → unchanged.
$GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'album', 'id' => '4m2880jivSbbyEGAKfITCa' );
pa_eq( 'music_unchanged', sn_handle_music_save( array( 'sn_music_featured' => 'https://open.spotify.com/album/4m2880jivSbbyEGAKfITCa' ) ), 'same featured re-submitted → music_unchanged' );

echo "\nTest: sn_handle_music_save() honors the Spotify secret constant lock\n";
define( 'SN_SPOTIFY_CLIENT_SECRET', 'locked-secret' );
pa_reset_store();
sn_handle_music_save( array( 'sn_spotify_secret' => 'attempt-override' ) );
pa_eq( false, array_key_exists( SN_SPOTIFY_SECRET_OPT, $GLOBALS['__options'] ), 'locked secret: no option written when constant is defined' );

// ─── Analytics credential handlers (S2) ──────────────────────────────────────
// Define the option-name constants (mirrors analytics-api.php). Done here (not
// via require_once of analytics-api.php) because the real module uses
// wp_remote_post which isn't available in this CLI harness.
if ( ! defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ) { define( 'SN_CF_ANALYTICS_TOKEN_OPT', 'sn_cf_analytics_token' ); }
if ( ! defined( 'SN_CF_ACCOUNT_ID_OPT' ) )      { define( 'SN_CF_ACCOUNT_ID_OPT', 'sn_cf_account_id' ); }
// SN_ANALYTICS_ERR_KEY is a class const in analytics-api.php (not required in this
// harness); define an equivalent so sn_handle_analytics_test()'s delete_transient()
// call resolves without a PHP fatal.
if ( ! defined( 'SN_ANALYTICS_ERR_KEY' ) )       { define( 'SN_ANALYTICS_ERR_KEY', 'sn_analytics_last_error' ); }

// sn_mask_secret is referenced in flash-messages but not needed inside
// the handler; it's already absent from this harness and that's fine.

// Seam functions — toggled via $GLOBALS so each test controls their return value.
// The real implementations are in inc/analytics-api.php; these thin stubs let the
// handler run in the CLI test environment without network access.
if ( ! function_exists( 'sn_analytics_config' ) ) {
	function sn_analytics_config() {
		return isset( $GLOBALS['__analytics_config'] ) ? $GLOBALS['__analytics_config'] : null;
	}
}
if ( ! function_exists( 'sn_analytics_probe' ) ) {
	function sn_analytics_probe() {
		return isset( $GLOBALS['__analytics_probe'] ) ? (bool) $GLOBALS['__analytics_probe'] : false;
	}
}

echo "\nTest: sn_handle_analytics_save() — fresh credentials\n";
pa_reset_store();
pa_eq(
	'analytics_saved',
	sn_handle_analytics_save( array( 'sn_cf_account_id' => 'acct123', 'sn_cf_analytics_token' => 'tok-abc' ) ),
	'fresh creds → analytics_saved'
);
pa_eq( 'acct123',  get_option( SN_CF_ACCOUNT_ID_OPT ),      'account id persisted' );
pa_eq( 'tok-abc',  get_option( SN_CF_ANALYTICS_TOKEN_OPT ), 'token persisted' );

echo "\nTest: sn_handle_analytics_save() — masked token (unchanged) re-submit\n";
// Store a known token, then re-submit the masked placeholder.
// The stored token must NOT be clobbered and the flash must be 'analytics_unchanged'.
$GLOBALS['__options'][ SN_CF_ANALYTICS_TOKEN_OPT ] = 'real-stored-token';
$GLOBALS['__options'][ SN_CF_ACCOUNT_ID_OPT ]      = 'acct123';
pa_eq(
	'analytics_unchanged',
	sn_handle_analytics_save( array( 'sn_cf_account_id' => 'acct123', 'sn_cf_analytics_token' => '••••-abc' ) ),
	'masked placeholder + same account → analytics_unchanged'
);
pa_eq( 'real-stored-token', get_option( SN_CF_ANALYTICS_TOKEN_OPT ), 'masked re-submit does NOT clobber the stored token' );

echo "\nTest: sn_handle_analytics_save() — 'clear' token\n";
pa_reset_store();
$GLOBALS['__options'][ SN_CF_ANALYTICS_TOKEN_OPT ] = 'tok-to-clear';
pa_eq(
	'analytics_saved',
	sn_handle_analytics_save( array( 'sn_cf_analytics_token' => 'clear' ) ),
	"'clear' token → analytics_saved"
);
pa_eq( false, array_key_exists( SN_CF_ANALYTICS_TOKEN_OPT, $GLOBALS['__options'] ), 'token option deleted on clear' );

echo "\nTest: sn_handle_analytics_save() — 'clear' account id\n";
pa_reset_store();
$GLOBALS['__options'][ SN_CF_ACCOUNT_ID_OPT ] = 'acct-to-clear';
pa_eq(
	'analytics_saved',
	sn_handle_analytics_save( array( 'sn_cf_account_id' => 'clear' ) ),
	"'clear' account id → analytics_saved"
);
pa_eq( false, array_key_exists( SN_CF_ACCOUNT_ID_OPT, $GLOBALS['__options'] ), 'account id option deleted on clear' );

echo "\nTest: sn_handle_analytics_test() — unconfigured, ok, err\n";
$GLOBALS['__analytics_config'] = null;
pa_eq( 'analytics_test_unconfigured', sn_handle_analytics_test( array() ), 'null config → analytics_test_unconfigured' );
$GLOBALS['__analytics_config'] = array( 'account_id' => 'acct', 'token' => 'tok' );
$GLOBALS['__analytics_probe']  = true;
pa_eq( 'analytics_test_ok', sn_handle_analytics_test( array() ), 'probe ok → analytics_test_ok' );
$GLOBALS['__analytics_probe'] = false;
pa_eq( 'analytics_test_err', sn_handle_analytics_test( array() ), 'probe err → analytics_test_err' );

echo "\nTest: sn_handle_analytics_save() — account changed + token masked in one submit\n";
// account CHANGED + token MASKED in one submit → saved; account written, token untouched.
$GLOBALS['__options'][ SN_CF_ACCOUNT_ID_OPT ]      = 'old-acct';
$GLOBALS['__options'][ SN_CF_ANALYTICS_TOKEN_OPT ] = 'real-stored-token';
pa_eq( 'analytics_saved', sn_handle_analytics_save( array( 'sn_cf_account_id' => 'new-acct', 'sn_cf_analytics_token' => '••••oken' ) ), 'account changed + masked token → analytics_saved' );
pa_eq( 'new-acct', get_option( SN_CF_ACCOUNT_ID_OPT ), 'account id updated' );
pa_eq( 'real-stored-token', get_option( SN_CF_ANALYTICS_TOKEN_OPT ), 'masked token NOT clobbered when account changes' );

// Constant-locked test LAST — define() is permanent in-process.
echo "\nTest: sn_handle_analytics_save() — both creds constant-locked\n";
define( 'SN_CF_ANALYTICS_TOKEN', 'x' );
define( 'SN_CF_ACCOUNT_ID', 'y' );
pa_reset_store();
pa_eq(
	'analytics_locked',
	sn_handle_analytics_save( array( 'sn_cf_account_id' => 'attempt', 'sn_cf_analytics_token' => 'attempt' ) ),
	'both constants defined → analytics_locked'
);
pa_eq( array(), $GLOBALS['__options'], 'no option written when both constants are defined (locked)' );

echo "\nTest: sn_admin_post_handlers() map is complete + callable\n";
$map = sn_admin_post_handlers();
pa_eq( 31, count( $map ), 'map has 31 actions' ); // S2: + analytics_save + analytics_test
foreach ( $map as $action => $cb ) {
	pa_eq( true, is_callable( $cb ), "handler for '$action' is callable" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
