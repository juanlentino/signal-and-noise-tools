<?php
/**
 * Tests: inc/tag-descriptions-seed.php — the one-shot tag-description seed.
 *
 * Drives the REAL sn_seed_tag_descriptions() against stubbed WP term
 * functions. The properties that matter: (1) every mapped tag with an empty
 * description gets its sentence; (2) a non-empty description is NEVER
 * clobbered; (3) a missing term is skipped without error; (4) the flag burns
 * after one full pass and a second run writes nothing; (5) the map covers the
 * 23-tag vocabulary with non-empty, period-terminated sentences.
 *
 * Run: php tests/tag-descriptions-seed.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__options'][ $k ] = $v; return true; }
$GLOBALS['__actions'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $c; return true; }

// Term store: name => (object) { term_id, description }.
$GLOBALS['__terms'] = array();
function get_term_by( $field, $value, $tax ) {
	if ( 'name' !== $field || 'post_tag' !== $tax ) { return false; }
	return $GLOBALS['__terms'][ $value ] ?? false;
}
$GLOBALS['__updates'] = array();
function wp_update_term( $id, $tax, $args ) {
	$GLOBALS['__updates'][] = array( 'id' => $id, 'tax' => $tax, 'args' => $args );
	foreach ( $GLOBALS['__terms'] as $name => $t ) {
		if ( $t->term_id === $id ) { $GLOBALS['__terms'][ $name ]->description = $args['description'] ?? ''; }
	}
	return array( 'term_id' => $id );
}

require_once __DIR__ . '/../inc/tag-descriptions-seed.php';

echo "tag-descriptions seed\n\n";

echo "Group: the map itself\n";
$map = sn_tag_description_seed_map();
ok( 23 === count( $map ), 'map carries exactly the 23-tag vocabulary (got ' . count( $map ) . ')' );
$all_sentences = true; $all_terminated = true;
foreach ( $map as $name => $s ) {
	if ( ! is_string( $s ) || '' === trim( $s ) ) { $all_sentences = false; }
	if ( '.' !== substr( trim( $s ), -1 ) ) { $all_terminated = false; }
}
ok( $all_sentences, 'every entry is a non-empty sentence' );
ok( $all_terminated, 'every sentence ends with a period' );
ok( count( $map ) === count( array_unique( $map ) ), 'no two tags share a sentence' );

echo "\nGroup: the full write pass\n";
$id = 100;
foreach ( $map as $name => $s ) {
	$GLOBALS['__terms'][ $name ] = (object) array( 'term_id' => ++$id, 'description' => '' );
}
// One owner-edited tag, one missing tag.
$GLOBALS['__terms']['Provenance']->description = 'The owner already wrote this one.';
unset( $GLOBALS['__terms']['Writing'] );

sn_seed_tag_descriptions();

ok( 21 === count( $GLOBALS['__updates'] ), '21 writes: 23 minus the owner-edited and the missing (got ' . count( $GLOBALS['__updates'] ) . ')' );
ok( 'The owner already wrote this one.' === $GLOBALS['__terms']['Provenance']->description,
	'NEVER CLOBBERS: the owner-edited description survives untouched' );
ok( $map['Authorship'] === $GLOBALS['__terms']['Authorship']->description, 'an empty description received its sentence verbatim' );
$writing_written = false;
foreach ( $GLOBALS['__updates'] as $u ) {
	if ( ( $u['args']['description'] ?? '' ) === $map['Writing'] ) { $writing_written = true; }
}
ok( ! $writing_written, 'the missing term was skipped, not invented' );
ok( ! empty( $GLOBALS['__options'][ SN_TAG_DESCRIPTIONS_SEED_OPT ] ), 'the flag burned after one full pass, skips included' );

echo "\nGroup: idempotence\n";
$before = count( $GLOBALS['__updates'] );
sn_seed_tag_descriptions();
ok( $before === count( $GLOBALS['__updates'] ), 'second run writes nothing (flag short-circuit)' );

echo "\nGroup: registration\n";
ok( in_array( 'sn_seed_tag_descriptions', $GLOBALS['__actions']['admin_init'] ?? array(), true ),
	'seed hooks admin_init under its OWN flag (deliberately NOT the master-sentinel registry)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
