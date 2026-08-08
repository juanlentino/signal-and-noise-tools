<?php
/**
 * Signal & Noise Tools — per-scan_type run telemetry for sn_scan (v10.60.0).
 *
 * ── Why this exists (owner ask, 2026-08-08) ──
 *
 * Layer B (inc/mcp/mcp-telemetry.php) records every tools/call but its
 * args_shape is KEY NAMES only, by design — so every sn_scan call looks
 * identical there regardless of scan_type, and per-type usage/yield can
 * never inform the consolidation program's decisions. sn-apply already
 * closed the same gap for change types via its rw-audit enrichment; this
 * module is the scan-side mirror, with one structural difference:
 *
 * ── The observer split (the zero-writes contract) ──
 *
 * sn_scan is readOnlyHint:true with a STRUCTURAL zero-writes guard
 * (tests/abilities-sn-scan.php's write recorders), and the rw-audit log has
 * its own test pinning that the read door never touches it. So the ability
 * itself writes NOTHING: it fires `sn_scan_completed` (an action dispatch
 * is not a write) with a complete metrics row, and THIS file's listener —
 * registered only in production via the main require chain, never in the
 * ability's CLI harnesses — persists it. Same split as `sn_prov_committed`
 * and the Desktop-Mode agent-telemetry bridge.
 *
 * ── Both outcomes, always ──
 *
 * The wrapper in inc/abilities-sn-scan.php fires the action on SUCCESS AND
 * ERROR alike — the telemetry-agents seam-2 lesson (a success-only observer
 * under-reports the failure rate to ~0%; the standing "success-only readout"
 * trap). Error rows carry the WP_Error code and whatever scan_type string
 * the caller sent (truncated), so per-type failure rates are measurable.
 * The one thing this table structurally cannot see, same as Layer B: calls
 * the MCP proxy refuses client-side (-32602) never reach WordPress at all.
 *
 * ── Storage idiom ──
 *
 * Mirrors inc/mcp/mcp-telemetry.php exactly: dbDelta table, lazy
 * version-option install on 'init', fail-open try/catch around the insert,
 * 90-day opportunistic retention (~1-in-50 inserts prunes, capped DELETE,
 * no cron), kill-switch filter (`sn_scan_telemetry_enabled`, default true).
 *
 * @package SignalNoiseTools
 * @since 10.60.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_SCAN_TELEMETRY_TABLE          = 'sn_scan_run';
const SNT_SCAN_TELEMETRY_DB_VERSION     = '1';
const SNT_SCAN_TELEMETRY_DB_VERSION_OPT = 'snt_scan_telemetry_db_version';
const SNT_SCAN_TELEMETRY_RETENTION_DAYS = 90;
const SNT_SCAN_TELEMETRY_PRUNE_LIMIT    = 500;
const SNT_SCAN_TELEMETRY_PRUNE_CHANCE   = 50;

/**
 * Kill switch — mirrors sn_mcp_telemetry_enabled().
 *
 * @return bool
 */
function snt_scan_telemetry_enabled() {
	if ( ! function_exists( 'apply_filters' ) ) {
		return true;
	}
	return (bool) apply_filters( 'sn_scan_telemetry_enabled', true );
}

/**
 * Build the per-run metrics row from the ability's input + result. PURE —
 * no reads, no writes; called by the ability wrapper on every execution.
 *
 * Fields, and what each one measures:
 *   outcome              'ok'|'error' — per-type failure rate.
 *   error_code           WP_Error code on failure ('' on success) — which gate refused.
 *   scan_type            as sent (truncated 64) — per-type usage, even for a bad value.
 *   scope_kind           'all'|'post_ids'|'modified_since' — do callers scope, or full-corpus every time?
 *   scope_size           count of ids for post_ids scope, 0 otherwise.
 *   freshness_requested  what the caller asked for.
 *   include_dismissed    the inert flag — evidence for/against ever wiring it.
 *   max_candidates       as sent (0 = defaulted) — do callers page deliberately?
 *   cursor_used          pagination actually exercised, or first-page-only forever?
 *   total_candidates     full pre-pagination yield — the per-type SIGNAL RATE.
 *   candidates_returned  page size actually delivered.
 *   candidates_with_apply_hint  actionability: how much of the yield has a wired next step.
 *   posts_examined / posts_skipped  corpus coverage per run.
 *   truncated            the underlying detector hit its own cap.
 *   corpus_fingerprint / scan_run_id  identical-rerun detection: repeat scans over unchanged content are re-checks, not new demand.
 *   duration_ms          per-type cost — the "cost profiles differ by an order of magnitude" claim, finally measured.
 *
 * @param array          $input  Raw ability input.
 * @param array|WP_Error $result The ability's return value.
 * @param float          $t0     microtime(true) at call start.
 * @return array
 */
function snt_sn_scan_run_metrics( array $input, $result, $t0 ) {
	$scope      = is_array( $input['scope'] ?? null ) ? $input['scope'] : array();
	$scope_kind = (string) ( $scope['kind'] ?? 'all' );
	$is_error   = is_wp_error( $result );

	$candidates = ( ! $is_error && is_array( $result['candidates'] ?? null ) ) ? $result['candidates'] : array();
	$with_hint  = 0;
	foreach ( $candidates as $c ) {
		if ( null !== ( $c['apply_hint'] ?? null ) ) {
			$with_hint++;
		}
	}

	return array(
		'ts'                         => time(),
		'outcome'                    => $is_error ? 'error' : 'ok',
		'error_code'                 => $is_error ? substr( (string) $result->get_error_code(), 0, 64 ) : '',
		'scan_type'                  => substr( (string) ( $input['scan_type'] ?? '' ), 0, 64 ),
		'scope_kind'                 => substr( $scope_kind, 0, 32 ),
		'scope_size'                 => 'post_ids' === $scope_kind ? count( (array) ( $scope['post_ids'] ?? array() ) ) : 0,
		'freshness_requested'        => substr( (string) ( $input['freshness'] ?? 'cached' ), 0, 16 ),
		'include_dismissed'          => ! empty( $input['include_dismissed'] ) ? 1 : 0,
		'max_candidates'             => (int) ( $input['max_candidates'] ?? 0 ),
		'cursor_used'                => ( '' !== (string) ( $input['cursor'] ?? '' ) ) ? 1 : 0,
		'total_candidates'           => $is_error ? 0 : (int) ( $result['total_candidates'] ?? 0 ),
		'candidates_returned'        => count( $candidates ),
		'candidates_with_apply_hint' => $with_hint,
		'posts_examined'             => $is_error ? 0 : (int) ( $result['corpus_state']['posts_examined'] ?? 0 ),
		'posts_skipped'              => $is_error ? 0 : (int) ( $result['corpus_state']['posts_skipped'] ?? 0 ),
		'truncated'                  => ( ! $is_error && ! empty( $result['truncated'] ) ) ? 1 : 0,
		'corpus_fingerprint'         => $is_error ? '' : (string) ( $result['corpus_state']['corpus_fingerprint'] ?? '' ),
		'scan_run_id'                => $is_error ? '' : (string) ( $result['scan_run_id'] ?? '' ),
		'duration_ms'                => (int) round( ( microtime( true ) - (float) $t0 ) * 1000 ),
	);
}

/**
 * dbDelta CREATE TABLE — VARCHAR over ENUM, the house convention (see
 * inc/mcp/mcp-telemetry.php's schema note).
 *
 * @return string
 */
function snt_scan_telemetry_schema() {
	global $wpdb;
	$table   = $wpdb->prefix . SNT_SCAN_TELEMETRY_TABLE;
	$charset = $wpdb->get_charset_collate();
	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ts DATETIME NOT NULL,
		outcome VARCHAR(8) NOT NULL DEFAULT 'ok',
		error_code VARCHAR(64) NOT NULL DEFAULT '',
		scan_type VARCHAR(64) NOT NULL DEFAULT '',
		scope_kind VARCHAR(32) NOT NULL DEFAULT 'all',
		scope_size INT UNSIGNED NOT NULL DEFAULT 0,
		freshness_requested VARCHAR(16) NOT NULL DEFAULT 'cached',
		include_dismissed TINYINT(1) NOT NULL DEFAULT 0,
		max_candidates INT UNSIGNED NOT NULL DEFAULT 0,
		cursor_used TINYINT(1) NOT NULL DEFAULT 0,
		total_candidates INT UNSIGNED NOT NULL DEFAULT 0,
		candidates_returned INT UNSIGNED NOT NULL DEFAULT 0,
		candidates_with_apply_hint INT UNSIGNED NOT NULL DEFAULT 0,
		posts_examined INT UNSIGNED NOT NULL DEFAULT 0,
		posts_skipped INT UNSIGNED NOT NULL DEFAULT 0,
		truncated TINYINT(1) NOT NULL DEFAULT 0,
		corpus_fingerprint VARCHAR(64) NOT NULL DEFAULT '',
		scan_run_id VARCHAR(64) NOT NULL DEFAULT '',
		duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY scan_type_ts (scan_type, ts),
		KEY ts (ts)
	) {$charset};";
}

/**
 * Lazy install on 'init' — one get_option() compare per request, dbDelta
 * only on a version delta (the analytics-buckets idiom Layer B also uses).
 */
function snt_scan_telemetry_maybe_install() {
	if ( ! function_exists( 'get_option' ) || ! snt_scan_telemetry_enabled() ) {
		return;
	}
	if ( SNT_SCAN_TELEMETRY_DB_VERSION === (string) get_option( SNT_SCAN_TELEMETRY_DB_VERSION_OPT, '' ) ) {
		return;
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( snt_scan_telemetry_schema() );
	update_option( SNT_SCAN_TELEMETRY_DB_VERSION_OPT, SNT_SCAN_TELEMETRY_DB_VERSION, false );
}
add_action( 'init', 'snt_scan_telemetry_maybe_install' );

/**
 * The listener: persist one row per `sn_scan_completed`. FAIL-OPEN — the
 * whole body is try/caught, and a missing table is a $wpdb->insert() that
 * returns false (never throws); the scan's own response is never touched.
 *
 * @param array $metrics From snt_sn_scan_run_metrics().
 */
function snt_scan_telemetry_on_completed( $metrics ) {
	try {
		if ( ! snt_scan_telemetry_enabled() || ! is_array( $metrics ) ) {
			return;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return;
		}

		$row       = $metrics;
		$row['ts'] = gmdate( 'Y-m-d H:i:s', (int) ( $metrics['ts'] ?? time() ) );

		$wpdb->insert( $wpdb->prefix . SNT_SCAN_TELEMETRY_TABLE, $row );

		// Opportunistic retention — no cron, per the standing guardrail.
		$gate = function_exists( 'wp_rand' ) ? wp_rand( 1, SNT_SCAN_TELEMETRY_PRUNE_CHANCE ) : ( ( (int) ( $metrics['ts'] ?? 0 ) % SNT_SCAN_TELEMETRY_PRUNE_CHANCE ) + 1 );
		if ( 1 === $gate ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - SNT_SCAN_TELEMETRY_RETENTION_DAYS * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}" . SNT_SCAN_TELEMETRY_TABLE . ' WHERE ts < %s LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff,
				SNT_SCAN_TELEMETRY_PRUNE_LIMIT
			) );
		}
	} catch ( \Throwable $e ) {
		return; // Fail-open, always — telemetry never breaks a scan.
	}
}
add_action( 'sn_scan_completed', 'snt_scan_telemetry_on_completed' );
