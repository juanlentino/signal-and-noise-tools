<?php
/**
 * Admin-request HTTP-call diagnosis (self-diagnosing slow wp-admin loads).
 *
 * WHY: a measured 10.11s admin page-generation time against a 0.04s DB time
 * pointed straight at synchronous remote HTTP — Query Monitor showed 3 HTTP
 * API calls on the same request, but QM isn't installed everywhere this
 * plugin runs, and re-installing it just to find the next slow page is a
 * bad diagnostic loop. This module makes the plugin name the culprits
 * itself, permanently, via the SAME Site Health surface plugin-footprint.php
 * already established for "what's actually going on with this install".
 *
 * HOW: `http_request_args` stamps every outbound request made during an
 * admin request with a start time; `http_api_debug` (fired once the
 * request completes) reads that stamp back to compute a duration and
 * records {url, ms, code, error} into a request-static buffer. At
 * `shutdown`, IF that buffer is non-empty OR the whole page took longer
 * than SN_HTTPDIAG_WALL_THRESHOLD_S, the snapshot (screen + wall time +
 * every captured call) is prepended to a small ring-buffer option. Site
 * Health's Info tab then surfaces the 10 slowest snapshots ever logged.
 *
 * SECURITY: outbound admin HTTP calls routinely carry short-lived
 * tokens/keys as query params (a webhook URL, a signed API call). This log
 * is a plain wp_options row — any admin (or a future export) can read it —
 * so a secret must be UNSTORABLE by construction, not merely unlikely to
 * appear. sn_httpdiag_sanitize_url() rebuilds every stored URL from its
 * scheme+host+path components via wp_parse_url(); it NEVER touches the
 * query string, fragment, or userinfo, so there is no code path by which a
 * token could reach storage. The same discipline applies to the "screen"
 * label: only a hardcoded whitelist of query keys (page/tab/sub/sn_view —
 * the same keys inc/admin-page.php and inc/admin-tabs.php already read to
 * route the request) is read, and every value is sanitize_key()'d.
 *
 * SCOPE: this module never itself performs an HTTP request — it only reacts
 * to hooks WP already fires for calls other code makes, plus one bounded
 * option write on a qualifying request. It cannot be the thing that makes a
 * page slow.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A page load logs only past this wall-clock time, even with zero HTTP calls captured. */
const SN_HTTPDIAG_WALL_THRESHOLD_S = 2.0;

/** Ring-buffer ceiling: newest N logged page-loads are kept, oldest dropped. */
const SN_HTTPDIAG_RING_MAX = 50;

/** Per-entry ceiling: a runaway page firing 200 HTTP calls still writes a bounded row. */
const SN_HTTPDIAG_HTTP_MAX = 20;

/** The ONLY query keys ever read for the "screen" label — a whitelist, not a blacklist. */
const SN_HTTPDIAG_SCREEN_QUERY_KEYS = array( 'page', 'tab', 'sub', 'sn_view' );

/**
 * Reduce a request URL to scheme+host+path ONLY. Query string, fragment,
 * and userinfo (user:pass@) are always discarded — see the file header for
 * why this is a hard security boundary, not a convenience trim.
 *
 * @param string $url
 * @return string e.g. "https://api.example.com/v1/things", or '' when the
 *                URL has no parseable host (never falls back to the raw
 *                string — that could still carry the query).
 */
function sn_httpdiag_sanitize_url( $url ) {
	$url    = (string) $url;
	$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
	$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );

	if ( '' === $host ) {
		return '';
	}

	$prefix = '' !== $scheme ? $scheme . '://' : '';
	return $prefix . $host . $path;
}

/**
 * The request-static capture buffer. A single static array shared by every
 * sn_httpdiag_capture() call within one PHP request — WP re-includes this
 * file fresh on every request, so the buffer naturally starts empty each
 * time; $reset exists purely so tests can clear it between groups without
 * spinning up a new PHP process per assertion.
 *
 * @param array|null $append Entry to append, or null to just read.
 * @param bool       $reset  True to clear the buffer first (tests only).
 * @return array<int,array{url:string,ms:int,code:int,error:bool}>
 */
function sn_httpdiag_buffer( $append = null, $reset = false ) {
	static $buffer = array();

	if ( $reset ) {
		$buffer = array();
	}
	if ( null !== $append ) {
		$buffer[] = $append;
	}

	return $buffer;
}

/** @return string The live $pagenow global, or '' when unset. */
function sn_httpdiag_current_pagenow() {
	global $pagenow;
	return isset( $pagenow ) ? (string) $pagenow : '';
}

/**
 * Read+sanitize one whitelisted query key. Production reads $_GET directly,
 * inline, at the point of sanitization — the raw superglobal is never
 * copied into an intermediate variable first. $override (tests only) is
 * used verbatim as the source array in its place.
 *
 * @param string     $key
 * @param array|null $override Test-only source array; null reads $_GET.
 * @return string sanitize_key()'d value, or '' when absent/empty.
 */
function sn_httpdiag_query_value( $key, $override ) {
	if ( null !== $override ) {
		return isset( $override[ $key ] ) && '' !== $override[ $key ]
			? sanitize_key( wp_unslash( $override[ $key ] ) )
			: '';
	}

	return isset( $_GET[ $key ] ) && '' !== $_GET[ $key ]
		? sanitize_key( wp_unslash( $_GET[ $key ] ) )
		: '';
}

/**
 * Build the sanitized "screen" label for one logged entry: $pagenow plus
 * whichever of SN_HTTPDIAG_SCREEN_QUERY_KEYS are present, each
 * sanitize_key()'d. Every OTHER query key is dropped outright — a hostile
 * or unexpected key/value can never reach the stored log.
 *
 * @param string|null $pagenow_override Test-only; defaults to the global.
 * @param array|null  $query_override   Test-only; defaults to $_GET.
 * @return string e.g. "index.php?page=sn-theme-options&tab=health"
 */
function sn_httpdiag_screen_label( $pagenow_override = null, $query_override = null ) {
	$pagenow = null !== $pagenow_override ? $pagenow_override : sn_httpdiag_current_pagenow();
	$label   = sanitize_key( (string) $pagenow );

	$parts = array();
	foreach ( SN_HTTPDIAG_SCREEN_QUERY_KEYS as $key ) {
		$value = sn_httpdiag_query_value( $key, $query_override );
		if ( '' !== $value ) {
			$parts[] = $key . '=' . $value;
		}
	}

	return $parts ? $label . '?' . implode( '&', $parts ) : $label;
}

/**
 * Persist one shutdown-triggered snapshot into the `snt_httpdiag_log`
 * option: prepend newest-first, cap the ring at SN_HTTPDIAG_RING_MAX
 * entries, and cap THIS entry's own call list at SN_HTTPDIAG_HTTP_MAX.
 *
 * @param array<int,array{url:string,ms:int,code:int,error:bool}> $http Captured calls, in the order they completed.
 * @param float                                                    $wall_s Total page wall-clock time (timer_stop(0) in production).
 * @param string|null                                              $pagenow_override Test-only, forwarded to sn_httpdiag_screen_label().
 * @param array|null                                               $query_override   Test-only, forwarded to sn_httpdiag_screen_label().
 * @return array{t:int,screen:string,wall_s:float,http:array} The entry that was written.
 */
function sn_httpdiag_record( array $http, $wall_s, $pagenow_override = null, $query_override = null ) {
	$entry = array(
		't'      => time(),
		'screen' => sn_httpdiag_screen_label( $pagenow_override, $query_override ),
		'wall_s' => (float) $wall_s,
		'http'   => array_slice( array_values( $http ), 0, SN_HTTPDIAG_HTTP_MAX ),
	);

	$log = get_option( 'snt_httpdiag_log' );
	$log = is_array( $log ) ? $log : array();

	array_unshift( $log, $entry );
	$log = array_slice( $log, 0, SN_HTTPDIAG_RING_MAX );

	update_option( 'snt_httpdiag_log', $log, false );

	return $entry;
}

/**
 * Render one captured call as "host/path — Nms (code)" for the Site Health
 * value string. Pure formatting — no WP calls, no translation (a unit
 * abbreviation, not prose; mirrors sn_footprint_format_bytes()' stance).
 *
 * @param array{url?:string,ms?:int,code?:int,error?:bool} $call
 * @return string
 */
function sn_httpdiag_format_call( $call ) {
	$url  = (string) ( $call['url'] ?? '' );
	$ms   = (int) ( $call['ms'] ?? 0 );
	$code = (int) ( $call['code'] ?? 0 );
	return sprintf( '%s — %dms (%d)', $url, $ms, $code );
}

/**
 * The single slowest individual HTTP call across every entry in $log
 * (not the slowest PAGE — the slowest CALL within any page).
 *
 * @param array $log
 * @return string The call's sanitized url, or '' when the log holds no calls.
 */
function sn_httpdiag_find_slowest_call( array $log ) {
	$slowest_url = '';
	$slowest_ms  = -1;

	foreach ( $log as $entry ) {
		$calls = is_array( $entry['http'] ?? null ) ? $entry['http'] : array();
		foreach ( $calls as $call ) {
			$ms = (int) ( $call['ms'] ?? 0 );
			if ( $ms > $slowest_ms ) {
				$slowest_ms  = $ms;
				$slowest_url = (string) ( $call['url'] ?? '' );
			}
		}
	}

	return $slowest_url;
}

if ( function_exists( 'add_action' ) ) {

	/**
	 * Wire this module's hooks. `http_request_args`/`http_api_debug` are
	 * admin-only (guarded by is_admin() HERE, at registration time — never
	 * inside the callbacks) so a front-end or cron-triggered HTTP call never
	 * pays for the stamping in the first place. `shutdown` and
	 * `debug_information` always register: shutdown self-gates at runtime
	 * (it has to — is_admin() alone doesn't distinguish an admin PAGE VIEW
	 * from an admin-ajax.php or REST call, both of which also report
	 * is_admin() === true), and debug_information is only ever consulted
	 * from within Site Health's own admin screen anyway.
	 *
	 * Split into its own function (rather than bare top-level add_action()
	 * calls) so tests can re-run it after flipping is_admin() and inspect
	 * which hooks landed, without needing a second process per branch.
	 */
	function sn_httpdiag_register_hooks() {
		if ( is_admin() ) {
			add_filter( 'http_request_args', 'sn_httpdiag_request_args', 10, 2 );
			add_action( 'http_api_debug', 'sn_httpdiag_capture', 10, 5 );
		}

		// accepted_args 0: do_action( 'shutdown' ) fires argless, and WP hands
		// accepted_args>=1 callbacks an EMPTY STRING for their first param —
		// which shadowed $buffer's null default and fataled record()'s array
		// type on every slow no-HTTP request (the v9.46.0→v9.46.2 incident).
		add_action( 'shutdown', 'sn_httpdiag_shutdown', 10, 0 );
		add_filter( 'debug_information', 'sn_httpdiag_debug_information' );
	}

	/**
	 * `http_request_args` filter: stamp the outbound request's start time so
	 * sn_httpdiag_capture() (paired via `http_api_debug`) can compute a
	 * duration once the request completes. Unconditional — the admin-only
	 * scoping happens once, at sn_httpdiag_register_hooks() time, not here.
	 *
	 * @param array  $args
	 * @param string $url
	 * @return array
	 */
	function sn_httpdiag_request_args( $args, $url ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$args['_sn_httpdiag_t0'] = microtime( true );
		return $args;
	}

	/**
	 * `http_api_debug` action: compute the elapsed ms since
	 * sn_httpdiag_request_args() stamped $parsed_args, then append a
	 * sanitized entry to the request-static buffer. A call with no stamp
	 * (started before this module wired up, or issued by something that
	 * bypassed http_request_args entirely) is skipped — there is no start
	 * time to measure from.
	 *
	 * @param array|WP_Error $response
	 * @param string         $context
	 * @param string         $class
	 * @param array          $parsed_args
	 * @param string         $url
	 * @return void
	 */
	function sn_httpdiag_capture( $response, $context, $class, $parsed_args, $url ) {
		if ( ! is_array( $parsed_args ) || ! isset( $parsed_args['_sn_httpdiag_t0'] ) ) {
			return;
		}

		$ms       = (int) round( ( microtime( true ) - (float) $parsed_args['_sn_httpdiag_t0'] ) * 1000 );
		$is_error = is_wp_error( $response );

		sn_httpdiag_buffer(
			array(
				'url'   => sn_httpdiag_sanitize_url( $url ),
				'ms'    => max( 0, $ms ),
				'code'  => $is_error ? 0 : (int) wp_remote_retrieve_response_code( $response ),
				'error' => $is_error,
			)
		);
	}

	/**
	 * `shutdown` callback: admin page-view and admin-post requests — skips
	 * admin-ajax.php, REST, and cron (all of which is_admin() alone lets
	 * through). Writes the `snt_httpdiag_log` entry when either the buffer
	 * caught at least one HTTP call, or the page itself ran past
	 * SN_HTTPDIAG_WALL_THRESHOLD_S even with zero calls captured (e.g. a
	 * call that started before this module wired up mid-request).
	 *
	 * $buffer and $wall_s are only ever overridden by tests; production
	 * always reads the real request-static buffer + WP's own timer_stop(0).
	 *
	 * @param array|null $buffer Override for tests; defaults to sn_httpdiag_buffer().
	 * @param float|null $wall_s Override for tests; defaults to timer_stop(0).
	 * @return array|null The logged entry, or null when the gate skipped the write.
	 */
	function sn_httpdiag_shutdown( $buffer = null, $wall_s = null ) {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return null;
		}

		// is_array (not null-check): a hook-dispatched call can hand this fn
		// WP's empty-string filler arg — a diagnostics module must be
		// impossible to fatal, so anything non-array falls back to the buffer.
		$buffer = is_array( $buffer ) ? $buffer : sn_httpdiag_buffer();
		$wall_s = is_numeric( $wall_s ) ? (float) $wall_s : (float) timer_stop( 0 );

		if ( empty( $buffer ) && $wall_s <= SN_HTTPDIAG_WALL_THRESHOLD_S ) {
			return null;
		}

		return sn_httpdiag_record( $buffer, $wall_s );
	}

	/**
	 * Site Health `debug_information` filter: the "Signal & Noise — admin
	 * HTTP diagnosis" panel. Shows the 10 slowest logged page-loads
	 * (slowest-first by wall_s), each field's value being that page's
	 * captured call list; plus a summary field (total logged requests + the
	 * single slowest individual call across the whole log). $log_override is
	 * only ever set by tests; production always reads `snt_httpdiag_log`.
	 *
	 * @param array      $info Core's accumulated debug-info panels.
	 * @param array|null $log_override Override for tests; defaults to the option.
	 * @return array
	 */
	function sn_httpdiag_debug_information( $info, $log_override = null ) {
		$log = null !== $log_override ? $log_override : get_option( 'snt_httpdiag_log' );
		$log = is_array( $log ) ? $log : array();

		$fields = array();

		if ( empty( $log ) ) {
			$fields['empty'] = array(
				'label' => __( 'Status', 'signal-and-noise-tools' ),
				'value' => __( 'No slow admin requests logged yet.', 'signal-and-noise-tools' ),
			);
		} else {
			$sorted = $log;
			usort(
				$sorted,
				function ( $a, $b ) {
					return ( (float) ( $b['wall_s'] ?? 0 ) ) <=> ( (float) ( $a['wall_s'] ?? 0 ) );
				}
			);

			$i = 0;
			foreach ( array_slice( $sorted, 0, 10 ) as $entry ) {
				$calls = is_array( $entry['http'] ?? null ) ? $entry['http'] : array();
				$value = array_map( 'sn_httpdiag_format_call', $calls );

				$fields[ 'slow_' . $i ] = array(
					'label' => sprintf(
						'%s — %ss',
						(string) ( $entry['screen'] ?? '' ),
						number_format( (float) ( $entry['wall_s'] ?? 0 ), 2 )
					),
					'value' => $value ? implode( ' | ', $value ) : __( '(no HTTP calls captured)', 'signal-and-noise-tools' ),
				);
				++$i;
			}

			$slowest_call = sn_httpdiag_find_slowest_call( $log );
			$fields['summary'] = array(
				'label' => __( 'Summary', 'signal-and-noise-tools' ),
				'value' => sprintf(
					/* translators: 1: total logged requests, 2: the single slowest host+path across the log. */
					__( '%1$d logged request(s); slowest call: %2$s', 'signal-and-noise-tools' ),
					count( $log ),
					'' !== $slowest_call ? $slowest_call : __( 'none captured', 'signal-and-noise-tools' )
				),
			);
		}

		$info['snt_httpdiag'] = array(
			'label'       => __( 'Signal & Noise — admin HTTP diagnosis', 'signal-and-noise-tools' ),
			'description' => __( 'Outbound HTTP calls captured during slow wp-admin page loads, newest ring-buffered log first.', 'signal-and-noise-tools' ),
			'fields'      => $fields,
		);

		return $info;
	}

	sn_httpdiag_register_hooks();
}
