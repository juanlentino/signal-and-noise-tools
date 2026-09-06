<?php
/**
 * Native window leaf: Tools → Provenance (apps/sn-dashboard/parts/leaves/tools-provenance.php).
 *
 * The oracle is the classic leaf (`sn_admin_render_provenance_section()`,
 * inc/provenance-admin.php:266) plus the two conditional fieldsets it pulls
 * in (inc/provenance-chain-backfill.php, inc/provenance-rotation.php). Both
 * sides run against the SAME fixture readers this file stubs directly — the
 * pattern the exemplar (tests/os-leaf-security-login.php) uses, applied to a
 * leaf whose real readers (sn_prov_get_chain(), sn_prov_worker_url(), …) live
 * three files deep and are out of scope to drag in whole.
 *
 * The five forms here post to admin-post.php with a literal `action` field
 * (not the shared `sn_action` table), so this suite pins field names via its
 * own `prov_names()`/`prov_actions()` rather than the harness's
 * `snt_leaf_actions()` (which only recognises `sn_action` / `os-arg-action`).
 *
 * Run: php tests/os-leaf-tools-provenance.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── Constants the classic readers rely on.
if ( ! defined( 'SN_PROV_UID_META' ) ) {
	define( 'SN_PROV_UID_META', '_sn_prov_uid' );
}
if ( ! defined( 'SN_PROV_GENESIS_OPT' ) ) {
	define( 'SN_PROV_GENESIS_OPT', 'sn_prov_genesis' );
}
if ( ! defined( 'SN_PROV_DID_TEST' ) ) {
	define( 'SN_PROV_DID_TEST', true ); // Skips provenance-rotation.php's own add_action() registration; harmless here.
}

// ── WP stubs the harness does not provide.
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return (object) array( 'ID' => (int) $id );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $e = 0 ) {
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) {
		return true;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array() ) {
		return true;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}

// ── The leaf's own fixture readers (the classic renderers' dependencies,
// three files deep — stubbed at the boundary rather than dragged in whole).
$GLOBALS['__prov_ids']    = array();
$GLOBALS['__prov_chains'] = array();
$GLOBALS['__prov_kind']   = array();
function sn_prov_subject_post_types() { return array( 'post' ); }
function sn_prov_subject_kind( $post ) { return $GLOBALS['__prov_kind'][ $post->ID ] ?? 'note'; }
function sn_prov_get_chain( $id ) { return $GLOBALS['__prov_chains'][ $id ] ?? array(); }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['__prov_uid'][ (int) $id ] ?? ''; }
function sn_prov_ledger_note_url( $uid, $kind = 'note' ) { return '' !== $uid ? 'https://ledger.test/notes/' . $uid : ''; }
function sn_prov_worker_url() { return $GLOBALS['__prov_worker_url'] ?? ''; }
function sn_prov_hmac_secret() { return $GLOBALS['__prov_hmac'] ?? ''; }
function sn_prov_pubkey_b64() { return $GLOBALS['__prov_pubkey'] ?? ''; }
function sn_prov_key_id() { return $GLOBALS['__prov_key_id'] ?? ''; }
function sn_prov_key_introduced_at() { return $GLOBALS['__prov_key_intro'] ?? ''; }
function sn_prov_key_config_source( $const, $option ) { return $GLOBALS['__prov_key_source'] ?? 'default'; }
function sn_prov_worker_version() { return $GLOBALS['__prov_worker_ver'] ?? ''; }
function sn_prov_next_key_commitment() { return $GLOBALS['__prov_commitment'] ?? null; }
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) { return $GLOBALS['__prov_ids']; }
}

// ── The classic-renderer dependencies that live outside the plugin's own
// provenance files (inc/admin-shell.php — explicitly out of scope).
function sn_admin_glance_grid( $cards ) {
	foreach ( (array) $cards as $c ) {
		echo '<div class="glance">' . esc_html( (string) ( $c['label'] ?? '' ) ) . ':' . esc_html( (string) ( $c['value'] ?? '' ) ) . '</div>';
	}
}
function sn_admin_shell_open() { echo '<div class="shell-main">'; }
function sn_admin_shell_rail( $heading = '' ) { echo '</div><div class="shell-rail"><h2>' . esc_html( $heading ) . '</h2>'; }
function sn_admin_shell_close() { echo '</div>'; }

require SNT_PATH . 'inc/provenance-admin.php';
require SNT_PATH . 'inc/provenance-chain-backfill.php';
require SNT_PATH . 'inc/provenance-rotation.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-provenance.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Every literal name="…" attribute in a markup blob, sorted+unique. */
function prov_names( $html ) {
	preg_match_all( '/\sname=(["\'])([^"\']+)\1/', (string) $html, $m );
	$names = array_values( array_unique( $m[2] ) );
	sort( $names );
	return $names;
}

/** Every admin-post `action` value: classic name="action", kit os-arg-action. */
function prov_actions( $html ) {
	preg_match_all( '/name=(["\'])action\1[^>]*value=(["\'])([^"\']+)\2|value=(["\'])([^"\']+)\4[^>]*name=(["\'])action\6|os-arg-action=(["\'])([^"\']+)\7/', (string) $html, $m );
	$out = array_values( array_unique( array_filter( array_merge( $m[3], $m[5], $m[8] ) ) ) );
	sort( $out );
	return $out;
}

/** snt_leaf_paint() with a `params` bag standing in for the flash query. */
function prov_paint( array $params = array() ) {
	return snt_leaf_paint( 'tools', 'provenance', array( 'params' => $params ) );
}

// ────────────────────────────────────────────────────────────────────────
// 1. Registration.
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/provenance'] ), 'the painter is registered under tools/provenance' );

// ────────────────────────────────────────────────────────────────────────
// 2. Baseline fixture: nothing configured, no chains, no candidates, no commitment.
$classic = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
$kit     = prov_paint();
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok(
	prov_names( $classic ) === prov_names( $kit ),
	'hidden field names match the classic forms: ' . implode( ',', prov_names( $kit ) ) . ' (classic: ' . implode( ',', prov_names( $classic ) ) . ')'
);
ok(
	array( 'sn_prov_reanchor', 'sn_prov_runsweep', 'sn_prov_stage_key' ) === prov_actions( $kit )
	&& prov_actions( $classic ) === prov_actions( $kit ),
	'baseline actions match the classic forms (no candidates yet, so no backfill action): ' . implode( ',', prov_actions( $kit ) )
);
ok( false !== strpos( $kit, 'os-arg-pipeline="admin-post"' ), 'every admin-post form declares the admin-post pipeline' );
ok( false !== strpos( $kit, 'No contact yet' ) && false !== strpos( $kit, 'Not anchored' ), 'the glance hero shows an unreached Worker and an unanchored genesis' );
ok( false !== strpos( $kit, 'Loading anchor status…' ) || false !== strpos( $kit, '&hellip;' ) || false !== strpos( $kit, 'Loading anchor status' ), 'the empty commits table carries the classic loading copy' );
ok( false !== strpos( $kit, '✗ Not set' ), 'unconfigured Worker URL/HMAC/pubkey read as not set' );
ok( false !== strpos( $kit, 'Publish a commitment to the staged key' ) && false === strpos( $kit, 'Rotate to the committed key' ), 'no commitment: only the stage-key button is offered' );

// ────────────────────────────────────────────────────────────────────────
// 3. Rich fixture: configured Worker, a pending + a confirmed commit, a
// signing key with a source, a genesis root, a published commitment, and
// backfill candidates — every state at once.
$GLOBALS['__prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__prov_hmac']        = 'secret';
$GLOBALS['__prov_pubkey']      = 'AAAAB3NzaC1yc2EAAAADAQAB';
$GLOBALS['__prov_worker_ver']  = '1.9.0';
$GLOBALS['__prov_key_id']      = 'key-2026-09';
$GLOBALS['__prov_key_intro']   = '2026-09-01';
$GLOBALS['__prov_key_source']  = 'option';
$GLOBALS['__options'][ SN_PROV_GENESIS_OPT ] = array( 'status' => 'pending', 'root' => str_repeat( 'ab', 20 ) );
$GLOBALS['__prov_commitment']  = array( 'value' => str_repeat( 'cd', 20 ), 'committed_at' => '2026-09-05' );
$GLOBALS['__prov_ids']         = array( 101, 102, 103 );
$GLOBALS['__prov_kind'][101]   = 'note';
$GLOBALS['__prov_kind'][102]   = 'note';
$GLOBALS['__prov_kind'][103]   = ''; // v13.69.1: an unresolved subject is never a candidate.
$GLOBALS['__prov_chains'][101] = array( array( 'version' => 1, 'status' => 'pending', 'committed_at' => '2026-09-04T10:00:00Z' ) );
$GLOBALS['__prov_chains'][102] = array( array( 'version' => 1, 'status' => 'confirmed', 'confirmed_at' => '2026-09-01T10:00:00Z' ) );
$GLOBALS['__prov_chains'][103] = array();

$classic = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
$kit     = prov_paint();

ok(
	prov_names( $classic ) === prov_names( $kit ),
	'rich fixture: hidden field names still match: ' . implode( ',', prov_names( $kit ) ) . ' (classic: ' . implode( ',', prov_names( $classic ) ) . ')'
);
ok(
	array( 'sn_prov_chain_backfill', 'sn_prov_rotate_key', 'sn_prov_runsweep' ) === prov_actions( $kit ),
	'rich fixture: backfill + rotate-key actions appear alongside runsweep: ' . implode( ',', prov_actions( $kit ) )
);
// The classic leaf still MARKS UP a `name="action" value="sn_prov_reanchor"`
// hidden field here — it renders the re-anchor form unconditionally and only
// disables its submit button — so the raw action set differs by exactly that
// one inert entry. Confirmed disabled (not merely present) before treating
// the kit's omission as equivalent rather than a dropped action.
ok(
	array( 'sn_prov_chain_backfill', 'sn_prov_reanchor', 'sn_prov_rotate_key', 'sn_prov_runsweep' ) === prov_actions( $classic ),
	'classic still carries a (disabled) sn_prov_reanchor field while genesis is pending: ' . implode( ',', prov_actions( $classic ) )
);
ok(
	1 === preg_match( '/name="action" value="sn_prov_reanchor"[^>]*\/>\s*<button type="submit" class="button" disabled>/', $classic ),
	'confirmed the classic sn_prov_reanchor submit is actually disabled while pending, not merely present — the kit form is withheld instead, not dropped'
);
ok( false !== strpos( $kit, '✓ Configured' ), 'configured Worker URL/HMAC/pubkey read as configured' );
ok( false !== strpos( $kit, '1.9.0' ), 'the Worker version is shown' );
ok( false !== strpos( $kit, 'key-2026-09' ) && false !== strpos( $kit, 'site option' ), 'the signing key id and its source are shown' );
ok( false !== strpos( $kit, 'Reachable' ), 'a pending/confirmed commit makes the Worker read reachable' );
ok( false !== strpos( $kit, 'Pending' ) && false !== strpos( $kit, 'Confirmed' ), 'the genesis pill reads Pending' );
ok( false !== strpos( $kit, 'Already anchored: nothing to re-anchor.' ) && false === strpos( $kit, 'name="action" value="sn_prov_reanchor"' ), 'a pending genesis omits the re-anchor form' );
ok( false !== strpos( $kit, 'cdcdcdcdcdcdcdcd…' ), 'the published commitment is truncated the same way the classic root is' );
ok( false !== strpos( $kit, 'Rotate to the committed key' ) && false === strpos( $kit, 'Publish a commitment to the staged key' ), 'a published commitment switches to the rotate-key button' );
ok( false !== strpos( $kit, '2 published subjects' ) || false !== strpos( $kit, sprintf( '%s published subjects', number_format_i18n( 2 ) ) ), 'exactly the 2 resolved-kind candidates are counted (the unresolved-kind post is excluded)' );
ok( false !== strpos( $kit, 'https://ledger.test/notes/' ) || false !== strpos( $kit, '&#8212;' ) || false !== strpos( $kit, '—' ), 'the commits table carries a ledger reference per row' );

// ────────────────────────────────────────────────────────────────────────
// 4. Escaping: a hostile signing-key id and a hostile genesis root never
// reach the markup raw.
$GLOBALS['__prov_key_id'] = '"><script>x</script>';
$GLOBALS['__options'][ SN_PROV_GENESIS_OPT ]['root'] = '<script>evil()</script>' . str_repeat( 'ab', 20 );
$kit = prov_paint();
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, '&lt;script&gt;x&lt;/script&gt;' ), 'a hostile signing-key id is escaped' );
ok( false === strpos( $kit, '<script>evil()</script>' ), 'a hostile genesis root is escaped' );
$GLOBALS['__prov_key_id'] = 'key-2026-09';
$GLOBALS['__options'][ SN_PROV_GENESIS_OPT ]['root'] = str_repeat( 'ab', 20 );

// ────────────────────────────────────────────────────────────────────────
// 5. Flash states, carried as state('params') rather than $_GET (a window
// never has a real query string — inc/openstation-host.php snt_os_host_params()).
$kit = prov_paint( array( 'sn_prov_reanchor' => 'ok' ) );
ok( false !== strpos( $kit, 'Re-anchor dispatched' ) && false !== strpos( $kit, 'tone="success"' ), 'a sn_prov_reanchor=ok param paints the dispatched notice' );

$kit = prov_paint( array( 'sn_prov_reanchor' => 'fail' ) );
ok( false !== strpos( $kit, 'Re-anchor failed' ) && false !== strpos( $kit, 'The Worker rejected the dispatch' ), 'a sn_prov_reanchor=fail param gives the config-aware failure copy (Worker IS configured here)' );

// get_transient() is hard-fixed to `false` by the shared harness, so the
// per-user sweep-result transient can never be populated here — the flag
// says 'ok' but the read-back result carries no 'ok' key, which both the
// classic leaf and this leaf correctly render as a failed sweep (config-aware:
// the Worker IS configured in this fixture, so it blames the Worker, not
// missing constants).
$kit = prov_paint( array( 'sn_prov_swept' => 'ok' ) );
ok( false !== strpos( $kit, 'Sweep failed' ) && false !== strpos( $kit, 'Could not reach the Worker, or it rejected the request.' ), 'a sn_prov_swept=ok param with no readable transient (harness caps get_transient at false) renders the config-aware sweep-failed copy' );

// ────────────────────────────────────────────────────────────────────────
// 6. Empty state: no candidates and no backfill result — the section is
// absent entirely, exactly as the classic leaf ("disappears after a clean
// import").
$GLOBALS['__prov_ids'] = array();
$kit     = prov_paint();
$classic = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
ok( false === strpos( $kit, 'Ledger backfill' ) && false === strpos( $classic, 'Ledger backfill' ), 'no candidates: the backfill section is painted on neither side' );

// ────────────────────────────────────────────────────────────────────────
// 7. Unreached Worker + unconfigured constants: the reanchor failure copy
// switches to the unconfigured-constants line instead of the Worker-rejected
// line (config-aware, per sn_prov_admin_render_reanchor_notice()).
$GLOBALS['__prov_worker_url'] = '';
$GLOBALS['__prov_hmac']       = '';
$GLOBALS['__prov_pubkey']     = '';
$kit = prov_paint( array( 'sn_prov_reanchor' => 'fail' ) );
ok( false !== strpos( $kit, 'Set the SN_PROV_* constants in wp-config first.' ), 'unconfigured constants: the re-anchor failure names the missing constants instead of blaming the Worker' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
