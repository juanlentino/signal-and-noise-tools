<?php
/**
 * Standalone test: file integrity, read-only (2026-10-18).
 *
 * Nothing compared core files with checksums. Once a day the plugin now
 * compares them with core's own get_core_checksums(), and plugins hosted on
 * wordpress.org with the plugin checksums API. Plugins not on wordpress.org
 * are counted "not checkable", silently. A capped budget keeps a run from
 * hanging. It stores counts and relative paths only, never contents. The
 * file_integrity watch ripens on a modified file, an unexpected PHP file in
 * wp-admin/ or wp-includes/, unreadable core checksums, an incomplete core
 * pass, or two days of silence.
 *
 * Run: php tests/file-integrity.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_FILE_INTEGRITY_TEST', true );
const DAY_IN_SECONDS = 86400;
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
require dirname( __DIR__ ) . '/inc/file-integrity.php';
require dirname( __DIR__ ) . '/inc/file-integrity-plugins.php';
require dirname( __DIR__ ) . '/inc/file-integrity-watch.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// A real tree: core files, one plugin, under the temp dir.
$root = sys_get_temp_dir() . '/sn-fi-' . getmypid();
$made = array();
function put( $rel, $body ) { global $root, $made; $p = "$root/$rel"; if ( ! is_dir( dirname( $p ) ) ) { mkdir( dirname( $p ), 0777, true ); } file_put_contents( $p, $body ); $made[] = $p; }
$core = array( 'index.php' => '<?php // index', 'wp-includes/load.php' => '<?php // load', 'wp-admin/admin.php' => '<?php // admin', 'readme.html' => 'readme', 'wp-content/themes/twenty/style.css' => 'theme' );
$sums = array();
foreach ( $core as $rel => $body ) { put( $rel, $body ); $sums[ $rel ] = md5( $body ); }
put( 'wp-content/plugins/akismet/akismet.php', '<?php // akismet' );
put( 'wp-content/plugins/akismet/class.php', '<?php // class' );
$plug = array( 'akismet.php' => array( 'md5' => md5( '<?php // akismet' ), 'sha256' => hash( 'sha256', '<?php // akismet' ) ), 'class.php' => array( 'md5' => array( 'stale', md5( '<?php // class' ) ) ) );

$plugins = array( 'akismet/akismet.php' => array( 'Version' => '5.3', 'UpdateURI' => '' ), 'signal-and-noise-tools/signal-and-noise-tools.php' => array( 'Version' => '1', 'UpdateURI' => 'https://github.com/x/y' ), 'private-one/private-one.php' => array( 'Version' => '2', 'UpdateURI' => '' ) );
$updates = (object) array( 'response' => array(), 'no_update' => array( 'akismet/akismet.php' => (object) array( 'slug' => 'akismet' ) ) );
$deps = static function ( array $over = array() ) use ( $root, $sums, $plug, $plugins, $updates ) {
	return array_merge( array(
		'root'             => $root,
		'plugin_dir'       => "$root/wp-content/plugins",
		'version'          => '7.1',
		'locale'           => 'en_US',
		'core_checksums'   => static fn( $v, $l ) => $sums,
		'plugins'          => static fn() => $plugins,
		'updates'          => static fn() => $updates,
		'plugin_checksums' => static fn( $slug, $ver ) => 'akismet' === $slug ? $plug : null,
		'now'              => 1000,
		'max_files'        => 5000,
		'seconds'          => 20,
	), $over );
};

// Clean tree.
$r = snt_fi_run( $deps() );
ok( 4 === $r['core']['checked'] && 0 === $r['core']['modified'] && 0 === $r['core']['added'] && $r['core']['complete'], 'a clean core reads clean; wp-content/ is not core\'s to judge' );
ok( 2 === $r['plugins']['checked'] && 0 === $r['plugins']['modified'], 'the wordpress.org plugin is checked file by file (sha256 when given, md5 lists accepted)' );
ok( 2 === $r['plugins']['not_checkable'], 'this site\'s private plugin and one not on wordpress.org are "not checkable", silently' );
ok( ! snt_watch_ripe_file_integrity( array(), 2000 )['ripe'], 'the watch is quiet on a clean run' );

// Tampering.
put( 'wp-includes/load.php', '<?php // tampered xyzzy load' );
put( 'wp-includes/wp-shell.php', '<?php // dropped' );
put( 'wp-content/plugins/akismet/class.php', '<?php // changed' );
unlink( "$root/readme.html" );
$r = snt_fi_run( $deps() );
ok( 1 === $r['core']['modified'] && 1 === $r['core']['added'] && 1 === $r['core']['missing'], 'a modified core file, an added PHP file and a missing file are each counted' );
ok( 1 === $r['plugins']['modified'], 'a modified plugin file is counted' );
ok( in_array( 'wp-includes/load.php', $r['paths'], true ) && in_array( 'wp-includes/wp-shell.php', $r['paths'], true ) && in_array( 'plugins/akismet/class.php', $r['paths'], true ), 'relative paths are kept for what changed' );
$stored = (string) json_encode( get_option( SN_FI_OPT ) );
ok( false === strpos( $stored, $root ) && false === strpos( $stored, 'xyzzy' ), 'the stored result holds no absolute path and no file contents' );
$w = snt_watch_ripe_file_integrity( array(), 2000 );
ok( $w['ripe'] && false !== strpos( $w['note'], 'wp-includes/wp-shell.php' ), 'the watch is ripe and names the files' );

// A missing file alone does not ripen it (hosts delete readme.html).
put( 'wp-includes/load.php', '<?php // load' ); unlink( "$root/wp-includes/wp-shell.php" ); put( 'wp-content/plugins/akismet/class.php', '<?php // class' );
snt_fi_run( $deps() );
ok( ! snt_watch_ripe_file_integrity( array(), 2000 )['ripe'], 'a missing core file alone stays quiet' );

// Blind or silent runs are never clean.
snt_fi_run( $deps( array( 'core_checksums' => static fn( $v, $l ) => false ) ) );
ok( snt_watch_ripe_file_integrity( array(), 2000 )['ripe'], 'ripe when core checksums could not be fetched' );
snt_fi_run( $deps( array( 'max_files' => 2 ) ) );
ok( snt_watch_ripe_file_integrity( array(), 2000 )['ripe'], 'ripe when the budget ran out inside core' );
$r = snt_fi_run( $deps( array( 'max_files' => 6 ) ) );
ok( $r['core']['complete'] && $r['plugins']['unfinished'] > 0 && ! snt_watch_ripe_file_integrity( array(), 2000 )['ripe'], 'a budget that runs out among plugins is counted unfinished, not ripe' );
snt_fi_run( $deps() );
ok( snt_watch_ripe_file_integrity( array(), 1000 + 3 * DAY_IN_SECONDS )['ripe'], 'ripe when the check has not run for two days' );
$r = snt_fi_run( $deps( array( 'plugin_checksums' => static fn( $s, $v ) => false ) ) );
ok( 1 === $r['plugins']['errors'] && ! snt_watch_ripe_file_integrity( array(), 2000 )['ripe'], 'a plugin checksum fetch error is counted, not ripe' );
unset( $GLOBALS['__opt'][ SN_FI_OPT ] );
ok( ! snt_watch_ripe_file_integrity( array(), 2000 )['ripe'], 'not ripe before the first run' );

// Cold update transient: fall back to the folder name when no Update URI is set.
$t = snt_fi_plugin_targets( $plugins, null );
ok( isset( $t['akismet/akismet.php'] ) && isset( $t['private-one/private-one.php'] ) && ! isset( $t['signal-and-noise-tools/signal-and-noise-tools.php'] ), 'with no update data, folders without an Update URI are tried (a 404 makes them not checkable)' );

// Caps.
ok( SN_FI_PATH_CAP <= 20, 'the path list is capped' );

foreach ( array_reverse( $made ) as $p ) { if ( is_file( $p ) ) { unlink( $p ); } }
foreach ( array( 'wp-content/plugins/akismet', 'wp-content/plugins', 'wp-content/themes/twenty', 'wp-content/themes', 'wp-content', 'wp-includes', 'wp-admin', '' ) as $d ) { if ( is_dir( "$root/$d" ) ) { rmdir( "$root/$d" ); } }

$src = dirname( __DIR__ );
ok( false !== strpos( (string) file_get_contents( "$src/inc/watches.php" ), "'file_integrity'" ), 'the watch is registered' );
ok( false !== strpos( (string) file_get_contents( "$src/inc/cron-dashboard.php" ), 'SN_FI_HOOK' ), 'the daily job is in the cron dashboard registry' );
ok( false !== strpos( (string) file_get_contents( "$src/inc/cron-lifecycle.php" ), 'SN_FI_HOOK' ), 'and cleared on deactivation' );
ok( false !== strpos( (string) file_get_contents( "$src/signal-and-noise-tools.php" ), 'inc/file-integrity-watch.php' ), 'the module is loaded' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
