<?php
/**
 * Token-contract tests for the v8.5.0 crisp-console-on-postbox treatment
 * (the admin-polish-v647 idiom applied to the Analytics page): the panel
 * shell reads from tokens, KPI numerals match the v8.0.3 glance contract,
 * and uptime-status.css no longer duplicates frame chrome the primitive owns.
 *
 * Run: php tests/analytics-tokens.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$an  = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-admin.css' );
$uw  = (string) file_get_contents( __DIR__ . '/../assets/uptime-status.css' );
$tok = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-tokens.css' );
$wg  = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-widget.css' );

echo "analytics-tokens suite - plugin v8.5.0\n";

echo "\nTest: the sn-an-postbox shell treatment\n";
ok( false !== strpos( $tok, '--sn-an-hairline: #dcdcde' ), 'hairline token defined in the shared tokens file (settings pages keep their #c3c4c7 contract untouched)' );
ok( false !== strpos( $an, '.sn-an-postbox' ), 'shell treatment targets the primitive marker class' );
ok( false !== strpos( $an, 'border: 1px solid var(--sn-an-hairline' ), 'shell border reads the token' );
ok( false !== strpos( $an, 'border-radius: var(--sn-an-radius' ), 'shell radius reads the D4 token (--sn-an-radius, 4px)' );

echo "\nTest: KPI numerals — the v8.0.3 glance contract\n";
ok( false !== strpos( $an, '.sn-an-postbox .sn-kpi-value' ), 'KPI override is scoped to primitive panels' );
$kpi_block = substr( $an, strpos( $an, '.sn-an-postbox .sn-kpi-value' ) );
$kpi_block = substr( $kpi_block, 0, strpos( $kpi_block, '}' ) );
ok( false !== strpos( $kpi_block, 'font-size: 1.35rem' ), 'glance value size 1.35rem (matches admin-polish-v647)' );
ok( false !== strpos( $kpi_block, 'font-weight: 600' ), 'glance value weight 600' );
ok( false !== strpos( $an, 'font-variant-numeric: tabular-nums' ), 'tabular numerals in the vocabulary' );

echo "\nTest: uptime-status.css frame duplication folded into the primitive\n";
ok( false === strpos( $uw, '#c3c4c7' ), 'no hardcoded frame border remains (the panel shell owns the frame)' );
ok( false !== strpos( $uw, '.sn-uw-table' ), 'table row styling stays (rows are the file\'s job, frames are not)' );

echo "\nTest: D1 modern-native block (v9.37.0)\n";
ok( false !== strpos( $an, '.sn-an-headline' ), 'D1: headline band styled' );
ok( false === strpos( $an, '.sn-an-insights{' ) && false === strpos( $an, '.sn-an-insights {' ), 'D1: dead .sn-an-insights band rules pruned' );
ok( false !== strpos( $an, '.sn-an-view-tabs .nav-tab' ), 'D1: underline tab treatment present' );
ok( preg_match( '/\.sn-an-view-tabs \.nav-tab-active\s*\{[^}]*border-bottom:\s*2px solid #2271b1/s', $an ) === 1, 'D1: active tab = 2px accent underline' );
ok( preg_match( '/\.postbox\.sn-overview \.sn-kpi-promoted \.sn-kpi-value\s*\{[^}]*font-size:\s*22px/s', $an ) === 1, 'D1: promoted KPI 22px (order-independent specificity — survives D4 reorders)' );
ok( preg_match( '/\.postbox\.sn-overview \.sn-kpi-value\s*\{[^}]*font-size:\s*13px/s', $an ) === 1, 'D1: secondary KPI 13px' );
ok( preg_match( '/\.sn-an-view-tabs\s*\{[^}]*padding-top:\s*0/s', $an ) === 1, 'D1: core nav-tab-wrapper 9px padding-top zeroed (8px rhythm)' );
ok( false !== strpos( $an, '.sn-an-sep-meta' ), 'D1: toolbar separation meta styled (muted inline)' );
ok( false !== strpos( $an, '.sn-an-uptime' ), 'D1: merged uptime details card styled' );
ok( preg_match( '/\.sn-overview \.sn-an-note\s*\{[^}]*border-left:\s*3px solid #2271b1/s', $an ) === 1, 'D1: Overview annotation = accent-bar callout (the .sn-an-note idiom, reused)' );
$d1 = substr( $an, (int) strpos( $an, 'v9.37.0 D1' ) );
ok( false !== strpos( $d1, 'box-shadow: none' ) && preg_match( '/box-shadow:\s*0/', $d1 ) === 0, 'D1 block: panels stay flat (box-shadow: none) and any shadow must ride the token, never a literal' );
ok( false === strpos( $d1, '#2563eb' ) && false === strpos( $d1, '#6b7280' ) && false === strpos( $d1, '#e2e4e7' ), 'D1 block: native palette only' );

echo "\nTest: D4 — the namespaced token layer (now shared via analytics-tokens.css)\n";
ok( false !== strpos( $tok, '--sn-an-accent:' ) && false !== strpos( $tok, '#2271b1' ), 'accent token defined in the shared tokens file' );
ok( false !== strpos( $tok, '--sn-an-up:' ) && false !== strpos( $tok, '--sn-an-down:' ), 'delta tokens defined in the shared tokens file' );
ok( false !== strpos( $tok, '--sn-an-elev-radius:' ) && false !== strpos( $tok, '--sn-an-shadow:' ), 'hero elevation tokens defined in the shared tokens file' );
ok( false === strpos( $an, ':root {' ), 'admin.css no longer declares :root tokens (moved to analytics-tokens.css)' );
ok( false === strpos( $an, '#006b18' ) && false === strpos( $an, '#b32d2e' ) && false === strpos( $an, '#2563eb' ), 'retired hexes gone (#006b18 / #b32d2e / #2563eb)' );
ok( substr_count( $an, '#0a7c2f' ) === 0, 'no raw #0a7c2f in admin.css (green lives only in the shared token file)' );
ok( false !== strpos( $tok, '#0a7c2f' ), 'the ONE green defined in the shared token file' );
ok( false === (bool) preg_match( '/#00a32a/', $an ), 'no raw delta-green outside tokens' );
ok( false !== strpos( $an, '.sn-an-rec-brief' ) && false !== strpos( $an, 'var(--sn-an-accent)' ), 'rec-brief reads the accent token' );
ok( false === strpos( $an, 'var(--sn-accent' ) && false === strpos( $an, 'var(--sn-surface-2' ), 'orphan undefined-token refs gone' );
ok( false !== strpos( $an, '.sn-an-headline' ) && false !== strpos( $an, 'var(--sn-an-elev-radius)' ) && false !== strpos( $an, 'var(--sn-an-shadow)' ), 'headline band carries the hero treatment' );
ok( false !== strpos( $an, '.sn-an-postbox.sn-overview, .sn-an-postbox.sn-an-rail-tile' ), 'D1 KPI specificity block intact' );
ok( false === (bool) preg_match( '/border-radius:\s*(2|3|5|6)px/', $an ), 'radius vocabulary consolidated (only token / 4px-in-token / 999px / 0 remain)' );
ok( preg_match( '/details\.sn-an-empty-fold summary\s*\{[^}]*color:\s*var\(--sn-an-muted\)/', $an ) === 1, 'D4 §4: fold-details summary reads the muted token (matches the plain line color)' );

echo "\nTest: S2 §6 — the modern settings leaf (hero pipeline strip + token cards)\n";
/**
 * Pulls the declaration block for the FIRST selector matching $selector,
 * from its opening `{` to the matching `}` (no nesting in this file).
 */
function tok_block( $css, $selector ) {
	$at = strpos( $css, $selector );
	if ( false === $at ) {
		return '';
	}
	$open = strpos( $css, '{', $at );
	$close = strpos( $css, '}', $open );
	return false === $open || false === $close ? '' : substr( $css, $open, $close - $open + 1 );
}

ok( false !== strpos( $an, '.sn-an-settings-leaf' ), 'leaf wrapper class is a real CSS scope, not just markup' );

// The hero: the pipeline strip is the leaf's ONE elevated surface.
$pipe = tok_block( $an, '.sn-an-pipeline {' );
ok( '' !== $pipe, 'pipeline strip rule found' );
ok( false !== strpos( $pipe, 'var(--sn-an-surface)' ), 'pipeline hero: surface token' );
ok( false !== strpos( $pipe, 'var(--sn-an-hairline)' ), 'pipeline hero: hairline token' );
ok( false !== strpos( $pipe, 'var(--sn-an-elev-radius)' ), 'pipeline hero: elev-radius token (the hero radius, not the flat one)' );
ok( false !== strpos( $pipe, 'var(--sn-an-shadow)' ), 'pipeline hero: shadow token' );
ok( false === strpos( $pipe, '#' ), 'pipeline hero: no raw hex — tokens only' );

// The token cards: the two operate/reference columns, flat (not elevated).
$card = tok_block( $an, '.sn-an-settings-leaf .sn-2up > .sn-fieldset {' );
ok( '' !== $card, 'leaf-scoped card rule found' );
ok( false !== strpos( $card, 'var(--sn-an-surface)' ), 'card: surface token' );
ok( false !== strpos( $card, 'var(--sn-an-hairline)' ), 'card: hairline token' );
ok( false !== strpos( $card, 'var(--sn-an-radius)' ) && false === strpos( $card, 'var(--sn-an-elev-radius)' ), 'card: the FLAT radius token, never the hero one' );
ok( false === strpos( $card, 'box-shadow' ), 'card: no shadow — the pipeline strip is the only elevated surface' );
ok( false === strpos( $card, '#' ), 'card: no raw hex — tokens only' );

$heading = tok_block( $an, '.sn-an-settings-leaf .sn-fieldset-h {' );
ok( '' !== $heading, 'leaf-scoped heading rule found' );
ok( false !== strpos( $heading, 'font-size: 13px' ) && false !== strpos( $heading, 'font-weight: 600' ), 'heading: 13px/600 dashboard treatment' );
ok( false !== strpos( $heading, 'var(--sn-an-text)' ) && false === strpos( $heading, '#' ), 'heading: text token, no raw hex' );

$help = tok_block( $an, '.sn-an-settings-leaf .sn-an-settings-help {' );
ok( '' !== $help, 'leaf-scoped help-text rule found' );
ok( false !== strpos( $help, 'var(--sn-an-muted)' ) && false === strpos( $help, '#' ), 'help text: muted token, no raw hex' );

// Exactly one hero consumer among the LEAF-scoped additions (the pipeline
// strip) + the dashboard's own hero (.sn-an-headline) is untouched — so the
// file-wide count of each hero token is exactly 2 (headline + pipeline).
ok( substr_count( $an, 'var(--sn-an-elev-radius)' ) === 2, 'exactly two elev-radius consumers file-wide: dashboard headline + leaf pipeline hero' );
ok( substr_count( $an, 'var(--sn-an-shadow)' ) === 2, 'exactly two shadow consumers file-wide: dashboard headline + leaf pipeline hero' );

echo "\nTest: analytics-tokens.css — the shared D4 token file (widget tokenization)\n";
ok( is_file( __DIR__ . '/../assets/analytics/analytics-tokens.css' ), 'asset: assets/analytics/analytics-tokens.css exists' );
ok( false !== strpos( $tok, ':root {' ), 'tokens file declares :root' );
foreach ( array(
	'--sn-an-accent'                   => '#2271b1',
	'--sn-an-up'                       => '#0a7c2f',
	'--sn-an-down'                     => '#d63638',
	'--sn-an-hairline'                 => '#dcdcde',
	'--sn-an-muted'                    => '#646970',
	'--sn-an-text'                     => '#1d2327',
	'--sn-an-surface'                  => '#fff',
	'--sn-an-surface-2'                => '#f6f7f7',
	'--sn-an-radius'                   => '4px',
	'--sn-an-elev-radius'              => '8px',
	'--sn-an-tier-predictive-border'   => '#9ec2e6',
	'--sn-an-tier-prescriptive'        => '#7c3aed',
	'--sn-an-tier-prescriptive-border' => '#c4b5fd',
	'--sn-an-tier-diagnostic'          => '#996800',
	'--sn-an-tier-diagnostic-border'   => '#e5cf8c',
) as $name => $value ) {
	ok( false !== strpos( $tok, "$name: $value" ), "tokens file: $name declared verbatim as $value" );
}
ok( false !== strpos( $tok, '--sn-an-shadow: 0 1px 2px rgba(0,0,0,.04)' ), 'tokens file: shadow value verbatim' );

echo "\nTest: shared tier-variant tokens reconciled in analytics-admin.css\n";
$pred_a = tok_block( $an, '.sn-an-tier--predictive' );
ok( '' !== $pred_a && false !== strpos( $pred_a, 'var(--sn-an-accent)' ) && false !== strpos( $pred_a, 'var(--sn-an-tier-predictive-border)' ) && false === strpos( $pred_a, '#' ), 'admin: predictive tier variant reads tokens, no raw hex' );
$presc_a = tok_block( $an, '.sn-an-tier--prescriptive' );
ok( '' !== $presc_a && false !== strpos( $presc_a, 'var(--sn-an-tier-prescriptive)' ) && false !== strpos( $presc_a, 'var(--sn-an-tier-prescriptive-border)' ) && false === strpos( $presc_a, '#' ), 'admin: prescriptive tier variant reads tokens, no raw hex' );
$diag_a = tok_block( $an, '.sn-an-tier--diagnostic' );
ok( '' !== $diag_a && false !== strpos( $diag_a, 'var(--sn-an-tier-diagnostic)' ) && false !== strpos( $diag_a, 'var(--sn-an-tier-diagnostic-border)' ) && false === strpos( $diag_a, '#' ), 'admin: diagnostic tier variant reads tokens, no raw hex' );

echo "\nTest: analytics-widget.css — tokenized hex list gone from rules (comments exempt)\n";
$wg_no_comments = (string) preg_replace( '/\/\*.*?\*\//s', '', $wg );
foreach ( array( '#646970', '#1d2327', '#0a7c2f', '#f6f7f7', '#d63638', '#2271b1', '#dcdcde', '#c4b5fd', '#9ec2e6', '#7c3aed' ) as $hex ) {
	ok( false === stripos( $wg_no_comments, $hex ), "widget: no raw $hex outside comments (tokenized)" );
}
ok( substr_count( $wg, 'var(--sn-an-muted)' ) === 12, 'widget: all 12 #646970 rule-occurrences read the muted token' );
ok( substr_count( $wg, 'var(--sn-an-text)' ) === 5, 'widget: all 5 #1d2327 rule-occurrences read the text token' );
ok( substr_count( $wg, 'var(--sn-an-up)' ) === 3, 'widget: all 3 #0a7c2f rule-occurrences read the up token' );
ok( substr_count( $wg, 'var(--sn-an-surface-2)' ) === 3, 'widget: all 3 #f6f7f7 rule-occurrences read the surface-2 token' );
ok( substr_count( $wg, 'var(--sn-an-down)' ) === 2, 'widget: all 2 #d63638 rule-occurrences read the down token' );
ok( substr_count( $wg, 'var(--sn-an-accent)' ) === 2, 'widget: all 2 #2271b1 rule-occurrences read the accent token' );
ok( substr_count( $wg, 'var(--sn-an-hairline)' ) === 2, 'widget: all 2 #dcdcde rule-occurrences read the hairline token' );
ok( substr_count( $wg, 'var(--sn-an-tier-predictive-border)' ) === 1, 'widget: predictive border reads its token' );
ok( substr_count( $wg, 'var(--sn-an-tier-prescriptive-border)' ) === 1, 'widget: prescriptive border reads its token' );
ok( substr_count( $wg, 'var(--sn-an-tier-prescriptive)' ) === 1, 'widget: prescriptive color reads its token' );

echo "\nTest: widget-only literals stay hex (pre-existing intra-file drift, out of scope)\n";
ok( false !== strpos( $wg, '#b32d2e' ) && false !== strpos( $wg, '#00a32a' ), 'widget: mover up/down one-offs untouched (no matching admin token — not this pass\'s job)' );
ok( false !== strpos( $wg, '#f0f0f1' ) && false !== strpos( $wg, '#c3c4c7' ), 'widget: lighter-hairline + settings-page-contract literals untouched' );

echo "\nTest: the missing diagnostic tier variant — the one deliberate visual change\n";
$diag_w = tok_block( $wg, '.sn-aw-insight .sn-an-tier--diagnostic' );
ok( '' !== $diag_w, 'widget: .sn-aw-insight .sn-an-tier--diagnostic is now defined (was missing — an unstyled insight bug)' );
ok( false !== strpos( $diag_w, 'var(--sn-an-tier-diagnostic)' ) && false !== strpos( $diag_w, 'var(--sn-an-tier-diagnostic-border)' ) && false === strpos( $diag_w, '#' ), 'widget: diagnostic variant reads tokens, no raw hex' );

echo "\nTest: the accent rgba tint stays literal, with a comment naming its token\n";
$rgba_pos = strpos( $wg, 'rgba(34, 113, 177, 0.07)' );
ok( false !== $rgba_pos, 'widget: accent rgba tint present, kept literal (not var()-ified)' );
$rgba_ctx = false !== $rgba_pos ? substr( $wg, max( 0, $rgba_pos - 400 ), 400 ) : '';
ok( false !== strpos( $rgba_ctx, '--sn-an-accent' ), 'widget: rgba tint has a nearby comment naming the accent token it mirrors' );

echo "\nTest: deliberate chip-scale deltas carry an explicit comment\n";
ok( false !== stripos( $wg, 'chip-scale: one step below the dashboard scale' ), 'widget: chip-scale size deltas are flagged explicit, not silent drift' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
