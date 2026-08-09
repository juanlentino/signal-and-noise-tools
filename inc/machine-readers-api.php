<?php
/**
 * Signal & Noise Tools — Machine Readers: sensor read + row normalization.
 *
 * Session 3 lane 1 (plan: docs/superpowers/specs/2026-07-28-session-3-machine-readers-plan.md).
 *
 * Contract (sensor v1.4.0): GET /_sn/rights-signals/machine-readers?days=N,
 * Bearer SN_MR_READ_TOKEN; 200 { worker, days, data: [{family,surface,day,hits}] }.
 * Every worker value is UNTRUSTED input: rows normalize through the fixed enum
 * allowlists below (unknown family → 'other-bot', unknown surface → 'html'),
 * fetches ride inc/ssrf-guard.php, token handling mirrors inc/analytics-api.php
 * (write-only field, never echoed, autoload=no).
 *
 * Pure data layer: no output sinks live here. The render lane still escapes
 * every cell at the sink, even though these values are allowlist-normalized.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The live sensor endpoint (sn-rights-signals-worker). Overridable via the
 * machine_readers.worker_url setting or the SN_MR_WORKER_URL constant.
 */
const SN_MR_DEFAULT_ENDPOINT = 'https://juanlentino.com/_sn/rights-signals/machine-readers';

/**
 * The sensor's fixed family enum (mirror of src/machine-readers.mjs in the
 * worker repo — extend BOTH or neither).
 *
 * @return string[]
 */
function snt_mr_valid_families() {
	return array(
		'openai', 'anthropic', 'google-ai', 'perplexity', 'commoncrawl',
		'bytedance', 'amazon-ai', 'apple-ai', 'meta-ai', 'mistral', 'cohere',
		'allen-ai', 'diffbot', 'search', 'seo', 'feed', 'uptime', 'other-bot',
	);
}

/**
 * The sensor's fixed surface-class enum (same mirror rule).
 *
 * @return string[]
 */
function snt_mr_valid_surfaces() {
	return array( 'robots', 'rights', 'llms', 'agents-manifest', 'well-known', 'feed', 'wp-json', 'sitemap', 'asset', 'html' );
}

/**
 * Resolve worker URL + read token, constant first (the inc/analytics-api.php
 * shape: a defined, non-empty wp-config constant always wins over the setting).
 * SN_MR_READ_TOKEN falls back to machine_readers.read_token (write-only admin
 * field, stored autoload=no, never echoed back); SN_MR_WORKER_URL falls back to
 * machine_readers.worker_url, then the live default. The URL always resolves;
 * a non-null return requires a non-empty token.
 *
 * @return array{url:string,token:string}|null
 */
function snt_mr_config() {
	$token = ( defined( 'SN_MR_READ_TOKEN' ) && '' !== (string) SN_MR_READ_TOKEN )
		? (string) SN_MR_READ_TOKEN
		: ( function_exists( 'sn_setting' ) ? (string) sn_setting( 'machine_readers.read_token', '' ) : '' );

	$url = ( defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL )
		? (string) SN_MR_WORKER_URL
		: ( function_exists( 'sn_setting' ) ? (string) sn_setting( 'machine_readers.worker_url', SN_MR_DEFAULT_ENDPOINT ) : SN_MR_DEFAULT_ENDPOINT );

	// v9.85.1: the settings form promises "blank uses the built-in live
	// endpoint" and the save handler stores '' for a blank field — so a
	// stored-empty URL means the default, not unconfigured. Only a missing
	// token makes config null (the v9.85.0 yellow-banner bug).
	if ( '' === $url ) {
		$url = SN_MR_DEFAULT_ENDPOINT;
	}

	if ( '' === $token ) {
		return null;
	}
	return array(
		'url'   => $url,
		'token' => $token,
	);
}

/**
 * Normalize raw worker rows: allowlist-coerce family/surface, sanitize day to
 * YYYY-MM-DD (a failing day becomes '', still escaped at the render sink),
 * coerce hits to non-negative int, drop rows that are not arrays. Pure: canned
 * rows testable, input never mutated, a new array returned.
 *
 * @param mixed $data The decoded `data` member of the sensor response.
 * @return array<int,array{family:string,surface:string,day:string,hits:int}>
 */
function snt_mr_normalize_rows( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}
	$families = snt_mr_valid_families();
	$surfaces = snt_mr_valid_surfaces();
	$rows     = array();
	foreach ( $data as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$family  = is_string( $row['family'] ?? null ) ? $row['family'] : '';
		$surface = is_string( $row['surface'] ?? null ) ? $row['surface'] : '';
		$day     = is_string( $row['day'] ?? null ) ? $row['day'] : '';
		$rows[]  = array(
			'family'  => in_array( $family, $families, true ) ? $family : 'other-bot',
			'surface' => in_array( $surface, $surfaces, true ) ? $surface : 'html',
			'day'     => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : '',
			'hits'    => is_numeric( $row['hits'] ?? null ) ? max( 0, (int) $row['hits'] ) : 0,
		);
	}
	return $rows;
}

/**
 * Fetch + normalize the sensor aggregates.
 *
 * Missing token is LOUD (ok=false, error=not_configured), never a silent empty
 * result. The request rides the shared outbound gate (https-only, core URL
 * validation, the resolve-then-range-check SSRF guard) with redirection=0 so a
 * validated host cannot redirect the Bearer token to an internal one. Non-200
 * and schema-invalid responses fail closed. Success is held in a short display
 * transient (volatile-OK under Breeze; nothing durable lives here).
 *
 * @param int $days Window, clamped 1..90.
 * @return array{ok:bool,rows:array,error:?string}
 */
function snt_mr_fetch( $days = 30 ) {
	$days = max( 1, min( 90, (int) $days ) );

	$cfg = snt_mr_config();
	if ( null === $cfg ) {
		return array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' );
	}

	$cache_key = 'sn_mr_rows_' . $days;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && true === ( $cached['ok'] ?? false ) ) {
		return $cached;
	}

	$url = $cfg['url'] . ( false === strpos( $cfg['url'], '?' ) ? '?' : '&' ) . 'days=' . $days;

	// Same outbound gate as every other probe (webhooks / uptime / worker-version):
	// https-only + core URL validation + the shared resolve-then-range-check guard.
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		return array( 'ok' => false, 'rows' => array(), 'error' => 'blocked' );
	}

	$resp = wp_remote_get( $url, array(
		'timeout'     => 6,
		// The host filter only sees the first hop; redirection=0 keeps the token there.
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'Authorization' => 'Bearer ' . $cfg['token'],
			'Accept'        => 'application/json',
		),
	) );

	if ( is_wp_error( $resp ) ) {
		return array( 'ok' => false, 'rows' => array(), 'error' => 'network' );
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 !== $code ) {
		return array( 'ok' => false, 'rows' => array(), 'error' => 'http_' . $code );
	}

	$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
		return array( 'ok' => false, 'rows' => array(), 'error' => 'bad_schema' );
	}

	$result = array(
		'ok'    => true,
		'rows'  => snt_mr_normalize_rows( $decoded['data'] ),
		'error' => null,
	);
	set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
	return $result;
}

/**
 * The worker's public crawler-list-status endpoint (no auth; a fixed sibling of
 * the sensor endpoint, never derived from settings).
 */
const SN_MR_CRAWLER_STATUS_URL = 'https://juanlentino.com/_sn/rights-signals/crawler-list-status';

/**
 * Fetch the public crawler-list-status document through the same outbound gate
 * as snt_mr_fetch. Untrusted worker JSON: only scalar top-level members
 * survive, capped and stringified here, escaped AGAIN at the render sink. Any
 * failure returns null so the admin card degrades to a quiet dash, never a
 * fatal. Success caches in a short display transient (volatile-OK).
 *
 * @return array<string,string>|null Flat scalar map, or null on any failure.
 */
/**
 * The sensor contract minimum this plugin's panels are built against. Bumps
 * with the read-path contract, beside the enum mirrors (extend BOTH repos).
 */
const SN_MR_SENSOR_MIN = '1.4.0';

/** The worker's public version endpoint (fixed, never configurable input). */
const SN_MR_VERSION_ENDPOINT = 'https://juanlentino.com/_sn/rights-signals/version';

/**
 * Deployed sensor version + deploy date (owner ask: "know the version and all
 * that" from inside wp-admin). Same outbound gate as every other read here;
 * version allowlisted to a SemVer-ish charset (a hostile body yields null, so
 * the card degrades to its quiet dash and no worker string reaches the page).
 * Null on ANY failure; 15-minute display transient.
 *
 * v10.70.2: the result carries `fetched_at`, because the card was describing
 * this as a live read and it is not — it is up to fifteen minutes old. That
 * cost real time on the v1.9.0 deploy: the worker was already reporting the
 * new version while the panel still showed the previous one, and the gap read
 * as a failed deploy rather than as a warm cache. The value was always stale;
 * only the label was wrong. A reader must be able to tell WHEN this was true.
 *
 * `fetched_at` is absent, never zero, on entries cached before this release —
 * the renderer omits the age line rather than printing an invented time.
 * Absent and "just now" are different answers.
 *
 * @return array{version:string,deployed_at:string,fetched_at?:int}|null
 */
function snt_mr_sensor_info() {
	$cached = get_transient( 'sn_mr_sensor_info' );
	if ( is_array( $cached ) && isset( $cached['version'] ) ) {
		return $cached;
	}

	$url  = SN_MR_VERSION_ENDPOINT;
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		return null;
	}

	$resp = wp_remote_get( $url, array(
		'timeout'     => 6,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array( 'Accept' => 'application/json' ),
	) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	$version = is_array( $decoded ) && is_string( $decoded['version'] ?? null ) ? $decoded['version'] : '';
	if ( 1 !== preg_match( '/^[0-9A-Za-z.+-]{1,32}$/', $version ) ) {
		return null;
	}
	$deployed = is_string( $decoded['deployed_at'] ?? null ) ? substr( $decoded['deployed_at'], 0, 32 ) : '';
	$info     = array(
		'version'     => $version,
		'deployed_at' => $deployed,
		'fetched_at'  => time(),
	);
	// v10.62.0: worker v1.6.0+ self-reports its AE sensor state — carry the
	// three known keys only (booleans/nulls + a clamped timestamp string),
	// never arbitrary worker JSON. Absent block (older worker) = no key, and
	// the health check treats that as an absent measurement, never a finding.
	if ( isset( $decoded['sensor'] ) && is_array( $decoded['sensor'] ) ) {
		$sensor = $decoded['sensor'];
		$info['sensor'] = array(
			'ae_bound'      => is_bool( $sensor['ae_bound'] ?? null ) ? $sensor['ae_bound'] : null,
			'last_write_ok' => is_bool( $sensor['last_write_ok'] ?? null ) ? $sensor['last_write_ok'] : null,
			'last_write_at' => is_string( $sensor['last_write_at'] ?? null ) ? substr( $sensor['last_write_at'], 0, 32 ) : null,
		);
	}
	set_transient( 'sn_mr_sensor_info', $info, 15 * MINUTE_IN_SECONDS );
	return $info;
}

function snt_mr_crawler_list_status() {
	$cached = get_transient( 'sn_mr_crawler_status' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$url  = SN_MR_CRAWLER_STATUS_URL;
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		return null;
	}

	$resp = wp_remote_get( $url, array(
		'timeout'     => 5,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array( 'Accept' => 'application/json' ),
	) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}

	$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $decoded ) ) {
		return null;
	}
	$flat = array();
	// v9.86.0: the status endpoint nests its payload under last_check — flatten
	// its scalar members (bools stringified) so the card can actually show the
	// drift verdict instead of dropping the whole object at the scalar cap.
	if ( isset( $decoded['last_check'] ) && is_array( $decoded['last_check'] ) ) {
		foreach ( $decoded['last_check'] as $lk => $lv ) {
			if ( is_scalar( $lv ) || null === $lv ) {
				$decoded[ 'last_check_' . $lk ] = is_bool( $lv ) ? ( $lv ? '1' : '' ) : (string) $lv;
			}
		}
		unset( $decoded['last_check'] );
	}
	foreach ( $decoded as $key => $value ) {
		if ( is_scalar( $value ) && count( $flat ) < 8 ) {
			$flat[ (string) $key ] = (string) $value;
		}
	}

	// v10.2.7: the last-known-good pattern (SN_WORKER_VERSION_LASTGOOD
	// precedent). The edge stores its verdict in isolate memory + the colo
	// cache, and BOTH are wiped by deploys and this site's own zone purges
	// (every plugin update auto-purges) — so a null verdict here is usually
	// "the edge just lost its copy", not "never checked". A completed verdict
	// is remembered durably (autoload no); a null-verdict response serves it,
	// with its own checked_at, instead of flickering the pill to "unchecked".
	// A site that has never seen a verdict still reports honestly unchecked,
	// and every NEWER verdict (including a drift flip) replaces the store.
	if ( isset( $flat['last_check_ok'] ) ) {
		if ( function_exists( 'update_option' ) ) {
			update_option( 'sn_mr_crawler_lastgood', $flat, false );
		}
	} elseif ( function_exists( 'get_option' ) ) {
		$lastgood = get_option( 'sn_mr_crawler_lastgood', null );
		if ( is_array( $lastgood ) && isset( $lastgood['last_check_ok'] ) ) {
			$flat = $lastgood;
		}
	}

	set_transient( 'sn_mr_crawler_status', $flat, 15 * MINUTE_IN_SECONDS );
	return $flat;
}

