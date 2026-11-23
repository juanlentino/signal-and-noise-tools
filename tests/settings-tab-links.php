<?php
/**
 * Every link to the Settings tab names ONE page slug.
 *
 * A classic S&N admin URL is `admin.php?page=<slug>&tab=<tab>&sub=<leaf>`.
 * `?tab=` picks what is painted (sn_admin_page_active_tab()), so a link with
 * the wrong `page=` still opens the right pane: nothing looks broken. What
 * `page=` decides is the sidebar item WordPress lights and the page a save
 * posted from there returns to. Six pointers to Settings > Accounts and
 * connections named `sn-tools` (2026-11), so the pane opened with Tools lit.
 *
 * The one spelling is `page=sn-theme-options&tab=settings`, the one
 * snt_desktop_admin_url(), the tab strip, the legacy 301 and every other
 * pointer write. This suite reads the source of inc/ and apps/ and fails on
 * any other slug beside `tab=settings`.
 *
 * Run: php tests/settings-tab-links.php
 */

const STL_SLUG = 'sn-theme-options';

$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * Every `page=<slug>` written beside `tab=settings` in one source text.
 *
 * @param string $src PHP or JS source.
 * @return string[] The slugs, one per link.
 */
function stl_slugs( $src ) {
	preg_match_all( '/page=([A-Za-z0-9_-]+)&(?:amp;)?tab=settings\b/', (string) $src, $m );
	return $m[1];
}

$root  = dirname( __DIR__ );
$wrong = array();
$right = 0;
$files = 0;
foreach ( array( 'inc', 'apps' ) as $dir ) {
	$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $walk as $file ) {
		if ( ! in_array( $file->getExtension(), array( 'php', 'js', 'mjs' ), true ) ) {
			continue;
		}
		++$files;
		foreach ( stl_slugs( file_get_contents( $file->getPathname() ) ) as $slug ) {
			if ( STL_SLUG === $slug ) {
				++$right;
			} else {
				$wrong[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ' (' . $slug . ')';
			}
		}
	}
}

ok( $files > 500, "the scan read the source of inc/ and apps/: $files files" );
ok( $right >= 10, "it sees the links it is about: $right name page=" . STL_SLUG . ' beside tab=settings' );
ok( array() === $wrong, 'no link to the Settings tab names another page slug' . ( $wrong ? ': ' . implode( ', ', $wrong ) : '' ) );
ok( array( 'sn-tools', 'sn-settings' ) === stl_slugs( "admin.php?page=sn-tools&tab=settings&sub=accounts admin.php?page=sn-settings&amp;tab=settings" ) && array() === stl_slugs( 'admin.php?page=sn-tools&tab=tools' ), 'the guard can fail: it reads the slug of a plain and an escaped link, and leaves another tab alone' );

// The six pointers that named sn-tools now ask the pane for its address.
define( 'ABSPATH', $root . '/' );
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function __( $t, $d = null ) { return $t; }
require_once $root . '/inc/admin-forms/settings-accounts-pointers.php';
ok( 'https://example.test/wp-admin/admin.php?page=' . STL_SLUG . '&tab=settings&sub=accounts' === snt_settings_accounts_url(), 'the Accounts pane gives its address with that slug' );
$calls = 0;
foreach ( array( 'inc/cloudflare-purge-tab-parts.php', 'inc/webhooks-admin.php', 'inc/machine-readers-admin.php', 'inc/admin-forms/music.php', 'inc/admin-forms/ai-settings.php' ) as $rel ) {
	$calls += substr_count( (string) file_get_contents( $root . '/' . $rel ), '<a href="\' . esc_url( snt_settings_accounts_url() ) . \'">' );
}
ok( 6 === $calls, "the six pointers to the pane link through snt_settings_accounts_url(): $calls" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
