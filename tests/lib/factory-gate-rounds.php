<?php
/**
 * Part of tests/factory-job.php, after factory-gate.php (same job, same
 * closures): the gate cannot be rerun until it passes. It runs only at stage
 * gate, a FAIL stands until `advance build` spends the round, and neither an
 * em dash spelled as an HTML entity nor a replaced git object gets past it.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$dash = 'no em dash in added lines'; $amp = '&'; // entities are assembled here so this file never holds one

// 2. An em dash spelled as an HTML entity is still an em dash. The en dash is not banned.
foreach ( array( 'mdash;', 'MDash;', '#8212;', '#08212;', '#x2014;', '#X2014;' ) as $entity ) {
	list( $c, $md ) = $gate( function () use ( $g, $good, $amp, $entity ) { $good(); file_put_contents( "$g/inc/notes.txt", "a $amp$entity b\n" ); } );
	ok( 0 !== $c && array( $dash ) === $fails( $md ), "the entity $entity on an added line fails the gate" );
}
list( $c, $md ) = $gate( function () use ( $g, $good, $amp ) { $good(); file_put_contents( "$g/inc/notes.txt", "1\xe2\x80\x932 and 3{$amp}ndash;4 and {$amp}#8211;\n" ); } );
ok( 0 === $c, 'an en dash, as a character or an entity, passes' );

// 3. A replaced object does not change what the branch is compared against.
$base = trim( git( $root, "rev-parse $BASE" )[1] );
git( $g, "reset -q --hard $BASE" ); git( $g, 'rm -q tests/old.php' ); git( $g, 'commit -q -m drop' ); $drop = head_of( '71' );
$good(); git( $g, 'add -A' ); git( $g, 'commit -q -m change' ); $fresh();
git( $root, "replace $base $drop" ); // the base now reads as a commit that never had the test
list( $c, $md ) = $run(); git( $root, "replace -d $base" );
ok( 0 !== $c && array( 'tests/run.sh is unchanged and no test is deleted or renamed away' ) === $fails( $md ), 'a git replace of the base commit does not hide a deleted test' );

// 1. A FAIL stands. The only way on is `advance build`, which costs the round.
list( $c, $md ) = $gate( $good );
ok( 0 === $c && 0 === $run()[0], 'a gate that passed may run again on the same commit' );
$next = commit_in( '71', 'one more commit' );
list( $c, $md ) = $run();
ok( 0 === $c && 0 === strpos( $md, "PASS $next" ), 'a PASS for an older commit is replaced by a fresh run on the new HEAD' );
list( $c, $md ) = $gate( function () {} );
ok( 0 !== $c && 0 === strpos( $md, 'FAIL ' ), 'an empty change fails (control)' );
$good(); git( $g, 'add -A' ); git( $g, 'commit -q -m fixed' );
list( $c, $md, $log, $out ) = $run();
ok( 2 === $c && 0 === strpos( $md, 'FAIL ' ) && false !== strpos( $out, 'advance 71 build' ), 'after a FAIL the gate refuses to run again in the same round, even on a fixed commit' );
ok( 0 !== job( 'advance', '71', 'review' )[0], 'and that FAIL is no way to review' );
job( 'advance', '71', 'build' );
list( $c, $md, $log, $out ) = $run();
ok( 2 === $c && 2 === state( '71' )['round'] && false !== strpos( $out, 'stage' ) && '' === $md, 'the gate refuses at stage build: the builder has not said READY for this round' );
putj( '71', 'round-2/build.md', "STATUS: READY\n" ); job( 'advance', '71', 'gate' );
list( $c, $md ) = $run();
ok( 0 === $c && 0 === strpos( $md, 'PASS ' . head_of( '71' ) ), 'round 2, at stage gate, runs and passes' );
job( 'advance', '71', 'review' ); $late = commit_in( '71', 'after the gate' );
list( $c, $md, $log, $out ) = $run();
ok( 2 === $c && false !== strpos( $out, 'stage' ) && false === strpos( $md, $late ), 'at stage review the gate refuses: evidence for a later commit costs a round' );
