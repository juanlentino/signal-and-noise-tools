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
echo "\nGroup: delete recursion — chmod hardening heals an unwritable manifest dir (A4)\n";
// SSH-checkout-era ownership can leave a dir with no write bit for the PHP
// user; on entry, sn_footprint_delete_dir_recursive() must chmod(0755) it
// BEFORE scandir rather than erroring out (a directory's own mode gates
// whether children under it can be unlinked, independent of file mode).
$chmod_base = $scratch_root . '/fixture-chmod-base';
sn_footprint_test_mkdirp( $chmod_base );
$locked_dir = $chmod_base . '/locked-sub';
sn_footprint_test_write( $locked_dir . '/inner.txt', 'stubborn file' );
chmod( $locked_dir, 0555 ); // read+execute, no write.

if ( is_writable( $locked_dir ) ) {
	echo "  SKIP: chmod-hardening test (running as a user that bypasses permission bits, e.g. root)\n";
} else {
	$chmod_errors = array();
	$chmod_freed  = sn_footprint_delete_dir_recursive( $locked_dir, (string) realpath( $chmod_base ), $chmod_errors );
	ok( array() === $chmod_errors, 'A4: a 0555 subdir is chmod-healed and its file is removed with no error' );
	ok( strlen( 'stubborn file' ) === $chmod_freed, 'A4: freed_bytes reflects the recovered file size' );
	ok( ! file_exists( $locked_dir ), 'A4: the locked-down dir itself is fully removed' );
}
if ( is_dir( $locked_dir ) ) { chmod( $locked_dir, 0755 ); } // restore in case an assertion above failed, so cleanup can still remove it

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
// v9.47.2 "diagnosis hygiene" — LANE A additions.
// A run-result override lets these tests drive the storage/gate logic in
// sn_footprint_janitor_maybe_run() directly, without needing a real FS
// failure (A4's chmod healing makes owner-chmodable fixtures unreliable for
// that — see the chmod-hardening group further down, which DOES use a real
// FS failure because that's what A4 itself is).
echo "\nGroup: sn_footprint_janitor_maybe_run — unconditional log storage (A1) + error cap (A2)\n";
$override_base = $scratch_root . '/fixture-does-not-need-to-exist';

// Nothing attempted: freed=0, no errors — still a real answer, still stored.
$GLOBALS['__test_options'] = array();
$run_empty = sn_footprint_janitor_maybe_run( $override_base, '1.0.0', array( 'deleted' => array(), 'freed_bytes' => 0, 'errors' => array() ) );
ok( null !== $run_empty, 'A1: a run with freed=0 and no errors still executes and returns a result' );
$log_empty = get_option( 'snt_janitor_log' );
ok( is_array( $log_empty ), 'A1: snt_janitor_log is stored even when nothing was freed and nothing errored' );
ok( 0 === ( $log_empty['freed_bytes'] ?? null ), 'A1: stored freed_bytes is 0' );
ok( array() === ( $log_empty['deleted'] ?? null ), 'A1: stored deleted map is empty' );
ok( array() === ( $log_empty['errors'] ?? null ), 'A1: stored errors is empty' );
ok( 0 === ( $log_empty['errors_total'] ?? null ), 'A1: errors_total is 0' );
ok( '1.0.0' === ( $log_empty['version'] ?? null ), 'A1: version recorded' );
ok( isset( $log_empty['time'] ), 'A1: time recorded' );

// Total failure: freed=0, 25 errors — still stored (today's code skips this entirely).
$GLOBALS['__test_options'] = array();
$many_errors = array();
for ( $i = 0; $i < 25; $i++ ) { $many_errors[] = "Failed to remove file \"stray-$i\"."; }
$run_failed = sn_footprint_janitor_maybe_run( $override_base, '1.0.0', array( 'deleted' => array(), 'freed_bytes' => 0, 'errors' => $many_errors ) );
ok( null !== $run_failed, 'A1: a total-failure sweep still returns a result' );
$log_failed = get_option( 'snt_janitor_log' );
ok( is_array( $log_failed ), 'A1: snt_janitor_log stored on a total failure (freed=0, errors present)' );
ok( 0 === ( $log_failed['freed_bytes'] ?? null ), 'A1: freed_bytes is 0 on total failure' );
ok( 25 === ( $log_failed['errors_total'] ?? null ), 'A2: errors_total records the TRUE pre-cap count (25)' );
ok( 20 === count( $log_failed['errors'] ?? array() ), 'A2: stored errors array is capped at 20' );
ok( ( $log_failed['errors'] ?? null ) === array_slice( $many_errors, 0, 20 ), 'A2: stored errors are the FIRST 20, order preserved' );

// Partial success: freed>0 with some errors.
$GLOBALS['__test_options'] = array();
$run_partial = sn_footprint_janitor_maybe_run( $override_base, '1.0.0', array( 'deleted' => array( '.git' => 500 ), 'freed_bytes' => 500, 'errors' => array( 'Failed to remove file "x".' ) ) );
$log_partial = get_option( 'snt_janitor_log' );
ok( 500 === ( $log_partial['freed_bytes'] ?? null ), 'partial success: freed_bytes stored' );
ok( 1 === ( $log_partial['errors_total'] ?? null ), 'partial success: errors_total is 1' );
ok( 1 === count( $log_partial['errors'] ?? array() ), 'partial success: errors array has the 1 error (under the cap)' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_debug_information — janitor_log row variants (A3)\n";
$base_panel = $scratch_root . '/fixture-panel';
sn_footprint_test_mkdirp( $base_panel );

// freed>0, no errors: "freed X on vY" — the pre-existing phrasing, unchanged.
$GLOBALS['__test_options'] = array( 'snt_janitor_log' => array( 'version' => '2.0.0', 'freed_bytes' => 2048, 'deleted' => array(), 'errors' => array(), 'errors_total' => 0, 'time' => time() ) );
$info_v1 = sn_footprint_debug_information( array(), $base_panel );
$val_v1  = $info_v1['snt_footprint']['fields']['janitor_log']['value'] ?? '';
ok( false !== stripos( $val_v1, 'freed' ) && false !== strpos( $val_v1, 'v2.0.0' ), 'freed>0, no errors: renders "freed X on vY"' );
ok( false === stripos( $val_v1, 'error' ), 'freed>0, no errors: no error suffix appended' );

// freed>0, errors present: "freed X on vY, N error(s)".
$GLOBALS['__test_options'] = array( 'snt_janitor_log' => array( 'version' => '2.0.1', 'freed_bytes' => 2048, 'deleted' => array(), 'errors' => array( 'e1' ), 'errors_total' => 3, 'time' => time() ) );
$info_v2 = sn_footprint_debug_information( array(), $base_panel );
$val_v2  = $info_v2['snt_footprint']['fields']['janitor_log']['value'] ?? '';
ok( false !== stripos( $val_v2, 'freed' ) && false !== strpos( $val_v2, 'v2.0.1' ), 'freed>0 + errors: still leads with "freed X on vY"' );
ok( false !== strpos( $val_v2, '3' ) && false !== stripos( $val_v2, 'error' ), 'freed>0 + errors: appends the error count (3)' );

// freed=0, errors present: "removed nothing on vY — N error(s)".
$GLOBALS['__test_options'] = array( 'snt_janitor_log' => array( 'version' => '2.0.2', 'freed_bytes' => 0, 'deleted' => array(), 'errors' => array( 'e1', 'e2' ), 'errors_total' => 2, 'time' => time() ) );
$info_v3 = sn_footprint_debug_information( array(), $base_panel );
$val_v3  = $info_v3['snt_footprint']['fields']['janitor_log']['value'] ?? '';
ok( false !== stripos( $val_v3, 'removed nothing' ) && false !== strpos( $val_v3, 'v2.0.2' ), 'freed=0 + errors: "removed nothing on vY"' );
ok( false !== strpos( $val_v3, '2' ) && false !== stripos( $val_v3, 'error' ), 'freed=0 + errors: error count present (2)' );
ok( false === stripos( $val_v3, 'freed ' ), 'freed=0 + errors: does NOT use the "freed X" phrasing' );

// freed=0, no errors (nothing attempted): "nothing to remove (vY)" — this is
// A1's corollary: a fully-empty sweep now renders SOMETHING (previously the
// field didn't exist at all for freed=0).
$GLOBALS['__test_options'] = array( 'snt_janitor_log' => array( 'version' => '2.0.3', 'freed_bytes' => 0, 'deleted' => array(), 'errors' => array(), 'errors_total' => 0, 'time' => time() ) );
$info_v4 = sn_footprint_debug_information( array(), $base_panel );
ok( isset( $info_v4['snt_footprint']['fields']['janitor_log'] ), 'freed=0, no errors: janitor_log field renders even for a fully-empty sweep' );
$val_v4 = $info_v4['snt_footprint']['fields']['janitor_log']['value'] ?? '';
ok( false !== stripos( $val_v4, 'nothing to remove' ) && false !== strpos( $val_v4, 'v2.0.3' ), 'freed=0, no errors: "nothing to remove (vY)"' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_debug_information — janitor_errors field (A3)\n";
// Present, private, and lists the (already-capped) stored error strings —
// no "and N more" suffix when errors_total equals the stored count.
$GLOBALS['__test_options'] = array( 'snt_janitor_log' => array( 'version' => '3.0.0', 'freed_bytes' => 0, 'deleted' => array(), 'errors' => array( 'Failed A', 'Failed B' ), 'errors_total' => 2, 'time' => time() ) );
$info_e1 = sn_footprint_debug_information( array(), $base_panel );
ok( isset( $info_e1['snt_footprint']['fields']['janitor_errors'] ), 'janitor_errors field present when errors exist' );
$err_field1 = $info_e1['snt_footprint']['fields']['janitor_errors'] ?? array();
ok( true === ( $err_field1['private'] ?? false ), 'janitor_errors field is private (internal paths)' );
$err_val1 = $err_field1['value'] ?? '';
ok( false !== strpos( $err_val1, 'Failed A' ) && false !== strpos( $err_val1, 'Failed B' ), 'janitor_errors value lists the stored error strings' );
ok( false === stripos( $err_val1, 'more' ), 'no "and N more" suffix when errors_total equals the stored count' );

// "…and N more" when errors_total exceeds the stored (capped) count.
$capped_errors = array();
for ( $i = 0; $i < 20; $i++ ) { $capped_errors[] = "Failed file $i"; }
$GLOBALS['__test_options'] = array( 'snt_janitor_log' => array( 'version' => '3.0.1', 'freed_bytes' => 0, 'deleted' => array(), 'errors' => $capped_errors, 'errors_total' => 47, 'time' => time() ) );
$info_e2    = sn_footprint_debug_information( array(), $base_panel );
$err_val2   = $info_e2['snt_footprint']['fields']['janitor_errors']['value'] ?? '';
ok( false !== strpos( $err_val2, '27' ) && false !== stripos( $err_val2, 'more' ), 'janitor_errors appends "…and N more" (47-20=27) past the stored cap' );

// Absent entirely when there are no errors at all.
$GLOBALS['__test_options'] = array( 'snt_janitor_log' => array( 'version' => '3.0.2', 'freed_bytes' => 100, 'deleted' => array(), 'errors' => array(), 'errors_total' => 0, 'time' => time() ) );
$info_e3 = sn_footprint_debug_information( array(), $base_panel );
ok( ! isset( $info_e3['snt_footprint']['fields']['janitor_errors'] ), 'janitor_errors field absent when there are no errors' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: sn_footprint_janitor_maybe_run — failure re-arm / daily retry gate (A5)\n";
// Literal 86400 (== DAY_IN_SECONDS), not a constant reference: mirrors the
// module's own stance (see SN_JANITOR_RETRY_INTERVAL_S's doc comment) and
// keeps this test from needing to know the SUT's internal constant name.
$retry_base = $scratch_root . '/fixture-retry';

// version-match + an OLD errored log → the gate re-opens and runs.
$GLOBALS['__test_options'] = array(
	'snt_janitor_last' => '5.0.0',
	'snt_janitor_log'  => array( 'version' => '5.0.0', 'freed_bytes' => 0, 'deleted' => array(), 'errors' => array( 'e' ), 'errors_total' => 1, 'time' => time() - 86400 - 10 ),
);
$retry_run = sn_footprint_janitor_maybe_run( $retry_base, '5.0.0', array( 'deleted' => array( 'tests' => 10 ), 'freed_bytes' => 10, 'errors' => array() ) );
ok( null !== $retry_run, 'A5: version-match + an old errored log re-opens the gate and runs' );
$retry_log_after = get_option( 'snt_janitor_log' );
ok( 10 === ( $retry_log_after['freed_bytes'] ?? null ), 'A5: the re-run result is what gets stored' );

// version-match + a FRESH errored log → skips (must not hammer every admin_init).
$GLOBALS['__test_options'] = array(
	'snt_janitor_last' => '5.0.0',
	'snt_janitor_log'  => array( 'version' => '5.0.0', 'freed_bytes' => 0, 'deleted' => array(), 'errors' => array( 'e' ), 'errors_total' => 1, 'time' => time() ),
);
$fresh_run = sn_footprint_janitor_maybe_run( $retry_base, '5.0.0', array( 'deleted' => array( 'tests' => 999 ), 'freed_bytes' => 999, 'errors' => array() ) );
ok( null === $fresh_run, 'A5: version-match + a FRESH errored log does NOT re-run' );
$fresh_log_after = get_option( 'snt_janitor_log' );
ok( 0 === ( $fresh_log_after['freed_bytes'] ?? null ), 'A5: the stored log is untouched when the gate skips' );

// version-match + a CLEAN (error-free) old log → skips, same as today.
$GLOBALS['__test_options'] = array(
	'snt_janitor_last' => '5.0.0',
	'snt_janitor_log'  => array( 'version' => '5.0.0', 'freed_bytes' => 500, 'deleted' => array(), 'errors' => array(), 'errors_total' => 0, 'time' => time() - 86400 - 10 ),
);
$clean_run = sn_footprint_janitor_maybe_run( $retry_base, '5.0.0', array( 'deleted' => array( 'tests' => 999 ), 'freed_bytes' => 999, 'errors' => array() ) );
ok( null === $clean_run, 'A5: version-match + a CLEAN (error-free) old log does not re-run even though old' );

// ═══════════════════════════════════════════════════════════════════════
echo "\nGroup: WP wiring — hooks actually register\n";
ok( in_array( 'admin_init', $GLOBALS['__test_hooks']['action'] ?? array(), true ), 'admin_init action registered' );
ok( in_array( 'debug_information', $GLOBALS['__test_hooks']['filter'] ?? array(), true ), 'debug_information filter registered' );

// ── Cleanup ──────────────────────────────────────────────────────────
sn_footprint_test_rrmdir( $scratch_root );
ok( ! is_dir( $scratch_root ), 'scratch fixture root cleaned up' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
