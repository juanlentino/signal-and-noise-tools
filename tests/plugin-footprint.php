<?php
/**
 * Plugin-footprint diagnostic + legacy-file janitor — fixture tests.
 *
 * Covers inc/plugin-footprint.php:
 *   - sn_footprint_legacy_manifest(): exact 16-name set (derived from the
 *     .gitattributes export-ignore list + .git + .planning; see the file
 *     header comment there for the historical-tag-root derivation).
 *   - sn_footprint_scan(): per-top-level-entry {name,bytes,files,is_legacy}
 *     + total, symlinks counted (not followed), unreadable dirs tolerated,
 *     truncated flag once the file budget is exhausted.
 *   - sn_footprint_entry_deletable(): the traversal guard (bare root name,
 *     lstat-exists).
 *   - sn_janitor_run(): deletes exactly the manifest entries present,
 *     leaves everything else untouched, symlinked entries lose only the
 *     link (never the thing they point at), freed_bytes is accurate.
 *   - sn_footprint_janitor_maybe_run(): the admin_init once-per-version
 *     gate (snt_janitor_last option) + the snt_janitor_log write.
 *   - sn_footprint_debug_information(): the Site Health `snt_footprint`
 *     panel.
 *
 * All filesystem fixtures live under a scratch dir beneath the OS temp dir
 * (never inside this worktree) and are torn down at suite end. Every path
 * handed to a deleting function in this file is an explicit fixture path —
 * never a repo path — so a bug here cannot reach the real checkout.
 *
 * Run: php tests/plugin-footprint.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SNT_VERSION', '99.0.0-test' );

// ── WP stubs (identity-ish; enough for the wiring + Site Health paths) ──
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function __( $s, $d = '' ) { return $s; }

$GLOBALS['__test_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}

$GLOBALS['__test_can_update_plugins'] = true;
function current_user_can( $cap ) { return ! empty( $GLOBALS['__test_can_update_plugins'] ); }

$GLOBALS['__test_is_admin'] = true;
function is_admin() { return ! empty( $GLOBALS['__test_is_admin'] ); }

function current_time( $type ) { return time(); }

$GLOBALS['__test_hooks'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__test_hooks']['action'][] = $hook; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__test_hooks']['filter'][] = $hook; }

require __DIR__ . '/../inc/plugin-footprint.php';

// ── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── Fixture helpers (test-local; deliberately independent of the SUT's
//    own delete routines, so a SUT bug can't mask an incomplete cleanup) ──
function sn_footprint_test_mkdirp( $dir ) {
	if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
}
function sn_footprint_test_write( $path, $content ) {
	sn_footprint_test_mkdirp( dirname( $path ) );
	file_put_contents( $path, $content );
}
function sn_footprint_test_build_fixture( $root ) {
	sn_footprint_test_mkdirp( $root );
	sn_footprint_test_write( $root . '/.git/objects/pack/dummy.pack', str_repeat( 'x', 200 * 1024 ) );
	sn_footprint_test_write( $root . '/tests/x.php', "<?php // test fixture\n" );
	sn_footprint_test_write( $root . '/docs/a.md', "# docs fixture\n" );
	sn_footprint_test_write( $root . '/CHANGELOG.md', "# changelog fixture\n" );
	sn_footprint_test_write( $root . '/composer.json', "{}\n" );
	sn_footprint_test_write( $root . '/inc/keep.php', "<?php // keep\n" );
	sn_footprint_test_write( $root . '/assets/a.css', "body{}\n" );
	sn_footprint_test_write( $root . '/LICENSE', "MIT\n" );
}
function sn_footprint_test_rrmdir( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) { unlink( $path ); return; }
	if ( ! is_dir( $path ) ) { return; }
	foreach ( scandir( $path ) as $child ) {
		if ( '.' === $child || '..' === $child ) { continue; }
		sn_footprint_test_rrmdir( $path . '/' . $child );
	}
	rmdir( $path );
}

$scratch_root = sys_get_temp_dir() . '/sn-footprint-test-' . uniqid( '', true );
sn_footprint_test_mkdirp( $scratch_root );

// ═══════════════════════════════════════════════════════════════════════
echo "Group: sn_footprint_legacy_manifest — the hardcoded manifest\n";
$manifest = sn_footprint_legacy_manifest();
$expected = array(
	'.git', '.github', '.gitattributes', '.gitignore', '.gitleaks.toml',
	'.planning', '.pre-commit-config.yaml', 'CHANGELOG.md', 'composer.json',
	'composer.lock', 'docs', 'phpcs.xml.dist', 'phpstan-baseline.neon',
	'phpstan-bootstrap.php', 'phpstan.neon', 'tests',
);
sort( $manifest ); sort( $expected );
ok( $manifest === $expected, 'manifest is exactly the 16-name legacy-deploy set' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_scan — sizes + legacy flags\n";
$base_a = $scratch_root . '/fixture-a';
sn_footprint_test_build_fixture( $base_a );
$scan = sn_footprint_scan( $base_a );

function scan_entry( $scan, $name ) {
	foreach ( $scan['entries'] as $e ) { if ( $e['name'] === $name ) { return $e; } }
	return null;
}

$git_entry = scan_entry( $scan, '.git' );
ok( null !== $git_entry, '.git present in scan' );
ok( true === $git_entry['is_legacy'], '.git flagged legacy' );
ok( 200 * 1024 === $git_entry['bytes'], '.git bytes == the 200KB pack file' );

$tests_entry = scan_entry( $scan, 'tests' );
ok( true === $tests_entry['is_legacy'], 'tests flagged legacy' );
ok( strlen( "<?php // test fixture\n" ) === $tests_entry['bytes'], 'tests bytes == x.php size' );

$docs_entry = scan_entry( $scan, 'docs' );
ok( true === $docs_entry['is_legacy'], 'docs flagged legacy' );

$changelog_entry = scan_entry( $scan, 'CHANGELOG.md' );
ok( true === $changelog_entry['is_legacy'], 'CHANGELOG.md flagged legacy' );
ok( 1 === $changelog_entry['files'], 'CHANGELOG.md counts as 1 file' );

$composer_entry = scan_entry( $scan, 'composer.json' );
ok( true === $composer_entry['is_legacy'], 'composer.json flagged legacy' );

$inc_entry = scan_entry( $scan, 'inc' );
ok( null !== $inc_entry, 'inc present in scan' );
ok( false === $inc_entry['is_legacy'], 'inc NOT flagged legacy (a keeper)' );

$assets_entry = scan_entry( $scan, 'assets' );
ok( false === $assets_entry['is_legacy'], 'assets NOT flagged legacy (a keeper)' );

$license_entry = scan_entry( $scan, 'LICENSE' );
ok( false === $license_entry['is_legacy'], 'LICENSE NOT flagged legacy (a keeper)' );

$expected_total = $git_entry['bytes'] + $tests_entry['bytes'] + $docs_entry['bytes']
	+ $changelog_entry['bytes'] + $composer_entry['bytes']
	+ $inc_entry['bytes'] + $assets_entry['bytes'] + $license_entry['bytes'];
ok( $expected_total === $scan['total_bytes'], 'total_bytes sums every top-level entry' );
ok( false === $scan['truncated'], 'truncated is false under the real (large) budget' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_janitor_run — deletes exactly the manifest entries present\n";
// Reuses fixture-a: sn_footprint_scan() above is read-only, so the tree is
// still intact. Legacy total computed above is the expected freed_bytes.
$legacy_total = $git_entry['bytes'] + $tests_entry['bytes'] + $docs_entry['bytes']
	+ $changelog_entry['bytes'] + $composer_entry['bytes'];
$result = sn_janitor_run( $base_a );

ok( array() === $result['errors'], 'errors array stays empty on the happy path' );
ok( $legacy_total === $result['freed_bytes'], 'freed_bytes matches the pre-measured legacy total' );
ok(
	isset( $result['deleted']['.git'], $result['deleted']['tests'], $result['deleted']['docs'], $result['deleted']['CHANGELOG.md'], $result['deleted']['composer.json'] ),
	'deleted map has all 5 present-legacy entries'
);
ok( 5 === count( $result['deleted'] ), 'deleted map has exactly 5 entries (nothing extra)' );

ok( ! file_exists( $base_a . '/.git' ), '.git removed from disk' );
ok( ! file_exists( $base_a . '/tests' ), 'tests removed from disk' );
ok( ! file_exists( $base_a . '/docs' ), 'docs removed from disk' );
ok( ! file_exists( $base_a . '/CHANGELOG.md' ), 'CHANGELOG.md removed from disk' );
ok( ! file_exists( $base_a . '/composer.json' ), 'composer.json removed from disk' );

ok( file_exists( $base_a . '/inc/keep.php' ), 'inc/keep.php SURVIVES' );
ok( file_exists( $base_a . '/assets/a.css' ), 'assets/a.css SURVIVES' );
ok( file_exists( $base_a . '/LICENSE' ), 'LICENSE SURVIVES' );

// Absent manifest entries (composer.lock, .github, etc.) are silent no-ops —
// re-running on the now-cleaned tree deletes nothing and stays error-free.
$result2 = sn_janitor_run( $base_a );
ok( array() === $result2['deleted'], 'second sweep of an already-clean tree deletes nothing' );
ok( 0 === $result2['freed_bytes'], 'second sweep frees 0 bytes' );
ok( array() === $result2['errors'], 'second sweep: no errors' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_janitor_run — symlinked manifest entry: link removed, target survives\n";
$base_d    = $scratch_root . '/fixture-symlink-base';
$external  = $scratch_root . '/fixture-symlink-external';
sn_footprint_test_mkdirp( $base_d );
sn_footprint_test_write( $external . '/canary.txt', 'canary survives' );
symlink( $external, $base_d . '/.git' );

ok( is_link( $base_d . '/.git' ), 'fixture sanity: .git is a symlink before the sweep' );
$sym_result = sn_janitor_run( $base_d );
ok( array() === $sym_result['errors'], 'symlink sweep: no errors' );
ok( isset( $sym_result['deleted']['.git'] ), 'symlink sweep: .git counted as deleted' );
ok( ! is_link( $base_d . '/.git' ) && ! file_exists( $base_d . '/.git' ), 'the LINK is gone' );
ok( is_dir( $external ) && file_exists( $external . '/canary.txt' ), 'the external target dir + canary file SURVIVE' );
ok( 'canary survives' === file_get_contents( $external . '/canary.txt' ), 'canary content untouched' );

// ═══════════════════════════════════════════════════════════════════════
// Review-fold (v9.43.0): the two safety-critical guards in the RECURSION
// get their own regression pins — a nested symlink must never be followed
// (is_link before is_dir inside delete_dir_recursive), and a direct
// out-of-base call must refuse at the containment check. Both passed as
// throwaway review probes; pinned here so a future delete-path refactor
// can't silently reorder them.
echo "\nGroup: delete recursion — nested symlink inside a manifest dir is not followed\n";
$base_n     = $scratch_root . '/fixture-nested-symlink-base';
$external_n = $scratch_root . '/fixture-nested-symlink-external';
sn_footprint_test_write( $base_n . '/tests/real-file.php', 'delete me' );
sn_footprint_test_write( $external_n . '/canary.txt', 'nested canary survives' );
symlink( $external_n, $base_n . '/tests/link-out' );

ok( is_link( $base_n . '/tests/link-out' ), 'fixture sanity: nested link-out is a symlink' );
$nested_result = sn_janitor_run( $base_n );
ok( array() === $nested_result['errors'], 'nested-symlink sweep: no errors' );
ok( ! file_exists( $base_n . '/tests' ), 'the manifest dir (tests/) is fully removed' );
ok( is_dir( $external_n ) && 'nested canary survives' === file_get_contents( $external_n . '/canary.txt' ), 'the OUTSIDE target of the nested symlink survives untouched' );

echo "\nGroup: delete recursion — direct out-of-base call refuses at the containment check\n";
$base_c    = $scratch_root . '/fixture-containment-base';
$outside_c = $scratch_root . '/fixture-containment-outside';
sn_footprint_test_mkdirp( $base_c );
sn_footprint_test_write( $outside_c . '/canary.txt', 'containment canary' );
$containment_errors = array();
$containment_freed  = sn_footprint_delete_dir_recursive( $outside_c, (string) realpath( $base_c ), $containment_errors );
ok( 0 === $containment_freed, 'out-of-base recursion frees 0 bytes' );
ok( 1 === count( $containment_errors ) && false !== strpos( $containment_errors[0], 'escapes the containment root' ), 'out-of-base recursion records the refusal error' );
ok( is_dir( $outside_c ) && 'containment canary' === file_get_contents( $outside_c . '/canary.txt' ), 'the outside tree survives untouched' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_entry_deletable — the traversal guard\n";
$guard_base = $scratch_root . '/fixture-guard';
sn_footprint_test_write( $guard_base . '/.git/marker', 'x' );
ok( false === sn_footprint_entry_deletable( $guard_base, 'evil/../..' ), 'refuses evil/../..' );
ok( false === sn_footprint_entry_deletable( $guard_base, 'a/b' ), 'refuses a/b' );
ok( false === sn_footprint_entry_deletable( $guard_base, '.' ), "refuses '.'" );
ok( false === sn_footprint_entry_deletable( $guard_base, '..' ), "refuses '..'" );
ok( false === sn_footprint_entry_deletable( $guard_base, '' ), 'refuses empty name' );
ok( false === sn_footprint_entry_deletable( $guard_base, 'nope-does-not-exist' ), 'refuses a name that does not exist under base' );
ok( true === sn_footprint_entry_deletable( $guard_base, '.git' ), 'accepts a real bare root-level name' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_scan — tolerates unreadable entries without warnings\n";
$unreadable_base = $scratch_root . '/fixture-unreadable';
sn_footprint_test_write( $unreadable_base . '/locked/inner.txt', 'secret' );
sn_footprint_test_write( $unreadable_base . '/open/inner.txt', 'fine' );
chmod( $unreadable_base . '/locked', 0000 );
$saw_warning = false;
set_error_handler( function () use ( &$saw_warning ) { $saw_warning = true; return true; } );
$unreadable_scan = sn_footprint_scan( $unreadable_base );
restore_error_handler();
if ( is_readable( $unreadable_base . '/locked' ) ) {
	echo "  SKIP: locked-dir readability test (running as a user that bypasses permission bits, e.g. root)\n";
} else {
	ok( false === $saw_warning, 'scanning an unreadable dir raises no PHP warning/notice' );
	ok( null !== scan_entry( $unreadable_scan, 'locked' ), 'the unreadable dir still appears as an entry (degrades to 0 bytes, not a crash)' );
}
chmod( $unreadable_base . '/locked', 0755 ); // restore so cleanup can remove it

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_scan — file-budget truncation\n";
$budget_base = $scratch_root . '/fixture-budget';
for ( $i = 0; $i < 5; $i++ ) {
	sn_footprint_test_write( $budget_base . '/many/f' . $i . '.txt', 'x' );
}
$budget_scan = sn_footprint_scan( $budget_base, 3 );
ok( true === $budget_scan['truncated'], 'a tiny file budget flags truncated' );
$full_scan = sn_footprint_scan( $budget_base );
ok( false === $full_scan['truncated'], 'the real (large) budget does not truncate the same tree' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_janitor_maybe_run — the once-per-version admin_init gate\n";
$GLOBALS['__test_options'] = array();
$base_c = $scratch_root . '/fixture-gate';
sn_footprint_test_build_fixture( $base_c );

$run1 = sn_footprint_janitor_maybe_run( $base_c );
ok( null !== $run1, 'first call (fresh install) actually runs the janitor' );
ok( $run1['freed_bytes'] > 0, 'first call frees bytes' );
ok( SNT_VERSION === get_option( 'snt_janitor_last' ), 'snt_janitor_last option now == SNT_VERSION' );
$log = get_option( 'snt_janitor_log' );
ok( is_array( $log ) && SNT_VERSION === $log['version'], 'snt_janitor_log written with the current version' );
ok( ! file_exists( $base_c . '/CHANGELOG.md' ), 'CHANGELOG.md actually removed by the wired run' );

// A stray reappears post-update (e.g. a fresh git checkout deploy) — but the
// version gate has already flipped, so the SAME version must not re-sweep it.
sn_footprint_test_write( $base_c . '/CHANGELOG.md', 'reappeared stray' );
$run2 = sn_footprint_janitor_maybe_run( $base_c );
ok( null === $run2, 'second call on the same version is a true no-op (gate holds)' );
ok( file_exists( $base_c . '/CHANGELOG.md' ), 'the reappeared stray is left untouched by the no-op' );

// A version bump re-opens the gate so post-update strays get swept again.
define( 'SNT_FOOTPRINT_TEST_NEXT_VERSION', '99.0.1-test' );
$GLOBALS['__test_options']['snt_janitor_last'] = '99.0.0-old';
$run3 = sn_footprint_janitor_maybe_run( $base_c, SNT_FOOTPRINT_TEST_NEXT_VERSION );
ok( null !== $run3, 'a version mismatch re-opens the gate' );

// Permission gate: neither is_admin() nor manage_options-tier cap should let
// the janitor run against a non-admin request.
$GLOBALS['__test_options'] = array();
$base_e = $scratch_root . '/fixture-gate-perms';
sn_footprint_test_build_fixture( $base_e );
$GLOBALS['__test_is_admin'] = false;
ok( null === sn_footprint_janitor_maybe_run( $base_e ), 'is_admin() === false blocks the run' );
$GLOBALS['__test_is_admin'] = true;
$GLOBALS['__test_can_update_plugins'] = false;
ok( null === sn_footprint_janitor_maybe_run( $base_e ), 'missing update_plugins cap blocks the run' );
$GLOBALS['__test_can_update_plugins'] = true;

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_debug_information — the Site Health snt_footprint panel\n";
$base_g = $scratch_root . '/fixture-debug';
sn_footprint_test_build_fixture( $base_g );
$GLOBALS['__test_options'] = array(
	'snt_janitor_log' => array( 'version' => '9.9.9', 'freed_bytes' => 123456, 'deleted' => array(), 'errors' => array(), 'time' => time() ),
);
$info = sn_footprint_debug_information( array( 'wp-core' => array( 'label' => 'WP' ) ), $base_g );

ok( isset( $info['wp-core'] ), 'preserves incoming panels' );
ok( isset( $info['snt_footprint'] ), "adds the 'snt_footprint' section" );
$panel = $info['snt_footprint'];
ok( isset( $panel['label'], $panel['description'], $panel['fields'] ), 'panel has label/description/fields' );
$field_values = implode( ' | ', array_map( function ( $f ) { return (string) ( $f['label'] ?? '' ) . ' ' . (string) ( $f['value'] ?? '' ); }, $panel['fields'] ) );
ok( false !== strpos( $field_values, '.git' ) && false !== strpos( $field_values, 'legacy deploy leftover' ), '.git field flags "legacy deploy leftover"' );
ok( false !== strpos( $field_values, 'inc' ) && false === strpos( $field_values, 'inc — legacy' ), "inc field does NOT flag legacy" );
ok( false !== stripos( $field_values, 'freed' ) && false !== strpos( $field_values, 'v9.9.9' ), 'last janitor log rendered as "freed X on vY"' );

// No log yet: the janitor-log field must not appear.
$GLOBALS['__test_options'] = array();
$info_no_log = sn_footprint_debug_information( array(), $base_g );
$no_log_values = implode( ' | ', array_map( function ( $f ) { return (string) ( $f['value'] ?? '' ); }, $info_no_log['snt_footprint']['fields'] ) );
ok( false === stripos( $no_log_values, 'freed' ), 'no janitor-log field when snt_janitor_log is unset' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: WP wiring — hooks actually register\n";
ok( in_array( 'admin_init', $GLOBALS['__test_hooks']['action'] ?? array(), true ), 'admin_init action registered' );
ok( in_array( 'debug_information', $GLOBALS['__test_hooks']['filter'] ?? array(), true ), 'debug_information filter registered' );

// ── Cleanup ──────────────────────────────────────────────────────────
sn_footprint_test_rrmdir( $scratch_root );
ok( ! is_dir( $scratch_root ), 'scratch fixture root cleaned up' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
