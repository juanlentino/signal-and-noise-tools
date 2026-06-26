<?php
/**
 * Contract guard for the sn/scheduled block metadata (blocks/scheduled/block.json).
 *
 * The editor side of the scheduled-content subsystem is buildless: there is no
 * bundler, no .asset.php sidecar, and editorScript MUST therefore be a
 * MANUALLY-registered script HANDLE string, never a file: path (a file: path
 * loads with empty deps and throws 'wp is undefined' at editor load). This
 * fixture pins the load-bearing shape of block.json so a regression in the
 * metadata is caught in CI, not in a broken editor:
 *
 *   - valid JSON, apiVersion 3, name signal-noise/scheduled
 *   - the three attributes from / until / scheduleId exist and are type string
 *   - supports.html is false (no raw-HTML edit mode for a gated fragment)
 *   - editorScript is a non-empty handle string that does NOT start with file:
 *
 * The render path (open -> verbatim, closed -> '') is covered separately by
 * tests/schedule-block.php; this fixture is metadata-only.
 *
 * Run: php tests/schedule-block-meta.php
 *
 * @since plugin v6.40.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Allow only CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}

echo "schedule-block-meta: sn/scheduled block.json contract\n\n";

$json_path = __DIR__ . '/../blocks/scheduled/block.json';
ok( is_file( $json_path ), 'meta: blocks/scheduled/block.json exists' );

$raw  = is_file( $json_path ) ? (string) file_get_contents( $json_path ) : '';
$meta = json_decode( $raw, true );
ok( JSON_ERROR_NONE === json_last_error() && is_array( $meta ), 'meta: block.json is valid JSON (decodes to an array)' );

// Guard against a null decode so every assertion below reads from an array.
if ( ! is_array( $meta ) ) {
	$meta = array();
}

ok( isset( $meta['apiVersion'] ) && 3 === $meta['apiVersion'], 'meta: apiVersion === 3' );
ok( isset( $meta['name'] ) && 'signal-noise/scheduled' === $meta['name'], "meta: name === 'signal-noise/scheduled'" );

// The three window/identity attributes, each type string.
$attrs = isset( $meta['attributes'] ) && is_array( $meta['attributes'] ) ? $meta['attributes'] : array();
foreach ( array( 'from', 'until', 'scheduleId' ) as $attr ) {
	$present = isset( $attrs[ $attr ] ) && is_array( $attrs[ $attr ] );
	$is_str  = $present && isset( $attrs[ $attr ]['type'] ) && 'string' === $attrs[ $attr ]['type'];
	ok( $present && $is_str, "meta: attribute '$attr' exists with type string" );
}

// supports.html must be explicitly false (no raw-HTML edit mode).
$supports = isset( $meta['supports'] ) && is_array( $meta['supports'] ) ? $meta['supports'] : array();
ok( array_key_exists( 'html', $supports ) && false === $supports['html'], 'meta: supports.html === false' );

// editorScript must be a non-empty handle string, NOT a file: path. A file:
// editorScript in a no-build repo loads with empty deps (no .asset.php) and
// throws 'wp is undefined'.
$editor_script = isset( $meta['editorScript'] ) ? $meta['editorScript'] : null;
ok( is_string( $editor_script ) && '' !== $editor_script, 'meta: editorScript is a non-empty string handle' );
ok( is_string( $editor_script ) && 0 !== strpos( $editor_script, 'file:' ), "meta: editorScript is a registered handle, NOT a file: path" );

// editorStyle wires the editor-only badge + gated-region CSS. Unlike a script, a
// CSS file: reference needs no .asset.php sidecar, so it loads buildless and WP
// auto-enqueues it in the block editor only (never on the front end). Guard that
// the wiring exists so the polish cannot silently regress to an unstyled badge.
$editor_style = isset( $meta['editorStyle'] ) ? $meta['editorStyle'] : null;
ok( is_string( $editor_style ) && '' !== $editor_style, 'meta: editorStyle is a non-empty string' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
