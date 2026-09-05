<?php
/**
 * Tests: the plugin-registry health check (issue #1026).
 *
 * The bug this detects reported itself as a HEALTHY 200 carrying an empty
 * list, so the assertions below are mostly about telling apart states that
 * look identical from the outside:
 *
 *   - "no plugins installed" vs "the registry lost them"
 *   - "the cache is stale" (file on disk) vs "the plugin is gone" (file absent)
 *   - "the check passed" vs "the check could not run"
 *
 * A suite that only asserted "returns findings when something is wrong" would
 * pass against a check that gave the same advice for every case, which is the
 * thing that made the original incident take so long to read.
 *
 * Run: php tests/health-check-plugin-registry.php
 * @since 13.96.6
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

// A local pack helper: requiring inc/health-checks.php drags in the whole scan
// layer (and DAY_IN_SECONDS). The REAL function's shape is pinned by the
// admin-registry suite, which is what keeps this stub from drifting.
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
		'skipped'  => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null,
	);
}

// A real directory, so the on-disk / absent split exercises the real predicate
// rather than a mocked file_exists().
$tmp = sys_get_temp_dir() . '/snt-plugin-registry-' . getmypid();
@mkdir( $tmp . '/on-disk', 0777, true );
file_put_contents( $tmp . '/on-disk/on-disk.php', "<?php\n" );
define( 'WP_PLUGIN_DIR', $tmp );

$GLOBALS['snt_active']   = array();
$GLOBALS['snt_registry'] = array();
function get_option( $name, $default = false ) {
	return 'active_plugins' === $name ? $GLOBALS['snt_active'] : $default;
}
function get_plugins() {
	return $GLOBALS['snt_registry'];
}

require_once __DIR__ . '/../inc/health-check-plugin-registry.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Run the check against a given world. */
function run( $active, $registry ) {
	$GLOBALS['snt_active']   = $active;
	$GLOBALS['snt_registry'] = $registry;
	return sn_health_check_plugin_registry();
}

echo "health-check-plugin-registry — plugin v13.96.6\n\nGroup 1: a healthy registry is silent\n";
$r = run( array( 'on-disk/on-disk.php' ), array( 'on-disk/on-disk.php' => array( 'Name' => 'On Disk' ) ) );
ok( 0 === $r['count'], 'an active plugin present in the registry produces no finding' );
ok( null === $r['skipped'], 'and the check reports that it RAN (skipped is null)' );

echo "\nGroup 2: the headline case — an empty registry while plugins are active\n";
$r = run( array( 'on-disk/on-disk.php', 'gone/gone.php' ), array() );
ok( $r['count'] >= 1, 'an empty registry with active plugins is a finding' );
$subjects = array_column( $r['findings'], 'subject_type' );
ok( in_array( 'plugin_registry', $subjects, true ), 'it names the REGISTRY itself, not only the individual plugins' );
$headline = '';
foreach ( $r['findings'] as $f ) {
	if ( 'plugin_registry' === $f['subject_type'] ) { $headline = $f['note']; }
}
ok( false !== strpos( $headline, 'wp cache flush' ), 'and it names the fix' );
ok( 1 === preg_match( '/\b2\b/', $headline ), 'and reports HOW MANY plugins are active while the registry is empty' );

echo "\nGroup 3: the two causes are told apart — the whole point of the check\n";
$r = run(
	array( 'on-disk/on-disk.php', 'gone/gone.php' ),
	array( 'other/other.php' => array( 'Name' => 'Other' ) )   // non-empty, so no headline finding
);
ok( 2 === $r['count'], 'both missing actives are reported (' . $r['count'] . ')' );
$notes = array();
foreach ( $r['findings'] as $f ) {
	$notes[ $f['subject_label'] ] = $f['note'];
}
ok( false !== strpos( $notes['on-disk/on-disk.php'] ?? '', 'wp cache flush' ), 'file ON DISK but unregistered -> flush the cache' );
ok( false === strpos( $notes['on-disk/on-disk.php'] ?? '', 'Deactivate' ), '   ...and it does NOT tell you to deactivate a plugin that is fine' );
ok( false !== strpos( $notes['gone/gone.php'] ?? '', 'Deactivate' ), 'file ABSENT -> deactivate the orphan' );
ok( false === strpos( $notes['gone/gone.php'] ?? '', 'wp cache flush' ), '   ...and it does NOT send you to flush a cache that is innocent' );
ok(
	( $notes['on-disk/on-disk.php'] ?? 'x' ) !== ( $notes['gone/gone.php'] ?? 'y' ),
	'THE PIN: two opposite repairs never share one sentence'
);

echo "\nGroup 4: absence of evidence is not a pass\n";
$r = run( array(), array() );
ok( 0 === $r['count'] && is_string( $r['skipped'] ), 'no active plugins -> SKIPPED, not passed (nothing was compared)' );
ok( false !== strpos( (string) $r['skipped'], 'no active plugins' ), 'and it says why' );

$GLOBALS['snt_registry'] = 'not-an-array';
$GLOBALS['snt_active']   = array( 'on-disk/on-disk.php' );
$r = sn_health_check_plugin_registry();
ok( is_string( $r['skipped'] ), 'a non-array registry -> SKIPPED, not a silent pass' );

echo "\nGroup 5: negative control\n";
// Prove the healthy case can be made to fail: the same world, minus the
// registry entry, must go from 0 findings to a finding.
$clean = run( array( 'on-disk/on-disk.php' ), array( 'on-disk/on-disk.php' => array( 'Name' => 'On Disk' ) ) );
$broken = run( array( 'on-disk/on-disk.php' ), array( 'unrelated/unrelated.php' => array( 'Name' => 'Unrelated' ) ) );
ok( 0 === $clean['count'] && $broken['count'] > 0, 'removing the active plugin from the registry turns the check red (' . $clean['count'] . ' -> ' . $broken['count'] . ')' );

@unlink( $tmp . '/on-disk/on-disk.php' );
@rmdir( $tmp . '/on-disk' );
@rmdir( $tmp );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
