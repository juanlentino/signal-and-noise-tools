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
 * Watches a LIST of workflows, each with its own grace, because one repo can
 * carry crons of different cadence and a shared tolerance fits none of them.
 *
 * Usage (stdin is JSON; the workflow assembles it from `gh api`):
 *   echo '{"workflows":[{"name":"smoke-test.yml","grace_hours":6,
 *          "workflow_created_at":"…","scheduled_run_times":["…"]}]}' \
 *     | php tools/cron-liveness.php --check
 *
 * The older single-workflow shape still parses, taking --grace-hours:
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

/**
 * The same question asked of SEVERAL workflows, each with its OWN grace.
 *
 * Grace is per-workflow and REQUIRED — never a shared default — because the
 * tolerance that fits a daily cron is nearly useless on an hourly one. The
 * theme's smoke-test fires every hour and its largest observed gap between
 * consecutive scheduled runs is 2.2h; watched at the daily guard's 48h it
 * could miss twenty-two consecutive runs and still report health. Demanding
 * the number forces whoever adds a row to look at the cadence first, which is
 * the only way this file's answer means anything.
 *
 * An EMPTY list is a FAILURE, not a clean bill of health. This is the same
 * shape as `jq 'all(.[]; …)'` over an empty array answering true: nothing was
 * checked, and nothing checked reads exactly like everything passing. A guard
 * built to catch absence must not be defeated by the absence of its own input.
 *
 * @param mixed $workflows List of {name, grace_hours, workflow_created_at, scheduled_run_times}.
 * @param int   $now       Unix time to judge against.
 * @return array{ok:bool,code:string,rows:array<int,array{name:string,verdict:array}>}
 */
function sn_cron_liveness_report( $workflows, $now ) {
	if ( ! is_array( $workflows ) || array() === $workflows ) {
		return array(
			'ok'   => false,
			'code' => 'no-workflows',
			'rows' => array(),
		);
	}

	$rows = array();
	$ok   = true;

	foreach ( $workflows as $entry ) {
		$entry = is_array( $entry ) ? $entry : array();
		$name  = isset( $entry['name'] ) && is_string( $entry['name'] ) ? trim( $entry['name'] ) : '';

		if ( '' === $name ) {
			// A nameless row cannot tell you WHICH cron died, which is the
			// only thing the operator needs from a red.
			$ok     = false;
			$rows[] = array(
				'name'    => '(unnamed)',
				'verdict' => array(
					'ok'        => false,
					'code'      => 'unnamed-workflow',
					'message'   => 'unnamed-workflow: a row arrived without a workflow name, so its verdict could not be attributed.',
					'age_hours' => null,
				),
			);
			continue;
		}

		$grace = isset( $entry['grace_hours'] ) ? (int) $entry['grace_hours'] : 0;
		if ( $grace <= 0 ) {
			// Deliberately NOT defaulted. Silently borrowing 48h is how an
			// hourly cron ends up watched by a daily tolerance.
			$ok     = false;
			$rows[] = array(
				'name'    => $name,
				'verdict' => array(
					'ok'        => false,
					'code'      => 'bad-grace',
					'message'   => sprintf( 'bad-grace: %s carries no usable grace_hours. State the tolerance that fits ITS cadence; there is no shared default.', $name ),
					'age_hours' => null,
				),
			);
			continue;
		}

		$runs    = isset( $entry['scheduled_run_times'] ) && is_array( $entry['scheduled_run_times'] ) ? $entry['scheduled_run_times'] : array();
		$created = isset( $entry['workflow_created_at'] ) ? $entry['workflow_created_at'] : null;
		$verdict = sn_cron_liveness_verdict( $runs, $created, $now, $grace );

		if ( ! $verdict['ok'] ) {
			$ok = false;
		}
		$rows[] = array(
			'name'    => $name,
			'verdict' => $verdict,
		);
	}

	return array(
		'ok'   => $ok,
		'code' => $ok ? 'all-live' : 'some-failed',
		'rows' => $rows,
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

// Two accepted shapes. The single-workflow form predates the list and is kept
// working so a repo can migrate its CI step separately from this file.
if ( isset( $payload['workflows'] ) ) {
	$workflows = $payload['workflows'];
} else {
	$workflows = array(
		array(
			'name'                => isset( $payload['name'] ) ? $payload['name'] : 'the scheduled workflow',
			'grace_hours'         => $grace_hours,
			'workflow_created_at' => isset( $payload['workflow_created_at'] ) ? $payload['workflow_created_at'] : null,
			'scheduled_run_times' => isset( $payload['scheduled_run_times'] ) && is_array( $payload['scheduled_run_times'] ) ? $payload['scheduled_run_times'] : array(),
		),
	);
}

$report = sn_cron_liveness_report( $workflows, time() );

if ( array() === $report['rows'] ) {
	fwrite( STDERR, "FAIL: cron-liveness was handed no workflows to check. Nothing checked is not the same as nothing wrong. code=no-workflows\n" );
	exit( 1 );
}

$failed = 0;
foreach ( $report['rows'] as $row ) {
	$verdict = $row['verdict'];
	if ( ! $verdict['ok'] ) {
		++$failed;
	}
	printf(
		"%-6s %-24s %s\n",
		$verdict['ok'] ? 'ok' : 'FAIL',
		$row['name'],
		$verdict['message']
	);
}

// A summary line, never absence-of-FAIL. Something must be PRESENT to read.
printf(
	"%s: %d workflow%s checked, %d failed. code=%s\n",
	$report['ok'] ? 'OK' : 'FAIL',
	count( $report['rows'] ),
	1 === count( $report['rows'] ) ? '' : 's',
	$failed,
	$report['code']
);
exit( $report['ok'] ? 0 : 1 );
