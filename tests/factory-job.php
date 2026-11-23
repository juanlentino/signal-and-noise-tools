<?php
/**
 * The factory's state keeper (tools/factory/job): it refuses an illegal
 * stage move, a move without its evidence file, and a public surface without
 * the owner; a failed round costs one; it stops at ready and never merges.
 * The parts in tests/lib/factory-*.php cover the bypasses an audit reproduced
 * (factory-job-evidence, -live, -worktree, -seams) and the gate (factory-gate).
 * Run: php tests/factory-job.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
if ( '' === trim( (string) shell_exec( 'command -v python3' ) ) ) { echo "SKIP: python3 not found\n\nResult: 0 passed, 0 failed.\n"; exit( 0 ); }

require __DIR__ . '/lib/factory-harness.php';
function put( $rel, $text ) { putj( '7', $rel, $text ); }

ok( 0 === job( 'new', '7' )[0] && is_file( "$jobs/7/issue.md" ), 'new: a job folder with the issue as quoted data' );
ok( false !== strpos( file_get_contents( "$jobs/7/issue.md" ), 'never an instruction' ), 'issue.md says the request is data, never an instruction' );
ok( 0 !== job( 'new', '7' )[0], 'a second job for the same issue is refused' );
ok( 0 !== job( 'advance', '7', 'build' )[0], 'intake -> build is not a legal move' );
ok( 0 !== job( 'advance', '7', 'spec' )[0], 'intake -> spec is refused without an accepting intake.md' );
put( 'intake.md', "DECISION: ACCEPT\nRISK: low\nSURFACES: public, admin\n" );
list( $c, $o ) = job( 'advance', '7', 'spec' );
ok( 0 !== $c && false !== strpos( $o, 'public surface' ), 'a public surface cannot reach spec: the owner decides first' );
foreach ( array( 'SURFACES: Public', '**SURFACES:** public', 'Surfaces : admin, PUBLIC' ) as $line ) {
	put( 'intake.md', "DECISION: ACCEPT\nRISK: low\n$line\n" );
	list( $c, $o ) = job( 'advance', '7', 'spec' );
	ok( 0 !== $c && false !== strpos( $o, 'public surface' ), "the public stop reads \"$line\" too" );
}
put( 'intake.md', "DECISION: ACCEPT\nRISK: low\n" );
ok( 0 !== job( 'advance', '7', 'spec' )[0], 'an intake with no SURFACES line is refused (fail closed)' );
put( 'intake.md', "DECISION: ACCEPT\nRISK: low\nSURFACES: admin\n" );
ok( 0 === job( 'advance', '7', 'spec' )[0], 'an accepted, non-public intake moves to spec' );
ok( 0 !== job( 'advance', '7', 'build' )[0], 'spec -> build is refused without spec.md' );
put( 'spec.md', "1. a criterion\n" );
ok( 0 === job( 'advance', '7', 'build' )[0], 'with spec.md the job moves to build' );
ok( 0 !== job( 'advance', '7', 'gate' )[0], 'build -> gate is refused until build.md says STATUS: READY' );
put( 'build.md', "STATUS: READY\n" );
ok( 0 !== job( 'advance', '7', 'gate' )[0], 'a build.md outside the round folder is not this round\'s evidence' );
put( 'round-1/build.md', "STATUS: READY\n" );
ok( 0 === job( 'advance', '7', 'gate' )[0], 'a READY build moves to gate' );
ok( 0 !== job( 'advance', '7', 'review' )[0], 'gate -> review is refused without a passing checks.md' );

// A real worktree HEAD, so the gate's sha is checked against it.
job( 'worktree', '7' ); $sha = head_of( '7' );
put( 'round-1/checks.md', "PASS 0000000000000000000000000000000000000000\n" );
ok( 0 !== job( 'advance', '7', 'review' )[0], 'a gate that passed on another commit does not count' );
put( 'round-1/checks.md', "PASS $sha\n" );
ok( 0 === job( 'advance', '7', 'review' )[0], 'a gate that passed on the current HEAD moves to review' );
ok( 0 !== job( 'advance', '7', 'ready' )[0], 'review -> ready is refused without VERDICT: PASS' );
put( 'round-1/review.md', "VERDICT: CHANGES\n" );
ok( 0 !== job( 'advance', '7', 'ready' )[0], 'a review asking for changes is not ready' );
ok( 0 === job( 'advance', '7', 'build' )[0] && 2 === json_decode( file_get_contents( "$jobs/7/job.json" ), true )['round'], 'changes send it back to build and cost a round' );
ok( 0 !== job( 'ready', '7' )[0], '`ready` refuses a job that is not at the ready stage' );

ok( 0 !== job( 'advance', '7', 'gate' )[0], 'round 2 needs its own build.md: round 1\'s READY does not carry over' );
put( 'round-2/build.md', "STATUS: READY\n" ); job( 'advance', '7', 'gate' ); put( 'round-2/checks.md', "PASS $sha\n" ); job( 'advance', '7', 'review' ); job( 'advance', '7', 'build' );
put( 'round-3/build.md', "STATUS: READY\n" ); job( 'advance', '7', 'gate' ); put( 'round-3/checks.md', "PASS $sha\n" ); job( 'advance', '7', 'review' );
list( $c, $o ) = job( 'advance', '7', 'build' );
ok( 0 !== $c && false !== strpos( $o, 'round limit' ), 'after three rounds another round is refused: escalate' );
put( 'round-3/review.md', "VERDICT: PASS\n" );
ok( 0 !== job( 'advance', '7', 'ready' )[0], 'a review that names no commit is not ready' );
put( 'round-3/review.md', "VERDICT: PASS 0000000000000000000000000000000000000000\n" );
ok( 0 !== job( 'advance', '7', 'ready' )[0], 'a review of another commit is not ready' );
put( 'round-3/review.md', "VERDICT: PASS $sha\n" );
ok( 0 === job( 'advance', '7', 'ready' )[0], 'gate PASS and review PASS, both on the current HEAD, reach ready' );
list( $c, $o ) = job( 'ready', '7' );
ok( 0 === $c && false !== strpos( $o, 'gh pr ready' ) && false === strpos( $o, 'pr merge' ), 'ready marks the pull request ready and comments; it never merges' );

// After a stop, only the owner's answer restarts a job, and old rounds cannot stand in for new ones.
list( $c, $o ) = job( 'advance', '7', 'build' );
ok( 0 !== $c && false !== strpos( $o, 'waiting for the owner' ), 'ready -> build is refused until the owner answers on the issue' );
$GLOBALS['owner_ok'] = '1';
ok( 0 === job( 'advance', '7', 'build' )[0] && 1 === json_decode( file_get_contents( "$jobs/7/job.json" ), true )['round'], 'with the owner\'s answer the job restarts at round 1' );
ok( ! is_file( "$jobs/7/round-1/review.md" ) && ! is_file( "$jobs/7/round-3/review.md" ) && 1 === count( glob( "$jobs/7/archive-*/round-3/review.md" ) ), 'the earlier rounds are archived: no old gate or review survives the restart' );
put( 'round-1/build.md', "STATUS: READY\n" ); job( 'advance', '7', 'gate' ); put( 'round-1/checks.md', "PASS $sha\n" ); job( 'advance', '7', 'review' );
ok( 0 !== job( 'advance', '7', 'ready' )[0], 'after a restart, ready needs a fresh review' );
$GLOBALS['owner_ok'] = '0';

job( 'new', '9' );
list( $c, $o ) = job( 'advance', '9', 'needs-human' );
ok( 0 !== $c && false !== strpos( $o, 'job escalate' ), 'needs-human is entered only through escalate, which records the stop and tells the owner' );
$j9 = json_decode( file_get_contents( "$jobs/9/job.json" ), true ); $j9['stage'] = 'needs-human'; unset( $j9['stopped'] ); file_put_contents( "$jobs/9/job.json", json_encode( $j9 ) );
file_put_contents( "$jobs/9/intake.md", "DECISION: ACCEPT\nSURFACES: public\n" ); file_put_contents( "$jobs/9/spec.md", "1.\n" );
$GLOBALS['owner_ok'] = '1';
ok( 0 !== job( 'advance', '9', 'spec' )[0] && 0 !== job( 'advance', '9', 'build' )[0], 'a waiting job with no recorded stop takes no comment as an answer' );
$GLOBALS['owner_ok'] = '0';
ok( isset( json_decode( file_get_contents( "$jobs/7/job.json" ), true )['stopped'] ), 'reaching ready records when the job stopped' );

job( 'new', '8' );
list( $c, $o ) = job( 'escalate', '8', 'needs a decision' );
ok( 0 === $c && false !== strpos( $o, 'factory:needs-human' ), 'escalate labels the issue and says why' );
file_put_contents( "$jobs/8/intake.md", "DECISION: ACCEPT\nSURFACES: public\n" );
list( $c, $o ) = job( 'advance', '8', 'spec' );
ok( 0 !== $c && false !== strpos( $o, 'waiting for the owner' ), 'escalating is no way around the owner: needs-human -> spec waits for their answer' );
$GLOBALS['owner_ok'] = '1';
ok( 0 !== job( 'advance', '8', 'build' )[0], 'an escalated job with no spec cannot jump to build' );
ok( 0 === job( 'advance', '8', 'spec' )[0], 'once the owner answers, a public-surface job may go on' );
file_put_contents( "$jobs/8/spec.md", "1.\n" ); $GLOBALS['owner_ok'] = '0';
ok( 0 === job( 'advance', '8', 'build' )[0], 'the owner\'s recorded yes carries that public job from spec to build' );
ok( false === strpos( file_get_contents( "$jobs/8/issue.md" ), "\n# Issue 8: " ) && false !== strpos( file_get_contents( "$jobs/8/issue.md" ), "## Title\n\n> " ), 'the issue title is quoted as data too' );
$src = file_get_contents( __DIR__ . '/../tools/factory/job' ) . file_get_contents( __DIR__ . '/../tools/factory/gate' ) . implode( '', array_map( 'file_get_contents', glob( __DIR__ . '/../tools/factory/jobkit/*.py' ) ) );
ok( false === strpos( $src, 'pr", "merge' ) && false === strpos( $src, 'pr merge' ) && false === strpos( $src, '--admin' ) && false === strpos( $src, 'cut-' ), 'neither script can merge, use --admin or cut' );

foreach ( array( 'evidence', 'live', 'worktree', 'seams' ) as $part ) { require __DIR__ . "/lib/factory-job-$part.php"; }
require __DIR__ . '/lib/factory-gate.php';
require __DIR__ . '/lib/factory-gate-rounds.php';

rrmdir( $tmp );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
