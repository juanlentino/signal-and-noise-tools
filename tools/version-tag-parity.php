<?php
/**
 * Signal & Noise Tools — does main's version header have a tag on main?
 *
 * SHIPS NOTHING. This is CI tooling; `tools/` is excluded from the built
 * plugin and from phpcs. Required as a library by tests/version-tag-parity.php
 * and run with --check by .github/workflows/version-tag-parity.yml.
 *
 * Releasing here is THREE steps — squash-merge, annotated tag, draft release —
 * and only the first is visible in the repo's own history. The WordPress
 * updater reads TAGS, so a version that merged and was never tagged is code on
 * main that cannot reach the site. It happened on 2026-08-20 with v12.4.0, an
 * outside contributor's PR: they did everything a good contributor does, and
 * the two steps they omitted are the two that need push access to this repo.
 *
 * Usage:
 *   php tools/version-tag-parity.php --check    # real repo; exit 1 on a gap
 *
 * @since 2026-08-20
 */

/**
 * The `Version:` value from a WordPress plugin header.
 *
 * Scoped to the plugin-header docblock form (` * Version: x.y.z`) inside the
 * first 8KB, which is all WordPress itself reads. A looser match would find the
 * word "Version:" in any prose file and report a version for something that has
 * none — and a stray match here is worse than no match, because it would make
 * the gate assert against a number nobody shipped.
 *
 * @return string Empty when the file is unreadable or carries no header.
 */
function sn_vtp_read_header( $file ) {
	if ( ! is_file( $file ) || ! is_readable( $file ) ) {
		return '';
	}
	$head = (string) file_get_contents( $file, false, null, 0, 8192 );
	if ( ! preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)[ \t]*$/mi', $head, $m ) ) {
		return '';
	}
	return trim( $m[1] );
}

/**
 * Is this version tagged, and is the tag on main?
 *
 * Both lists are passed in rather than read here so the decision logic is
 * testable without a repo — see tests/version-tag-parity.php, which drives the
 * off-main case that is awkward to manufacture live.
 *
 * @param string   $version      Raw header value; a leading `v` and padding are tolerated.
 * @param string[] $tags_on_main Tags whose commit is an ancestor of main.
 * @param string[] $all_tags     Every tag known to the clone.
 * @return array{ok:bool,code:string,message:string}
 */
function sn_vtp_verdict( $version, array $tags_on_main, array $all_tags ) {
	$version = ltrim( trim( (string) $version ), 'vV' );

	// An unreadable header is a FAILURE, never a pass. A gate that cannot see
	// its subject has not cleared it — it has declined to measure.
	if ( '' === $version || ! preg_match( '/^\d+(\.\d+)+$/', $version ) ) {
		return array(
			'ok'      => false,
			'code'    => 'no-version',
			'message' => 'could not read a version from the plugin header (got ' . var_export( $version, true ) . ') — the gate cannot clear what it cannot see',
		);
	}

	$tag = 'v' . $version;

	if ( in_array( $tag, $tags_on_main, true ) ) {
		return array( 'ok' => true, 'code' => 'tagged', 'message' => $tag . ' exists and is an ancestor of main' );
	}

	// The tag exists but sits off main. This is the documented linked-worktree
	// trap: a tag cut from a linked worktree lands on the PRE-SQUASH commit, so
	// it looks right in `git tag -l` and is invisible to anyone reading a log
	// subject. Different diagnosis, different fix — say which one this is.
	if ( in_array( $tag, $all_tags, true ) ) {
		return array(
			'ok'      => false,
			'code'    => 'tag-off-main',
			'message' => $tag . ' EXISTS but is not an ancestor of main — likely cut from a linked worktree onto the pre-squash commit. Delete and re-cut it from a real checkout of main.',
		);
	}

	return array(
		'ok'      => false,
		'code'    => 'missing-tag',
		'message' => 'main declares version ' . $version . ' and NO ' . $tag . ' tag exists. The WordPress updater reads TAGS, so this release cannot reach the site.',
	);
}

// ── CLI ────────────────────────────────────────────────────────────────────
if ( PHP_SAPI === 'cli' && isset( $argv[1] ) && '--check' === $argv[1] ) {
	$root = dirname( __DIR__ );
	$ver  = sn_vtp_read_header( $root . '/signal-and-noise-tools.php' );

	// --merged takes the ancestry question to git rather than comparing log
	// subjects, which match across a squash and have lied here before.
	exec( 'git -C ' . escapeshellarg( $root ) . ' tag --merged origin/main 2>/dev/null', $on_main );
	exec( 'git -C ' . escapeshellarg( $root ) . ' tag -l 2>/dev/null', $all );

	$v = sn_vtp_verdict( $ver, array_map( 'trim', $on_main ), array_map( 'trim', $all ) );

	// A clone with no tags at all means the fetch did not bring them; that is a
	// broken RUN, not a clean repo, and must not read as a pass.
	if ( ! $all ) {
		echo "FAIL: no tags in this clone — fetch-depth/tags are wrong, so this run measured nothing.\n";
		exit( 1 );
	}

	echo ( $v['ok'] ? 'OK: ' : 'FAIL: ' ) . $v['message'] . "\n";
	echo 'version=' . $ver . ' code=' . $v['code'] . ' tags_on_main=' . count( $on_main ) . "\n";
	exit( $v['ok'] ? 0 : 1 );
}
