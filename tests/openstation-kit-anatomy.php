<?php
/**
 * The leaf anatomy helpers (inc/openstation-kit-anatomy.php) and the CSS the
 * two live defects of 2026-10-16 were fixed with (assets/os-app.css): a fold
 * title inside a card read larger than the card's own heading, and Your
 * devices' Revoke buttons each took a full-width row as solid primaries.
 *
 * Run: php tests/openstation-kit-anatomy.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
require_once __DIR__ . '/lib/os-leaf-harness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── snt_kit_status_line(): one row of chips, the door on the right.
$door = snt_kit_door( 'View dashboard →', 'https://example.com/wp-admin/admin.php?page=x' );
$line = snt_kit_status_line(
	array(
		array( 'tone' => 'ok', 'label' => '5 of 5 set', 'title' => 'Beacon token set · Worker bound' ),
		array( 'tone' => 'warn', 'label' => 'Salt window not read' ),
		array( 'tone' => 'bad', 'label' => 'Collector down' ),
		array( 'tone' => 'neutral', 'label' => '2 devices yours' ),
		array( 'tone' => 'ok', 'label' => '' ),
	),
	$door
);
ok( 0 === strpos( $line, '<div class="snt-status-line"><os-cluster gap="8">' ) && '</os-cluster>' . $door . '</div>' === substr( $line, -strlen( '</os-cluster>' . $door . '</div>' ) ), 'status line: the chips in one cluster, the door after it, one wrapper' );
ok( false !== strpos( $line, '<os-badge tone="success" title="Beacon token set · Worker bound">5 of 5 set</os-badge><os-badge tone="warning">Salt window not read</os-badge><os-badge tone="danger">Collector down</os-badge><os-chip>2 devices yours</os-chip></os-cluster>' ), 'status line: ok, warn and bad are toned badges in order (bad is danger, not neutral), a title rides along, neutral is the plain chip, an empty label paints nothing' );
ok( '<div class="snt-status-line"><os-cluster gap="8"><os-chip>x</os-chip></os-cluster></div>' === snt_kit_status_line( array( array( 'label' => 'x' ) ) ), 'status line: no door means none, and a chip with no tone is neutral' );
ok( false === strpos( snt_kit_status_line( array( array( 'tone' => 'warn', 'label' => '<b>x</b>' ) ) ), '<b>' ), 'status line: labels are escaped' );

// ── snt_kit_setup_card(): one card, one fold per setting, summary = value.
$card = snt_kit_setup_card(
	'Setup',
	array(
		array( 'title' => 'Credentials', 'summary' => 'Configured', 'body' => '<p>a</p>', 'open' => true ),
		array( 'title' => 'Collector endpoint', 'summary' => 'Site default', 'body' => '<p>b</p>' ),
		array( 'title' => '', 'summary' => 'x', 'body' => 'skipped' ),
		'not a fold',
	),
	'Each line is the setting as it stands.'
);
ok( 0 === strpos( $card, '<os-section heading="Setup" description="Each line is the setting as it stands."' ), 'setup card: one section with its heading and description' );
ok( 2 === substr_count( $card, '<os-disclosure ' ) && false === strpos( $card, 'skipped' ), 'setup card: one fold per setting; a fold with no title or a non-array is skipped' );
ok( 1 === preg_match( '#<os-disclosure heading="Credentials" hint="Configured" open[ >]#', $card ) && 1 === preg_match( '#<os-disclosure heading="Collector endpoint" hint="Site default"(?![^>]*\sopen[\s>=])[^>]*>#', $card ), 'setup card: the closed summary is the value, and only the fold asked to open paints open' );
ok( strpos( $card, 'heading="Credentials"' ) < strpos( $card, 'heading="Collector endpoint"' ), 'setup card: folds keep their order' );
ok( false === strpos( snt_kit_setup_card( 'Setup', array( array( 'title' => 'Tuning', 'summary' => '', 'body' => '<p>c</p>' ) ) ), '<os-disclosure' ), 'setup card: a fold with no summary paints open as a section (snt_kit_fold())' );
ok( '' === snt_kit_setup_card( 'Setup', array() ), 'setup card: no folds, no card' );

// ── snt_kit_how_fold(): the small, closed How this works.
$how = snt_kit_how_fold( 'A signed marker on each browser.', '<p class="snt-prose">Long.</p>' );
ok( 0 === strpos( $how, '<os-disclosure heading="How this works" hint="A signed marker on each browser."' ) && 0 === preg_match( '/<os-disclosure[^>]*\sopen[\s=>]/', $how ) && false !== strpos( $how, 'data-snt-fold="how-this-works"' ) && false !== strpos( $how, '<p class="snt-prose">Long.</p>' ), 'how fold: titled How this works, closed, its summary on the closed line, the prose inside' );

// ── snt_kit_form( inline ): the compact one-button form.
$inline = snt_kit_form( 'owner_device_revoke_all', '', array( 'submit' => 'Revoke all devices', 'confirm' => 'Sure?', 'danger' => true, 'inline' => true ) );
ok( 1 === preg_match( '#^<os-form class="snt-form snt-form--inline"[^>]*os-confirm-danger[ >]#', $inline ) && false !== strpos( $inline, 'submit-label="Revoke all devices"' ) && false !== strpos( $inline, 'value="sn_owner_device_revoke_all"' ), 'inline form: the class, the danger confirm, the action and nonce kept' );
ok( false === strpos( snt_kit_form( 'x', '' ), 'snt-form--inline' ), 'a form is not inline unless asked' );

// ── The CSS the two defects were fixed with.
$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/os-app.css' );
$rule = static function ( $selector ) use ( $css ) {
	$at = strpos( $css, $selector . ' {' );
	return false === $at ? '' : substr( $css, $at, (int) strpos( $css, '}', $at ) - $at );
};
$h = $rule( '.snt-app os-section os-disclosure::part( heading )' );
ok( false !== strpos( $h, 'font-size: 14px;' ) && false !== strpos( $h, 'font-weight: 500;' ) && false !== strpos( $h, 'white-space: nowrap;' ), 'a fold inside a card titles at body size (14px, 500) on one line, under the card heading\'s 20px' );
$s = $rule( '.snt-app os-section os-disclosure::part( summary )' );
ok( false !== strpos( $s, 'display: grid;' ) && false !== strpos( $s, 'minmax( 0, 1fr )' ) && false !== strpos( $s, 'text-align: end;' ), 'its closed summary takes the rest of the title\'s line, right-aligned, wrapping in its column' );
ok( false === strpos( $css, "\nos-disclosure::part( heading )" ) && false === strpos( $css, '.snt-app os-disclosure::part( heading )' ), 'a fold that is the card (not in an os-section) keeps the component\'s heading size' );
$f = $rule( '.snt-app os-form.snt-form--inline' );
ok( false !== strpos( $f, 'container-type: normal;' ) && false !== strpos( $f, 'width: max-content;' ) && false !== strpos( $f, '--os-ui-button-bg:' ), 'an inline form sizes to its button and its submit is a quiet secondary' );
$d = $rule( '.snt-app os-form.snt-form--inline[os-confirm-danger]' );
ok( false !== strpos( $d, '--os-ui-button-bg: transparent;' ) && false !== strpos( $d, '--os-ui-danger' ) && false !== strpos( $d, 'currentColor' ), 'a danger inline form is a danger outline, never a solid fill' );
ok( '' !== $rule( '.snt-app os-form.snt-form--inline::part( footer )' ) && false !== strpos( $rule( '.snt-app os-form.snt-form--inline::part( footer )' ), 'border-top: 0;' ), 'an inline form carries no footer rule' );
preg_match_all( '/font-size:\s*(\d+)px/', substr( $css, (int) strpos( $css, 'Leaf anatomy' ) ), $sizes );
ok( array() !== $sizes[1] && min( array_map( 'intval', $sizes[1] ) ) >= 11, 'no type under 11px in the anatomy rules' );
ok( false !== strpos( $css, ".snt-app .snt-status-line {\n\tdisplay: flex;" ) && false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/apps/sn-dashboard/sn-dashboard.css' ), 'snt-an-status' ), 'the status line is the kit\'s rule now, not the Analytics leaf\'s' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
