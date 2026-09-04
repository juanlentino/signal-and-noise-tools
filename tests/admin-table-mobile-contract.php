<?php
/**
 * Tests: our admin tables do not claim core's list-table contract (issue #1021).
 *
 * ── What core does at max-width 782px, read from list-tables.css ──────────
 *
 *   .wp-list-table tr:not(.inline-edit-row):not(.no-items) td:not(.check-column)::before {
 *       position: absolute; left: 10px; width: 32%; content: attr(data-colname); }
 *   .wp-list-table tr:not(...) td.column-primary ~ td:not(.check-column) {
 *       padding: 3px 8px 3px 35%; }
 *   .wp-list-table td.column-primary ~ td:not(.check-column) { display: none; }
 *   .wp-list-table .is-expanded td:not(.hidden)  { display: block !important; }
 *   .wp-list-table .column-primary .toggle-row   { display: block; }
 *
 * Two consequences, and BOTH need core's `WP_List_Table` machinery to be safe:
 *
 *   1. The ::before label is absolutely positioned and applies to EVERY
 *      non-check cell — the primary included. Only NON-primary cells get the
 *      35% left padding that makes room for it. So a primary cell carrying
 *      `data-colname` paints its own label over its own text.
 *   2. Every column after the primary is `display: none` until the row gets
 *      `.is-expanded`, which only core's `.toggle-row` disclosure button sets.
 *
 * We render no `.toggle-row`, no `.check-column`, and no `WP_List_Table`. On a
 * phone that combination meant: one column visible, with its header printed on
 * top of it. Observed 2026-09-04 in the OpenStation PWA — "Top sources" over
 * "(direct)", and the Views/Visits columns simply gone.
 *
 * ── Why the guard asserts ABSENCE ─────────────────────────────────────────
 *
 * The first version of this file pinned "every table that wears wp-list-table
 * also emits column-primary" and passed on all of the markup above, because
 * emitting `column-primary` is exactly what turns the hiding rule on. It
 * measured a property that is necessary for core's layout and sufficient for
 * nothing, and #1015 then ADDED `data-colname` to five more primary cells —
 * spreading the defect while reporting a fix.
 *
 * The enforceable invariant is the one that matches what we actually build:
 * these are readouts, not list tables, so none of them wears the class. If a
 * real list table is ever added here, it needs a `.toggle-row` button, and this
 * guard should be changed deliberately rather than deleted.
 *
 * Run: php tests/admin-table-mobile-contract.php
 * @since 13.96.5
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/lib/inc-population.php';

$pass = 0;
$fail = 0;

/** Assert helper. */
function snt_atmc_ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		return true;
	}
	++$fail;
	echo "FAIL: $msg\n";
	return false;
}

/**
 * Strip comments so prose NAMING the class is never mistaken for markup.
 *
 * This file's own docblock quotes `wp-list-table` a dozen times; without this,
 * a guard that greps its own subject would fail on its own explanation.
 */
function snt_atmc_code( $src ) {
	$out = '';
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$out .= str_repeat( "\n", substr_count( $t[1], "\n" ) );
			continue;
		}
		$out .= is_array( $t ) ? $t[1] : $t;
	}
	return $out;
}

$files = snt_test_inc_files();

echo "admin-table-mobile-contract — plugin v13.96.5\n\nGroup 1: the sweep reaches the whole tree\n";
snt_atmc_ok( count( $files ) >= 400, sprintf( 'walked %d php files under inc/ at any depth', count( $files ) ) );
snt_atmc_ok( count( snt_test_inc_packages() ) >= 4, 'reached the packages: ' . implode( ', ', snt_test_inc_packages() ) );

// The sweep must actually find TABLES, or "no offending table" is the same
// sentence as "no table was looked at".
$table_files = 0;
foreach ( $files as $file ) {
	if ( false !== strpos( snt_atmc_code( (string) file_get_contents( $file ) ), '<table' ) ) {
		++$table_files;
	}
}
snt_atmc_ok( $table_files >= 10, sprintf( 'VACUITY: %d file(s) emit a <table> — the population is non-empty', $table_files ) );

echo "\nGroup 2: no readout claims core's list-table contract\n";
$offenders = array();
foreach ( $files as $file ) {
	$code = snt_atmc_code( (string) file_get_contents( $file ) );
	if ( false === strpos( $code, 'wp-list-table' ) ) {
		continue;
	}
	// A table MAY wear the class - if it also ships the disclosure button that
	// makes core's mobile layout usable. None of ours does today.
	if ( false !== strpos( $code, 'toggle-row' ) ) {
		continue;
	}
	$offenders[] = basename( $file );
}
snt_atmc_ok(
	array() === $offenders,
	'these wear wp-list-table without a .toggle-row, so on a phone core hides every column after the primary: ' . implode( ', ', $offenders )
);

echo "\nGroup 3: no primary cell labels itself\n";
// Even without the class this is worth pinning: it is the specific mistake
// #1015 made, and it becomes live again the moment anything re-adds the class.
$overprint = array();
foreach ( $files as $file ) {
	$code = snt_atmc_code( (string) file_get_contents( $file ) );
	if ( ! preg_match_all( '/<td\b[^>]*>/', $code, $m ) ) {
		continue;
	}
	foreach ( $m[0] as $tag ) {
		if ( false !== strpos( $tag, 'column-primary' ) && false !== strpos( $tag, 'data-colname' ) ) {
			$overprint[] = basename( $file );
			break;
		}
	}
}
snt_atmc_ok(
	array() === $overprint,
	'a primary cell carries data-colname; under .wp-list-table core paints that label over the cell own text: ' . implode( ', ', $overprint )
);

echo "\nGroup 4: negative control\n";
// Every predicate above must be shown to fire. A guard asserting ABSENCE is
// the easiest kind to leave vacuously green.
$bad_class = '<?php echo \'<table class="wp-list-table widefat">\';';
snt_atmc_ok( false !== strpos( snt_atmc_code( $bad_class ), 'wp-list-table' ), 'control: a table wearing the class IS detected' );

$commented = '<?php // wp-list-table is named only in this comment.' . "\n" . 'echo "hi";';
snt_atmc_ok( false === strpos( snt_atmc_code( $commented ), 'wp-list-table' ), 'control: a comment naming the class is NOT counted' );

$bad_cell = '<?php echo \'<td class="column-primary" data-colname="Path">\';';
$hit      = false;
if ( preg_match_all( '/<td\b[^>]*>/', snt_atmc_code( $bad_cell ), $cm ) ) {
	foreach ( $cm[0] as $tag ) {
		if ( false !== strpos( $tag, 'column-primary' ) && false !== strpos( $tag, 'data-colname' ) ) {
			$hit = true;
		}
	}
}
snt_atmc_ok( $hit, 'control: a self-labelling primary cell IS detected' );

$ok_cell = '<?php echo \'<td class="column-primary">\'; echo \'<td data-colname="Views">\';';
$false_hit = false;
if ( preg_match_all( '/<td\b[^>]*>/', snt_atmc_code( $ok_cell ), $om ) ) {
	foreach ( $om[0] as $tag ) {
		if ( false !== strpos( $tag, 'column-primary' ) && false !== strpos( $tag, 'data-colname' ) ) {
			$false_hit = true;
		}
	}
}
snt_atmc_ok( ! $false_hit, 'control: a primary cell and a labelled NON-primary cell are not confused for one another' );

echo sprintf( "%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail );
exit( $fail ? 1 : 0 );
