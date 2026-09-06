<?php
/**
 * Standalone test: the OpenStation host's two client-side halves —
 * assets/os-host.js (new) and the `window.snAdmin.init( root )` seam added to
 * assets/admin.js.
 *
 * WHY SOURCE PINS. Both files are behaviour the BROWSER runs; there is no DOM
 * in this suite and no build step in this plugin, so what can be proven here is
 * that the file still says what the port depends on. Each pin therefore names
 * ONE seam the host cannot lose without the window going quietly dead:
 *
 *   - an inline `<script>` that arrived through innerHTML is inert forever
 *     unless the node is RE-CREATED, so `data-snt-exec` → fresh node → marked
 *     `data-snt-ran` is the whole mechanism, not an implementation detail;
 *   - admin.js binds on DOMContentLoaded, which fired before the window ever
 *     opened, so the seam and the marker that keeps it idempotent are the only
 *     reason a leaf's section tabs work twice;
 *   - a hidden document is never painted and never fires a frame, so the
 *     debounce needs BOTH `requestAnimationFrame` and a timer;
 *   - the order inside one pass is load-bearing: the seam hides every section
 *     panel but the active one, so an anchor scrolled to before it runs lands
 *     on a node that is about to be hidden.
 *
 * The regions are extracted BALANCED (brace-matched from the function's own
 * opening brace), never "up to the first `} );`": a first-terminator cut reads
 * a neighbouring function and pins whatever happens to be there.
 *
 * `node --check` runs both files when node is on PATH and prints a SKIP line
 * when it is not — an absent parser must never read as a parsed file.
 *
 * Run: php tests/openstation-host-assets.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
$skip = 0;

/**
 * Record one assertion.
 *
 * @param bool   $cond Condition.
 * @param string $msg  What it proves.
 */
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

/**
 * Record something that could not be measured here. Never a pass.
 *
 * @param string $msg Why.
 */
function skip( $msg ) {
	global $skip;
	$skip++;
	echo "  SKIP: $msg\n";
}

/**
 * The balanced `{ … }` region belonging to the first `$marker` in `$src`.
 *
 * Starts at the first brace AFTER the marker and returns when depth returns to
 * zero, so a nested function or object literal is included and the region
 * cannot run past its own function into the next one.
 *
 * @param string $src    File text.
 * @param string $marker Text the region follows.
 * @return string The region including both braces, or '' when not found.
 */
function snt_region( $src, $marker ) {
	$at = strpos( $src, $marker );
	if ( false === $at ) {
		return '';
	}
	$open = strpos( $src, '{', $at );
	if ( false === $open ) {
		return '';
	}
	$depth = 0;
	$len   = strlen( $src );
	for ( $i = $open; $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			$depth++;
		} elseif ( '}' === $src[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $src, $open, $i - $open + 1 );
			}
		}
	}
	return '';
}

/**
 * Whether every needle is present in the haystack.
 *
 * @param string   $hay     Haystack.
 * @param string[] $needles Needles.
 * @return bool
 */
function snt_has_all( $hay, array $needles ) {
	foreach ( $needles as $needle ) {
		if ( false === strpos( $hay, $needle ) ) {
			return false;
		}
	}
	return true;
}

$host_path  = __DIR__ . '/../assets/os-host.js';
$admin_path = __DIR__ . '/../assets/admin.js';

echo "openstation-host-assets suite — the host script and the admin.js seam\n";

echo "\nGroup 0: the files are there and readable (a missing file must not read as a clean sweep)\n";
ok( is_file( $host_path ), 'assets/os-host.js exists' );
ok( is_file( $admin_path ), 'assets/admin.js exists' );
$host  = is_file( $host_path ) ? (string) file_get_contents( $host_path ) : '';
$admin = is_file( $admin_path ) ? (string) file_get_contents( $admin_path ) : '';
ok( strlen( $host ) > 1000, 'VACUITY: os-host.js has content (' . strlen( $host ) . ' bytes) — every pin below is a substring search and an empty file would fail them all loudly, not pass quietly' );
ok( strlen( $admin ) > 1000, 'VACUITY: admin.js has content (' . strlen( $admin ) . ' bytes)' );

echo "\nGroup 1: os-host.js hosts BOTH windows, now and later\n";
ok( false !== strpos( $host, '.os-app[data-os-app="sn-dashboard"]' ), 'it selects the sn-dashboard app root by the framework\'s own template attributes' );
ok( false !== strpos( $host, '.os-app[data-os-app="sn-analytics"]' ), 'it selects the sn-analytics app root too — one host script, two windows' );
$start = snt_region( $host, 'function start(' );
ok( '' !== $start && false !== strpos( $start, 'scan( document.body )' ), 'VACUITY+PIN: start() is extracted and scans the body that is already there' );
ok( snt_has_all( $start, array( 'MutationObserver', 'document.body', 'addedNodes', 'childList' ) ), 'start() observes document.body for ADDED nodes, so a window opened later is hosted (a window almost always opens after this file loads)' );
ok( false !== strpos( $host, 'document.readyState' ) && false !== strpos( $host, 'DOMContentLoaded' ), 'it waits for a body when the document is still loading' );
$scan = snt_region( $host, 'function scan(' );
ok( '' !== $scan && snt_has_all( $scan, array( 'node.matches( ROOT_SELECTOR )', 'node.querySelectorAll( ROOT_SELECTOR )' ) ), 'scan() hosts a root that IS the added node and roots INSIDE it — the shell may add either' );
$host_fn = snt_region( $host, 'function host(' );
ok( '' !== $host_fn && snt_has_all( $host_fn, array( 'hosted.has( root )', 'hosted.add( root )' ) ), 'a root is given exactly one observer, however many times it is scanned' );
ok( snt_has_all( $host_fn, array( 'MutationObserver', 'subtree: true', 'schedule( root )' ) ), 'each root gets a SUBTREE observer, and the paint already on screen is passed over immediately' );

echo "\nGroup 2: one pass per paint — a frame, or a timer for a document nobody is painting\n";
$schedule = snt_region( $host, 'function schedule(' );
ok( '' !== $schedule && false !== strpos( $schedule, 'if ( pending.has( root ) ) {' ), 'VACUITY+PIN: schedule() is extracted and RETURNS EARLY when a pass is already scheduled — the closure\'s own re-check further down is the loser\'s disarm, not the coalescing latch, so this names the early return itself' );
ok( false !== strpos( $schedule, 'requestAnimationFrame' ), 'a frame runs the pass' );
ok( false !== strpos( $schedule, 'setTimeout' ), 'AND a timer does — a hidden document is never painted and never fires a frame, so a frame-only debounce would leave the window dead until it was looked at' );
ok( false !== strpos( $schedule, 'pending.delete( root )' ), 'whichever fires first clears the latch, so the loser is a no-op instead of a second pass' );

echo "\nGroup 3: the three things a pass does, in the order that works\n";
$pass_fn = snt_region( $host, 'function pass(' );
ok( '' !== $pass_fn, 'VACUITY: pass() is extracted' );
$i_scripts = strpos( $pass_fn, 'runScripts(' );
$i_seam    = strpos( $pass_fn, 'snAdmin.init(' );
$i_anchor  = strpos( $pass_fn, 'scrollToAnchor(' );
ok( false !== $i_scripts && false !== $i_seam && false !== $i_anchor, 'a pass re-runs scripts, calls the admin.js seam, and lands the anchor' );
ok( false !== $i_scripts && false !== $i_seam && $i_scripts < $i_seam, 'scripts run BEFORE the seam — a leaf script may create the markup the seam binds' );
ok( false !== $i_seam && false !== $i_anchor && $i_seam < $i_anchor, 'the seam runs BEFORE the anchor — it hides every section panel but the active one, and a scroll into a panel about to be hidden lands nowhere' );
ok( false !== strpos( $pass_fn, 'window.snAdmin && typeof window.snAdmin.init === \'function\'' ), 'the seam is called only when admin.js published it: os-host.js depends on nothing else' );

echo "\nGroup 4: an innerHTML-painted script is re-created so it executes\n";
$scripts_fn = snt_region( $host, 'function runScripts(' );
ok( '' !== $scripts_fn, 'VACUITY: runScripts() is extracted' );
ok( false !== strpos( $scripts_fn, 'script[data-snt-exec]:not([data-snt-ran])' ), 'it takes every block the rewrite pass marked and has not yet run' );
ok( false !== strpos( $scripts_fn, "document.createElement( 'script' )" ), 'it CREATES a fresh node — a script parsed in through innerHTML is inert forever, and nothing short of a created node runs' );
ok( false !== strpos( $scripts_fn, 'replaceChild( fresh, old )' ), 'the fresh node takes the old one\'s place, so the block runs where it was written' );
ok( snt_has_all( $scripts_fn, array( "getAttribute( 'src' )", "getAttribute( 'type' )", 'fresh.text = old.text' ) ), 'src, type and the inline text carry over — the three things that are the script' );
ok( false !== strpos( $scripts_fn, "old.setAttribute( 'data-snt-ran', '1' )" ) && false !== strpos( $scripts_fn, "fresh.setAttribute( 'data-snt-ran', '1' )" ), 'both nodes are marked ran: the swap is itself a mutation, and an unmarked block would be re-created by the pass it schedules, forever' );
$i_mark = strpos( $scripts_fn, "old.setAttribute( 'data-snt-ran', '1' )" );
$i_swap = strpos( $scripts_fn, 'replaceChild( fresh, old )' );
ok( false !== $i_mark && false !== $i_swap && $i_mark < $i_swap, 'the mark is written BEFORE the swap' );

echo "\nGroup 5: the post-save anchor, scrolled once\n";
$anchor_fn = snt_region( $host, 'function scrollToAnchor(' );
ok( '' !== $anchor_fn, 'VACUITY: scrollToAnchor() is extracted' );
ok( false !== strpos( $anchor_fn, "hasAttribute( 'data-snt-anchor' )" ) && false !== strpos( $anchor_fn, 'querySelector( \'[data-snt-anchor]\' )' ), 'the attribute is read from the app root OR from the view\'s own outermost element' );
ok( false !== strpos( $anchor_fn, 'target.scrollIntoView(' ), 'it scrolls to the element with that id — the CALL, not just the capability check beside it' );
ok( false !== strpos( $anchor_fn, "removeAttribute( 'data-snt-anchor' )" ), 'and removes the attribute, so it scrolls ONCE and not on every later paint' );
ok( false !== strpos( $host, 'function byId(' ) && false === strpos( $host, 'document.getElementById' ), 'the id is resolved INSIDE the root: getElementById would search the desktop, which holds every other open window' );

echo "\nGroup 6: what the host script must never do\n";
ok( false === strpos( $host, 'location.reload' ), 'no location.reload — a window repaints through the framework\'s dispatch, and a reload would take the whole desktop down with it' );
ok( false === strpos( $host, 'fetch(' ), 'no fetch: this file knows no endpoint' );
ok( false === strpos( $host, '/wp-abilities/' ), 'no ability path literal (the assets-wide transport guard in tests/ability-run-client.php says the same thing; said here too, at the file that would be tempted)' );
ok( false === strpos( $host, 'XMLHttpRequest' ), 'no XHR either' );

echo "\nGroup 7: admin.js publishes an idempotent, root-scoped seam\n";
ok( false !== strpos( $admin, 'window.snAdmin = window.snAdmin || {};' ), 'the seam object is merged onto, never replaced' );
ok( false !== strpos( $admin, 'window.snAdmin.init = init;' ), 'window.snAdmin.init is the published entry point' );
$init_fn = snt_region( $admin, 'function init( root )' );
ok( '' !== $init_fn, 'VACUITY: init() is extracted' );
ok( false !== strpos( $init_fn, 'root || document' ), 'init( root ) defaults to document, so the classic page keeps its old scope exactly' );
ok( snt_has_all( $init_fn, array( 'initSectionTabs( scope )', "scope.querySelector( '.sn-identity-form' )", 'initDirtyTracking( form )', 'initAddRowButton( form )' ) ), 'init() does everything DOMContentLoaded used to do — section tabs first, then the Identity form\'s dirty-tracking and add-row button' );
ok( false === strpos( $init_fn, 'document.querySelector' ), 'and does it INSIDE the given root: an unscoped query would reach another desktop window\'s leaf' );
ok( snt_has_all( $init_fn, array( "form.hasAttribute( 'data-snt-init' )", "form.setAttribute( 'data-snt-init', '1' )" ) ), 'the form is marked and skipped when already marked — the host calls this after EVERY paint, and a second dirty-tracker would double-count' );

echo "\nGroup 8: the old direct bindings are gone from DOMContentLoaded\n";
$dcl = snt_region( $admin, "document.addEventListener( 'DOMContentLoaded'" );
ok( '' !== $dcl, 'VACUITY: the DOMContentLoaded callback is extracted' );
ok( false !== strpos( $dcl, 'init( document )' ), 'DOMContentLoaded now calls the seam' );
ok( false === strpos( $dcl, 'initSectionTabs' ), 'it no longer calls initSectionTabs directly' );
ok( false === strpos( $dcl, 'initDirtyTracking' ) && false === strpos( $dcl, 'initAddRowButton' ), 'nor the form initialisers — one code path, so the window and the page cannot drift' );
ok( false === strpos( $dcl, 'sn-identity-form' ), 'nor does it look the form up itself' );

echo "\nGroup 9: the section tabs are root-scoped and armed once\n";
$tabs_fn = snt_region( $admin, 'function initSectionTabs( root )' );
ok( '' !== $tabs_fn, 'VACUITY: initSectionTabs() is extracted' );
ok( false !== strpos( $tabs_fn, "scope.querySelector( '.sn-section-tabs' )" ), 'the nav is looked up inside the root' );
ok( false === strpos( $tabs_fn, 'document.querySelector' ) && false === strpos( $tabs_fn, 'document.getElementById' ), 'no unscoped document lookup survives — on the desktop that is every open window' );
ok( false !== strpos( $tabs_fn, "nav.hasAttribute( 'data-snt-init' )" ), 'an already-armed nav is left alone: re-activating would throw the reader back to the first panel mid-read' );
ok( false !== strpos( $tabs_fn, "nav.setAttribute( 'data-snt-init', '1' )" ), 'and an armed nav is marked' );
$i_bail = strpos( $tabs_fn, 'tabs.length < 2' );
$i_mark2 = strpos( $tabs_fn, "nav.setAttribute( 'data-snt-init', '1' )" );
ok( false !== $i_bail && false !== $i_mark2 && $i_bail < $i_mark2, 'the marker is written only AFTER the <2-pairs bail-out: a nav that bound nothing must stay open to a later paint that brings its panels' );
ok( false !== strpos( $admin, 'function byId( root, id )' ), 'the panel lookup goes through a root-scoped byId()' );

echo "\nGroup 10: nothing the two files already did was dropped\n";
foreach ( array(
	'sn:row-added'    => 'the dirty-tracker still re-snapshots when a row is added',
	'aria-selected'   => 'the tabs pattern still sets aria-selected',
	'ArrowRight'      => 'keyboard navigation survives',
	'sn-savebar-hint' => 'the save bar hint is still tracked',
	'sn-add-row-btn'  => 'the add-row button is still wired',
	'social_same_as[]' => 'the added row still submits as the sameAs array',
	'sn-an-clamp--open' => 'the analytics clamp toggle (second IIFE) is untouched',
	'data-sn-an-collapsible' => 'the collapsible panels (second IIFE) are untouched',
) as $needle => $why ) {
	ok( false !== strpos( $admin, $needle ), $why );
}

echo "\nGroup 11: both files parse\n";
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $node ) {
	skip( 'node is not on PATH — os-host.js and admin.js were NOT parsed here (CI runs node --check on both)' );
} else {
	foreach ( array( $host_path, $admin_path ) as $file ) {
		$out  = array();
		$code = 0;
		exec( escapeshellarg( $node ) . ' --check ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
		ok( 0 === $code, 'node --check ' . basename( $file ) . ( 0 === $code ? '' : ' — ' . implode( ' ', $out ) ) );
	}
	// A parser that accepts anything proves nothing: give it a file that must fail.
	$broken = tempnam( sys_get_temp_dir(), 'snthost' ) . '.js';
	file_put_contents( $broken, "function ( {\n" );
	$out  = array();
	$code = 0;
	exec( escapeshellarg( $node ) . ' --check ' . escapeshellarg( $broken ) . ' 2>&1', $out, $code );
	@unlink( $broken );
	ok( 0 !== $code, 'NEGATIVE CONTROL: node --check rejects a deliberately broken file, so the two passes above are the parser working' );
}

echo "\nResult: $pass passed, $fail failed" . ( $skip > 0 ? ", $skip skipped" : '' ) . ".\n";
exit( $fail > 0 ? 1 : 0 );
