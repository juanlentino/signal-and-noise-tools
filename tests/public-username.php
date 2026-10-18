<?php
/**
 * Standalone test: is an administrator's login username public? (2026-10-18)
 *
 * The site locks /wp-json/wp/v2/users and the ?author= scan, but the oEmbed
 * endpoint publishes author_url = /notes/author/<user_nicename>/, and that
 * archive answers 200. Core derives user_nicename from user_login at creation
 * (sanitize_title of it), so unless someone changed it, the author slug IS the
 * login username. The watch and the Site Health row compare the two core user
 * fields for every administrator and never print either value.
 *
 * Run: php tests/public-username.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_PUBLIC_USERNAME_TEST', true );
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $s ) ), '-' ); }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__admins'] = array();
function get_users( $a ) { return 'administrator' === ( $a['role'] ?? '' ) ? $GLOBALS['__admins'] : array(); }
function u( $id, $login, $nice ) { return (object) array( 'ID' => $id, 'user_login' => $login, 'user_nicename' => $nice ); }
require dirname( __DIR__ ) . '/inc/security-public-username.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function leaks( $text, array $values ) { foreach ( $values as $v ) { if ( false !== stripos( (string) $text, $v ) ) { return true; } } return false; }

// The comparison.
ok( snt_public_username_matches( 'ownerx', 'ownerx' ), 'a slug equal to the login is public' );
ok( snt_public_username_matches( 'OwnerX', 'ownerx' ), 'case does not hide it' );
ok( snt_public_username_matches( 'owner.x', 'owner-x' ), 'nor does core slugging it (sanitize_title)' );
ok( ! snt_public_username_matches( 'ownerx', 'quiet-writer' ), 'a changed slug is private' );
ok( ! snt_public_username_matches( '', '' ), 'empty fields are never a finding' );

// The verdict over every administrator.
$v = snt_public_username_verdict( array( u( 1, 'ownerx', 'ownerx' ), u( 7, 'helper.two', 'helper-two' ), u( 9, 'third', 'pen-name' ) ) );
ok( $v['ripe'] && 2 === $v['public'] && 3 === $v['admins'], 'two of three administrators have a public login' );
ok( false !== strpos( $v['note'], 'user IDs 1, 7:' ) && false === strpos( $v['note'], '9' ), 'the note names the users by ID only' );
ok( ! leaks( $v['note'], array( 'ownerx', 'helper', 'pen-name', 'third' ) ), 'the note contains neither field, for any administrator' );
ok( false !== strpos( $v['note'], 'user_nicename' ) && false !== strpos( $v['note'], 'wp user update' ), 'and says the core way to change it (no profile field sets it)' );
$v = snt_public_username_verdict( array( u( 9, 'third', 'pen-name' ) ) );
ok( ! $v['ripe'] && 0 === $v['public'], 'quiet when every slug differs from its login' );
ok( ! snt_public_username_verdict( array() )['ripe'], 'no administrators is quiet' );

// The watch reads core live.
$GLOBALS['__admins'] = array( u( 3, 'Site.Owner', 'site-owner' ) );
$w = snt_watch_ripe_public_username( array(), 0 );
ok( $w['ripe'] && ! leaks( $w['note'], array( 'site', 'owner' ) ), 'the watch is ripe on the live read and leaks neither value' );
$GLOBALS['__admins'] = array( u( 3, 'Site.Owner', 'byline' ) );
ok( ! snt_watch_ripe_public_username( array(), 0 )['ripe'], 'and quiet once the slug is changed' );

// Site Health: a direct test, Security badge, counts only.
$t = snt_public_username_register_site_health_test( array() );
ok( 'snt_public_username_site_health_result' === ( $t['direct']['sn_public_username']['test'] ?? '' ), 'a DIRECT Site Health test named sn_public_username' );
$GLOBALS['__admins'] = array( u( 3, 'Site.Owner', 'site-owner' ), u( 4, 'other', 'byline' ) );
$row = snt_public_username_site_health_result();
ok( 'recommended' === $row['status'] && 'sn_public_username' === $row['test'] && 'Security' === $row['badge']['label'], 'recommended while a login is public' );
ok( false !== strpos( $row['description'], '1 of 2' ) && ! leaks( $row['description'] . $row['label'], array( 'site', 'owner', 'byline', 'other' ) ), 'it counts, and names nobody' );
$GLOBALS['__admins'] = array( u( 4, 'other', 'byline' ) );
ok( 'good' === snt_public_username_site_health_result()['status'], 'good when no administrator\'s slug is their login' );

$root = dirname( __DIR__ );
ok( false !== strpos( (string) file_get_contents( "$root/inc/watches.php" ), "'public_username'" ), 'the watch is registered' );
ok( false !== strpos( (string) file_get_contents( "$root/signal-and-noise-tools.php" ), 'inc/security-public-username.php' ), 'the module is loaded' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
