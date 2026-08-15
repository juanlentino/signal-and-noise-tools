<?php
/**
 * Tests: every sn_settings subtree survives a save (v11.10.0).
 *
 * `sn_settings_save()` builds a fresh array and ends with a WHOLE-OPTION
 * replace: update_option( SN_SETTINGS_OPTION, $sanitized ). Anything not
 * re-included is silently wiped the next time any tab is saved. No error, no
 * notice, no failing test — the setting simply ceases to exist and its tab
 * renders the default as though it had never been configured.
 *
 * That has bitten this codebase four times. Each fix added another hand-written
 * preservation block, which is a list that only stays correct while someone
 * remembers to extend it. This test is the thing that remembers.
 *
 * It was carried as a prompt instruction for the Claude Review workflow, and
 * then briefly as custom instructions on the security audit. Neither enforced
 * it: Claude Review was disabled 2026-08-15 after producing zero comments in
 * its entire history, and a control PR adding an unpreserved subtree produced
 * `total_original_findings: 0` from the security audit — that tool scopes to
 * security vulnerabilities, and this is a data-integrity bug. A deterministic
 * property deserves a deterministic check.
 *
 * Run: php tests/settings-subtree-preservation.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$root = dirname( __DIR__ );

/**
 * Source with comments removed.
 *
 * Regexing raw source reads DOCBLOCKS as code. settings.php:8 documents the
 * dot-path convention with the example `sn_setting('cat.field')`, and a naive
 * scan reports a phantom `cat` subtree that would fail this test forever.
 * Tokenising is the fix — PHP's own lexer knows what is a comment and what is
 * a string.
 *
 * @param string $file Path to a PHP file.
 * @return string Code with T_COMMENT / T_DOC_COMMENT stripped.
 */
function snt_code_only( $file ) {
	$out = '';
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				$out .= "\n";
				continue;
			}
			$out .= $token[1];
			continue;
		}
		$out .= $token;
	}
	return $out;
}

/**
 * Subtrees that survive a save: written into or copied onto $sanitized inside
 * sn_settings_save(). Read from the function body only, so a mention elsewhere
 * in settings.php cannot be mistaken for preservation.
 */
function snt_preserved_subtrees( $settings_php ) {
	$src = snt_code_only( $settings_php );
	// The function body: from its signature to the first close-brace in column 0.
	if ( ! preg_match( '/^function sn_settings_save\s*\(.*?^\}/ms', $src, $m ) ) {
		return array();
	}
	preg_match_all( "/\\\$sanitized\\[\s*'([a-z0-9_]+)'\s*\\]/", $m[0], $keys );
	return array_values( array_unique( $keys[1] ) );
}

/**
 * Subtrees any shipped code actually reads or writes, via the two documented
 * accessors. Literal paths only — a dynamic $path cannot be resolved statically
 * and is deliberately not guessed at.
 */
function snt_used_subtrees( $dir, $main_file ) {
	$files = glob( $dir . '/*.php' );
	$files = array_merge( $files, glob( $dir . '/*/*.php' ), array( $main_file ) );
	$found = array();
	foreach ( $files as $file ) {
		$src = snt_code_only( $file );
		preg_match_all( "/sn_setting(?:_update)?\s*\(\s*'([a-z0-9_]+)\./", $src, $m );
		foreach ( $m[1] as $key ) {
			$found[ $key ] = true;
		}
	}
	return array_keys( $found );
}

$preserved = snt_preserved_subtrees( $root . '/inc/settings.php' );
$used      = snt_used_subtrees( $root . '/inc', $root . '/signal-and-noise-tools.php' );

echo "Group: the extractors are not vacuous\n";
// Without these, a broken regex yields two empty sets, the comparison below is
// trivially satisfied, and the suite reports green while checking nothing.
ok( count( $preserved ) >= 5, 'found preserved subtrees in sn_settings_save() (' . count( $preserved ) . ')' );
ok( count( $used ) >= 5, 'found used subtrees across inc/ (' . count( $used ) . ')' );
ok( in_array( 'identity', $preserved, true ), "'identity' is recognised as preserved" );
ok( in_array( 'audit', $preserved, true ), "'audit' is recognised as preserved (the v4.5.2 fix)" );
ok( in_array( 'audit', $used, true ), "'audit' is recognised as used" );
// settings.php:8 documents the convention with `sn_setting('cat.field')`. A
// scan that reads comments reports a phantom 'cat' subtree and fails forever.
ok( ! in_array( 'cat', $used, true ), "the docblock example `sn_setting('cat.field')` is not mistaken for a subtree" );

echo "\nGroup: the comparison itself catches a missing subtree\n";
// Pins the logic, not just today's data: if this ever stops failing, the real
// assertion below has stopped meaning anything.
$synthetic_missing = array_values( array_diff( array( 'a', 'b', 'c' ), array( 'a', 'b' ) ) );
ok( array( 'c' ) === $synthetic_missing, 'a used-but-unpreserved subtree is detected' );
ok( array() === array_values( array_diff( array( 'a' ), array( 'a', 'b' ) ) ), 'an unused-but-preserved subtree is not an error' );

echo "\nGroup: every used subtree survives a save\n";
$missing = array_values( array_diff( $used, $preserved ) );
if ( $missing ) {
	echo "  preserved: " . implode( ', ', $preserved ) . "\n";
	echo "  used:      " . implode( ', ', $used ) . "\n";
}
ok(
	array() === $missing,
	$missing
		? 'SUBTREE WOULD BE WIPED ON SAVE: ' . implode( ', ', $missing ) . ' — re-include it in sn_settings_save() (see the login.slug and audit blocks for the pattern)'
		: 'all ' . count( $used ) . ' used subtrees are re-included in sn_settings_save()'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
