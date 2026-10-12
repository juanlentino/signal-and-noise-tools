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
 * The five writes here post to admin-post.php with a literal `action` field
 * (not the shared `sn_action` table): classic as a hidden input, the kit as
 * a one-click `<os-button os-arg-action os-arg-nonce
 * os-arg-pipeline="admin-post">` (#1614). So this suite pins actions via its
 * own `prov_actions()` on both sides rather than the harness's
 * `snt_leaf_actions()` (which only recognises `sn_action` / `os-arg-action`).
 *
 * Run: php tests/os-leaf-tools-provenance.php
 */
require_once __DIR__ . '/lib/site-timezone-stub.php'; // 14.7.4: the site's zone, not UTC

// A transient store, declared BEFORE the harness (whose get_transient() is a
// hard `false`): the sweep-result flag hygiene pin below needs a transient
// that is readable exactly once, the way the real per-user transient is.
$GLOBALS['__transients'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

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
	// numberposts honored: the Commits table reads the newest 100 subjects,
	// the tiles read every one (2026-10-12: the cut is counted, not silent).
	function get_posts( $args = array() ) {
		$n = (int) ( $args['numberposts'] ?? -1 );
		return $n > 0 ? array_slice( $GLOBALS['__prov_ids'], 0, $n ) : $GLOBALS['__prov_ids'];
	}
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
// #1614: a one-click write is a button, not an os-form with its header and
// fields hidden by CSS; each carries its handler's OWN nonce, never the
// shared one, and declares the admin-post pipeline on the button itself.
ok( false === strpos( $kit, '<os-form' ), 'no os-form on the leaf: every write is a one-click button' );
ok( 3 === substr_count( $kit, '<os-button' ) && 3 === substr_count( $kit, 'os-action="post"' ), 'baseline: three post buttons, one per write' );
foreach ( array( 'sn_prov_reanchor', 'sn_prov_runsweep', 'sn_prov_stage_key' ) as $a ) {
	ok( 1 === preg_match( '/<os-button[^>]*os-action="post"[^>]*os-arg-action="' . $a . '"[^>]*os-arg-nonce="nonce-' . $a . '"[^>]*os-arg-pipeline="admin-post"/', $kit ), $a . ' is a post button carrying its own nonce and the admin-post pipeline' );
}
ok( false === strpos( $kit, 'name="_wpnonce"' ), 'no hidden input carries any nonce: each button carries its own as an argument' );
$css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
ok( false === strpos( $css, 'snt-provenance-action' ), 'the CSS that hid the os-form chrome is gone' );
ok(
	array( 'sn_prov_reanchor', 'sn_prov_runsweep', 'sn_prov_stage_key' ) === prov_actions( $kit )
	&& prov_actions( $classic ) === prov_actions( $kit ),
	'baseline actions match the classic forms (no candidates yet, so no backfill action): ' . implode( ',', prov_actions( $kit ) )
);
ok( false !== strpos( $kit, 'os-arg-pipeline="admin-post"' ), 'every admin-post form declares the admin-post pipeline' );
ok( false !== strpos( $kit, 'No answer yet' ) && false !== strpos( $kit, 'Not anchored' ), 'the glance hero shows an unreached Worker and an unanchored genesis' );
ok( false !== strpos( $kit, 'label="Not sent"' ) && false !== strpos( $kit, 'nothing has been sent' ), '2026-10-12: a Not sent tile, and a Worker that was never asked says so instead of a warn "idle"' );
// 14.7.2: a window has no poller, so its empty table is a STATE (nothing
// pending), never a wait. The classic pre-hydration copy read as a stall the
// first time the queue drained.
ok( false !== strpos( $kit, 'No pending proofs' ), 'the empty commits table says nothing is pending' );
ok( false === strpos( $kit, 'Every commit is anchored' ), '14.7.3: and claims nothing about intactness — anchored is not intact, and this table measures neither' );
// 14.7.3: when the integrity sweep's last reading has failures, the empty
// table SAYS SO and dates the reading, instead of sitting beside an Attention
// queue full of twin drift while reading as "all is well" (2026-09-14).
if ( ! function_exists( 'sn_prov_integrity_state' ) ) { function sn_prov_integrity_state() { return $GLOBALS['__prov_integrity'] ?? null; } }
$GLOBALS['__prov_integrity'] = array( 'failed' => 3, 'swept_at' => 1789372828 );
$kit_failing = prov_paint();
ok( false !== strpos( $kit_failing, 'reports 3 subjects failing' ) && false !== strpos( $kit_failing, 'September 14, 2026 4:00 am EDT' ) && false !== strpos( $kit_failing, 'Trust checks' ),
	'with 3 failing in the last sweep, the empty table names the count, dates the reading in the SITE timezone and its date format (08:00 UTC, 4:00 am EDT) and points at Trust checks' );
ok( false !== strpos( $kit_failing, 'The Provenance integrity check' ) && false === strpos( $kit_failing, 'integrity sweep' ), '2026-10-12: the check goes by the one name Health and Trust checks use' );
$GLOBALS['__prov_integrity'] = array( 'failed' => 0, 'swept_at' => 1789372828 );
ok( false === strpos( prov_paint(), 'failing' ), 'a clean sweep adds nothing: the sentence stays about pending proofs' );
$GLOBALS['__prov_integrity'] = null;
ok( false === strpos( $kit, 'Loading anchor status' ), 'and never borrows the classic page\'s pre-hydration "Loading…" (a window has no poller to finish it)' );
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
$GLOBALS['__prov_uid'][101]    = 'uid-101'; // 2026-10-12: a row with a ledger record, so the link cell is exercised.
$GLOBALS['__prov_chains'][102] = array( array( 'version' => 1, 'status' => 'confirmed', 'confirmed_at' => '2026-09-01T10:00:00Z' ) );
$GLOBALS['__prov_chains'][103] = array();

$classic = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
$kit     = prov_paint();

ok( false === strpos( $kit, '<os-form' ) && 3 === substr_count( $kit, 'os-arg-pipeline="admin-post"' ), 'rich fixture: still no os-form, three admin-post buttons' );
ok( false !== strpos( $kit, 'os-arg-action="sn_prov_rotate_key" os-arg-nonce="nonce-sn_prov_rotate_key"' ), 'rich fixture: the rotate button carries the rotate handler`s own nonce' );
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
ok( 1 === preg_match( '/<os-stat value="Answered" label="Worker" caption="last answered September 4, 2026 6:00 am EDT"/', $kit ) && false === strpos( $kit, 'Reachable' ) && false === strpos( $kit, 'online' ), 'a pending/confirmed commit makes the Worker read "Answered", its caption dating the last answer in the site format; it never says "online" (inferred, not pinged)' );
ok( false !== strpos( $kit, 'Pending' ) && false !== strpos( $kit, 'Confirmed' ), 'the genesis pill reads Pending' );
ok( false !== strpos( $kit, 'Already anchored: nothing to re-anchor.' ) && false === strpos( $kit, 'name="action" value="sn_prov_reanchor"' ), 'a pending genesis omits the re-anchor form' );
ok( false !== strpos( $kit, 'cdcdcdcdcdcdcdcd…' ), 'the published commitment is truncated the same way the classic root is' );
ok( false !== strpos( $kit, 'committed September 5, 2026' ) && false !== strpos( $classic, 'committed September 5, 2026' ) && false === strpos( $kit, 'committed 2026-09-05' ), '2026-10-12: the commitment date reads in the site\'s date format on both surfaces, never the raw ISO date' );
ok( false !== strpos( $kit, 'Rotate to the committed key' ) && false === strpos( $kit, 'Publish a commitment to the staged key' ), 'a published commitment switches to the rotate-key button' );
ok( false !== strpos( $kit, '2 published notes and pages cannot be verified yet' ), 'exactly the 2 resolved-kind candidates are counted (the unresolved-kind post is excluded)' );
// 2026-10-12: one name for what the count counts, and no jargon.
ok( false !== strpos( $kit, 'Import or repair 2 notes and pages from the ledger' ) && false === strpos( $kit, 'Repair 2 Notes' ) && false === strpos( $kit, 'July ledger backfill' ), 'the button names the same notes and pages the sentence counts, with no "July ledger backfill" jargon' );
ok( false !== strpos( $classic, 'Import or repair 2 notes and pages from the ledger' ) && false !== strpos( $classic, '2 published notes and pages cannot be verified yet' ), '...and the classic box says the same sentences' );
// 2026-10-12: the Ledger cell is a link that names its record, never a URL as text or a dash.
ok( 1 === preg_match( '/<a [^>]*href="https:\/\/ledger\.test\/notes\/[^"]*"[^>]*>v1 on the ledger<\/a>/', $kit ), 'the commits table\'s Ledger cell is a link reading "v1 on the ledger"' );
ok( false === strpos( $kit, '—' ) && false === strpos( $kit, '&#8212;' ), 'no em dash anywhere in the leaf' );
$prov_js = (string) file_get_contents( SNT_PATH . 'assets/provenance-admin.js' );
ok( false === strpos( $prov_js, "createTextNode('—')" ) && false === strpos( $prov_js, 'All commits anchored' ), 'the classic poller paints no dash and no "All commits anchored"' );
// 2026-10-12 review: the poller's words come translated from the tbody the
// server paints, the kit's own strings, never English hard-coded in the script.
foreach ( array( 'data-text-ledger="v%s on the ledger"', 'data-text-no-ledger="No ledger record"', 'data-text-empty="No pending proofs; a new one appears here within 30 seconds of minting."', 'data-text-error="Status check failed: reload the page to check again."' ) as $attr ) {
	ok( false !== strpos( $classic, $attr ), "classic tbody carries $attr" );
}
ok( false !== strpos( $prov_js, "text(live, 'ledger'," ) && false !== strpos( $prov_js, "text(live, 'no-ledger'," ) && false !== strpos( $prov_js, "text(live, 'empty'," ) && false !== strpos( $prov_js, "text(live, 'error'," ), 'and the poller reads each one from there' );

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

// No sweep-result transient is set here, so the flag says 'ok' but the
// read-back result carries no 'ok' key, which both the classic leaf and this
// leaf correctly render as a failed sweep (config-aware: the Worker IS
// configured in this fixture, so it blames the Worker, not missing constants).
$kit = prov_paint( array( 'sn_prov_swept' => 'ok' ) );
ok( false !== strpos( $kit, 'Sweep failed' ) && false !== strpos( $kit, 'Could not reach the Worker, or it rejected the request.' ), 'a sn_prov_swept=ok param with no readable transient renders the config-aware sweep-failed copy' );

// ── #1607: the read is idempotent. The classic notice deletes the per-user
// transient on read while the flag rides `params` until the next go or post;
// a window repaints the same session on every poll tick, so the SECOND paint
// used to read the flag with an empty result and paint "Sweep failed" for a
// sweep that succeeded. The first read now moves the result into `params`
// beside the flag. Painted twice through ONE state object, as the runtime
// round-trips it.
$GLOBALS['__transients'][ 'sn_prov_sweep_result_' . get_current_user_id() ] = array( 'ok' => true, 'upgraded' => 2, 'still_pending' => 1 );
$prov_state = new class( array( 'params' => array( 'sn_prov_swept' => 'ok' ) ) ) {
	private $v;
	public function __construct( array $v ) { $this->v = $v; }
	public function get( $k ) { return $this->v[ $k ] ?? null; }
	public function set( $k, $x ) { $this->v[ $k ] = $x; return $this; }
};
$prov_painter = \SignalNoise\OpenStationHost\Dashboard\painters()['tools/provenance'];
$prov_ctx     = array( 'tab' => 'tools', 'sub' => 'provenance', 'state' => $prov_state, 'os' => null );
$first        = (string) call_user_func( $prov_painter, $prov_ctx );
$second       = (string) call_user_func( $prov_painter, $prov_ctx );
ok( false !== strpos( $first, 'Sweep complete' ) && false !== strpos( $first, '2 proofs newly confirmed on Bitcoin; 1 still pending.' ), 'the first paint after a sweep reads the transient and says Sweep complete with its counts' );
ok( array() === $GLOBALS['__transients'], '...and spends the one-shot transient, as the classic notice does' );
ok( false !== strpos( $second, 'Sweep complete' ) && false === strpos( $second, 'Sweep failed' ), 'the SECOND paint of the same session (a poll tick, the title-bar Refresh) still says Sweep complete: the result rode params beside the flag instead of being re-read from a spent transient' );
ok( array( 'sn_prov_swept' => 'ok', 'sn_prov_sweep_result' => array( 'ok' => true, 'upgraded' => 2, 'still_pending' => 1 ) ) === $prov_state->get( 'params' ), 'the stash is a sn_* key in params, so it leaves with the flag on the next go or post' );
ok( false !== strpos( $first, '<span os-action="poll" os-poll="30000" hidden></span>' ) && false === strpos( $first, 'os-action="refresh"' ) && false === strpos( $first, '>Refresh</os-button>' ), 'the Commits fieldset carries one hidden os-poll trigger on the no-op poll action every 30 s, and no ghost Refresh: this leaf\'s forms are bare submit buttons, so it polls freely' );

// ────────────────────────────────────────────────────────────────────────
// 6. Empty state: no candidates and no backfill result — the section is
// absent entirely, exactly as the classic leaf ("disappears after a clean
// import").
$GLOBALS['__prov_ids'] = array();
$kit     = prov_paint();
$classic = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
ok( false === strpos( $kit, 'Ledger backfill' ) && false === strpos( $classic, 'Ledger backfill' ), 'no candidates: the backfill section is painted on neither side' );

// ── #1607, the OTHER one-shot transient: the backfill result rides no flag
// at all (its redirect carries none), so the transient is the whole signal.
// Spent on the first read, a poll tick anywhere in the next 30 s dropped the
// "Imported N" notice and, with no candidates left, the whole section. Painted
// twice through ONE state object after a clean import, as the runtime
// round-trips it: both paints carry the notice and the section.
$GLOBALS['__transients'][ 'sn_prov_backfill_result_' . get_current_user_id() ] = array( 'imported' => 3, 'repaired' => 1, 'skipped' => array(), 'remaining' => 0, 'stopped' => '' );
$prov_state = new class( array( 'params' => array() ) ) {
	private $v;
	public function __construct( array $v ) { $this->v = $v; }
	public function get( $k ) { return $this->v[ $k ] ?? null; }
	public function set( $k, $x ) { $this->v[ $k ] = $x; return $this; }
};
$prov_ctx = array( 'tab' => 'tools', 'sub' => 'provenance', 'state' => $prov_state, 'os' => null );
$first    = (string) call_user_func( $prov_painter, $prov_ctx );
$second   = (string) call_user_func( $prov_painter, $prov_ctx );
ok( false !== strpos( $first, 'Ledger backfill' ) && false !== strpos( $first, 'Imported 3 confirmed anchors from the ledger, and repaired 1 missing signatures.' ) && false !== strpos( $first, 'Nothing is left unverifiable.' ), 'the first paint after a backfill reads the transient and paints the Imported notice inside the Ledger backfill section' );
ok( array() === $GLOBALS['__transients'], '...and spends the one-shot transient, as the classic fieldset does' );
ok( false !== strpos( $second, 'Ledger backfill' ) && false !== strpos( $second, 'Imported 3 confirmed anchors from the ledger, and repaired 1 missing signatures.' ), 'the SECOND paint of the same session (a poll tick within 30 s) still paints the notice and the section: the result rode params instead of being re-read from a spent transient' );
ok( array( 'sn_prov_backfill_result' => array( 'imported' => 3, 'repaired' => 1, 'skipped' => array(), 'remaining' => 0, 'stopped' => '' ) ) === $prov_state->get( 'params' ), 'the stash is a sn_* key in params, so it leaves on the next go or post' );
ok( false === strpos( prov_paint(), 'Ledger backfill' ), 'a fresh state with no transient and no candidates paints no section: the stash never leaked past its state object' );

// ────────────────────────────────────────────────────────────────────────
// 6b. 2026-10-12: the rotation result is painted on both surfaces. The
// handlers redirect with sn_prov_rotate=staged|rotated|e-<code>, and nothing
// read it: a rotation consumed its commitment and the box looked untouched.
$rot = array(
	'staged'            => array( 'Commitment published', 'hash of the key the Worker has staged is now public' ),
	'rotated'           => array( 'Rotated to key key-2026-09', 'previous key is in the published history' ),
	'e-reveal-mismatch' => array( 'Nothing changed', 'does not hash to the published commitment' ),
	'e-ledger-write-failed' => array( 'Nothing changed', 'public ledger did not record the rotation' ),
	'e-surprise'        => array( 'Nothing changed', 'refused with the code &quot;surprise&quot;' ),
);
foreach ( $rot as $flag => $want ) {
	$kit_r = prov_paint( array( 'sn_prov_rotate' => $flag ) );
	$_GET['sn_prov_rotate'] = $flag;
	$cl_r = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
	unset( $_GET['sn_prov_rotate'] );
	$kit_ok = false !== strpos( $kit_r, $want[0] ) && false !== strpos( $kit_r, $want[1] ) && false !== strpos( $kit_r, 0 === strpos( $flag, 'e-' ) ? 'tone="danger"' : 'tone="success"' );
	ok( $kit_ok && false !== strpos( $cl_r, $want[0] ) && false !== strpos( $cl_r, $want[1] ), "sn_prov_rotate=$flag paints \"{$want[0]}\" with its reason on the kit leaf and the classic page" );
}
ok( false === strpos( prov_paint(), 'Nothing changed' ) && false === strpos( prov_paint( array( 'sn_prov_rotate' => 'e-' ) ), 'Nothing changed' ), 'no flag (or an empty code) paints no rotation notice' );
ok( false === strpos( prov_paint(), 'commitment above' ), 'the rotate hint names the commitment, not its position' );

// ── 6c. The tiles and the table count the same population: the table reads
// the newest 100 subjects, so the cut is counted under it on both surfaces.
$GLOBALS['__prov_ids'] = range( 1001, 1102 );
foreach ( $GLOBALS['__prov_ids'] as $i ) {
	$GLOBALS['__prov_kind'][ $i ]   = 'note';
	$GLOBALS['__prov_uid'][ $i ]    = 'u' . $i;
	$GLOBALS['__prov_chains'][ $i ] = array( array( 'version' => 1, 'status' => $i > 1100 ? 'unanchored' : 'pending', 'committed_at' => '2026-09-04T10:00:00Z' ) );
}
$kit_cut = prov_paint();
$cl_cut  = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
ok( false !== strpos( $kit_cut, '+2 older proofs waiting are not listed; the table reads the newest 100 subjects.' ), '102 waiting, 100 listed: the kit says what the table leaves out' );
ok( false !== strpos( $cl_cut, '+2 older proofs waiting are not listed; the table reads the newest 100 subjects.' ), '...and the classic page says the same under its table' );
ok( 1 === preg_match( '/<os-stat[^>]*value="100"[^>]*label="Pending"/', $kit_cut ) && 1 === preg_match( '/<os-stat[^>]*value="2"[^>]*label="Not sent"[^>]*tone="warning"|<os-stat[^>]*tone="warning"[^>]*value="2"[^>]*label="Not sent"/', $kit_cut ), 'the tiles count every subject: Pending 100, Not sent 2 (amber)' );
// 2026-10-12 review: the newest 100 subjects all confirmed, the 2 oldest
// pending. The Pending tile reads 2, so the empty table must not say "No
// pending proofs": it says where they are, once, on both surfaces.
foreach ( $GLOBALS['__prov_ids'] as $n => $i ) {
	$GLOBALS['__prov_chains'][ $i ] = array( array( 'version' => 1, 'status' => $n < 100 ? 'confirmed' : 'pending', 'committed_at' => '2026-09-04T10:00:00Z' ) );
}
$kit_old = prov_paint();
$cl_old  = snt_leaf_classic_html( 'sn_admin_render_provenance_section' );
ok( 1 === preg_match( '/<os-stat value="2" label="Pending"/', $kit_old ) && false !== strpos( $kit_old, 'None of the newest 100 subjects has a proof waiting; 2 older ones do.' ) && false === strpos( $kit_old, 'No pending proofs' ), 'kit: Pending 2 with an empty table says "None of the newest 100 subjects has a proof waiting; 2 older ones do.", never "No pending proofs"' );
ok( false === strpos( $kit_old, 'older proofs waiting are not listed' ), '...and says it once: no "+2 older" line under an empty table' );
ok( false !== strpos( $cl_old, 'data-unlisted="2"' ) && false !== strpos( $cl_old, 'data-text-empty-cut="None of the newest 100 subjects has a proof waiting; 2 older ones do."' ) && false === strpos( $cl_old, 'older proofs waiting are not listed' ), 'classic: the tbody hands the poller the cut and that sentence, and no "+2" line repeats it' );
$prov_js = (string) file_get_contents( SNT_PATH . 'assets/provenance-admin.js' );
ok( false !== strpos( $prov_js, "Number(live.getAttribute('data-unlisted')) > 0" ) && false !== strpos( $prov_js, "text(live, 'empty-cut'," ), 'the poller picks the cut sentence from data-unlisted' );
// One older proof: the singular, on both lines.
$GLOBALS['__prov_ids'] = range( 1001, 1101 );
$kit_one = prov_paint();
ok( false !== strpos( $kit_one, 'None of the newest 100 subjects has a proof waiting; 1 older one does.' ), 'one older proof: "1 older one does" (_n)' );
ok( '+1 older proof waiting is not listed; the table reads the newest 100 subjects.' === sn_prov_admin_unlisted_line( 1 ), 'and the cut line is singular at 1' );
$GLOBALS['__prov_ids'] = range( 1001, 1102 );
foreach ( $GLOBALS['__prov_ids'] as $i ) {
	unset( $GLOBALS['__prov_chains'][ $i ], $GLOBALS['__prov_kind'][ $i ], $GLOBALS['__prov_uid'][ $i ] );
}
$GLOBALS['__prov_ids'] = array();
ok( '1 published note or page cannot be verified yet.' === substr( sn_prov_backfill_copy( 'intro', 1 ), 0, 48 ) && 'Import or repair 1 note or page from the ledger' === sn_prov_backfill_copy( 'button', 1 ), 'the backfill copy is singular at 1 (_n)' );

// ── 6d. The Internet Archive box: "not configured" is neutral and said once;
// a push that got no response says so, never "HTTP 0".
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
define( 'SN_ARCHIVE_PUSH_LAST_OPT', 'sn_archive_push_last' );
define( 'SN_ARCHIVE_EXISTING_OPT', 'sn_archive_existing' );
function sn_archive_push_keys() { return $GLOBALS['__arch_keys'] ?? null; }
function sn_archive_existing_pending( $limit = 0 ) { return array( 7 ); }
function sn_archive_existing_status_line() { return null === sn_archive_push_keys() ? 'Not configured: add SN_ARCHIVE_ACCESS_KEY and SN_ARCHIVE_SECRET_KEY to wp-config.' : '1 published notes have never been pushed.'; }
require SNT_PATH . 'inc/archive-push-status.php';
require SNT_PATH . 'inc/archive-push-admin.php';
require_once SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-provenance-archive.php';
$arch_kit = \SignalNoise\OpenStationHost\Dashboard\Leaves\provenance_archive_html();
$arch_cl  = snt_leaf_classic_html( 'sn_archive_existing_render_fieldset' );
ok( false !== strpos( $arch_kit, 'tone="info"' ) && false === strpos( $arch_kit, 'tone="success"' ), 'unconfigured: the kit notice is neutral, not a green pass' );
ok( 1 === substr_count( strtolower( $arch_kit ), 'not configured' ) && false === strpos( $arch_kit, 'Older notes:' ), '...and says "not configured" once: no "Older notes" line repeating it' );
ok( 1 === substr_count( strtolower( $arch_cl ), 'not configured' ) && false === strpos( $arch_cl, 'Older notes:' ), 'the classic box says it once too' );
$GLOBALS['__arch_keys'] = array( 'a', 'b' );
$GLOBALS['__options']['sn_archive_push_last'] = array( 'post_id' => 7, 'requested_at' => time() - 60, 'state' => 'failed', 'failures' => array( 7 => array( 'requested_at' => time() - 60, 'status' => 0, 'state' => 'failed', 'reason' => 'timeout' ) ) );
$arch_kit = \SignalNoise\OpenStationHost\Dashboard\Leaves\provenance_archive_html();
ok( false !== strpos( $arch_kit, 'no response' ) && false === strpos( $arch_kit, 'HTTP 0' ) && false !== strpos( $arch_kit, 'tone="warning"' ), 'a failed push with no response reads "no response", never "HTTP 0", in an amber notice' );
ok( false !== strpos( $arch_kit, 'Older notes:' ), 'configured: the older-notes line is back' );
// 2026-10-12 review: keys removed within a week of a failed push. The notice
// reports the failure, so the run line is the only one left to say the keys
// are gone: it stays on both surfaces.
unset( $GLOBALS['__arch_keys'] );
$arch_kit = \SignalNoise\OpenStationHost\Dashboard\Leaves\provenance_archive_html();
$arch_cl  = snt_leaf_classic_html( 'sn_archive_existing_render_fieldset' );
ok( false !== strpos( $arch_kit, 'Push FAILED' ) && false !== strpos( $arch_kit, 'Older notes:</strong> Not configured' ), 'keys removed after a failed push: the kit still says "not configured" beside the failure' );
ok( false !== strpos( $arch_cl, 'Push FAILED' ) && false !== strpos( $arch_cl, 'Older notes:</strong> Not configured' ), '...and so does the classic box' );
unset( $GLOBALS['__options']['sn_archive_push_last'], $GLOBALS['__arch_keys'] );

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
