<?php
/**
 * Tests for inc/abilities-provenance.php — the anchor-status read +
 * anchor-sweep action abilities behind the Desktop Mode mirror
 * (SN Anchors widget + the ⌘K sweep command).
 *
 * Covers:
 *   - Registration: both abilities register on wp_abilities_api_init with
 *     owner perms, valid categories, and honest annotations (status is
 *     readonly+idempotent; sweep is non-readonly but idempotent and
 *     non-destructive).
 *   - Aggregation (snt_prov_anchor_overview): latest-chain-entry
 *     semantics, pending rows carry txid + confirmations, a missing
 *     confirmation count stays null (never a fabricated 0), chainless
 *     posts are neither counted nor listed, empty-corpus shape.
 *   - Execute callbacks: sweep passes the seam's result through
 *     untouched; both degrade honestly when their seam is absent.
 *
 * Run: php tests/abilities-provenance.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }

echo "abilities-provenance suite\n";

// ─── Load-time stubs ─────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }

require __DIR__ . '/../inc/abilities-provenance.php';

// ─── Group A: overview degrades to the empty shape without its seams ─────
$empty = snt_prov_anchor_overview();
ok( array( 'pending' => array(), 'confirmed' => 0, 'total' => 0 ) === $empty,
	'overview returns the honest empty shape when WP seams are absent' );

// ─── Group B: aggregation over a stubbed corpus ──────────────────────────
define( 'SN_PROV_UID_META', '_sn_prov_uid' );
function get_posts( $args ) { return array( 11, 22, 33, 44 ); }
function get_the_title( $id ) { return 'Note ' . $id; }
function sn_prov_get_chain( $id ) {
	$chains = array(
		// 11: two entries; the LATEST (pending, with live tx data) must win.
		11 => array(
			array( 'version' => 1, 'status' => 'confirmed' ),
			array( 'version' => 2, 'status' => 'pending', 'bitcoin_txid' => 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12', 'confirmations' => 3 ),
		),
		// 22: confirmed only.
		22 => array( array( 'version' => 1, 'status' => 'confirmed', 'bitcoin_block' => 957611 ) ),
		// 33: pending with NO confirmation count recorded.
		33 => array( array( 'version' => 1, 'status' => 'pending' ) ),
		// 44: uid meta exists but no chain yet — must not be counted or listed.
		44 => array(),
	);
	return $chains[ $id ] ?? array();
}

$ov = snt_prov_anchor_overview();
ok( 3 === $ov['total'], 'total counts only posts WITH a chain (chainless post excluded), got ' . $ov['total'] );
ok( 1 === $ov['confirmed'], 'one note fully confirmed' );
ok( 2 === count( $ov['pending'] ), 'two pending rows' );

$p11 = null; $p33 = null;
foreach ( $ov['pending'] as $row ) {
	if ( 11 === $row['post_id'] ) { $p11 = $row; }
	if ( 33 === $row['post_id'] ) { $p33 = $row; }
}
ok( null !== $p11 && 2 === $p11['version'], 'latest chain entry wins (post 11 pending at v2, not confirmed v1)' );
ok( null !== $p11 && 3 === $p11['confirmations'], 'pending row carries the live confirmation count' );
ok( null !== $p11 && 0 === strpos( $p11['bitcoin_txid'], 'ab12cd34' ), 'pending row carries the in-flight txid' );
ok( null !== $p11 && 'Note 11' === $p11['title'], 'pending row carries the post title' );
ok( null !== $p33 && null === $p33['confirmations'], 'missing confirmation count stays null — never a fabricated 0' );

// ─── Group C: registration on the canonical hook ─────────────────────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }

ok( isset( $GLOBALS['__actions']['wp_abilities_api_init'] ) && 1 === count( $GLOBALS['__actions']['wp_abilities_api_init'] ),
	'module registers exactly one wp_abilities_api_init callback' );
call_user_func( $GLOBALS['__actions']['wp_abilities_api_init'][0] );

ok( isset( $GLOBALS['__abilities']['signal-noise/anchor-status'] ), 'anchor-status registered' );
ok( isset( $GLOBALS['__abilities']['signal-noise/anchor-sweep'] ), 'anchor-sweep registered' );

$st = $GLOBALS['__abilities']['signal-noise/anchor-status'];
$sw = $GLOBALS['__abilities']['signal-noise/anchor-sweep'];
ok( 'snt_ability_perm_manage_options' === $st['permission_callback'] && 'snt_ability_perm_manage_options' === $sw['permission_callback'],
	'both abilities are owner-gated' );
ok( 'diagnostics' === $st['category'] && 'maintenance' === $sw['category'], 'categories come from the REGISTERED set in inc/abilities-categories.php (v9.78.0 shipped the unregistered \'monitoring\' — caught live)' );
ok( true === ( $st['meta']['annotations']['readonly'] ?? null ), 'anchor-status is annotated readonly (GET run-path)' );
ok( array( 'object', 'null' ) === ( $st['input_schema']['type'] ?? null ),
	'anchor-status input accepts the null a bodyless GET delivers (the [object,null] union — a plain object shipped in v9.78.1 rejected every widget read live)' );
ok( false === ( $sw['meta']['annotations']['readonly'] ?? null ) && true === ( $sw['meta']['annotations']['idempotent'] ?? null ),
	'anchor-sweep is non-readonly but idempotent' );
ok( false === ( $sw['meta']['annotations']['destructive'] ?? null ), 'anchor-sweep is annotated non-destructive' );

// ─── Group D: execute callbacks ──────────────────────────────────────────
$status = call_user_func( $st['execute_callback'] );
ok( is_array( $status ) && 3 === $status['total'], 'anchor-status execute returns the overview' );

$sweep_absent = call_user_func( $sw['execute_callback'] );
ok( false === $sweep_absent['ok'] && 'unavailable' === $sweep_absent['error'],
	'anchor-sweep degrades honestly when the dispatch seam is absent' );

// Conditionally declared: an unconditional top-level declaration is hoisted
// at parse time and would falsify the seam-absent assertion above.
if ( ! function_exists( 'sn_prov_run_sweep' ) ) {
	function sn_prov_run_sweep() { return array( 'ok' => true, 'checked' => 5, 'upgraded' => 2, 'still_pending' => 3 ); }
}
$sweep = call_user_func( $sw['execute_callback'] );
ok( array( 'ok' => true, 'checked' => 5, 'upgraded' => 2, 'still_pending' => 3 ) === $sweep,
	'anchor-sweep passes the seam result through untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
