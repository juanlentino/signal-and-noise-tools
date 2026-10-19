<?php
/**
 * Tests: the résumé's formats on the Machine readers view. The sensor's
 * `resume` surface class and blob17 resume_format (rights-signals #100), the
 * allowlist that reads them, and the Résumé panel both twins paint.
 *
 * Run: php tests/machine-readers-resume.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';
foreach ( array( 'machine-readers-api', 'machine-readers-taxonomy', 'machine-readers-rights-reads', 'machine-readers-render', 'machine-readers-basis', 'machine-readers-readers-model', 'evidence-kit', 'evidence-paint-surface', 'machine-readers-resume' ) as $f ) {
	require_once SNT_PATH . "inc/$f.php";
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok  - $m\n"; } else { ++$fail; echo "  FAIL - $m\n"; } }

echo "Group A: the surface class and the format column\n";
ok( in_array( 'resume', snt_mr_valid_surfaces(), true ), 'resume is a valid surface (mirrors the worker classifySurface)' );
ok( ! in_array( 'resume', snt_mr_rights_surfaces(), true ), 'resume is NOT a rights surface: reading the résumé is not reading the terms' );
$n = snt_mr_normalize_rows( array(
	array( 'family' => 'openai', 'surface' => 'resume', 'day' => '2026-10-18', 'hits' => 3, 'resume_format' => 'json' ),
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-10-18', 'hits' => 2, 'resume_format' => '<b>x' ),
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-10-18', 'hits' => 1 ),
) );
ok( 'resume' === $n[0]['surface'] && 'json' === $n[0]['resume_format'], 'a resume row keeps its surface and format' );
ok( '' === $n[1]['resume_format'] && '' === $n[2]['resume_format'], 'a value off the allowlist, or an older Worker sending none, reads as not a résumé read' );

echo "\nGroup B: reads per format, readers only\n";
$row = static fn( $fmt, $agent, $hits, $extra = array() ) => array_merge( array( 'family' => 'openai', 'surface' => 'resume', 'day' => '2026-10-18', 'hits' => $hits, 'purpose' => 'train', 'agent' => $agent, 'vendor' => 'openai', 'first_party' => false, 'network' => '', 'signed_agent' => 'unsigned', 'resume_format' => $fmt ), $extra );
$rows = array(
	$row( 'json', 'openai-gptbot', 4 ),
	$row( 'json', 'anthropic-claudebot', 6, array( 'family' => 'anthropic', 'vendor' => 'anthropic' ) ),
	$row( 'md', 'openai-chatgpt-user', 2 ),
	$row( 'pdf', 'openai-gptbot', 1 ),
	$row( 'vcf', 'self-ledger', 50, array( 'first_party' => true, 'vendor' => 'signal-and-noise' ) ),
	$row( '', 'openai-gptbot', 99, array( 'surface' => 'html' ) ),
);
$reads = snt_mr_resume_reads( $rows );
ok( 10 === $reads['json']['reads'] && 2 === $reads['md']['reads'] && 1 === $reads['pdf']['reads'], 'reads summed per format' );
ok( ! isset( $reads['vcf'] ), 'this site\'s own tools move no résumé count' );
ok( array( 'anthropic-claudebot', 'openai-gptbot' ) === array_keys( $reads['json']['agents'] ), 'readers listed highest first' );
ok( array( 'md', 'json', 'pdf' ) === array_keys( $reads ), 'formats in a fixed order (md before json though json has more), not by volume' );

echo "\nGroup C: the panel, on both surfaces\n";
foreach ( array( 'kit', 'classic' ) as $s ) {
	$h = snt_mr_paint_resume( $rows, 3, $s );
	ok( false !== strpos( $h, 'Résumé' ), "$s: the panel is headed Résumé" );
	ok( false !== strpos( $h, 'JSON Resume' ) && false !== strpos( $h, 'anthropic-claudebot (6)' ), "$s: a format row names its readers" );
	ok( false !== strpos( $h, '3 people downloaded the PDF' ), "$s: people who downloaded the PDF, beside the machines" );
	ok( false === strpos( $h, '—' ), "$s: no em dash" );
	$none = snt_mr_paint_resume( array(), null, $s );
	ok( false !== strpos( $none, 'No reader fetched the résumé in this window.' ) && false !== strpos( $none, 'not measured' ), "$s: an empty window says so, and people read as not measured" );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
