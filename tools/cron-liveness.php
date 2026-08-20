<?php
/**
 * Does a scheduled workflow show signs of life?
 *
 * A cron cannot witness its own absence. When a scheduled workflow stops
 * firing — GitHub's 60-day inactivity disable, a cron expression that never
 * parsed, a file that never reached the default branch, a repo-level Actions
 * block — it produces NO run, and an absent run reads exactly like a run that
 * passed. So this guard runs on a DIFFERENT trigger (pull_request/push) from
 * the workflow it watches, and asserts a run must be PRESENT.
 *
 * Companion to version-tag-parity.php: that one asks whether the release
 * happened, this one asks whether the thing that asks is still asking.
 *
 * The verdict is a pure function taking its clock as an argument, so the whole
 * decision table is drivable offline — see tests/cron-liveness.php.
 *
 * Dev/CI tooling. Never bundled with the plugin.
 *
 * Usage (stdin is JSON; the workflow assembles it from `gh api`):
 *   echo '{"workflow_created_at":"…","scheduled_run_times":["…"]}' \
 *     | php tools/cron-liveness.php --check [--grace-hours=48]
 *
 * @package signal-and-noise-tools
 */

/**
 * Five verdicts, of which two are failures — kept distinct because the fix
 * differs, the same reason version-tag-parity.php separates missing-tag from
 * tag-off-main. A single red would send you to the wrong door.
 *
 *   live            a scheduled run landed within grace.
 *   not-yet-due     no run yet, but the workflow is younger than grace — it
 *                   cannot have fired. A check that reds the day it lands
 *                   teaches everyone to ignore it.
 *   stale     FAIL  it fired before and stopped. Look at the 60-day inactivity
 *                   disable, or a repo-level Actions block.
 *   never-scheduled FAIL  it never registered at all. Look at the cron
 *                   expression, or whether the file is on the default branch.
 *   indeterminate   FAIL  the workflow's age could not be read, so nothing can
 *                   be concluded. An instrument that cannot see must never
 *                   report health — that is the failure mode this file exists
 *                   to catch, and it would be absurd to reproduce it here.
 *
 * @param array<int,mixed> $scheduled_run_times ISO-8601 stamps of runs with event=schedule.
 * @param mixed            $workflow_created_at ISO-8601 stamp, or null when unknown.
 * @param int              $now                 Unix time to judge against.
 * @param int              $grace_hours         How long silence is tolerated.
 * @return array{ok:bool,code:string,message:string,age_hours:?float}
 */
function sn_cron_liveness_verdict( array $scheduled_run_times, $workflow_created_at, $now, $grace_hours = 48 ) {
	$grace = (int) $grace_hours * 3600;

	// An unparseable stamp is ABSENCE, not presence. Counting it as a run
	// would let a malformed API payload assert liveness.
	$stamps = array();
	foreach ( $scheduled_run_times as $raw ) {
		$t = is_string( $raw ) ? strtotime( $raw ) : false;
		if ( false !== $t ) {
			$stamps[] = $t;
		}
	}

	if ( array() !== $stamps ) {
		// The newest decides. The API promises no ordering, so never trust [0].
		$age = (int) $now - max( $stamps );
		if ( $age <= $grace ) {
			return array(
				'ok'        => true,
				'code'      => 'live',
				'message'   => sprintf( 'live: a scheduled run landed %.1fh ago (grace %dh).', $age / 3600, (int) $grace_hours ),
				'age_hours' => round( $age / 3600, 1 ),
			);
		}
		return array(
			'ok'        => false,
			'code'      => 'stale',
			'message'   => sprintf(
				'stale: the newest scheduled run is %.1fh old, past the %dh grace. It fired before and stopped — check the 60-day inactivity disable, or whether Actions is blocked for this repo.',
				$age / 3600,
				(int) $grace_hours
			),
			'age_hours' => round( $age / 3600, 1 ),
		);
	}

	$created = is_string( $workflow_created_at ) ? strtotime( $workflow_created_at ) : false;
	if ( false === $created ) {
		return array(
			'ok'        => false,
			'code'      => 'indeterminate',
			'message'   => 'indeterminate: no scheduled run, and the workflow age could not be read — nothing can be concluded, so this does not pass.',
			'age_hours' => null,
		);
	}

	$age = (int) $now - $created;
	if ( $age <= $grace ) {
		return array(
			'ok'        => true,
			'code'      => 'not-yet-due',
			'message'   => sprintf( 'not-yet-due: the workflow is %.1fh old (grace %dh) and cannot have fired on schedule yet.', $age / 3600, (int) $grace_hours ),
			'age_hours' => round( $age / 3600, 1 ),
		);
	}
	return array(
		'ok'        => false,
		'code'      => 'never-scheduled',
		'message'   => sprintf(
			'never-scheduled: the workflow has existed %.1fh and has NEVER produced a run with event=schedule. It never registered — check the cron expression, and that the file is on the default branch.',
			$age / 3600
		),
		'age_hours' => round( $age / 3600, 1 ),
	);
}

if ( defined( 'SN_CRON_LIVENESS_NO_MAIN' ) ) {
	return;
}

$argv_local  = isset( $argv ) && is_array( $argv ) ? $argv : array();
$grace_hours = 48;
foreach ( $argv_local as $arg ) {
	if ( is_string( $arg ) && 0 === strpos( $arg, '--grace-hours=' ) ) {
		$grace_hours = (int) substr( $arg, strlen( '--grace-hours=' ) );
	}
}

$raw     = (string) file_get_contents( 'php://stdin' );
$payload = json_decode( $raw, true );
if ( ! is_array( $payload ) ) {
	// Refuse rather than default. A missing payload is the instrument failing,
	// and the whole point of this file is that a blind instrument reports red.
	fwrite( STDERR, "FAIL: cron-liveness could not parse its stdin payload. code=indeterminate\n" );
	exit( 1 );
}

$runs    = isset( $payload['scheduled_run_times'] ) && is_array( $payload['scheduled_run_times'] ) ? $payload['scheduled_run_times'] : array();
$created = isset( $payload['workflow_created_at'] ) ? $payload['workflow_created_at'] : null;
$verdict = sn_cron_liveness_verdict( $runs, $created, time(), $grace_hours );

printf(
	"%s: %s\ncode=%s ok=%s runs_seen=%d\n",
	$verdict['ok'] ? 'OK' : 'FAIL',
	$verdict['message'],
	$verdict['code'],
	$verdict['ok'] ? 'true' : 'false',
	count( $runs )
);
exit( $verdict['ok'] ? 0 : 1 );
