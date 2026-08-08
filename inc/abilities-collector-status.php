<?php
/**
 * Signal & Noise Tools — signal-noise/get-collector-status (readonly ability).
 *
 * One call answers "is the analytics collector healthy?" by fetching the
 * Worker's public GET /_sn/version endpoint (the same derived URL
 * inc/worker-version.php probes — no second URL to keep in sync) and
 * evaluating NAMED invariants instead of dumping raw JSON:
 *
 *   config_bindings  — every self-reported config boolean is true (a false
 *                      one, e.g. px_token_set, is a silent-zero-data mode);
 *   salt_window      — the daily identity-salt window is sane: today's salt
 *                      is present (worker v1.14.0+ "salt" object);
 *   version_present  — the deployed semver is reported (deploys go through
 *                      `npm run deploy`, which injects it);
 *   cron_fresh       — the worker's cron self-report exists, its
 *                      refresh_status is 'ok', and its `at` stamp is fresh
 *                      (within ~2h of now — the hourly cadence plus slack).
 *
 * The evaluator is PURE (json + now in, verdicts out) so every invariant is
 * exhaustively testable without HTTP. The ability takes an optional `worker`
 * input (enum, default 'analytics') so sibling workers can ride the same
 * surface later without a schema break.
 *
 * @package SignalNoiseTools
 * @since 9.81.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cron `at` freshness ceiling: the hourly cadence, doubled, plus slack. */
const SN_COLLECTOR_STATUS_CRON_FRESH_SECS = 2 * 3600 + 900;

/** HTTP timeout (seconds) — an agent call must never hang on a cold edge. */
const SN_COLLECTOR_STATUS_TIMEOUT = 4;

/**
 * Evaluate the named invariants over a decoded /_sn/version payload. PURE.
 *
 * @since 9.81.0
 * @param array $json Decoded /_sn/version JSON.
 * @param int   $now  Current unix time (injected for testability).
 * @return array{healthy:bool,invariants:array[]} invariants: [{name, ok, detail}]
 */
function sn_collector_status_invariants( $json, $now ) {
	$json       = is_array( $json ) ? $json : array();
	$invariants = array();

	// config_bindings: all self-reported presence booleans true.
	$config = ( isset( $json['config'] ) && is_array( $json['config'] ) ) ? $json['config'] : array();
	$broken = array();
	foreach ( $config as $key => $value ) {
		if ( ! $value ) {
			$broken[] = (string) $key;
		}
	}
	if ( array() === $config ) {
		$invariants[] = array(
			'name'   => 'config_bindings',
			'ok'     => false,
			'detail' => 'No config block reported: the deployed worker predates the self-report (v1.9.0+), so bindings cannot be confirmed.',
		);
	} else {
		$invariants[] = array(
			'name'   => 'config_bindings',
			'ok'     => array() === $broken,
			'detail' => array() === $broken
				? 'All ' . count( $config ) . ' config bindings report true.'
				: 'False bindings: ' . implode( ', ', $broken ) . ': each is a silent-data-loss mode.',
		);
	}

	// salt_window: today's rotating identity salt is present.
	$salt          = ( isset( $json['salt'] ) && is_array( $json['salt'] ) ) ? $json['salt'] : array();
	$today_present = (bool) ( $salt['today_present'] ?? false );
	$invariants[]  = array(
		'name'   => 'salt_window',
		'ok'     => $today_present,
		'detail' => $today_present
			? 'Today\'s identity salt is present (rotation is healthy).'
			: ( array() === $salt
				? 'No salt window reported: worker predates the readout (v1.14.0+) or the KV list failed.'
				: 'Today\'s identity salt is MISSING: visitor identity falls back and rotation has stalled.' ),
	);

	// version_present: a deployed semver is reported.
	$version      = trim( (string) ( $json['version'] ?? '' ) );
	$invariants[] = array(
		'name'   => 'version_present',
		'ok'     => '' !== $version,
		'detail' => '' !== $version
			? 'Deployed version v' . $version . '.'
			: 'No semver reported: deploy with `npm run deploy` so SN_VERSION is injected.',
	);

	// cron_fresh: cron block present + refresh_status ok + at within the ceiling.
	$cron = ( isset( $json['cron'] ) && is_array( $json['cron'] ) ) ? $json['cron'] : array();
	if ( array() === $cron ) {
		$invariants[] = array(
			'name'   => 'cron_fresh',
			'ok'     => false,
			'detail' => 'No cron block reported: the worker\'s scheduled refresh cannot be confirmed.',
		);
	} else {
		$status = (string) ( $cron['refresh_status'] ?? '' );
		$at_ts  = isset( $cron['at'] ) ? strtotime( (string) $cron['at'] ) : false;
		$fresh  = false !== $at_ts && ( $now - $at_ts ) <= SN_COLLECTOR_STATUS_CRON_FRESH_SECS && ( $now - $at_ts ) >= 0;
		$ok     = ( 'ok' === $status ) && $fresh;
		if ( 'ok' !== $status ) {
			$detail = 'Cron refresh_status is "' . ( '' !== $status ? $status : 'absent' ) . '", not "ok": the last scheduled run failed.';
		} elseif ( ! $fresh ) {
			$detail = 'Cron last ran at ' . (string) ( $cron['at'] ?? 'an unparseable time' ) . ': outside the ~2h freshness window; the schedule has stalled.';
		} else {
			$detail = 'Cron ran ok at ' . (string) $cron['at'] . '.';
		}
		$invariants[] = array(
			'name'   => 'cron_fresh',
			'ok'     => $ok,
			'detail' => $detail,
		);
	}

	$healthy = true;
	foreach ( $invariants as $inv ) {
		if ( ! $inv['ok'] ) {
			$healthy = false;
			break;
		}
	}

	return array(
		'healthy'    => $healthy,
		'invariants' => $invariants,
	);
}

/**
 * Register the readonly ability on the canonical registrar hook.
 *
 * @since 9.81.0
 */
function snt_abilities_collector_status_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/get-collector-status', array(
		'label'               => 'Analytics collector health',
		'description'         => 'Fetches the analytics worker\'s public /_sn/version endpoint and evaluates named invariants: config_bindings (every self-reported binding true), salt_window (today\'s rotating identity salt present), version_present (deployed semver reported), cron_fresh (scheduled refresh ok within ~2h). Returns {healthy, worker, invariants:[{name, ok, detail}]} plus, when the deployed worker reports one (v1.19.0+), a sanitized `rejects` passthrough {scope:"isolate", since, total, by_reason} — INFORMATIONAL, never an invariant: counts reset on isolate eviction/deploy, so they answer "is something being rejected right now and why", not "how healthy are we". Read-only; the optional worker input (default "analytics") reserves room for sibling workers.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_collector_status',
		'input_schema'        => array(
			// The [object,null] union: readonly ⇒ GET run-path ⇒ an omitted
			// ?input= delivers NULL, and a plain 'object' rejects every such call.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'worker' => array(
					'type'    => 'string',
					'enum'    => array( 'analytics' ),
					'default' => 'analytics',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'healthy'    => array( 'type' => 'boolean' ),
				'worker'     => array( 'type' => 'string' ),
				'error'      => array( 'type' => 'string' ),
				'invariants' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array( 'type' => 'string' ),
							'ok'     => array( 'type' => 'boolean' ),
							'detail' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => true, // it reaches the edge worker.
			),
		),
	) );
}
add_action( 'wp_abilities_api_init', 'snt_abilities_collector_status_register' );

/**
 * Ability execute callback: signal-noise/get-collector-status.
 *
 * @since 9.81.0
 * @param array|null $input Optional { worker: 'analytics' }.
 * @return array{healthy:bool,worker:string,error?:string,invariants:array[]}
 */
function snt_ability_get_collector_status( $input = null ) {
	$worker = is_array( $input ) && isset( $input['worker'] ) ? (string) $input['worker'] : 'analytics';

	$url = function_exists( 'sn_worker_version_endpoint_url' ) ? sn_worker_version_endpoint_url() : '';
	if ( '' === $url ) {
		return array(
			'healthy'    => false,
			'worker'     => $worker,
			'error'      => 'no-endpoint',
			'invariants' => array(),
		);
	}

	$resp = wp_safe_remote_get(
		$url,
		array(
			'timeout'     => SN_COLLECTOR_STATUS_TIMEOUT,
			'redirection' => 0,
			'headers'     => array( 'Accept' => 'application/json' ),
			'user-agent'  => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : 'dev' ) . ' collector-status',
		)
	);
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return array(
			'healthy'    => false,
			'worker'     => $worker,
			'error'      => 'unreachable',
			'invariants' => array(),
		);
	}
	$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $json ) ) {
		return array(
			'healthy'    => false,
			'worker'     => $worker,
			'error'      => 'bad-response',
			'invariants' => array(),
		);
	}

	$out = array_merge(
		array( 'worker' => $worker ),
		sn_collector_status_invariants( $json, time() )
	);

	// v10.62.0: pass through the worker's isolate-scoped reject counters
	// (worker v1.19.0+) — INFORMATIONAL, never an invariant: the counts reset
	// on eviction/deploy (the block self-describes with scope:"isolate"), so
	// "low" is not evidence of health. What they buy: a token-rotation outage
	// or limiter failure shows up as climbing token/ratelimit_error counts
	// instead of an unexplained traffic decline. Sanitized passthrough —
	// reason names allowlisted, counts int-cast, never arbitrary worker JSON.
	$rejects = sn_collector_status_sanitize_rejects( $json['rejects'] ?? null );
	if ( null !== $rejects ) {
		$out['rejects'] = $rejects;
	}

	return $out;
}

/**
 * Sanitize the worker's rejects block. PURE. Null in/malformed -> null (the
 * key is simply absent for pre-v1.19.0 workers).
 *
 * @since 10.62.0
 * @param mixed $raw
 * @return array{scope:string,since:?string,total:int,by_reason:array<string,int>}|null
 */
function sn_collector_status_sanitize_rejects( $raw ) {
	if ( ! is_array( $raw ) ) {
		return null;
	}
	$by_reason = array();
	foreach ( (array) ( $raw['by_reason'] ?? array() ) as $reason => $count ) {
		if ( is_string( $reason ) && 1 === preg_match( '/^[a-z_]{1,32}$/', $reason ) ) {
			$by_reason[ $reason ] = (int) $count;
		}
	}
	return array(
		'scope'     => 'isolate',
		'since'     => is_string( $raw['since'] ?? null ) ? substr( $raw['since'], 0, 32 ) : null,
		'total'     => (int) ( $raw['total'] ?? 0 ),
		'by_reason' => $by_reason,
	);
}
