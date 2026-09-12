<?php
/**
 * Regression test (#1228): sn_rss_tracker_handle_form()'s post-save redirect
 * must target admin.php, not the stale themes.php — the SN admin page moved
 * from Appearance (add_theme_page) to its own top-level menu (add_menu_page,
 * inc/admin-menu.php) long ago. themes.php ignores an unrecognized ?page=,
 * so the old target silently stranded the owner on the Themes screen after
 * every RSS-tab save/purge/reset.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}
function current_user_can( $c ) { return true; }
function wp_verify_nonce( $n, $a ) { return true; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $v ) { return $v; }
$GLOBALS['__options'] = array();
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function update_option( $k, $v ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }

class SN_RSS_Redirect extends Exception {
	public $url;
	public function __construct( $url ) { parent::__construct( 'redirect' ); $this->url = $url; }
}
function wp_safe_redirect( $url ) { throw new SN_RSS_Redirect( $url ); }

require __DIR__ . '/../inc/rss-feed-tracker.php';

$_POST = array(
	'sn_rss_action' => 'reset_defaults',
	'_wpnonce'      => 'x',
);
try {
	sn_rss_tracker_handle_form();
	ok( false, 'expected a redirect to be thrown' );
} catch ( SN_RSS_Redirect $r ) {
	ok( 0 === strpos( $r->url, 'https://example.test/wp-admin/admin.php?' ), 'redirect targets admin.php, not themes.php (#1228): ' . $r->url );
	ok( false === strpos( $r->url, 'themes.php' ), 'themes.php never appears in the redirect target' );
	ok( false !== strpos( $r->url, 'page=sn-theme-options' ) && false !== strpos( $r->url, 'tab=rss' ), 'the page/tab params still land on the RSS sub-tab' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
