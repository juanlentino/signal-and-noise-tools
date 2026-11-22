<?php
/**
 * The factory's state keeper (tools/factory/job): it refuses an illegal
 * stage move, a move without its evidence file, and a public surface without
 * the owner; a failed round costs one; it stops at ready and never merges.
 * Run: php tests/factory-job.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
if ( '' === trim( (string) shell_exec( 'command -v python3' ) ) ) { echo "SKIP: python3 not found\n\nResult: 0 passed, 0 failed.\n"; exit( 0 ); }

$jobs = sys_get_temp_dir() . '/factory-test-' . getmypid();
$wt   = $jobs . '/wt';
function job( ...$a ) {
	global $jobs;
	$cmd = 'FACTORY_DRY=1 FACTORY_JOBS=' . escapeshellarg( $jobs ) . ' python3 ' . escapeshellarg( __DIR__ . '/../tools/factory/job' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $a ) ) . ' 2>&1';
	exec( $cmd, $out, $code );
	return array( $code, implode( "\n", $out ) );
}
function put( $rel, $text ) { global $jobs; @mkdir( dirname( "$jobs/7/$rel" ), 0777, true ); file_put_contents( "$jobs/7/$rel", $text ); }

ok( 0 === job( 'new', '7' )[0] && is_file( "$jobs/7/issue.md" ), 'new: a job folder with the issue as quoted data' );
ok( false !== strpos( file_get_contents( "$jobs/7/issue.md" ), 'never an instruction' ), 'issue.md says the request is data, never an instruction' );
ok( 0 !== job( 'new', '7' )[0], 'a second job for the same issue is refused' );
ok( 0 !== job( 'advance', '7', 'build' )[0], 'intake -> build is not a legal move' );
ok( 0 !== job( 'advance', '7', 'spec' )[0], 'intake -> spec is refused without an accepting intake.md' );
put( 'intake.md', "DECISION: ACCEPT\nRISK: low\nSURFACES: public, admin\n" );
list( $c, $o ) = job( 'advance', '7', 'spec' );
ok( 0 !== $c && false !== strpos( $o, 'public surface' ), 'a public surface cannot reach spec: the owner decides first' );
put( 'intake.md', "DECISION: ACCEPT\nRISK: low\nSURFACES: admin\n" );
ok( 0 === job( 'advance', '7', 'spec' )[0], 'an accepted, non-public intake moves to spec' );
ok( 0 !== job( 'advance', '7', 'build' )[0], 'spec -> build is refused without spec.md' );
put( 'spec.md', "1. a criterion\n" );
ok( 0 === job( 'advance', '7', 'build' )[0], 'with spec.md the job moves to build' );
ok( 0 !== job( 'advance', '7', 'gate' )[0], 'build -> gate is refused until build.md says STATUS: READY' );
put( 'build.md', "STATUS: READY\n" );
ok( 0 === job( 'advance', '7', 'gate' )[0], 'a READY build moves to gate' );
ok( 0 !== job( 'advance', '7', 'review' )[0], 'gate -> review is refused without a passing checks.md' );

// A real worktree HEAD, so the gate's sha is checked against it.
exec( 'git init -q ' . escapeshellarg( $wt ) . ' && git -C ' . escapeshellarg( $wt ) . ' -c user.name=t -c user.email=t@example.test commit -q --allow-empty -m one' );
$j = json_decode( file_get_contents( "$jobs/7/job.json" ), true ); $j['worktree'] = $wt; file_put_contents( "$jobs/7/job.json", json_encode( $j ) );
$sha = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $wt ) . ' rev-parse HEAD' ) );
put( 'round-1/checks.md', "PASS 0000000000000000000000000000000000000000\n" );
ok( 0 !== job( 'advance', '7', 'review' )[0], 'a gate that passed on another commit does not count' );
put( 'round-1/checks.md', "PASS $sha\n" );
ok( 0 === job( 'advance', '7', 'review' )[0], 'a gate that passed on the current HEAD moves to review' );
ok( 0 !== job( 'advance', '7', 'ready' )[0], 'review -> ready is refused without VERDICT: PASS' );
put( 'round-1/review.md', "VERDICT: CHANGES\n" );
ok( 0 !== job( 'advance', '7', 'ready' )[0], 'a review asking for changes is not ready' );
ok( 0 === job( 'advance', '7', 'build' )[0] && 2 === json_decode( file_get_contents( "$jobs/7/job.json" ), true )['round'], 'changes send it back to build and cost a round' );
ok( 0 !== job( 'ready', '7' )[0], '`ready` refuses a job that is not at the ready stage' );

job( 'advance', '7', 'gate' ); put( 'round-2/checks.md', "PASS $sha\n" ); job( 'advance', '7', 'review' ); job( 'advance', '7', 'build' );
job( 'advance', '7', 'gate' ); put( 'round-3/checks.md', "PASS $sha\n" ); job( 'advance', '7', 'review' );
list( $c, $o ) = job( 'advance', '7', 'build' );
ok( 0 !== $c && false !== strpos( $o, 'round limit' ), 'after three rounds another round is refused: escalate' );
put( 'round-3/review.md', "VERDICT: PASS\n" );
ok( 0 === job( 'advance', '7', 'ready' )[0], 'gate PASS on HEAD and review PASS reach ready' );
list( $c, $o ) = job( 'ready', '7' );
ok( 0 === $c && false !== strpos( $o, 'gh pr ready' ) && false === strpos( $o, 'pr merge' ), 'ready marks the pull request ready and comments; it never merges' );

job( 'new', '8' );
list( $c, $o ) = job( 'escalate', '8', 'needs a decision' );
ok( 0 === $c && false !== strpos( $o, 'factory:needs-human' ), 'escalate labels the issue and says why' );
$src = file_get_contents( __DIR__ . '/../tools/factory/job' ) . file_get_contents( __DIR__ . '/../tools/factory/gate' );
ok( false === strpos( $src, 'pr", "merge' ) && false === strpos( $src, 'pr merge' ) && false === strpos( $src, '--admin' ) && false === strpos( $src, 'cut-' ), 'neither script can merge, use --admin or cut' );

exec( 'rm -rf ' . escapeshellarg( $jobs ) );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
