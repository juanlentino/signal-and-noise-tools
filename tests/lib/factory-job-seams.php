<?php
/**
 * Part of tests/factory-job.php: the test seams and job.json itself.
 * Dry and live state never mix, FACTORY_ROOT is a dry-only seam, a job's
 * worktree and branch come from its id and never from the file, and a
 * job.json of the wrong shape gets one line of refusal from every command.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! $live_ok ) { ok( false, 'the stand-in gh is first on PATH (the seams part did not run)' ); return; }
$dry_at = function ( $folder, ...$a ) { global $root; return run_job( array( 'FACTORY_DRY' => '1', 'FACTORY_ROOT' => $root, 'FACTORY_JOBS' => $folder ), $a ); };

// The harness itself: its git helper resets and cleans, so it must refuse anything outside the fixture.
ok( 1 === git( '', 'rev-parse HEAD' )[0] && 1 === git( dirname( dirname( $tool ) ), 'rev-parse HEAD' )[0] && 0 === git( $root, 'rev-parse HEAD' )[0], 'the test helper runs git inside the fixture only: an empty path or the real checkout is refused' );

// 5. A job born in a dry run is a dry job for good, and a live job is never a dry one.
walk( '81', 'admin', 'build' ); issue(); live( 'new', '82' );
ok( true === ( state( '81' )['dry'] ?? null ) && ! isset( state( '82' )['dry'] ), 'a job made under FACTORY_DRY is marked dry in job.json; a live one is not' );
list( $c, $o ) = live( 'advance', '81', 'needs-info' );
ok( 0 !== $c && false !== strpos( $o, 'dry run' ) && 'build' === state( '81' )['stage'], 'a live run refuses a dry job' );
putj( '82', 'intake.md', "DECISION: ACCEPT\nSURFACES: admin\n" ); live( 'escalate', '82', 'a question' ); $GLOBALS['owner_ok'] = '1';
list( $c, $o ) = job( 'advance', '82', 'spec' );
ok( 0 !== $c && false !== strpos( $o, 'live job' ) && 'needs-human' === state( '82' )['stage'], 'a dry run refuses a live job, so FACTORY_OWNER_OK never answers for a real owner' );
list( $c, $o ) = run_job( array( 'FACTORY_DRY' => '1', 'FACTORY_ROOT' => $root ), array( 'advance', '82', 'spec' ), "$copy/job" );
ok( 0 !== $c && 'needs-human' === state( '82' )['stage'], 'the same holds for a dry run of another copy of the script' );
$GLOBALS['owner_ok'] = '0';
mkdir( "$tmp/sj" ); symlink( "$jobs/81", "$tmp/sj/81" );
list( $c, $o ) = $dry_at( "$tmp/sj", 'advance', '81', 'gate' );
ok( 0 !== $c && false !== strpos( $o, 'symlink' ), 'a job folder reached through a symlink is refused' );
symlink( "$tool/jobs", "$tmp/alias" );
list( $c, $o ) = $dry_at( "$tmp/alias", 'status' );
ok( 0 !== $c && false !== strpos( $o, 'FACTORY_JOBS' ), 'a symlink to the real jobs folder is still the real jobs folder' );

@mkdir( "$copy/jobs" ); $shout = "$root/tools/factory/JOBS"; // the copy's own real jobs folder, spelled in capitals
if ( is_dir( $shout ) ) { // a case-insensitive filesystem: the same folder under another spelling
	list( $c, $o ) = run_job( array( 'FACTORY_DRY' => '1', 'FACTORY_ROOT' => $root, 'FACTORY_JOBS' => $shout ), array( 'status' ), "$copy/job" );
	ok( 0 !== $c && false !== strpos( $o, 'FACTORY_JOBS' ), 'the real jobs folder spelled in another case is still the real jobs folder' );
}
foreach ( array( 'true', 'yes', '01', '0', ' 1' ) as $v ) {
	list( $c, $o ) = run_job( array( 'FACTORY_DRY' => $v, 'FACTORY_ROOT' => $root ), array( 'status' ) );
	ok( 0 !== $c && false !== strpos( $o, 'FACTORY_DRY' ) && 1 === count( explode( "\n", trim( $o ) ) ), "FACTORY_DRY=\"$v\" is refused in one line, not run live" );
}

// 6. FACTORY_ROOT moves the root only in a dry run. (`where` only prints; it creates nothing.)
$real = dirname( dirname( $tool ) );
list( $c, $o ) = run_job( live_env() + array( 'FACTORY_ROOT' => $root ), array( 'where', '82' ) );
ok( 0 === $c && 0 === strpos( $o, "$real/.claude/worktrees/factory-82\n" ), 'a live run ignores FACTORY_ROOT: the root is where the script sits' );
ok( 0 === strpos( job( 'where', '81' )[1], "$root/.claude/worktrees/factory-81\n" ), 'a dry run takes its root from FACTORY_ROOT' );

// 9. job.json is not trusted for identity or shape.
commit_in( '81', 'so the worktree is not at main' ); putj( '81', 'round-1/build.md', "STATUS: READY\n" ); job( 'advance', '81', 'gate' );
$main = trim( git( $root, 'rev-parse HEAD' )[1] ); set_state( '81', array( 'worktree' => $root, 'branch' => 'main' ) ); putj( '81', 'round-1/checks.md', "PASS $main\n" );
list( $c, $o ) = job( 'advance', '81', 'review' );
ok( 0 !== $c && false !== strpos( $o, 'checks.md' ), 'a job.json edited to point at the main checkout changes nothing: worktree and branch come from the id' );
$shapes = array( '{}', 'null', '[]', '"x"', '{"id":"5","stage":"intake"}', '{"id":"5","stage":"gate","round":"x"}', '{"id":"5","stage":"bogus","round":1}', '{"id":"6","stage":"intake","round":1}', '{"id":"5","stage":"intake","round":1,"stopped":"soon"}' );
foreach ( $shapes as $body ) {
	putj( '5', 'job.json', $body ); $all = ''; $refused = true;
	foreach ( array( array( 'advance', '5', 'spec' ), array( 'escalate', '5', 'why' ), array( 'ready', '5' ), array( 'worktree', '5' ), array( 'refresh', '5' ), array( 'where', '5' ), array( 'status', '5' ) ) as $cmd ) {
		list( $c, $o ) = job( ...$cmd ); $all .= $o; $refused = $refused && 0 !== $c && false !== strpos( $o, 'wrong shape' ) && 1 === count( explode( "\n", trim( $o ) ) );
	}
	list( $c, $o ) = job( 'status' );
	ok( $refused && false === strpos( $all . $o, 'Traceback' ) && 0 === $c && false !== strpos( $o, 'skipping job 5' ), "a job.json that is $body gets one line of refusal from every command" );
}
rrmdir( "$jobs/5" );
issue( array( 'comments' => array( array( 'body' => 'no author field' ) ) ) );
list( $c, $o ) = live( 'advance', '82', 'spec' ); issue();
ok( 0 !== $c && false === strpos( $o, 'Traceback' ) && false !== strpos( $o, 'unexpected' ), 'an answer from gh of the wrong shape is a one-line refusal too, never a traceback' );
foreach ( array( '-5', '0', '../x', '12/../13', ' 7 ', '0x10', '1e3', "\xef\xbc\x91\xef\xbc\x92" ) as $id ) {
	list( $c, $o ) = job( 'new', $id );
	ok( 0 !== $c && false !== strpos( $o, 'positive whole number' ), "new refuses the id \"$id\"" );
}
ok( ! file_exists( "$tmp/x" ) && ! file_exists( "$jobs/13" ) && ! file_exists( "$jobs/-5" ), 'and none of those ids left a folder anywhere' );
list( $c, $o ) = job( 'status', '4242' );
ok( 0 !== $c && false !== strpos( $o, 'no job 4242' ), 'status for a job that does not exist says so and fails' );
