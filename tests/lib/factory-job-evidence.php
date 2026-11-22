<?php
/**
 * Part of tests/factory-job.php: the evidence rules an audit got around.
 * `job ready` re-checks the gate and the review, SURFACES is an allowlist
 * that fails closed, no path to build skips intake, security and data need a
 * second reviewer's line, and a job at spec can stop.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

// 1. A commit made after `advance ready` is not what the gate and the review saw.
walk( '21' );
ok( 'ready' === state( '21' )['stage'], 'a fresh job walks to ready in the fixture (control)' );
$old = head_of( '21' ); $new = commit_in( '21', 'after the review' );
list( $c, $o ) = job( 'ready', '21' );
ok( 0 !== $c && false !== strpos( $o, 'checks.md' ) && false === strpos( $o, 'gh pr ready' ), '`job ready` refuses a commit made after the gate, and marks nothing ready' );
putj( '21', 'round-1/checks.md', "PASS $new\n" );
list( $c, $o ) = job( 'ready', '21' );
ok( 0 !== $c && false !== strpos( $o, 'review.md' ), '`job ready` refuses a commit the review did not see' );
putj( '21', 'round-1/review.md', "VERDICT: PASS $new\n" ); set_state( '21', array( 'stopped' => 1000 ) );
list( $c, $o ) = job( 'ready', '21' );
ok( 0 === $c && false !== strpos( $o, 'gh pr ready' ), 'with the gate and the review on the new HEAD, `job ready` goes through' );
job( 'ready', '21' );
ok( 1000 === state( '21' )['stopped'], '`job ready` never moves the recorded stop, however often it runs' );

// 2. SURFACES is an allowlist: exactly one line, a value on it, known words only.
job( 'new', '22' );
$bad = array(
	"SURFACES:\n- public\n- admin"                       => 'empty',
	'SURFACES: front-end page, visitor copy'             => 'front-end',
	"SURFACES: admin\n\n## Scope\nSURFACES: public (x)"  => 'exactly one',
	"SURFACES: admin\nThe surfaces: public"              => 'exactly one',
	'SURFACES: none, admin'                              => 'none',
	'SURFACES: admin; public'                            => 'admin;',
);
foreach ( $bad as $lines => $names ) {
	putj( '22', 'intake.md', "DECISION: ACCEPT\nRISK: low\n$lines\n" );
	list( $c, $o ) = job( 'advance', '22', 'spec' );
	ok( 0 !== $c && false !== strpos( $o, $names ) && 'intake' === state( '22' )['stage'], 'refused, naming "' . $names . '": ' . str_replace( "\n", ' / ', $lines ) );
}
putj( '22', 'intake.md', "DECISION: ACCEPT\nRISK: low\n**SURFACES:** `admin`, Data security\n" );
ok( 0 === job( 'advance', '22', 'spec' )[0], 'known surfaces, comma or space separated and decorated, move to spec' );
job( 'new', '23' ); putj( '23', 'intake.md', "DECISION: ACCEPT\nSURFACES: none\n" );
ok( 0 === job( 'advance', '23', 'spec' )[0], '`none` alone moves to spec' );

// 3. Escalating from intake and restarting does not skip intake.
$GLOBALS['owner_ok'] = '1';
foreach ( array( 'DECISION: REJECT', 'DECISION: NEEDS-INFO' ) as $i => $decision ) {
	$id = (string) ( 24 + $i ); job( 'new', $id ); job( 'escalate', $id, 'a question' );
	putj( $id, 'intake.md', "$decision\nSURFACES: admin\n" ); putj( $id, 'spec.md', "1.\n" );
	list( $c, $o ) = job( 'advance', $id, 'build' );
	ok( 0 !== $c && false !== strpos( $o, 'DECISION: ACCEPT' ), "an intake that says $decision never reaches build, whatever the owner comments" );
}
putj( '24', 'intake.md', "DECISION: ACCEPT\nSURFACES: the whole site\n" );
list( $c, $o ) = job( 'advance', '24', 'build' );
ok( 0 !== $c && false !== strpos( $o, 'SURFACES' ), 'needs-human -> build runs the SURFACES check too' );
putj( '24', 'intake.md', "DECISION: ACCEPT\nSURFACES: admin\n" );
ok( 0 === job( 'advance', '24', 'build' )[0], 'an accepted intake, a spec and the owner\'s answer restart at build' );
$GLOBALS['owner_ok'] = '0';
job( 'new', '26' ); putj( '26', 'intake.md', "DECISION: ACCEPT\nSURFACES: admin\n" ); job( 'advance', '26', 'spec' );
putj( '26', 'intake.md', "DECISION: ACCEPT\nSURFACES: admin, public\n" ); putj( '26', 'spec.md', "1.\n" );
list( $c, $o ) = job( 'advance', '26', 'build' );
ok( 0 !== $c && false !== strpos( $o, 'public surface' ), 'an intake rewritten to public after spec cannot reach build without the owner' );
job( 'escalate', '26', 'another question' ); putj( '26', 'intake.md', "DECISION: ACCEPT\nSURFACES: admin\n" );
$GLOBALS['owner_ok'] = '1'; job( 'advance', '26', 'spec' ); $GLOBALS['owner_ok'] = '0';
putj( '26', 'intake.md', "DECISION: ACCEPT\nSURFACES: public\n" );
ok( 'spec' === state( '26' )['stage'] && 0 !== job( 'advance', '26', 'build' )[0], 'an answer to an escalation that was not about a public surface does not unlock one later' );

// 9. Security and data need a second reviewer, on the same commit.
walk( '27', 'admin, security', 'review' ); $sha = head_of( '27' );
putj( '27', 'round-1/review.md', "VERDICT: PASS $sha\n\nLooks right.\n" );
list( $c, $o ) = job( 'advance', '27', 'ready' );
ok( 0 !== $c && false !== strpos( $o, 'SECURITY: PASS' ), 'intake names security: ready needs the second line SECURITY: PASS <sha>' );
putj( '27', 'round-1/review.md', "VERDICT: PASS $sha\nSECURITY: PASS 0000000000000000000000000000000000000000\n" );
ok( 0 !== job( 'advance', '27', 'ready' )[0], 'a security pass on another commit does not count' );
putj( '27', 'round-1/review.md', "VERDICT: PASS $sha\nSECURITY: PASS $sha\n" );
ok( 0 === job( 'advance', '27', 'ready' )[0], 'with both lines on the HEAD, a security job reaches ready' );
putj( '27', 'round-1/review.md', "VERDICT: PASS $sha\n" );
list( $c, $o ) = job( 'ready', '27' );
ok( 0 !== $c && false !== strpos( $o, 'SECURITY: PASS' ), '`job ready` runs the same second-reviewer check' );
walk( '28', 'data', 'review' );
putj( '28', 'round-1/review.md', 'VERDICT: PASS ' . head_of( '28' ) . "\n" );
ok( 0 !== job( 'advance', '28', 'ready' )[0], 'intake names data: the same second line is required' );

// 7. A job at spec is not wedged: it can stop for the owner or be abandoned.
job( 'new', '29' ); putj( '29', 'intake.md', "DECISION: ACCEPT\nSURFACES: admin\n" ); job( 'advance', '29', 'spec' );
ok( 0 === job( 'escalate', '29', 'the spec cannot be written' )[0] && 'needs-human' === state( '29' )['stage'], 'spec can escalate to needs-human' );
job( 'new', '30' ); putj( '30', 'intake.md', "DECISION: ACCEPT\nSURFACES: admin\n" ); job( 'advance', '30', 'spec' );
ok( 0 === job( 'advance', '30', 'abandoned' )[0], 'spec can be abandoned' );

// 8. Rework from ready un-readies the pull request; DRY prints the calls.
walk( '31' ); $GLOBALS['owner_ok'] = '1';
list( $c, $o ) = job( 'advance', '31', 'build' ); $GLOBALS['owner_ok'] = '0';
ok( 0 === $c && false !== strpos( $o, 'gh pr ready factory/31 --undo' ) && false !== strpos( $o, '--remove-label factory:ready' ), 'ready -> build puts the pull request back to draft and removes factory:ready' );
