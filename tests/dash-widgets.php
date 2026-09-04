<?php
/**
 * Tests: the four fallback dashboard boxes on index.php (v13.30.0).
 *
 * WHY THESE EXIST AT ALL. "dashboard-widget sprawl" is listed Declined,
 * standing in docs/superpowers/specs/2026-07-01-stack-audit-abilities-
 * consolidation-design.md:85, and v8.3.0 + v11.30.0 both folded boxes AWAY.
 * The owner reopened it on 2026-08-29 with a constraint, while OpenStation's
 * command palette is severed upstream (WordPress/openstation#705) and the
 * Classic Admin home is the surface actually being used: group the ten desktop
 * widgets by WHAT THEY SHOW rather than mirroring them one for one.
 *
 * So the guard is no longer "one box". It is "one box PER SUBJECT, and every
 * one of them earns its column". These tests pin the grouping, the deep links,
 * and the two invariants that survive unchanged:
 *
 *   1. ZERO COST. index.php renders on every admin login. Every render here is
 *      an instant shell; the live reads happen client-side through readonly
 *      abilities, exactly as assets/uptime-status.js has done since v8.2.0.
 *   2. NO TYPOED ABILITY NAMES. A box whose ability does not exist renders an
 *      empty shell forever and reports nothing, which is the silent-green
 *      failure this codebase keeps re-learning. Every declared name is checked
 *      against the real registration calls in inc/.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/lib/inc-population.php'; // #987: inc/ is walked, not top-level-globbed.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$GLOBALS['__actions'] = array(); $GLOBALS['__widgets'] = array();
$GLOBALS['__http_calls'] = 0; $GLOBALS['__scans'] = 0; $GLOBALS['__caps'] = array( 'manage_options' );
$GLOBALS['__styles'] = array(); $GLOBALS['__scripts'] = array();

function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $cb; }
function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = null ) { $GLOBALS['__styles'][ $h ] = $src; }
function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = null, $f = false ) { $GLOBALS['__scripts'][ $h ] = $deps; }
function wp_add_dashboard_widget( $id, $title, $cb ) { $GLOBALS['__widgets'][ $id ] = array( $title, $cb ); }
// $GLOBALS['__caps'] is the set the current user ACTUALLY holds. view_stats is
// NOT a core WordPress capability, so a plain administrator holds only
// manage_options — which is why every other consumer here gates on
// `view_stats || manage_options`. The previous stub granted view_stats to all
// callers and made the Audience assertion vacuous: it passed while the box was
// invisible on the real dashboard.
function current_user_can( $c ) { return in_array( $c, (array) $GLOBALS['__caps'], true ); }
function wp_remote_get( $u, $a = array() ) { $GLOBALS['__http_calls']++; return array(); }
function wp_remote_post( $u, $a = array() ) { $GLOBALS['__http_calls']++; return array(); }
function sn_health_run_scan() { $GLOBALS['__scans']++; return array(); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function __( $t, $d = '' ) { return $t; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function apply_filters( $t, $v ) { return $v; }
if ( ! defined( 'SNT_URL' ) ) { define( 'SNT_URL', 'https://x.test/wp-content/plugins/snt/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '13.30.0-test' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fire() { $GLOBALS['__widgets'] = array(); foreach ( $GLOBALS['__actions']['wp_dashboard_setup'] ?? array() as $cb ) { $cb(); } }

function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function _n( $sg, $pl, $n, $d = '' ) { return 1 === (int) $n ? $sg : $pl; }
// The SAME measurement fixture tests/dash-widget.php drives its signal strip
// with, so the two suites cannot disagree about the shape of this payload. The
// real sn_dash_signals_from_measurement() runs against it — stubbing the unit
// under test would assert nothing about it.
function snt_dashboard_measurement_data() { return array(
	'views_7d' => 128, 'views_delta' => 39, 'views_prior' => 89,
	'search_clicks' => 5, 'search_clicks_days' => 28, 'search_impressions' => 1240,
	'anchored' => 33, 'anchored_total' => 33, 'citations' => 0,
); }
require __DIR__ . '/../inc/dash-signals.php';
require __DIR__ . '/../inc/dash-widgets.php';

echo "the fallback dashboard boxes\n\n";
fire();

// ── THE GROUPING ────────────────────────────────────────────────────────────
echo "Grouping: one box per subject, not one per desktop widget\n";
$expect = array(
	'sn_dash_audience'   => 'Audience',
	'sn_dash_machines'   => 'Machine Readers',
	'sn_dash_ops'        => 'Operations',
	'sn_dash_provenance' => 'Provenance',
);
foreach ( $expect as $id => $subject ) {
	ok( isset( $GLOBALS['__widgets'][ $id ] ), "$id registers ($subject)" );
}
ok( 4 === count( $GLOBALS['__widgets'] ),
	'FOUR, not ten — the ten desktop widgets group into four subjects (the fifth box is sn_dashboard, another module)' );
ok( ! isset( $GLOBALS['__widgets']['sn_dashboard'] ),
	'and this module does NOT re-register the consolidated box that inc/dash-widget.php owns' );

// ── ZERO COST ───────────────────────────────────────────────────────────────
echo "\nZero-cost render — index.php renders on every admin login\n";
$GLOBALS['__http_calls'] = 0; $GLOBALS['__scans'] = 0;
$html = array();
foreach ( array_keys( $expect ) as $id ) {
	ob_start(); call_user_func( $GLOBALS['__widgets'][ $id ][1] ); $html[ $id ] = ob_get_clean();
}
ok( 0 === $GLOBALS['__http_calls'], 'ZERO HTTP CALLS across all four renders' );
ok( 0 === $GLOBALS['__scans'], 'AND NO SCAN' );
foreach ( $expect as $id => $subject ) {
	ok( '' !== trim( $html[ $id ] ), "$id renders a shell" );
}

// ── THE ABILITY NAMES ARE REAL ──────────────────────────────────────────────
// A typoed name renders an empty shell forever and reports nothing. That is
// the silent-green shape this codebase keeps paying for, so it is pinned here
// against the actual wp_register_ability() calls rather than a hand list.
echo "\nEvery declared ability actually exists\n";
$registered = array();
foreach ( snt_test_inc_files() as $f ) {
	if ( preg_match_all( "#wp_register_ability\(\s*'([^']+)'#", (string) file_get_contents( $f ), $m ) ) {
		foreach ( $m[1] as $n ) { $registered[ $n ] = true; }
	}
}
ok( count( $registered ) > 20, 'the ability scan found the real registry (' . count( $registered ) . ' abilities)' );
$declared = array();
foreach ( snt_dwx_boxes() as $box ) {
	foreach ( $box['sections'] as $sec ) {
		if ( ! empty( $sec['ability'] ) ) { $declared[ $sec['ability'] ] = $box['id']; }
	}
	foreach ( (array) ( $box['lists'] ?? array() ) as $l ) {
		if ( ! empty( $l['ability'] ) ) { $declared[ $l['ability'] ] = $box['id'] . ' (list)'; }
	}
	foreach ( (array) ( $box['actions'] ?? array() ) as $a ) {
		if ( ! empty( $a['ability'] ) ) { $declared[ $a['ability'] ] = $box['id'] . ' (action)'; }
	}
}
ok( count( $declared ) >= 3, 'at least three boxes are ability-backed (' . count( $declared ) . ')' );
foreach ( $declared as $name => $box_id ) {
	ok( isset( $registered[ $name ] ), "$box_id declares a REAL ability: $name" );
}

// ── THE FIELD PATHS ARE REAL TOO ────────────────────────────────────────────
// Pinning the ability NAME is not enough: this suite went green while Audience
// asked for `totals.pageviews`, a key get-analytics-summary has never had, and
// the box would have hydrated to em dashes forever. The first segment of every
// path must appear as a property in that ability's own registration block.
echo "\nEvery declared field path names a real top-level property\n";
$src_by_ability = array();
foreach ( snt_test_inc_files() as $f ) {
	$src = (string) file_get_contents( $f );
	foreach ( array_keys( $declared ) as $name ) {
		$i = strpos( $src, "wp_register_ability( '" . $name . "'" );
		if ( false !== $i ) { $src_by_ability[ $name ] = substr( $src, $i, 9000 ); }
	}
}
foreach ( snt_dwx_boxes() as $box ) {
	foreach ( $box['sections'] as $sec ) {
		if ( empty( $sec['ability'] ) || ! isset( $src_by_ability[ $sec['ability'] ] ) ) { continue; }
		$blob = $src_by_ability[ $sec['ability'] ];
		foreach ( $sec['fields'] as $field ) {
			$root = explode( '.', (string) $field['path'] )[0];
			ok( false !== strpos( $blob, "'" . $root . "'" ),
				$box['id'] . ": '" . $field['path'] . "' roots at a real property of " . $sec['ability'] );
		}
	}
	foreach ( (array) ( $box['lists'] ?? array() ) as $l ) {
		if ( ! isset( $src_by_ability[ $l['ability'] ] ) ) { continue; }
		$root = explode( '.', (string) $l['path'] )[0];
		ok( false !== strpos( $src_by_ability[ $l['ability'] ], "'" . $root . "'" ),
			$box['id'] . " list: '" . $l['path'] . "' roots at a real property of " . $l['ability'] );
	}
}

// ── DEEP LINKS ──────────────────────────────────────────────────────────────
// Owner ask, 2026-08-29: "if you think they'd go, put the deep links to each
// thing." A box that reports a number and strands you is worse on the home
// screen than on the desktop, where the shell is one click from everything.
echo "\nDeep links: every box routes to the screen that holds the detail\n";
foreach ( $expect as $id => $subject ) {
	$links = 0;
	foreach ( snt_dwx_boxes() as $box ) { if ( $box['id'] === $id ) { $links = count( $box['links'] ); } }
	ok( $links >= 1, "$id declares at least one deep link ($links)" );
	ok( false !== strpos( $html[ $id ], 'sn-dwx__links' ), "$id renders its link row" );
}
$all = implode( '', $html );
foreach ( array(
	'tab=monitoring&sub=analytics'       => 'Analytics',
	'tab=monitoring&sub=rss'             => 'RSS',
	'tab=monitoring&sub=machine-readers' => 'Machine Readers',
	'tab=tools&sub=provenance'           => 'Provenance',
	'tab=connections&sub=cron'           => 'Cron',
) as $target => $label ) {
	ok( false !== strpos( $all, $target ), "a box deep-links to $label ($target)" );
}
// Guard the shape of every URL rather than only the ones spot-checked above:
// a leaf that does not exist is a 'sub=' pointing at nothing.
$known_subs = array( 'analytics', 'rss', 'health', 'insights', 'machine-readers', 'provenance', 'cron', 'cloudflare', 'tags', 'models-budget', 'block-migrations' );
foreach ( snt_dwx_boxes() as $box ) {
	foreach ( $box['links'] as $link ) {
		ok( '' !== trim( (string) $link['label'] ), $box['id'] . ': every link carries a label' );
		if ( preg_match( '#sub=([a-z-]+)#', (string) $link['url'], $m ) ) {
			ok( in_array( $m[1], $known_subs, true ), $box['id'] . ": sub=$m[1] is a real admin leaf" );
		}
	}
}

// ── CAPABILITY TIERS ────────────────────────────────────────────────────────
echo "\nCapability tiers\n";
$GLOBALS['__caps'] = array( 'view_stats' );
fire();
ok( isset( $GLOBALS['__widgets']['sn_dash_audience'] ),
	'a view_stats user gets Audience — readership is what that capability is for' );
foreach ( array( 'sn_dash_machines', 'sn_dash_ops', 'sn_dash_provenance' ) as $id ) {
	ok( ! isset( $GLOBALS['__widgets'][ $id ] ), "$id is manage_options business and does not register for view_stats" );
}
$GLOBALS['__caps'] = array( 'manage_options' );
fire();

// ── THE BUG THE OWNER SAW: an administrator must get Audience ───────────────
// Shipped in 13.30.0 gated on 'view_stats' ALONE. view_stats is not a core
// WordPress capability, so a plain administrator does not hold it and the box
// registered for nobody — it was simply absent from the dashboard while this
// suite was green, because the old stub granted view_stats to every caller.
echo "\nAn administrator gets every box\n";
$GLOBALS['__caps'] = array( 'manage_options' );
fire();
foreach ( array_keys( $expect ) as $id ) {
	ok( isset( $GLOBALS['__widgets'][ $id ] ), "$id registers for a manage_options-only user (the real administrator shape)" );
}
$GLOBALS['__caps'] = array( 'view_stats' );
fire();
ok( isset( $GLOBALS['__widgets']['sn_dash_audience'] ), 'and a view_stats-only user still gets Audience' );
$GLOBALS['__caps'] = array( 'manage_options' );
fire();

// ── IT MUST LOOK LIKE A WIDGET, NOT A SETTINGS TABLE ───────────────────────
// Owner, 2026-08-29, looking at the shipped boxes: "No format as a widget
// should have and definitely not the same information or deltas where they're
// needed." A flat label-value list is a settings table. The sibling box's grid
// is the house idiom and its CSS is already on this screen.
echo "\nWidget format: the sibling box's signal grid, not a label-value list\n";
$html = array();
foreach ( array_keys( $expect ) as $id ) {
	ob_start(); call_user_func( $GLOBALS['__widgets'][ $id ][1] ); $html[ $id ] = ob_get_clean();
}
foreach ( $expect as $id => $subject ) {
	ok( false !== strpos( $html[ $id ], 'sn-dw__signals' ), "$id renders the signal GRID" );
	ok( false !== strpos( $html[ $id ], 'sn-dw__k' ) && false !== strpos( $html[ $id ], 'sn-dw__n' ),
		"$id uses the sibling's label/number vocabulary, so dash-widget.css styles it" );
	// The exact old markup, quote included: 'sn-dwx__row' alone also matches
	// 'sn-dwx__rows', the LIST container, and would fail on correct code.
	ok( false === strpos( $html[ $id ], 'class="sn-dwx__row"' ), "$id no longer renders the flat label-value row list" );
}

// ── DELTAS AND CONTEXT WHERE THEY ARE NEEDED ───────────────────────────────
// A bare count says nothing: "3,168" is meaningless without "of 64,503", and
// "Reads 64,503" is meaningless without its window.
echo "\nEvery box carries comparison lines, not bare counts\n";
foreach ( $expect as $id => $subject ) {
	ok( false !== strpos( $html[ $id ], 'sn-dw__c' ), "$id renders comparison slots" );
}
$with_sub = 0; $cells = 0; $deltas = 0;
foreach ( snt_dwx_boxes() as $box ) {
	foreach ( $box['sections'] as $sec ) {
		foreach ( (array) ( $sec['fields'] ?? array() ) as $f ) {
			$cells++;
			if ( ! empty( $f['compare']['template'] ) || ! empty( $f['delta'] ) ) { $with_sub++; }
			if ( ! empty( $f['delta'] ) ) { $deltas++; }
		}
	}
}
ok( $cells > 0 && $with_sub === $cells,
	"EVERY ability-backed cell carries a comparison OR a delta ($with_sub/$cells) — a number with no denominator is not a reading" );
ok( $deltas >= 2, "and at least two cells carry a REAL period-over-period delta ($deltas)" );

// A delta is derived by subtracting the wider window from the narrower one, so
// the section MUST declare a baseline and the field MUST be an additive count.
// Subtracting two ratios yields a confident, meaningless number.
echo "\nDeltas are derived honestly\n";
$ratio_paths = array( 'view_visit_ratio', 'pageviews_per_visitor_day', 'scroll_avg_per_view', 'time_avg_per_view', 'scroll_avg_per_visit', 'time_avg_per_visit' );
foreach ( snt_dwx_boxes() as $box ) {
	foreach ( $box['sections'] as $sec ) {
		foreach ( (array) ( $sec['fields'] ?? array() ) as $f ) {
			if ( empty( $f['delta'] ) ) { continue; }
			ok( ! empty( $sec['baseline'] ),
				$box['id'] . ": '" . $f['path'] . "' declares a delta AND its section declares the wider baseline window" );
			ok( ! in_array( (string) $f['path'], $ratio_paths, true ),
				$box['id'] . ": '" . $f['path'] . "' is an additive count, not a ratio — a ratio delta by subtraction is meaningless" );
		}
	}
}
ok( false !== strpos( $html['sn_dash_audience'], 'data-sn-dwx-baseline' ),
	'Audience ships the baseline contract, so the delta has a prior period to subtract' );
ok( false !== strpos( $html['sn_dash_machines'], 'data-sn-dwx-baseline' ),
	'and so does Machine Readers' );

// ── RICHNESS: a fallback is still a widget ─────────────────────────────────
// Owner, 2026-08-29: "The widgets should be rich, even if they're a fallback."
// Four signal cells is a summary, not the desktop widget's content. Each box
// carries the breakdown its desktop counterpart carries.
echo "\nRichness: breakdown lists, and actions where the desktop widget has one\n";
foreach ( $expect as $id => $subject ) {
	$box = null;
	foreach ( snt_dwx_boxes() as $b ) { if ( $b['id'] === $id ) { $box = $b; } }
	ok( ! empty( $box['lists'] ), "$id carries at least one breakdown list" );
	ok( false !== strpos( $html[ $id ], 'sn-dwx__list' ), "$id renders its list container" );
	ok( false !== strpos( $html[ $id ], 'data-sn-dwx-list' ), "$id ships the list hydration contract" );
}
// A list heading with invented skeleton rows would be a claim about data nobody
// has read. The heading holds the space; the rows arrive or they do not.
// Quote-anchored: 'sn-dwx__li' is a substring of 'sn-dwx__list', the CONTAINER,
// so the bare form fails on correct code. Second time this bit in this suite.
ok( false === strpos( $html['sn_dash_machines'], 'class="sn-dwx__li"' ),
	'and renders NO skeleton rows server-side — an invented row count is a claim about unread data' );

echo "\nActions on the two boxes whose desktop counterparts have one\n";
foreach ( array( 'sn_dash_ops' => 'signal-noise/purge-all-caches', 'sn_dash_provenance' => 'signal-noise/anchor-sweep' ) as $id => $ability ) {
	ok( false !== strpos( $html[ $id ], 'data-sn-dwx-action="' . $ability . '"' ), "$id wires the $ability button" );
	ok( false !== strpos( $html[ $id ], 'class="button button-small sn-dwx__btn"' ),
		"$id uses core's own .button class, so it looks like wp-admin rather than a bespoke control" );
	ok( false !== strpos( $html[ $id ], 'sn-dwx__result' ), "$id has somewhere to report the outcome" );
}
foreach ( array( 'sn_dash_audience', 'sn_dash_machines' ) as $id ) {
	ok( false === strpos( $html[ $id ], 'sn-dwx__actions' ), "$id is a readout and carries NO write button" );
}

// ── ONE OWNER PER FACT ─────────────────────────────────────────────────────
// Owner, 2026-08-29, on the shipped 13.31.0: the grid and the list said the same
// thing twice. "TOP FAMILY 23,888 / unclassified-machine" WAS row 1 of TOP
// FAMILIES directly beneath it; the feed cells restated the feed windows.
echo "\nNo cell restates a fact its own box's list already owns\n";
foreach ( snt_dwx_boxes() as $box ) {
	$list_roots = array();
	foreach ( (array) ( $box['lists'] ?? array() ) as $l ) {
		$list_roots[] = explode( '.', (string) $l['path'] )[0];
	}
	if ( ! $list_roots ) { continue; }
	foreach ( $box['sections'] as $sec ) {
		foreach ( (array) ( $sec['fields'] ?? array() ) as $f ) {
			$root = explode( '.', (string) $f['path'] )[0];
			ok( ! in_array( $root, $list_roots, true ) || 'pending' === $root,
				$box['id'] . ": cell '" . $f['path'] . "' does not restate the list that owns '" . $root . "'" );
		}
	}
}
// Provenance is the one allowed overlap and it is NOT a restatement: the cell
// owns the COUNT, the list owns per-note detail no cell can carry, and the two
// carry different headings for that reason.
ok( false !== strpos( $html['sn_dash_provenance'], 'In flight' ),
	'Provenance list is headed "In flight", not a second "Pending"' );

// ── ASSETS REACH index.php, AND NOWHERE ELSE ────────────────────────────────
// v11.30.2 shipped this widget's CSS into a stylesheet that only loaded on S&N
// pages, so the box rendered unstyled on the one screen it lives on.
echo "\nAssets reach the screen that renders the boxes\n";
$GLOBALS['__styles'] = array(); $GLOBALS['__scripts'] = array();
foreach ( $GLOBALS['__actions']['admin_enqueue_scripts'] ?? array() as $cb ) { $cb( 'index.php' ); }
ok( isset( $GLOBALS['__styles']['sn-dash-widgets'] ), 'the stylesheet is enqueued on index.php' );
ok( isset( $GLOBALS['__scripts']['sn-dash-widgets'] ), 'and the hydrator script too' );
ok( in_array( 'snt-ability-run', (array) $GLOBALS['__scripts']['sn-dash-widgets'], true ),
	'THE HYDRATOR DEPENDS ON snt-ability-run — without it sntAbilityRun is undefined and every box stays an empty shell' );

$GLOBALS['__styles'] = array(); $GLOBALS['__scripts'] = array();
foreach ( $GLOBALS['__actions']['admin_enqueue_scripts'] ?? array() as $cb ) { $cb( 'edit.php' ); }
ok( ! isset( $GLOBALS['__styles']['sn-dash-widgets'] ), 'and neither loads anywhere else' );
ok( ! isset( $GLOBALS['__scripts']['sn-dash-widgets'] ), 'nor the script' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
