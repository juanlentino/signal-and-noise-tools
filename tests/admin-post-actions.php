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
	public function get_error_code() { return $this->code; }
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

// v6.23.0: sn_handle_analytics_exclude_save() sanitizes via the owner-exclusion
// module. The module registers an sn_beacon_enabled filter at load — stub
// add_filter; wp_roles + sanitize_key back the role allow-list. (The filter +
// status fns aren't exercised here; only the sanitizer feeds the handler.)
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); } }
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() {
		return (object) array( 'roles' => array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
		) );
	}
}
require_once __DIR__ . '/../inc/beacon-owner-exclusion.php';

require_once __DIR__ . '/../inc/admin-post-handler.php';
// Task 8: the schedule_run_now / schedule_repurge map entries point at handler
// bodies that live in inc/schedule-admin.php (kept there to keep the subsystem
// cohesive). Require it so the map's "every handler is callable" assertion can
// resolve them, the same pattern as requiring admin-post-actions.php above for
// its own handlers. The file registers no hooks at load and guards its
// sn_schedule_* dependencies with function_exists, so requiring it in isolation
// is safe.
require_once __DIR__ . '/../inc/schedule-admin.php';

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

echo "\nTest: sn_handle_monitoring_save() enforces https (Fix C)\n";
pa_reset_store();
// http:// push URL → rejected, cleared, error flash.
pa_eq( 'monitoring_url_not_https', sn_handle_monitoring_save( array( 'uptime_kuma_enabled' => '1', 'uptime_kuma_push_url' => 'http://kuma.example/api/push/x' ) ), 'http url → monitoring_url_not_https' );
pa_eq( '', sn_setting( 'monitoring.uptime_kuma_push_url' ), 'rejected http url cleared (not persisted)' );
// https:// push URL → saved.
pa_reset_store();
pa_eq( 'monitoring_saved', sn_handle_monitoring_save( array( 'uptime_kuma_enabled' => '1', 'uptime_kuma_push_url' => 'https://kuma.example/api/push/x' ) ), 'https url → monitoring_saved' );
pa_eq( 'https://kuma.example/api/push/x', sn_setting( 'monitoring.uptime_kuma_push_url' ), 'https url persisted' );

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

echo "\nTest: sn_handle_analytics_exclude_save (v6.23.0 owner/role exclusion)\n";
$GLOBALS['__options']['sn_settings'] = array();
sn_setting_reset_cache();
pa_eq( 'analytics_exclude_saved', sn_handle_analytics_exclude_save( array( 'sn_exclude_roles' => array( 'administrator', 'bogus_role' ) ) ), 'exclude-save: returns saved flash on change' );
pa_eq( array( 'administrator' ), sn_setting( 'analytics.exclude_roles', array() ), 'exclude-save: persists only valid (sanitized) roles' );
pa_eq( 'analytics_exclude_unchanged', sn_handle_analytics_exclude_save( array( 'sn_exclude_roles' => array( 'administrator' ) ) ), 'exclude-save: identical set returns unchanged flash' );
pa_eq( 'analytics_exclude_saved', sn_handle_analytics_exclude_save( array() ), 'exclude-save: no checkboxes clears the set' );
pa_eq( array(), sn_setting( 'analytics.exclude_roles', 'SENTINEL' ), 'exclude-save: cleared set persists as empty array' );

// ─── sn_handle_tag_ai_apply — transient allow-list enforcement (v6.39.2) ─
//
// The apply handler must NOT trust client-supplied (post,term) pairs. It must
// load this user's cached suggestion transient and apply ONLY pairs that SN
// actually proposed, on an editable Note (post_type 'post') the user can edit.
$GLOBALS['__test_set_terms_calls'] = array();
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$GLOBALS['__test_set_terms_calls'][] = array(
			'post'  => (int) $object_id,
			'terms' => array_values( array_map( 'intval', (array) $terms ) ),
			'tax'   => $taxonomy,
		);
		return array_map( 'intval', (array) $terms );
	}
}
$GLOBALS['__test_post_types'] = array();
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) {
		return $GLOBALS['__test_post_types'][ (int) $id ] ?? 'post';
	}
}
$GLOBALS['__test_caps'] = array();
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $id = 0 ) {
		$id = (int) $id;
		return array_key_exists( $id, $GLOBALS['__test_caps'] ) ? (bool) $GLOBALS['__test_caps'][ $id ] : true;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}

/** Build a suggestion-transient row matching snt_ai_tag_suggest_impl()'s shape. */
function pa_sugg_row( $post_id, array $term_ids ) {
	$suggested = array();
	foreach ( $term_ids as $tid ) {
		$suggested[] = array( 'term_id' => (int) $tid, 'name' => 'T' . $tid, 'slug' => 't' . $tid );
	}
	return array( 'ok' => true, 'post_id' => (int) $post_id, 'suggested' => $suggested, 'title' => 'Note ' . $post_id );
}

/** Did wp_set_object_terms get called for $pid, and with which term ids? */
function pa_terms_for( $pid ) {
	foreach ( $GLOBALS['__test_set_terms_calls'] as $c ) {
		if ( $c['post'] === (int) $pid ) { return $c['terms']; }
	}
	return null;
}

echo "\nTest: sn_handle_tag_ai_apply() honors the suggestion transient allow-list\n";
$tkey = 'sn_tag_ai_suggestions_' . get_current_user_id();

// Happy path — both suggested terms applied to the suggested Note.
$GLOBALS['__test_set_terms_calls'] = array();
$GLOBALS['__test_post_types']      = array();
$GLOBALS['__test_caps']            = array();
$GLOBALS['__transients'][ $tkey ]  = array( pa_sugg_row( 101, array( 11, 12 ) ) );
pa_eq( 'tag_ai_applied', sn_handle_tag_ai_apply( array( 'assign' => array( 101 => array( 11, 12 ) ) ) ), 'returns tag_ai_applied' );
pa_eq( array( 11, 12 ), pa_terms_for( 101 ), 'both suggested terms applied to the Note' );
pa_eq( false, isset( $GLOBALS['__transients'][ $tkey ] ), 'transient cleared after apply' );

// Forged TERM — a term id that was never suggested for this post is dropped.
$GLOBALS['__test_set_terms_calls'] = array();
$GLOBALS['__transients'][ $tkey ]  = array( pa_sugg_row( 101, array( 11 ) ) );
sn_handle_tag_ai_apply( array( 'assign' => array( 101 => array( 11, 99 ) ) ) );
pa_eq( array( 11 ), pa_terms_for( 101 ), 'unsuggested term 99 intersected out; only 11 applied' );

// Forged POST — a post id absent from the suggestions is rejected entirely.
$GLOBALS['__test_set_terms_calls'] = array();
$GLOBALS['__transients'][ $tkey ]  = array( pa_sugg_row( 101, array( 11 ) ) );
sn_handle_tag_ai_apply( array( 'assign' => array( 202 => array( 11 ) ) ) );
pa_eq( null, pa_terms_for( 202 ), 'post 202 (never suggested) gets no wp_set_object_terms call' );

// Wrong POST TYPE — suggested post is not a Note (e.g. a page) → rejected.
$GLOBALS['__test_set_terms_calls'] = array();
$GLOBALS['__test_post_types']      = array( 303 => 'page' );
$GLOBALS['__transients'][ $tkey ]  = array( pa_sugg_row( 303, array( 11 ) ) );
sn_handle_tag_ai_apply( array( 'assign' => array( 303 => array( 11 ) ) ) );
pa_eq( null, pa_terms_for( 303 ), 'non-Note (page) post rejected even though it was in the cache' );

// No CAP — user cannot edit_post the target → rejected.
$GLOBALS['__test_set_terms_calls'] = array();
$GLOBALS['__test_post_types']      = array();
$GLOBALS['__test_caps']            = array( 404 => false );
$GLOBALS['__transients'][ $tkey ]  = array( pa_sugg_row( 404, array( 11 ) ) );
sn_handle_tag_ai_apply( array( 'assign' => array( 404 => array( 11 ) ) ) );
pa_eq( null, pa_terms_for( 404 ), 'edit_post denied → no wp_set_object_terms call' );

// No transient at all — nothing is applied (can't validate → reject).
$GLOBALS['__test_set_terms_calls'] = array();
$GLOBALS['__test_caps']            = array();
unset( $GLOBALS['__transients'][ $tkey ] );
sn_handle_tag_ai_apply( array( 'assign' => array( 101 => array( 11 ) ) ) );
pa_eq( 0, count( $GLOBALS['__test_set_terms_calls'] ), 'no suggestion cache → nothing applied' );

echo "\nTest: sn_admin_post_handlers() map is complete + callable\n";
$map = sn_admin_post_handlers();
pa_eq( 42, count( $map ), 'map has 42 actions' ); // v5.1.0: +3 indexnow · v5.2.0: +2 analytics (save/test) · v6.0.0: +1 analytics_import · v6.1.0: +1 analytics_export · v6.23.0: +1 analytics_exclude_save · v6.30.0: +1 narration_run · v6.36.0: +1 tag_merge · v6.37.0: +3 tag_ai_suggest/apply + tag_prune_unused · v6.40.0: +2 schedule_run_now/schedule_repurge · v6.51.0: -1 insights_create_draft (advisor no longer prescribes posts) · v7.2.0: +1 security_digest_save
foreach ( $map as $action => $cb ) {
	pa_eq( true, is_callable( $cb ), "handler for '$action' is callable" );
}
pa_eq( true, isset( $map['narration_run'] ) && is_callable( $map['narration_run'] ), 'narration_run handler registered + callable (v6.30.0)' );

// ─── sn_handle_insights_run() — report the REAL failure (v7.0.1) ─────────────
// The bug: EVERY WP_Error collapsed to 'insights_failed' → the flash registry's
// blanket "check that an AI provider is configured" copy, even though AI was
// configured + billing (the weekly digest, same transport, worked). The handler
// must (a) record the real error, (b) send only the genuine ai-unavailable case
// to the configure-AI copy, (c) surface every other (insights-specific) failure,
// (d) clear the stale error after a successful scan.
$GLOBALS['__insights_scan_result']  = null;
$GLOBALS['__insights_stored_error'] = null;
$GLOBALS['__insights_cleared']      = false;
if ( ! function_exists( 'snt_insights_run_scan' ) ) {
	function snt_insights_run_scan( $force = false ) {
		$GLOBALS['__insights_scan_last_force'] = (bool) $force;
		return $GLOBALS['__insights_scan_result'];
	}
}
if ( ! function_exists( 'snt_insights_store_last_error' ) ) {
	function snt_insights_store_last_error( $err ) {
		$GLOBALS['__insights_stored_error'] = is_wp_error( $err )
			? array( 'code' => $err->get_error_code(), 'message' => $err->get_error_message() )
			: 'NON_ERROR';
	}
}
if ( ! function_exists( 'snt_insights_clear_last_error' ) ) {
	function snt_insights_clear_last_error() { $GLOBALS['__insights_cleared'] = true; }
}

echo "\nTest: sn_handle_insights_run() reports the REAL failure, not a blanket 'configure AI'\n";
// Genuine AI-unavailable → the ONE case that keeps the configure-AI copy.
$GLOBALS['__insights_stored_error'] = null;
$GLOBALS['__insights_scan_result']  = new PA_WP_Error( 'snt_insights_ai_unavailable', 'AI client not available. Configure a provider in Settings.' );
pa_eq( 'insights_ai_unavailable', sn_handle_insights_run( array() ), 'ai-unavailable error → insights_ai_unavailable (configure-AI copy is correct here)' );
pa_eq( 'snt_insights_ai_unavailable', $GLOBALS['__insights_stored_error']['code'], 'the real error is stored for the surface' );

// Downstream, insights-specific parse failure → insights_failed (surfaces the
// real error), NOT the misleading "configure AI" copy.
$GLOBALS['__insights_stored_error'] = null;
$GLOBALS['__insights_scan_result']  = new PA_WP_Error( 'snt_insights_invalid_json', 'AI response was not valid JSON.' );
pa_eq( 'insights_failed', sn_handle_insights_run( array() ), 'parse failure → insights_failed (real error, not blamed on AI config)' );
pa_eq( 'snt_insights_invalid_json', $GLOBALS['__insights_stored_error']['code'], 'the real parse error is stored' );

// Transport/runtime failure (shared AI client) → also insights_failed.
$GLOBALS['__insights_scan_result'] = new PA_WP_Error( 'snt_ai_empty_response', 'AI returned an empty response.' );
pa_eq( 'insights_failed', sn_handle_insights_run( array() ), 'empty-response transport error → insights_failed' );

// Success → insights_scanned; the force flag is forwarded; any stale error clears.
$GLOBALS['__insights_cleared']    = false;
$GLOBALS['__insights_scan_result'] = array( 'scanned_at' => 1, 'recommendations' => array() );
pa_eq( 'insights_scanned', sn_handle_insights_run( array( 'force' => '1' ) ), 'success → insights_scanned' );
pa_eq( true, $GLOBALS['__insights_scan_last_force'], 'force flag forwarded to the scan' );
pa_eq( true, $GLOBALS['__insights_cleared'], 'success clears any stale stored error' );

// ─── sn_handle_narration_run() — report the REAL failure (v7.2.2) ────────────
// Same class as the insights v7.0.1 fix: EVERY WP_Error collapsed to
// 'narration_failed' → the blanket configure-AI copy. The handler must store
// the real error, route only the genuine ai-unavailable code to the
// configure-AI copy, and clear the stale error on success.
$GLOBALS['__narration_run_result']  = null;
$GLOBALS['__narration_stored_error'] = null;
$GLOBALS['__narration_cleared']      = false;
if ( ! function_exists( 'snt_narration_run' ) ) {
	function snt_narration_run( $force = false ) { return $GLOBALS['__narration_run_result']; }
}
if ( ! function_exists( 'snt_narration_store_last_error' ) ) {
	function snt_narration_store_last_error( $err ) {
		$GLOBALS['__narration_stored_error'] = is_wp_error( $err )
			? array( 'code' => $err->get_error_code(), 'message' => $err->get_error_message() )
			: 'NON_ERROR';
	}
}
if ( ! function_exists( 'snt_narration_clear_last_error' ) ) {
	function snt_narration_clear_last_error() { $GLOBALS['__narration_cleared'] = true; }
}

echo "\nTest: sn_handle_narration_run() reports the REAL failure, not a blanket 'configure AI'\n";
$GLOBALS['__narration_run_result'] = new PA_WP_Error( 'snt_ai_unavailable', 'AI client not available.' );
pa_eq( 'narration_ai_unavailable', sn_handle_narration_run( array() ), 'ai-unavailable → narration_ai_unavailable (configure-AI copy correct here)' );
pa_eq( 'snt_ai_unavailable', $GLOBALS['__narration_stored_error']['code'], 'the real error is stored' );

$GLOBALS['__narration_stored_error'] = null;
$GLOBALS['__narration_run_result']   = new PA_WP_Error( 'snt_narration_invalid_json', 'AI digest response was not valid JSON.' );
pa_eq( 'narration_failed', sn_handle_narration_run( array() ), 'parse failure → narration_failed (real error, not blamed on AI config)' );
pa_eq( 'snt_narration_invalid_json', $GLOBALS['__narration_stored_error']['code'], 'the real parse error is stored' );

$GLOBALS['__narration_run_result'] = new PA_WP_Error( 'snt_ai_empty_response', 'AI returned an empty response.' );
pa_eq( 'narration_failed', sn_handle_narration_run( array() ), 'empty-response transport error → narration_failed' );

$GLOBALS['__narration_cleared']    = false;
$GLOBALS['__narration_run_result'] = array( 'generated_at' => 1, 'headline' => 'H', 'paragraphs' => array( 'p' ), 'highlights' => array() );
pa_eq( 'narration_generated', sn_handle_narration_run( array( 'force' => '1' ) ), 'success → narration_generated' );
pa_eq( true, $GLOBALS['__narration_cleared'], 'success clears any stale stored error' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
