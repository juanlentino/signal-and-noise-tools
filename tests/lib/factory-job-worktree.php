<?php
/**
 * Part of tests/factory-job.php: `job worktree` in the fixture repository.
 * A deleted worktree folder comes back on its branch, and a path that is not
 * a git worktree of the job's branch is refused, so the HEAD `job` reads can
 * never be the main checkout's.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

walk( '61', 'admin', 'build' ); $wt61 = "$root/.claude/worktrees/factory-61";
ok( $wt61 === state( '61' )['worktree'] && 'factory/61' === trim( git( $wt61, 'symbolic-ref --short HEAD' )[1] ), 'worktree adds a worktree on the job branch, under the root it was given (control)' );
$kept = commit_in( '61', 'work on the branch' );
rrmdir( $wt61 );
list( $c, $o ) = job( 'worktree', '61' );
ok( 0 === $c && is_dir( $wt61 ) && $kept === head_of( '61' ), 'the folder is gone but the branch exists: the worktree comes back with the branch\'s commits' );
ok( 0 === job( 'worktree', '61' )[0], 'running it again on a healthy worktree is fine' );
git( $wt61, 'checkout -q -b elsewhere' );
list( $c, $o ) = job( 'worktree', '61' );
ok( 0 !== $c && false !== strpos( $o, 'not a git worktree' ), 'a worktree that is on another branch is refused' );
putj( '61', 'round-1/build.md', "STATUS: READY\n" ); job( 'advance', '61', 'gate' );
putj( '61', 'round-1/checks.md', 'PASS ' . head_of( '61' ) . "\n" );
list( $c, $o ) = job( 'advance', '61', 'review' );
ok( 0 !== $c && false !== strpos( $o, 'no worktree HEAD' ), 'a gate that passed on another branch\'s HEAD is not this job\'s evidence' );

// A plain folder at the worktree's path sits inside the main checkout: git there answers for the main checkout.
job( 'new', '62' ); $wt62 = "$root/.claude/worktrees/factory-62"; mkdir( $wt62, 0777, true );
list( $c, $o ) = job( 'worktree', '62' );
ok( 0 !== $c && false !== strpos( $o, 'not a git worktree' ), 'a plain folder at the worktree path is refused' );
$main = trim( git( $root, 'rev-parse HEAD' )[1] );
set_state( '62', array( 'worktree' => $wt62, 'stage' => 'gate' ) ); putj( '62', 'round-1/checks.md', "PASS $main\n" );
list( $c, $o ) = job( 'advance', '62', 'review' );
ok( 0 !== $c && false !== strpos( $o, 'no worktree HEAD' ), 'the main checkout\'s HEAD is never read as the job\'s HEAD' );
// Even when the main checkout sits on the job's own branch name, a plain folder is not the job's worktree.
job( 'new', '63' ); $wt63 = "$root/.claude/worktrees/factory-63"; mkdir( $wt63, 0777, true ); git( $root, 'checkout -q -b factory/63' );
set_state( '63', array( 'worktree' => $wt63, 'stage' => 'gate' ) ); putj( '63', 'round-1/checks.md', "PASS $main\n" );
list( $c, $o ) = job( 'advance', '63', 'review' ); git( $root, 'checkout -q main' );
ok( 0 !== $c && false !== strpos( $o, 'no worktree HEAD' ), 'the folder must be the top of its own worktree, whatever branch the main checkout is on' );
