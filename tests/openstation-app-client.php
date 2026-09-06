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
ok( 2 === substr_count( $js, "section.id === 'notes'" ), 'the dossier is still gated to Notes in exactly two places -- the control surface reads the section DESCRIPTOR, it does not add a third literal' );
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

echo "\nGroup 5: what confirms, and in whose words\n";
ok( false !== strpos( $js, 'confirm:' ), 'confirmation goes through ctx.dispatch\'s confirm option, the one primitive there is' );
ok( false !== strpos( $js, 'danger: true' ), 'the Trash dialog is the danger one' );
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

echo "\nGroup 7: the stylesheet defines every class the client emits\n";
// Every `snt-…` token the client writes, minus the `data-snt-…` attributes
// (which are hooks, not classes). A token's BEM modifier is stripped before
// the lookup: `snt-block--${ block.group }` is server data and cannot be
// enumerated, so the sheet can only promise the base class.
preg_match_all( '/(?<!data-)(?<![a-z0-9_-])snt-[a-z0-9_-]+/', $js, $m );
$emitted = array_values( array_unique( $m[0] ) );
sort( $emitted );
$orphans = array();
foreach ( $emitted as $token ) {
	$base = rtrim( (string) strstr( $token . '--', '--', true ), '-' );
	if ( '' === $base || false === strpos( $css, '.' . $base ) ) {
		$orphans[] = $token;
	}
}
ok( count( $emitted ) > 40, 'the extraction actually found the client\'s classes (' . count( $emitted ) . ' of them) -- a regex that matched nothing would pass the next pin vacuously' );
ok( array() === $orphans, 'no orphan class: every snt- class the client emits is defined in signal-noise.css' . ( $orphans ? ' (orphans: ' . implode( ', ', $orphans ) . ')' : '' ) );
foreach ( array( '.snt-status-bar', '.snt-marquee', '.snt-menu-backdrop', '.snt-menu', '.snt-more', '.snt-cell.is-selected' ) as $rule ) {
	ok( false !== strpos( $css, $rule ), "the sheet carries the control surface's own rule: $rule" );
}
ok( false !== strpos( $css, 'html[data-os-mode="mobile"] .snt-more' ), 'the phone gets its own rule for the More button -- there is no hover there to reveal it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
