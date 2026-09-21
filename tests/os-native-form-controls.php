<?php
/**
 * A native <form os-action> ends in a kit-looking submit and ticks accent
 * checkboxes (#1595).
 *
 * Connections > Webhooks and Content > Tags carry repeated-name checkbox
 * groups (`events[]`, `sn_tag_from[]`) that `<os-form>` would fold into one
 * boolean, so they are native `<form>`s of real inputs ending in a real
 * `<button class="snt-submit">`. Neither window sheet carried a rule for the
 * button or the boxes, so each form ended in the browser's light-grey
 * user-agent button and wp-admin forms.css's white boxes on the dark station
 * surface. `os-button` and `os-checkbox` are not form-associated (nothing in
 * the kit declares formAssociated), so the fix is the look, borrowed onto the
 * shell's tokens. forms.css stays the painter of the boxes (it sets
 * appearance: none and draws the box, the tick and the dot), so the pin is the
 * unchecked box's background token, never accent-color, which cannot fire on a
 * box that is not natively rendered.
 *
 * COMMENTS ARE STRIPPED FIRST: the rule's own comment names both selectors,
 * and a scan that read prose would stay green after the rule was deleted.
 *
 * Run: php tests/os-native-form-controls.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/os-app.css' );
ok( '' !== $css, 'os-app.css is readable' );
$stripped = preg_replace( '#/\*.*?\*/#s', '', $css );
ok( $stripped !== $css, 'comments were actually stripped (the scan is not reading prose)' );

// The submit: os-button's primary chain, so it reads as every other primary
// button in the station.
if ( preg_match( '/\.snt-app \.snt-submit\s*\{([^}]*)\}/', $stripped, $m ) ) {
	ok( false !== strpos( $m[1], 'background-color' ) && false !== strpos( $m[1], '--os-ui-button-bg' ), '.snt-app .snt-submit fills with --os-ui-button-bg, the primary os-button chain' );
	ok( false !== strpos( $m[1], '--os-ui-button-fg' ) && false !== strpos( $m[1], '--os-ui-button-border-radius' ), '...with the primary ink and the kit radius' );
} else {
	ok( false, '.snt-app .snt-submit rule exists' );
	ok( false, '...with the primary ink and the kit radius' );
}

// The boxes: forms.css draws them, so the unchecked box takes the field pair
// variables.css names for a plain <input> in a window body, the checked box
// the accent holoCheck ticks in, at the kit's 16px. The radio shares the
// selector so the Tags row's two controls share metrics.
if ( preg_match( '/\.snt-app input\[type="checkbox"\],\s*\.snt-app input\[type="radio"\]\s*\{([^}]*)\}/', $stripped, $m ) ) {
	ok( false !== strpos( $m[1], 'background: var( --os-ui-field-bg' ) && false !== strpos( $m[1], 'border-color: var( --os-ui-field-border' ), '.snt-app input[type="checkbox"], .snt-app input[type="radio"] paint the unchecked box on --os-ui-field-bg and --os-ui-field-border' );
	ok( false !== strpos( $m[1], 'width: 16px' ) && false !== strpos( $m[1], 'height: 16px' ) && false !== strpos( $m[1], 'margin: 0' ), '...at the kit\'s 16px with no forms.css margin' );
	ok( false === strpos( $m[1], 'accent-color' ), '...and never accent-color, which forms.css\'s appearance: none keeps from painting' );
} else {
	ok( false, '.snt-app input[type="checkbox"], .snt-app input[type="radio"] rule exists' );
	ok( false, '...at the kit\'s 16px with no forms.css margin' );
	ok( false, '...and never accent-color' );
}
if ( preg_match( '/\.snt-app input\[type="checkbox"\]:checked,\s*\.snt-app input\[type="radio"\]:checked\s*\{([^}]*)\}/', $stripped, $m ) ) {
	ok( false !== strpos( $m[1], 'background: var( --os-ui-accent' ) && false !== strpos( $m[1], 'border-color: var( --os-ui-accent' ), '...and the checked box and dot fill with --os-ui-accent' );
} else {
	ok( false, '.snt-app input[type="checkbox"]:checked, .snt-app input[type="radio"]:checked rule exists' );
}

// Both native forms still end in that button: the rule has something to paint.
foreach ( array( 'connections-webhooks-parts.php', 'content-tags-parts.php' ) as $leaf ) {
	$php = (string) file_get_contents( dirname( __DIR__ ) . '/apps/sn-dashboard/parts/leaves/' . $leaf );
	ok( false !== strpos( $php, "'class' => 'snt-submit'" ) && false === strpos( $php, 'STYLING GAP' ), $leaf . ' ends its native form in the .snt-submit button and no longer records the gap as deliberate' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
