<?php
/**
 * Signal & Noise Tools — Machine Readers: sensor read + row normalization.
 *
 * SCAFFOLD (Session 3 plan: docs/superpowers/specs/2026-07-28-session-3-machine-readers-plan.md).
 * Locked API shapes; bodies are Session 3's lane 1. tests/machine-readers-api.php
 * is RED against this shell on purpose (TDD).
 *
 * Contract (sensor v1.4.0): GET /_sn/rights-signals/machine-readers?days=N,
 * Bearer SN_MR_READ_TOKEN; 200 { worker, days, data: [{family,surface,day,hits}] }.
 * Every worker value is UNTRUSTED input: rows normalize through the fixed enum
 * allowlists below (unknown family → 'other-bot', unknown surface → 'html'),
 * fetches ride inc/ssrf-guard.php, token handling mirrors inc/analytics-api.php
 * (write-only field, never echoed, autoload=no).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Normalize raw worker rows: allowlist-coerce family/surface, sanitize day to
 * YYYY-MM-DD, coerce hits to non-negative int, drop rows that are not arrays.
 * Pure (testable with canned rows).
 *
 * @param mixed $data The decoded `data` member of the sensor response.
 * @return array<int,array{family:string,surface:string,day:string,hits:int}>
 */
function snt_mr_normalize_rows( $data ) {
	return array(); // Session 3 lane 1.
}

/**
 * Fetch + normalize the sensor aggregates.
 *
 * @param int $days Window, clamped 1..90.
 * @return array{ok:bool,rows:array,error:?string}
 */
function snt_mr_fetch( $days = 30 ) {
	return array(
		'ok'    => false,
		'rows'  => array(),
		'error' => 'not_implemented',
	); // Session 3 lane 1: settings-resolved URL + token, ssrf-guarded wp_remote_get, schema fail-closed, short display transient.
}
