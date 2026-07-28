<?php
/**
 * Signal & Noise Tools — Content Health check: rights-signals drift probe.
 *
 * SCAFFOLD (Session 3 plan, lane 3). The cf-security-headers probe pattern
 * applied to the rights surface: verify the edge still serves what Phase 1
 * shipped. Loaded by the inc/health-checks.php orchestrator; packs via
 * sn_health_pack_check; a failure raises the existing Health attention chip.
 * Probe targets are a HARDCODED own-domain allowlist — never configurable
 * input (scope §2.5a). tests/health-check-rights-signals.php is RED against
 * this shell on purpose.
 *
 * Checks (each classified separately by the pure evaluator):
 *   tdmrep    — /.well-known/tdmrep.json answers 200 + parses as JSON.
 *   rsl       — /license.xml answers 200 + parses as XML.
 *   signal    — robots.txt carries ONE Content-Signal line incl. ai-input=yes.
 *   license   — robots.txt carries the License: line.
 *   headers   — TDM-Reservation/TDM-Policy present on HTML and /wp-json.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure evaluator: canned bodies/headers in, per-check verdicts out.
 *
 * @param array $responses Keyed raw responses (body + headers per target).
 * @return array<string,array{ok:bool,detail:string}> Keyed by check name.
 */
function snt_rights_probe_evaluate( $responses ) {
	return array(); // Session 3 lane 3.
}

/**
 * Fetch the probe targets (hardcoded allowlist) and pack the health check.
 *
 * @return array sn_health_pack_check() shape.
 */
function snt_health_check_rights_signals() {
	return array(); // Session 3 lane 3.
}
