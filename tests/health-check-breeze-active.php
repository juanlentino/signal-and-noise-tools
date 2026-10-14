<?php
/**
 * Tests: the Breeze page cache health check.
 *
 * The Cloudflare edge is the only page cache (owner, 2026-10-13). Breeze is
 * deactivated, and Cloudways can switch it back on. A Breeze page cache serves
 * pages without Cache-Tag, so tag purges cannot clear them. This check goes red
 * when Breeze is active, or when WP_CACHE is on with an advanced-cache.php
 * drop-in present.
 *
 * Run: php tests/health-check-breeze-active.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
		'skipped'  => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null,
	);
}

require_once __DIR__ . '/../inc/health-check-breeze-active.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$sixteen = array( 'akismet/akismet.php', 'object-cache-pro/object-cache-pro.php', 'signal-and-noise-tools/signal-and-noise-tools.php' );

echo "Group 1: the edge is the only page cache, nothing to report\n";
ok( array() === sn_health_breeze_findings( $sixteen, false, false ), 'Breeze off, WP_CACHE off, no drop-in: no finding' );
ok( array() === sn_health_breeze_findings( $sixteen, true, false ), 'WP_CACHE on with no drop-in caches nothing: no finding' );
ok( array() === sn_health_breeze_findings( $sixteen, false, true ), 'a drop-in WordPress does not load (WP_CACHE off): no finding' );
ok( array() === sn_health_breeze_findings( array( 'breeze-extra/breeze.php' ), false, false ), 'only breeze/breeze.php counts, not a lookalike path' );

echo "\nGroup 2: a second page cache is red\n";
$f = sn_health_breeze_findings( array_merge( $sixteen, array( 'breeze/breeze.php' ) ), false, false );
ok( 1 === count( $f ) && 'breeze/breeze.php' === $f[0]['subject_label'], 'Breeze active: one finding naming the plugin' );
$f = sn_health_breeze_findings( $sixteen, true, true );
ok( 1 === count( $f ) && 'advanced-cache.php' === $f[0]['subject_label'], 'WP_CACHE on with a drop-in: one finding naming the drop-in' );
$f = sn_health_breeze_findings( array( 'breeze/breeze.php' ), true, true );
ok( 2 === count( $f ), 'both at once: two findings' );
ok( array() === sn_health_breeze_findings( 'not an array', false, false ), 'an unreadable active_plugins reads as no plugin, never a crash' );

echo "\nGroup 3: the live check\n";
$GLOBALS['__opt'] = array( 'breeze/breeze.php' );
function get_option( $k, $d = false ) { return 'active_plugins' === $k ? $GLOBALS['__opt'] : $d; }
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/snt-breeze-' . getmypid() );
$r = sn_health_check_breeze_active();
ok( 1 === $r['count'] && null === $r['skipped'], 'the live check reads active_plugins and goes red' );
ok( false !== strpos( $r['fix_hint'], 'Deactivate Breeze' ) && false !== strpos( $r['fix_hint'], 'Cloudflare edge' ), 'the fix hint says what to do and why' );
$GLOBALS['__opt'] = array();
ok( 0 === sn_health_check_breeze_active()['count'], 'and reads clean when Breeze is off' );

echo "\nGroup 4: registered in all four places\n";
$root = dirname( __DIR__ );
ok( false !== strpos( (string) file_get_contents( $root . '/signal-and-noise-tools.php' ), 'inc/health-check-breeze-active.php' ), 'the bootstrap loads it' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/health-checks.php' ), "'breeze_active'" ), 'the scan runs it' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/health-check-surfaces.php' ), "'breeze_active'" ), 'a surface owns it' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/health-check-families.php' ), "'breeze_active'" ), 'a family maps it' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
