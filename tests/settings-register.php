<?php
/**
 * Pin: sn_settings is declared to core with register_setting() on init, and
 * its sanitize_callback is the whole-array sanitiser (#1612).
 *
 * Before 17.4.4 no register_setting() call existed, so a write from WP-CLI, a
 * migration or an ability bypassed every sanitiser. The registration binds
 * sn_settings_sanitize_option() to sanitize_option_sn_settings, which
 * update_option() and add_option() both run through.
 *
 * Standalone CLI fixture: add_action records hooks, register_setting records
 * its args, then the recorded init callbacks run.
 *
 * Run: php tests/settings-register.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

$GLOBALS['__hooks']      = array();
$GLOBALS['__registered'] = array();
function add_action( $hook, $cb ) { $GLOBALS['__hooks'][ $hook ][] = $cb; }
function register_setting( $group, $name, $args = array() ) {
	$GLOBALS['__registered'][ $name ] = array( 'group' => $group, 'args' => $args );
}
function get_option( $name, $default = false ) { return $default; }
function update_option( $name, $value ) { return true; }
function get_bloginfo( $what ) { return 'name' === $what ? 'TestSite' : ''; }
// Real enough: wp_strip_all_tags drops script and style bodies, then tags; textarea keeps newlines.
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
// Core's _sanitize_text_fields octet loop: every %xx is dropped, which is why
// URL leaves must not route through either field sanitiser.
function __sn_strip_octets( $s ) {
	while ( preg_match( '/%[a-f0-9]{2}/i', $s, $m ) ) { $s = str_replace( $m[0], '', $s ); }
	return $s;
}
function sanitize_textarea_field( $s ) { return __sn_strip_octets( wp_strip_all_tags( $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', __sn_strip_octets( wp_strip_all_tags( $s ) ) ) ); }
function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); }
// Real enough: esc_url keeps %xx and wp_kses_bad_protocol drops a scheme it does not allow.
function esc_url_raw( $s ) { return preg_replace( '#^(?!https?:)[a-z][a-z0-9+.-]*:#i', '', trim( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }

require __DIR__ . '/../inc/settings.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; } else { $fail++; echo "  FAIL - $label\n"; }
}

// 1. The registration: on init, a private group, a callable sanitiser, no REST.
foreach ( $GLOBALS['__hooks']['init'] ?? array() as $cb ) { call_user_func( $cb ); }
$reg = $GLOBALS['__registered']['sn_settings'] ?? null;
ok( null !== $reg, 'sn_settings is registered from an init callback' );
ok( isset( $GLOBALS['__hooks']['init'] ) && ! isset( $GLOBALS['__hooks']['admin_init'] ), 'the hook is init, not admin_init' );
ok( 'sn_private' === ( $reg['group'] ?? '' ), 'the group is sn_private' );
ok( 'object' === ( $reg['args']['type'] ?? '' ), 'type is object' );
ok( false === ( $reg['args']['show_in_rest'] ?? null ), 'show_in_rest is false' );
ok( ! array_key_exists( 'default', (array) ( $reg['args'] ?? array() ) ), 'no default: sn_setting() merges the defaults' );
$cb = $reg['args']['sanitize_callback'] ?? null;
ok( is_callable( $cb ), 'sanitize_callback is callable' );
if ( ! is_callable( $cb ) ) {
	// Unfixed code: nothing registered. Count every leaf assertion red instead of dying.
	$cb = function () { return null; };
}

// 2. The sanitiser, on the tree the issue names.
$in = array(
	'identity'        => array( 'site_name' => '<script>x</script>Juan', 'job_title' => 'Producer' ),
	'machine_readers' => array( 'read_token' => 'tok' ),
	'perf'            => array( 'speculative_loading' => '1' ),
	'ml'              => array( 'embeddings_token' => '<not-a-tag>', 'related_source' => ' tfidf ' ),
	'search_console'  => array( 'gsc_credential' => "{\n \"private_key\": \"<pem>\"\n}" ),
	'seo_copy'        => array( 'home_description' => "line one\nline two" ),
	'theme'           => array( 'related_count' => '4', 'jev_credit' => 7.5, 'ai_monthly_budget' => '12.25' ),
	'analytics'       => array( 'exclude_roles' => array( 'editor' ), 'funnels' => array( array( 'steps' => array( '/a' ) ) ) ),
	'monitoring'      => array( 'uptime_token' => 'keep' ),
	'login'           => array( 'slug' => 42 ),
	'og'              => array( 'default_image_url' => ' https://cdn.example.com/covers/caf%C3%A9%20noir.jpg ' ),
);
$out = (array) call_user_func( $cb, $in );
ok( 'Juan' === ( $out['identity']['site_name'] ?? null ), 'a known string leaf is sanitised' );
ok( 'Producer' === ( $out['identity']['job_title'] ?? null ), 'an unknown key survives (not a whitelist)' );
ok( 'tok' === ( $out['machine_readers']['read_token'] ?? null ), 'an undeclared subtree survives' );
ok( true === ( $out['perf']['speculative_loading'] ?? null ), 'a bool leaf is coerced from "1"' );
ok( '<not-a-tag>' === ( $out['ml']['embeddings_token'] ?? null ), 'a token leaf passes through byte for byte' );
ok( 'tfidf' === ( $out['ml']['related_source'] ?? null ), 'a sibling string leaf is trimmed' );
ok( $in['search_console']['gsc_credential'] === ( $out['search_console']['gsc_credential'] ?? null ), 'the GSC credential JSON passes through byte for byte' );
ok( "line one\nline two" === ( $out['seo_copy']['home_description'] ?? null ), 'a textarea leaf keeps its newlines' );
ok( 4 === ( $out['theme']['related_count'] ?? null ), 'an int leaf is coerced from "4"' );
ok( 7.5 === ( $out['theme']['jev_credit'] ?? null ), 'a float on an int-defaulted leaf is kept' );
ok( 12.25 === ( $out['theme']['ai_monthly_budget'] ?? null ), 'a numeric string on an int-defaulted leaf becomes a float' );
ok( array( 'editor' ) === ( $out['analytics']['exclude_roles'] ?? null ), 'an empty-default list passes through' );
ok( $in['analytics']['funnels'] === ( $out['analytics']['funnels'] ?? null ), 'the funnels list passes through' );
ok( array( 'uptime_token' => 'keep' ) === ( $out['monitoring'] ?? null ), 'the monitoring subtree passes through' );
ok( '42' === ( $out['login']['slug'] ?? null ), 'a string leaf handed an int becomes a string' );
ok( 'https://cdn.example.com/covers/caf%C3%A9%20noir.jpg' === ( $out['og']['default_image_url'] ?? null ), 'a _url leaf keeps its percent-encoded octets (esc_url_raw, not the field sanitisers)' );
$prop = call_user_func( $cb, array( 'search_console' => array( 'property' => 'https://example.com/caf%C3%A9/' ) ) );
ok( 'https://example.com/caf%C3%A9/' === ( $prop['search_console']['property'] ?? null ), 'a percent-encoded URL-prefix property survives the strict re-read' );
$prop = call_user_func( $cb, array( 'search_console' => array( 'property' => 'sc-domain:example.com' ) ) );
ok( 'sc-domain:example.com' === ( $prop['search_console']['property'] ?? null ), 'a domain property keeps its sc-domain scheme' );
ok( array() === call_user_func( $cb, 'not an array' ), 'a non-array write becomes an empty array' );
ok( array() !== $out && $out === call_user_func( $cb, $out ), 'the sanitiser is a fixed point on its own output' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
