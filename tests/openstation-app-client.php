<?php
/**
 * Standalone test: the Signal & Noise app's CONTROL SURFACE, client side (#1065).
 *
 * The client view is JavaScript a browser runs; PHP cannot execute it. What PHP
 * can do is hold the file to the contract phase two agreed: the seams it takes
 * from the runtime (`applySelection`, `createMarquee`, `copyText`, the drag
 * manager, `<os-context-menu>`), the fixed order of the menu, the exact
 * confirmation wording, and — the pin that catches the whole class of silent
 * frontend rot — that every `snt-` class the client EMITS is a class the
 * stylesheet DEFINES.
 *
 * These are source-text pins. They cannot prove the surface behaves; they can
 * prove it did not quietly lose a seam, reorder the menu, or grow an orphan
 * class. Behaviour is the sandbox pass's job.
 * Run: php tests/openstation-app-client.php
 *
 * @since 13.101.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );

$pass = 0;
$fail = 0;
/**
 * Record one assertion.
 *
 * @param bool   $c Condition.
 * @param string $m What it means.
 * @return void
 */
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "PASS: $m\n";
	} else {
		++$fail;
		echo "FAIL: $m\n";
	}
}

$js_path  = SNT_PATH . 'apps/signal-noise/signal-noise-client.js';
$css_path = SNT_PATH . 'apps/signal-noise/signal-noise.css';
$js       = is_file( $js_path ) ? (string) file_get_contents( $js_path ) : '';
$css      = is_file( $css_path ) ? (string) file_get_contents( $css_path ) : '';

echo "openstation-app-client -- the control surface (#1065)\n\nGroup 1: the file, the one bag, the two gates\n";
ok( '' !== $js && '' !== $css, 'the client view and its stylesheet are both readable' );
ok( 1 === substr_count( $js, 'ctx.ui(' ), 'still exactly one ctx.ui() bag: the runtime keeps one per mounted view and silently discards every later factory' );
ok( false !== strpos( $js, 'menu: null' ), 'the open menu lives in that same bag, never in declared state' );
ok( 0 === substr_count( $js, "section.id === 'notes'" ), 'no section-id literal gates the dossier any more: a second section with a dossier would have needed a second literal in both places, and the third would have been missed' );
ok( 2 === substr_count( $js, 'section.hasDossier' ), 'the dossier is gated in exactly two places -- the render and the fetch -- and both read the DESCRIPTOR field hasDossier, so a section says for itself whether it has one' );
ok( false === strpos( $js, '/wp-abilities/' ), 'the client still never spells the abilities path' );

echo "\nGroup 2: the runtime seams, as 1.1.6 declares them\n";
foreach ( array( 'applySelection(', 'createMarquee(', 'copyText(' ) as $seam ) {
	ok( false !== strpos( $js, $seam ), "the selection and clipboard math come from the framework, not a hand-roll: $seam" );
}
ok( false !== strpos( $js, 'applySelection( selectedIds( state ), order, String( args.id ), { ctrl: !! args.toggle, shift: !! args.shift } )' ), 'applySelection is called with the SOURCE signature (selected, order, id, { ctrl, shift }) -- not the one the plan text guessed' );
ok( false !== strpos( $js, "'select-set'" ), 'the marquee reports through a select-set reducer, the Explorer\'s own name for it' );
ok( false !== strpos( $js, 'className: ' ) && false !== strpos( $js, 'select: ( ids )' ), 'createMarquee is handed the source\'s option names (canvas selector, className, select)' );

echo "\nGroup 3: the drag lift\n";
ok( false !== strpos( $js, 'dragManager.start(' ), 'the lift goes through the shell DragManager, not HTML5 dataTransfer' );
ok( false !== strpos( $js, "type: 'shortcut'" ), 'it lifts as a shortcut payload' );
ok( false !== strpos( $js, 'restPath' ), 'the section\'s REST collection rides along so a drop target can route the object' );
ok( false !== strpos( $js, "'signal-noise:notes'" ), 'the entity id names the app and the section' );
// The KEY and the VALUE, together: `'signal-noise:pages'` sitting anywhere in
// the file proves the string exists, not that the `pages` section maps to it.
// A map keyed `page:` -- or one whose pages entry points at the notes entity --
// would satisfy a bare substring search and lift the wrong object.
ok( 1 === preg_match( "/pages:\\s*'signal-noise:pages'/", $js ), '...and Pages opts in BY NAME beside Notes -- the `pages` KEY mapped to the `signal-noise:pages` entity, not merely the string somewhere in the file' );
ok( false !== strpos( $js, 'data-snt-drag' ), 'only elements carrying the drag flag lift' );
ok( false !== strpos( $js, 'e.button !== 0 || e.shiftKey || e.ctrlKey || e.metaKey || isPhone()' ), 'the lift refuses a non-primary button, any modifier, and the phone -- one guard, the Explorer\'s' );

echo "\nGroup 4: the menu\n";
ok( false !== strpos( $js, 'os-context-menu' ), 'the menu is the kit\'s component' );
ok( false !== strpos( $js, 'os-context-menu-pick' ), '...and its pick event carries the option id' );
ok( false !== strpos( $js, "'More actions'" ), 'the button trigger is labelled the Explorer\'s way' );
ok( false !== strpos( $js, 'snt-menu-backdrop' ), 'a full-window backdrop closes it' );
$menu_order = array( 'edit', 'view', 'copy-link', 'copy-id', 'verify', 'purge', 'anchor', 'publish', 'trash' );
$at         = array();
foreach ( $menu_order as $option_id ) {
	$at[ $option_id ] = strpos( $js, "id: '" . $option_id . "'" );
}
ok( ! in_array( false, $at, true ), 'every menu option id is declared: ' . implode( ', ', $menu_order ) );
$ascending = true;
$previous  = -1;
foreach ( $menu_order as $option_id ) {
	if ( false === $at[ $option_id ] || $at[ $option_id ] <= $previous ) {
		$ascending = false;
	}
	$previous = (int) $at[ $option_id ];
}
ok( $ascending, 'the options are declared in the FIXED order the spec fixed -- a menu whose rows move under the hand is a different menu' );
// The order pin above reads byte offsets; this one reads the CONSTRUCTION:
// every conditional row is appended, never inserted ahead of an earlier one.
$menu_fn = (string) substr( $js, (int) strpos( $js, 'const menuOptions' ), (int) strpos( $js, 'const runAction' ) - (int) strpos( $js, 'const menuOptions' ) );
ok( 5 === substr_count( $menu_fn, 'options.push( { id:' ) && false === strpos( $menu_fn, 'unshift' ) && false === strpos( $menu_fn, 'splice' ), 'the five conditional rows are pushed onto the four fixed ones, never inserted or reordered' );
ok( false !== strpos( $js, "id: 'refresh'" ) && false !== strpos( $js, 'canvasOptions' ), 'a right-click on the empty canvas paints the Explorer\'s canvas menu, here Refresh alone' );

echo "\nGroup 5: what confirms, and in whose words\n";
ok( false !== strpos( $js, 'confirm:' ), 'confirmation goes through ctx.dispatch\'s confirm option, the one primitive there is' );
ok( 1 === preg_match( "/confirm:\\s*\\{[^}]*'Trash'[^}]*danger: true/s", $js ), 'the Trash CONFIRM is the danger one (the object, not any danger token elsewhere)' );
ok( 0 === preg_match( "/confirm:\\s*\\{[^}]*'Publish'[^}]*danger: true/s", $js ), 'and the Publish confirm is not: Enter must not publish, but the button is not red' );
ok( false !== strpos( $js, "'Move to Trash'" ) && false === strpos( $js, "'Move to the Trash'" ), 'the row reads "Move to Trash", every shell surface\'s words' );
ok( false !== strpos( $js, "'Move this to the Trash?'" ), 'the single-item Trash question is the Explorer\'s, verbatim' );
ok( false !== strpos( $js, "'Move %d items to the Trash?'" ), '...and the plural one pluralises in the MESSAGE, never in the label' );
ok( false !== strpos( $js, "'Publish this note?'" ), 'publishing confirms too: a signature is permanent' );
ok( false !== strpos( $js, 'A published version is permanent.' ), '...and the dialog says why' );
ok( false === strpos( $js, "'Publish %d" ), 'there is no bulk publish -- one signature at a time' );

echo "\nGroup 6: selection, and the count that reports it\n";
ok( false !== strpos( $js, 'e.metaKey || e.ctrlKey' ), 'Cmd/Ctrl toggles' );
ok( false !== strpos( $js, 'e.shiftKey' ), 'Shift extends' );
ok( false !== strpos( $js, 'is-selected' ) && false !== strpos( $js, 'aria-selected' ), 'a selected cell says so to the eye and to a screen reader' );
ok( false !== strpos( $js, 'snt-status-bar' ), 'the count lives in a status footer the app paints -- the framework has no footer slot' );
ok( false !== strpos( $js, "'%1\$d of %2\$d items'" ), '...reading the Explorer\'s "N of M items"' );
ok( false !== strpos( $js, 'selected' ) && false !== strpos( $js, '%d selected' ), '...and appending the selection only when there is one' );
ok( false !== strpos( $js, 'aria-multiselectable' ), 'the canvas announces that more than one cell may be chosen' );
ok( 3 === substr_count( $js, 'state.selected = [];' ), 'search, filter and the view switch each drop the selection -- a confirmed "Move N items" never acts on notes the reader cannot see' );
ok( false !== strpos( $js, "some( ( i ) => String( i.id ) === id )" ), 'the marquee keeps only ids that name a real item: the framework reports numbers, this app\'s ids are strings' );
ok( false !== strpos( $js, 'visibility:hidden' ) && false !== strpos( $js, 'requestAnimationFrame' ) && false !== strpos( $js, 'window.innerWidth' ) && false !== strpos( $js, 'window.innerHeight' ), 'the menu paints hidden and is clamped into the viewport a frame later, then revealed' );
ok( false !== strpos( $js, "isPostSection( data ) && isPhone() ? html`<span class=\"snt-detail__more\">" ), 'the dossier header\'s More button is the phone\'s trigger only; the Explorer\'s pane has none on the desk' );

echo "\nGroup 7: the stylesheet defines every class the client emits\n";
// Every `snt-…` token the client writes, minus the `data-snt-…` attributes
// (which are hooks, not classes). A token's BEM modifier is stripped before
// the lookup: `snt-block--${ block.group }` is server data and cannot be
// enumerated, so the sheet can only promise the base class.
preg_match_all( '/(?<!data-)(?<![a-z0-9_-])snt-[a-z0-9_-]+/', $js, $m );
$emitted = array_values( array_unique( $m[0] ) );
sort( $emitted );
// The sheet's classes as a SET at selector boundaries: `.snt-menu` must not
// vouch for `snt-men`, and `.snt-status-bar` must not vouch for `snt-status`.
preg_match_all( '/\\.(snt-[a-z0-9_-]+)/', $css, $c );
$defined = array_values( array_unique( $c[1] ) );
$orphans = array();
foreach ( $emitted as $token ) {
	$base = rtrim( (string) strstr( $token . '--', '--', true ), '-' );
	if ( '' === $base || ! in_array( $base, $defined, true ) ) {
		$orphans[] = $token;
	}
}
ok( in_array( 'snt-menu', $defined, true ) && ! in_array( 'snt-men', $defined, true ) && ! in_array( 'snt-nope', $defined, true ), 'negative control: the class set matches whole selectors, and a name the sheet lacks is reported as lacking' );
ok( count( $emitted ) > 40, 'the extraction actually found the client\'s classes (' . count( $emitted ) . ' of them) -- a regex that matched nothing would pass the next pin vacuously' );
ok( array() === $orphans, 'no orphan class: every snt- class the client emits is defined in signal-noise.css' . ( $orphans ? ' (orphans: ' . implode( ', ', $orphans ) . ')' : '' ) );
foreach ( array( '.snt-status-bar', '.snt-marquee', '.snt-menu-backdrop', '.snt-menu', '.snt-more', '.snt-cell.is-selected' ) as $rule ) {
	ok( false !== strpos( $css, $rule ), "the sheet carries the control surface's own rule: $rule" );
}
ok( false !== strpos( $css, 'html[data-os-mode="mobile"] .snt-more' ), 'the phone gets its own rule for the More button -- there is no hover there to reveal it' );

echo "\nGroup 8: the phone -- the stacked list, the band, the way back (#1071)\n";
// The list on a phone. `docs/mobile.md` names the sideways table with a pinned
// column as the shape never to ship; `stacked` -- a card per row -- is what the
// shell's own lists (Trash, Users, Plugins) switch to there.
ok( false !== strpos( $js, '?stacked=${ phone }' ), 'the list view stacks into a card per row on the phone: the kit\'s own `stacked`, not a hand-rolled card' );
ok( false !== strpos( $js, 'sticky-columns=${ phone ? \'0\' : \'1\' }' ), '...and pins no column there -- a card has no columns to pin, and the pinned column beside a sideways scroll is the shape docs/mobile.md forbids' );

// The band crossing. A window born on the desk kept its drag listeners into the
// phone band; one born on the phone never got them back.
ok( false !== strpos( $js, "document.addEventListener( 'os-mode-changed', onModeChange )" ), 'the client subscribes to the shell\'s band crossing by the event WP Explorer listens for: `os-mode-changed`, on the document' );
ok( false !== strpos( $js, "document.removeEventListener( 'os-mode-changed', onModeChange )" ), '...and unsubscribes on teardown, so a closed window leaves no listener on the document' );
$desk_start = strpos( $js, 'const mountDesk = ( ctx ) =>' );
$desk_end   = strpos( $js, "defineApp( 'signal-noise'" );
$desk_fn    = ( false !== $desk_start && false !== $desk_end && $desk_end > $desk_start ) ? (string) substr( $js, $desk_start, $desk_end - $desk_start ) : '';
ok( '' !== $desk_fn, 'the desk-only listeners live in one named function, mountDesk( ctx ), ahead of the app definition' );
ok( false !== strpos( $desk_fn, 'createMarquee(' ) && false !== strpos( $desk_fn, 'dragManager.start(' ), '...and BOTH are inside it -- the drawn marquee and the drag lift, with nothing left behind in mounted()' );
ok( false !== strpos( $desk_fn, 'return teardowns;' ), '...and it hands back its teardowns, so a crossing into the phone band can take them off again' );
ok( 2 === substr_count( $js, 'mountDesk( ctx )' ), 'it is mounted in exactly two places: once at mount for the band the window was born in, once on a crossing back to the desk' );
ok( false !== strpos( $js, 'desk.forEach( ( off ) => off() )' ), 'a crossing INTO the phone band tears the desk listeners down rather than leaving them armed' );
ok( false === strpos( $js, 'if ( ! isPhone() ) {' ), 'mounted() no longer decides the band once and for all' );

// The way back. The crumb was the only exit, and the More button is
// post-sections-only, so Citations, Scheduled and Discography had none at all.
ok( false !== strpos( $js, 'class="snt-back"' ), 'the phone\'s item page carries a Back control of its own' );
ok( false !== strpos( $js, "'\xe2\x80\xb9 Back'" ), '...reading a left guillemet and the word Back' );
$detail_start = strpos( $js, 'const renderDetail = ( ctx, item )' );
$detail_end   = strpos( $js, '// ---------------------------------------------------------------- status bar' );
$detail_fn    = ( false !== $detail_start && false !== $detail_end && $detail_end > $detail_start ) ? (string) substr( $js, $detail_start, $detail_end - $detail_start ) : '';
ok( '' !== $detail_fn && false !== strpos( $detail_fn, 'class="snt-back"' ), 'it is painted in the detail header, where the phone\'s item page keeps its chrome' );
$back_at  = (int) strpos( $detail_fn, 'class="snt-back"' );
$title_at = (int) strpos( $detail_fn, 'class="snt-detail__title"' );
ok( $back_at > 0 && $title_at > $back_at, '...before the title, not under the body' );
$back_line = (string) substr( $detail_fn, (int) strrpos( (string) substr( $detail_fn, 0, $back_at ), "\n" ), (int) strpos( $detail_fn, "\n", $back_at ) - (int) strrpos( (string) substr( $detail_fn, 0, $back_at ), "\n" ) );
ok( false !== strpos( $back_line, 'isPhone()' ) && false === strpos( $back_line, 'isPostSection' ), 'EVERY section gets it: the guard is the band alone, never the post-section pair that gates the More button' );
ok( false !== strpos( $back_line, "ctx.local( 'close' )" ), '...and it closes locally, the crumb\'s own reducer -- no round trip to go back one screen' );

// The canvas menu by finger. iOS never synthesises `contextmenu` from a held
// finger, so Refresh was unreachable on a phone.
$canvas_start = strpos( $js, 'const renderCanvas = ( ctx, shown )' );
$canvas_end   = strpos( $js, '// ---------------------------------------------------------------- list view' );
$canvas_fn    = ( false !== $canvas_start && false !== $canvas_end && $canvas_end > $canvas_start ) ? (string) substr( $js, $canvas_start, $canvas_end - $canvas_start ) : '';
ok( '' !== $canvas_fn && false !== strpos( $canvas_fn, 'press.pointerdown' ), 'the canvas takes the long press, the gesture that is a right-click under a finger' );
ok( false !== strpos( $canvas_fn, "closestInPath( source, '.snt-cell' )" ), '...and a press that BEGAN on a cell stays the cell\'s: the press bubbles, and two menus for one finger is the bug this guard exists for' );
ok( false !== strpos( $canvas_fn, 'openMenu( ctx, x, y, null )' ), '...opening the canvas menu -- the same null-item menu the right-click opens, not a second vocabulary' );

// Callouts. The Explorer writes the same declarations twice; the tablet band is
// coarse without carrying the mobile stamp.
ok( 1 === preg_match( '/@media \( pointer: coarse \) \{\s*([^{]*)\{/s', $css, $coarse ), 'the stylesheet has a coarse-pointer block' );
$coarse_sel = isset( $coarse[1] ) ? (string) $coarse[1] : '';
ok( false !== strpos( $coarse_sel, '.snt-cell' ) && false !== strpos( $coarse_sel, '.snt-canvas' ), '...covering the pressable surfaces, the canvas among them: a long press there now opens a menu, and iOS\'s callout would land on top of it' );
ok( false !== strpos( $css, 'html[data-os-mode="mobile"] .snt-canvas' ), '...and the mode-stamped twin covers the same surface, the way the Explorer\'s sheet writes it' );
ok( 0 === substr_count( $js, 'is-phone' ), 'the dead is-phone class is gone: it styled nothing, no code read it, and the orphan pin\'s regex could never see it' );

// The empty state says what the section wanted said.
ok( false !== strpos( $js, 'section.emptyHeading' ), 'the empty state reads the section\'s own heading when it projects one' );
ok( false !== strpos( $js, 'section.emptyNote' ), '...and paints its note under it -- what was measured, and when' );
ok( false !== strpos( $js, "__( 'Nothing here yet.' )" ), '...falling back to the words that were already there for a section that projects neither' );

echo "\nGroup 9: the stale build (#1071)\n";
// Two reads of ONE constant at two freeze times. `extra` is baked into the
// shell document at render and nothing but the nonce rewrites it; `data` is
// recomputed by PHP on every dispatch, over REST, which the service worker
// provably does not cache.
ok( false !== strpos( $js, 'const built = ctx.extra && ctx.extra.version;' ), 'the frozen half comes from ctx.extra: what the document was built with' );
ok( false !== strpos( $js, 'const live = ctx.data && ctx.data.version;' ), 'the live half comes from ctx.data: what the server is running right now' );
ok( false !== strpos( $js, 'built && live && built !== live' ), 'the line paints only when the two DIFFER -- a compare, not a presence check, and silent when they agree' );
ok( false !== strpos( $js, '\'A newer build is installed (%1$s); this window is running %2$s.\'' ), 'the sentence names BOTH builds, so the reading is falsifiable by anyone who can read a version number' );
ok( 2 === substr_count( $js, 'renderStale( ctx )' ), 'it is painted in BOTH view branches: the crumb region is the only one present in both, and the phone\'s item page elides everything else' );
ok( false !== strpos( $css, '.snt-stale' ), 'the sheet carries its rule -- a class the client emits and the sheet lacks is the orphan the ratchet above catches' );
ok( 1 === substr_count( $js, 'location.reload()' ), 'location.reload() appears exactly once in the whole client' );
ok( false !== strpos( $js, '@click=${ () => window.location.reload() }' ), '...and only as a click handler: the shell\'s own reload flushes the session first and that flush is not reachable from an app, so a reload we issue must be the reader\'s deliberate act' );
ok( 0 === preg_match( '/set(?:Timeout|Interval)\(.{0,120}?reload/s', $js ), 'and nothing reloads on a timer: the condition is not transient and the reader is the one who decides' );
// The compare pin must be able to go red. Invert the difference test in a COPY
// and confirm the pin stops matching: a pin that survives its own mutant is
// measuring the presence of two words, not the compare.
$mutant = str_replace( 'built && live && built !== live', 'built && live && built === live', $js );
ok( $mutant !== $js && false === strpos( $mutant, 'built && live && built !== live' ), 'negative control: with the compare inverted the difference pin goes red, so it is measuring the compare itself' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
