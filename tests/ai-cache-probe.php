<?php
/**
 * Standalone fixture tests for inc/ai-cache-probe.php.
 *
 * The probe exists to settle one question with evidence — would Anthropic
 * prompt caching pay here — so its tests are mostly about NOT lying:
 *
 *   1. Read-only: the http_response filter returns the response untouched on
 *      every path, recorded or not. A measurement hook that mutates traffic
 *      is worse than no hook.
 *   2. Absent != zero: a usage object with no cache keys records null, not 0.
 *      "Never measured" and "measured, no caching" are different answers, and
 *      collapsing them would manufacture a verdict.
 *   3. prefix_hash is a PREFIX identity: it must ignore `messages` (which sit
 *      after the cache breakpoint) and must change with model/system/tools.
 *      If it tracked messages, every entry would look unique and the repeat
 *      rate would read as zero — the exact false negative that would kill a
 *      caching decision on bad data.
 *   4. Host match is exact: a URL that merely MENTIONS api.anthropic.com
 *      (a webhook payload, a proxied callback) is not an Anthropic API call.
 *
 * Stubs mirror the real shapes the module consumes: WP's response array
 * (headers/body/response.code), the `http_response` filter's
 * ($response, $args, $url) signature, and — per upstream
 * WordPress/anthropic-ai-provider trunk — a request body whose `system` is a
 * BARE STRING, not a content-block array.
 *
 * @since plugin v10.50.0
 */

// SECURITY: fixture, not a runtime module. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__filters']  = array();
$GLOBALS['__hooked']   = array();
$GLOBALS['__options']  = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['__hooked'][ $tag ][] = array( $callback, $priority, $args );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		if ( ! isset( $GLOBALS['__filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__filters'][ $tag ] as $cb ) {
			$value   = call_user_func_array( $cb, $args );
			$args[0] = $value;
		}
		return $value;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public function __construct( $code = '' ) {
			$this->code = $code;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
// Real WP shape: 'response' => array('code' => int, 'message' => string).
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

require_once __DIR__ . '/../inc/ai-cache-probe.php';

// ─── Assertions ───────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function hc_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}
function hc_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n";
	}
}

// ─── Fixtures ─────────────────────────────────────────────────────────
const MSG_URL = 'https://api.anthropic.com/v1/messages';

/**
 * A request body in the shape the WP AI Client's Anthropic provider actually
 * emits: `system` as a bare string, no cache_control anywhere.
 */
function fx_request( $system = 'You are an editor.', $tools = array(), $messages = null ) {
	$req = array(
		'model'      => 'claude-sonnet-5',
		'messages'   => null === $messages ? array( array( 'role' => 'user', 'content' => 'hello' ) ) : $messages,
		'system'     => $system,
		'max_tokens' => 512,
	);
	if ( $tools ) {
		$req['tools'] = $tools;
	}
	return $req;
}

function fx_response( $usage = array( 'input_tokens' => 400, 'output_tokens' => 30 ), $code = 200, $model = 'claude-sonnet-5' ) {
	$body = array( 'model' => $model, 'content' => array( array( 'type' => 'text', 'text' => 'ok' ) ) );
	if ( null !== $usage ) {
		$body['usage'] = $usage;
	}
	return array(
		'headers'  => array(),
		'body'     => (string) json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Error' ),
	);
}

function fx_args( $request ) {
	// WordPress_HTTP_Client passes the body as a JSON STRING in $args['body'].
	return array( 'method' => 'POST', 'body' => (string) json_encode( $request ) );
}

function fx_reset() {
	$GLOBALS['__options'] = array();
	$GLOBALS['__filters'] = array();
}

function fx_log() {
	$log = get_option( SN_AI_CACHE_PROBE_OPT, array() );
	return is_array( $log ) ? $log : array();
}

// ─── Group: registration ──────────────────────────────────────────────
echo "\nGroup: filter registration\n";
hc_true( isset( $GLOBALS['__hooked']['http_response'] ), 'probe hooks http_response' );
hc_eq( 3, $GLOBALS['__hooked']['http_response'][0][2], 'hooked with 3 args ($response, $args, $url)' );
hc_eq(
	'snt_ai_cache_probe_record',
	$GLOBALS['__hooked']['http_response'][0][0],
	'hooks the record callback'
);

// ─── Group: URL gating ────────────────────────────────────────────────
echo "\nGroup: URL gating\n";

fx_reset();
$resp = fx_response();
$out  = snt_ai_cache_probe_record( $resp, fx_args( fx_request() ), 'https://api.github.com/repos/x/y' );
hc_eq( 0, count( fx_log() ), 'unrelated host records nothing' );
hc_eq( $resp, $out, 'unrelated host returns the response untouched' );

fx_reset();
// A webhook whose QUERY STRING mentions the host is not an API call. This is
// why the fast strpos() bail is followed by an exact host comparison.
snt_ai_cache_probe_record( fx_response(), fx_args( fx_request() ), 'https://example.com/hook?upstream=api.anthropic.com' );
hc_eq( 0, count( fx_log() ), 'host mentioned in a query string is not recorded' );

fx_reset();
snt_ai_cache_probe_record( fx_response(), fx_args( fx_request() ), 'https://api.anthropic.com/v1/models' );
hc_eq( 0, count( fx_log() ), 'a non-/v1/messages Anthropic endpoint is not recorded' );

fx_reset();
snt_ai_cache_probe_record( fx_response(), fx_args( fx_request() ), MSG_URL );
hc_eq( 1, count( fx_log() ), '/v1/messages on the real host is recorded' );

// ─── Group: read-only contract ────────────────────────────────────────
echo "\nGroup: read-only contract\n";

fx_reset();
$resp = fx_response();
$out  = snt_ai_cache_probe_record( $resp, fx_args( fx_request() ), MSG_URL );
hc_eq( $resp, $out, 'recorded call returns the response byte-identical' );

fx_reset();
$args_before = fx_args( fx_request() );
$args_copy   = $args_before;
snt_ai_cache_probe_record( fx_response(), $args_before, MSG_URL );
hc_eq( $args_copy, $args_before, 'request args are not mutated' );

fx_reset();
$err = new WP_Error( 'http_request_failed' );
$out = snt_ai_cache_probe_record( $err, fx_args( fx_request() ), MSG_URL );
hc_true( $out === $err, 'WP_Error response passes through by identity' );
hc_eq( 0, count( fx_log() ), 'WP_Error response records nothing' );

// ─── Group: non-200 is not a measured zero ────────────────────────────
echo "\nGroup: non-200 handling\n";
fx_reset();
snt_ai_cache_probe_record( fx_response( null, 429 ), fx_args( fx_request() ), MSG_URL );
hc_eq( 0, count( fx_log() ), '429 records nothing (no usage object to measure)' );

fx_reset();
$bad = fx_response();
$bad['body'] = 'not json';
snt_ai_cache_probe_record( $bad, fx_args( fx_request() ), MSG_URL );
hc_eq( 0, count( fx_log() ), 'undecodable response body records nothing' );

// ─── Group: kill switch ───────────────────────────────────────────────
echo "\nGroup: kill switch\n";
fx_reset();
$GLOBALS['__filters']['snt_ai_cache_probe_enabled'][] = static function () {
	return false;
};
$resp = fx_response();
$out  = snt_ai_cache_probe_record( $resp, fx_args( fx_request() ), MSG_URL );
hc_eq( 0, count( fx_log() ), 'snt_ai_cache_probe_enabled=false records nothing' );
hc_eq( $resp, $out, 'disabled probe still returns the response untouched' );

// ─── Group: absent vs measured-zero ───────────────────────────────────
echo "\nGroup: absent cache keys are null, not zero\n";

// Today's reality: the provider sends no cache_control, so Anthropic returns
// the cache keys as 0 — measured, no caching. That must NOT look like "never
// measured", and vice versa.
$e_absent = snt_ai_cache_probe_entry( fx_request(), array( 'usage' => array( 'input_tokens' => 400, 'output_tokens' => 30 ) ) );
hc_eq( null, $e_absent['cache_read'], 'absent cache_read_input_tokens records null' );
hc_eq( null, $e_absent['cache_write'], 'absent cache_creation_input_tokens records null' );
hc_eq( 400, $e_absent['in'], 'input_tokens recorded as reported' );

$e_zero = snt_ai_cache_probe_entry(
	fx_request(),
	array( 'usage' => array( 'input_tokens' => 400, 'output_tokens' => 30, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0 ) )
);
hc_eq( 0, $e_zero['cache_read'], 'present-and-zero cache_read records 0, not null' );
hc_true( null !== $e_zero['cache_write'], 'present-and-zero cache_write is distinguishable from absent' );

$e_hit = snt_ai_cache_probe_entry(
	fx_request(),
	array( 'usage' => array( 'input_tokens' => 12, 'output_tokens' => 30, 'cache_read_input_tokens' => 5800, 'cache_creation_input_tokens' => 0 ) )
);
hc_eq( 5800, $e_hit['cache_read'], 'a real cache read is recorded verbatim' );

hc_eq( 0, snt_ai_cache_probe_summary( array( $e_absent ) )['measured'], 'summary: null cache fields do not count as measured' );
hc_eq( 1, snt_ai_cache_probe_summary( array( $e_zero ) )['measured'], 'summary: zero cache fields DO count as measured' );

// ─── Group: prefix_hash is a prefix identity ──────────────────────────
echo "\nGroup: prefix_hash identity\n";

$tools = array( array( 'name' => 'get_posts', 'input_schema' => array( 'type' => 'object' ) ) );

$a = snt_ai_cache_probe_entry( fx_request( 'SYS A', $tools ), array() );
$b = snt_ai_cache_probe_entry( fx_request( 'SYS A', $tools ), array() );
hc_eq( $a['prefix_hash'], $b['prefix_hash'], 'identical model+tools+system share a prefix_hash' );

// The load-bearing case: a Copilot conversation grows its messages array every
// turn while tools+system stay fixed. If messages fed the hash, every turn
// would look unique and the repeat rate would read as a false zero.
$turn2 = snt_ai_cache_probe_entry(
	fx_request( 'SYS A', $tools, array( array( 'role' => 'user', 'content' => 'a much longer follow-up turn' ) ) ),
	array()
);
hc_eq( $a['prefix_hash'], $turn2['prefix_hash'], 'differing messages do NOT change the prefix_hash' );

$diff_sys   = snt_ai_cache_probe_entry( fx_request( 'SYS B', $tools ), array() );
$diff_tools = snt_ai_cache_probe_entry( fx_request( 'SYS A', array() ), array() );
hc_true( $a['prefix_hash'] !== $diff_sys['prefix_hash'], 'a different system instruction changes the prefix_hash' );
hc_true( $a['prefix_hash'] !== $diff_tools['prefix_hash'], 'a different tool set changes the prefix_hash' );

$req_model          = fx_request( 'SYS A', $tools );
$req_model['model'] = 'claude-haiku-4-5';
$diff_model         = snt_ai_cache_probe_entry( $req_model, array() );
hc_true( $a['prefix_hash'] !== $diff_model['prefix_hash'], 'a different model changes the prefix_hash (caches are model-scoped)' );

// ─── Group: sizes ─────────────────────────────────────────────────────
echo "\nGroup: recorded sizes\n";
$sized = snt_ai_cache_probe_entry( fx_request( 'SYS A', $tools ), array() );
hc_eq( 1, $sized['tools_count'], 'tools_count counts tool definitions' );
hc_eq( strlen( (string) json_encode( $tools ) ), $sized['tools_bytes'], 'tools_bytes measures the serialized tool set' );
hc_eq( strlen( (string) json_encode( 'SYS A' ) ), $sized['sys_bytes'], 'sys_bytes measures a bare-string system (the shape the provider sends)' );
hc_eq( 1, $sized['msg_count'], 'msg_count counts messages' );
hc_true( $sized['req_bytes'] > $sized['tools_bytes'], 'req_bytes covers the whole body' );

$no_tools = snt_ai_cache_probe_entry( fx_request( 'SYS A' ), array() );
hc_eq( 0, $no_tools['tools_count'], 'an SN-shaped call (no tools) records tools_count 0' );
hc_eq( 0, $no_tools['tools_bytes'], 'an SN-shaped call records tools_bytes 0' );

// A body we could not decode still yields a row rather than silence.
$partial = snt_ai_cache_probe_entry( array(), array( 'model' => 'claude-sonnet-5', 'usage' => array( 'input_tokens' => 1 ) ) );
hc_eq( 0, $partial['req_bytes'], 'undecodable request degrades to a partial row (req_bytes 0)' );
hc_eq( 'claude-sonnet-5', $partial['model'], 'model still recovered from the response on a partial row' );

// ─── Group: summary verdict fields ────────────────────────────────────
echo "\nGroup: summary verdict\n";

$now = 1770000000;
$log = array(
	array( 'ts' => $now,       'prefix_hash' => 'aaa', 'tools_bytes' => 23000, 'sys_bytes' => 500 ),
	array( 'ts' => $now + 30,  'prefix_hash' => 'aaa', 'tools_bytes' => 23000, 'sys_bytes' => 500 ), // repeat, in TTL
	array( 'ts' => $now + 60,  'prefix_hash' => 'bbb', 'tools_bytes' => 0,     'sys_bytes' => 1600 ),
	array( 'ts' => $now + 900, 'prefix_hash' => 'bbb', 'tools_bytes' => 0,     'sys_bytes' => 1600 ), // repeat, TTL expired
);
$sum = snt_ai_cache_probe_summary( $log );
hc_eq( 4, $sum['calls'], 'summary counts every call' );
hc_eq( 2, $sum['prefixes'], 'summary counts distinct prefixes' );
hc_eq( 1, $sum['repeatable'], 'only a repeat INSIDE the TTL counts as repeatable' );
hc_eq( 23500, $sum['max_prefix_bytes'], 'max_prefix_bytes is tools + system (the cacheable span)' );

$sum_empty = snt_ai_cache_probe_summary( array() );
hc_eq( 0, $sum_empty['calls'], 'empty log summarises to zeros, not a warning' );
hc_eq( 0, $sum_empty['repeatable'], 'empty log has no repeatable calls' );

$sum_totals = snt_ai_cache_probe_summary( array( $e_hit, $e_zero ) );
hc_eq( 5800, $sum_totals['cache_read'], 'summary totals cache reads' );
hc_eq( 2, $sum_totals['measured'], 'summary counts measured rows' );

hc_true( is_array( snt_ai_cache_probe_summary( 'garbage' ) ), 'a non-array log summarises without fataling' );

// ─── Group: FIFO cap ──────────────────────────────────────────────────
echo "\nGroup: FIFO cap\n";
fx_reset();
for ( $i = 0; $i < SN_AI_CACHE_PROBE_CAP + 5; $i++ ) {
	snt_ai_cache_probe_append( array( 'ts' => $now + $i, 'prefix_hash' => 'zzz' ) );
}
$log = fx_log();
hc_eq( SN_AI_CACHE_PROBE_CAP, count( $log ), 'log is capped at SN_AI_CACHE_PROBE_CAP' );
hc_eq( $now + SN_AI_CACHE_PROBE_CAP + 4, $log[ count( $log ) - 1 ]['ts'], 'cap evicts the OLDEST entries (FIFO)' );

fx_reset();
$GLOBALS['__options'][ SN_AI_CACHE_PROBE_OPT ] = 'corrupt';
snt_ai_cache_probe_append( array( 'ts' => $now, 'prefix_hash' => 'zzz' ) );
hc_eq( 1, count( fx_log() ), 'a corrupt option value is replaced, not appended to' );

// ─── Group: no prompt content is ever stored ──────────────────────────
echo "\nGroup: privacy — sizes only, never content\n";
$secret_sys = 'SECRET-SYSTEM-INSTRUCTION';
$secret_msg = 'SECRET-USER-PROMPT';
$entry      = snt_ai_cache_probe_entry(
	fx_request( $secret_sys, $tools, array( array( 'role' => 'user', 'content' => $secret_msg ) ) ),
	array( 'usage' => array( 'input_tokens' => 1, 'output_tokens' => 1 ) )
);
$flat = (string) json_encode( $entry );
hc_true( false === strpos( $flat, $secret_sys ), 'system instruction text is never stored' );
hc_true( false === strpos( $flat, $secret_msg ), 'prompt text is never stored' );
hc_true( false === strpos( $flat, 'get_posts' ), 'tool schemas are never stored' );
hc_true( false === strpos( $flat, 'ok' ), 'response content is never stored' );

/* ════════════════════════════════════════════════════════════════════════
 * v11.8.0 — per-model content-free samples, so a model the site never pins
 * can actually be placed instead of just counted.
 * ════════════════════════════════════════════════════════════════════════ */

// Mirrors the real shape: a dominant model plus 2 calls on one SN never pins
// (SN_AI_DEFAULT_MODEL and sn_theme_ai_models() both exclude claude-sonnet-4-6),
// which is exactly the situation the panel could not explain.
$mix_log = array();
for ( $i = 0; $i < 6; $i++ ) {
	$mix_log[] = array(
		'ts' => 1000 + $i, 'model' => 'claude-sonnet-5', 'prefix_hash' => 'aaaa',
		'tools_bytes' => 50000, 'sys_bytes' => 8000, 'tools_count' => 12, 'msg_count' => 3, 'in' => 19000,
	);
}
$mix_log[] = array(
	'ts' => 2000, 'model' => 'claude-sonnet-4-6', 'prefix_hash' => 'bbbb',
	'tools_bytes' => 0, 'sys_bytes' => 2863, 'tools_count' => 0, 'msg_count' => 1, 'in' => 900,
);
$mix_log[] = array(
	'ts' => 2100, 'model' => 'claude-sonnet-4-6', 'prefix_hash' => 'cccc',
	'tools_bytes' => 0, 'sys_bytes' => 2000, 'tools_count' => 0, 'msg_count' => 1, 'in' => 700,
);
$mix_v = snt_ai_cache_probe_verdict( $mix_log );

$by_model = array();
foreach ( $mix_v['models'] as $mm ) {
	$by_model[ $mm['model'] ] = $mm;
}
hc_true( isset( $by_model['claude-sonnet-4-6']['samples'] ), 'verdict: a non-dominant model carries samples' );
hc_true( 2 === count( $by_model['claude-sonnet-4-6']['samples'] ), 'verdict: both of its calls are sampled' );
// Newest first — the recognisable end of a rolling log.
hc_true( 2100 === (int) $by_model['claude-sonnet-4-6']['samples'][0]['ts'], 'verdict: samples are newest-first' );
// The two fields that actually discriminate a caller.
hc_true( 0 === (int) $by_model['claude-sonnet-4-6']['samples'][0]['tools_count'], 'verdict: tools_count is carried (0 here = not an agent run)' );
hc_true( 12 === (int) $by_model['claude-sonnet-5']['samples'][0]['tools_count'], 'verdict: a tool-carrying call is distinguishable from a bare one' );
hc_true( 2863 === (int) $by_model['claude-sonnet-4-6']['samples'][1]['sys_bytes'], 'verdict: sys_bytes is carried' );

// Bounded: a rolling 200-entry log must not become a 200-row panel.
$big = array();
for ( $i = 0; $i < 40; $i++ ) {
	$big[] = array( 'ts' => 5000 + $i, 'model' => 'claude-opus-5', 'prefix_hash' => 'h' . $i, 'tools_bytes' => 10, 'sys_bytes' => 10, 'tools_count' => 0, 'msg_count' => 1 );
}
$big_v = snt_ai_cache_probe_verdict( $big );
hc_true( count( $big_v['models'][0]['samples'] ) === SN_AI_CACHE_PROBE_SAMPLES, 'verdict: samples are capped at SN_AI_CACHE_PROBE_SAMPLES (' . SN_AI_CACHE_PROBE_SAMPLES . '), not unbounded' );
hc_true( 5039 === (int) $big_v['models'][0]['samples'][0]['ts'], 'verdict: the cap keeps the NEWEST rows, not the first ones seen' );

// The privacy pin still holds with samples attached — same assertion style as
// the existing sweep above, re-run against the verdict (not just the entry).
$flat_v = wp_json_encode( $mix_v );
hc_true( false === strpos( (string) $flat_v, 'secret' ), 'verdict with samples: still no prompt/system text anywhere in the payload' );
// Pin the sample SHAPE exactly. An allowlist of keys is what stops a future
// edit from quietly widening a content-free record into a content-carrying one
// — asserting "no secrets in this fixture" would pass even then.
$sample_keys = array_keys( $by_model['claude-sonnet-4-6']['samples'][0] );
sort( $sample_keys );
hc_true(
	array( 'msg_count', 'sys_bytes', 'tools_count', 'ts' ) === $sample_keys,
	'verdict with samples: a sample carries EXACTLY ts/tools_count/sys_bytes/msg_count — no field can be widened in without failing here'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
