<?php
/**
 * Signal & Noise Tools: deploy-workers.php, the per-worker and fleet status,
 * the warm cron and its hooks, and the cache flush.
 *
 * Split from inc/deploy-workers.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status struct for one worker.
 *
 * @param string $id    Registry key.
 * @param array  $opts  { force?:bool, allow_probe?:bool }. allow_probe false
 *                      serves cache only (miss → unknown without HTTP).
 * @return array{id:string,label:string,live:string,latest:string,state:string,repo:string,source_commit:string,reason:string}
 */
function snt_deploy_worker_status_for( $id, $opts = array() ) {
	$registry = snt_deploy_workers_registry();
	$id       = (string) $id;
	$force    = ! empty( $opts['force'] );
	$allow    = ! array_key_exists( 'allow_probe', $opts ) || ! empty( $opts['allow_probe'] );

	$empty = array(
		'id'            => $id,
		'label'         => $id,
		'live'          => '',
		'latest'        => '',
		'state'         => 'unknown',
		'repo'          => '',
		'source_commit' => '',
		'reason'        => 'unknown worker',
	);
	if ( ! isset( $registry[ $id ] ) || ! is_array( $registry[ $id ] ) ) {
		return $empty;
	}
	$cfg = $registry[ $id ];

	$label = isset( $cfg['label'] ) ? (string) $cfg['label'] : $id;
	$repo  = isset( $cfg['repo'] ) ? (string) $cfg['repo'] : '';

	$live_result = array(
		'ok'     => false,
		'live'   => '',
		'commit' => '',
		'error'  => 'skipped',
	);
	if ( $allow ) {
		$live_result = snt_deploy_worker_live_probe( $id, $cfg, $force );
	} else {
		// Cache-only path: never blocks the dashboard on a cold edge.
		$key    = 'snt_dw_live_' . preg_replace( '/[^a-z0-9_-]/i', '', $id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'live', $cached ) ) {
			$live_result = $cached;
		} elseif ( array_key_exists( 'probe_url', $cfg ) && null === $cfg['probe_url'] ) {
			$live_result = array(
				'ok'     => false,
				'live'   => 'unprobeable',
				'commit' => '',
				'error'  => 'unprobeable',
			);
		}
	}

	$latest = snt_deploy_worker_latest_tag( $repo, $id, $force );
	$latest = null === $latest ? '' : (string) $latest;
	$live   = (string) ( $live_result['live'] ?? '' );
	// A live version newer than the newest tag cannot be true: the tag cache is
	// from before the release. Ask GitHub again, at most once per worker per
	// TTL, so a deploy whose tag is late (or never made) cannot spend the
	// rate limit on every read.
	if ( ! $force && snt_deploy_worker_tag_is_stale( $live, $latest ) ) {
		$guard = 'snt_dw_tag_refetch_' . preg_replace( '/[^a-z0-9_-]/i', '', $id );
		if ( false === get_transient( $guard ) ) {
			set_transient( $guard, 1, SNT_DEPLOY_WORKER_TAG_REFETCH_TTL );
			$fresh  = snt_deploy_worker_latest_tag( $repo, $id, true );
			$latest = null === $fresh ? $latest : (string) $fresh;
		}
	}
	$state  = snt_deploy_worker_state( $live, $latest );

	$reason = '';
	if ( 'unknown' === $state ) {
		if ( 'unprobeable' === $live ) {
			$reason = 'no public version route';
		} elseif ( '' === $live ) {
			$err = (string) ( $live_result['error'] ?? '' );
			// Cold is not broken: a budget-skipped row has simply never been
			// probed this cache cycle (the warm cron is already scheduled).
			// Say so, instead of leaking the internal 'skipped' token as if
			// it were a failure.
			$reason = ( 'skipped' === $err ) ? 'warming' : ( '' !== $err ? $err : 'probe failed' );
		} elseif ( '' === $latest ) {
			$reason = 'no GitHub tag';
		}
	}

	$row = array(
		'id'            => $id,
		'label'         => $label,
		'live'          => $live,
		'latest'        => $latest,
		'state'         => $state,
		'repo'          => $repo,
		'source_commit' => (string) ( $live_result['commit'] ?? '' ),
		'reason'        => $reason,
	);
	// v13.26.0: carry the versioned-contract comparison through to consumers
	// (fleet zone, get-deploy-status, sn-status{deploy}). The probe only sets
	// these for workers whose registry entry names a contract_path, and
	// absence stays absence — a worker with no contract is not "matching".
	if ( array_key_exists( 'contract_match', $live_result ) ) {
		$row['contract_live']     = (string) ( $live_result['contract_live'] ?? '' );
		$row['contract_expected'] = (string) ( $live_result['contract_expected'] ?? '' );
		$row['contract_match']    = (bool) $live_result['contract_match'];
	}
	return $row;
}

/**
 * Status for every registered worker, stable registry order.
 *
 * $opts:
 *   - force (bool)         Bypass caches.
 *   - probe_budget (int)   Max cold live probes this call. Dashboard uses 1 so
 *                          a cold page load never fans out five HTTP calls;
 *                          the ability uses a high budget. Cached rows never
 *                          spend budget. Tags always resolve (separate cache).
 *
 * @param array $opts
 * @return array<int,array<string,mixed>>
 */
function snt_deploy_workers_status( $opts = array() ) {
	$force  = ! empty( $opts['force'] );
	$budget = array_key_exists( 'probe_budget', $opts ) ? (int) $opts['probe_budget'] : 5;
	if ( $force ) {
		$budget = PHP_INT_MAX;
	}

	$out  = array();
	$cold = 0;
	foreach ( snt_deploy_workers_registry() as $id => $cfg ) {
		if ( ! is_array( $cfg ) ) {
			continue;
		}
		$cache_key   = 'snt_dw_live_' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $id );
		$warm        = is_array( get_transient( $cache_key ) );
		// Unprobeable (null URL) never needs HTTP — always allow.
		$unprobeable = array_key_exists( 'probe_url', $cfg ) && null === $cfg['probe_url'];
		$allow       = $force || $warm || $unprobeable;
		if ( ! $allow && $budget > 0 ) {
			$allow = true;
			--$budget;
		}
		if ( ! $allow ) {
			$cold++;
		}

		$out[] = snt_deploy_worker_status_for(
			(string) $id,
			array(
				'force'       => $force,
				'allow_probe' => $allow,
			)
		);
	}

	// A render that left workers cold (post-flush, post-install) schedules an
	// immediate out-of-band warm so the NEXT render reads every cache hot —
	// the budget keeps THIS render unblocked, the cron removes the four
	// dead-eyed "unknown" cards the budget would otherwise leave behind for
	// several page loads (observed live minutes after v11.11.4 installed).
	if ( $cold > 0 && function_exists( 'wp_schedule_single_event' )
		&& function_exists( 'wp_next_scheduled' )
		&& ! wp_next_scheduled( 'snt_deploy_workers_warm' ) ) {
		wp_schedule_single_event( time() + 5, 'snt_deploy_workers_warm' );
	}
	return $out;
}

/** Cron: probe every cold worker with a full budget (never in a page load). */
function snt_deploy_workers_warm_cb() {
	// Every warm probes, whatever the cache says: a cache still valid at the
	// warm would skip it and leave a fresh deploy unread for another cycle.
	// One worker at a time, each overwriting its own entry, so a page load
	// during the warm never finds the fleet cold.
	foreach ( snt_deploy_workers_registry() as $id => $cfg ) {
		if ( is_array( $cfg ) ) {
			snt_deploy_worker_live_probe( (string) $id, $cfg, true );
		}
	}
	snt_deploy_workers_status( array( 'probe_budget' => 10 ) );
}
add_action( 'snt_deploy_workers_warm', 'snt_deploy_workers_warm_cb' );

/**
 * Register the 5-minute recurrence the warm runs on.
 *
 * The interval is not a taste: it MUST stay below
 * SNT_DEPLOY_WORKER_LIVE_TTL_OK, or every cycle leaves a window in which the
 * cache has expired and the dashboard is cold again. The suite pins that
 * relationship as arithmetic rather than as a comment.
 *
 * @since 11.32.0
 * @param array<string,array<string,mixed>> $schedules Existing schedules.
 * @return array<string,array<string,mixed>>
 */
function snt_deploy_workers_cron_schedules( $schedules ) {
	if ( ! is_array( $schedules ) ) {
		$schedules = array();
	}
	if ( ! isset( $schedules['sn_five_minutes'] ) ) {
		$schedules['sn_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Signal & Noise)', 'signal-and-noise-tools' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'snt_deploy_workers_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 5 min is required to stay under the live-probe TTL; see the docblock.

/**
 * Keep the fleet's live-probe cache warm ahead of any human arriving.
 *
 * WHY THIS EXISTS. The dashboard renders with `probe_budget => 1` across FIVE
 * workers so a cold edge can never block the page. That is correct — but it
 * means a cold cache leaves four rows reading "warming…", and the cache lives
 * ten minutes. The previous design healed that reactively: a render that left
 * workers cold scheduled a ONE-OFF warm. That fixes the NEXT render and
 * nothing after it, so on an admin screen visited every few hours the caches
 * are always expired on arrival and "warming…" is the steady state. Observed
 * live 2026-08-19: three cells reading "warming…" for hours while every worker
 * was in fact answering with a current version.
 *
 * Warming on a schedule shorter than the TTL inverts that: the cache is hot
 * before anyone looks, the render never pays for a probe, and "warming…" goes
 * back to meaning what it says — a genuinely cold start.
 *
 * MIGRATION. An install carrying the old one-off event must be moved onto the
 * recurrence. wp_next_scheduled() cannot tell the two apart (both return a
 * timestamp), so this reads the event itself and clears a non-recurring one.
 *
 * @since 11.32.0
 * @return void
 */
function snt_deploy_workers_warm_schedule() {
	if ( ! function_exists( 'wp_get_scheduled_event' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	$event = wp_get_scheduled_event( 'snt_deploy_workers_warm' );
	if ( $event && empty( $event->schedule ) ) {
		// A leftover one-off. Firing once and stopping is exactly the bug.
		wp_clear_scheduled_hook( 'snt_deploy_workers_warm' );
		$event = false;
	}
	if ( ! $event ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'sn_five_minutes', 'snt_deploy_workers_warm' );
	}
}
add_action( 'init', 'snt_deploy_workers_warm_schedule' );

/**
 * Drop live + tag caches for every registered worker (force_refresh path).
 *
 * @return void
 */
function snt_deploy_workers_flush_caches() {
	foreach ( array_keys( snt_deploy_workers_registry() ) as $id ) {
		$safe = preg_replace( '/[^a-z0-9_-]/i', '', (string) $id );
		delete_transient( 'snt_dw_live_' . $safe );
		delete_site_transient( 'snt_dw_tag_' . $safe );
	}
}
