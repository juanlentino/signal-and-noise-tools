<?php
/**
 * Shared by tests/factory-job.php and its parts (tests/lib/factory-*.php):
 * a temp jobs folder, a fixture repository with a bare origin, and two ways
 * to run tools/factory/job. `job()` is the dry seam, pointed at the fixture
 * with FACTORY_ROOT. A live run ignores FACTORY_ROOT and takes its root from
 * where the script sits, so `live()` runs a COPY of tools/factory placed
 * inside the fixture, against a stand-in `gh` that is first on PATH. Nothing
 * here touches the real checkout, the real jobs folder or GitHub: git is held
 * to local paths (GIT_ALLOW_PROTOCOL=file) for every child process.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

putenv( 'GIT_ALLOW_PROTOCOL=file' );
$tmp    = realpath( sys_get_temp_dir() ) . '/factory-test-' . getmypid();
$jobs   = "$tmp/jobs";
$root   = "$tmp/root";
$origin = "$tmp/origin.git";
$tool   = realpath( __DIR__ . '/../../tools/factory' );
$copy   = "$root/tools/factory";
$BASE   = 'refs/remotes/origin/main'; // never the shorthand: a branch could be named origin/main
$GITHUB = 'https://github.com/juanlentino/signal-and-noise-tools.git';
$EM     = "\xe2\x80\x94";

function sh( $cmd ) { exec( $cmd . ' 2>&1', $out, $code ); return array( $code, implode( "\n", $out ) ); }
/** git, in the fixture ONLY. An empty or outside path would run in the caller's checkout (these tests reset and clean), so it is refused. */
function git( $dir, $args ) {
	global $tmp;
	if ( 0 !== strpos( (string) realpath( $dir ) . '/', "$tmp/" ) ) { return array( 1, 'refused: not inside the fixture' ); }
	return sh( 'git -C ' . escapeshellarg( $dir ) . ' -c user.name=t -c user.email=t@example.test -c commit.gpgsign=false ' . $args );
}
function wt_of( $id ) { global $root; return "$root/.claude/worktrees/factory-$id"; } // from the id, never from job.json
function run_job( array $env, array $a, $script = null ) {
	global $jobs, $tool;
	$e = '';
	foreach ( $env + array( 'FACTORY_JOBS' => $jobs, 'FACTORY_OWNER_OK' => $GLOBALS['owner_ok'] ?? '0' ) as $k => $v ) { $e .= $k . '=' . escapeshellarg( $v ) . ' '; }
	return sh( $e . 'python3 ' . escapeshellarg( $script ?? "$tool/job" ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $a ) ) );
}
function job( ...$a ) { global $root; return run_job( array( 'FACTORY_DRY' => '1', 'FACTORY_ROOT' => $root ), $a ); }
function live_env() {
	global $tmp;
	return array( 'PATH' => "$tmp/bin:" . getenv( 'PATH' ), 'GH_STUB_DIR' => "$tmp/bin", 'GH_CONFIG_DIR' => "$tmp/ghcfg", 'GH_TOKEN' => '', 'GITHUB_TOKEN' => '', 'GH_ENTERPRISE_TOKEN' => '', 'GH_HOST' => '', 'FACTORY_DRY' => '0' );
}
function live( ...$a ) { global $copy; return run_job( live_env(), $a, "$copy/job" ); }
function stub( $name, $text ) { global $tmp; file_put_contents( "$tmp/bin/$name", is_array( $text ) ? json_encode( $text ) : $text ); }
function issue( array $over = array() ) { stub( 'issue.json', $over + array( 'title' => 't', 'body' => 'b', 'state' => 'OPEN', 'labels' => array( array( 'name' => 'factory' ) ), 'author' => array( 'login' => 'stranger' ), 'comments' => array() ) ); }
function calls() { global $tmp; return (string) @file_get_contents( "$tmp/bin/calls.log" ); }

function putj( $id, $rel, $text ) { global $jobs; @mkdir( dirname( "$jobs/$id/$rel" ), 0777, true ); file_put_contents( "$jobs/$id/$rel", $text ); }
function state( $id ) { global $jobs; return json_decode( (string) @file_get_contents( "$jobs/$id/job.json" ), true ); }
function set_state( $id, array $over ) { global $jobs; file_put_contents( "$jobs/$id/job.json", json_encode( $over + state( $id ) ) ); }
function head_of( $id ) { return trim( git( wt_of( $id ), 'rev-parse HEAD' )[1] ); }
function commit_in( $id, $msg ) { git( wt_of( $id ), 'commit -q --allow-empty -m ' . escapeshellarg( $msg ) ); return head_of( $id ); }
/** Walk a fresh job to a stage, with a real worktree in the fixture. */
function walk( $id, $surfaces = 'admin', $to = 'ready', $job = 'job' ) {
	$job( 'new', $id ); putj( $id, 'intake.md', "DECISION: ACCEPT\nRISK: low\nSURFACES: $surfaces\n" ); $job( 'advance', $id, 'spec' );
	putj( $id, 'spec.md', "1. a criterion\n" ); $job( 'worktree', $id ); $job( 'advance', $id, 'build' );
	if ( 'build' === $to ) { return; }
	putj( $id, 'round-1/build.md', "STATUS: READY\n" ); $job( 'advance', $id, 'gate' );
	if ( 'gate' === $to ) { return; }
	$sha = head_of( $id ); putj( $id, 'round-1/checks.md', "PASS $sha\n" ); $job( 'advance', $id, 'review' );
	if ( 'review' === $to ) { return; }
	putj( $id, 'round-1/review.md', "VERDICT: PASS $sha\n" . ( preg_match( '/security|data/', $surfaces ) ? "SECURITY: PASS $sha\n" : '' ) );
	return $job( 'advance', $id, 'ready' );
}
function rrmdir( $dir ) {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) { @unlink( $dir ); return; }
	foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $f ) { rrmdir( "$dir/$f" ); }
	@rmdir( $dir );
}

// The fixture: a bare origin with `main`, and a clone that plays the repository root.
@mkdir( "$tmp/bin", 0777, true ); @mkdir( "$tmp/ghcfg" ); @mkdir( "$root/tests", 0777, true ); @mkdir( "$root/inc" );
file_put_contents( "$root/CHANGELOG.md", "# Changelog\n\n## [Unreleased]\n\n## [1.0.0]\n\n- an old bullet\n" );
// The fixture's runner does what the real one does: it finds the repository from $0 and takes a suite name as $1.
file_put_contents( "$root/tests/run.sh", "#!/usr/bin/env bash\ncd \"\$(dirname \"\$0\")/..\" || exit 2\n[ -z \"\${1:-}\" ] || { echo \"unexpected argument\"; exit 2; }\n[ -f CHANGELOG.md ] || { echo \"wrong folder\"; exit 2; }\necho BASE-RUNNER\nexit 0\n" );
file_put_contents( "$root/tests/old.php", "<?php\necho \"1 passed, 0 failed\";\n" );
file_put_contents( "$root/inc/keep.php", "<?php\n" );
file_put_contents( "$root/inc/quiet.txt", "plain\n" ); file_put_contents( "$root/.gitattributes", "inc/quiet.txt -diff\n" ); // git shows this file's changes as "Binary files differ"
sh( 'git -c init.defaultBranch=main init -q --bare ' . escapeshellarg( $origin ) );
sh( 'git -c init.defaultBranch=main init -q ' . escapeshellarg( $root ) );
git( $root, 'add -A' ); git( $root, 'commit -q -m base' ); git( $root, 'remote add origin ' . escapeshellarg( $origin ) ); git( $root, 'push -q origin main' );
// The copy a live run uses: its root is the fixture, because that is where it sits.
@mkdir( "$copy/jobkit", 0777, true );
foreach ( array_merge( array( 'job', 'gate', 'config.json' ), array_map( function ( $f ) { return 'jobkit/' . basename( $f ); }, glob( "$tool/jobkit/*.py" ) ) ) as $f ) { copy( "$tool/$f", "$copy/$f" ); }
// The stand-in gh: answers reads from files beside it, logs every call, never contacts GitHub.
file_put_contents( "$tmp/bin/gh", "#!/bin/sh\necho \"gh \$*\" >> \"\$GH_STUB_DIR/calls.log\"\ncase \"\$1 \$2\" in\n\t\"issue view\") cat \"\$GH_STUB_DIR/issue.json\";;\n\t\"pr view\") cat \"\$GH_STUB_DIR/pr.json\";;\n\t\"pr checks\") exit \"\$(cat \"\$GH_STUB_DIR/checks.rc\")\";;\nesac\nexit 0\n" );
chmod( "$tmp/bin/gh", 0755 );
$e = ''; foreach ( live_env() as $k => $v ) { $e .= $k . '=' . escapeshellarg( $v ) . ' '; }
$live_ok = "$tmp/bin/gh" === trim( sh( $e . 'sh -c ' . escapeshellarg( 'command -v gh' ) )[1] );
