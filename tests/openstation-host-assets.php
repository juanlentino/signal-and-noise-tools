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

/**
 * The same JS with its comments removed.
 *
 * WHY. Every ordering pin below is an offset comparison, and a comment that
 * QUOTES the call it describes satisfies a bare `strpos` from the wrong place:
 * measured here on 2026-09-06, `form.appendChild( input )` appeared in
 * carrySubmitter's own explanatory comment 260 bytes BEFORE the statement, and
 * three order pins read the comment and went red against correct code. An
 * instrument that can be satisfied by prose is not measuring the code.
 *
 * Conservative by design: a `//` preceded by `:` is left alone so a URL inside
 * a string is not truncated. Block comments go whole.
 *
 * @param string $js Source.
 * @return string Source with comments blanked.
 */
function snt_js_code( $js ) {
	$js = (string) preg_replace( '#/\*.*?\*/#s', '', $js );
	return (string) preg_replace( '#(?<!:)//[^\n]*#', '', $js );
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

echo "\nGroup 7b: the idempotence marker is a WeakSet, because the runtime DELETES attributes\n";
// The window paints by MORPH: `zt()` (offset 26198 of desktop-mode's
// app-runtime.min.js) removes every attribute the server's node does not
// carry, while `Se()` reuses the node — so a `data-snt-init` attribute was
// gone by the next paint and the seam bound the SAME button a second time
// (two rows per click, then three). A WeakSet is out of the patcher's reach.
ok( false === strpos( $admin, "'data-snt-init'" ), 'admin.js never passes `data-snt-init` to a DOM call — the attribute marker the patcher deleted is GONE, not merely supplemented' );
ok( false === strpos( $host, "'data-snt-init'" ), '...and neither does os-host.js' );
ok( snt_has_all( $admin, array( 'var boundNavs', 'var boundForms', 'var boundButtons', 'new WeakSet()' ) ), 'the three bind-once registries are module-scope WeakSets, keyed by the element the listeners actually sit on' );
$dirty_fn = snt_js_code( snt_region( $admin, 'function initDirtyTracking( form )' ) );
ok( '' !== trim( $dirty_fn ), 'VACUITY: initDirtyTracking() is extracted' );
$i_base  = strpos( $dirty_fn, 'dirtyBaseline.set( form, snapshotForm( form ) )' );
$i_guard = strpos( $dirty_fn, 'boundForms.has( form )' );
$i_bind  = strpos( $dirty_fn, "form.addEventListener( 'input', update )" );
ok( false !== $i_base && false !== $i_guard && false !== $i_bind, 'it refreshes a baseline, guards on the form, and binds the input listener' );
ok( $i_base < $i_guard, 'ORDER: the baseline is re-read BEFORE the bind-once guard returns — a repaint restores the server\'s saved values, and a baseline frozen at the first paint reports every SAVED change as still unsaved' );
ok( $i_guard < $i_bind, 'ORDER: and the guard is checked BEFORE the listener is attached, so a second call adds no second dirty-tracker' );
ok( false !== strpos( $dirty_fn, 'boundForms.add( form )' ), 'the form is recorded as bound' );
ok( false !== strpos( $dirty_fn, "form.hasAttribute( 'data-dirty' )" ), 'and a form still marked dirty is mid-edit: its baseline and clean copy are left alone (data-dirty is written only here, so a repaint strips it)' );
$addrow_fn = snt_js_code( snt_region( $admin, 'function initAddRowButton( form )' ) );
ok( '' !== trim( $addrow_fn ), 'VACUITY: initAddRowButton() is extracted' );
$i_bguard = strpos( $addrow_fn, 'boundButtons.has( btn )' );
$i_bbind  = strpos( $addrow_fn, "btn.addEventListener( 'click'" );
ok( false !== $i_bguard && false !== $i_bbind && $i_bguard < $i_bbind, 'ORDER: the add-row button is guarded on the BUTTON before its click is bound — this is the pair that added one more empty social_same_as[] row per repaint' );
ok( false !== strpos( $addrow_fn, 'boundButtons.add( btn )' ), 'and the button is recorded' );

echo "\nGroup 8: the old direct bindings are gone from DOMContentLoaded\n";
$dcl = snt_region( $admin, "document.addEventListener( 'DOMContentLoaded'" );
ok( '' !== $dcl, 'VACUITY: the DOMContentLoaded callback is extracted' );
ok( false !== strpos( $dcl, 'init( document )' ), 'DOMContentLoaded now calls the seam' );
ok( false === strpos( $dcl, 'initSectionTabs' ), 'it no longer calls initSectionTabs directly' );
ok( false === strpos( $dcl, 'initDirtyTracking' ) && false === strpos( $dcl, 'initAddRowButton' ), 'nor the form initialisers — one code path, so the window and the page cannot drift' );
ok( false === strpos( $dcl, 'sn-identity-form' ), 'nor does it look the form up itself' );

echo "\nGroup 9: the section tabs are root-scoped and armed once\n";
$tabs_fn = snt_js_code( snt_region( $admin, 'function initSectionTabs( root )' ) );
ok( '' !== trim( $tabs_fn ), 'VACUITY: initSectionTabs() is extracted (comments stripped: a pin an explanatory comment can satisfy is not measuring the code)' );
ok( false !== strpos( $tabs_fn, "scope.querySelector( '.sn-section-tabs' )" ), 'the nav is looked up inside the root' );
ok( false === strpos( $tabs_fn, 'document.querySelector' ) && false === strpos( $tabs_fn, 'document.getElementById' ), 'no unscoped document lookup survives — on the desktop that is every open window' );
$i_bail  = strpos( $tabs_fn, 'pairs.tabs.length < 2' );
$i_aria  = strpos( $tabs_fn, "nav.setAttribute( 'role', 'tablist' )" );
$i_mark2 = strpos( $tabs_fn, 'boundNavs.add( nav )' );
$i_act   = strpos( $tabs_fn, 'activateSection( nav,' );
ok( false !== strpos( $tabs_fn, 'boundNavs.has( nav )' ) && false !== $i_mark2, 'an already-bound nav is not bound again — the registry is the WeakSet, not an attribute the patcher deletes' );
ok( false !== $i_bail && false !== $i_mark2 && $i_bail < $i_mark2, 'the nav is recorded only AFTER the <2-pairs bail-out: a nav that bound nothing must stay open to a later paint that brings its panels' );
ok( false !== $i_aria && false !== $i_mark2 && $i_aria < $i_mark2, 'RE-APPLIED, NOT ONCE: the ARIA upgrade runs BEFORE the bind-once branch, so every paint gets it back — `role`, `aria-*` and `tabindex` are written here and nowhere in the server\'s markup, so the attribute sync deletes all of them on each repaint' );
ok( false !== $i_act && false !== $i_mark2 && $i_mark2 < $i_act, '...and the panel state is applied AFTER it, on every call, so a repaint does not leave every section expanded' );
ok( false !== strpos( $tabs_fn, 'navIndex.has( nav ) ? navIndex.get( nav ) : hashIndex(' ), 'the reader\'s own open panel is restored; only a nav armed for the FIRST time opens the one named by location.hash' );
ok( false !== strpos( $admin, 'function byId( root, id )' ), 'the panel lookup goes through a root-scoped byId()' );
$bind_tabs = snt_js_code( snt_region( $admin, 'function bindSectionTabs( nav )' ) );
ok( '' !== trim( $bind_tabs ), 'VACUITY: bindSectionTabs() is extracted' );
ok( snt_has_all( $bind_tabs, array( "nav.addEventListener( 'click'", "nav.addEventListener( 'keydown'" ) ), 'the two listeners sit on the NAV, by delegation' );
ok( false === strpos( $bind_tabs, 'tab.addEventListener' ), 'and NOT on the tabs: the ARIA upgrade gives each tab an `id`, which is exactly what the runtime reads as a diff key (te()), while the server paints the anchors unkeyed — so Se() replaces every tab node on each repaint and a listener on one would be thrown away with it' );
ok( snt_has_all( $bind_tabs, array( 'ArrowRight', 'ArrowLeft', 'Home', 'End' ) ), 'keyboard navigation kept its four keys' );

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
	foreach ( array(
		$host_path,
		$admin_path,
		__DIR__ . '/../assets/cron-dashboard.js',
		__DIR__ . '/../assets/uptime-status.js',
		__DIR__ . '/../assets/provenance-admin.js',
		__DIR__ . '/../assets/freshness-dot.js',
		__DIR__ . '/../assets/analytics/analytics-brush.js',
	) as $file ) {
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

echo "\nGroup S: the submitter rides the form -- FormData never includes the clicked button\n";
$host_js = (string) file_get_contents( __DIR__ . '/../assets/os-host.js' );
ok( false !== strpos( $host_js, "document.addEventListener( 'submit', carrySubmitter, true )" ), 'a CAPTURE-phase submit listener on the document: it runs before the runtime serialises the form wherever that listener sits' );
ok( false !== strpos( $host_js, "document.addEventListener( 'click', rememberSubmitter, true )" ), '...and a capture-phase click remembers the last data-snt-submit button pressed, for browsers without SubmitEvent.submitter' );
ok( false !== strpos( $host_js, 'var btn = e.submitter || (' ) && false !== strpos( $host_js, "form.querySelector( '[data-snt-submit]' )" ), 'the submitter is the event\'s own first, the remembered click second, the form\'s default button last -- what implicit submission uses' );
ok( false !== strpos( $host_js, "! form.hasAttribute( 'os-action' )" ), 'only a rewritten form (os-action) is touched: a real form the host kept keeps its own submitter' );
ok( 1 === substr_count( $host_js, 'armSubmitter();' ) && false !== strpos( $host_js, 'armSubmitter.done' ), 'armed once from start(), never per root' );

// The four writes below have an ORDER, and the order is the whole fix, so
// every one of these is an OFFSET comparison inside the BALANCED carrySubmitter
// region -- not a substring search anywhere in the file. A previous version of
// this group asked only "is `form.appendChild( input )` present", which a
// mutant that moved the stale-carrier sweep AFTER the append passed word for
// word while deleting the carrier it had just added.
$carry = snt_js_code( snt_region( $host_js, 'function carrySubmitter(' ) );
ok( '' !== trim( $carry ), 'VACUITY: carrySubmitter() is extracted BALANCED, comments stripped (a comment that quotes a call must not satisfy an order pin)' );
$i_stale  = strpos( $carry, "form.querySelectorAll( 'input[data-snt-submitter]' )" );
$i_shadow = strpos( $carry, 'shadowSameName( form, btn.name )' );
$i_undo   = strpos( $carry, 'unshadowSoon( form )' );
$i_append = strpos( $carry, 'form.appendChild( input )' );
ok( false !== $i_stale && false !== $i_shadow && false !== $i_undo && false !== $i_append, 'the region sweeps the previous press\'s carrier, shadows the same-named fields, schedules the undo, and appends the carrier' );
ok( $i_stale < $i_append, 'ORDER: the stale carrier is removed BEFORE the append -- swapped, the sweep deletes the carrier it just added and no submit button\'s name ever reaches the server' );
ok( $i_shadow < $i_append, 'ORDER: the same-named fields are DISABLED before the append -- swapped, the append\'s own hidden input is the one that gets disabled and the action is lost' );
ok( $i_undo < $i_append, 'ORDER: the re-enable is SCHEDULED before the append too, so the append stays the last write' );
$tail = substr( $carry, $i_append + strlen( 'form.appendChild( input );' ) );
ok( '' === trim( $tail, " \t\r\n}" ), 'AND THE APPEND IS THE LAST STATEMENT of the region: nothing after it can touch the form data set (tail after it is only whitespace and the closing brace)' );

echo "\nGroup S2: a repeated name becomes an ARRAY, not PHP's later-value-wins\n";
// `jt()` (offset 22876 of desktop-mode's assets/js/app-runtime.min.js) walks
// `new FormData( form )` and folds a repeated key into a JS array:
// `o[i]=Array.isArray(r)?[...r,s]:[r,s]` (23311). The replay requires a SCALAR
// sn_action, so appending beside inc/admin-forms/ai-settings.php's hidden
// sn_action shipped [ ai_settings_save, ml_embed_compare ] and "Run
// comparison" answered "Nothing was saved."
ok( false === strpos( $host_js, "so PHP's later-value-wins rule" ), 'THE CLAIM IS GONE: the comment no longer says the append makes PHP\'s later-value-wins rule apply -- this runtime never sends a urlencoded body and has no such rule' );
ok( false !== strpos( $host_js, 'Array.isArray(r)?[...r,s]:[r,s]' ), '...and the comment quotes the fold that actually happens, from the bundle' );
$shadow_fn = snt_js_code( snt_region( $host_js, 'function shadowSameName(' ) );
ok( '' !== $shadow_fn, 'VACUITY: shadowSameName() is extracted' );
ok( false !== strpos( $shadow_fn, 'field.disabled = true' ), 'it DISABLES the same-named fields -- FormData skips a disabled field, which is what makes the appended carrier the only value for that name' );
ok( false !== strpos( $shadow_fn, "field.setAttribute( 'data-snt-shadowed', '1' )" ), 'and marks what it disabled, so the undo restores exactly those' );
ok( false !== strpos( $shadow_fn, 'field.disabled' ) && false !== strpos( $shadow_fn, 'continue' ), 'a field the PAGE had already disabled is skipped and left unmarked -- it must still be disabled after the undo' );
ok( false !== strpos( $shadow_fn, "'submit' === type" ), 'the button kinds are skipped: a button is never in a FormData entry list, so disabling one would grey the reader\'s own button and buy nothing' );
$unshadow_fn = snt_js_code( snt_region( $host_js, 'function unshadowSoon(' ) );
ok( '' !== $unshadow_fn, 'VACUITY: unshadowSoon() is extracted' );
ok( snt_has_all( $unshadow_fn, array( 'window.setTimeout(', "querySelectorAll( '[data-snt-shadowed]' )", 'disabled = false', "removeAttribute( 'data-snt-shadowed' )" ) ), 'the undo runs on the next tick and re-enables ONLY what was shadowed: a refused dispatch must leave the form usable, not a form of dead fields' );

echo "\nGroup P: every paint re-arms the leaf scripts, not just admin.js\n";
// The nine handles the host appends are first-open window companions: each ran
// once, against a root holding only the spinner, and nothing called them again.
// Cron's Run now / Unschedule / history, the Webhooks uptime rail and the
// Provenance stepper were inert for the life of the window.
$pass_code = snt_js_code( $pass_fn );
$i_scripts_c = strpos( $pass_code, 'runScripts(' );
$i_seam_c    = strpos( $pass_code, 'snAdmin.init(' );
$i_anchor_c  = strpos( $pass_code, 'scrollToAnchor(' );
$i_paint = strpos( $pass_code, "document.dispatchEvent( new CustomEvent( 'snt:paint'" );
ok( false !== $i_paint, 'pass() dispatches a snt:paint CustomEvent' );
ok( false !== $i_paint && false !== $i_scripts_c && $i_scripts_c < $i_paint, 'ORDER: AFTER the scripts step — a leaf script re-arming on the event must see the markup an inline block created' );
ok( false !== $i_paint && false !== $i_seam_c && $i_seam_c < $i_paint, 'ORDER: AFTER the admin.js seam — which hides every section panel but the active one' );
ok( false !== $i_paint && false !== $i_anchor_c && $i_anchor_c < $i_paint, 'ORDER: AFTER the anchor scroll — a leaf painting into the section underneath would otherwise undo the landing' );
ok( false !== strpos( $pass_code, 'detail: { root: root }' ), 'the painted root rides in detail.root, so a subscriber arms THAT window and not the desktop' );

// (b) The five scripts that bind to leaf elements, or fetch for one, at load.
$seamed = array(
	'cron-dashboard.js'            => 'Cron: Run now, Unschedule, the history toggles and the filter',
	'uptime-status.js'             => 'the Webhooks + Health uptime mounts',
	'provenance-admin.js'          => 'the Provenance live commits stepper',
	'freshness-dot.js'             => 'the Caches glance card',
	'analytics/analytics-brush.js' => 'the Views-per-day brush',
);
foreach ( $seamed as $rel => $what ) {
	$src = (string) file_get_contents( __DIR__ . '/../assets/' . $rel );
	ok( strlen( $src ) > 400, "VACUITY: assets/$rel has content (" . strlen( $src ) . ' bytes)' );
	// Spacing differs across these files (two house styles, no build step), so
	// the needle is the whitespace-stripped source, never one file's habits.
	$tight = (string) preg_replace( '/\s+/', '', snt_js_code( $src ) );
	ok( false !== strpos( $tight, 'functioninit(root)' ) || false !== strpos( $tight, 'functionboot(root)' ), "$rel exposes an init/boot that takes a root — $what" );
	ok( false !== strpos( $tight, "addEventListener('snt:paint'" ), "$rel subscribes to snt:paint, so it re-arms after every repaint" );
	ok( false !== strpos( $tight, 'e.detail&&e.detail.root' ), "$rel arms the PAINTED root (falling back to document), never the whole desktop" );
	ok( false !== strpos( $tight, 'init(document)' ) || false !== strpos( $tight, 'boot(document)' ), "$rel still arms itself at load with `document` — the classic page behaves exactly as before" );
}

echo "\nGroup P2: re-arming is idempotent, and by the RIGHT kind of marker\n";
// Two kinds, and the difference is measured, not stylistic. A marker that must
// SURVIVE the morph (this element already has its listener) is a WeakSet: `zt`
// only touches attributes. A marker that must be CLEARED by the morph (the
// answer I painted is still on screen) is an attribute: the same repaint that
// restored the server's placeholder removes it.
$cron = (string) file_get_contents( __DIR__ . '/../assets/cron-dashboard.js' );
ok( false !== strpos( $cron, 'var wired = new WeakSet()' ), 'cron-dashboard tracks bound controls in a WeakSet' );
$bind_once = snt_js_code( snt_region( $cron, 'function bindOnce(' ) );
$i_w   = strpos( $bind_once, 'wired.has( el )' );
$i_ret = strpos( $bind_once, 'return false;' );
$i_add = strpos( $bind_once, 'wired.add( el )' );
$i_a   = strpos( $bind_once, 'el.addEventListener( event, fn )' );
ok( '' !== $bind_once && false !== $i_w && false !== $i_ret && false !== $i_add && false !== $i_a, 'bindOnce() checks the registry, returns early, records the element and binds' );
ok( false !== $i_w && false !== $i_ret && $i_w < $i_ret, 'ORDER: the check comes first...' );
// `check < bind` alone is too weak to be a pin: a mutant that put the bind
// INSIDE the guard block still reads left-to-right in the right order and
// stayed green here on 2026-09-06. What actually protects the button is that
// the EARLY RETURN sits between the check and the bind.
ok( false !== $i_ret && false !== $i_a && $i_ret < $i_a, 'ORDER: ...and the early RETURN sits between the check and the bind, so an element already in the set is never reached by addEventListener — a repaint reuses the button, and the attribute marker this replaces fired Run now twice' );
ok( false !== $i_add && false !== $i_a && $i_add < $i_a, 'ORDER: and the element is recorded before it is bound' );
ok( false === strpos( $cron, 'document.getElementById' ) && false === strpos( $cron, 'document.querySelectorAll' ), 'and it scans the given root, never the desktop document that holds every other open window' );

$upt = (string) file_get_contents( __DIR__ . '/../assets/uptime-status.js' );
$boot_fn = snt_js_code( snt_region( $upt, 'function boot( root )' ) );
$i_mark  = strpos( $boot_fn, 'm.setAttribute( PAINTED' );
$i_run   = strpos( $boot_fn, 'window.sntAbilityRun(' );
ok( '' !== $boot_fn && false !== $i_mark && false !== $i_run && $i_mark < $i_run, 'ORDER: uptime marks each mount BEFORE the ability call — the mark is itself a mutation, so the no-op pass it schedules must not start a second round trip' );
ok( false !== strpos( $boot_fn, ':not([' ), 'and only unpainted mounts are collected, so a mount already answered is left alone' );

$fresh = (string) file_get_contents( __DIR__ . '/../assets/freshness-dot.js' );
$fresh_init = snt_js_code( snt_region( $fresh, 'function init(root)' ) );
$i_fmark = strpos( $fresh_init, 'card.setAttribute(ARMED' );
$i_fetch = strpos( $fresh_init, 'Promise.all(' );
ok( '' !== $fresh_init && false !== $i_fmark && false !== $i_fetch && $i_fmark < $i_fetch, 'ORDER: the freshness card is marked BEFORE its route fetches start' );
ok( false === strpos( $fresh, 'document.getElementById(cfg.cardId)' ), 'and the card is found INSIDE the root: getElementById would reach a second window\'s card' );

$prov = (string) file_get_contents( __DIR__ . '/../assets/provenance-admin.js' );
ok( 1 === substr_count( $prov, 'setInterval(' ), 'the provenance stepper starts EXACTLY ONE interval in the file — a per-paint re-arm that started another would poll N times per 30s and never stop' );
ok( false !== strpos( $prov, 'armed.has(live)' ) && false !== strpos( $prov, 'armed.add(live)' ), 'and the interval is started once per live region, tracked in a WeakSet' );
ok( false !== strpos( $prov, 'clearInterval(timer)' ) && false !== strpos( $prov, 'live.isConnected' ), 'the interval stops itself when the window that owned it is closed — a detached tbody must not be polled forever' );

$brush = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-brush.js' );
ok( false !== strpos( $brush, 'armed.has(wrap)' ) && false !== strpos( $brush, 'armed.add(wrap)' ), 'the brush binds its pointer handlers once per wrap' );
ok( false !== strpos( $brush, 'sel.parentNode !== wrap' ), 'and re-attaches its selection overlay when a repaint dropped it: the overlay is a child the server never paints, so the diff removes it every time' );

echo "\nGroup P3: the four scripts that need NO seam, and why\n";
// Measured, not assumed: each of these attaches its handler to `document` and
// re-reads the DOM at event time, so a repaint changes nothing for it. The
// port map guessed resume-admin's add-row was element-bound; it is not.
$delegated = array(
	'snt-confirm.js'           => array( "document.addEventListener( 'click'", 'the [data-snt-confirm] interceptor' ),
	'resume-admin.js'          => array( "document.addEventListener( 'click'", 'the repeatable-row add/move/remove buttons' ),
	'health-suggest-actions.js' => array( "document.addEventListener( 'click'", 'the suggest / suggest-all / dismiss buttons' ),
	'admin-heartbeat.js'       => array( '$( document ).on(', 'the heartbeat send/tick patchers (and the host appends no such handle)' ),
);
foreach ( $delegated as $rel => $spec ) {
	$src = (string) file_get_contents( __DIR__ . '/../assets/' . $rel );
	ok( strlen( $src ) > 400, "VACUITY: assets/$rel has content (" . strlen( $src ) . ' bytes)' );
	ok( false !== strpos( (string) preg_replace( '/\s+/', '', snt_js_code( $src ) ), (string) preg_replace( '/\s+/', '', $spec[0] ) ), "$rel is DELEGATED ON DOCUMENT — {$spec[1]} keeps working through every repaint without a seam" );
	ok( false === strpos( (string) preg_replace( '/\s+/', '', $src ), "addEventListener('snt:paint'" ), "...so $rel subscribes to nothing: a seam it does not need is a second code path that can rot" );
}

echo "\nResult: $pass passed, $fail failed" . ( $skip > 0 ? ", $skip skipped" : '' ) . ".\n";
exit( $fail > 0 ? 1 : 0 );
