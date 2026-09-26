<?php
/**
 * The forms spam rules, against entries copied from the contact form's real
 * spam and against plausible real inquiries that must pass.
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['snt_fs_filters'] = array();
function __( $s ) { return $s; }
function add_action() {}
function add_filter( $h, $cb ) { $GLOBALS['snt_fs_filters'][ $h ] = $cb; }
function apply_filters( $h, $v ) { return $v; }
require __DIR__ . '/../inc/forms-spam.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Forms spam rules\n\n";

$schema = array( 'fields' => array(
	array( 'id' => 'f1', 'type' => 'text', 'label' => 'Your name' ),
	array( 'id' => 'f2', 'type' => 'email', 'label' => 'Your email' ),
	array( 'id' => 'f3', 'type' => 'text', 'label' => 'Publication or institution' ),
	array( 'id' => 'f4', 'type' => 'text', 'label' => 'Outlet' ),
	array( 'id' => 'f5', 'type' => 'text', 'label' => 'Angle' ),
	array( 'id' => 'f6', 'type' => 'textarea', 'label' => 'Message' ),
) );
$reason = function ( $v ) use ( $schema ) { return snt_fs_reason( snt_fs_signals( $v, $schema ) ); };

// Spam, as it arrived.
$r = $reason( array( 'f1' => "\u{1F535}\u{1F7E1} You have A NEW MESSAGE #H3084 OPEN - graph.org/Bitcoin-Cloud-Mining-08-27?hs=ed34986006d", 'f2' => 'vnrwevcjq@emlpro.com', 'f3' => '6350le', 'f4' => 'l77264', 'f5' => 'r29any', 'f6' => 'You have A NEW MESSAGE' ) );
ok( '' !== $r && false !== strpos( $r, 'link_in_name' ) && false !== strpos( $r, 'lure' ), "the crypto lure is caught with its reasons ($r)" );
ok( '' !== $reason( array( 'f1' => 'RobertBiB RonaldBiBGM', 'f2' => 'a@b.co', 'f6' => 'hello' ) ), 'a bot name alone is caught' );
ok( '' !== $reason( array( 'f1' => 'NATREGTEGH475080NEHTYHYHTR', 'f2' => 'a@b.co', 'f6' => 'x' ) ), 'an all-caps digit run for a name is caught' );
ok( '' !== $reason( array( 'f1' => 'Darby', 'f2' => 'x@emlpro.com', 'f3' => 'a6ulx7', 'f4' => '98775w', 'f5' => 'giuz73' ) ), 'two weak signals together (throwaway mail + random answers) are caught' );

// Real inquiries: must pass.
ok( '' === $reason( array( 'f1' => 'María García', 'f2' => 'maria@uni.edu', 'f3' => 'Universidad de Chile', 'f6' => 'I read https://juanlentino.com/notes/two-kinds-of-provenance/ and would like to cite it.' ) ), 'a real inquiry with a link in the MESSAGE passes' );
ok( '' === $reason( array( 'f1' => 'Sam McDonald', 'f2' => 's@gmail.com', 'f4' => 'Wired', 'f6' => 'Interview request about C2PA.' ) ), 'a camel-case surname (McDonald) passes' );
ok( '' === $reason( array( 'f1' => 'Ana 🙂', 'f2' => 'ana@gmail.com', 'f6' => 'Loved the note on ISRC and ISWC.' ) ), 'one weak signal alone (an emoji in the name) passes' );
ok( '' === $reason( array( 'f1' => 'Lee', 'f2' => 'lee@x.io', 'f3' => 'MIT', 'f4' => 'AES', 'f5' => 'JAES 2026' ) ), 'short real answers (MIT, AES) are not random tokens' );

// The filter: never overrides Forms' own verdict, and adds ours.
$f = $GLOBALS['snt_fs_filters']['alltfo_spam_verdict'];
$own = array( 'spam' => true, 'reason' => 'honeypot' );
ok( $own === $f( $own, $schema, array( 'f1' => 'María' ) ), "Forms' own spam verdict passes through untouched" );
$v = $f( array( 'spam' => false, 'reason' => '' ), $schema, array( 'f1' => 'RobertBiB RonaldBiBGM' ) );
ok( true === $v['spam'] && 0 === strpos( $v['reason'], 'snt:' ), 'a clean Forms verdict on a bot name becomes spam, reason snt:*' );
ok( array( 'spam' => false, 'reason' => '' ) === $f( array( 'spam' => false, 'reason' => '' ), $schema, array( 'f1' => 'María' ) ), 'a clean entry stays clean' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
