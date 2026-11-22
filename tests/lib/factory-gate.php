<?php
/**
 * Part of tests/factory-job.php: tools/factory/gate against the fixture
 * repository. One branch passes; every other branch is that same change plus
 * one thing an audit slipped past the gate, and must fail on the step named.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

walk( '71', 'admin', 'gate' ); $g = state( '71' )['worktree'];
@mkdir( "$jobs/.wp-core/wp-includes/html-api", 0777, true ); // the core fetch is not what is under test
$bullet = function ( $under ) use ( $g ) { file_put_contents( "$g/CHANGELOG.md", str_replace( "## [$under]\n", "## [$under]\n\n- **A new bullet.**\n", file_get_contents( "$g/CHANGELOG.md" ) ) ); };
$good   = function () use ( $g, $bullet ) { file_put_contents( "$g/tests/new.php", "<?php\necho \"1 passed, 0 failed\";\n" ); file_put_contents( "$g/inc/a.php", "<?php\n\$a = 1;\n" ); $bullet( 'Unreleased' ); };
/** Run the gate. Returns [exit code, checks.md, checks.log]. */
$run = function () use ( $jobs, $tool ) {
	$code = sh( 'FACTORY_DRY=1 FACTORY_ROOT=' . escapeshellarg( $GLOBALS['root'] ) . ' FACTORY_JOBS=' . escapeshellarg( $jobs ) . ' bash ' . escapeshellarg( "$tool/gate" ) . ' 71' )[0];
	return array( $code, (string) @file_get_contents( "$jobs/71/round-1/checks.md" ), (string) @file_get_contents( "$jobs/71/round-1/checks.log" ) );
};
/** Reset the branch to the base, apply a change, commit it, run the gate. */
$gate = function ( callable $change ) use ( $g, $run, $BASE ) {
	git( $g, "reset -q --hard $BASE" ); git( $g, 'clean -qfd' ); $change(); git( $g, 'add -A' ); git( $g, 'commit -q -m change' );
	return $run();
};
$fails = function ( $md ) { preg_match_all( '/^- FAIL: (.*)$/m', $md, $m ); return $m[1]; };

list( $c, $md, $log ) = $gate( $good );
ok( 0 === $c && 0 === strpos( $md, 'PASS ' . head_of( '71' ) ) && array() === $fails( $md ), 'a change with a test, a lint-clean file and an Unreleased bullet passes the gate (control)' );
ok( false !== strpos( $log, 'BASE-RUNNER' ), 'the sweep ran, from the repository root and with no stray argument' );

list( $c, $md, $log ) = $gate( function () use ( $g, $good ) { $good(); file_put_contents( "$g/tests/run.sh", "#!/usr/bin/env bash\necho BRANCH-RUNNER\nexit 0\n" ); } );
ok( 0 !== $c && array( 'tests/run.sh is unchanged and no test is deleted or renamed away' ) === $fails( $md ), 'a branch that rewrites tests/run.sh fails, on that step alone' );
ok( false !== strpos( $log, 'BASE-RUNNER' ) && false === strpos( $log, 'BRANCH-RUNNER' ), 'the runner that ran is the base branch\'s copy, not the branch\'s' );

list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); unlink( "$g/tests/old.php" ); } );
ok( 0 !== $c && array( 'tests/run.sh is unchanged and no test is deleted or renamed away' ) === $fails( $md ), 'deleting a test fails the gate' );
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); rename( "$g/tests/old.php", "$g/tests/renamed.php" ); } );
ok( 0 !== $c && in_array( 'tests/run.sh is unchanged and no test is deleted or renamed away', $fails( $md ), true ), 'renaming a test away fails the gate' );
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); unlink( "$g/tests/new.php" ); unlink( "$g/tests/old.php" ); } );
ok( 0 !== $c && in_array( 'the change adds or modifies a file under tests/', $fails( $md ), true ), 'a deletion under tests/ is not a touched test' );

list( $c, $md ) = $gate( function () use ( $good, $bullet, $g, $BASE ) { $good(); git( $g, "checkout -q $BASE -- CHANGELOG.md" ); $bullet( '1.0.0' ); } );
ok( 0 !== $c && array( 'CHANGELOG has a new Unreleased bullet' ) === $fails( $md ), 'a bullet added under an old release is not an Unreleased bullet' );

list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); file_put_contents( "$g/inc/caf\xc3\xa9.php", "<?php if (\n" ); } );
ok( 0 !== $c && array( 'php -l on every changed PHP file' ) === $fails( $md ), 'a parse error in a PHP file with a non-ASCII name is linted and fails' );

$step = 'CHANGELOG has a new Unreleased bullet';
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); file_put_contents( "$g/CHANGELOG.md", "\n## [Unreleased]\n", FILE_APPEND ); } );
ok( 0 !== $c && array( $step ) === $fails( $md ), 'a second Unreleased heading fails, even beside a real new bullet' );
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); file_put_contents( "$g/CHANGELOG.md", "# Changelog\n\n## [Unreleased]\n\n- an old bullet\n\n## [1.0.0]\n" ); } );
ok( 0 !== $c && array( $step ) === $fails( $md ), 'an old bullet that ends up inside Unreleased because a heading moved is not an added bullet' );

$lint = 'php -l on every changed PHP file';
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); file_put_contents( "$g/-d.php", "<?php\n" ); file_put_contents( "$g/inc/zbroken.php", "<?php if (\n" ); } );
ok( 0 !== $c && array( $lint ) === $fails( $md ), 'a file named -d.php does not stop the lint: a broken file after it still fails' );
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); file_put_contents( "$g/-d.php", "<?php if (\n" ); } );
ok( 0 !== $c && array( $lint ) === $fails( $md ), 'a broken file named -d.php is linted as a file, not read as an option' );
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); file_put_contents( "$g/inc/b.PHP", "<?php if (\n" ); } );
ok( 0 !== $c && array( $lint ) === $fails( $md ), 'a .PHP file is a PHP file' );

list( $c, $md ) = $gate( function () use ( $g, $good, $EM ) { $good(); file_put_contents( "$g/inc/quiet.txt", "a $EM b\n" ); } );
ok( 0 !== $c && array( 'no em dash in added lines' ) === $fails( $md ), 'a -diff attribute does not hide an em dash from the gate' );
list( $c, $md ) = $gate( function () use ( $g, $good ) { $good(); @mkdir( "$g/inc/deep" ); file_put_contents( "$g/inc/deep/.gitattributes", "*.php -diff\n" ); } );
ok( 0 !== $c && array( 'the change does not touch .gitattributes' ) === $fails( $md ), 'a change to any .gitattributes fails the gate' );

// A local branch named origin/main must not become the base the branch is judged against.
git( $g, "reset -q --hard $BASE" ); file_put_contents( "$g/tests/run.sh", "#!/usr/bin/env bash\necho BRANCH-RUNNER\nexit 0\n" ); git( $g, 'add -A' ); git( $g, 'commit -q -m runner' );
git( $g, 'branch origin/main HEAD' ); $good(); git( $g, 'add -A' ); git( $g, 'commit -q -m change' );
list( $c, $md, $log ) = $run(); git( $g, 'branch -D origin/main' );
ok( 0 !== $c && array( 'tests/run.sh is unchanged and no test is deleted or renamed away' ) === $fails( $md ) && false === strpos( $log, 'BRANCH-RUNNER' ), 'the base is refs/remotes/origin/main even when a local branch is named origin/main' );

foreach ( array( '+ a line that starts with a plus', '++ b/inc/a.php and looks like a diff header' ) as $line ) {
	list( $c, $md ) = $gate( function () use ( $g, $good, $line, $EM ) { $good(); file_put_contents( "$g/inc/notes.txt", "$line $EM here\n" ); } );
	ok( 0 !== $c && array( 'no em dash in added lines' ) === $fails( $md ), "an em dash on an added line is caught: \"$line\"" );
}
// The protections the gate already had still hold.
list( $c, $md ) = $gate( function () {} );
ok( 0 !== $c && 0 === strpos( $md, 'FAIL ' ), 'an empty change still fails the gate' );
$gate( $good ); file_put_contents( "$g/stray.txt", "not committed\n" );
list( $c, $md ) = $run(); unlink( "$g/stray.txt" );
ok( 0 !== $c && array( 'the work is committed (clean tree)' ) === $fails( $md ), 'uncommitted work still fails the gate' );
git( $root, 'update-ref -d refs/remotes/origin/main' );
list( $c, $md ) = $run(); git( $root, 'fetch -q origin' );
ok( 0 !== $c && 0 === strpos( $md, 'FAIL ' ) && false !== strpos( $md, 'cannot diff against origin/main' ), 'a missing base ref still fails the gate, and says so' );
ok( 0 === $run()[0], 'with the base ref fetched again the same commit passes (control)' );
$src = file_get_contents( "$tool/gate" );
ok( false === strpos( $src, $EM ), 'the gate spells its em dash in bytes, so it passes its own check' );
