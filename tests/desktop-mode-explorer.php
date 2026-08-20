<?php
/**
 * Standalone fixture tests for inc/desktop-mode-explorer.php — the WP
 * Explorer (OpenStation "My WordPress") surface (since v12.4.0).
 *
 * Pins the four contracts the module ships with:
 *
 *   1. SHELL-ABSENT NEUTRALITY — with neither naming family installed, the
 *      module loads cleanly, registers NOTHING with the shell, enqueues
 *      nothing, and attaches no inline config. The filters it adds sit on
 *      hooks only the shell ever fires. This is the "plugin must keep
 *      working without OpenStation" guarantee, asserted, not assumed.
 *   2. THE ENTITY CONTRACT — both sections carry the descriptor fields the
 *      shell's Explorer requires (id/label/icon/restPath/kind + the shared
 *      group), Notes scopes wp/v2/posts by the REAL category id and carries
 *      `sn_provenance` in listFields, and every gate (category missing,
 *      store empty, capability absent) removes exactly its own section.
 *      Both filter callbacks are IDEMPOTENT — a hypothetical transition
 *      shim chaining both hook families must not duplicate folders or
 *      companion scripts.
 *   3. THE REST SURFACE — /desktop/discography is manage_options-gated and
 *      returns the store verbatim; the `sn_provenance` field summarizes the
 *      chain (head version, latest status, anchored count, 20-commit cap)
 *      and returns null — never an empty struct — for non-Notes and
 *      chainless Notes.
 *   4. NO MINTING ON READ — the field reads the UID meta raw. The
 *      sn_prov_note_uid() accessor PERSISTS a UUID on first read; a REST
 *      GET must never write post meta, and this harness's update_post_meta
 *      stub records any call so the regression cannot land silently.
 *
 * Run: php tests/desktop-mode-explorer.php
 *
 * @since plugin v12.4.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', __DIR__ . '/../' );
define( 'SNT_VERSION', '12.4.0-test' );

// ── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][ $p ][] = $cb; }

$GLOBALS['__filters'] = array();
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $hook ][ $p ][] = $cb; }
function apply_filters( $hook, $value ) {
	$by_priority = $GLOBALS['__filters'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = $cb( $value );
		}
	}
	return $value;
}

/** Run every callback registered on an action hook, priority-ordered. */
function run_action( $hook ) {
	$by_priority = $GLOBALS['__actions'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $cb ) {
			$cb();
		}
	}
}

$GLOBALS['__scripts'] = array();
function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['__scripts'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}
$GLOBALS['__inline'] = array();
function wp_add_inline_script( $handle, $code, $position = 'after' ) { $GLOBALS['__inline'][ $handle ][] = $code; }

$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['__routes'][ $ns . $route ] = $args; }
$GLOBALS['__rest_fields'] = array();
function register_rest_field( $type, $name, $args = array() ) { $GLOBALS['__rest_fields'][ $type . ':' . $name ] = $args; }

class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}

function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function esc_url_raw( $u ) { return $u; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
function __( $t, $d = null ) { return $t; }

// Capability fixture — per-cap so edit_posts and manage_options can diverge.
$GLOBALS['__caps'] = array();
function current_user_can( $cap ) { return $GLOBALS['__caps'][ $cap ] ?? true; }

// Category fixture.
$GLOBALS['__cat'] = null;
function get_category_by_slug( $slug ) { return $GLOBALS['__cat']; }

// Post-meta fixtures. update_post_meta RECORDS — contract 4 asserts on it.
$GLOBALS['__meta'] = array();
$GLOBALS['__meta_writes'] = array();
function get_post_meta( $post_id, $key = '', $single = false ) { return $GLOBALS['__meta'][ $post_id ][ $key ] ?? ''; }
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['__meta_writes'][] = array( $post_id, $key ); return true; }

// Option store — feeds the REAL inc/discography-store.php required below.
$GLOBALS['__opts'] = array();
function get_option( $name, $default = false ) { return $GLOBALS['__opts'][ $name ] ?? $default; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }

// Provenance fixtures (the real inc/provenance-core.php needs ext-intl and
// half of WP; the FIELD contract only needs these two seams).
$GLOBALS['__is_note'] = array();
function sn_prov_is_note( $post_id ) { return ! empty( $GLOBALS['__is_note'][ $post_id ] ); }
$GLOBALS['__chains'] = array();
function sn_prov_get_chain( $post_id ) { return $GLOBALS['__chains'][ $post_id ] ?? array(); }

// ── Load the real code under test ────────────────────────────────────
require SNT_PATH . 'inc/openstation-compat.php';
require SNT_PATH . 'inc/discography-store.php';
require SNT_PATH . 'inc/desktop-mode-explorer.php';

// ── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}

/** Find a section by id in an entities array, or null. */
function find_entity( $entities, $id ) {
	foreach ( (array) $entities as $entity ) {
		if ( is_array( $entity ) && ( $entity['id'] ?? '' ) === $id ) {
			return $entity;
		}
	}
	return null;
}

// ── Contract 1: shell-absent neutrality ──────────────────────────────
// Nothing from either naming family is defined at this point in the process.
ok( ! snt_os_active(), 'fixture sanity: neither shell family is present yet' );

run_action( 'init' );
ok( ! isset( $GLOBALS['__scripts']['sn-desktop-mode-explorer'] ),
	'SHELL ABSENT: the companion handle is NOT registered — init:5 early-returns on snt_os_active()' );

run_action( 'admin_enqueue_scripts' );
ok( empty( $GLOBALS['__inline'] ),
	'SHELL ABSENT: no inline config is attached — the enqueue hook early-returns' );

// The filters exist but sit on hooks only the shell fires — registering them
// is inert. Both families are registered, per the compat layer's one-pattern
// rule.
ok( isset( $GLOBALS['__filters']['openstation_my_wordpress_entities'] ),
	'entities filter is registered under the post-rename name' );
ok( isset( $GLOBALS['__filters']['desktop_mode_my_wordpress_entities'] ),
	'entities filter is registered under the pre-rename name too' );
ok( isset( $GLOBALS['__filters']['openstation_my_wordpress_window_args'] )
	&& isset( $GLOBALS['__filters']['desktop_mode_my_wordpress_window_args'] ),
	'window-args filter is dual-registered' );

// ── Contract 2: the entity contract ──────────────────────────────────
// Full fixture: category present, store populated, all caps held.
$GLOBALS['__cat'] = (object) array( 'term_id' => 7 );
$GLOBALS['__opts'][ SN_DISCOGRAPHY_OPTION ] = array(
	'entries'     => array(
		array( 'id' => 'ISRC1', 'title' => 'Album One', 'artist' => 'Juan', 'year' => 2025, 'image' => 'https://x/1.jpg' ),
	),
	'count'       => 1,
	'last_synced' => 1755000000,
);

$entities = apply_filters( 'openstation_my_wordpress_entities', array( array( 'id' => 'posts' ) ) );
ok( 3 === count( $entities ), 'both SN sections are appended after the shell built-ins' );

$notes = find_entity( $entities, 'sn-notes' );
ok( is_array( $notes ), 'the Notes section is present' );
ok( 'wp/v2/posts?categories=7' === ( $notes['restPath'] ?? '' ),
	'Notes rides the core posts collection with the category filter IN restPath — the shell\'s folder counter probes restPath and ignores listQuery, so a listQuery-scoped section honestly lists 5 Notes while its tile claims the site\'s whole post count' );
ok( 'post' === ( $notes['kind'] ?? '' ), 'Notes uses the built-in post kind — preview/trash/locks come free' );
ok( ! isset( $notes['listQuery'] ),
	'no listQuery duplicate of the restPath filter — two spellings of one scope would be free to diverge' );
ok( in_array( 'sn_provenance', (array) ( $notes['listFields'] ?? array() ), true ),
	'sn_provenance survives the shell\'s _fields stripping via listFields' );

$albums = find_entity( $entities, 'sn-albums' );
ok( is_array( $albums ), 'the Discography section is present' );
ok( 'signal-noise/album' === ( $albums['kind'] ?? '' ), 'Discography declares the custom kind the bundle registers' );
ok( false === ( $albums['thumbnails'] ?? null ), 'Discography disables featured-image thumbnails — cover art is the renderer\'s job' );

foreach ( array( 'notes' => $notes, 'albums' => $albums ) as $which => $section ) {
	ok( 'plugin:signal-and-noise-tools' === ( $section['group'] ?? '' )
		&& 'Signal & Noise' === ( $section['groupLabel'] ?? '' )
		&& 15 === ( $section['groupOrder'] ?? 0 ),
		"the $which section nests in the shared Signal & Noise folder (owner-resolver id convention, order 15)" );
}

// Idempotence: a transition shim chaining both hook names hands our callback
// an array that ALREADY holds our sections. It must not duplicate them.
$twice = apply_filters( 'desktop_mode_my_wordpress_entities', $entities );
ok( count( $twice ) === count( $entities ),
	'IDEMPOTENT: re-filtering an array already carrying both sections adds nothing' );

// Gates remove exactly their own section.
$GLOBALS['__cat'] = null;
$gated = apply_filters( 'openstation_my_wordpress_entities', array() );
ok( null === find_entity( $gated, 'sn-notes' ) && null !== find_entity( $gated, 'sn-albums' ),
	'no Notes category (surfaces not seeded yet) → only the Notes section is absent' );
$GLOBALS['__cat'] = (object) array( 'term_id' => 7 );

$GLOBALS['__opts'][ SN_DISCOGRAPHY_OPTION ]['entries'] = array();
$gated = apply_filters( 'openstation_my_wordpress_entities', array() );
ok( null !== find_entity( $gated, 'sn-notes' ) && null === find_entity( $gated, 'sn-albums' ),
	'empty discography store → only the Discography section is absent (no useless empty folder)' );
$GLOBALS['__opts'][ SN_DISCOGRAPHY_OPTION ]['entries'] = array( array( 'id' => 'ISRC1', 'title' => 'Album One' ) );

$GLOBALS['__caps'] = array( 'edit_posts' => true, 'manage_options' => false );
$gated = apply_filters( 'openstation_my_wordpress_entities', array() );
ok( null !== find_entity( $gated, 'sn-notes' ) && null === find_entity( $gated, 'sn-albums' ),
	'an editor without manage_options gets Notes but never a Discography folder they cannot fetch' );

$GLOBALS['__caps'] = array( 'edit_posts' => false, 'manage_options' => false );
$gated = apply_filters( 'openstation_my_wordpress_entities', array() );
ok( array() === $gated, 'no capabilities → no sections at all' );
$GLOBALS['__caps'] = array();

// Window args: companion script appended, existing companions preserved,
// idempotent, non-array passthrough.
$args = apply_filters( 'openstation_my_wordpress_window_args', array( 'scripts' => array( 'os-my-wordpress-woocommerce' ) ) );
ok( array( 'os-my-wordpress-woocommerce', 'sn-desktop-mode-explorer' ) === $args['scripts'],
	'the companion handle is APPENDED — other integrations\' companions survive' );
$args = apply_filters( 'desktop_mode_my_wordpress_window_args', $args );
ok( 1 === count( array_keys( $args['scripts'], 'sn-desktop-mode-explorer', true ) ),
	'IDEMPOTENT: re-filtering does not append the handle twice' );
ok( 'nope' === apply_filters( 'openstation_my_wordpress_window_args', 'nope' ),
	'a non-array window-args value passes through untouched' );

// ── Contract 3: the REST surface ─────────────────────────────────────
run_action( 'rest_api_init' );
$route = $GLOBALS['__routes']['signal-noise/v1/desktop/discography'] ?? null;
ok( is_array( $route ), 'the discography route is registered' );
$GLOBALS['__caps'] = array( 'manage_options' => false );
ok( is_callable( $route['permission_callback'] ?? null ) && false === $route['permission_callback'](),
	'the route is manage_options-gated — matching its /desktop/* siblings' );
$GLOBALS['__caps'] = array();

$res     = snt_explorer_discography_payload();
$payload = $res->get_data();
ok( 'Album One' === ( $payload['entries'][0]['title'] ?? '' )
	&& 1 === $payload['count'] && 1755000000 === $payload['last_synced'],
	'the payload is the store verbatim: entries + count + last_synced' );
ok( '1' === ( $res->headers['X-WP-Total'] ?? null ) && '1' === ( $res->headers['X-WP-TotalPages'] ?? null ),
	'X-WP-Total/-TotalPages ride the response — the shell\'s folder counter reads the COUNT exclusively from that header, and without it the tile said 0 over a full shelf' );

$field = $GLOBALS['__rest_fields']['post:sn_provenance'] ?? null;
ok( is_array( $field ) && is_callable( $field['get_callback'] ?? null ),
	'the sn_provenance REST field is registered on post' );

// Field: null for non-Notes and for chainless Notes — never an empty struct.
ok( null === snt_explorer_provenance_field( array( 'id' => 10 ) ),
	'a non-Note post yields null' );
$GLOBALS['__is_note'][11] = true;
ok( null === snt_explorer_provenance_field( array( 'id' => 11 ) ),
	'a Note with no chain yields null — "unsigned" is not "signed zero times"' );

// A real chain: genesis v0 + three commits, mixed statuses.
$GLOBALS['__is_note'][12] = true;
$GLOBALS['__meta'][12]['_sn_prov_uid'] = 'uuid-12';
$GLOBALS['__chains'][12] = array(
	array( 'version' => 0, 'status' => 'genesis', 'committed_at' => '2026-01-01T00:00:00Z', 'content_hash' => 'aaa' ),
	array( 'version' => 1, 'status' => 'confirmed', 'committed_at' => '2026-01-02T00:00:00Z', 'content_hash' => 'bbb' ),
	array( 'version' => 2, 'status' => 'confirmed', 'committed_at' => '2026-01-03T00:00:00Z', 'content_hash' => 'ccc' ),
	array( 'version' => 3, 'status' => 'pending', 'committed_at' => '2026-01-04T00:00:00Z', 'content_hash' => 'ddd' ),
);
$prov = snt_explorer_provenance_field( array( 'id' => 12 ) );
ok( 3 === $prov['versions'], 'versions reports the chain head version (genesis v0 does not inflate it)' );
ok( 'pending' === $prov['status'], 'status is the NEWEST commit\'s status' );
ok( 2 === $prov['anchored'], 'anchored counts confirmed commits only' );
ok( 'uuid-12' === $prov['uid'], 'the ledger UID rides along when present' );
ok( 4 === count( $prov['commits'] ) && 'ddd' === $prov['commits'][3]['content_hash'],
	'commits preserve order, newest last, with their hashes' );

// The 20-commit cap keeps order and keeps the NEWEST.
$GLOBALS['__is_note'][13] = true;
$long = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$long[] = array( 'version' => $i, 'status' => 'confirmed', 'committed_at' => '2026-01-01T00:00:00Z', 'content_hash' => 'h' . $i );
}
$GLOBALS['__chains'][13] = $long;
$prov = snt_explorer_provenance_field( array( 'id' => 13 ) );
ok( 20 === count( $prov['commits'] ) && 11 === $prov['commits'][0]['version'] && 30 === $prov['commits'][19]['version'],
	'the commit list is capped at the NEWEST 20; versions still reports the full head (30)' );
ok( 30 === $prov['versions'], 'the cap is visible, not silent — the head version survives it' );

// ── Contract 4: no minting on read ───────────────────────────────────
$GLOBALS['__is_note'][14] = true;
$GLOBALS['__chains'][14] = array(
	array( 'version' => 1, 'status' => 'unanchored', 'committed_at' => '2026-02-01T00:00:00Z', 'content_hash' => 'eee' ),
);
$prov = snt_explorer_provenance_field( array( 'id' => 14 ) );
ok( null === $prov['uid'], 'a Note without a persisted UID reports null — no fabricated key' );
ok( array() === $GLOBALS['__meta_writes'],
	'NO MINTING ON READ: the field never writes post meta (sn_prov_note_uid() would have)' );

// ── Contract 1b: the shell APPEARS mid-process → registration works ──
// Defining one post-rename function flips snt_os_active(); replaying the
// captured init callbacks must now register the handle with the deps the
// module promises (wp-hooks only — the bundle is self-sufficient by design).
// The declarations are CONDITIONAL so they bind at runtime, HERE — a bare
// top-level `function` is hoisted at compile time and would have made the
// shell "present" from line 1, hollowing out every shell-absent assertion.
if ( ! function_exists( 'openstation_register_command' ) ) {
	function openstation_register_command( $args = array() ) {}
	function openstation_is_enabled() { return true; }
}
ok( snt_os_active() && snt_os_is_post_rename(), 'fixture sanity: the post-rename family is now live' );
run_action( 'init' );
$script = $GLOBALS['__scripts']['sn-desktop-mode-explorer'] ?? null;
ok( is_array( $script ), 'SHELL PRESENT: the companion handle registers on init' );
ok( array( 'wp-hooks' ) === ( $script['deps'] ?? null ),
	'deps are wp-hooks ONLY — the lazy loader injects by URL and never walks the graph, so the bundle must not lean on siblings' );

run_action( 'admin_enqueue_scripts' );
$inline = implode( '', $GLOBALS['__inline']['sn-desktop-mode-explorer'] ?? array() );
ok( false !== strpos( $inline, 'window.snExplorerConfig=' ), 'the inline config blob is attached to the handle' );
// json_encode escapes slashes (real wp_json_encode does too), so match the
// escaped form.
ok( false !== strpos( $inline, 'signal-noise\/v1\/desktop\/discography' ),
	'an admin\'s config carries the discography endpoint' );

$GLOBALS['__inline'] = array();
$GLOBALS['__caps']   = array( 'edit_posts' => true, 'manage_options' => false );
run_action( 'admin_enqueue_scripts' );
$inline = implode( '', $GLOBALS['__inline']['sn-desktop-mode-explorer'] ?? array() );
ok( false !== strpos( $inline, '"discographyUrl":""' ),
	'a non-admin\'s config carries an EMPTY discography URL — explicit, not absent' );
$GLOBALS['__caps'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
