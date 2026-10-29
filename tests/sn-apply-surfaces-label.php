<?php
/**
 * Standalone test: a surfaces write refuses a leaked field label (2026-10-29).
 *
 * A drafting note ("FOCUS KEYWORD: ...") once reached post 2467's live meta
 * description. Pins: the detector catches the field labels however they are
 * spelled, ordinary prose passes, and gate 2 raises an error finding (which
 * refuses the write) on every field the surfaces type writes.
 *
 * Run: php tests/sn-apply-surfaces-label.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function get_post( $id ) { return null; }
function __( $s, $d = '' ) { return $s; }
function snt_sn_validate_finding( $surface, $check, $severity, $message, $observed, $expected, array $evidence, $identity ) {
	return array( 'surface' => $surface, 'check' => $check, 'severity' => $severity, 'observed' => $observed );
}

require __DIR__ . '/../inc/sn-apply/validation-gate2-parts.php';
require __DIR__ . '/../inc/sn-apply/create-draft.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "The detector\n";
foreach ( array(
	'Provenance has to be signed. FOCUS KEYWORD: verified signature' => 'FOCUS KEYWORD',
	'meta_description: A note about credit.'                         => 'meta_description',
	'SEO Title : Why credit stays private'                           => 'SEO Title',
	'og-card-title: A remix inherits nothing'                        => 'og-card-title',
	'OG title: x'                                                    => 'OG title',
	'Excerpt: the first line'                                        => 'Excerpt',
	"A line of copy.\nfocus keyword: provenance"                     => 'focus keyword',
	'_sn_meta_description: pasted from a staged-meta row'            => 'meta_description',
	'post_excerpt: pasted from a revision'                           => 'excerpt',
	'Focus keyphrase: credit'                                        => 'Focus keyphrase',
	'Open Graph title: x'                                            => 'Open Graph title',
	'Meta title: x'                                                  => 'Meta title',
) as $value => $want ) {
	ok( $want === snt_surface_leaked_label( $value ), "\"$want:\" is caught" );
}
foreach ( array(
	'A signed record tells you who made it, not who heard it.',
	'The meta description of a track is not its credit.',
	'Keyword search fails here: the credit was never written down.',
	'Title: the word alone is not a field label we write.',
	'An excerpt: the first bars of the take, unedited.',
	'Liner notes, short excerpt: "recorded live in one pass".',
	'The OG title: Doggystyle, credited to no one on the sleeve.',
	'Why the SEO title: matters less than the credit.',
	'',
) as $value ) {
	ok( '' === snt_surface_leaked_label( $value ), 'prose passes: "' . $value . '"' );
}

echo "\nGate 2\n";
$g = snt_sn_apply_gate2_validation( 'surfaces', array( 'post_id' => 2467 ), array( 'payload' => array(
	'seo_title'        => 'Verified signature',
	'focus_keyword'    => 'verified signature',
	'meta_description' => 'Provenance has to be signed. FOCUS KEYWORD: verified signature',
) ) );
ok( in_array( 'field_label', $g['checks'], true ), 'the label check runs on a surfaces write' );
ok( array( array( 'surface' => 'meta_description', 'check' => 'field_label', 'severity' => 'error', 'observed' => 'FOCUS KEYWORD' ) ) === $g['findings'], 'the leaked description is one error finding, on its own field' );
$g = snt_sn_apply_gate2_validation( 'surfaces', array( 'post_id' => 1 ), array( 'payload' => array( 'seo_title' => 'seo title: x', 'focus_keyword' => 'focus keyword: y' ) ) );
ok( array( 'seo_title', 'focus_keyword' ) === array_column( $g['findings'], 'surface' ), 'seo_title and focus_keyword are checked too, though no length check covers them' );
$g = snt_sn_apply_gate2_validation( 'surfaces', array( 'post_id' => 1 ), array( 'payload' => array( 'seo_title' => 'A clean title' ) ) );
ok( array( 'field_label' ) === $g['checks'] && array() === $g['findings'], 'a clean seo_title is checked and passes, so the gate no longer reports no_applicable_checks' );

echo "\ncreate_draft\n";
$g      = snt_sn_apply_gate2_create_draft( array( 'title' => 'T', 'content' => '', 'meta_description' => 'A note. SEO description: x', 'excerpt' => 'Clean.' ) );
$labels = array_values( array_filter( $g['findings'], fn( $f ) => 'field_label' === $f['check'] ) );
ok( in_array( 'field_label', $g['checks'], true ), 'a new draft runs the same label check' );
ok( 1 === count( $labels ) && 'meta_description' === $labels[0]['surface'] && 'error' === $labels[0]['severity'], 'and refuses the leaked description, only that field' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
