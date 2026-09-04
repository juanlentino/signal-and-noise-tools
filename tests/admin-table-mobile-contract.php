<?php
/**
 * Tests: a table wearing core's list-table class must meet its responsive contract.
 *
 * WordPress core, wp-admin/css/list-tables.css, at max-width 782px:
 *
 *     .wp-list-table tr { display: flex; flex-wrap: wrap; }
 *     .wp-list-table td.column-primary,
 *     .wp-list-table th.column-primary { flex: 1 1 0; }
 *     .wp-list-table tr td:nth-child(n+3) { flex: 0 1 100%; }
 *     .wp-list-table td::before { content: attr(data-colname); }
 *
 * Every row becomes a FLEX CONTAINER sized from `column-primary`, and every cell
 * is labelled from its own `data-colname`. A table that wears `wp-list-table`
 * without emitting those gets the flex layout and none of the sizing, and on a
 * phone the header paints on top of the first cell. Observed 2026-09-04 in the
 * OpenStation PWA, which made phone-width wp-admin routine rather than rare.
 *
 * FIVE of ten producing files were in that state (analytics-render-quality,
 * cloudflare-purge, provenance-admin, schedule-admin, tag-consolidation-admin).
 * They were fixed by MEETING the contract, not by dropping the class - these are
 * real tabular readouts and they should stack on a phone like every other one.
 *
 * ── Why this guard is scoped to the FILE ──────────────────────────────────
 *
 * The first version of this audit used a 60-line window FORWARD from the
 * `<table>` tag and reported eight violations. Three were fabricated:
 *
 *   - inc/analytics-panels.php builds a column array (with the primary class in
 *     it) at line 714 and only opens the `<table>` at 734 - the class is ABOVE
 *     the tag, so a forward window cannot see it.
 *   - inc/machine-readers-render.php splits the header (snt_mr_table_open) from
 *     the body (four sibling snt_mr_render_*_table functions) - so a
 *     FUNCTION-scoped window cannot see it either.
 *
 * Neither window matched the contract, because the contract is not "near the
 * tag" or "in the same function" - it is "this table, wherever its cells are
 * written". The file is the smallest scope that always contains both halves.
 * A pattern that agrees with a rule when written diverges from it silently as
 * the tree grows; this docblock exists so the next widening is deliberate.
 *
 * Run: php tests/admin-table-mobile-contract.php
 * @since 13.96.4
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
 * Strip comments so a comment NAMING the class can never satisfy the check.
 *
 * Four assertions in this session's earlier passes went green on prose that
 * merely explained the rule they were meant to enforce.
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

// ── The population: every inc/ file, at ANY depth, that opens a list table ──
// Derived, never listed. A hand-kept list is a pattern pretending to be a rule.
$producers = array();
foreach ( snt_test_inc_files() as $file ) {
	$code = snt_atmc_code( (string) file_get_contents( $file ) );
	if ( false !== strpos( $code, 'wp-list-table' ) ) {
		$producers[ $file ] = $code;
	}
}

// A scan that found nothing reports the same clean bill as a scan that found no
// violations. Pin the floor so an empty population is a FAILURE, not a pass.
snt_atmc_ok(
	count( $producers ) >= 5,
	sprintf( 'population collapsed: %d files open a wp-list-table (expected >= 5)', count( $producers ) )
);

// ── A. Every producing file emits the primary column class ──
foreach ( $producers as $file => $code ) {
	snt_atmc_ok(
		false !== strpos( $code, 'column-primary' ),
		sprintf( '%s wears wp-list-table but never emits column-primary', basename( $file ) )
	);
}

// ── B. Every producing file that writes DATA cells labels them ──
// A cell with colspan is a message row - "Loading...", "No rows" - spanning the
// whole table. Core's ::before would label it with a column it does not occupy,
// so those are exempt and only genuine data cells are required to carry a name.
//
// SCOPE LIMIT, stated rather than hidden: a file may hold a wp-list-table AND
// plain `widefat` tables (inc/provenance-admin.php holds one of each kind), and
// file-scoped text cannot tell which cells belong to which table. Part A is
// still sound there - "does this file emit the class at all" needs no such
// attribution - but B is only decidable where EVERY table in the file is a list
// table. The count is printed so a future narrowing shows up as a number that
// moved, instead of as silence.
$b_checked = 0;
$b_skipped = array();
foreach ( $producers as $file => $code ) {
	$tables = preg_match_all( '/<table\b[^>]*>/', $code, $tm ) ? $tm[0] : array();
	$mixed  = false;
	foreach ( $tables as $tag ) {
		if ( false === strpos( $tag, 'wp-list-table' ) ) {
			$mixed = true;
		}
	}
	if ( $mixed ) {
		$b_skipped[] = basename( $file );
		continue;
	}
	++$b_checked;
	$data_cells = 0;
	if ( preg_match_all( '/<td\b[^>]*>/', $code, $m ) ) {
		foreach ( $m[0] as $tag ) {
			if ( false === stripos( $tag, 'colspan' ) ) {
				++$data_cells;
			}
		}
	}
	if ( 0 === $data_cells ) {
		continue;
	}
	snt_atmc_ok(
		false !== strpos( $code, 'data-colname' ),
		sprintf( '%s emits %d data cell(s) in a list table with no data-colname label', basename( $file ), $data_cells )
	);
}
snt_atmc_ok(
	$b_checked >= 4,
	sprintf( 'label check covered only %d file(s) (expected >= 4); skipped as mixed: %s', $b_checked, $b_skipped ? implode( ', ', $b_skipped ) : 'none' )
);
echo sprintf( "note: label check covered %d file(s); %d skipped as mixed-table (%s)\n", $b_checked, count( $b_skipped ), $b_skipped ? implode( ', ', $b_skipped ) : 'none' );

// ── C. The one cross-language producer ──
// inc/provenance-admin.php prints the header; assets/provenance-admin.js builds
// every body row. Part A passes on the PHP alone, so the JS half needs its own
// pin or the body silently loses the contract the header claims.
$js_path = dirname( __DIR__ ) . '/assets/provenance-admin.js';
if ( snt_atmc_ok( is_file( $js_path ), 'assets/provenance-admin.js is missing' ) ) {
	$js = (string) file_get_contents( $js_path );
	snt_atmc_ok(
		false !== strpos( $js, 'column-primary' ),
		'provenance-admin.js builds rows for a wp-list-table without column-primary'
	);
	snt_atmc_ok(
		false !== strpos( $js, 'data-colname' ),
		'provenance-admin.js builds cells with no data-colname label'
	);
}

// ── D. Negative control ──
// A guard that has never been made to fail is not evidence. Drive the same
// predicates over a synthetic violator and require them to go red.
$violator = '<?php // column-primary data-colname are named only in THIS comment.
echo \'<table class="wp-list-table widefat"><tr><td>x</td></tr></table>\';';
$vcode = snt_atmc_code( $violator );
snt_atmc_ok(
	false !== strpos( $vcode, 'wp-list-table' ),
	'negative control: synthetic violator was not even detected as a producer'
);
snt_atmc_ok(
	false === strpos( $vcode, 'column-primary' ),
	'negative control: comment-stripping failed - a commented class satisfied the check'
);
snt_atmc_ok(
	false === strpos( $vcode, 'data-colname' ),
	'negative control: comment-stripping failed - a commented label satisfied the check'
);

echo sprintf( "%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail );
exit( $fail ? 1 : 0 );
