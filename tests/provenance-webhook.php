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

$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_http']    = array(); // captured wp_remote_post calls
$GLOBALS['__pv_get_posts_pages'] = array();
$GLOBALS['__pv_get_posts_calls'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
// v10.84.0: sn_prov_dispatch() now resolves the subject KIND, which needs the
// post object. Third stub-drift fatal of the day and the same shape each time —
// a callee grew a dependency and the suite died BEFORE its first assertion,
// caught only by CI's no-summary-line rule.
$GLOBALS['__wh_post'] = null;
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id = 0 ) { return $GLOBALS['__wh_post']; }
}
if ( ! function_exists( 'has_term' ) ) {
	function has_term( $term, $tax, $id = 0 ) { return ! empty( $GLOBALS['__wh_has_term'] ); }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__pv_http'][] = array( $url, $args );
		if ( false !== strpos( (string) $url, '/sweep' ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode(
				$GLOBALS['__pv_sweep_body'] ?? array( 'ok' => true, 'checked' => 3, 'upgraded' => 2, 'stillPending' => 1 )
			) );
		}
		return array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array(
			'signature'   => 'SIGBASE64',
			'pubkey_id'   => 'sn-ed25519-2026-07',
			'ledger_path' => 'notes/u/v1.json',
			'ots_status'  => 'pending',
		) ) );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/' ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) {
		return $t instanceof WP_Error; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return $r['response']['code'] ?? 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return $r['body'] ?? ''; }
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
	function wp_generate_uuid4() {
		return 'uuuuuuuu-0000-4000-8000-000000000000'; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $data;
		public function __construct( $c = '', $m = '', $d = array() ) {
			$this->code = $c;
			$this->data = $d; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $d = null, $s = 200 ) {
			$this->data = $d;
			$this->status = $s; }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $headers;
		private $body;
		public function __construct( $headers = array(), $body = '' ) {
			$this->headers = $headers;
			$this->body = $body; }
		public function get_header( $k ) {
			return $this->headers[ $k ] ?? null; }
		public function get_body() {
			return $this->body; }
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route() {
		return true; }
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$GLOBALS['__pv_get_posts_calls'][] = $args;
		$want = $args['meta_value'] ?? null;
		if ( null !== $want ) {
			foreach ( $GLOBALS['__pv_meta'] as $pid => $meta ) {
				if ( ( $meta[ SN_PROV_UID_META ] ?? null ) === $want ) {
					return array( (int) $pid );
				}
			}
			return array();
		}
		$page = (int) ( $args['paged'] ?? 1 );
		return $GLOBALS['__pv_get_posts_pages'][ $page ] ?? array();
	}
}

// ── v9.21.1 outbound-hardening stubs (CMA LOW-1) ──────────────────────────
$GLOBALS['__pv_http_get'] = array(); // captured wp_remote_get calls: [ url, args ]
$GLOBALS['__pv_ver_body'] = array( 'version' => '3.1.0' ); // GET /_sn/version body
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['__pv_http_get'][] = array( $url, $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $GLOBALS['__pv_ver_body'] ) );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) {
		return $GLOBALS['__pv_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['__pv_transients'][ $k ] = $v;
		return true; }
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
// Faithful wp_http_validate_url: http/https + host only. The 169.254 / encoded-IP
// blocking is the plugin's OWN sn_ssrf_host_blocked() guard — this stub must not
// fake it or the metadata cases would give false assurance (mirrors tests/webhooks.php).
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
// INFO-1 async-dispatch cron seam: schedule captures; wp_next_scheduled reflects it.
$GLOBALS['__pv_sched'] = array();
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['__pv_sched'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
		return true; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( $GLOBALS['__pv_sched'] as $e ) {
			if ( $e['hook'] === $hook && $e['args'] === $args ) {
				return $e['ts'] > 0 ? $e['ts'] : 1;
			}
		}
		return false;
	}
}
// Deterministic resolver seam for the shared SSRF guard — defined BEFORE
// inc/ssrf-guard.php so its function_exists() guard keeps THIS one (mirrors
// tests/worker-version.php). Encoded metadata IP + an RFC-1918 hostname are
// blocked; every other host resolves public.
if ( ! function_exists( 'sn_ssrf_resolve_host' ) ) {
	function sn_ssrf_resolve_host( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$map = array(
			'2852039166'               => '169.254.169.254', // decimal-encoded metadata IP
			'blocked-internal.example' => '10.0.0.5',         // hostname → RFC-1918
		);
		return $map[ $host ] ?? '93.184.216.34'; // any other host → public
	}
}

require_once SNT_PATH . 'inc/ssrf-guard.php';
require_once SNT_PATH . 'inc/provenance-core.php';
require_once SNT_PATH . 'inc/provenance-webhook.php';

$pass = 0;
$fail = 0;
function wh_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function wh_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Provenance webhook suite\n\nTask 1: config\n";
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'https://worker.example/';
wh_eq( 'https://worker.example/', sn_prov_worker_url(), 'worker url from option' );
wh_eq( '', sn_prov_hmac_secret(), 'hmac secret empty when unset' );

echo "\nTask 2: dispatch\n";
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
// Seed a chain entry (version 1) as Plan 1 would have appended.
update_post_meta( 42, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'unanchored' ) ) );
update_post_meta( 42, SN_PROV_UID_META, 'u' );

$GLOBALS['__pv_http'] = array();
$commit   = array( 'version' => 1, 'content_hash' => 'aa' );
$canonical = '{"algo":"sn-normalize-v1"}';
sn_prov_dispatch( 42, $commit, $canonical );

wh_eq( 1, count( $GLOBALS['__pv_http'] ), 'one webhook POST fired' );
$sent = $GLOBALS['__pv_http'][0];
wh_eq( 'https://worker.example/', $sent[0], 'posted to the worker url' );
$expected_sig = 'sha256=' . hash_hmac( 'sha256', $sent[1]['body'], 'shh' );
wh_eq( $expected_sig, $sent[1]['headers']['X-SN-Signature'], 'HMAC signature over raw body' );
$body = json_decode( $sent[1]['body'], true );
wh_eq( 'u', $body['note_uid'], 'body carries note_uid' );

// v10.84.0: the Worker (>= v1.10.0) builds the ledger path from `kind`. It
// treats an ABSENT kind as 'note', but the plugin sends it explicitly rather
// than relying on that: a payload that states its own subject type cannot be
// silently reinterpreted if the Worker's default ever changes.
wh_eq( 'note', $body['kind'], 'body carries the subject kind, explicitly' );

// A post that is NOT a note (no notes category) still dispatches as 'note'
// rather than as an empty string — dispatch is only ever reached for a real
// subject, and an empty kind would be REFUSED by the Worker's validator.
$GLOBALS['__wh_has_term'] = false;
$GLOBALS['__wh_post']     = (object) array( 'ID' => 42, 'post_type' => 'post' );
$GLOBALS['__pv_http']     = array();
sn_prov_dispatch( 42, $commit, $canonical );
$last     = end( $GLOBALS['__pv_http'] );
$fallback = json_decode( $last[1]['body'], true );
wh_eq( 'note', $fallback['kind'], 'kind never dispatches empty — the Worker would refuse it' );
wh_eq( $canonical, $body['canonical'], 'body carries canonical bytes' );

$chain = sn_prov_get_chain( 42 );
wh_eq( 'pending', $chain[0]['status'], 'chain entry flipped to pending' );
wh_eq( 'SIGBASE64', $chain[0]['signature'], 'signature stored on the commit' );
wh_eq( 'notes/u/v1.json', $chain[0]['ledger_path'], 'ledger_path stored' );

echo "\nTask 3: confirm permission (ed25519)\n";
if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	echo "  SKIP: libsodium not available\n";
} else {
	$kp     = sodium_crypto_sign_keypair();
	$sk     = sodium_crypto_sign_secretkey( $kp );
	$pk     = sodium_crypto_sign_publickey( $kp );
	$GLOBALS['__pv_options']['sn_prov_pubkey_b64'] = base64_encode( $pk );

	$body   = wp_json_encode( array( 'note_uid' => 'u', 'version' => 1, 'content_hash' => 'aa', 'status' => 'confirmed' ) );
	$goodsig = base64_encode( sodium_crypto_sign_detached( $body, $sk ) );

	$req_ok  = new WP_REST_Request( array( 'x_sn_ed25519' => $goodsig ), $body );
	$req_bad = new WP_REST_Request( array( 'x_sn_ed25519' => base64_encode( str_repeat( "\0", 64 ) ) ), $body );

	wh_eq( true, sn_prov_confirm_permission( $req_ok ), 'valid ed25519 signature accepted' );
	wh_true( sn_prov_confirm_permission( $req_bad ) instanceof WP_Error, 'forged signature rejected' );

	// Tampered body: signature is valid for body-A, but the submitted request carries body-B.
	$body_b       = wp_json_encode( array( 'note_uid' => 'u', 'version' => 1, 'content_hash' => 'aa', 'status' => 'confirmed', 'bitcoin_block' => 1 ) );
	$req_tampered = new WP_REST_Request( array( 'x_sn_ed25519' => $goodsig ), $body_b );
	wh_true( sn_prov_confirm_permission( $req_tampered ) instanceof WP_Error, 'tampered body rejected even with a validly-formed signature' );

	// Missing header: no x_sn_ed25519 at all.
	$req_missing = new WP_REST_Request( array(), $body );
	wh_true( sn_prov_confirm_permission( $req_missing ) instanceof WP_Error, 'missing signature header rejected' );

	// Empty pubkey config: must fail CLOSED (500), not open.
	$saved_pubkey = $GLOBALS['__pv_options']['sn_prov_pubkey_b64'];
	unset( $GLOBALS['__pv_options']['sn_prov_pubkey_b64'] );
	$no_key_result = sn_prov_confirm_permission( $req_ok );
	wh_true( $no_key_result instanceof WP_Error, 'unset public key rejected' );
	wh_eq( 500, $no_key_result->data['status'] ?? null, 'unset public key fails closed with a 500' );
	$GLOBALS['__pv_options']['sn_prov_pubkey_b64'] = $saved_pubkey; // restore

	// Wrong-length sig: base64 of a 10-byte value, rejected before verification.
	$req_short = new WP_REST_Request( array( 'x_sn_ed25519' => base64_encode( str_repeat( "\x01", 10 ) ) ), $body );
	wh_true( sn_prov_confirm_permission( $req_short ) instanceof WP_Error, 'wrong-length signature rejected before verify' );
}

echo "\nTask 4: apply confirmation\n";
update_post_meta( 42, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'pending' ) ) );
update_post_meta( 42, SN_PROV_UID_META, 'u' );

wh_eq( 42, sn_prov_post_by_uid( 'u' ), 'note_uid resolves to post id' );
$applied = sn_prov_apply_confirmation( 'u', 1, array( 'content_hash' => 'aa', 'status' => 'confirmed', 'bitcoin_block' => 902417 ) );
wh_eq( true, $applied, 'confirmation applied' );
$chain = sn_prov_get_chain( 42 );
wh_eq( 'confirmed', $chain[0]['status'], 'status is confirmed' );
wh_eq( 902417, $chain[0]['bitcoin_block'], 'block height recorded' );
wh_eq( false, sn_prov_apply_confirmation( 'nope', 1, array() ), 'unknown uid returns false' );

// Pending-progress callback: records the in-flight tx + confirmation count on the
// still-pending commit (status stays pending); a malformed txid is rejected.
update_post_meta( 42, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'pending' ) ) );
$txid = str_repeat( 'ab', 32 );
sn_prov_apply_confirmation( 'u', 1, array( 'content_hash' => 'aa', 'status' => 'pending', 'bitcoin_txid' => $txid, 'confirmations' => 3 ) );
$pchain = sn_prov_get_chain( 42 );
wh_eq( 'pending', $pchain[0]['status'], 'pending update keeps status pending' );
wh_eq( $txid, $pchain[0]['bitcoin_txid'] ?? '', 'pending tx id recorded' );
wh_eq( 3, $pchain[0]['confirmations'] ?? -1, 'confirmation count recorded' );
update_post_meta( 42, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'pending' ) ) );
sn_prov_apply_confirmation( 'u', 1, array( 'status' => 'pending', 'bitcoin_txid' => 'not-a-txid' ) );
wh_eq( '', sn_prov_get_chain( 42 )[0]['bitcoin_txid'] ?? '', 'a malformed txid is rejected' );

echo "\nTask 5: reconcile\n";
// Post 77 has an unanchored commit and no worker response yet.
update_post_meta( 77, SN_PROV_UID_META, 'w' );
update_post_meta( 77, SN_PROV_CHAIN_META, array(
	array( 'version' => 1, 'content_hash' => 'bb', 'status' => 'unanchored', 'payload' => array( 'x' => 1 ) ),
) );
$GLOBALS['__pv_http'] = array();
sn_prov_reconcile_post( 77 );
wh_eq( 1, count( $GLOBALS['__pv_http'] ), 'unanchored commit re-dispatched' );
$chain = sn_prov_get_chain( 77 );
wh_eq( 'pending', $chain[0]['status'], 'reconcile flips unanchored -> pending' );
// A pending/confirmed commit is left alone.
$GLOBALS['__pv_http'] = array();
sn_prov_reconcile_post( 77 );
wh_eq( 0, count( $GLOBALS['__pv_http'] ), 'already-pending commit not re-dispatched' );

// The cron sweep must not silently stop after the first 50 Notes.
$GLOBALS['__pv_get_posts_pages'] = array(
	1 => range( 1000, 1049 ),
	2 => range( 1050, 1099 ),
	3 => range( 1100, 1119 ),
);
$GLOBALS['__pv_get_posts_calls'] = array();
sn_prov_reconcile_sweep();
wh_eq( 3, count( $GLOBALS['__pv_get_posts_calls'] ), 'sweep paginates through every Note batch' );
wh_eq( 3, $GLOBALS['__pv_get_posts_calls'][2]['paged'] ?? 0, 'sweep requests the final partial page' );
wh_eq( 'ID', $GLOBALS['__pv_get_posts_calls'][0]['orderby'] ?? '', 'sweep ordering is stable across pages' );
$GLOBALS['__pv_get_posts_pages'] = array();

echo "\nTask 6: manual sweep trigger (sn_prov_run_sweep)\n";
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http'] = array();
$res = sn_prov_run_sweep();
wh_eq( 1, count( $GLOBALS['__pv_http'] ), 'one sweep POST fired' );
$swept = $GLOBALS['__pv_http'][0];
wh_eq( 'https://worker.example/sweep', $swept[0], 'posts to <worker>/sweep (trailing slash collapsed)' );
$expected_sig = 'sha256=' . hash_hmac( 'sha256', $swept[1]['body'], 'shh' );
wh_eq( $expected_sig, $swept[1]['headers']['X-SN-Signature'], 'sweep request HMAC-signed over the body' );
wh_true( ! empty( $res['ok'] ), 'configured sweep returns ok' );
wh_eq( 2, $res['upgraded'], 'summary upgraded count parsed' );
wh_eq( 1, $res['still_pending'], 'summary still_pending parsed (from Worker stillPending)' );

// Unconfigured (no secret) → no POST, ok:false.
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = '';
$GLOBALS['__pv_http'] = array();
$res2 = sn_prov_run_sweep();
wh_eq( 0, count( $GLOBALS['__pv_http'] ), 'unconfigured: no sweep POST fired' );
wh_true( empty( $res2['ok'] ) && 'unconfigured' === ( $res2['error'] ?? '' ), 'unconfigured → ok:false error:unconfigured' );
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh'; // restore

// Worker replies ok:false → result ok:false (not silently treated as success).
$GLOBALS['__pv_sweep_body'] = array( 'ok' => false, 'error' => 'x' );
$GLOBALS['__pv_http'] = array();
$res3 = sn_prov_run_sweep();
wh_true( empty( $res3['ok'] ), 'worker ok:false body → result ok:false' );
unset( $GLOBALS['__pv_sweep_body'] );

echo "\nTask 7: confirm handler routes the genesis sentinel to the option path\n";
// provenance-genesis.php is NOT loaded here — stub the genesis applier to capture
// that the handler routes note_uid 'genesis' to it (not the post-chain path).
$GLOBALS['__pv_genesis_applied'] = null;
if ( ! function_exists( 'sn_prov_apply_genesis_confirmation' ) ) {
	function sn_prov_apply_genesis_confirmation( array $data ) {
		$GLOBALS['__pv_genesis_applied'] = $data;
		return true; }
}
$gbody = wp_json_encode( array( 'note_uid' => 'genesis', 'version' => 0, 'content_hash' => 'aa', 'status' => 'confirmed' ) );
$gresp = sn_prov_confirm_handler( new WP_REST_Request( array(), $gbody ) );
wh_true( is_array( $GLOBALS['__pv_genesis_applied'] ), 'genesis payload routed to sn_prov_apply_genesis_confirmation' );
wh_eq( 200, $gresp->status ?? null, 'genesis confirm returns 200 on success' );
wh_eq( 'confirmed', $GLOBALS['__pv_genesis_applied']['status'] ?? null, 'genesis applier receives the confirm payload' );
// A Note payload must NOT hit the genesis path.
$GLOBALS['__pv_genesis_applied'] = null;
$nbody = wp_json_encode( array( 'note_uid' => 'u', 'version' => 1, 'status' => 'confirmed' ) );
sn_prov_confirm_handler( new WP_REST_Request( array(), $nbody ) );
wh_eq( null, $GLOBALS['__pv_genesis_applied'], 'a Note confirm does NOT hit the genesis path' );

echo "\nTask 8: outbound hardening — redirection=0 + shared SSRF gate (CMA LOW-1)\n";
// The shared provenance outbound gate: https + public host only, fail closed.
wh_true( sn_prov_url_allowed( 'https://worker.example/' ), 'gate allows an https public host' );
wh_true( ! sn_prov_url_allowed( 'http://worker.example/' ), 'gate blocks a plain-http URL (scheme)' );
wh_true( ! sn_prov_url_allowed( 'https://2852039166/' ), 'gate blocks the decimal-encoded metadata IP' );
wh_true( ! sn_prov_url_allowed( 'https://blocked-internal.example/' ), 'gate blocks an RFC-1918 hostname' );
wh_true( ! sn_prov_url_allowed( '' ), 'gate blocks an empty URL (fail closed)' );

$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';

// dispatch(): the POST carries redirection => 0.
update_post_meta( 42, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'unanchored' ) ) );
update_post_meta( 42, SN_PROV_UID_META, 'u' );
$GLOBALS['__pv_http'] = array();
sn_prov_dispatch( 42, array( 'version' => 1, 'content_hash' => 'aa' ), '{"x":1}' );
wh_eq( 1, count( $GLOBALS['__pv_http'] ), 'dispatch fired one POST for an allowed host' );
wh_eq( 0, $GLOBALS['__pv_http'][0][1]['redirection'] ?? -1, 'dispatch POST sets redirection => 0' );

// dispatch(): a blocked (plain-http) worker URL fires NO POST.
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'http://worker.example/';
$GLOBALS['__pv_http'] = array();
sn_prov_dispatch( 42, array( 'version' => 1, 'content_hash' => 'aa' ), '{"x":1}' );
wh_eq( 0, count( $GLOBALS['__pv_http'] ), 'dispatch fires NO POST when the gate blocks the URL' );

// run_sweep(): redirection => 0 on an allowed host; blocked host → ok:false error:blocked, no POST.
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'https://worker.example/';
$GLOBALS['__pv_http'] = array();
$sw = sn_prov_run_sweep();
wh_true( ! empty( $sw['ok'] ), 'run_sweep ok for an allowed host' );
wh_eq( 0, $GLOBALS['__pv_http'][0][1]['redirection'] ?? -1, 'run_sweep POST sets redirection => 0' );
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'http://worker.example/';
$GLOBALS['__pv_http'] = array();
$sw2 = sn_prov_run_sweep();
wh_true( empty( $sw2['ok'] ) && 'blocked' === ( $sw2['error'] ?? '' ), 'run_sweep blocked host → ok:false error:blocked' );
wh_eq( 0, count( $GLOBALS['__pv_http'] ), 'run_sweep fires NO POST when blocked' );

// worker_version(): redirection => 0 on the GET; returns the version; blocked → '' + no GET.
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'https://worker.example/';
$GLOBALS['__pv_transients'] = array();
$GLOBALS['__pv_http_get']  = array();
$ver = sn_prov_worker_version();
wh_eq( '3.1.0', $ver, 'worker_version returns the probed version for an allowed host' );
wh_eq( 0, $GLOBALS['__pv_http_get'][0][1]['redirection'] ?? -1, 'worker_version GET sets redirection => 0' );
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'http://worker.example/';
$GLOBALS['__pv_transients'] = array();
$GLOBALS['__pv_http_get']  = array();
$verb = sn_prov_worker_version();
wh_eq( '', $verb, 'worker_version returns empty for a blocked host' );
wh_eq( 0, count( $GLOBALS['__pv_http_get'] ), 'worker_version fires NO GET when blocked' );

echo "\nTask 9: async dispatch — commit enqueues a near-term cron, no synchronous POST (INFO-1)\n";
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_sched'] = array();
$GLOBALS['__pv_http']  = array();
sn_prov_enqueue_dispatch( 42, array( 'version' => 1, 'content_hash' => 'aa' ), '{"x":1}' );
wh_eq( 0, count( $GLOBALS['__pv_http'] ), 'enqueue fires NO synchronous POST (off the editor save path)' );
wh_eq( 1, count( $GLOBALS['__pv_sched'] ), 'enqueue schedules exactly one event' );
wh_eq( 'sn_prov_dispatch_async', $GLOBALS['__pv_sched'][0]['hook'] ?? '', 'enqueue schedules the async dispatch hook' );
wh_eq( array( 42 ), $GLOBALS['__pv_sched'][0]['args'] ?? null, 'scheduled event carries just the post id' );
// A second enqueue while one is pending dedups (reconcile catches all unanchored anyway).
sn_prov_enqueue_dispatch( 42, array( 'version' => 1, 'content_hash' => 'aa' ), '{"x":1}' );
wh_eq( 1, count( $GLOBALS['__pv_sched'] ), 'a second enqueue for the same post does not double-schedule' );

// ── v9.88.0 (hardening gate): the confirm callback must not be forgeable ──
// The Worker signs ledger payloads and confirm callbacks with ONE Ed25519 key
// and no domain separation, and a signed ledger payload is published publicly
// (credential proof.signedPayloadB64 + the GitHub ledger). Replaying one as a
// confirm body used to verify: it carries no content_hash (the integrity belt
// was isset()-conditional) and no status (which defaulted to 'confirmed'), so
// it flipped any pending commit to Verified. Both holes are closed here.
update_post_meta( 42, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'pending' ) ) );
wh_eq( false, sn_prov_apply_confirmation( 'u', 1, array( 'status' => 'confirmed' ) ), 'a payload with NO content_hash is rejected (replayed ledger payload)' );
$c = get_post_meta( 42, SN_PROV_CHAIN_META, true );
wh_eq( 'pending', $c[0]['status'], 'the rejected replay left the commit pending' );
wh_eq( false, sn_prov_apply_confirmation( 'u', 1, array( 'content_hash' => 'aa' ) ), 'a payload with NO status is rejected (no defaulting to confirmed)' );
wh_eq( false, sn_prov_apply_confirmation( 'u', 1, array( 'content_hash' => 'bb', 'status' => 'confirmed' ) ), 'a payload whose content_hash disagrees is rejected' );
wh_eq( false, sn_prov_apply_confirmation( 'u', 1, array( 'content_hash' => 'aa', 'status' => 'nonsense' ) ), 'an out-of-allowlist status is rejected, not coerced' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
