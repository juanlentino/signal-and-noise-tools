<?php
/**
 * Signal & Noise Tools — Content Health check: rights-signals drift probe.
 *
 * The cf-security-headers probe pattern applied to the rights surface
 * (Session 3, lane 3): verify the edge still serves what Phase 1 shipped.
 * Loaded by the inc/health-checks.php orchestrator; packs via
 * sn_health_pack_check; a failure raises the existing Health attention chip.
 * Probe targets are a HARDCODED own-domain allowlist (fixed paths on the
 * site's own home_url), never configurable input (scope 2.5a). The pure
 * evaluator is the tested surface (tests/health-check-rights-signals.php);
 * the fetch wrapper stays thin.
 *
 * Checks (each classified separately by the pure evaluator):
 *   tdmrep    - /.well-known/tdmrep.json answers 200 + parses as JSON.
 *   rsl       - /license.xml answers 200 + parses as XML.
 *   signal    - robots.txt carries ONE Content-Signal line with ai-input=yes
 *               AND ai-train=no (value drift fails, not just absence).
 *   license   - robots.txt carries the License: line.
 *   headers   - TDM-Reservation is 1 on BOTH the HTML and /wp-json responses.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure evaluator: canned bodies/headers in, per-check verdicts out.
 *
 * Reads $responses only (never mutates it) and calls no WordPress function,
 * so the fixture can drive every failure mode offline.
 *
 * @param array $responses Keyed raw responses (body + headers per target).
 * @return array<string,array{ok:bool,detail:string}> Keyed by check name.
 */
function snt_rights_probe_evaluate( $responses ) {
	$verdict = static function ( $ok, $good, $bad ) {
		return array(
			'ok'     => (bool) $ok,
			'detail' => $ok ? $good : $bad,
		);
	};

	$tdmrep = $responses['tdmrep'] ?? array();
	$rsl    = $responses['rsl'] ?? array();
	$robots = (string) ( $responses['robots']['body'] ?? '' );

	// tdmrep: 200 + the body decodes as JSON. json_last_error (not a null
	// test), so a literal "null" body still counts as parsed.
	json_decode( (string) ( $tdmrep['body'] ?? '' ) );
	$tdmrep_ok = 200 === (int) ( $tdmrep['code'] ?? 0 ) && JSON_ERROR_NONE === json_last_error();

	// rsl: 200 + the body parses as XML. libxml_use_internal_errors keeps a
	// malformed document from spraying warnings; prior state restored after.
	$libxml_prev = libxml_use_internal_errors( true );
	$rsl_xml     = simplexml_load_string( (string) ( $rsl['body'] ?? '' ) );
	libxml_clear_errors();
	libxml_use_internal_errors( $libxml_prev );
	$rsl_ok = 200 === (int) ( $rsl['code'] ?? 0 ) && false !== $rsl_xml;

	// signal: exactly ONE Content-Signal line (the single-line contract) that
	// still carries ai-input=yes (the CF Managed-robots regression class) AND
	// ai-train=no. Value drift to ai-train=yes is the semantic INVERSE of
	// Phase 1, so presence alone is never enough; both values are asserted.
	preg_match_all( '/^[ \t]*content-signal[ \t]*:[ \t]*(.+?)[ \t\r]*$/mi', $robots, $signal_lines );
	$signal_ok = 1 === count( $signal_lines[1] )
		&& false !== stripos( $signal_lines[1][0], 'ai-input=yes' )
		&& false !== stripos( $signal_lines[1][0], 'ai-train=no' );

	// license: the robots License: line survives, with a value.
	$license_ok = 1 === preg_match( '/^[ \t]*license[ \t]*:[ \t]*\S/mi', $robots );

	// headers: tdm-reservation must be the VALUE 1 (rights reserved) on BOTH
	// representations (HTML and /wp-json). A drift to 0 means rights are NOT
	// reserved, the semantic inverse of Phase 1, so an isset() test alone
	// would fail open. Keys are normalized so canned fixtures and live sets
	// compare alike.
	$html_headers   = array_change_key_case( (array) ( $responses['html']['headers'] ?? array() ), CASE_LOWER );
	$wpjson_headers = array_change_key_case( (array) ( $responses['wpjson']['headers'] ?? array() ), CASE_LOWER );
	$headers_ok     = '1' === trim( (string) ( $html_headers['tdm-reservation'] ?? '' ) )
		&& '1' === trim( (string) ( $wpjson_headers['tdm-reservation'] ?? '' ) );

	return array(
		'tdmrep'  => $verdict( $tdmrep_ok, 'tdmrep.json answers 200 and parses as JSON.', 'tdmrep.json is missing, non-200, or not valid JSON.' ),
		'rsl'     => $verdict( $rsl_ok, 'license.xml answers 200 and parses as XML.', 'license.xml is missing, non-200, or not well-formed XML.' ),
		'signal'  => $verdict( $signal_ok, 'robots.txt carries one Content-Signal line with ai-input=yes and ai-train=no.', 'robots.txt Content-Signal drift: expected exactly one line carrying both ai-input=yes and ai-train=no.' ),
		'license' => $verdict( $license_ok, 'robots.txt carries the License: line.', 'robots.txt is missing the License: line.' ),
		'headers' => $verdict( $headers_ok, 'TDM-Reservation is 1 on both the HTML and /wp-json responses.', 'TDM-Reservation is missing or not 1 on the HTML and/or /wp-json response headers (0 would mean rights NOT reserved).' ),
	);
}

/**
 * Flatten a wp_remote_retrieve_headers() result to a lowercase-keyed array.
 *
 * Same gotcha as the cf-security-headers probe: live WP returns a
 * CaseInsensitiveDictionary whose $data is protected (a (array) cast mangles
 * the key); tests return a plain array. getAll() first, then a fallback.
 *
 * @param mixed $raw The wp_remote_retrieve_headers() result.
 * @return array<string,string> Lowercase header names to string values.
 */
function snt_rights_probe_flatten_headers( $raw ) {
	if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
		$raw = (array) $raw->getAll();
	}
	$flat = array();
	if ( is_array( $raw ) || $raw instanceof \Traversable ) {
		foreach ( $raw as $name => $value ) {
			$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
	}
	return $flat;
}

/**
 * Fetch the probe targets (hardcoded allowlist) and pack the health check.
 *
 * Thin wrapper: fetch, hand to the pure evaluator, pack. An unreachable
 * target is a transient state (not a finding), mirroring the
 * cf-security-headers probe: advisory note, zero findings, retry next scan.
 *
 * @return array sn_health_pack_check() shape.
 */
function snt_health_check_rights_signals() {
	$label    = 'Rights signals';
	$fix_hint = 'The Phase 1 rights surface drifted at the edge (tdmrep.json, license.xml, the robots.txt Content-Signal and License lines, or the TDM headers). Verify the Cloudflare rules and the rights-signals worker.';

	// Kill switch, mirroring sn_health_cf_header_check_enabled.
	if ( ! apply_filters( 'sn_health_rights_signals_check_enabled', true ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	// HARDCODED own-domain allowlist (scope 2.5a): fixed paths, never input.
	$targets = array(
		'tdmrep' => home_url( '/.well-known/tdmrep.json' ),
		'rsl'    => home_url( '/license.xml' ),
		'robots' => home_url( '/robots.txt' ),
		'html'   => home_url( '/' ),
		'wpjson' => home_url( '/wp-json/' ),
	);

	// SSRF guard (resolve, never string-match): fail closed before any request.
	if ( sn_ssrf_host_blocked( (string) wp_parse_url( $targets['html'], PHP_URL_HOST ) ) ) {
		return sn_health_pack_check( $label, array(), 'Probe skipped: the site host failed the SSRF guard. The check will retry on the next scan.' );
	}

	$responses = array();
	foreach ( $targets as $key => $url ) {
		// redirection => 0: the SSRF guard only ever sees the first hop, and a
		// redirect on a rights target is itself drift worth surfacing.
		$resp = wp_remote_get( $url, array(
			'timeout'     => 5,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' rights-drift-check',
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return sn_health_pack_check( $label, array(), 'Probe failed (' . $resp->get_error_message() . ') fetching ' . $url . '. The check will retry on the next scan.' );
		}
		$responses[ $key ] = array(
			'code'    => (int) wp_remote_retrieve_response_code( $resp ),
			'body'    => (string) wp_remote_retrieve_body( $resp ),
			'headers' => snt_rights_probe_flatten_headers( wp_remote_retrieve_headers( $resp ) ),
		);
	}

	$verdicts = snt_rights_probe_evaluate( $responses );

	// Origin-direct degenerate guard (the cf-security-headers precedent, its
	// edge-bypass branch): every rights target is worker/edge-served on this
	// deployment, so ALL checks failing at once with no Cloudflare marker on
	// the HTML response means the probe most likely bypassed the edge
	// (split-horizon DNS, hosts pin, grey-cloud). Flagging all five would be a
	// false-positive attention chip: emit one advisory note with ZERO findings
	// so a later scan re-attempts.
	$failed = 0;
	foreach ( $verdicts as $verdict ) {
		if ( empty( $verdict['ok'] ) ) {
			$failed++;
		}
	}
	$html_headers = (array) ( $responses['html']['headers'] ?? array() );
	$server       = (string) ( $html_headers['server'] ?? '' );
	$is_edge      = isset( $html_headers['cf-ray'] ) || false !== stripos( $server, 'cloudflare' );
	if ( count( $verdicts ) === $failed && ! $is_edge ) {
		return sn_health_pack_check( $label, array(), 'Could not confirm the rights surface from this host: every check failed and the probe saw no Cloudflare edge marker, so it may have hit the origin directly. Verify the edge config manually; the check will retry on the next scan.' );
	}

	// One finding per failed check, anchored to the URL that carries it.
	$check_urls = array(
		'tdmrep'  => $targets['tdmrep'],
		'rsl'     => $targets['rsl'],
		'signal'  => $targets['robots'],
		'license' => $targets['robots'],
		'headers' => $targets['html'],
	);
	$findings   = array();
	foreach ( $verdicts as $check => $verdict ) {
		if ( ! empty( $verdict['ok'] ) ) {
			continue;
		}
		$findings[] = array(
			'subject_type'  => 'rights_signal',
			'subject_id'    => 0,
			'subject_url'   => $check_urls[ $check ] ?? $targets['html'],
			'subject_label' => $check,
			'edit_url'      => '',
			'note'          => $verdict['detail'],
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
