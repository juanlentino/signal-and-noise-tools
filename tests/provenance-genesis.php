<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
$GLOBALS['__pv_meta'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $rb = false ) {
		return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $p ) {
		return is_object( $p ) ? $p->post_title : ''; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $k, $single = false ) {
		$v = $GLOBALS['__pv_meta'][ $id ][ $k ] ?? null;
		return $single ? ( null === $v ? '' : $v ) : ( null === $v ? array() : array( $v ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) {
		$GLOBALS['__pv_meta'][ $id ][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	$GLOBALS['__pv_uidc'] = 0;
	function wp_generate_uuid4() {
		return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['__pv_uidc'] ); }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action() {
		return null; }
}
// ── Task 5–9 stubs: options store, configurable Worker transport, post lookup ──
$GLOBALS['__pv_options']   = array(); // options store (get_option/update_option)
$GLOBALS['__pv_http']      = array(); // captured wp_remote_post calls: [ url, args ]
$GLOBALS['__pv_http_code'] = 202;     // settable response code for the transport stub
$GLOBALS['__pv_http_err']  = false;   // when true, wp_remote_post returns a WP_Error
$GLOBALS['__pv_note_ids']  = array(); // ids the get_posts stub returns (date ASC)
$GLOBALS['__pv_posts']     = array(); // id => post object, for the get_post stub
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = null ) {
		$GLOBALS['__pv_options'][ $k ] = $v;
		return true; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public function __construct( $c = '', $m = '', $d = array() ) {
			$this->code = $c; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) {
		return $t instanceof WP_Error; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__pv_http'][] = array( $url, $args );
		if ( ! empty( $GLOBALS['__pv_http_err'] ) ) {
			return new WP_Error( 'http_request_failed', 'boom' );
		}
		return array( 'response' => array( 'code' => (int) $GLOBALS['__pv_http_code'] ), 'body' => '' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return $r['response']['code'] ?? 0; }
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		return $GLOBALS['__pv_note_ids']; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return $GLOBALS['__pv_posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $k = '' ) {
		return 'Signal & Noise'; }
}

// ── v9.21.1 outbound-hardening stubs (CMA LOW-1) ──────────────────────────
$GLOBALS['__pv_http_get']    = array(); // captured wp_remote_get calls: [ url, args ]
$GLOBALS['__pv_ledger_body'] = array( 'ots' => array( 'status' => 'confirmed', 'bitcoin_block' => 900001 ) );
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['__pv_http_get'][] = array( $url, $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $GLOBALS['__pv_ledger_body'] ) );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return $r['body'] ?? ''; }
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $u ) {
		if ( ! is_string( $u ) || '' === $u ) {
			return false; }
		$p = parse_url( $u );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return false; }
		return in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ? $u : false;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}
// Deterministic resolver seam (defined BEFORE ssrf-guard.php so its
// function_exists() guard keeps THIS one). Encoded metadata IP is blocked; the
// ledger host is forced internal only when __pv_block_ghraw is set, so the
// blocked-path test can prove the gate covers even the trusted ledger host.
if ( ! function_exists( 'sn_ssrf_resolve_host' ) ) {
	function sn_ssrf_resolve_host( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		if ( 'raw.githubusercontent.com' === $host && ! empty( $GLOBALS['__pv_block_ghraw'] ) ) {
			return '10.0.0.9';
		}
		return '2852039166' === $host ? '169.254.169.254' : '93.184.216.34';
	}
}

require_once SNT_PATH . 'inc/ssrf-guard.php';
require_once SNT_PATH . 'inc/provenance-core.php';
require_once SNT_PATH . 'inc/provenance-webhook.php'; // SN_PROV_CONFIRM_HOOK — required by Task 5's add_action()
require_once SNT_PATH . 'inc/provenance-genesis.php';

$pass = 0;
$fail = 0;
function gn_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function gn_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}
function gn_make_post( $id, $title, $body, $date ) {
	$p               = new stdClass();
	$p->ID           = $id;
	$p->post_title   = $title;
	$p->post_content = $body;
	$p->post_date    = $date;
	$p->post_date_gmt = $date;
	$p->post_author  = 1;
	return $p;
}

echo "Genesis suite\n\nTask 2: v0 leaf\n";
$p1   = gn_make_post( 101, 'First note', '<p>Body one.</p>', '2025-01-01 00:00:00' );
$leaf = sn_prov_genesis_leaf( $p1, 'Juan Lentino' );
gn_true( is_string( $leaf ) && '' !== $leaf, 'leaf is a non-empty canonical string' );
$decoded = json_decode( $leaf, true );
gn_eq( 0, $decoded['version'], 'v0 payload' );
gn_eq( null, $decoded['parent'], 'v0 parent null' );
gn_eq( 'Body one.', $decoded['content'], 'v0 content normalized' );

echo "\nTask 3: assemble + persist\n";
$posts = array(
	gn_make_post( 201, 'Note A', '<p>Alpha body.</p>', '2025-02-01 00:00:00' ),
	gn_make_post( 202, 'Note B', '<p>Beta body.</p>',  '2025-03-01 00:00:00' ),
	gn_make_post( 203, 'Note C', '<p>Gamma body.</p>', '2025-04-01 00:00:00' ),
);
$genesis = sn_prov_genesis_build( $posts, 'Juan Lentino' );

gn_true( 1 === preg_match( '/^[0-9a-f]{64}$/', $genesis['root'] ), 'genesis root is 64-hex' );
gn_eq( 3, count( $genesis['leaves'] ), 'one leaf entry per Note' );

// Every stored inclusion proof verifies against the root.
foreach ( $genesis['leaves'] as $entry ) {
	gn_true(
		sn_prov_merkle_verify( $entry['leaf'], $entry['proof'], $genesis['root'] ),
		"proof verifies for note {$entry['post_id']}"
	);
}

// Persistence: each Note gets genesis parent + proof meta + a v0 chain entry.
sn_prov_genesis_persist( $genesis );
gn_eq( $genesis['root'], get_post_meta( 201, SN_PROV_GENESIS_META, true ), 'genesis root stored as parent baseline' );
$proof201 = get_post_meta( 201, SN_PROV_PROOF_META, true );
gn_true( is_array( $proof201 ) && count( $proof201 ) >= 1, 'inclusion proof stored on the Note' );
$chain201 = sn_prov_get_chain( 201 );
gn_eq( 0, $chain201[0]['version'], 'v0 commit written to the chain' );
gn_eq( 'genesis', $chain201[0]['status'], 'v0 commit marked genesis' );
gn_true( isset( $chain201[0]['genesis'] ) && true === $chain201[0]['genesis'], 'v0 commit flagged genesis snapshot' );

echo "\nTask 4: FIX B — no version gap after a genesis v0 entry\n";
// Post 201's chain currently holds only the v0 genesis entry (asserted above).
$edit    = gn_make_post( 201, 'Note A (revised)', '<p>Alpha body, materially revised.</p>', '2025-02-01 00:00:00' );
$updated = sn_prov_record( $edit, 'Juan Lentino' );
gn_true( is_array( $updated ), 'sn_prov_record returns the chain for a post with only a v0 genesis entry' );
gn_eq( 2, count( $updated ), 'chain now holds the v0 genesis entry plus the new commit' );
gn_eq( 1, $updated[1]['version'], 'first real commit after genesis is version 1, not 2 (no gap)' );
gn_eq( $chain201[0]['content_hash'], $updated[1]['parent'], 'new commit parent = genesis v0 content_hash' );

echo "\nTask 5: dispatch_manifest return contract (FIX #1)\n";
// No Worker config -> no-op, returns false, and NOTHING is POSTed.
unset( $GLOBALS['__pv_options']['sn_prov_worker_url'], $GLOBALS['__pv_options']['sn_prov_hmac_secret'] );
$GLOBALS['__pv_http'] = array();
gn_eq( false, sn_prov_dispatch_manifest( 'deadbeef', '{"kind":"genesis"}', '2025-05-01' ), 'dispatch_manifest returns false without Worker config' );
gn_eq( 0, count( $GLOBALS['__pv_http'] ), 'no POST attempted when unconfigured' );

// Configured from here on.
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';

// Non-2xx (e.g. 500) -> false.
$GLOBALS['__pv_http']      = array();
$GLOBALS['__pv_http_err']  = false;
$GLOBALS['__pv_http_code'] = 500;
gn_eq( false, sn_prov_dispatch_manifest( 'deadbeef', '{"kind":"genesis"}', '2025-05-01' ), 'dispatch_manifest returns false on a non-2xx response' );
gn_eq( 1, count( $GLOBALS['__pv_http'] ), 'a POST was attempted for the non-2xx case' );

// Transport WP_Error -> false.
$GLOBALS['__pv_http_err'] = true;
gn_eq( false, sn_prov_dispatch_manifest( 'deadbeef', '{"kind":"genesis"}', '2025-05-01' ), 'dispatch_manifest returns false on a wp_error transport failure' );

// 202 (the Worker's accept code) -> true.
$GLOBALS['__pv_http_err']  = false;
$GLOBALS['__pv_http_code'] = 202;
gn_eq( true, sn_prov_dispatch_manifest( 'deadbeef', '{"kind":"genesis"}', '2025-05-01' ), 'dispatch_manifest returns true on a 202 dispatch' );

echo "\nTask 6: genesis_anchor status + return (FIX #2)\n";
// Reuse $genesis from Task 3. No config -> status 'unsent', returns false.
unset( $GLOBALS['__pv_options']['sn_prov_worker_url'], $GLOBALS['__pv_options']['sn_prov_hmac_secret'] );
unset( $GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] );
gn_eq( false, sn_prov_genesis_anchor( $genesis ), 'anchor returns false when the manifest could not be dispatched' );
$opt = get_option( SN_PROV_GENESIS_OPT, array() );
gn_eq( 'unsent', $opt['status'], "no-op anchor records status 'unsent' — never a false 'pending'" );
gn_eq( $genesis['root'], $opt['root'], 'anchor persists the root even when unsent' );

// Configured + 202 -> status 'pending', returns true.
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http_code'] = 202;
$GLOBALS['__pv_http_err']  = false;
gn_eq( true, sn_prov_genesis_anchor( $genesis ), 'anchor returns true when the manifest dispatched' );
$opt = get_option( SN_PROV_GENESIS_OPT, array() );
gn_eq( 'pending', $opt['status'], "dispatched anchor records status 'pending'" );

echo "\nTask 7: genesis_reanchor re-anchors the PERSISTED root (FIX #3)\n";
// (a) Nothing persisted -> false.
unset( $GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] );
gn_eq( false, sn_prov_genesis_reanchor(), 'reanchor returns false when nothing is persisted' );

// Persist the Task-3 snapshot root. Posts 201/202/203 already carry
// GENESIS_META === root + v0 commits from sn_prov_genesis_persist() above.
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array(
	'root'   => $genesis['root'],
	'date'   => '2025-05-01',
	'status' => 'unsent',
);
$GLOBALS['__pv_note_ids'] = array( 201, 202, 203 ); // published Notes, date ASC

// (b) No config -> false, persisted status left untouched.
unset( $GLOBALS['__pv_options']['sn_prov_worker_url'], $GLOBALS['__pv_options']['sn_prov_hmac_secret'] );
$GLOBALS['__pv_http'] = array();
gn_eq( false, sn_prov_genesis_reanchor(), 'reanchor returns false without Worker config' );
gn_eq( 'unsent', get_option( SN_PROV_GENESIS_OPT )['status'], 'reanchor leaves status unsent when it could not dispatch' );
gn_eq( 0, count( $GLOBALS['__pv_http'] ), 'reanchor fires no POST without config' );

// (c) Configured -> true; POSTs the persisted root + a faithfully reconstructed manifest.
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http_code'] = 202;
$GLOBALS['__pv_http_err']  = false;
$GLOBALS['__pv_http']      = array();
gn_eq( true, sn_prov_genesis_reanchor(), 'reanchor returns true when dispatched' );
gn_eq( 1, count( $GLOBALS['__pv_http'] ), 'reanchor fired exactly one POST' );

$sent_body = json_decode( $GLOBALS['__pv_http'][0][1]['body'], true );
gn_eq( $genesis['root'], $sent_body['content_hash'], 'POST content_hash === the PERSISTED root (never recomputed)' );
gn_eq( 'genesis', $sent_body['note_uid'], "POST note_uid is 'genesis'" );
gn_eq( 0, $sent_body['version'], 'POST version is 0' );

$manifest = json_decode( $sent_body['canonical'], true );
gn_eq( 'genesis', $manifest['kind'], 'manifest kind is genesis' );
gn_eq( $genesis['root'], $manifest['root'], 'manifest root === persisted root' );
gn_eq( 3, $manifest['count'], 'manifest counts all three Notes' );

// Reconstructed notes must match the original snapshot: note_uid + the v0 leaf
// hash (bin2hex(sn_prov_leaf_hash(leaf))) that persist wrote to each chain.
$expected_notes = array();
foreach ( $genesis['leaves'] as $lf ) {
	$expected_notes[] = array(
		'note_uid'  => $lf['note_uid'],
		'leaf_hash' => bin2hex( sn_prov_leaf_hash( $lf['leaf'] ) ),
	);
}
gn_eq( $expected_notes, $manifest['notes'], 'reconstructed manifest notes match the original leaf_hash + note_uid, in order' );
gn_eq( 'pending', get_option( SN_PROV_GENESIS_OPT )['status'], 'successful reanchor flips persisted status to pending' );

echo "\nTask 8: migrate self-heal — flag the gate only after a real anchor (FIX #4)\n";
// Two fresh Notes with no chain form the backlog.
$GLOBALS['__pv_posts'][301] = gn_make_post( 301, 'Backlog One', '<p>One.</p>', '2025-06-01 00:00:00' );
$GLOBALS['__pv_posts'][302] = gn_make_post( 302, 'Backlog Two', '<p>Two.</p>', '2025-06-02 00:00:00' );
$GLOBALS['__pv_note_ids']   = array( 301, 302 );

// (a) No Worker config: the anchor no-ops -> the gate MUST stay unset (retry).
unset( $GLOBALS['__pv_options']['sn_prov_worker_url'], $GLOBALS['__pv_options']['sn_prov_hmac_secret'] );
unset( $GLOBALS['__pv_options'][ SN_PROV_GENESIS_MIGR_OPT ] );
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_migrate();
gn_eq( false, get_option( SN_PROV_GENESIS_MIGR_OPT ), 'migrate leaves the gate UNSET when the anchor no-ops (no config)' );
gn_true( '' !== get_post_meta( 301, SN_PROV_GENESIS_META, true ), 'persist still ran (root stored on the backlog Note) even though the anchor no-op\'d' );

// (b) Configured: the anchor dispatches -> the gate IS set.
$GLOBALS['__pv_posts'][311] = gn_make_post( 311, 'Backlog Three', '<p>Three.</p>', '2025-07-01 00:00:00' );
$GLOBALS['__pv_posts'][312] = gn_make_post( 312, 'Backlog Four', '<p>Four.</p>', '2025-07-02 00:00:00' );
$GLOBALS['__pv_note_ids']   = array( 311, 312 );
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http_code'] = 202;
$GLOBALS['__pv_http_err']  = false;
unset( $GLOBALS['__pv_options'][ SN_PROV_GENESIS_MIGR_OPT ] );
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_migrate();
gn_true( (bool) get_option( SN_PROV_GENESIS_MIGR_OPT ), 'migrate SETS the gate after a successful anchor dispatch' );
gn_eq( 1, count( $GLOBALS['__pv_http'] ), 'migrate dispatched the genesis manifest' );

// (c) Empty backlog: nothing to snapshot -> gate set, no POST.
$GLOBALS['__pv_note_ids'] = array();
unset( $GLOBALS['__pv_options'][ SN_PROV_GENESIS_MIGR_OPT ] );
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_migrate();
gn_true( (bool) get_option( SN_PROV_GENESIS_MIGR_OPT ), 'migrate sets the gate when there is nothing to snapshot' );
gn_eq( 0, count( $GLOBALS['__pv_http'] ), 'empty-backlog migrate fires no POST' );

echo "\nTask 9: reanchor_migrate one-shot self-heal (FIX #5)\n";
// Persist a genesis root that was built but never dispatched ('unsent' — the
// current code's failed-dispatch state), backed by the Task-3 Notes. The
// self-heal re-dispatches an 'unsent' root; a genuinely 'pending'/'confirmed'
// one is left alone by the re-anchor guard (Task 12).
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array(
	'root'   => $genesis['root'],
	'date'   => '2025-05-01',
	'status' => 'unsent',
);
$GLOBALS['__pv_note_ids'] = array( 201, 202, 203 );
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http_code'] = 202;
$GLOBALS['__pv_http_err']  = false;

// (a) Gate already set -> no-op (no reanchor, no POST).
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_REANCHOR_OPT ] = time();
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_reanchor_migrate();
gn_eq( 0, count( $GLOBALS['__pv_http'] ), 'reanchor_migrate no-ops when its gate is already set' );

// (b) Gate unset + root persisted + config -> reanchors, sets the gate.
unset( $GLOBALS['__pv_options'][ SN_PROV_GENESIS_REANCHOR_OPT ] );
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_reanchor_migrate();
gn_eq( 1, count( $GLOBALS['__pv_http'] ), 'reanchor_migrate dispatched the persisted root' );
gn_true( (bool) get_option( SN_PROV_GENESIS_REANCHOR_OPT ), 'reanchor_migrate sets its gate after a successful dispatch' );

// (c) Gate unset + dispatch fails (no config) -> gate stays unset (retry).
// Reset to 'unsent' — (b)'s successful dispatch flipped the status to 'pending',
// which the re-anchor guard would now no-op (so it would never reach the failing
// dispatch this case exercises).
unset( $GLOBALS['__pv_options'][ SN_PROV_GENESIS_REANCHOR_OPT ] );
unset( $GLOBALS['__pv_options']['sn_prov_worker_url'], $GLOBALS['__pv_options']['sn_prov_hmac_secret'] );
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ]['status'] = 'unsent';
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_reanchor_migrate();
gn_eq( false, get_option( SN_PROV_GENESIS_REANCHOR_OPT ), 'reanchor_migrate leaves the gate UNSET when the dispatch fails (retry next admin_init)' );

echo "\nTask 10: fresh-install cascade — no same-request double-dispatch (FIX #6)\n";
// Both hooks run on one admin_init (migrate registered first). On a fresh install
// migrate() must anchor once AND close the re-anchor gate, so reanchor_migrate()
// no-ops instead of re-POSTing the identical root a second time in the same request.

// (a) No config: migrate's anchor no-ops -> NEITHER gate is set (both retry).
$GLOBALS['__pv_posts'][401] = gn_make_post( 401, 'Fresh One', '<p>Uno.</p>', '2025-08-01 00:00:00' );
$GLOBALS['__pv_posts'][402] = gn_make_post( 402, 'Fresh Two', '<p>Dos.</p>', '2025-08-02 00:00:00' );
$GLOBALS['__pv_note_ids']   = array( 401, 402 );
unset(
	$GLOBALS['__pv_options']['sn_prov_worker_url'],
	$GLOBALS['__pv_options']['sn_prov_hmac_secret'],
	$GLOBALS['__pv_options'][ SN_PROV_GENESIS_MIGR_OPT ],
	$GLOBALS['__pv_options'][ SN_PROV_GENESIS_REANCHOR_OPT ]
);
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_migrate();
gn_eq( false, get_option( SN_PROV_GENESIS_MIGR_OPT ), 'no-config migrate leaves the migrate gate unset' );
gn_eq( false, get_option( SN_PROV_GENESIS_REANCHOR_OPT ), 'no-config migrate never sets the re-anchor gate either' );

// (b) Config present + non-empty backlog: migrate anchors and closes BOTH gates,
//     so the re-anchor self-heal (same admin_init) no-ops -> exactly ONE POST.
$GLOBALS['__pv_posts'][411] = gn_make_post( 411, 'Fresh Three', '<p>Tres.</p>', '2025-09-01 00:00:00' );
$GLOBALS['__pv_posts'][412] = gn_make_post( 412, 'Fresh Four', '<p>Cuatro.</p>', '2025-09-02 00:00:00' );
$GLOBALS['__pv_note_ids']   = array( 411, 412 );
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http_code'] = 202;
$GLOBALS['__pv_http_err']  = false;
unset(
	$GLOBALS['__pv_options'][ SN_PROV_GENESIS_MIGR_OPT ],
	$GLOBALS['__pv_options'][ SN_PROV_GENESIS_REANCHOR_OPT ]
);
$GLOBALS['__pv_http'] = array();
sn_prov_genesis_migrate();            // registered first on admin_init
sn_prov_genesis_reanchor_migrate();   // registered second on the same admin_init
gn_eq( 1, count( $GLOBALS['__pv_http'] ), 'fresh-install cascade fires exactly ONE POST (no same-request double-dispatch)' );
gn_true( (bool) get_option( SN_PROV_GENESIS_MIGR_OPT ), 'migrate gate set after the initial anchor' );
gn_true( (bool) get_option( SN_PROV_GENESIS_REANCHOR_OPT ), 'initial anchor also closes the re-anchor gate -> reanchor_migrate no-ops' );

echo "\nTask 11: genesis confirm applies to the OPTION (genesis is not a post)\n";
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array(
	'root'   => str_repeat( 'ab', 32 ),
	'date'   => '2026-07-09',
	'status' => 'pending',
);
gn_eq( true, sn_prov_apply_genesis_confirmation( array( 'content_hash' => str_repeat( 'ab', 32 ), 'status' => 'confirmed', 'bitcoin_block' => 902417 ) ), 'genesis confirm applied' );
gn_eq( 'confirmed', get_option( SN_PROV_GENESIS_OPT )['status'], 'genesis option flipped to confirmed' );
gn_eq( 902417, get_option( SN_PROV_GENESIS_OPT )['bitcoin_block'], 'genesis bitcoin_block recorded when present' );
// Integrity: a mismatched root is rejected, option untouched.
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ]['status'] = 'pending';
gn_eq( false, sn_prov_apply_genesis_confirmation( array( 'content_hash' => str_repeat( 'ff', 32 ), 'status' => 'confirmed' ) ), 'genesis confirm with a mismatched root is rejected' );
gn_eq( 'pending', get_option( SN_PROV_GENESIS_OPT )['status'], 'rejected genesis confirm leaves status pending' );
// A non-confirmed status is a no-op.
gn_eq( false, sn_prov_apply_genesis_confirmation( array( 'content_hash' => str_repeat( 'ab', 32 ), 'status' => 'pending' ) ), 'genesis confirm requires status=confirmed' );

echo "\nTask 12: re-anchor is a no-op once the root is dispatched or confirmed\n";
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http_code'] = 202;
$GLOBALS['__pv_http_err']  = false;
// pending -> no re-stamp (would reset the OTS clock).
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => str_repeat( 'ab', 32 ), 'date' => '2026-07-09', 'status' => 'pending' );
$GLOBALS['__pv_http'] = array();
gn_eq( true, sn_prov_genesis_reanchor(), 'reanchor no-ops (returns true) when already pending' );
gn_eq( 0, count( $GLOBALS['__pv_http'] ), 'reanchor fires NO POST when pending' );
gn_eq( 'pending', get_option( SN_PROV_GENESIS_OPT )['status'], 'reanchor leaves a pending genesis untouched' );
// confirmed -> no re-stamp (never reverts a confirmed anchor).
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ]['status'] = 'confirmed';
$GLOBALS['__pv_http'] = array();
gn_eq( true, sn_prov_genesis_reanchor(), 'reanchor no-ops when already confirmed' );
gn_eq( 0, count( $GLOBALS['__pv_http'] ), 'reanchor fires NO POST when confirmed' );
gn_eq( 'confirmed', get_option( SN_PROV_GENESIS_OPT )['status'], 'reanchor leaves a confirmed genesis confirmed' );

echo "\nTask 13: outbound hardening — redirection=0 + shared SSRF gate (CMA LOW-1)\n";
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http_code'] = 202;
$GLOBALS['__pv_http_err']  = false;

// dispatch_manifest(): redirection => 0 for an allowed host; a blocked (http) host → false, no POST.
$GLOBALS['__pv_http'] = array();
gn_eq( true, sn_prov_dispatch_manifest( 'deadbeef', '{"kind":"genesis"}', '2025-05-01' ), 'dispatch_manifest ok for an allowed host' );
gn_eq( 0, $GLOBALS['__pv_http'][0][1]['redirection'] ?? -1, 'dispatch_manifest POST sets redirection => 0' );
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'http://worker.example/';
$GLOBALS['__pv_http'] = array();
gn_eq( false, sn_prov_dispatch_manifest( 'deadbeef', '{"kind":"genesis"}', '2025-05-01' ), 'dispatch_manifest returns false for a blocked (http) host' );
gn_eq( 0, count( $GLOBALS['__pv_http'] ), 'dispatch_manifest fires NO POST when blocked' );

// genesis_refresh(): redirection => 0 on the ledger GET; a blocked ledger host → no GET, status untouched.
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => str_repeat( 'ab', 32 ), 'date' => '2026-07-11', 'status' => 'pending' );
$GLOBALS['__pv_block_ghraw'] = false;
$GLOBALS['__pv_http_get']    = array();
sn_prov_genesis_refresh();
gn_eq( 'confirmed', get_option( SN_PROV_GENESIS_OPT )['status'], 'genesis_refresh flips pending → confirmed from the ledger record' );
gn_eq( 1, count( $GLOBALS['__pv_http_get'] ), 'genesis_refresh fired one GET for the allowed ledger host' );
gn_eq( 0, $GLOBALS['__pv_http_get'][0][1]['redirection'] ?? -1, 'genesis_refresh GET sets redirection => 0' );

$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => str_repeat( 'ab', 32 ), 'date' => '2026-07-11', 'status' => 'pending' );
$GLOBALS['__pv_block_ghraw'] = true;
$GLOBALS['__pv_http_get']    = array();
sn_prov_genesis_refresh();
gn_eq( 'pending', get_option( SN_PROV_GENESIS_OPT )['status'], 'genesis_refresh leaves status pending when the ledger host is blocked' );
gn_eq( 0, count( $GLOBALS['__pv_http_get'] ), 'genesis_refresh fires NO GET when blocked' );
$GLOBALS['__pv_block_ghraw'] = false;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
