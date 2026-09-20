<?php
/**
 * Standalone fixture tests for inc/health-checks-admin.php.
 *
 * Covers the v6.39.2 "Suggest all" cost cap: the batch button's label must show
 * min(count, SNT_AI_SUGGEST_ALL_MAX) and carry the cap as a data attribute the
 * JS reads, so one click can fire at most SNT_AI_SUGGEST_ALL_MAX AI calls.
 *
 * @since plugin v6.39.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $f, $t = 0 ) { return '5 minutes'; } }

require_once __DIR__ . '/../inc/health-summary.php'; // finding-total + flagged-checks accessors the glance hero shares
require_once __DIR__ . '/../inc/health-checks-admin.php';

$pass = 0; $fail = 0;
function hca_true( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
function hca_eq( $e, $a, $msg ) { global $pass, $fail; if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n"; } }

echo "health-checks-admin suite — plugin v6.39.2\n";

echo "\nTest: SNT_AI_SUGGEST_ALL_MAX defined\n";
hca_true( defined( 'SNT_AI_SUGGEST_ALL_MAX' ), 'cap constant exists' );
hca_eq( 50, defined( 'SNT_AI_SUGGEST_ALL_MAX' ) ? SNT_AI_SUGGEST_ALL_MAX : null, 'cap is 50' );

echo "\nTest: snt_health_suggest_all_button_html caps the label + emits the cap\n";

// Below the cap — label shows the true count.
$html = snt_health_suggest_all_button_html( 10 );
hca_true( false !== strpos( $html, 'Suggest all 10' ), 'count 10 → "Suggest all 10"' );
hca_true( false !== strpos( $html, 'data-snt-suggest-all="1"' ), 'carries the suggest-all hook attribute' );
hca_true( false !== strpos( $html, 'data-snt-suggest-all-max="50"' ), 'carries the cap as a data attribute' );

// At the cap.
$html = snt_health_suggest_all_button_html( 50 );
hca_true( false !== strpos( $html, 'Suggest all 50' ), 'count 50 → "Suggest all 50"' );

// Above the cap — label is clamped to the cap, never the raw count.
$html = snt_health_suggest_all_button_html( 73 );
hca_true( false !== strpos( $html, 'Suggest all 50' ), 'count 73 → label clamped to "Suggest all 50"' );
hca_true( false === strpos( $html, 'Suggest all 73' ), 'raw over-cap count 73 is NOT shown' );

// A single finding.
$html = snt_health_suggest_all_button_html( 1 );
hca_true( false !== strpos( $html, 'Suggest all 1' ), 'count 1 → "Suggest all 1"' );

// ── snt_health_glance_cards(): the Health-tab hero. Characterization tests that
// pin its behavior so converging its inline finding-total onto the shared
// sn_health_finding_total() / sn_health_flagged_checks() accessors stays identical.
echo "\nTest: snt_health_glance_cards no-scan\n";
$ns = snt_health_glance_cards( null );
hca_eq( 1, count( $ns ), 'no-scan → one card' );
hca_eq( 'no scan', $ns[0]['value'], 'no-scan card value is "no scan"' );

echo "\nTest: snt_health_glance_cards with findings\n";
$scan = array(
	'scanned_at' => time() - 300,
	'elapsed_ms' => 900,
	'checks'     => array(
		'missing_alt'    => array( 'count' => 2 ),
		'broken_links'   => array( 'count' => 1 ),
		'external_links' => array( 'count' => 0 ),
		'stale_posts'    => array( 'count' => 0 ),
	),
);
$cards = snt_health_glance_cards( $scan );
hca_eq( '3 findings', $cards[0]['value'], 'Findings card sums all check counts (2+1=3)' );
hca_eq( 'warn', $cards[0]['pill']['kind'], 'Findings pill is warn when total>0' );
hca_true( false !== strpos( $cards[0]['meta_html'], 'across 4 check' ), 'Findings meta reports total check count' );
hca_eq( '2 / 4', $cards[1]['value'], 'Checks-passed card is passed/total (2 of 4)' );
hca_eq( 'warn', $cards[1]['pill']['kind'], 'Checks-passed pill is warn when not all clean' );
hca_true( false !== strpos( $cards[2]['value'], 'ago' ), 'Last-scan card shows a relative age' );

echo "\nTest: snt_health_glance_cards all clean\n";
$clean = array( 'scanned_at' => time() - 300, 'elapsed_ms' => 5, 'checks' => array(
	'missing_alt' => array( 'count' => 0 ), 'broken_links' => array( 'count' => 0 ) ) );
$cc = snt_health_glance_cards( $clean );
hca_eq( '0 findings', $cc[0]['value'], 'all-clean Findings value is "0 findings"' );
hca_eq( 'ok', $cc[0]['pill']['kind'], 'all-clean Findings pill is ok' );
hca_eq( '2 / 2', $cc[1]['value'], 'all-clean Checks-passed is 2 / 2' );
hca_eq( 'ok', $cc[1]['pill']['kind'], 'all-clean Checks-passed pill is ok' );

echo "\nTest: advisory tier in the hero (v8.0.4 — external rot re-tiered)\n";
// Owner decision 2026-07-02: third-party link rot must not flip the hero off
// "all clear". It surfaces as an advisory count in the Findings card meta;
// the findings card below stays fully visible and actionable.
$one = array( 'scanned_at' => time(), 'checks' => array( 'external_links' => array( 'count' => 1 ) ) );
$oc = snt_health_glance_cards( $one );
hca_eq( '0 findings', $oc[0]['value'], 'external-rot-only scan reads 0 findings' );
hca_eq( 'ok', $oc[0]['pill']['kind'], 'external-rot-only scan stays all-clear' );
hca_true( false !== strpos( $oc[0]['meta_html'], '1 advisory' ), 'hero meta surfaces the singular advisory' );

$adv = array( 'scanned_at' => time(), 'elapsed_ms' => 5, 'checks' => array(
	'missing_alt'    => array( 'count' => 0 ),
	'external_links' => array( 'count' => 3 ),
) );
$ac = snt_health_glance_cards( $adv );
hca_eq( '0 findings', $ac[0]['value'], 'advisories alone leave the finding count at 0' );
hca_true( false !== strpos( $ac[0]['meta_html'], '3 advisories' ), 'hero meta shows the plural advisory count' );
// Checks-passed must agree with the strip's RAW split (a check carrying
// advisories is not "passing"), while the pill keys off real findings.
hca_eq( '1 / 2', $ac[1]['value'], 'checks-passed ratio uses the raw count split (advisory check is not passing)' );
hca_eq( 'ok', $ac[1]['pill']['kind'], 'checks-passed pill stays ok when only advisories exist' );

$mixed = array( 'scanned_at' => time(), 'checks' => array(
	'missing_alt'    => array( 'count' => 2 ),
	'external_links' => array( 'count' => 1 ),
) );
$mc = snt_health_glance_cards( $mixed );
hca_eq( '2 findings', $mc[0]['value'], 'mixed scan counts only real findings' );
hca_eq( 'warn', $mc[1]['pill']['kind'], 'real findings still drive the review pill' );

// ── v8.0.1: elapsed humanizer — the live scan rendered "ran in 22206ms". ──
echo "\nTest: snt_health_format_elapsed boundaries\n";
hca_eq( '0ms', snt_health_format_elapsed( 0 ), '0 → 0ms' );
hca_eq( '412ms', snt_health_format_elapsed( 412 ), 'sub-second stays milliseconds' );
hca_eq( '999ms', snt_health_format_elapsed( 999 ), 'boundary: 999ms stays milliseconds' );
hca_eq( '1.0s', snt_health_format_elapsed( 1000 ), 'boundary: 1000ms reads as 1.0s' );
hca_eq( '22.2s', snt_health_format_elapsed( 22206 ), 'live-site case: 22206ms reads as 22.2s' );

echo "\nTest: hero last-scan meta uses the humanized elapsed\n";
hca_eq( 'ran in 900ms', $cards[2]['meta_html'], 'sub-second scan meta keeps the ms form' );
$slow = array( 'scanned_at' => time(), 'elapsed_ms' => 22206, 'checks' => array( 'a' => array( 'count' => 0 ) ) );
$sc = snt_health_glance_cards( $slow );
hca_eq( 'ran in 22.2s', $sc[2]['meta_html'], 'multi-second scan meta reads in seconds' );

echo "\nTest: unlinked_mentions suggest wiring (v7.4.0)\n";
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
$src = file_get_contents( __DIR__ . '/../inc/health-checks-admin.php' );
hca_true( false !== strpos( $src, "'unlinked_mentions'" ), 'unlinked_mentions joins $suggest_supported_checks' );
$cell = sn_health_render_suggest_cell( 'unlinked_mentions', array( 'subject_id' => 12, 'target_id' => 34 ) );
hca_true( false !== strpos( $cell, 'data-check="unlinked_mentions"' ), 'cell button carries the check key' );
hca_true( false !== strpos( $cell, 'data-post-id="12"' ) && false !== strpos( $cell, 'data-target-id="34"' ), 'cell button carries source + target ids' );
$js = file_get_contents( __DIR__ . '/../assets/health-suggest-actions.js' );
hca_true( false !== strpos( $js, 'unlinked_mentions:' ) && false !== strpos( $js, "'ai-link-suggest'" ) && false !== strpos( $js, "'ai-link-apply'" ), 'JS ABILITY_BY_CHECK routes unlinked_mentions to the link abilities' );
hca_true( false !== strpos( $js, "'link' === res.verdict" ), 'JS verdict renderer has a link branch' );

echo "\nTest: JS wiring for link_opportunities (v8.1.0)\n";
$js = file_get_contents( __DIR__ . '/../assets/health-suggest-actions.js' );
hca_true( false !== strpos( $js, 'link_opportunities:' ) && false !== strpos( $js, "'ai-pair-suggest'" ), 'JS ABILITY_BY_CHECK routes link_opportunities to ai-pair-suggest' );
hca_true( false !== strpos( $js, "'link_opportunities' === checkType" ), 'JS pair-input dispatch covers link_opportunities' );
hca_true( false !== strpos( $js, "'link' === res.verdict && ! res.anchor" ), 'JS verdict renderer has the advice-only branch (empty anchor never offers Apply)' );
hca_true( false !== strpos( $js, 'err.code' ), 'JS error fallback surfaces the error code when the message is empty (v8.1.1)' );

echo "\nTest: JS noise collapse for non-actionable verdicts (v8.1.2)\n";
hca_true( false !== strpos( $js, 'isLinkCheck' ), 'JS gates the noise collapse to the two link checks' );
hca_true( false !== strpos( $js, 'No link to apply' ), 'JS collapses non-actionable link-check verdicts to a quiet row (owner noise rule)' );

echo "\nTest: link_opportunities suggest cell (v8.1.0)\n";
$cell = sn_health_render_suggest_cell( 'link_opportunities', array( 'subject_id' => 12, 'target_id' => 34 ) );
hca_true( false !== strpos( $cell, 'data-check="link_opportunities"' ), 'cell carries the check key' );
hca_true( false !== strpos( $cell, 'data-post-id="12"' ), 'cell carries the source id' );
hca_true( false !== strpos( $cell, 'data-target-id="34"' ), 'cell carries the target id' );
hca_true( false !== strpos( $cell, 'data-snt-suggest="1"' ), 'cell is a live Suggest button' );

/* ── missing_alt routes by EXACT subject type (v10.77.0) ──────────────
 * This branch used to be a binary: inline_img ? inline : attachment. When the
 * check grew inline_svg and the two *_alt_quality types, every one of them fell
 * into the else — emitting data-attachment-id="<POST id>" and pointing the
 * vision-based alt suggester at a post. An allowlist is not a classifier;
 * reusing one as a predicate inverts on everything it never enumerated.
 * ─────────────────────────────────────────────────────────────────── */
$cell = sn_health_render_suggest_cell( 'missing_alt', array( 'subject_type' => 'attachment', 'subject_id' => 77 ) );
hca_true( false !== strpos( $cell, 'data-attachment-id="77"' ) && false !== strpos( $cell, 'data-check="missing_alt"' ),
	'missing_alt/attachment still routes to the attachment suggester' );

$cell = sn_health_render_suggest_cell( 'missing_alt', array( 'subject_type' => 'inline_img', 'subject_id' => 88, 'subject_url' => 'https://e.test/a.png' ) );
hca_true( false !== strpos( $cell, 'data-check="missing_alt_inline"' ) && false !== strpos( $cell, 'data-post-id="88"' ),
	'missing_alt/inline_img still routes to the inline suggester' );

foreach ( array( 'inline_svg', 'attachment_alt_quality', 'inline_img_alt_quality' ) as $subject ) {
	$cell = sn_health_render_suggest_cell( 'missing_alt', array( 'subject_type' => $subject, 'subject_id' => 99 ) );
	hca_true( '' === $cell,
		"missing_alt/$subject emits NO button — and specifically never data-attachment-id=\"99\" (a post id)" );
}

$cell = sn_health_render_suggest_cell( 'missing_alt', array( 'subject_id' => 99 ) );
hca_true( '' === $cell, 'missing_alt with a MISSING subject_type emits no button rather than guessing attachment' );

echo "\nTest: the data contract builder the kit row shares with the classic cell (17.2.2)\n";
hca_true( array() === sn_health_suggest_cell_attrs( 'missing_alt', array( 'subject_type' => 'inline_svg', 'subject_id' => 99 ) ), 'a no-button subject type builds an empty contract' );
hca_true( array( 'data-snt-suggest' => '1', 'data-check' => 'missing_alt', 'data-attachment-id' => 77 ) === sn_health_suggest_cell_attrs( 'missing_alt', array( 'subject_type' => 'attachment', 'subject_id' => 77 ) ), 'the contract starts with data-snt-suggest and carries the check key and the id, in the classic attribute order' );
hca_true( array( 'missing_alt', 'drift_time_phrases', 'orphaned_media', 'pattern_adoption_pull_quote', 'pattern_adoption_steps_enumerated', 'unlinked_mentions', 'link_opportunities' ) === sn_health_suggest_supported_checks(), 'the supported-check list is one function, the seven keys the classic tab gated on' );
$css = file_get_contents( __DIR__ . '/../assets/admin.css' );
hca_true( 1 === preg_match( '/os-cluster > \.snt-suggest-panel,\s*os-cluster > \.snt-verdict-panel \{\s*flex: 1 1 100%;\s*min-width: 0;/', $css ), 'the Suggest and verdict panels fill an os-cluster cell (a flex host paints a child shrink-to-fit; the classic <td> does not)' );

echo "\nTest: the buttons the script mints inside a kit leaf are kit buttons (#1562)\n";
$js = file_get_contents( __DIR__ . '/../assets/health-suggest-actions.js' );
hca_true( 1 === substr_count( $js, "createElement( kit ? 'os-button' : 'button' )" ), 'one fork mints every row button: os-button in a kit app, .button elsewhere' );
hca_true( false !== strpos( $js, "cell.closest( '.snt-app' )" ), 'the kit test is the app root the leaves paint in' );
hca_true( 0 === preg_match( "/\.className = 'button[^']*button/", $js ), 'no call site sets a wp-admin button class itself (the modal pair is through the helper too since 17.4.1)' );
hca_true( 1 === substr_count( $js, "'button button-primary button-small'" ) && 1 === substr_count( $js, "'button button-small snt-verdict-delete-btn'" ), 'the classic classes live in the one variant map, byte-equal to what the tab painted' );
hca_true( 12 === substr_count( $js, 'mintButton(' ), 'nine row buttons and the modal pair through the helper (Copy, Apply, Discard x4, Link it, Delete, Suggest, Cancel, Apply) plus its definition' );
hca_true( 1 === substr_count( $js, "createElement( 'button' )" ), 'the only native <button> left is the classic box\'s close x (the kit modal paints its own)' );
hca_true( false !== strpos( $js, "mintButton( __( 'Suggest', 'signal-noise-tools' ), 'secondary', cell )" ), 'the rebuilt Suggest carries the weight the PHP twin paints (secondary)' );
hca_true( 2 === substr_count( $js, "'primary', cell )" ) && false !== strpos( $js, "mintButton( __( 'Apply', 'signal-noise-tools' ), 'primary', cell )" ), 'Apply and Link it keep the classic button-primary weight' );
hca_true( 4 === substr_count( $js, "mintButton( __( 'Discard', 'signal-noise-tools' ), 'secondary', cell )" ) && false !== strpos( $js, "mintButton( __( 'Copy', 'signal-noise-tools' ), 'secondary', cell )" ), 'all four Discards and Copy are secondary' );
hca_true( false !== strpos( $js, "mintButton( __( 'Delete', 'signal-noise-tools' ), 'danger', cell )" ), 'Delete is the danger variant' );
hca_true( false !== strpos( $js, "back.shadowRoot.querySelector( 'button' )" ) && 0 === substr_count( $js, 'originatingButton.focus()' ), 'closing the modal focuses the shadow button of a host, never the host (no delegatesFocus)' );
hca_true( false === strpos( $js, "'busy'" ) && false === strpos( $js, '.busy' ), 'the busy attribute is not used: the disabled guards would not see it (17.3.0 read, held)' );
hca_true( 3 === substr_count( $js, ".hasAttribute( 'disabled' )" ) && 0 === preg_match( '/if \( ! \w+ \|\| \w+\.disabled \)/', $js ), 'disabled is still read as an attribute (a host getter returns the string) (17.3.0 read, held)' );

echo "\nTest: the Apply preview inside a kit leaf is the kit's <os-modal> (17.4.1, the diff #1562 deferred)\n";
$js = file_get_contents( __DIR__ . '/../assets/health-suggest-actions.js' );
hca_true( 1 === substr_count( $js, "opts.originatingButton.closest( '.snt-app' )" ), 'the host forks on the same test mintButton runs, from the button that opened it' );
hca_true( 1 === substr_count( $js, "createElement( 'os-modal' )" ), 'the kit host is one <os-modal>' );
hca_true( false !== strpos( $js, "host.setAttribute( 'size', 'lg' )" ) && false !== strpos( $js, "host.setAttribute( 'title', opts.title )" ), 'the kit host carries the size and the title the classic box painted' );
hca_true( 2 === substr_count( $js, "setAttribute( 'slot', 'footer' )" ), 'Cancel and Apply sit in the footer slot' );
hca_true( false !== strpos( $js, "mintButton( __( 'Cancel', 'signal-noise-tools' ), 'secondary', opts.originatingButton )" ) && false !== strpos( $js, "mintButton( __( 'Apply', 'signal-noise-tools' ), 'primary', opts.originatingButton )" ), 'the modal pair goes through mintButton, keyed off the originating button' );
hca_true( 1 === substr_count( $js, "'Escape' === e.key && ! kit" ) && 1 === substr_count( $js, "'Escape' === e.key" ), 'the script handles Escape on the classic box only: the kit modal cancels on Escape itself' );
hca_true( 1 === preg_match( "/addEventListener\( 'os-modal-cancel', function\( e \) \{\s*e\.preventDefault\(\);\s*dismiss\(\);/", $js ), 'the component cancel is taken over: the element is removed, never hidden, so its own focus return never runs' );
hca_true( 0 === preg_match( '/if \( ! kit \) \{\s*focusHandler/', $js ) && false !== strpos( $js, 'host.contains( e.target )' ) && false !== strpos( $js, 'focusButton( applyBtn );' ), 'the focusin trap is installed on both paths, against the host: the kit modal\'s own Tab trap does not count slotted <os-button> hosts' );
hca_true( 2 === substr_count( $js, "document.addEventListener( 'keydown', escapeHandler )" ) && 2 === substr_count( $js, "document.addEventListener( 'focusin', focusHandler )" ) && 1 === preg_match( "/document\.body\.appendChild\( host \);\s*host\.setAttribute\( 'open', '' \);\s*document\.addEventListener\( 'keydown'/", $js ), 'the document listeners go in with the host on both paths, never before the dialog is on screen' );
hca_true( 1 === preg_match( '/\}, function\(\) \{\s*\/\/[^\n]*\n\s*if \( activeModal && activeModal\.host === host \) \{ dismiss\(\); \}/', $js ), 'a kit bundle that cannot be fetched disarms the modal instead of leaving Enter armed with no dialog' );
hca_true( 1 === preg_match( "/'Enter' === e\.key \) \{\s*if \( 'TEXTAREA' === \( e\.target && e\.target\.tagName \) \) \{ return; \}/", $js ), 'Enter in the textarea keeps its newline before the button test (#1572 owns the button test below)' );
hca_true( 1 === substr_count( $js, 'queueMicrotask( function() { focusButton( applyBtn ); } )' ) && 1 === substr_count( $js, 'function focusButton( back )' ) && false !== strpos( $js, 'focusButton( activeModal.originatingButton )' ), 'the kit modal lands on Apply after the component\'s own focus microtask, through the one shadow-button helper close uses too' );
hca_true( false !== strpos( $js, "loadComponents( [ 'os-modal' ] )" ), 'the tag is loaded through the kit\'s own route before the host is appended (the reschedule precedent)' );
hca_true( false !== strpos( $js, "host.className = 'snt-modal-backdrop'" ) && false !== strpos( $js, "box.className = 'snt-modal-box'" ) && false !== strpos( $js, "footer.className = 'snt-modal-footer'" ), 'the classic box is still built: backdrop, box, footer' );
hca_true( false !== strpos( $js, 'activeModal.host.parentNode.removeChild( activeModal.host )' ), 'close removes the host on both paths' );
$css = file_get_contents( __DIR__ . '/../assets/admin.css' );
hca_true( 1 === preg_match( '/os-modal > \.snt-modal-body \{\s*padding: 0;\s*--sn-text: var\(--os-ui-fg\);\s*--sn-text-muted: var\(--os-ui-fg-muted\);\s*--sn-border: var\(--os-ui-border\);/', $css ), 'inside the dark dialog the three plugin tokens the panes read follow the kit, and the grid drops its own padding' );
hca_true( 1 === preg_match( '/os-modal \.snt-modal-textarea,\s*os-modal \.snt-modal-snippet \{\s*background: var\(--os-window-bg\);/', $css ), 'the two light boxes take the dialog field surface' );

echo "\nTest: Enter on the modal's Cancel or close x cancels, it does not apply\n";
$js = file_get_contents( __DIR__ . '/../assets/health-suggest-actions.js' );
hca_true( 1 === substr_count( $js, "closest( 'button, os-button' )" ), 'the keydown handler tests the Enter target for a button (native, or the kit host a shadow-root keydown retargets to)' );
hca_true( false !== strpos( $js, 'e.composedPath()[ 0 ]' ), 'the target is read through the shadow root: the document sees <os-button> or <os-modal>, the real button sits inside' );
hca_true( 1 === preg_match( "/closest\( 'button, os-button' \) \) \{ return; \}\s*e\.preventDefault\(\);\s*accept\(\);/", $js ), 'a button gets its own click (Cancel cancels, Apply applies once); Enter anywhere else still applies' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
