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

// The Spam folder's four that 18.8.2 missed (2026-09-26), as submitted.
$pitch = "Hello,\n\nI am Buddy Sambell from FreeB2BData\n\nIt is with sad regret to inform you that we are shutting down\n\nWe have over 252 countries and over 33 million companies available in our database with phone numbers, industries, emails, etc.\n\nPlease come and download your data in the next 24 hours.\n\nhttps://Buddy.freeb2bdata.org";
$schema_full = array( 'fields' => array_merge( $schema['fields'], array( array( 'id' => 'f7', 'type' => 'text', 'label' => 'Timeline' ), array( 'id' => 'f8', 'type' => 'text', 'label' => 'Company' ) ) ) );
$r2 = function ( $v ) use ( $schema_full ) { return snt_fs_reason( snt_fs_signals( $v, $schema_full ) ); };
ok( '' !== $r2( array( 'f1' => 'Buddy Sambell', 'f2' => 'info@freeb2bdata.org', 'f6' => $pitch, 'f7' => 'Buddy', 'f8' => 'Buddy Sambell' ) ), 'Buddy Sambell: the bought-database pitch is caught' );
ok( '' !== $r2( array( 'f1' => 'Darby Vang', 'f2' => 'info@freeb2bdata.org', 'f6' => str_replace( 'Buddy Sambell', 'Darby Vang', $pitch ), 'f7' => 'Darby Vang', 'f8' => 'Darby Vang' ) ), 'Darby Vang: the same template is caught' );
ok( '' !== $r2( array( 'f1' => 'Debra Rodd', 'f2' => 'rodd.debra@gmail.com', 'f6' => "Hello there from BonusBacklinks,\nBest quality seo backlinks to super grow your website backlinks!\nTake 85% Discount\nPrice as low as $1", 'f7' => 'Debra Rodd', 'f8' => 'Debra Rodd' ) ), 'Debra Rodd: the backlinks pitch is caught' );
ok( '' !== $r2( array( 'f1' => 'DAvid LIN', 'f2' => 'support@decentgears.com', 'f3' => 'David Lin', 'f4' => 'N/A', 'f5' => 'Replica Rolex watches, cash on delivery', 'f6' => 'I would like to inquire about your services.' ) ), 'DAvid LIN: the replica watches pitch is caught' );
ok( '' === $r2( array( 'f1' => 'Ana Ruiz', 'f2' => 'ana@studio.com', 'f7' => 'Ana Ruiz', 'f8' => 'Ana Ruiz', 'f6' => 'Could we talk about provenance for my label?' ) ), 'a freelancer whose Company is her own name, filling a required field with it, passes' );
ok( '' === $r2( array( 'f1' => 'Kim Park', 'f2' => 'kim@uni.edu', 'f8' => 'Seoul National University', 'f6' => 'Your note on detection mentions SEO for music catalogs; could we talk about metadata and discoverability?' ) ), 'a real inquiry that mentions SEO passes (the pitch needs its selling words)' );

// The contact form's real shape (Contact (spec), exported 2026-09-26): fields
// shown by "intent is <X>", and Message shown unless Other or Panacea.
$show = function ( $id, $label, $intent ) { return array( 'id' => $id, 'type' => 'text', 'label' => $label, 'logic' => array( 'enabled' => true, 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'intent', 'operator' => 'is', 'value' => $intent ) ) ) ); };
$contact = array( 'fields' => array(
	array( 'id' => 'name', 'type' => 'name', 'label' => 'Your name' ),
	array( 'id' => 'email', 'type' => 'email', 'label' => 'Your email' ),
	array( 'id' => 'intent', 'type' => 'select', 'label' => 'What is this about?' ),
	$show( 'research_org', 'Publication or institution', 'Research' ), $show( 'research_paper', 'Paper', 'Research' ),
	$show( 'press_outlet', 'Outlet', 'Press' ), $show( 'press_angle', 'Angle', 'Press' ),
	$show( 'speak_event', 'Event', 'Speaking' ), $show( 'speak_need', 'What you need from me', 'Speaking' ),
	$show( 'music_stage', 'Stage', 'Music' ), $show( 'music_timeline', 'Timeline', 'Music' ),
	$show( 'role_company', 'Company', 'Role' ),
	array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message', 'logic' => array( 'enabled' => true, 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'intent', 'operator' => 'is_not', 'value' => 'Other' ), array( 'field' => 'intent', 'operator' => 'is_not', 'value' => 'Panacea' ) ) ) ),
) );
$rc = function ( $v ) use ( $contact ) { return snt_fs_reason( snt_fs_signals( $v, $contact ) ); };
// Darby Vang, as stored: "Something else" (Other), yet answers under Speaking, Music, Role and Message.
$darby = array( 'name' => array( 'first' => 'Darby', 'last' => 'Vang' ), 'email' => 'x@y.org', 'intent' => 'Other', 'speak_need' => 'Hello', 'music_stage' => 'tracking', 'music_timeline' => 'Darby Vang', 'role_company' => 'Darby Vang', 'message' => 'Hello' );
ok( false !== strpos( $rc( $darby ), 'hidden_answers' ), 'answers in hidden branches (Speaking, Music, Role, Message under Other) are caught' );
ok( 4 === snt_fs_hidden_branches( $darby, $contact ), 'four hidden branches counted, one per rule set' );
// A person who picked Press, typed in the Research fields first, then switched: one stray branch.
ok( '' === $rc( array( 'name' => array( 'first' => 'Ana', 'last' => 'Ruiz' ), 'email' => 'a@wired.com', 'intent' => 'Press', 'research_org' => 'Wired', 'press_outlet' => 'Wired', 'press_angle' => 'C2PA', 'message' => 'Interview?' ) ), 'one stray hidden branch (a person who switched their choice) passes' );
ok( '' === $rc( array( 'name' => array( 'first' => 'Lee', 'last' => 'Park' ), 'email' => 'l@uni.edu', 'intent' => 'Research', 'research_org' => 'MIT', 'research_paper' => 'Provenance as Substrate', 'message' => 'Citing it.' ) ), 'a real Research inquiry, every answer visible, passes' );
ok( '' === $rc( array( 'name' => array( 'first' => 'Sol', 'last' => 'Diaz' ), 'email' => 's@x.io', 'intent' => 'Other' ) ), 'Other with nothing hidden filled passes' );
$unknown = $contact; $unknown['fields'][3]['logic']['rules'][0]['operator'] = 'greater';
ok( 0 === snt_fs_hidden_branches( array( 'intent' => 'Other', 'research_org' => 'x' ), $unknown ), 'an operator we do not mirror counts the field visible, never guessed hidden' );

// Real inquiries: must pass.
ok( '' === $reason( array( 'f1' => 'María García', 'f2' => 'maria@uni.edu', 'f3' => 'Universidad de Chile', 'f6' => 'I read https://juanlentino.com/notes/two-kinds-of-provenance/ and would like to cite it.' ) ), 'a real inquiry with a link in the MESSAGE passes' );
ok( '' === $reason( array( 'f1' => 'Sam McDonald', 'f2' => 's@gmail.com', 'f4' => 'Wired', 'f6' => 'Interview request about C2PA.' ) ), 'a camel-case surname (McDonald) passes' );
ok( '' === $reason( array( 'f1' => 'Ana 🙂', 'f2' => 'ana@gmail.com', 'f6' => 'Loved the note on ISRC and ISWC.' ) ), 'one weak signal alone (an emoji in the name) passes' );
ok( '' === $reason( array( 'f1' => 'Lee', 'f2' => 'lee@x.io', 'f3' => 'MIT', 'f4' => 'AES', 'f5' => 'JAES 2026' ) ), 'short real answers (MIT, AES) are not random tokens' );

// 2026-10-16 audit: bitcoin, btc and crypto are weak on their own. This site
// writes about Bitcoin-anchored provenance, so a researcher says "bitcoin".
ok( '' === $reason( array( 'f1' => 'Dana Whitfield', 'f2' => 'dana@ucl.ac.uk', 'f3' => 'UCL', 'f6' => 'I am researching Bitcoin provenance for audio files and how OpenTimestamps anchors a hash in a BTC block. Could we talk about your crypto anchoring?' ) ), 'a genuine research inquiry about bitcoin provenance is NOT flagged' );
$r = $reason( array( 'f1' => 'Mark', 'f2' => 'mark@emlpro.com', 'f6' => 'Earn bitcoin daily' ) );
ok( '' !== $r && false !== strpos( $r, 'crypto_word' ) && false !== strpos( $r, 'throwaway_email' ), "bitcoin beside one other weak signal is still caught ($r)" );
$r = $reason( array( 'f1' => 'Lucky', 'f2' => 'l@x.co', 'f6' => 'You have won 0.5 BTC, claim now' ) );
ok( '' !== $r && false !== strpos( $r, 'lure' ), "classic crypto spam is still caught by its lure ($r)" );
$r = $reason( array( 'f1' => 'Jo', 'f2' => 'jo@x.co', 'f6' => 'Your USDT wallet is ready, crypto bonus inside' ) );
ok( '' !== $r && false !== strpos( $r, 'lure' ), "USDT stays a strong lure ($r)" );

// Stored entries: Forms keeps values as a JSON string. The sweep must decode
// it; reading the string as an array hands the rules nothing (18.8.0 scanned
// 338 entries and flagged none).
$stored = json_encode( array( 'f1' => 'RobertBiB RonaldBiBGM', 'f2' => 'a@b.co' ) );
ok( '' !== snt_fs_reason( snt_fs_signals( snt_fs_entry_values( $stored ), $schema ) ), 'a JSON-string entry, as Forms stores it, is decoded and caught' );
ok( array( 'f1' => 'x' ) === snt_fs_entry_values( array( 'f1' => 'x' ) ), 'an array passes through' );
ok( array() === snt_fs_entry_values( 'not json' ) && array() === snt_fs_entry_values( null ), 'garbage or missing meta reads as no values, never a crash' );

// The filter: never overrides Forms' own verdict, and adds ours.
$f = $GLOBALS['snt_fs_filters']['alltfo_spam_verdict'];
$own = array( 'spam' => true, 'reason' => 'honeypot' );
ok( $own === $f( $own, $schema, array( 'f1' => 'María' ) ), "Forms' own spam verdict passes through untouched" );
$v = $f( array( 'spam' => false, 'reason' => '' ), $schema, array( 'f1' => 'RobertBiB RonaldBiBGM' ) );
ok( true === $v['spam'] && 0 === strpos( $v['reason'], 'snt:' ), 'a clean Forms verdict on a bot name becomes spam, reason snt:*' );
ok( array( 'spam' => false, 'reason' => '' ) === $f( array( 'spam' => false, 'reason' => '' ), $schema, array( 'f1' => 'María' ) ), 'a clean entry stays clean' );

// 2026-10-16 audit: the scan goes to an MCP caller, so its rows carry no
// submitter name or email, only a masked hint. The apply flow still works.
ok( 'j***@example.com' === snt_fs_mask_hint( 'juan.lentino@Example.com' ), 'an email title masks to j***@domain' );
ok( 'M. G.' === snt_fs_mask_hint( 'María García' ), 'a name masks to initials' );
ok( '' === snt_fs_mask_hint( '' ), 'an empty title masks to nothing' );
define( 'ALLTFO_ENTRY_TYPE', 'alltfo_entry' ); define( 'ALLTFO_FORM_TYPE', 'alltfo_form' ); define( 'ALLTFO_META_VALUES', '_v' ); define( 'ALLTFO_META_FORM', '_f' );
define( 'ALLTFO_STATUS_UNREAD', 'unread' ); define( 'ALLTFO_STATUS_READ', 'read' ); define( 'ALLTFO_STATUS_SPAM', 'spam' );
$GLOBALS['__entries'] = array( 11 => array( 'title' => 'RobertBiB RonaldBiBGM', 'v' => array( 'f1' => 'RobertBiB RonaldBiBGM' ), 'status' => 'unread' ), 12 => array( 'title' => 'spammer@emlpro.com', 'v' => array( 'f1' => 'NATREGTEGH475080NEHTYHYHTR', 'f2' => 'spammer@emlpro.com' ), 'status' => 'spam' ), 13 => array( 'title' => 'María García', 'v' => array( 'f1' => 'María García' ), 'status' => 'read' ) );
$GLOBALS['__set'] = array();
function alltfo_get_form_schema( $f ) { return $GLOBALS['schema_for_scan']; }
function alltfo_set_entry_status( $id, $st ) { $GLOBALS['__set'][ $id ] = $st; return true; }
function get_posts( $q ) {
	if ( ALLTFO_FORM_TYPE === $q['post_type'] ) { return array( (object) array( 'ID' => 5, 'post_title' => 'Contact' ) ); }
	return array_keys( array_filter( $GLOBALS['__entries'], static function ( $e ) use ( $q ) { return in_array( $e['status'], (array) $q['post_status'], true ); } ) );
}
function get_post_meta( $id, $k, $single = false ) { return ALLTFO_META_FORM === $k ? 5 : json_encode( $GLOBALS['__entries'][ $id ]['v'] ); }
function get_the_title( $id ) { return $GLOBALS['__entries'][ $id ]['title']; }
function get_post_time( $f, $gmt, $id ) { return '2026-10-16T00:00:00+00:00'; }
function is_wp_error( $x ) { return false; }
$GLOBALS['schema_for_scan'] = $schema;
$scan = snt_fs_scan();
$row  = $scan['flagged'][0] ?? array();
ok( 1 === count( $scan['flagged'] ) && 11 === $row['id'], 'the scan flags the bot entry' );
ok( array( 'id', 'form', 'hint', 'date', 'reason' ) === array_keys( $row ) && 'R. R.' === $row['hint'], 'a flagged row is id, form, hint, date and reason; no title' );
$folder = $scan['spam_folder']['caught_rows'][0] ?? array();
ok( ! isset( $folder['title'] ) && 's***@emlpro.com' === $folder['hint'], 'a Spam-folder row carries the masked hint, not the email' );
$json = json_encode( $scan );
ok( false === strpos( $json, 'RobertBiB' ) && false === strpos( $json, 'spammer@' ) && false === strpos( $json, 'Garc' ), 'no submitter name or email anywhere in the scan output' );
$applied = snt_fs_apply( array( 'entry_ids' => array( 11, 13 ) ) );
ok( array( 11 ) === $applied['marked'] && array( 13 ) === $applied['refused'] && array( 11 => 'spam' ) === $GLOBALS['__set'], 'forms-spam-apply still marks the flagged id and refuses the clean one' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
