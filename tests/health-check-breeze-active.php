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

$plain_plugins = array( 'akismet/akismet.php', 'object-cache-pro/object-cache-pro.php', 'signal-and-noise-tools/signal-and-noise-tools.php' );

echo "Group 1: the edge is the only page cache, nothing to report\n";
ok( array() === sn_health_breeze_findings( $plain_plugins, false, false ), 'Breeze off, WP_CACHE off, no drop-in: no finding' );
ok( array() === sn_health_breeze_findings( $plain_plugins, true, false ), 'WP_CACHE on with no drop-in caches nothing: no finding' );
ok( array() === sn_health_breeze_findings( $plain_plugins, false, true ), 'a drop-in WordPress does not load (WP_CACHE off): no finding' );
ok( array() === sn_health_breeze_findings( array( 'breeze-extra/breeze.php' ), false, false ), 'only breeze/breeze.php counts, not a lookalike path' );

echo "\nGroup 2: a second page cache is red\n";
$f = sn_health_breeze_findings( array_merge( $plain_plugins, array( 'breeze/breeze.php' ) ), false, false );
ok( 1 === count( $f ) && 'breeze/breeze.php' === $f[0]['subject_label'], 'Breeze active: one finding naming the plugin' );
$f = sn_health_breeze_findings( $plain_plugins, true, true );
ok( 1 === count( $f ) && 'advanced-cache.php' === $f[0]['subject_label'], 'WP_CACHE on with a drop-in: one finding naming the drop-in' );
$f = sn_health_breeze_findings( array( 'breeze/breeze.php' ), true, true );
ok( 2 === count( $f ), 'both at once: two findings' );
ok( array() === sn_health_breeze_findings( 'not an array', false, false ), 'an unreadable active_plugins reads as no plugin, never a crash' );

echo "\nGroup 3: the fix hint names what to switch off\n";
$bf = sn_health_breeze_findings( array( 'breeze/breeze.php' ), false, false );
ok( 0 === strpos( sn_health_breeze_fix_hint( $bf ), 'Deactivate Breeze' ), 'Breeze active: deactivate Breeze' );
$df = sn_health_breeze_findings( array(), true, true, 'WP Rocket' );
$h  = sn_health_breeze_fix_hint( $df, 'WP Rocket' );
ok( false === strpos( $h, 'Deactivate Breeze' ) && false !== strpos( $h, 'WP Rocket, which wrote wp-content/advanced-cache.php' ) && false !== strpos( $df[0]['note'], 'its header names WP Rocket' ), 'another plugin\'s drop-in: the hint and the note name that plugin, not Breeze' );
ok( false !== strpos( sn_health_breeze_fix_hint( sn_health_breeze_findings( array(), true, true ), '' ), 'the plugin that wrote wp-content/advanced-cache.php' ), 'an unknown drop-in: the hint names the file' );
ok( 0 === strpos( sn_health_breeze_fix_hint( $df, 'Breeze' ), 'Deactivate Breeze' ), 'Breeze\'s own leftover drop-in: deactivate Breeze' );
ok( 'WP Rocket' === sn_health_dropin_owner( "<?php\n/* WP Rocket advanced cache */" ) && '' === sn_health_dropin_owner( '<?php // mine' ), 'the owner comes from the file header, or nothing' );
ok( false !== strpos( $bf[0]['note'], 'can serve pages without Cache-Tag' ), 'the note says Breeze can, not that it does' );

echo "\nGroup 4: the live check\n";
$GLOBALS['__opt'] = array( 'breeze/breeze.php' );
function get_option( $k, $d = false ) { return 'active_plugins' === $k ? $GLOBALS['__opt'] : $d; }
$content = sys_get_temp_dir() . '/snt-breeze-' . getmypid();
@mkdir( $content );
define( 'WP_CONTENT_DIR', $content );
$r = sn_health_check_breeze_active();
ok( 1 === $r['count'] && null === $r['skipped'], 'the live check reads active_plugins and goes red' );
ok( false !== strpos( $r['fix_hint'], 'Deactivate Breeze' ) && false !== strpos( $r['fix_hint'], 'Cloudflare edge' ), 'the fix hint says what to do and why' );
$GLOBALS['__opt'] = array();
ok( 0 === sn_health_check_breeze_active()['count'], 'and reads clean when Breeze is off' );
file_put_contents( $content . '/advanced-cache.php', "<?php\n/* WP Rocket advanced cache */\n" );
ok( 0 === sn_health_check_breeze_active()['count'], 'a drop-in with WP_CACHE undefined (this process) reads clean' );
// WP_CACHE is a constant: the true branch runs in its own process.
$child = 'define("ABSPATH","/"); define("WP_CACHE", true); define("WP_CONTENT_DIR", ' . var_export( $content, true ) . ');'
	. ' function get_option($k,$d=false){return array();}'
	. ' function sn_health_pack_check($l,$f,$h="",$s=null){return array("count"=>count($f),"findings"=>$f,"fix_hint"=>$h);}'
	. ' require ' . var_export( dirname( __DIR__ ) . '/inc/health-check-breeze-active.php', true ) . ';'
	. ' echo json_encode(sn_health_check_breeze_active());';
$cr = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $child ) ), true );
ok( is_array( $cr ) && 1 === $cr['count'] && 'advanced-cache.php' === $cr['findings'][0]['subject_label'] && false !== strpos( $cr['fix_hint'], 'WP Rocket' ) && false === strpos( $cr['fix_hint'], 'Deactivate Breeze' ), 'WP_CACHE true with a drop-in (child process): red, and the hint names the drop-in\'s owner' );
unlink( $content . '/advanced-cache.php' );
rmdir( $content );

echo "\nGroup 5: registered in all four places\n";
$root = dirname( __DIR__ );
ok( false !== strpos( (string) file_get_contents( $root . '/signal-and-noise-tools.php' ), 'inc/health-check-breeze-active.php' ), 'the bootstrap loads it' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/health-checks.php' ), "'breeze_active'" ), 'the scan runs it' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/health-check-surfaces.php' ), "'breeze_active'" ), 'a surface owns it' );
ok( false !== strpos( (string) file_get_contents( $root . '/inc/health-check-families.php' ), "'breeze_active'" ), 'a family maps it' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
